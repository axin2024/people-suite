<?php

declare(strict_types=1);

namespace PeopleSuite;

final class EmployeeServices
{
    private const REQUEST_COLUMNS = 'id, employment_id, employee_account_id, reference, kind, details, requested_name, state,
        version, submitted_at, decided_by, decision_ref, response, outcome, decided_at';

    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly WorkforceAccess $workforce,
    ) {
    }

    public function profile(Access $actor, int $company, mixed $on): array
    {
        $current = $actor->employeeRecords($company);
        $day = $on === null ? $this->workforce->companyToday($actor, $company) : Input::date($on, 'on');
        $person = $this->db->row(
            'SELECT id, employee_code, display_name FROM employees WHERE group_id=? AND account_id=?',
            [$actor->groupId, $actor->accountId],
        ) ?? throw new BusinessError(404, 'employee_profile_not_found');
        $records = $this->db->rows(
            'SELECT w.id AS employment_id, w.starts_on, w.ends_before, a.position_id, r.title AS position_title,
                    a.department_id, d.name AS department_name
             FROM employments w LEFT JOIN assignments a ON a.group_id=w.group_id AND a.company_id=w.company_id
                AND a.employment_id=w.id AND a.effective_from<=? AND (a.effective_until IS NULL OR a.effective_until>?)
             LEFT JOIN position_revisions r ON r.group_id=a.group_id AND r.company_id=a.company_id AND r.id=a.position_revision_id
             LEFT JOIN departments d ON d.group_id=a.group_id AND d.company_id=a.company_id AND d.id=a.department_id
             WHERE w.group_id=? AND w.company_id=? AND w.employee_id=?
              AND (? OR EXISTS (SELECT 1 FROM archived_employment_access x WHERE x.group_id=w.group_id AND x.company_id=w.company_id
                  AND x.employment_id=w.id AND x.account_id=?)) ORDER BY w.starts_on DESC',
            [$day, $day, $actor->groupId, $company, $person['id'], (int) $current, $actor->accountId],
        );
        if ($records === []) {
            throw new BusinessError(404, 'employee_profile_not_found');
        }
        return ['employee' => $person, 'as_of' => $day, 'employments' => $records];
    }

    public function submit(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'employee');
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['employment_certificate', 'name_correction', 'other'], true)) {
            throw new BusinessError(422, 'invalid_employee_request_kind');
        }
        $request = [
            'employment_id' => Input::integer($input['employment_id'] ?? null, 'employment_id'),
            'reference' => Input::code($input['reference'] ?? null, 'reference'),
            'kind' => $kind,
            'details' => Input::text($input['details'] ?? null, 'details', 1600),
            'requested_name' => $kind === 'name_correction' ? Input::text($input['requested_name'] ?? null, 'requested_name', 160) : null,
        ];
        return $this->operations->once($actor, $company, 'employee_request.submitted', $key, $request,
            function () use ($actor, $company, $request): array {
                $employment = $request['employment_id'];
                $worker = $this->workforce->lockEmployment($actor, $company, $employment);
                $actor->company($company, 'employee');
                if (!$this->workforce->isSelf($actor->groupId, (int) $worker['employee_id'], $actor->accountId)) {
                    throw new BusinessError(404, 'employment_not_found');
                }
                $scope = [$actor->groupId, $company, $employment];
                $previous = $this->db->row(
                    'SELECT id, kind, details, requested_name FROM employee_requests
                     WHERE group_id=? AND company_id=? AND employment_id=? AND reference=?',
                    [...$scope, $request['reference']],
                );
                if ($previous !== null) {
                    foreach (['kind', 'details', 'requested_name'] as $field) {
                        if ($previous[$field] !== $request[$field]) {
                            throw new BusinessError(409, 'employee_request_reference_conflict');
                        }
                    }
                    return self::submitted((int) $previous['id'], $request['reference']);
                }
                $this->workforce->requireNewWorkAllowed($actor, $company, $employment);
                $day = $this->workforce->companyToday($actor, $company);
                if ($day < $worker['starts_on'] || ($worker['ends_before'] !== null && $day >= $worker['ends_before'])) {
                    throw new BusinessError(409, 'employee_request_requires_active_employment');
                }
                $id = $this->db->insert(
                    'INSERT INTO employee_requests (group_id, company_id, employment_id, employee_account_id, reference,
                            kind, details, requested_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$scope, $actor->accountId, $request['reference'], $request['kind'], $request['details'], $request['requested_name']],
                );
                $this->operations->audit($actor, $company, 'employee_request', $id, 'employee_request.submitted', [
                    'employment_id' => $employment, 'reference' => $request['reference'], 'kind' => $request['kind'],
                ]);
                return self::submitted($id, $request['reference']);
            },
        );
    }

    public function myRequests(Access $actor, int $company, int $after): array
    {
        $current = $actor->employeeRecords($company);
        $rows = $this->db->rows(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM employee_requests r
             WHERE r.group_id=? AND r.company_id=? AND r.employee_account_id=? AND r.id>?
               AND (? OR EXISTS (SELECT 1 FROM archived_employment_access x WHERE x.group_id=r.group_id AND x.company_id=r.company_id
                 AND x.employment_id=r.employment_id AND x.account_id=r.employee_account_id)) ORDER BY r.id LIMIT 100',
            [$actor->groupId, $company, $actor->accountId, $after, (int) $current],
        );
        return array_map($this->employeeView(...), $rows);
    }

    public function reviewQueue(Access $actor, int $company, int $after, mixed $state): array
    {
        $actor->company($company, 'hr');
        if ($state !== null && !in_array($state, ['pending', 'approved', 'rejected', 'withdrawn'], true)) {
            throw new BusinessError(422, 'invalid_employee_request_state');
        }
        $filter = $state === null ? '' : ' AND state=?';
        $parameters = $state === null ? [] : [$state];
        $rows = $this->db->rows(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM employee_requests WHERE group_id=? AND company_id=? AND id>?' . $filter . ' ORDER BY id LIMIT 100',
            [$actor->groupId, $company, $after, ...$parameters],
        );
        return array_map($this->decodeOutcome(...), $rows);
    }

    public function detail(Access $actor, int $company, int $id): array
    {
        $roles = $actor->roles($company);
        $record = $this->request([$actor->groupId, $company, $id]);
        if (in_array('hr', $roles, true)) {
            return $this->decodeOutcome($record);
        }
        if ((int) $record['employee_account_id'] !== $actor->accountId || (!in_array('employee', $roles, true)
            && !$actor->archiveGranted($company, (int) $record['employment_id']))) {
            throw new BusinessError(404, 'employee_request_not_found');
        }
        return $this->employeeView($record);
    }

    public function decide(Access $actor, int $company, int $id, string $action, string $key, array $input): array
    {
        $state = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'withdraw' => 'withdrawn',
            default => throw new BusinessError(404, 'route_not_found'),
        };
        $withdrawing = $action === 'withdraw';
        $actor->company($company, $withdrawing ? 'employee' : 'hr');
        $version = Input::integer($input['version'] ?? null, 'version');
        $reference = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $response = Input::text($input['response'] ?? null, 'response', 1600);
        return $this->operations->once($actor, $company, 'employee_request.' . $state, $key, [
            'request_id' => $id, 'version' => $version, 'decision_ref' => $reference, 'response' => $response,
        ], function () use ($actor, $company, $id, $state, $withdrawing, $version, $reference, $response): array {
            $scope = [$actor->groupId, $company, $id];
            $original = $this->request($scope);
            $owner = (int) $original['employee_account_id'] === $actor->accountId;
            if ($withdrawing && !$owner) {
                throw new BusinessError(404, 'employee_request_not_found');
            }
            if (!$withdrawing && $owner) {
                throw new BusinessError(403, 'employee_request_independent_reviewer_required');
            }
            $employment = (int) $original['employment_id'];
            $identity = $this->db->row(
                'SELECT e.id, e.employee_code, e.display_name FROM employees e JOIN employments w ON w.group_id=e.group_id AND w.employee_id=e.id
                 WHERE w.group_id=? AND w.company_id=? AND w.id=? FOR UPDATE OF e',
                [$actor->groupId, $company, $employment],
            );
            $worker = $this->workforce->lockEmployment($actor, $company, $employment);
            $record = $this->request($scope, lock: true);
            if ($record['state'] !== 'pending' || (int) $record['version'] !== $version) {
                throw new BusinessError(409, 'employee_request_conflict', ['state' => $record['state'], 'version' => (int) $record['version']]);
            }
            $outcome = $state === 'approved' ? $this->approve($actor, $company, $identity, $worker, $record) : null;
            $this->db->run(
                'UPDATE employee_requests SET state=?, version=2, decision_ref=?, response=?, outcome=?, decided_by=?, decided_at=CURRENT_TIMESTAMP(6)
                 WHERE group_id=? AND company_id=? AND id=?',
                [$state, $reference, $response, $outcome === null ? null : json_encode($outcome, JSON_THROW_ON_ERROR), $actor->accountId, ...$scope],
            );
            $this->operations->audit($actor, $company, 'employee_request', $id, 'employee_request.' . $state, [
                'employment_id' => $employment, 'decision_ref' => $reference,
            ]);
            return ['request_id' => $id, 'state' => $state, 'version' => 2, 'decision_ref' => $reference];
        });
    }

    private function approve(Access $actor, int $company, array $person, array $worker, array $request): array
    {
        if ($request['kind'] === 'name_correction') {
            $this->db->run('UPDATE employees SET display_name=? WHERE group_id=? AND id=?', [$request['requested_name'], $actor->groupId, $person['id']]);
            return ['previous_name' => $person['display_name'], 'display_name' => $request['requested_name']];
        }
        if ($request['kind'] === 'employment_certificate') {
            $employer = $this->db->row('SELECT name FROM companies WHERE group_id=? AND id=?', [$actor->groupId, $company]);
            return ['employer' => $employer['name'], 'employee_code' => $person['employee_code'], 'employee_name' => $person['display_name'],
                'starts_on' => $worker['starts_on'], 'ends_before' => $worker['ends_before'], 'issued_on' => $this->workforce->companyToday($actor, $company)];
        }
        return ['review_completed' => true];
    }

    private function request(array $scope, bool $lock = false): array
    {
        return $this->db->row('SELECT ' . self::REQUEST_COLUMNS . ' FROM employee_requests WHERE group_id=? AND company_id=? AND id=?'
            . ($lock ? ' FOR UPDATE' : ''), $scope) ?? throw new BusinessError(404, 'employee_request_not_found');
    }

    private function employeeView(array $row): array
    {
        unset($row['employee_account_id'], $row['decided_by']);
        return $this->decodeOutcome($row);
    }

    private function decodeOutcome(array $row): array
    {
        $row['outcome'] = $row['outcome'] === null ? null : json_decode($row['outcome'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    private static function submitted(int $id, string $reference): array
    {
        return ['request_id' => $id, 'reference' => $reference, 'state' => 'pending', 'version' => 1];
    }
}
