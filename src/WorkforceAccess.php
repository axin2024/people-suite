<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;
use DateTimeZone;

final class WorkforceAccess
{
    public function __construct(private readonly Database $db, private readonly Operations $operations)
    {
    }

    public function linkAccount(Access $actor, int $company, int $employment, string $key, int $account): array
    {
        $actor->company($company, 'hr');
        return $this->operations->once($actor, $company, 'employment.link_account', $key, [
            'employment_id' => $employment, 'account_id' => $account,
        ], function () use ($actor, $company, $employment, $account): array {
            $record = $this->db->row('SELECT employee_id FROM employments WHERE group_id=? AND company_id=? AND id=?', [
                $actor->groupId, $company, $employment,
            ]) ?? throw new BusinessError(404, 'employment_not_found');
            $person = $this->db->row('SELECT account_id FROM employees WHERE group_id=? AND id=? FOR UPDATE', [
                $actor->groupId, $record['employee_id'],
            ]);
            $this->lockEmployment($actor, $company, $employment);
            $this->requireNewWorkAllowed($actor, $company, $employment);
            $role = $this->db->row(
                "SELECT a.id FROM company_roles r JOIN accounts a ON a.group_id=r.group_id AND a.id=r.account_id
                 WHERE r.group_id=? AND r.company_id=? AND r.account_id=? AND r.role='employee' AND a.enabled=1",
                [$actor->groupId, $company, $account],
            );
            if ($role === null) {
                throw new BusinessError(404, 'employee_account_not_found');
            }
            if ($person['account_id'] !== null && (int) $person['account_id'] !== $account) {
                throw new BusinessError(409, 'employee_account_already_linked');
            }
            if ($person['account_id'] === null) {
                $this->db->run('UPDATE employees SET account_id=? WHERE id=?', [$account, $record['employee_id']]);
                $this->operations->audit($actor, $company, 'employment', $employment, 'employee.account_linked', [
                    'employee_id' => (int) $record['employee_id'], 'account_id' => $account,
                ]);
            }
            return ['employment_id' => $employment, 'account_id' => $account];
        });
    }

    public function assignManager(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $manager = Input::integer($input['account_id'] ?? null, 'account_id');
        $start = Input::date($input['effective_from'] ?? null, 'effective_from');
        return $this->operations->once($actor, $company, 'employment.reporting_line', $key, [
            'employment_id' => $employment, 'manager_account_id' => $manager, 'effective_from' => $start,
        ], function () use ($actor, $company, $employment, $manager, $start): array {
            $employmentRow = $this->lockEmployment($actor, $company, $employment, $start);
            $this->requireNewWorkAllowed($actor, $company, $employment, $start);
            $scope = [$actor->groupId, $company, $employment];
            $role = $this->db->row(
                "SELECT r.account_id FROM company_roles r
                 JOIN accounts a ON a.group_id=r.group_id AND a.id=r.account_id
                 WHERE r.group_id=? AND r.company_id=? AND r.account_id=? AND r.role='manager' AND a.enabled=1",
                [$actor->groupId, $company, $manager],
            );
            if ($role === null || $this->isSelf($actor->groupId, (int) $employmentRow['employee_id'], $manager)) {
                throw new BusinessError(404, 'manager_not_found');
            }
            $latest = $this->db->row(
                'SELECT effective_from, manager_account_id FROM reporting_lines
                 WHERE group_id=? AND company_id=? AND employment_id=?
                 ORDER BY effective_from DESC LIMIT 1',
                $scope,
            );
            if ($latest !== null && $latest['effective_from'] >= $start) {
                throw new BusinessError(409, 'reporting_line_overlap', ['current' => $latest]);
            }
            if ($latest !== null) {
                $this->db->run(
                    'UPDATE reporting_lines SET effective_until=?
                     WHERE group_id=? AND company_id=? AND employment_id=? AND effective_from=? AND effective_until IS NULL',
                    [$start, ...$scope, $latest['effective_from']],
                );
            }
            $this->db->run(
                'INSERT INTO reporting_lines
                    (group_id, company_id, employment_id, manager_account_id, effective_from, effective_until)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [...$scope, $manager, $start, $employmentRow['ends_before']],
            );
            $this->operations->audit($actor, $company, 'employment', $employment, 'reporting_line.assigned', [
                'manager_account_id' => $manager, 'effective_from' => $start,
            ]);
            return ['employment_id' => $employment, 'manager_account_id' => $manager, 'effective_from' => $start];
        });
    }

    public function visibility(Access $actor, int $company, int $employment, string $day, bool $selfAllowed): string
    {
        $roles = $actor->roles($company);
        if (in_array('hr', $roles, true)) {
            return 'hr';
        }
        if (in_array('manager', $roles, true) && $this->db->row(
            'SELECT 1 FROM reporting_lines r JOIN employments w
               ON w.group_id=r.group_id AND w.company_id=r.company_id AND w.id=r.employment_id
             WHERE r.group_id=? AND r.company_id=? AND r.employment_id=? AND r.manager_account_id=?
                 AND r.effective_from<=? AND (r.effective_until IS NULL OR r.effective_until>?)
                 AND w.starts_on<=? AND (w.ends_before IS NULL OR w.ends_before>?)',
            [$actor->groupId, $company, $employment, $actor->accountId, $day, $day, $day, $day],
        ) !== null) {
            return 'manager';
        }
        if ($selfAllowed && in_array('employee', $roles, true) && $this->db->row(
            'SELECT 1 FROM employments w JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
             WHERE w.group_id=? AND w.company_id=? AND w.id=? AND e.account_id=?',
            [$actor->groupId, $company, $employment, $actor->accountId],
        ) !== null) {
            return 'employee';
        }
        throw new BusinessError(404, 'employment_not_found');
    }

    public function lockEmployment(Access $actor, int $company, int $id, ?string $day = null): array
    {
        $row = $this->db->row(
            'SELECT employee_id, starts_on, ends_before FROM employments WHERE group_id=? AND company_id=? AND id=? FOR UPDATE',
            [$actor->groupId, $company, $id],
        );
        if ($row === null) {
            throw new BusinessError(404, 'employment_not_found');
        }
        if ($day !== null && ($day < $row['starts_on'] || ($row['ends_before'] !== null && $day >= $row['ends_before']))) {
            throw new BusinessError(409, 'employment_not_effective', ['workday' => $day]);
        }
        return $row;
    }

    public function isSelf(int $group, int $employee, int $account): bool
    {
        return $this->db->row('SELECT 1 FROM employees WHERE group_id=? AND id=? AND account_id=?', [
            $group, $employee, $account,
        ]) !== null;
    }

    public function companyToday(Access $actor, int $company): string
    {
        $row = $this->db->row('SELECT timezone FROM companies WHERE group_id=? AND id=?', [$actor->groupId, $company])
            ?? throw new BusinessError(404, 'company_not_found');
        return (new DateTimeImmutable('today', new DateTimeZone($row['timezone'])))->format('Y-m-d');
    }

    public function requireNewWorkAllowed(Access $actor, int $company, int $employment, ?string $scheduledOn = null): void
    {
        $active = $this->db->row(
            "SELECT id, ends_before FROM offboarding_cases WHERE group_id=? AND company_id=? AND employment_id=? AND state='in_progress'",
            [$actor->groupId, $company, $employment],
        );
        if ($active !== null && ($scheduledOn === null || $scheduledOn >= $active['ends_before'])) {
            throw new BusinessError(409, 'offboarding_new_work_frozen', ['case_id' => (int) $active['id'], 'ends_before' => $active['ends_before']]);
        }
    }
}
