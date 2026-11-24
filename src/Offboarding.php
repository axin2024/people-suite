<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Offboarding
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly Employment $employment,
        private readonly WorkforceAccess $workforce,
    ) {
    }

    public function start(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $spec = [
            'employment_id' => Input::integer($input['employment_id'] ?? null, 'employment_id'),
            'assignment_version' => Input::integer($input['assignment_version'] ?? null, 'assignment_version'),
            'ends_before' => Input::date($input['ends_before'] ?? null, 'ends_before'),
            'reference' => Input::code($input['reference'] ?? null, 'reference'),
            'reason' => Input::text($input['reason'] ?? null, 'reason', 600),
            'equipment' => $this->equipment($input['equipment'] ?? []),
        ];
        return $this->operations->once($actor, $company, 'offboarding.started', $key, $spec, function () use ($actor, $company, $spec): array {
            $employment = $spec['employment_id'];
            $until = $spec['ends_before'];
            $worker = $this->employment->lockOffboardingCutover($actor, $company, $employment, $spec['assignment_version'], $until);
            $this->requireIndependent($actor, $worker['account_id']);
            $this->workforce->requireNewWorkAllowed($actor, $company, $employment);
            if ($worker['account_id'] !== null) {
                $this->db->row('SELECT id FROM accounts WHERE group_id=? AND id=? FOR UPDATE', [$actor->groupId, $worker['account_id']]);
            }
            $scope = [$actor->groupId, $company];
            $case = $this->db->insert(
                'INSERT INTO offboarding_cases (group_id, company_id, employment_id, employee_account_id, reference,
                    ends_before, assignment_version, reason, opened_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $employment, $worker['account_id'], $spec['reference'], $until, $spec['assignment_version'], $spec['reason'], $actor->accountId],
            );
            $manager = $this->db->row(
                "SELECT r.manager_account_id FROM reporting_lines r JOIN company_roles m
                 ON m.group_id=r.group_id AND m.company_id=r.company_id AND m.account_id=r.manager_account_id AND m.role='manager'
                 JOIN accounts a ON a.group_id=m.group_id AND a.id=m.account_id AND a.enabled=1
                 WHERE r.group_id=? AND r.company_id=? AND r.employment_id=? AND r.effective_from<?
                   AND (r.effective_until IS NULL OR r.effective_until>=?) ORDER BY r.effective_from DESC LIMIT 1",
                [...$scope, $employment, $until, $until],
            )['manager_account_id'] ?? null;
            $tasks = [
                ['HANDOVER', 'Work handover', $manager === null ? 'hr' : 'manager', $manager],
                ['EQUIPMENT', 'Return all assigned equipment', 'hr', null],
                ['PAYROLL', 'Review final payroll obligations', 'payroll_reviewer', null],
                ['ACCESS', 'Inventory company resources and access', 'hr', null],
            ];
            foreach ($spec['equipment'] as $item) {
                $tasks[] = [$item['code'], $item['label'], 'hr', null];
            }
            foreach ($tasks as $task) {
                $this->db->run('INSERT INTO offboarding_tasks (group_id, company_id, case_id, code, label, owner_role, owner_account_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)', [...$scope, $case, ...$task]);
            }
            $this->operations->audit($actor, $company, 'offboarding_case', $case, 'offboarding.started', [
                'employment_id' => $employment, 'ends_before' => $until, 'reference' => $spec['reference'],
            ]);
            return ['case_id' => $case, 'employment_id' => $employment, 'ends_before' => $until, 'state' => 'in_progress', 'version' => 1];
        });
    }

    public function cases(Access $actor, int $company, int $after): array
    {
        $roles = $actor->roles($company);
        $scope = [$actor->groupId, $company, $after];
        $filters = [];
        if (in_array('employee', $roles, true)) {
            $filters[] = 'c.employee_account_id=?';
            $scope[] = $actor->accountId;
        } elseif ($actor->archiveGranted($company)) {
            $filters[] = 'EXISTS (SELECT 1 FROM archived_employment_access a WHERE a.group_id=c.group_id
                                 AND a.company_id=c.company_id AND a.employment_id=c.employment_id
                                 AND a.account_id=c.employee_account_id AND a.account_id=?)';
            $scope[] = $actor->accountId;
        }
        if (in_array('manager', $roles, true)) {
            $filters[] = "EXISTS (SELECT 1 FROM offboarding_tasks t WHERE t.group_id=c.group_id AND t.company_id=c.company_id
                                 AND t.case_id=c.id AND t.owner_role='manager' AND t.owner_account_id=?)";
            $scope[] = $actor->accountId;
        }
        $all = array_intersect(['hr', 'payroll_reviewer'], $roles) !== [];
        if (!$all && $filters === []) {
            throw new BusinessError(404, 'company_not_found');
        }
        return $this->db->rows(
            'SELECT c.id, c.employment_id, c.reference, c.ends_before, c.state, c.version, c.opened_at, c.decided_at
             FROM offboarding_cases c WHERE c.group_id=? AND c.company_id=? AND c.id>?'
             . ($all ? '' : ' AND (' . implode(' OR ', $filters) . ')') . ' ORDER BY c.id LIMIT 100',
            $all ? [$actor->groupId, $company, $after] : $scope,
        );
    }

    public function detail(Access $actor, int $company, int $case): array
    {
        $scope = [$actor->groupId, $company, $case];
        $record = $this->case($scope);
        $roles = $actor->roles($company);
        $tasks = $this->tasks($scope);
        $hr = in_array('hr', $roles, true);
        $owner = (int) $record['employee_account_id'] === $actor->accountId && (in_array('employee', $roles, true)
            || $actor->archiveGranted($company, (int) $record['employment_id']));
        if (!$hr && !$owner) {
            $tasks = array_values(array_filter($tasks, fn(array $task): bool => $this->ownsTask($actor, $roles, $task)));
            if ($tasks === []) {
                throw new BusinessError(404, 'offboarding_case_not_found');
            }
        }
        $blockers = $hr && $record['state'] === 'in_progress' ? $this->blockers($record, $scope, $tasks) : [];
        if (!$hr) {
            unset($record['reason'], $record['employee_account_id'], $record['opened_by'], $record['decided_by']);
        }
        foreach ($tasks as &$task) {
            if ($owner && !$hr) {
                unset($task['evidence'], $task['decision_ref'], $task['confirmed_by'], $task['owner_account_id']);
            }
        }
        unset($task);
        return ['case' => $record, 'tasks' => $tasks, 'blockers' => $blockers];
    }

    public function confirm(Access $actor, int $company, int $case, string $code, string $key, array $input): array
    {
        $actor->company($company, 'hr', 'manager', 'payroll_reviewer');
        $version = Input::integer($input['version'] ?? null, 'version');
        $ref = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $evidence = Input::text($input['evidence'] ?? null, 'evidence', 1200);
        return $this->operations->once($actor, $company, 'offboarding.task_confirmed', $key, [
            'case_id' => $case, 'task_code' => $code, 'version' => $version, 'decision_ref' => $ref, 'evidence' => $evidence,
        ], function () use ($actor, $company, $case, $code, $version, $ref, $evidence): array {
            $scope = [$actor->groupId, $company, $case];
            $record = $this->case($scope);
            $this->workforce->lockEmployment($actor, $company, (int) $record['employment_id']);
            $record = $this->case($scope, lock: true);
            $task = $this->db->row(
                'SELECT owner_role, owner_account_id, confirmed_at FROM offboarding_tasks WHERE group_id=? AND company_id=? AND case_id=? AND code=?',
                [...$scope, $code],
            );
            $roles = $actor->roles($company);
            if ($task === null || !$this->ownsTask($actor, $roles, $task)) {
                throw new BusinessError(404, 'offboarding_task_not_found');
            }
            $this->requireIndependent($actor, $record['employee_account_id']);
            $this->requireActive($record, $version);
            if ($task['confirmed_at'] !== null) {
                throw new BusinessError(409, 'offboarding_task_already_confirmed');
            }
            $this->db->run('UPDATE offboarding_tasks SET evidence=?, decision_ref=?, confirmed_by=?, confirmed_at=CURRENT_TIMESTAMP(6)
                WHERE group_id=? AND company_id=? AND case_id=? AND code=?', [$evidence, $ref, $actor->accountId, ...$scope, $code]);
            $this->db->run('UPDATE offboarding_cases SET version=version+1 WHERE group_id=? AND company_id=? AND id=?', $scope);
            $this->operations->audit($actor, $company, 'offboarding_case', $case, 'offboarding.task_confirmed', [
                'code' => $code, 'decision_ref' => $ref, 'version' => $version + 1,
            ]);
            return ['case_id' => $case, 'task_code' => $code, 'confirmed' => true, 'version' => $version + 1];
        });
    }

    public function decide(Access $actor, int $company, int $case, string $action, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $next = match ($action) {
            'complete' => 'completed',
            'cancel' => 'cancelled',
            default => throw new BusinessError(404, 'route_not_found'),
        };
        $version = Input::integer($input['version'] ?? null, 'version');
        $reference = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        return $this->operations->once($actor, $company, 'offboarding.' . $next, $key, [
            'case_id' => $case, 'version' => $version, 'decision_ref' => $reference,
        ], function () use ($actor, $company, $case, $next, $version, $reference): array {
            $scope = [$actor->groupId, $company, $case];
            $record = $this->case($scope);
            $employment = (int) $record['employment_id'];
            $until = $record['ends_before'];
            $this->requireIndependent($actor, $record['employee_account_id']);
            $this->requireActive($record, $version);
            $worker = null;
            if ($next === 'completed') {
                $worker = $this->employment->lockOffboardingCutover($actor, $company, $employment, (int) $record['assignment_version'], $until);
            } else {
                $this->workforce->lockEmployment($actor, $company, $employment);
            }
            $record = $this->case($scope, lock: true);
            $this->requireActive($record, $version);
            $revoked = 0;
            if ($worker !== null) {
                $pending = $this->blockers($record, $scope, $this->tasks($scope));
                if ($pending !== []) {
                    throw new BusinessError(409, 'offboarding_clearance_incomplete', ['blockers' => $pending]);
                }
                if ($until > $this->workforce->companyToday($actor, $company)) {
                    throw new BusinessError(409, 'offboarding_cutoff_not_reached', ['ends_before' => $until]);
                }
                $this->employment->applyOffboardingEnd($actor, $company, $employment, $worker['assignment'], $until);
                if ($worker['account_id'] !== null) {
                    $revoked = $this->archiveAccount($actor, $company, $employment, (int) $worker['account_id'], $case);
                }
            }
            $this->db->run('UPDATE offboarding_cases SET state=?, version=version+1, decision_ref=?, decided_by=?, decided_at=CURRENT_TIMESTAMP(6)
                WHERE group_id=? AND company_id=? AND id=?', [$next, $reference, $actor->accountId, ...$scope]);
            $this->operations->audit($actor, $company, 'offboarding_case', $case, 'offboarding.' . $next, [
                'employment_id' => $employment, 'ends_before' => $until, 'version' => $version + 1,
                'decision_ref' => $reference, 'revoked_role_count' => $revoked,
            ]);
            return ['case_id' => $case, 'employment_id' => $employment, 'state' => $next, 'version' => $version + 1,
                'ends_before' => $until, 'revoked_role_count' => $revoked];
        });
    }

    private function archiveAccount(Access $actor, int $company, int $employment, int $account, int $case): int
    {
        $this->db->row('SELECT id FROM accounts WHERE group_id=? AND id=? FOR UPDATE', [$actor->groupId, $account]);
        $this->db->run('INSERT INTO archived_employment_access (group_id, company_id, employment_id, account_id, case_id)
            VALUES (?, ?, ?, ?, ?)', [$actor->groupId, $company, $employment, $account, $case]);
        $scope = [$actor->groupId, $company, $account];
        $this->db->run('DELETE FROM recruiter_positions WHERE group_id=? AND company_id=? AND account_id=?', $scope);
        return $this->db->run('DELETE FROM company_roles WHERE group_id=? AND company_id=? AND account_id=?', $scope)->rowCount();
    }

    private function case(array $scope, bool $lock = false): array
    {
        return $this->db->row('SELECT id, employment_id, employee_account_id, reference, ends_before, assignment_version, state,
                version, reason, opened_by, opened_at, decided_by, decision_ref, decided_at
            FROM offboarding_cases WHERE group_id=? AND company_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), $scope)
            ?? throw new BusinessError(404, 'offboarding_case_not_found');
    }

    private function tasks(array $scope): array
    {
        return $this->db->rows('SELECT code, label, owner_role, owner_account_id, evidence, decision_ref, confirmed_by, confirmed_at
            FROM offboarding_tasks WHERE group_id=? AND company_id=? AND case_id=? ORDER BY code', $scope);
    }

    private function blockers(array $record, array $scope, array $tasks): array
    {
        $blockers = [];
        foreach ($tasks as $task) {
            if ($task['confirmed_at'] === null) {
                $blockers[] = ['kind' => 'task', 'code' => $task['code'], 'label' => $task['label'], 'owner_role' => $task['owner_role']];
            }
        }
        $forEmployment = [$scope[0], $scope[1], $record['employment_id']];
        $remaining = [
            'employee_request' => $this->db->rows("SELECT id, version FROM employee_requests
                WHERE group_id=? AND company_id=? AND employment_id=? AND state='pending' ORDER BY id LIMIT 50", $forEmployment),
            'leave' => $this->db->rows("SELECT id, version FROM leave_requests WHERE group_id=? AND company_id=? AND employment_id=?
                AND (state='pending' OR (state='approved' AND workday>=?)) ORDER BY id LIMIT 50", [...$forEmployment, $record['ends_before']]),
            'shift' => $this->db->rows("SELECT id, workday FROM shifts WHERE group_id=? AND company_id=? AND employment_id=?
                AND state='scheduled' AND workday>=? ORDER BY id LIMIT 50", [...$forEmployment, $record['ends_before']]),
            'payslip_inquiry' => $this->db->rows("SELECT q.id, q.version FROM payslip_inquiries q JOIN payslips p
                ON p.group_id=q.group_id AND p.company_id=q.company_id AND p.id=q.payslip_id
                WHERE q.group_id=? AND q.company_id=? AND p.employment_id=? AND q.state<>'acknowledged' ORDER BY q.id LIMIT 50", $forEmployment),
        ];
        foreach ($remaining as $kind => $objects) {
            foreach ($objects as $object) {
                $blockers[] = ['kind' => $kind, ...$object];
            }
        }
        return $blockers;
    }

    private function ownsTask(Access $actor, array $roles, array $task): bool
    {
        $role = $task['owner_role'];
        if ($role === 'manager') {
            return in_array('hr', $roles, true) || (in_array('manager', $roles, true) && (int) $task['owner_account_id'] === $actor->accountId);
        }
        return in_array($role, $roles, true);
    }

    private function requireIndependent(Access $actor, mixed $account): void
    {
        if ($account !== null && (int) $account === $actor->accountId) {
            throw new BusinessError(403, 'offboarding_independent_handler_required');
        }
    }

    private function requireActive(array $record, int $version): void
    {
        if ($record['state'] !== 'in_progress' || (int) $record['version'] !== $version) {
            throw new BusinessError(409, 'offboarding_conflict', ['state' => $record['state'], 'version' => (int) $record['version']]);
        }
    }

    private function equipment(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 20) {
            throw new BusinessError(422, 'invalid_offboarding_equipment');
        }
        $reserved = ['HANDOVER', 'EQUIPMENT', 'PAYROLL', 'ACCESS'];
        $items = [];
        foreach ($input as $asset) {
            if (!is_array($asset)) {
                throw new BusinessError(422, 'invalid_offboarding_equipment');
            }
            $code = Input::code($asset['code'] ?? null, 'equipment_code');
            if (in_array($code, $reserved, true) || isset($items[$code])) {
                throw new BusinessError(422, 'duplicate_offboarding_equipment');
            }
            $items[$code] = ['code' => $code, 'label' => Input::text($asset['label'] ?? null, 'equipment_label', 160)];
        }
        return array_values($items);
    }
}
