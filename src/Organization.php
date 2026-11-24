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
                if ($holds !== []) {
                    throw new BusinessError(409, 'position_still_occupied', ['occupancies' => $holds]);
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
    ): int {
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
        $overlap = $this->db->rows(
            'SELECT starts_on, ends_before FROM headcount_slots
             WHERE group_id=? AND company_id=? AND position_id=? AND budget_id=? AND released_at IS NULL
               AND starts_on<? AND ends_before>?',
            [$actor->groupId, $company, $position, $budget['id'], $until, $start],
        );
        $events = [$start => 0];
        foreach ($overlap as $slot) {
            $from = max($start, $slot['starts_on']);
            $to = min($until, $slot['ends_before']);
            $events[$from] = ($events[$from] ?? 0) + 1;
            $events[$to] = ($events[$to] ?? 0) - 1;
        }
        ksort($events, SORT_STRING);
        $occupied = 0;
        foreach ($events as $on => $change) {
            $occupied += $change;
            if ($on < $until && $occupied >= (int) $budget['capacity']) {
                throw new BusinessError(409, 'headcount_exhausted', [
                    'budget_id' => (int) $budget['id'], 'effective_on' => $on,
                ]);
            }
        }
        return $this->db->insert(
            'INSERT INTO headcount_slots (group_id, company_id, position_id, budget_id, offer_id, starts_on, ends_before)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$actor->groupId, $company, $position, $budget['id'], $offer, $start, $until],
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
        if ($this->db->row('SELECT id FROM accounts WHERE group_id=? AND id=? AND enabled=1', [$actor->groupId, $account]) === null) {
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
