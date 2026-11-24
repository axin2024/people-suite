<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Employment
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly Organization $organization,
    ) {
    }

    public function onboard(Access $actor, int $company, int $position, int $offerId, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $version = Input::integer($input['version'] ?? null, 'version');
        $code = Input::code($input['employee_code'] ?? null, 'employee_code');
        $contract = Input::code($input['contract_ref'] ?? null, 'contract_ref');
        $end = isset($input['contract_ends_before']) ? Input::date($input['contract_ends_before'], 'contract_ends_before') : null;
        return $this->operations->once($actor, $company, 'employment.onboard', $key, [
            'offer_id' => $offerId, 'position_id' => $position, 'version' => $version,
            'employee_code' => $code, 'contract_ref' => $contract, 'contract_ends_before' => $end,
        ], function () use ($actor, $company, $position, $offerId, $version, $code, $contract, $end): array {
            $this->organization->lockPosition($actor, $company, $position);
            $scope = [$actor->groupId, $company, $position, $offerId];
            $offer = $this->db->row(
                'SELECT o.state, o.version, o.effective_on, o.monthly_minor, o.application_id, a.candidate_id
                 FROM offers o JOIN applications a
                 ON a.group_id=o.group_id AND a.company_id=o.company_id AND a.position_id=o.position_id AND a.id=o.application_id
                 WHERE o.group_id=? AND o.company_id=? AND o.position_id=? AND o.id=? FOR UPDATE',
                $scope,
            ) ?? throw new BusinessError(404, 'offer_not_found');
            if ($offer['state'] !== 'accepted' || (int) $offer['version'] !== $version) {
                throw new BusinessError(409, 'offer_state_conflict', ['state' => $offer['state'], 'version' => (int) $offer['version']]);
            }
            $start = $offer['effective_on'];
            $this->contractDates($start, $end);
            $slot = $this->db->row(
                'SELECT id FROM headcount_slots
                 WHERE group_id=? AND company_id=? AND position_id=? AND offer_id=? AND released_at IS NULL',
                $scope,
            ) ?? throw new BusinessError(409, 'offer_slot_missing');
            $revision = $this->organization->activeRevision($actor, $company, $position, $start);
            $this->organization->checkFutureAssignment($actor, $company, $position, (int) $slot['id']);
            $employee = $this->employeeForCandidate($actor, (int) $offer['candidate_id'], $code);
            $overlap = $this->db->row(
                'SELECT id, company_id, starts_on, ends_before FROM employments
                 WHERE group_id=? AND employee_id=? AND (ends_before IS NULL OR ends_before>?) LIMIT 1',
                [$actor->groupId, $employee, $start],
            );
            if ($overlap !== null) {
                throw new BusinessError(409, 'employee_already_assigned');
            }
            $employment = $this->db->insert(
                'INSERT INTO employments (group_id, company_id, employee_id, starts_on) VALUES (?, ?, ?, ?)',
                [$actor->groupId, $company, $employee, $start],
            );
            $assignment = $this->insertAssignment($actor, $company, $employment, $position, $revision, (int) $slot['id'], $start, 1);
            $contractId = $this->insertContract($actor, $company, $employment, 1, $contract, $start, $end, (int) $offer['monthly_minor']);
            $this->db->run(
                'INSERT INTO employment_intakes (group_id, company_id, position_id, offer_id, employment_id) VALUES (?, ?, ?, ?, ?)',
                [...$scope, $employment],
            );
            $this->db->run("UPDATE offers SET state='hired', version=version+1 WHERE id=?", [$offerId]);
            $this->db->run("UPDATE applications SET state='hired', version=version+1 WHERE id=?", [$offer['application_id']]);
            $this->operations->audit($actor, $company, 'employment', $employment, 'employment.onboarded', [
                'offer_id' => $offerId, 'assignment_id' => $assignment, 'contract_id' => $contractId,
            ]);
            return [
                'employee_id' => $employee, 'employment_id' => $employment,
                'assignment_id' => $assignment, 'assignment_version' => 1, 'contract_version' => 1, 'starts_on' => $start,
            ];
        });
    }

    public function renew(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $version = Input::integer($input['version'] ?? null, 'version');
        $reference = Input::code($input['contract_ref'] ?? null, 'contract_ref');
        $start = Input::date($input['effective_from'] ?? null, 'effective_from');
        $end = isset($input['ends_before']) ? Input::date($input['ends_before'], 'ends_before') : null;
        $minor = Input::integer($input['monthly_minor'] ?? null, 'monthly_minor', 1, 100000000000);
        $this->contractDates($start, $end);
        return $this->operations->once($actor, $company, 'contract.renew', $key, [
            'employment_id' => $employment, 'version' => $version, 'contract_ref' => $reference,
            'effective_from' => $start, 'ends_before' => $end, 'monthly_minor' => $minor,
        ], function () use ($actor, $company, $employment, $version, $reference, $start, $end, $minor): array {
            $record = $this->employment($actor, $company, $employment, true);
            $prior = $this->db->row(
                'SELECT version, effective_from, ends_before FROM contract_revisions WHERE group_id=? AND company_id=? AND employment_id=?
                 ORDER BY version DESC LIMIT 1',
                [$actor->groupId, $company, $employment],
            );
            if ((int) $prior['version'] !== $version || $start <= $prior['effective_from']
                || ($record['ends_before'] !== null && $start >= $record['ends_before'])) {
                throw new BusinessError(409, 'contract_revision_conflict', ['current_contract' => $prior]);
            }
            if ($prior['ends_before'] !== null && $start !== $prior['ends_before']) {
                throw new BusinessError(409, 'contract_renewal_not_contiguous', ['available_from' => $prior['ends_before']]);
            }
            $revision = $this->insertContract($actor, $company, $employment, $version + 1, $reference, $start, $end, $minor);
            $this->operations->audit($actor, $company, 'employment', $employment, 'contract.renewed', [
                'contract_id' => $revision, 'contract_version' => $version + 1, 'effective_from' => $start,
            ]);
            return ['contract_id' => $revision, 'version' => $version + 1, 'effective_from' => $start];
        });
    }

    public function transfer(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $position = Input::integer($input['position_id'] ?? null, 'position_id');
        $version = Input::integer($input['assignment_version'] ?? null, 'assignment_version');
        $from = Input::date($input['effective_from'] ?? null, 'effective_from');
        return $this->operations->once($actor, $company, 'employment.transfer', $key, [
            'employment_id' => $employment, 'assignment_version' => $version, 'position_id' => $position, 'effective_from' => $from,
        ], function () use ($actor, $company, $employment, $position, $version, $from): array {
            $record = $this->employment($actor, $company, $employment, false);
            $known = $this->latestAssignment($actor, $company, $employment);
            $this->lockPositions($actor, [(int) $known['position_id'] => $company, $position => $company]);
            $prior = $this->lockCutover($actor, $company, $employment, $record, $known, $version, $from);
            if ((int) $prior['position_id'] === $position) {
                throw new BusinessError(422, 'same_position');
            }
            $revision = $this->organization->activeRevision($actor, $company, $position, $from);
            $slot = $this->organization->reserve($actor, $company, $position, $from);
            $this->organization->checkFutureAssignment($actor, $company, $position, $slot);
            $this->endAssignment($prior, $from);
            $assignment = $this->insertAssignment($actor, $company, $employment, $position, $revision, $slot, $from, $version + 1);
            $this->db->run('UPDATE employments SET assignment_version=assignment_version+1 WHERE id=?', [$employment]);
            $this->operations->audit($actor, $company, 'employment', $employment, 'employment.transferred', [
                'previous_assignment_id' => (int) $prior['id'], 'assignment_id' => $assignment, 'effective_from' => $from,
            ]);
            return [
                'employment_id' => $employment, 'assignment_id' => $assignment, 'assignment_version' => $version + 1,
                'previous' => ['from' => $prior['effective_from'], 'until' => $from, 'department_id' => (int) $prior['department_id']],
                'next' => ['from' => $from, 'until' => null, 'department_id' => (int) $revision['department_id']],
                'schedule_review_from' => $from, 'cost_recalculation_from' => substr($from, 0, 7),
            ];
        });
    }

    public function requestLegalTransfer(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $target = Input::integer($input['target_company_id'] ?? null, 'target_company_id');
        $position = Input::integer($input['target_position_id'] ?? null, 'target_position_id');
        $version = Input::integer($input['assignment_version'] ?? null, 'assignment_version');
        $from = Input::date($input['effective_from'] ?? null, 'effective_from');
        if ($target === $company) {
            throw new BusinessError(422, 'same_company');
        }
        return $this->operations->once($actor, $company, 'legal_transfer.request', $key, [
            'employment_id' => $employment, 'target_company_id' => $target, 'target_position_id' => $position,
            'assignment_version' => $version, 'effective_from' => $from,
        ], function () use ($actor, $company, $employment, $target, $position, $version, $from): array {
            $record = $this->employment($actor, $company, $employment, false);
            $known = $this->latestAssignment($actor, $company, $employment);
            $this->lockPositions($actor, [(int) $known['position_id'] => $company, $position => $target]);
            $this->lockCutover($actor, $company, $employment, $record, $known, $version, $from);
            $this->organization->activeRevision($actor, $target, $position, $from);
            $id = $this->db->insert(
                "INSERT INTO legal_transfers (
                    group_id, source_company_id, source_employment_id, source_version, target_company_id, target_position_id,
                    effective_on, requested_by, state
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [$actor->groupId, $company, $employment, $version, $target, $position, $from, $actor->accountId],
            );
            $this->operations->audit($actor, $company, 'legal_transfer', $id, 'legal_transfer.requested', [
                'target_company_id' => $target, 'effective_on' => $from,
            ]);
            return ['transfer_id' => $id, 'state' => 'pending', 'effective_on' => $from];
        });
    }

    public function acceptLegalTransfer(Access $actor, int $target, int $transferId, string $key, array $input): array
    {
        $actor->company($target, 'hr');
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $reference = Input::code($input['contract_ref'] ?? null, 'contract_ref');
        $minor = Input::integer($input['monthly_minor'] ?? null, 'monthly_minor', 1, 100000000000);
        $end = isset($input['ends_before']) ? Input::date($input['ends_before'], 'ends_before') : null;
        return $this->operations->once($actor, $target, 'legal_transfer.accept', $key, [
            'transfer_id' => $transferId, 'decision_ref' => $decision, 'contract_ref' => $reference,
            'monthly_minor' => $minor, 'ends_before' => $end,
        ], function () use ($actor, $target, $transferId, $decision, $reference, $minor, $end): array {
            $scope = [$actor->groupId, $target, $transferId];
            $transfer = $this->db->row(
                'SELECT id, source_company_id, source_employment_id, source_version, target_position_id,
                        effective_on, state, requested_by
                 FROM legal_transfers WHERE group_id=? AND target_company_id=? AND id=?',
                $scope,
            ) ?? throw new BusinessError(404, 'legal_transfer_not_found');
            if ($transfer['state'] !== 'pending' || (int) $transfer['requested_by'] === $actor->accountId) {
                throw new BusinessError(409, 'legal_transfer_requires_receiver');
            }
            $source = (int) $transfer['source_company_id'];
            $employment = (int) $transfer['source_employment_id'];
            $position = (int) $transfer['target_position_id'];
            $from = $transfer['effective_on'];
            $version = (int) $transfer['source_version'];
            $this->contractDates($from, $end);

            $record = $this->employment($actor, $source, $employment, false);
            $known = $this->latestAssignment($actor, $source, $employment);
            $this->lockPositions($actor, [(int) $known['position_id'] => $source, $position => $target]);
            $prior = $this->lockCutover($actor, $source, $employment, $record, $known, $version, $from);
            $revision = $this->organization->activeRevision($actor, $target, $position, $from);
            $slot = $this->organization->reserve($actor, $target, $position, $from);
            $this->organization->checkFutureAssignment($actor, $target, $position, $slot);

            $this->endAssignment($prior, $from);
            $this->db->run('UPDATE employments SET ends_before=?, assignment_version=assignment_version+1 WHERE id=?', [
                $from, $employment,
            ]);
            $receiving = $this->db->insert(
                'INSERT INTO employments (group_id, company_id, employee_id, starts_on) VALUES (?, ?, ?, ?)',
                [$actor->groupId, $target, $record['employee_id'], $from],
            );
            $assignment = $this->insertAssignment($actor, $target, $receiving, $position, $revision, $slot, $from, 1);
            $this->insertContract($actor, $target, $receiving, 1, $reference, $from, $end, $minor);
            $this->db->run(
                "UPDATE legal_transfers SET state='accepted', accepted_by=?, target_employment_id=?, decision_ref=? WHERE id=?",
                [$actor->accountId, $receiving, $decision, $transferId],
            );
            $this->operations->audit($actor, $source, 'legal_transfer', $transferId, 'legal_transfer.departed', [
                'employment_id' => $employment, 'effective_on' => $from, 'decision_ref' => $decision,
            ]);
            $this->operations->audit($actor, $target, 'legal_transfer', $transferId, 'legal_transfer.received', [
                'employment_id' => $receiving, 'effective_on' => $from, 'decision_ref' => $decision,
            ]);
            return [
                'transfer_id' => $transferId, 'state' => 'accepted', 'effective_on' => $from,
                'employment_id' => $receiving, 'assignment_id' => $assignment, 'decision_ref' => $decision,
            ];
        });
    }

    public function incomingTransfers(Access $actor, int $company, int $after, string $state = 'pending'): array
    {
        $actor->company($company, 'hr');
        if (!in_array($state, ['pending', 'accepted'], true)) {
            throw new BusinessError(422, 'invalid_transfer_state');
        }
        return $this->db->rows(
            self::INCOMING_TRANSFER
            . " WHERE t.group_id=? AND t.target_company_id=? AND t.state=? AND t.id>?
                AND (t.state='accepted' OR (w.assignment_version=t.source_version AND w.ends_before IS NULL))
                ORDER BY t.id LIMIT 100",
            [$actor->groupId, $company, $state, $after],
        );
    }

    public function incomingTransfer(Access $actor, int $company, int $id): array
    {
        $actor->company($company, 'hr');
        return $this->db->row(
            self::INCOMING_TRANSFER . ' WHERE t.group_id=? AND t.target_company_id=? AND t.id=?',
            [$actor->groupId, $company, $id],
        ) ?? throw new BusinessError(404, 'legal_transfer_not_found');
    }

    private const INCOMING_TRANSFER = <<<'SQL'
        SELECT t.id, t.effective_on, t.source_company_id, t.target_position_id, t.target_employment_id,
               t.requested_by, t.state, t.decision_ref, e.employee_code, e.display_name,
               c.name AS source_company_name, p.code AS target_position_code,
               (w.assignment_version<>t.source_version OR w.ends_before IS NOT NULL) AND t.state='pending' AS stale
        FROM legal_transfers t JOIN employments w
          ON w.group_id=t.group_id AND w.company_id=t.source_company_id AND w.id=t.source_employment_id
        JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
        JOIN companies c ON c.group_id=t.group_id AND c.id=t.source_company_id
        JOIN positions p ON p.group_id=t.group_id AND p.company_id=t.target_company_id AND p.id=t.target_position_id
        SQL;

    public function outgoingTransfers(Access $actor, int $company, int $employment, int $after): array
    {
        $actor->company($company, 'hr');
        $this->employment($actor, $company, $employment, false);
        return $this->db->rows(
            "SELECT t.id, t.effective_on, t.target_company_id, c.name AS target_company_name, p.code AS target_position_code,
                    t.state, t.decision_ref,
                    (w.assignment_version<>t.source_version OR w.ends_before IS NOT NULL) AND t.state='pending' AS stale
             FROM legal_transfers t
             JOIN employments w ON w.group_id=t.group_id AND w.company_id=t.source_company_id AND w.id=t.source_employment_id
             JOIN companies c ON c.group_id=t.group_id AND c.id=t.target_company_id
             JOIN positions p ON p.group_id=t.group_id AND p.company_id=t.target_company_id AND p.id=t.target_position_id
             WHERE t.group_id=? AND t.source_company_id=? AND t.source_employment_id=? AND t.id>? ORDER BY t.id LIMIT 100",
            [$actor->groupId, $company, $employment, $after],
        );
    }

    public function directory(Access $actor, int $company, int $after): array
    {
        $actor->company($company, 'hr');
        return $this->db->rows(
            'SELECT w.id, e.employee_code, e.display_name, w.starts_on, w.ends_before, w.assignment_version
             FROM employments w JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
             WHERE w.group_id=? AND w.company_id=? AND w.id>? ORDER BY w.id LIMIT 100',
            [$actor->groupId, $company, $after],
        );
    }

    public function history(Access $actor, int $company, int $id): array
    {
        $actor->company($company, 'hr');
        return $this->db->transaction(function () use ($actor, $company, $id): array {
            $record = $this->employment($actor, $company, $id, false);
            $scope = [$actor->groupId, $company, $id];
            return [
                'employment' => $record,
                'employee' => $this->db->row('SELECT id, employee_code, display_name FROM employees WHERE group_id=? AND id=?', [
                    $actor->groupId, $record['employee_id'],
                ]),
                'assignments' => $this->db->rows(self::ASSIGNMENT_DETAILS . ' ORDER BY a.version', $scope),
                'contracts' => $this->db->rows(
                    'SELECT id, version, reference, effective_from, ends_before, monthly_minor FROM contract_revisions
                     WHERE group_id=? AND company_id=? AND employment_id=? ORDER BY version',
                    $scope,
                ),
            ];
        }, repeatableRead: true);
    }

    public function assignmentOn(Access $actor, int $company, int $id, string $on): array
    {
        $actor->company($company, 'hr');
        $this->employment($actor, $company, $id, false);
        return $this->db->row(
            self::ASSIGNMENT_DETAILS . ' AND a.effective_from<=? AND (a.effective_until IS NULL OR a.effective_until>?)',
            [$actor->groupId, $company, $id, $on, $on],
        ) ?? throw new BusinessError(404, 'assignment_not_effective');
    }

    private const ASSIGNMENT_DETAILS = <<<'SQL'
        SELECT a.id, a.version, a.position_id, a.position_revision_id, a.department_id, a.effective_from, a.effective_until,
               p.code AS position_code, r.title AS position_title, d.name AS department_name
        FROM assignments a
        JOIN positions p ON p.group_id=a.group_id AND p.company_id=a.company_id AND p.id=a.position_id
        JOIN position_revisions r ON r.group_id=a.group_id AND r.company_id=a.company_id AND r.position_id=a.position_id
          AND r.id=a.position_revision_id
        JOIN departments d ON d.group_id=a.group_id AND d.company_id=a.company_id AND d.id=a.department_id
        WHERE a.group_id=? AND a.company_id=? AND a.employment_id=?
        SQL;

    private function employeeForCandidate(Access $actor, int $candidateId, string $code): int
    {
        $candidate = $this->db->row('SELECT display_name FROM candidates WHERE group_id=? AND id=? FOR UPDATE', [
            $actor->groupId, $candidateId,
        ]);
        $row = $this->db->row('SELECT id, employee_code FROM employees WHERE group_id=? AND candidate_id=? FOR UPDATE', [
            $actor->groupId, $candidateId,
        ]);
        if ($row !== null) {
            if (strcasecmp($row['employee_code'], $code) !== 0) {
                throw new BusinessError(409, 'existing_employee_code');
            }
            return (int) $row['id'];
        }
        return $this->db->insert(
            'INSERT INTO employees (group_id, candidate_id, employee_code, display_name) VALUES (?, ?, ?, ?)',
            [$actor->groupId, $candidateId, $code, $candidate['display_name']],
        );
    }

    private function employment(Access $actor, int $company, int $id, bool $lock): array
    {
        return $this->db->row(
            'SELECT id, employee_id, starts_on, ends_before, assignment_version
             FROM employments WHERE group_id=? AND company_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$actor->groupId, $company, $id],
        ) ?? throw new BusinessError(404, 'employment_not_found');
    }

    private function latestAssignment(Access $actor, int $company, int $employment): array
    {
        return $this->db->row(
            'SELECT id, position_id, department_id, slot_id, effective_from, effective_until FROM assignments
             WHERE group_id=? AND company_id=? AND employment_id=? ORDER BY version DESC LIMIT 1',
            [$actor->groupId, $company, $employment],
        ) ?? throw new BusinessError(409, 'assignment_missing');
    }

    private function lockPositions(Access $actor, array $positions): void
    {
        ksort($positions, SORT_NUMERIC);
        foreach ($positions as $position => $company) {
            $this->organization->lockPosition($actor, $company, $position);
        }
    }

    private function lockCutover(
        Access $actor,
        int $company,
        int $employment,
        array $record,
        array $known,
        int $version,
        string $from,
        bool $offboarding = false,
    ): array {
        $this->db->row('SELECT id FROM employees WHERE group_id=? AND id=? FOR UPDATE', [
            $actor->groupId, $record['employee_id'],
        ]);
        $record = $this->employment($actor, $company, $employment, true);
        $prior = $this->latestAssignment($actor, $company, $employment);
        if ((int) $record['assignment_version'] !== $version || (int) $known['id'] !== (int) $prior['id']
            || $from <= $prior['effective_from'] || $prior['effective_until'] !== null || $record['ends_before'] !== null) {
            throw new BusinessError(409, 'assignment_interval_conflict', [
                'current_version' => (int) $record['assignment_version'],
                'from' => $prior['effective_from'], 'until' => $prior['effective_until'],
            ]);
        }
        if (!$offboarding && $this->db->row(
            "SELECT id FROM offboarding_cases WHERE group_id=? AND company_id=? AND employment_id=? AND state='in_progress'",
            [$actor->groupId, $company, $employment],
        ) !== null) {
            throw new BusinessError(409, 'offboarding_new_work_frozen');
        }
        return $prior;
    }

    public function lockOffboardingCutover(Access $actor, int $company, int $employment, int $version, string $until): array
    {
        $record = $this->employment($actor, $company, $employment, false);
        $known = $this->latestAssignment($actor, $company, $employment);
        $this->lockPositions($actor, [(int) $known['position_id'] => $company]);
        $assignment = $this->lockCutover($actor, $company, $employment, $record, $known, $version, $until, offboarding: true);
        $identity = $this->db->row('SELECT account_id FROM employees WHERE group_id=? AND id=?', [$actor->groupId, $record['employee_id']]);
        return ['assignment' => $assignment, 'employee_id' => (int) $record['employee_id'], 'account_id' => $identity['account_id']];
    }

    public function applyOffboardingEnd(Access $actor, int $company, int $employment, array $assignment, string $until): void
    {
        $scope = [$actor->groupId, $company, $employment];
        $this->endAssignment($assignment, $until);
        $this->db->run('UPDATE employments SET ends_before=?, assignment_version=assignment_version+1 WHERE group_id=? AND company_id=? AND id=?',
            [$until, ...$scope]);
        $this->db->run('UPDATE reporting_lines SET effective_until=? WHERE group_id=? AND company_id=? AND employment_id=?
            AND effective_from<? AND (effective_until IS NULL OR effective_until>?)', [$until, ...$scope, $until, $until]);
    }

    private function endAssignment(array $assignment, string $from): void
    {
        $this->db->run('UPDATE assignments SET effective_until=? WHERE id=?', [$from, $assignment['id']]);
        $this->db->run(
            'UPDATE headcount_slots SET ends_before=LEAST(ends_before, ?) WHERE id=? AND starts_on<?',
            [$from, $assignment['slot_id'], $from],
        );
    }

    private function insertAssignment(
        Access $actor,
        int $company,
        int $employment,
        int $position,
        array $revision,
        int $slot,
        string $start,
        int $version,
    ): int {
        return $this->db->insert(
            'INSERT INTO assignments
                (group_id, company_id, employment_id, position_id, position_revision_id, slot_id, department_id, effective_from, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$actor->groupId, $company, $employment, $position, $revision['id'], $slot, $revision['department_id'], $start, $version],
        );
    }

    private function insertContract(
        Access $actor,
        int $company,
        int $employment,
        int $version,
        string $reference,
        string $start,
        ?string $end,
        int $minor,
    ): int {
        return $this->db->insert(
            'INSERT INTO contract_revisions
                (group_id, company_id, employment_id, version, reference, effective_from, ends_before, monthly_minor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$actor->groupId, $company, $employment, $version, $reference, $start, $end, $minor],
        );
    }

    private function contractDates(string $start, ?string $end): void
    {
        if ($end !== null && $end <= $start) {
            throw new BusinessError(422, 'empty_contract_period');
        }
    }
}
