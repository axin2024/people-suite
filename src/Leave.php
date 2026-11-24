<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Leave
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly WorkforceAccess $workforce,
    ) {
    }

    public function grant(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $kind = Input::code($input['kind'] ?? null, 'kind');
        $year = Input::integer($input['year'] ?? null, 'year', 1000, 9999);
        $minutes = Input::integer($input['minutes'] ?? null, 'minutes', 1, 527040);
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        return $this->operations->once($actor, $company, 'leave.grant', $key, [
            'employment_id' => $employment, 'kind' => $kind, 'year' => $year,
            'minutes' => $minutes, 'decision_ref' => $decision,
        ], function () use ($actor, $company, $employment, $kind, $year, $minutes, $decision): array {
            $employmentRow = $this->workforce->lockEmployment($actor, $company, $employment);
            if ((int) substr($employmentRow['starts_on'], 0, 4) > $year
                || ($employmentRow['ends_before'] !== null && $employmentRow['ends_before'] <= $year . '-01-01')) {
                throw new BusinessError(409, 'leave_year_outside_employment');
            }
            $scope = [$actor->groupId, $company, $employment];
            $this->db->run(
                'INSERT INTO leave_balances (group_id, company_id, employment_id, leave_kind, leave_year)
                 VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE leave_year=leave_balances.leave_year',
                [...$scope, $kind, $year],
            );
            $balance = $this->balance($scope, $kind, $year);
            if ($balance['granted_minutes'] + $minutes > 527040) {
                throw new BusinessError(409, 'leave_grant_exceeds_year');
            }
            $this->db->run('UPDATE leave_balances SET granted_minutes=granted_minutes+? WHERE id=?', [$minutes, $balance['id']]);
            $this->ledger($actor, $company, $employment, (int) $balance['id'], null, null, 'grant', $decision, $minutes, 0, 0);
            return $this->balanceView($this->balance($scope, $kind, $year));
        });
    }

    public function request(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $kind = Input::code($input['kind'] ?? null, 'kind');
        $day = Input::date($input['workday'] ?? null, 'workday');
        $minutes = Input::integer($input['minutes'] ?? null, 'minutes', 1, 1440);
        $reason = Input::text($input['reason'] ?? null, 'reason', 600);
        $decision = Input::code($input['reference'] ?? null, 'reference');
        $this->requester($actor, $company, $employment, $day);
        return $this->operations->once($actor, $company, 'leave.request', $key, [
            'employment_id' => $employment, 'kind' => $kind, 'workday' => $day,
            'minutes' => $minutes, 'reason' => $reason, 'reference' => $decision,
        ], function () use ($actor, $company, $employment, $kind, $day, $minutes, $reason, $decision): array {
            $this->workforce->lockEmployment($actor, $company, $employment, $day);
            $this->requester($actor, $company, $employment, $day);
            $scope = [$actor->groupId, $company, $employment];
            $balance = $this->balance($scope, $kind, (int) substr($day, 0, 4));
            $available = $balance['granted_minutes'] - $balance['reserved_minutes'] - $balance['used_minutes'];
            if ($minutes > $available) {
                throw new BusinessError(409, 'leave_balance_insufficient', ['available_minutes' => $available]);
            }
            $sameDay = $this->db->row(
                "SELECT COALESCE(SUM(minutes),0) AS minutes FROM leave_requests
                 WHERE group_id=? AND company_id=? AND employment_id=? AND workday=? AND state IN ('pending','approved')",
                [...$scope, $day],
            );
            if ($sameDay['minutes'] + $minutes > 1440) {
                throw new BusinessError(409, 'leave_day_overallocated');
            }
            $id = $this->db->insert(
                "INSERT INTO leave_requests
                    (group_id, company_id, employment_id, balance_id, workday, minutes, state, reason, submitted_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
                [...$scope, $balance['id'], $day, $minutes, $reason, $actor->accountId],
            );
            $this->db->run('UPDATE leave_balances SET reserved_minutes=reserved_minutes+? WHERE id=?', [$minutes, $balance['id']]);
            $this->ledger($actor, $company, $employment, (int) $balance['id'], $id, 1, 'reserve', $decision, 0, $minutes, 0);
            return ['leave_id' => $id, 'state' => 'pending', 'version' => 1, 'workday' => $day, 'reserved_minutes' => $minutes];
        });
    }

    public function decide(Access $actor, int $company, int $employment, int $id, string $action, string $key, array $input): array
    {
        $next = match ($action) {
            'approve' => 'approved',
            'deny' => 'denied',
            'cancel' => 'cancelled',
            default => throw new BusinessError(404, 'operation_not_found'),
        };
        $request = $this->find($actor, $company, $employment, $id);
        $this->workforce->visibility($actor, $company, $employment, $request['workday'], $action === 'cancel');
        $expected = Input::integer($input['version'] ?? null, 'version');
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        return $this->operations->once($actor, $company, 'leave.' . $action, $key, [
            'employment_id' => $employment, 'leave_id' => $id, 'version' => $expected, 'decision_ref' => $decision,
        ], function () use ($actor, $company, $employment, $id, $action, $next, $expected, $decision, $request): array {
            $worker = $this->workforce->lockEmployment($actor, $company, $employment, $action === 'approve' ? $request['workday'] : null);
            $role = $this->workforce->visibility($actor, $company, $employment, $request['workday'], $action === 'cancel');
            $record = $this->find($actor, $company, $employment, $id);
            $valid = $record['state'] === 'pending' || ($action === 'cancel' && $record['state'] === 'approved');
            if ((int) $record['version'] !== $expected || !$valid) {
                throw new BusinessError(409, 'leave_state_conflict', ['state' => $record['state'], 'version' => (int) $record['version']]);
            }
            if ($action === 'approve' && $this->workforce->isSelf($actor->groupId, (int) $worker['employee_id'], $actor->accountId)) {
                throw new BusinessError(403, 'leave_self_approval');
            }
            if ($action === 'cancel' && $record['state'] === 'approved' && $role === 'employee') {
                throw new BusinessError(403, 'approved_leave_cancellation_requires_review');
            }
            $minutes = (int) $record['minutes'];
            $reservedChange = $record['state'] === 'pending' ? -$minutes : 0;
            $usedChange = match (true) {
                $action === 'approve' => $minutes,
                $record['state'] === 'approved' => -$minutes,
                default => 0,
            };
            $this->db->run(
                'UPDATE leave_balances SET reserved_minutes=CAST(reserved_minutes AS SIGNED)+?, used_minutes=CAST(used_minutes AS SIGNED)+?
                 WHERE id=?',
                [$reservedChange, $usedChange, $record['balance_id']],
            );
            $this->db->run('UPDATE leave_requests SET state=?, version=version+1 WHERE id=?', [$next, $id]);
            $this->ledger(
                $actor, $company, $employment, (int) $record['balance_id'], $id, $expected + 1, $action, $decision, 0,
                $reservedChange, $usedChange,
            );
            return ['leave_id' => $id, 'state' => $next, 'version' => $expected + 1, 'workday' => $record['workday'],
                'reserved_change' => $reservedChange, 'used_change' => $usedChange];
        });
    }

    public function detail(Access $actor, int $company, int $employment, int $id): array
    {
        $request = $this->find($actor, $company, $employment, $id);
        $this->workforce->visibility($actor, $company, $employment, $request['workday'], true);
        $entries = $this->db->rows(
            'SELECT action, version, decision_ref, reserved_change, used_change, actor_id, recorded_at
             FROM leave_ledger WHERE group_id=? AND company_id=? AND employment_id=? AND balance_id=? AND request_id=?
             ORDER BY version',
            [$actor->groupId, $company, $employment, $request['balance_id'], $id],
        );
        return ['request' => $request, 'ledger' => $entries];
    }

    public function summary(Access $actor, int $company, int $employment, string $day): array
    {
        $role = $this->workforce->visibility($actor, $company, $employment, $day, true);
        $scope = [$actor->groupId, $company, $employment];
        $requests = $this->db->rows(
            'SELECT r.id, r.workday, b.leave_kind, r.minutes, r.state, r.version FROM leave_requests r
             JOIN leave_balances b ON b.group_id=r.group_id AND b.company_id=r.company_id AND b.employment_id=r.employment_id AND b.id=r.balance_id
             WHERE r.group_id=? AND r.company_id=? AND r.employment_id=? AND r.workday=? ORDER BY r.id',
            [...$scope, $day],
        );
        $balances = $role === 'manager' ? [] : $this->db->rows(
            'SELECT id, leave_kind, leave_year, granted_minutes, reserved_minutes, used_minutes FROM leave_balances
             WHERE group_id=? AND company_id=? AND employment_id=? AND leave_year=? ORDER BY leave_kind',
            [...$scope, (int) substr($day, 0, 4)],
        );
        return ['requests' => $requests, 'balances' => array_map($this->balanceView(...), $balances)];
    }

    private function requester(Access $actor, int $company, int $employment, string $day): void
    {
        if ($this->workforce->visibility($actor, $company, $employment, $day, true) === 'manager') {
            throw new BusinessError(404, 'employment_not_found');
        }
    }

    private function find(Access $actor, int $company, int $employment, int $id): array
    {
        return $this->db->row(
            'SELECT id, balance_id, workday, minutes, state, reason, version, submitted_by FROM leave_requests
             WHERE group_id=? AND company_id=? AND employment_id=? AND id=?',
            [$actor->groupId, $company, $employment, $id],
        ) ?? throw new BusinessError(404, 'leave_not_found');
    }

    private function balance(array $scope, string $kind, int $year): array
    {
        return $this->db->row(
            'SELECT id, leave_kind, leave_year, granted_minutes, reserved_minutes, used_minutes FROM leave_balances
             WHERE group_id=? AND company_id=? AND employment_id=? AND leave_kind=? AND leave_year=? FOR UPDATE',
            [...$scope, $kind, $year],
        ) ?? throw new BusinessError(409, 'leave_entitlement_missing', ['kind' => $kind, 'year' => $year]);
    }

    private function balanceView(array $row): array
    {
        return [
            ...$row,
            'available_minutes' => $row['granted_minutes'] - $row['reserved_minutes'] - $row['used_minutes'],
        ];
    }

    private function ledger(
        Access $actor,
        int $company,
        int $employment,
        int $balance,
        ?int $request,
        ?int $version,
        string $action,
        string $decision,
        int $granted,
        int $reserved,
        int $used,
    ): void {
        $this->db->run(
            'INSERT INTO leave_ledger
                (group_id, company_id, employment_id, balance_id, request_id, version, actor_id, action, decision_ref,
                 granted_change, reserved_change, used_change)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$actor->groupId, $company, $employment, $balance, $request, $version, $actor->accountId,
                $action, $decision, $granted, $reserved, $used],
        );
        $this->operations->audit($actor, $company, $request === null ? 'leave_balance' : 'leave_request', $request ?? $balance,
            'leave.' . $action, ['decision_ref' => $decision, 'version' => $version]);
    }
}
