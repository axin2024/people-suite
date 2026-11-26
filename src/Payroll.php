<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;

final class Payroll
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly Attendance $attendance,
        private readonly AttendanceCertifications $certifications,
        private readonly PayrollCalculator $calculator,
        private readonly PayrollAdjustments $adjustments,
    ) {
    }

    public function rules(Access $actor, int $company, int $after): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        return $this->db->rows(
            'SELECT id, revision, rule_ref, title, proration_basis, monthly_allowance_minor, absence_hourly_minor,
                    withholding_bps, withholding_exempt_minor, created_at
             FROM pay_rules WHERE group_id=? AND company_id=? AND id>? ORDER BY id LIMIT 100',
            [$actor->groupId, $company, $after],
        );
    }

    public function runs(Access $actor, int $company, int $after): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        return $this->db->rows(
            'SELECT r.id, r.period_start, r.period_end, r.version, r.state, r.trial_revision, t.eligible_count, t.blocked_count
             FROM payroll_runs r LEFT JOIN payroll_trials t
               ON t.group_id=r.group_id AND t.company_id=r.company_id AND t.run_id=r.id AND t.revision=r.trial_revision
             WHERE r.group_id=? AND r.company_id=? AND r.id>? ORDER BY r.id LIMIT 100',
            [$actor->groupId, $company, $after],
        );
    }

    public function createRule(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'payroll_maker');
        $reference = Input::code($input['rule_ref'] ?? null, 'rule_ref');
        $title = Input::text($input['title'] ?? null, 'title', 160);
        $basis = $input['proration_basis'] ?? null;
        if (!in_array($basis, ['actual_days', 'fixed_30'], true)) {
            throw new BusinessError(422, 'invalid_proration_basis');
        }
        $rule = [
            'rule_ref' => $reference, 'title' => $title, 'proration_basis' => $basis,
            'monthly_allowance_minor' => Input::integer($input['monthly_allowance_minor'] ?? 0, 'monthly_allowance_minor', 0, 100000000000),
            'absence_hourly_minor' => Input::integer($input['absence_hourly_minor'] ?? 0, 'absence_hourly_minor', 0, 1000000000),
            'withholding_bps' => Input::integer($input['withholding_bps'] ?? 0, 'withholding_bps', 0, 10000),
            'withholding_exempt_minor' => Input::integer($input['withholding_exempt_minor'] ?? 0, 'withholding_exempt_minor', 0, 100000000000),
        ];
        return $this->operations->once($actor, $company, 'payroll.rule_created', $key, $rule, function () use ($actor, $company, $rule): array {
            $scope = [$actor->groupId, $company];
            $this->db->run(
                'INSERT INTO pay_rule_sequences (group_id, company_id, latest_revision) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE latest_revision=pay_rule_sequences.latest_revision+1',
                $scope,
            );
            $revision = (int) $this->db->row(
                'SELECT latest_revision FROM pay_rule_sequences WHERE group_id=? AND company_id=?', $scope,
            )['latest_revision'];
            $id = $this->db->insert(
                'INSERT INTO pay_rules (
                    group_id, company_id, revision, rule_ref, title, proration_basis, monthly_allowance_minor,
                    absence_hourly_minor, withholding_bps, withholding_exempt_minor, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $revision, ...array_values($rule), $actor->accountId],
            );
            $this->operations->audit($actor, $company, 'pay_rule', $id, 'payroll.rule_created', ['revision' => $revision]);
            return ['rule_id' => $id, 'revision' => $revision, ...$rule];
        });
    }

    public function createRun(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'payroll_maker');
        $start = Input::date($input['period_start'] ?? null, 'period_start');
        if (!str_ends_with($start, '-01') || $start >= '9999-12-01') {
            throw new BusinessError(422, 'period_must_start_on_first_day');
        }
        $end = (new DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d');
        return $this->operations->once($actor, $company, 'payroll.create_run', $key, [
            'period_start' => $start,
        ], function () use ($actor, $company, $start, $end): array {
            $id = $this->db->insert(
                'INSERT INTO payroll_runs (group_id, company_id, period_start, period_end, created_by) VALUES (?, ?, ?, ?, ?)',
                [$actor->groupId, $company, $start, $end, $actor->accountId],
            );
            $this->operations->audit($actor, $company, 'payroll_run', $id, 'payroll.run_created', ['period_start' => $start]);
            return ['run_id' => $id, 'version' => 1, 'state' => 'draft', 'period_start' => $start, 'period_end' => $end];
        });
    }

    public function trial(Access $actor, int $company, int $id, string $key, array $input): array
    {
        $actor->company($company, 'payroll_maker');
        $version = Input::integer($input['version'] ?? null, 'version');
        $rule = Input::integer($input['rule_id'] ?? null, 'rule_id');
        $watermark = Input::integer($input['attendance_watermark'] ?? null, 'attendance_watermark', 0);
        return $this->operations->once($actor, $company, 'payroll.trial', $key, [
            'run_id' => $id, 'version' => $version, 'rule_id' => $rule, 'attendance_watermark' => $watermark,
        ], function () use ($actor, $company, $id, $version, $rule, $watermark): array {
            $scope = [$actor->groupId, $company];
            $run = $this->run($scope, $id, true);
            if ((int) $run['version'] !== $version || !in_array($run['state'], ['draft', 'calculated'], true)) {
                throw new BusinessError(409, 'payroll_run_conflict', ['version' => (int) $run['version'], 'state' => $run['state']]);
            }
            $settings = $this->rule($scope, $rule);
            $start = $run['period_start'];
            $end = $run['period_end'];
            if ($settings['proration_basis'] === 'fixed_30' && (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days !== 30) {
                throw new BusinessError(422, 'payroll_period_incompatible_with_rule');
            }
            if ($watermark > $this->attendance->watermark($actor, $company)) {
                throw new BusinessError(422, 'attendance_watermark_not_reached');
            }

            $revision = (int) $run['trial_revision'] + 1;
            $trialId = $this->db->insert(
                'INSERT INTO payroll_trials (group_id, company_id, run_id, revision, rule_id, attendance_watermark, calculated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $id, $revision, $rule, $watermark, $actor->accountId],
            );
            $summary = ['eligible_count' => 0, 'blocked_count' => 0, 'gross_minor' => 0, 'net_minor' => 0, 'cost_minor' => 0];
            $workers = $this->db->rows(
                'SELECT w.id, w.starts_on, w.ends_before, e.employee_code, e.display_name
                 FROM employments w JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
                 WHERE w.group_id=? AND w.company_id=? AND w.starts_on<? AND (w.ends_before IS NULL OR w.ends_before>?)
                 ORDER BY w.id',
                [...$scope, $end, $start],
            );
            foreach ($workers as $worker) {
                $summary['eligible_count']++;
                $calculated = $this->calculateWorker($scope, $start, $end, $worker, $settings, $watermark, $trialId);
                if ($calculated === null) {
                    $summary['blocked_count']++;
                    continue;
                }
                foreach (['gross_minor','net_minor','cost_minor'] as $total) {
                    $summary[$total] += $calculated[$total];
                }
            }
            $summary['eligible_count'] += $this->adjustments->attachToTrial($scope, $id, $trialId);
            $this->db->run(
                'UPDATE payroll_trials SET eligible_count=?, blocked_count=?, gross_minor=?, net_minor=?, cost_minor=? WHERE id=?',
                [...array_values($summary), $trialId],
            );
            $this->db->run(
                "UPDATE payroll_runs SET state='calculated', trial_revision=?, version=version+1 WHERE id=?",
                [$revision, $id],
            );
            $this->operations->audit($actor, $company, 'payroll_run', $id, 'payroll.trial_calculated', [
                'trial_id' => $trialId, 'trial_revision' => $revision, 'attendance_watermark' => $watermark,
                'eligible_count' => $summary['eligible_count'], 'blocked_count' => $summary['blocked_count'],
            ]);
            return [
                'run_id' => $id, 'trial_id' => $trialId, 'version' => $version + 1, 'trial_revision' => $revision,
                'state' => 'calculated', 'attendance_watermark' => $watermark, ...$this->withAdjustedTotals($scope, $trialId, $summary),
            ];
        }, repeatableRead: true);
    }

    public function detail(Access $actor, int $company, int $id, int $after): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        $scope = [$actor->groupId, $company];
        $run = $this->run($scope, $id);
        if ((int) $run['trial_revision'] === 0) {
            return ['run' => $run, 'trial' => null, 'payslips' => [], 'review_events' => [], 'closure' => null];
        }
        $trial = $this->currentTrial($scope, $run);
        $payslips = $this->db->rows(
            'SELECT id, employment_id, employee_code, employee_name, state, gross_minor, absence_minor, withholding_minor,
                    net_minor, cost_minor, facts
             FROM payslips WHERE group_id=? AND company_id=? AND trial_id=? AND id>? ORDER BY id LIMIT 100',
            [...$scope, $trial['id'], $after],
        );
        foreach ($payslips as &$payslip) {
            $payslip['facts'] = json_decode($payslip['facts'], true, flags: JSON_THROW_ON_ERROR);
        }
        unset($payslip);
        $payslips = $this->adjustments->withPayableAmounts($scope, $payslips);
        $reviews = $this->db->rows(
            'SELECT e.trial_id, e.resulting_version, e.actor_id, e.action, e.decision_ref, e.notes, e.recorded_at,
                    d.id AS delegation_id, d.granted_by
             FROM payroll_review_events e LEFT JOIN payroll_review_delegations d
               ON d.group_id=e.group_id AND d.company_id=e.company_id AND d.run_id=e.run_id
                  AND d.resulting_version=e.resulting_version AND d.trial_id=e.trial_id AND d.delegate_id=e.actor_id
             WHERE e.group_id=? AND e.company_id=? AND e.run_id=? ORDER BY e.resulting_version',
            [...$scope, $id],
        );
        return ['run' => $run, 'trial' => $this->withAdjustedTotals($scope, (int) $trial['id'], $trial),
            'payslips' => $payslips, 'review_events' => $reviews,
            'delegation' => $run['state'] === 'in_review' ? $this->activeDelegation($scope, $id, (int) $run['version']) : null,
            'closure' => $this->closure($scope, $id)];
    }

    public function delegationCandidates(Access $actor, int $company, int $id, int $after): array
    {
        $actor->company($company, 'payroll_reviewer');
        $scope = [$actor->groupId, $company];
        $run = $this->run($scope, $id);
        $this->assertReviewState($run, (int) $run['version'], 'in_review');
        $trial = $this->currentTrial($scope, $run);
        if ((int) $trial['calculated_by'] === $actor->accountId) {
            throw new BusinessError(403, 'payroll_reviewer_must_differ');
        }
        return $this->db->rows(
            "SELECT a.id, a.display_name FROM company_roles r JOIN accounts a
               ON a.group_id=r.group_id AND a.id=r.account_id AND a.enabled=1
             WHERE r.group_id=? AND r.company_id=? AND r.role='payroll_reviewer'
               AND r.account_id>? AND r.account_id NOT IN (?, ?) ORDER BY r.account_id LIMIT 100",
            [...$scope, $after, $actor->accountId, $trial['calculated_by']],
        );
    }

    public function setDelegation(Access $actor, int $company, int $id, string $key, array $input, bool $assign): array
    {
        $actor->company($company, 'payroll_reviewer');
        $version = Input::integer($input['version'] ?? null, 'version');
        $prior = Input::integer($input['previous_delegation_id'] ?? 0, 'previous_delegation_id', $assign ? 0 : 1);
        $delegate = $assign ? Input::integer($input['delegate_id'] ?? null, 'delegate_id') : null;
        $hours = $assign ? Input::integer($input['hours'] ?? 24, 'hours', 1, 168) : null;
        $notes = Input::text($input['notes'] ?? null, 'notes', 600);
        $action = $assign ? 'payroll.delegation_assigned' : 'payroll.delegation_revoked';
        return $this->operations->once($actor, $company, $action, $key, [
            'run_id' => $id, 'version' => $version, 'previous_delegation_id' => $prior,
            'delegate_id' => $delegate, 'hours' => $hours, 'notes' => $notes,
        ], function () use ($actor, $company, $id, $version, $prior, $delegate, $hours, $notes, $action): array {
            $scope = [$actor->groupId, $company];
            $run = $this->run($scope, $id, true);
            $this->assertReviewState($run, $version, 'in_review');
            $trial = $this->currentTrial($scope, $run);
            $maker = (int) $trial['calculated_by'];
            if ($maker === $actor->accountId || $maker === $delegate) {
                throw new BusinessError(403, 'payroll_reviewer_must_differ');
            }
            $existing = $this->activeDelegation($scope, $id, $version);
            if ((int) ($existing['id'] ?? 0) !== $prior) {
                throw new BusinessError(409, 'payroll_delegation_conflict');
            }
            if ($delegate !== null) {
                if ($delegate === $actor->accountId) {
                    throw new BusinessError(422, 'payroll_delegate_must_differ');
                }
                $this->lockReviewers($scope, $actor->accountId, $delegate);
            } else {
                if ((int) $existing['granted_by'] !== $actor->accountId) {
                    throw new BusinessError(403, 'payroll_delegation_grantor_required');
                }
                $this->lockReviewers($scope, $actor->accountId);
            }
            if ($existing !== null) {
                $this->finishDelegation($scope, $id, $prior, $delegate === null ? 'revoked' : 'superseded');
            }
            $result = ['run_id' => $id, 'version' => $version, 'trial_id' => (int) $trial['id']];
            if ($delegate === null) {
                $result += ['delegation_id' => $prior, 'delegate_id' => (int) $existing['delegate_id'], 'state' => 'revoked'];
            } else {
                $this->db->insert(
                    'INSERT INTO payroll_review_delegations
                        (group_id, company_id, run_id, run_version, trial_id, granted_by, delegate_id, notes, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, TIMESTAMPADD(HOUR, ?, CURRENT_TIMESTAMP(6)))',
                    [...$scope, $id, $version, $trial['id'], $actor->accountId, $delegate, $notes, $hours],
                );
                $current = $this->activeDelegation($scope, $id, $version);
                $result += ['delegation_id' => (int) $current['id'], 'delegate_id' => $delegate,
                    'state' => 'assigned', 'expires_at' => $current['expires_at']];
            }
            $this->operations->audit($actor, $company, 'payroll_run', $id, $action, [
                ...$result, 'previous_delegation_id' => $prior, 'notes' => $notes,
            ]);
            return $result;
        });
    }

    public function review(Access $actor, int $company, int $id, string $action, string $key, array $input): array
    {
        $needed = match ($action) {
            'submit' => 'calculated',
            'return', 'close' => 'in_review',
            'publish' => 'closed',
            default => throw new BusinessError(404, 'operation_not_found'),
        };
        $actor->company($company, $action === 'submit' ? 'payroll_maker' : 'payroll_reviewer');
        $version = Input::integer($input['version'] ?? null, 'version');
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $notes = Input::text($input['notes'] ?? null, 'notes', 600);
        $delegationId = isset($input['delegation_id']) ? Input::integer($input['delegation_id'], 'delegation_id') : null;
        if ($delegationId !== null && !in_array($action, ['return', 'close'], true)) {
            throw new BusinessError(422, 'payroll_action_cannot_be_delegated');
        }
        return $this->operations->once($actor, $company, 'payroll.' . $action, $key, [
            'run_id' => $id, 'version' => $version, 'decision_ref' => $decision, 'notes' => $notes,
            ...($delegationId === null ? [] : ['delegation_id' => $delegationId]),
        ], function () use ($actor, $company, $id, $action, $needed, $version, $decision, $notes, $delegationId): array {
            $scope = [$actor->groupId, $company];
            $run = $this->run($scope, $id, true);
            $this->assertReviewState($run, $version, $needed);
            $trial = $this->currentTrial($scope, $run);
            $preparedByActor = (int) $trial['calculated_by'] === $actor->accountId;
            if ($action === 'submit' && !$preparedByActor) {
                throw new BusinessError(403, 'payroll_trial_maker_required');
            }
            if ($action !== 'submit' && $preparedByActor) {
                throw new BusinessError(403, 'payroll_reviewer_must_differ');
            }
            $activeDelegation = $needed === 'in_review' ? $this->activeDelegation($scope, $id, $version) : null;
            $delegationFacts = [];
            if ($delegationId !== null) {
                if ($activeDelegation === null || (int) $activeDelegation['id'] !== $delegationId
                    || (int) $activeDelegation['trial_id'] !== (int) $trial['id'] || $activeDelegation['expired']) {
                    throw new BusinessError(409, 'payroll_delegation_conflict');
                }
                if ((int) $activeDelegation['delegate_id'] !== $actor->accountId) {
                    throw new BusinessError(403, 'payroll_delegate_required');
                }
                $grantor = (int) $activeDelegation['granted_by'];
                $this->lockReviewers($scope, $actor->accountId, $grantor);
                $delegationFacts = ['delegation_id' => $delegationId, 'granted_by' => $grantor];
            }
            if ($action === 'submit') {
                $this->adjustments->assertIncluded($scope, $id, (int) $trial['id']);
            }
            if ($action === 'submit' && ((int) $trial['blocked_count'] > 0 || (int) $trial['eligible_count'] === 0)) {
                throw new BusinessError(409, 'payroll_trial_blocked', [
                    'blocked_count' => (int) $trial['blocked_count'], 'eligible_count' => (int) $trial['eligible_count'],
                ]);
            }

            $next = match ($action) {
                'submit' => 'in_review',
                'return' => 'calculated',
                'close' => 'closed',
                'publish' => 'published',
            };
            $digest = null;
            if ($action === 'close' || $action === 'publish') {
                $digest = $this->snapshotDigest($scope, $run, $trial);
                if ($action === 'close') {
                    $this->db->run(
                        'INSERT INTO payroll_closures (group_id, company_id, run_id, trial_id, closed_by, digest)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [...$scope, $id, $trial['id'], $actor->accountId, $digest],
                    );
                } else {
                    $closure = $this->closure($scope, $id);
                    if ($closure === null || (int) $closure['trial_id'] !== (int) $trial['id'] || !hash_equals($closure['digest'], $digest)) {
                        throw new BusinessError(409, 'payroll_closure_mismatch');
                    }
                }
            }
            $this->db->run(
                'INSERT INTO payroll_review_events (
                    group_id, company_id, run_id, trial_id, resulting_version, actor_id, action, decision_ref, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $id, $trial['id'], $version + 1, $actor->accountId, $action, $decision, $notes],
            );
            if ($activeDelegation !== null) {
                $this->finishDelegation($scope, $id, (int) $activeDelegation['id'], $delegationId === null ? 'superseded' : 'used');
            }
            $this->db->run('UPDATE payroll_runs SET state=?, version=version+1 WHERE id=?', [$next, $id]);
            $this->operations->audit($actor, $company, 'payroll_run', $id, 'payroll.' . $action, [
                'trial_id' => (int) $trial['id'], 'decision_ref' => $decision, 'version' => $version + 1, 'digest' => $digest,
                ...$delegationFacts,
            ]);
            return [
                'run_id' => $id, 'trial_id' => (int) $trial['id'], 'version' => $version + 1, 'state' => $next,
                'decision_ref' => $decision, 'digest' => $digest, ...$delegationFacts,
            ];
        });
    }

    private function activeDelegation(array $scope, int $id, int $version): ?array
    {
        return $this->db->row(
            'SELECT id, trial_id, granted_by, delegate_id, notes, expires_at, expires_at<=CURRENT_TIMESTAMP(6) AS expired
             FROM payroll_review_delegations WHERE group_id=? AND company_id=? AND run_id=? AND assigned_version=?',
            [...$scope, $id, $version],
        );
    }

    private function finishDelegation(array $scope, int $id, int $delegation, string $state): void
    {
        $this->db->run(
            'UPDATE payroll_review_delegations SET state=?, finished_at=CURRENT_TIMESTAMP(6)
             WHERE group_id=? AND company_id=? AND run_id=? AND id=?',
            [$state, ...$scope, $id, $delegation],
        );
    }

    private function lockReviewers(array $scope, int ...$accounts): void
    {
        sort($accounts, SORT_NUMERIC);
        foreach ($accounts as $account) {
            if ($this->db->row(
                "SELECT a.id FROM company_roles r JOIN accounts a ON a.group_id=r.group_id AND a.id=r.account_id
                 WHERE r.group_id=? AND r.company_id=? AND r.account_id=? AND r.role='payroll_reviewer' AND a.enabled=1 FOR SHARE",
                [...$scope, $account],
            ) === null) {
                throw new BusinessError(404, 'payroll_reviewer_not_found');
            }
        }
    }

    private function assertReviewState(array $run, int $version, string $state): void
    {
        if ((int) $run['version'] !== $version || $run['state'] !== $state) {
            throw new BusinessError(409, 'payroll_run_conflict', ['version' => (int) $run['version'], 'state' => $run['state']]);
        }
    }

    public function verifyClosure(Access $actor, int $company, int $id): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        $scope = [$actor->groupId, $company];
        $run = $this->run($scope, $id);
        $closed = $this->closure($scope, $id) ?? throw new BusinessError(404, 'payroll_closure_not_found');
        $trial = $this->currentTrial($scope, $run);
        $digest = $this->snapshotDigest($scope, $run, $trial);
        return ['run_id' => $id, 'digest' => $closed['digest'],
            'valid' => (int) $closed['trial_id'] === (int) $trial['id'] && hash_equals($closed['digest'], $digest)];
    }

    public function myPayslips(Access $actor, int $company, int $after): array
    {
        $current = $actor->employeeRecords($company);
        $scope = [$actor->groupId, $company];
        $payslips = $this->db->rows(
            'SELECT p.id, r.period_start, r.period_end, p.gross_minor, p.absence_minor, p.withholding_minor, p.net_minor, c.digest
             FROM payslips p JOIN employments w ON w.group_id=p.group_id AND w.company_id=p.company_id AND w.id=p.employment_id
             JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
             JOIN payroll_closures c ON c.group_id=p.group_id AND c.company_id=p.company_id AND c.trial_id=p.trial_id
             JOIN payroll_runs r ON r.group_id=c.group_id AND r.company_id=c.company_id AND r.id=c.run_id
             WHERE p.group_id=? AND p.company_id=? AND e.account_id=? AND p.id>?
               AND r.state=? AND (? OR EXISTS (SELECT 1 FROM archived_employment_access a WHERE a.group_id=p.group_id
                   AND a.company_id=p.company_id AND a.employment_id=p.employment_id AND a.account_id=e.account_id))
             ORDER BY p.id LIMIT 100',
            [...$scope, $actor->accountId, $after, 'published', (int) $current],
        );
        return $this->adjustments->withPayableAmounts($scope, $payslips);
    }

    public function myPayslip(Access $actor, int $company, int $id): array
    {
        $summary = $this->publishedSummary($actor, $company, $id);
        unset($summary['employment_id']);
        return [...$summary, ...$this->storedPayslip($actor, $company, $id, employeeView: true)];
    }

    public function publishedSummary(Access $actor, int $company, int $id): array
    {
        $current = $actor->employeeRecords($company);
        $summary = $this->db->row(
            'SELECT r.period_start, r.period_end, c.digest, p.employment_id
             FROM payslips p JOIN employments w ON w.group_id=p.group_id AND w.company_id=p.company_id AND w.id=p.employment_id
             JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
             JOIN payroll_closures c ON c.group_id=p.group_id AND c.company_id=p.company_id AND c.trial_id=p.trial_id
             JOIN payroll_runs r ON r.group_id=c.group_id AND r.company_id=c.company_id AND r.id=c.run_id
             WHERE p.group_id=? AND p.company_id=? AND e.account_id=? AND p.id=? AND r.state=?',
            [$actor->groupId, $company, $actor->accountId, $id, 'published'],
        ) ?? throw new BusinessError(404, 'payslip_not_found');
        if (!$current && !$actor->archiveGranted($company, (int) $summary['employment_id'])) {
            throw new BusinessError(404, 'payslip_not_found');
        }
        return $summary;
    }

    public function payslip(Access $actor, int $company, int $id): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        return $this->storedPayslip($actor, $company, $id);
    }

    private function storedPayslip(Access $actor, int $company, int $id, bool $employeeView = false): array
    {
        $scope = [$actor->groupId, $company, $id];
        $record = $this->db->row(
            'SELECT id, trial_id, employment_id, attendance_certification_id, employee_code, employee_name, state,
                    gross_minor, absence_minor, withholding_minor, net_minor, cost_minor, facts
             FROM payslips WHERE group_id=? AND company_id=? AND id=?',
            $scope,
        ) ?? throw new BusinessError(404, 'payslip_not_found');
        $segments = $this->db->rows(
            'SELECT line_no, department_id, starts_on, ends_before, base_minor, allowance_minor, absence_minor, cost_minor, facts
             FROM payslip_segments WHERE group_id=? AND company_id=? AND payslip_id=? ORDER BY line_no',
            $scope,
        );
        $record['facts'] = json_decode($record['facts'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($segments as &$segment) {
            $segment['facts'] = json_decode($segment['facts'], true, flags: JSON_THROW_ON_ERROR);
        }
        unset($segment);
        $adjustments = $this->adjustments->forPayslip([$actor->groupId, $company], $id, $employeeView);
        $record['payable_minor'] = $record['net_minor'] === null
            ? null : (int) $record['net_minor'] + array_sum(array_column($adjustments, 'payable_delta_minor'));
        return ['payslip' => $record, 'segments' => $segments, 'adjustments' => $adjustments];
    }

    private function withAdjustedTotals(array $scope, int $trial, array $summary): array
    {
        $adjustments = $this->adjustments->totals($scope, $trial);
        return [...$summary, ...$adjustments,
            'payable_minor' => (int) $summary['net_minor'] + $adjustments['adjustment_minor'],
            'total_cost_minor' => (int) $summary['cost_minor'] + $adjustments['gross_adjustment_minor']];
    }

    private function currentTrial(array $scope, array $run): array
    {
        return $this->db->row(
            'SELECT id, revision, rule_id, attendance_watermark, calculated_by, eligible_count, blocked_count,
                    gross_minor, net_minor, cost_minor, calculated_at
             FROM payroll_trials WHERE group_id=? AND company_id=? AND run_id=? AND revision=?',
            [...$scope, $run['id'], $run['trial_revision']],
        ) ?? throw new BusinessError(409, 'payroll_trial_missing');
    }

    private function closure(array $scope, int $run): ?array
    {
        return $this->db->row(
            'SELECT trial_id, closed_by, digest, closed_at FROM payroll_closures WHERE group_id=? AND company_id=? AND run_id=?',
            [...$scope, $run],
        );
    }

    private function snapshotDigest(array $scope, array $run, array $trial): string
    {
        $hash = hash_init('sha256');
        $rule = $this->rule($scope, (int) $trial['rule_id']);
        hash_update($hash, json_encode([
            'payroll-closure-v1', $scope, $run['id'], $run['period_start'], $run['period_end'], $trial, $rule,
        ], JSON_THROW_ON_ERROR) . "\n");
        $parameters = [...$scope, $trial['id']];
        $payslips = $this->db->rows(
            'SELECT id, employment_id, attendance_certification_id, employee_code, employee_name, state,
                    gross_minor, absence_minor, withholding_minor, net_minor, cost_minor, facts
             FROM payslips WHERE group_id=? AND company_id=? AND trial_id=? ORDER BY id',
            $parameters,
        );
        foreach ($payslips as $payslip) {
            hash_update($hash, json_encode($payslip, JSON_THROW_ON_ERROR) . "\n");
        }
        $segments = $this->db->rows(
            'SELECT s.payslip_id, s.line_no, s.department_id, s.starts_on, s.ends_before, s.base_minor, s.allowance_minor,
                    s.absence_minor, s.cost_minor, s.facts
             FROM payslip_segments s JOIN payslips p ON p.group_id=s.group_id AND p.company_id=s.company_id AND p.id=s.payslip_id
             WHERE p.group_id=? AND p.company_id=? AND p.trial_id=? ORDER BY s.payslip_id, s.line_no',
            $parameters,
        );
        foreach ($segments as $segment) {
            hash_update($hash, json_encode($segment, JSON_THROW_ON_ERROR) . "\n");
        }
        foreach ($this->adjustments->forTrial($scope, (int) $trial['id']) as $adjustment) {
            hash_update($hash, json_encode(['payroll-adjustment-v1', $adjustment], JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function run(array $scope, int $id, bool $lock = false): array
    {
        return $this->db->row(
            'SELECT id, period_start, period_end, version, trial_revision, state, created_by, created_at
             FROM payroll_runs WHERE group_id=? AND company_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            [...$scope, $id],
        ) ?? throw new BusinessError(404, 'payroll_run_not_found');
    }

    private function rule(array $scope, int $id): array
    {
        return $this->db->row(
            'SELECT id, revision, proration_basis, monthly_allowance_minor, absence_hourly_minor, withholding_bps, withholding_exempt_minor
             FROM pay_rules WHERE group_id=? AND company_id=? AND id=?',
            [...$scope, $id],
        ) ?? throw new BusinessError(404, 'pay_rule_not_found');
    }

    private function calculateWorker(
        array $scope,
        string $start,
        string $end,
        array $worker,
        array $rule,
        int $watermark,
        int $trialId,
    ): ?array {
        $employment = (int) $worker['id'];
        try {
            $certified = $this->certifications->forPayroll($scope[0], $scope[1], $employment, $start, $end, $watermark);
            $assignment = $this->db->rows(
                'SELECT id, department_id, effective_from, effective_until
                 FROM assignments WHERE group_id=? AND company_id=? AND employment_id=? AND effective_from<?
                   AND (effective_until IS NULL OR effective_until>?) ORDER BY version',
                [...$scope, $employment, $end, $start],
            );
            $contracts = $this->db->rows(
                'SELECT id, effective_from, ends_before, monthly_minor FROM contract_revisions
                 WHERE group_id=? AND company_id=? AND employment_id=? AND effective_from<? ORDER BY version',
                [...$scope, $employment, $end],
            );
            $calculated = $this->calculator->calculate($start, $end, $worker, $assignment, $contracts, $certified, $rule);
        } catch (BusinessError $error) {
            $this->db->run(
                "INSERT INTO payslips (group_id, company_id, trial_id, employment_id, employee_code, employee_name, state, facts)
                 VALUES (?, ?, ?, ?, ?, ?, 'blocked', ?)",
                [...$scope, $trialId, $employment, $worker['employee_code'], $worker['display_name'], json_encode([
                    'blocking_reason' => $error->reason, 'details' => $error->details,
                ], JSON_THROW_ON_ERROR)],
            );
            return null;
        }
        $payslip = $this->db->insert(
            "INSERT INTO payslips (
                group_id, company_id, trial_id, employment_id, attendance_certification_id, employee_code, employee_name,
                state, gross_minor, absence_minor, withholding_minor, net_minor, cost_minor, facts
             ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ready', ?, ?, ?, ?, ?, ?)",
            [
                ...$scope, $trialId, $employment, $certified['id'], $worker['employee_code'], $worker['display_name'],
                $calculated['gross_minor'], $calculated['absence_minor'], $calculated['withholding_minor'],
                $calculated['net_minor'], $calculated['cost_minor'],
                json_encode([
                    'rule_id' => (int) $rule['id'], 'rule_revision' => (int) $rule['revision'],
                    'attendance_certification_id' => (int) $certified['id'], 'attendance_watermark' => $watermark,
                    'worked_minutes' => (int) $certified['worked_minutes'], 'absent_minutes' => (int) $certified['absent_minutes'],
                    'taxable_minor' => $calculated['taxable_minor'], 'rounding' => 'minor_unit_half_up_largest_remainder',
                ], JSON_THROW_ON_ERROR),
            ],
        );
        foreach ($calculated['segments'] as $index => $segment) {
            $this->db->run(
                'INSERT INTO payslip_segments (
                    group_id, company_id, payslip_id, line_no, department_id, starts_on, ends_before,
                    base_minor, allowance_minor, absence_minor, cost_minor, facts
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    ...$scope, $payslip, $index + 1, $segment['department_id'], $segment['from'], $segment['until'],
                    $segment['base_minor'], $segment['allowance_minor'], $segment['absence_minor'], $segment['cost_minor'],
                    json_encode([
                        'assignment_id' => $segment['assignment_id'], 'contract_id' => $segment['contract_id'],
                        'contract_monthly_minor' => $segment['contract_monthly_minor'],
                        'days' => $segment['days'], 'period_days' => $segment['period_days'], 'absent_minutes' => $segment['absent_minutes'],
                    ], JSON_THROW_ON_ERROR),
                ],
            );
        }
        return $calculated;
    }
}
