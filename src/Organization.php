<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeZone;

final class Organization
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
    ) {
    }

    public function createCompany(Access $actor, string $code, string $name, string $timezone, string $currency): array
    {
        $actor->administrator();
        $code = Input::code($code, 'company_code');
        $name = Input::text($name, 'company_name', 160);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new BusinessError(422, 'invalid_timezone');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new BusinessError(422, 'invalid_currency');
        }
        return $this->db->transaction(function () use ($actor, $code, $name, $timezone, $currency): array {
            $id = $this->db->insert(
                'INSERT INTO companies (group_id, code, name, timezone, currency) VALUES (?, ?, ?, ?, ?)',
                [$actor->groupId, $code, $name, $timezone, $currency],
            );
            $this->operations->audit($actor, $id, 'company', $id, 'company.created');
            $this->db->run("INSERT INTO workforce_rollouts (group_id,company_id,state) VALUES (?,?,'active')",[$actor->groupId,$id]);
            return ['company_id' => $id];
        });
    }

    public function grantRole(Access $actor, int $company, int $account, string $role): array
    {
        $actor->administrator();
        if (!in_array($role, ['hr', 'recruiter', 'manager', 'payroll_maker', 'payroll_reviewer', 'employee'], true)) {
            throw new BusinessError(422, 'invalid_role');
        }
        return $this->db->transaction(function () use ($actor, $company, $account, $role): array {
            $this->knownCompany($actor, $company);
            $this->knownAccount($actor, $account);
            if ($this->db->row(
                "SELECT c.id FROM offboarding_cases c WHERE c.group_id=? AND c.company_id=? AND c.employee_account_id=?
                 AND (c.state='in_progress' OR (c.state='completed' AND NOT EXISTS (
                     SELECT 1 FROM employments w JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
                     WHERE w.group_id=c.group_id AND w.company_id=c.company_id AND e.account_id=c.employee_account_id
                       AND w.starts_on>=c.ends_before AND w.ends_before IS NULL
                 ))) LIMIT 1", [$actor->groupId, $company, $account],
            ) !== null) {
                throw new BusinessError(409, 'offboarding_role_grants_locked');
            }
            $grant = $this->db->run(
                'INSERT INTO company_roles (group_id, company_id, account_id, role) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE role=company_roles.role',
                [$actor->groupId, $company, $account, $role],
            );
            if ($grant->rowCount() > 0) {
                $this->operations->audit($actor, $company, 'account', $account, 'role.granted', ['role' => $role]);
            }
            return ['company_id' => $company, 'account_id' => $account, 'role' => $role];
        });
    }

    public function grantRecruitingPosition(Access $actor, int $company, int $position, int $account): array
    {
        $actor->company($company, 'hr');
        return $this->db->transaction(function () use ($actor, $company, $position, $account): array {
            $this->lockPosition($actor, $company, $position);
            if ($this->db->row(
                "SELECT 1 FROM company_roles WHERE group_id=? AND company_id=? AND account_id=? AND role='recruiter'",
                [$actor->groupId, $company, $account],
            ) === null) {
                throw new BusinessError(404, 'recruiter_not_found');
            }
            $grant = $this->db->run(
                'INSERT INTO recruiter_positions (group_id, company_id, position_id, account_id) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE account_id=recruiter_positions.account_id',
                [$actor->groupId, $company, $position, $account],
            );
            if ($grant->rowCount() > 0) {
                $this->operations->audit($actor, $company, 'position', $position, 'recruiter.assigned', ['account_id' => $account]);
            }
            return ['position_id' => $position, 'account_id' => $account];
        });
    }

    public function createDepartment(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $code = Input::code($input['code'] ?? null, 'code');
        $name = Input::text($input['name'] ?? null, 'name', 160);
        $from = Input::date($input['effective_from'] ?? null, 'effective_from');
        $parent = isset($input['parent_id']) ? Input::integer($input['parent_id'], 'parent_id') : null;
        return $this->operations->once($actor, $company, 'department.create', $key, [
            'code' => $code, 'name' => $name, 'from' => $from, 'parent' => $parent,
        ], function () use ($actor, $company, $code, $name, $from, $parent): array {
            if ($parent !== null) {
                $this->departmentOn($actor, $company, $parent, $from);
            }
            $id = $this->db->insert(
                'INSERT INTO departments (group_id, company_id, parent_id, code, name, effective_from) VALUES (?, ?, ?, ?, ?, ?)',
                [$actor->groupId, $company, $parent, $code, $name, $from],
            );
            $this->operations->audit($actor, $company, 'department', $id, 'department.created', [
                'parent_id' => $parent, 'effective_from' => $from,
            ]);
            return ['department_id' => $id, 'effective_from' => $from];
        });
    }

    public function departments(Access $actor, int $company, string $on): array
    {
        $actor->company($company, 'hr');
        $on = Input::date($on, 'on');
        return $this->db->rows(
            'SELECT id, parent_id, code, name, effective_from FROM departments
             WHERE group_id=? AND company_id=? AND effective_from<=? ORDER BY parent_id, id',
            [$actor->groupId, $company, $on],
        );
    }

    public function positions(Access $actor, int $company, string $on, int $after): array
    {
        $actor->company($company, 'hr', 'recruiter');
        $scope = [$on, $on, $actor->groupId, $company, $after];
        $grant = '';
        if (!in_array('hr', $actor->roles($company), true)) {
            $grant = ' AND EXISTS (SELECT 1 FROM recruiter_positions g WHERE g.group_id=p.group_id
                                   AND g.company_id=p.company_id AND g.position_id=p.id AND g.account_id=?)';
            $scope[] = $actor->accountId;
        }
        return $this->db->rows(
            'SELECT p.id, p.code, r.id AS revision_id, r.title, r.active, d.name AS department_name
             FROM positions p
             JOIN position_revisions r ON r.group_id=p.group_id AND r.company_id=p.company_id AND r.position_id=p.id
                AND r.effective_from<=? AND (r.effective_until IS NULL OR r.effective_until>?)
             JOIN departments d ON d.group_id=r.group_id AND d.company_id=r.company_id AND d.id=r.department_id
             WHERE p.group_id=? AND p.company_id=? AND p.id>?' . $grant . ' ORDER BY p.id LIMIT 100',
            $scope,
        );
    }

    public function headcount(Access $actor, int $company, int $position, string $on): array
    {
        $actor->company($company, 'hr');
        $scope = [$actor->groupId, $company, $position];
        return $this->db->transaction(function () use ($scope, $on): array {
            if ($this->db->row('SELECT id FROM positions WHERE group_id=? AND company_id=? AND id=?', $scope) === null) {
                throw new BusinessError(404, 'position_not_found');
            }
            $budget = $this->db->row(
                'SELECT id, starts_on, ends_before, capacity FROM headcount_budgets
                 WHERE group_id=? AND company_id=? AND position_id=? AND starts_on<=? AND ends_before>?',
                [...$scope, $on, $on],
            );
            if ($budget === null) {
                return ['on' => $on, 'budget' => null, 'occupied' => null, 'available' => null];
            }
            $tomorrow = (new \DateTimeImmutable($on))->modify('+1 day')->format('Y-m-d');
            $occupiedBp = array_sum(array_column($this->headcountIntervals($scope, $on, $tomorrow, (int) $budget['id']), 'fte_bp'));
            $occupied = $occupiedBp / 10000;
            return ['on' => $on, 'budget' => $budget, 'occupied' => $occupied, 'available' => (int) $budget['capacity'] - $occupied];
        }, repeatableRead: true);
    }

    public function createPosition(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $code = Input::code($input['code'] ?? null, 'code');
        $revision = $this->positionInput($input, true);
        return $this->operations->once($actor, $company, 'position.create', $key, [
            'code' => $code, ...$revision,
        ], function () use ($actor, $company, $code, $revision): array {
            $this->departmentOn($actor, $company, $revision['department_id'], $revision['effective_from']);
            $position = $this->db->insert('INSERT INTO positions (group_id, company_id, code) VALUES (?, ?, ?)', [
                $actor->groupId, $company, $code,
            ]);
            return $this->insertRevision($actor, $company, $position, $revision);
        });
    }

    public function revisePosition(Access $actor, int $company, int $position, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $revision = $this->positionInput($input, false);
        $expected = Input::integer($input['revision_id'] ?? null, 'revision_id');
        return $this->operations->once($actor, $company, 'position.revise', $key, [
            'position_id' => $position, 'revision_id' => $expected, ...$revision,
        ], function () use ($actor, $company, $position, $expected, $revision): array {
            $this->lockPosition($actor, $company, $position);
            $latest = $this->db->row(
                'SELECT id, effective_from FROM position_revisions
                 WHERE group_id=? AND company_id=? AND position_id=? AND effective_until IS NULL',
                [$actor->groupId, $company, $position],
            );
            if ((int) $latest['id'] !== $expected || $revision['effective_from'] <= $latest['effective_from']) {
                throw new BusinessError(409, 'position_revision_conflict', ['current_revision' => $latest]);
            }
            $from = $revision['effective_from'];
            if (!$revision['active']) {
                $holds = $this->db->rows(
                    'SELECT id, offer_id, starts_on, ends_before FROM headcount_slots
                     WHERE group_id=? AND company_id=? AND position_id=? AND released_at IS NULL AND ends_before>?
                     ORDER BY starts_on, id',
                    [$actor->groupId, $company, $position, $from],
                );
                $assignments = $this->db->rows(
                    'SELECT id, employment_id, effective_from, effective_until FROM assignments
                     WHERE group_id=? AND company_id=? AND position_id=? AND (effective_until IS NULL OR effective_until>?)
                     ORDER BY effective_from, id',
                    [$actor->groupId, $company, $position, $from],
                );
                if ($holds !== [] || $assignments !== []) {
                    throw new BusinessError(409, 'position_still_occupied', [
                        'occupancies' => $holds, 'assignments' => $assignments,
                    ]);
                }
            }
            $this->departmentOn($actor, $company, $revision['department_id'], $from);
            $this->db->run('UPDATE position_revisions SET effective_until=? WHERE id=?', [$from, $expected]);
            return $this->insertRevision($actor, $company, $position, $revision);
        });
    }

    public function history(Access $actor, int $company, int $position): array
    {
        $actor->company($company, 'hr');
        $rows = $this->db->rows(
            'SELECT id, department_id, title, active, effective_from, effective_until FROM position_revisions
             WHERE group_id=? AND company_id=? AND position_id=? ORDER BY effective_from',
            [$actor->groupId, $company, $position],
        );
        if ($rows === []) {
            throw new BusinessError(404, 'position_not_found');
        }
        return $rows;
    }

    public function budget(Access $actor, int $company, int $position, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $start = Input::date($input['starts_on'] ?? null, 'starts_on');
        $end = Input::date($input['ends_before'] ?? null, 'ends_before');
        $capacity = Input::integer($input['capacity'] ?? null, 'capacity', 0, 1000000);
        if ($end <= $start) {
            throw new BusinessError(422, 'empty_budget_period');
        }
        return $this->operations->once($actor, $company, 'headcount.budget', $key, [
            'position_id' => $position, 'start' => $start, 'end' => $end, 'capacity' => $capacity,
        ], function () use ($actor, $company, $position, $start, $end, $capacity): array {
            $this->lockPosition($actor, $company, $position);
            $this->activeRevision($actor, $company, $position, $start);
            $overlap = $this->db->row(
                'SELECT id, starts_on, ends_before FROM headcount_budgets
                 WHERE group_id=? AND company_id=? AND position_id=? AND starts_on<? AND ends_before>? LIMIT 1',
                [$actor->groupId, $company, $position, $end, $start],
            );
            if ($overlap !== null) {
                throw new BusinessError(409, 'budget_period_overlap', $overlap);
            }
            $scope = [$actor->groupId, $company, $position];
            $this->checkCapacity($scope, $start, $end, $capacity, 0);
            $id = $this->db->insert(
                'INSERT INTO headcount_budgets (group_id, company_id, position_id, starts_on, ends_before, capacity)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$actor->groupId, $company, $position, $start, $end, $capacity],
            );
            $this->operations->audit($actor, $company, 'position', $position, 'headcount.budget_created', ['budget_id' => $id]);
            return ['budget_id' => $id, 'starts_on' => $start, 'ends_before' => $end, 'capacity' => $capacity];
        });
    }

    public function lockPosition(Access $actor, int $company, int $position): void
    {
        if ($this->db->row('SELECT id FROM positions WHERE group_id=? AND company_id=? AND id=? FOR UPDATE', [
            $actor->groupId, $company, $position,
        ]) === null) {
            throw new BusinessError(404, 'position_not_found');
        }
    }

    public function activeRevision(Access $actor, int $company, int $position, string $on): array
    {
        $row = $this->db->row(
            'SELECT id, department_id FROM position_revisions
             WHERE group_id=? AND company_id=? AND position_id=? AND active=1
               AND effective_from<=? AND (effective_until IS NULL OR effective_until>?)',
            [$actor->groupId, $company, $position, $on, $on],
        );
        if ($row === null) {
            throw new BusinessError(409, 'position_inactive', ['effective_on' => $on]);
        }
        return $row;
    }

    public function reserve(
        Access $actor,
        int $company,
        int $position,
        string $start,
        ?int $offer = null,
        ?string $end = null,
        int $fteBp = 10000,
    ): int {
        Input::integer($fteBp,'fte_bp',1,10000);
        $this->lockPosition($actor, $company, $position);
        $this->activeRevision($actor, $company, $position, $start);
        $budget = $this->db->row(
            'SELECT id, ends_before, capacity FROM headcount_budgets
             WHERE group_id=? AND company_id=? AND position_id=? AND starts_on<=? AND ends_before>?',
            [$actor->groupId, $company, $position, $start, $start],
        );
        if ($budget === null) {
            throw new BusinessError(409, 'budget_not_found', ['effective_on' => $start]);
        }
        $until = $end ?? $budget['ends_before'];
        if ($until <= $start || $until > $budget['ends_before']) {
            throw new BusinessError(409, 'outside_budget_period', ['budget_id' => (int) $budget['id']]);
        }
        $retired = $this->db->row(
            'SELECT effective_from FROM position_revisions
             WHERE group_id=? AND company_id=? AND position_id=? AND active=0
               AND effective_from>? AND effective_from<? ORDER BY effective_from LIMIT 1',
            [$actor->groupId, $company, $position, $start, $until],
        );
        if ($retired !== null) {
            throw new BusinessError(409, 'position_inactive', ['effective_on' => $retired['effective_from']]);
        }
        $scope = [$actor->groupId, $company, $position];
        $this->checkCapacity($scope, $start, $until, (int) $budget['capacity'], $fteBp, (int) $budget['id']);
        $slot = $this->db->insert(
            'INSERT INTO headcount_slots (group_id, company_id, position_id, budget_id, offer_id, starts_on, ends_before)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [...$scope, $budget['id'], $offer, $start, $until],
        );
        if ($fteBp !== 10000) {
            $this->db->run('INSERT INTO headcount_slot_workloads (group_id,company_id,position_id,slot_id,fte_bp) VALUES (?,?,?,?,?)',
                [...$scope,$slot,$fteBp]);
        }
        return $slot;
    }

    public function checkFutureAssignment(Access $actor, int $company, int $position, int $slot): void
    {
        $scope = [$actor->groupId, $company, $position];
        $period = $this->db->row(
            'SELECT b.ends_before,COALESCE(f.fte_bp,10000) fte_bp FROM headcount_slots s LEFT JOIN headcount_slot_workloads f ON f.slot_id=s.id JOIN headcount_budgets b
               ON b.group_id=s.group_id AND b.company_id=s.company_id AND b.position_id=s.position_id AND b.id=s.budget_id
             WHERE s.group_id=? AND s.company_id=? AND s.position_id=? AND s.id=?',
            [...$scope, $slot],
        );
        $budgets = $this->db->rows(
            'SELECT id, starts_on, ends_before, capacity FROM headcount_budgets
             WHERE group_id=? AND company_id=? AND position_id=? AND starts_on>=? ORDER BY starts_on',
            [...$scope, $period['ends_before']],
        );
        foreach ($budgets as $budget) {
            $this->checkCapacity(
                $scope, $budget['starts_on'], $budget['ends_before'], (int) $budget['capacity'], (int)$period['fte_bp'], (int) $budget['id'], $slot,
            );
        }
    }

    private function checkCapacity(
        array $scope,
        string $start,
        string $until,
        int $capacity,
        int $incomingBp,
        int $budgetId = 0,
        int $excludePendingSlot = 0,
    ): void {
        $events = [$start => 0];
        foreach ($this->headcountIntervals($scope, $start, $until, $budgetId, $excludePendingSlot) as $slot) {
            $from = max($start, $slot['starts_on']);
            $to = min($until, $slot['ends_before'] ?? $until);
            $events[$from] = ($events[$from] ?? 0) + $slot['fte_bp'];
            $events[$to] = ($events[$to] ?? 0) - $slot['fte_bp'];
        }
        ksort($events, SORT_STRING);
        $occupied = 0;
        foreach ($events as $on => $change) {
            $occupied += $change;
            if ($on < $until && $occupied + $incomingBp > $capacity * 10000) {
                throw new BusinessError(409, 'headcount_exhausted', [
                    'budget_id' => $budgetId ?: null, 'effective_on' => $on,
                ]);
            }
        }
    }

    private function headcountIntervals(array $scope, string $start, string $until, int $budgetId,int $excludePendingSlot=0): array
    {
        return $this->db->rows(
            'SELECT s.starts_on, s.ends_before, COALESCE(f.fte_bp,10000) fte_bp FROM headcount_slots s
             LEFT JOIN headcount_slot_workloads f ON f.slot_id=s.id
             WHERE s.group_id=? AND s.company_id=? AND s.position_id=? AND s.budget_id=? AND s.released_at IS NULL
               AND starts_on<? AND ends_before>?
             UNION ALL
             SELECT a.effective_from AS starts_on, a.effective_until AS ends_before, COALESCE(f.fte_bp,10000) fte_bp FROM assignments a
             JOIN headcount_slots s ON s.group_id=a.group_id AND s.company_id=a.company_id AND s.position_id=a.position_id AND s.id=a.slot_id
             LEFT JOIN headcount_slot_workloads f ON f.slot_id=s.id
             WHERE a.group_id=? AND a.company_id=? AND a.position_id=? AND s.budget_id<>?
               AND a.effective_from<? AND (a.effective_until IS NULL OR a.effective_until>?)
             UNION ALL
             SELECT m.effective_date,NULL,m.reserved_fte FROM internal_moves m JOIN headcount_slots s ON s.id=m.slot_id
             WHERE m.group_id=? AND m.company_id=? AND m.to_position=? AND m.state IN (\'awaiting_signatures\',\'scheduled\')
               AND s.budget_id<>? AND m.effective_date<? AND m.slot_id<>?',
            [...$scope, $budgetId, $until, $start, ...$scope, $budgetId, $until, $start,...$scope,$budgetId,$until,$excludePendingSlot],
        );
    }

    private function knownCompany(Access $actor, int $company): void
    {
        if ($this->db->row('SELECT id FROM companies WHERE group_id=? AND id=?', [$actor->groupId, $company]) === null) {
            throw new BusinessError(404, 'company_not_found');
        }
    }

    private function knownAccount(Access $actor, int $account): void
    {
        if ($this->db->row('SELECT id FROM accounts WHERE group_id=? AND id=? AND enabled=1 FOR UPDATE', [$actor->groupId, $account]) === null) {
            throw new BusinessError(404, 'account_not_found');
        }
    }

    private function departmentOn(Access $actor, int $company, int $department, string $on): void
    {
        if ($this->db->row(
            'SELECT id FROM departments WHERE group_id=? AND company_id=? AND id=? AND effective_from<=?',
            [$actor->groupId, $company, $department, $on],
        ) === null) {
            throw new BusinessError(404, 'department_not_effective');
        }
    }

    private function positionInput(array $input, bool $new): array
    {
        $active = $new ? true : ($input['active'] ?? null);
        if (!is_bool($active)) {
            throw new BusinessError(422, 'invalid_active_flag');
        }
        return [
            'department_id' => Input::integer($input['department_id'] ?? null, 'department_id'),
            'title' => Input::text($input['title'] ?? null, 'title', 160),
            'effective_from' => Input::date($input['effective_from'] ?? null, 'effective_from'),
            'active' => $active,
        ];
    }

    private function insertRevision(Access $actor, int $company, int $position, array $revision): array
    {
        $id = $this->db->insert(
            'INSERT INTO position_revisions (group_id, company_id, position_id, department_id, title, effective_from, active)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $actor->groupId, $company, $position, $revision['department_id'], $revision['title'],
                $revision['effective_from'], (int) $revision['active'],
            ],
        );
        $this->operations->audit($actor, $company, 'position', $position, 'position.revised', [
            'revision_id' => $id, 'effective_from' => $revision['effective_from'], 'active' => $revision['active'],
        ]);
        return ['position_id' => $position, 'revision_id' => $id, ...$revision];
    }
}
