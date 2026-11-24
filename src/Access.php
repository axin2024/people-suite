<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Access
{
    public function __construct(
        private readonly Database $db,
        public readonly int $groupId,
        public readonly int $accountId,
    ) {
    }

    public function administrator(): void
    {
        if (!$this->isAdministrator()) {
            throw new BusinessError(403, 'group_admin_required');
        }
    }

    public function isAdministrator(): bool
    {
        return $this->db->row('SELECT 1 FROM group_administrators WHERE group_id=? AND account_id=?', [
            $this->groupId, $this->accountId,
        ]) !== null;
    }

    public function company(int $companyId, string ...$allowed): void
    {
        if (array_intersect($this->roles($companyId), $allowed) === []) {
            throw new BusinessError(404, 'company_not_found');
        }
    }

    public function roles(int $companyId): array
    {
        return array_column($this->db->rows(
            'SELECT role FROM company_roles WHERE group_id=? AND company_id=? AND account_id=?',
            [$this->groupId, $companyId, $this->accountId],
        ), 'role');
    }

    public function employeeRecords(int $company): bool
    {
        $current = in_array('employee', $this->roles($company), true);
        if (!$current && !$this->archiveGranted($company)) {
            throw new BusinessError(404, 'company_not_found');
        }
        return $current;
    }

    public function archiveGranted(int $company, ?int $employment = null): bool
    {
        $scope = [$this->groupId, $company, $this->accountId];
        $filter = '';
        if ($employment !== null) {
            $filter = ' AND employment_id=?';
            $scope[] = $employment;
        }
        return $this->db->row(
            'SELECT 1 FROM archived_employment_access WHERE group_id=? AND company_id=? AND account_id=?' . $filter . ' LIMIT 1',
            $scope,
        ) !== null;
    }

    public function recruitment(int $companyId, int $positionId): void
    {
        $this->company($companyId, 'hr', 'recruiter');
        $scope = $this->db->row(
            'SELECT p.id FROM positions p
             WHERE p.group_id=? AND p.company_id=? AND p.id=? AND (
                 EXISTS (SELECT 1 FROM company_roles r WHERE r.group_id=p.group_id
                         AND r.company_id=p.company_id AND r.account_id=? AND r.role=?)
                 OR (
                     EXISTS (SELECT 1 FROM company_roles r WHERE r.group_id=p.group_id
                             AND r.company_id=p.company_id AND r.account_id=? AND r.role=?)
                     AND EXISTS (SELECT 1 FROM recruiter_positions r WHERE r.group_id=p.group_id
                                 AND r.company_id=p.company_id AND r.position_id=p.id AND r.account_id=?)
                 )
             )',
            [$this->groupId, $companyId, $positionId, $this->accountId, 'hr', $this->accountId, 'recruiter', $this->accountId],
        );
        if ($scope === null) {
            throw new BusinessError(404, 'position_not_found');
        }
    }
}
