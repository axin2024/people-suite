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
    ) {
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
                'state' => 'calculated', 'attendance_watermark' => $watermark, ...$summary,
            ];
        }, repeatableRead: true);
    }

    public function detail(Access $actor, int $company, int $id, int $after): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        $scope = [$actor->groupId, $company];
        $run = $this->run($scope, $id);
        if ((int) $run['trial_revision'] === 0) {
            return ['run' => $run, 'trial' => null, 'payslips' => []];
        }
        $trial = $this->db->row(
            'SELECT id, revision, rule_id, attendance_watermark, calculated_by, eligible_count, blocked_count,
                    gross_minor, net_minor, cost_minor, calculated_at
             FROM payroll_trials WHERE group_id=? AND company_id=? AND run_id=? AND revision=?',
            [...$scope, $id, $run['trial_revision']],
        );
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
        return ['run' => $run, 'trial' => $trial, 'payslips' => $payslips];
    }

    public function payslip(Access $actor, int $company, int $id): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
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
        return ['payslip' => $record, 'segments' => $segments];
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
