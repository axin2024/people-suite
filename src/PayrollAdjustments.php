<?php

declare(strict_types=1);

namespace PeopleSuite;

final class PayrollAdjustments
{
    public function __construct(private readonly Database $db, private readonly Operations $operations)
    {
    }

    public function forRun(Access $actor, int $company, int $run, int $after): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        $scope = [$actor->groupId, $company, $run];
        if ($this->db->row('SELECT id FROM payroll_runs WHERE group_id=? AND company_id=? AND id=?', $scope) === null) {
            throw new BusinessError(404, 'payroll_run_not_found');
        }
        return $this->db->rows(
            "SELECT id, original_payslip_id, original_line_no, department_id, decision_ref, original_minor, prior_minor,
                    recalculated_minor, gross_delta_minor, withholding_delta_minor, recorded_at,
                    JSON_UNQUOTE(JSON_EXTRACT(facts,'$.notes')) AS notes
             FROM payroll_adjustments WHERE group_id=? AND company_id=? AND target_run_id=? AND id>? ORDER BY id LIMIT 100",
            [...$scope, $after],
        );
    }

    public function record(Access $actor, int $company, int $run, string $key, array $input): array
    {
        $actor->company($company, 'payroll_maker');
        $payslip = Input::integer($input['original_payslip_id'] ?? null, 'original_payslip_id');
        $line = Input::integer($input['original_line_no'] ?? null, 'original_line_no', 1, 31);
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $monthly = Input::integer($input['corrected_monthly_minor'] ?? null, 'corrected_monthly_minor', 0, 100000000000);
        $previousId = Input::integer($input['previous_adjustment_id'] ?? 0, 'previous_adjustment_id', 0);
        $notes = Input::text($input['notes'] ?? null, 'notes', 600);
        $payload = [
            'target_run_id' => $run, 'original_payslip_id' => $payslip, 'original_line_no' => $line,
            'decision_ref' => $decision, 'corrected_monthly_minor' => $monthly, 'previous_adjustment_id' => $previousId, 'notes' => $notes,
        ];
        return $this->operations->once($actor, $company, 'payroll.adjustment', $key, $payload,
            function () use ($actor, $company, $run, $payslip, $line, $decision, $monthly, $previousId, $notes, $payload): array {
                $scope = [$actor->groupId, $company];
                $target = $this->db->row(
                    'SELECT state, period_start FROM payroll_runs WHERE group_id=? AND company_id=? AND id=? FOR UPDATE',
                    [...$scope, $run],
                ) ?? throw new BusinessError(404, 'payroll_run_not_found');
                if ($this->db->row('SELECT id FROM payslips WHERE group_id=? AND company_id=? AND id=? FOR UPDATE',
                    [...$scope, $payslip]) === null) {
                    throw new BusinessError(404, 'original_payroll_line_not_found');
                }

                $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $existing = $this->db->row(
                    'SELECT id, target_run_id, original_payslip_id, original_line_no, department_id, decision_ref,
                            payload_digest, original_minor, prior_minor, recalculated_minor, gross_delta_minor, withholding_delta_minor, facts
                     FROM payroll_adjustments WHERE group_id=? AND company_id=? AND original_payslip_id=? AND original_line_no=? AND decision_ref=?',
                    [...$scope, $payslip, $line, $decision],
                );
                if ($existing !== null) {
                    if (!hash_equals($existing['payload_digest'], $digest)) {
                        throw new BusinessError(409, 'payroll_adjustment_decision_conflict');
                    }
                    return $this->receipt($existing);
                }
                if (!in_array($target['state'], ['draft', 'calculated'], true)) {
                    throw new BusinessError(409, 'payroll_adjustment_target_not_editable');
                }

                $original = $this->original($scope, $payslip, $line);
                if ($target['period_start'] < $original['period_end']) {
                    throw new BusinessError(409, 'payroll_adjustment_requires_later_period');
                }
                $previous = $this->db->row(
                    'SELECT id, recalculated_minor FROM payroll_adjustments
                     WHERE group_id=? AND company_id=? AND original_payslip_id=? AND original_line_no=? ORDER BY id DESC LIMIT 1',
                    [...$scope, $payslip, $line],
                );
                if ((int) ($previous['id'] ?? 0) !== $previousId) {
                    throw new BusinessError(409, 'payroll_adjustment_baseline_changed', ['previous_adjustment_id' => (int) ($previous['id'] ?? 0)]);
                }
                $totals = $this->db->row(
                    'SELECT COALESCE(SUM(a.gross_delta_minor),0) AS gross_delta, MAX(r.period_start) AS latest_period
                     FROM payroll_adjustments a JOIN payroll_runs r
                     ON r.group_id=a.group_id AND r.company_id=a.company_id AND r.id=a.target_run_id
                     WHERE a.group_id=? AND a.company_id=? AND a.original_payslip_id=?',
                    [...$scope, $payslip],
                );
                if ($totals['latest_period'] !== null && $target['period_start'] < $totals['latest_period']) {
                    throw new BusinessError(409, 'payroll_adjustment_period_out_of_order');
                }

                $facts = json_decode($original['facts'], true, flags: JSON_THROW_ON_ERROR);
                $base = (int) $original['base_minor'];
                $prior = (int) ($previous['recalculated_minor'] ?? $base);
                $shift = ($monthly - (int) $facts['contract_monthly_minor']) * (int) $facts['days'];
                $revised = max(0, $base + $this->round($shift, (int) $facts['period_days']));
                $delta = $revised - $prior;
                if ($delta === 0) {
                    throw new BusinessError(422, 'payroll_adjustment_has_no_delta');
                }
                if ($revised + (int) $original['allowance_minor'] < (int) $original['absence_minor']) {
                    throw new BusinessError(409, 'payroll_adjustment_below_certified_deductions');
                }
                $priorCost = (int) $original['cost_minor'] + (int) $totals['gross_delta'];
                $withholdingDelta = $this->withholding($original, $priorCost + $delta) - $this->withholding($original, $priorCost);
                $originalGross = (int) $original['gross_minor'];
                $priorGross = $originalGross + (int) $totals['gross_delta'];
                $evidence = json_encode([
                    'notes' => $notes, 'original_run_id' => (int) $original['run_id'], 'original_closure' => $original['digest'],
                    'starts_on' => $original['starts_on'], 'ends_before' => $original['ends_before'],
                    'original_monthly_minor' => (int) $facts['contract_monthly_minor'], 'days' => (int) $facts['days'],
                    'period_days' => (int) $facts['period_days'], 'previous_adjustment_id' => $previousId,
                    'original_gross_minor' => $originalGross, 'prior_gross_minor' => $priorGross,
                    'recalculated_gross_minor' => $priorGross + $delta,
                ], JSON_THROW_ON_ERROR);
                $id = $this->db->insert(
                    'INSERT INTO payroll_adjustments (
                        group_id, company_id, target_run_id, original_payslip_id, original_line_no, employment_id,
                        department_id, decision_ref, payload_digest, original_minor, prior_minor, recalculated_minor,
                        monthly_minor, gross_delta_minor, withholding_delta_minor, facts, recorded_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$scope, $run, $payslip, $line, $original['employment_id'], $original['department_id'], $decision, $digest,
                        $base, $prior, $revised, $monthly, $delta, $withholdingDelta, $evidence, $actor->accountId],
                );
                $this->operations->audit($actor, $company, 'payroll_run', $run, 'payroll.adjustment_recorded', [
                    'adjustment_id' => $id, 'original_payslip_id' => $payslip, 'decision_ref' => $decision,
                ]);
                return $this->receipt([
                    'id' => $id, 'target_run_id' => $run, 'original_payslip_id' => $payslip, 'original_line_no' => $line,
                    'department_id' => $original['department_id'], 'decision_ref' => $decision, 'original_minor' => $base,
                    'prior_minor' => $prior, 'recalculated_minor' => $revised,
                    'gross_delta_minor' => $delta, 'withholding_delta_minor' => $withholdingDelta, 'facts' => $evidence,
                ]);
            },
        );
    }

    public function attachToTrial(array $scope, int $run, int $trial): int
    {
        $adjustments = $this->db->rows(
            'SELECT a.id, a.employment_id, p.id AS payslip_id, e.employee_code, e.display_name
             FROM payroll_adjustments a JOIN employments w ON w.group_id=a.group_id AND w.company_id=a.company_id AND w.id=a.employment_id
             JOIN employees e ON e.group_id=w.group_id AND e.id=w.employee_id
             LEFT JOIN payslips p ON p.group_id=a.group_id AND p.company_id=a.company_id AND p.employment_id=a.employment_id AND p.trial_id=?
             WHERE a.group_id=? AND a.company_id=? AND a.target_run_id=? ORDER BY a.employment_id, a.id',
            [$trial, ...$scope, $run],
        );
        $supplementary = [];
        foreach ($adjustments as $adjustment) {
            $employment = (int) $adjustment['employment_id'];
            $slip = $adjustment['payslip_id'] ?? $supplementary[$employment] ?? null;
            if ($slip === null) {
                $slip = $supplementary[$employment] = $this->db->insert(
                    "INSERT INTO payslips (
                        group_id, company_id, trial_id, employment_id, employee_code, employee_name, state,
                        gross_minor, absence_minor, withholding_minor, net_minor, cost_minor, facts
                    ) VALUES (?, ?, ?, ?, ?, ?, 'ready', 0, 0, 0, 0, 0, ?)",
                    [...$scope, $trial, $employment, $adjustment['employee_code'], $adjustment['display_name'], '{"supplementary":true}'],
                );
            }
            $this->db->run(
                'INSERT INTO payroll_trial_adjustments (group_id, company_id, trial_id, adjustment_id, payslip_id) VALUES (?, ?, ?, ?, ?)',
                [...$scope, $trial, $adjustment['id'], $slip],
            );
        }
        return count($supplementary);
    }

    public function assertIncluded(array $scope, int $run, int $trial): void
    {
        if ($this->db->row(
            'SELECT a.id FROM payroll_adjustments a
             LEFT JOIN payroll_trial_adjustments t ON t.group_id=a.group_id AND t.company_id=a.company_id AND t.adjustment_id=a.id AND t.trial_id=?
             WHERE a.group_id=? AND a.company_id=? AND a.target_run_id=? AND t.adjustment_id IS NULL LIMIT 1',
            [$trial, ...$scope, $run],
        ) !== null) {
            throw new BusinessError(409, 'payroll_adjustments_require_new_trial');
        }
    }

    public function totals(array $scope, int $trial): array
    {
        $totals = $this->db->row(
            'SELECT COALESCE(SUM(a.gross_delta_minor),0) AS gross, COALESCE(SUM(a.withholding_delta_minor),0) AS withholding
             FROM payroll_trial_adjustments t JOIN payroll_adjustments a
             ON a.group_id=t.group_id AND a.company_id=t.company_id AND a.id=t.adjustment_id
             JOIN payslips p ON p.group_id=t.group_id AND p.company_id=t.company_id AND p.id=t.payslip_id
             WHERE t.group_id=? AND t.company_id=? AND t.trial_id=? AND p.state=?',
            [...$scope, $trial, 'ready'],
        );
        return [
            'gross_adjustment_minor' => (int) $totals['gross'],
            'withholding_adjustment_minor' => (int) $totals['withholding'],
            'adjustment_minor' => (int) $totals['gross'] - (int) $totals['withholding'],
        ];
    }

    public function forTrial(array $scope, int $trial): array
    {
        return $this->rows($scope, 't.trial_id', $trial);
    }

    public function withPayableAmounts(array $scope, array $payslips): array
    {
        if ($payslips === []) {
            return [];
        }
        $ids = array_column($payslips, 'id');
        $parameters = implode(',', array_fill(0, count($ids), '?'));
        $totals = array_column($this->db->rows(
            'SELECT t.payslip_id, SUM(a.gross_delta_minor-a.withholding_delta_minor) AS delta
             FROM payroll_trial_adjustments t JOIN payroll_adjustments a
             ON a.group_id=t.group_id AND a.company_id=t.company_id AND a.id=t.adjustment_id
             WHERE t.group_id=? AND t.company_id=? AND t.payslip_id IN (' . $parameters . ') GROUP BY t.payslip_id',
            [...$scope, ...$ids],
        ), 'delta', 'payslip_id');
        foreach ($payslips as &$payslip) {
            $payslip['payable_minor'] = $payslip['net_minor'] === null
                ? null : (int) $payslip['net_minor'] + (int) ($totals[$payslip['id']] ?? 0);
        }
        unset($payslip);
        return $payslips;
    }

    public function forPayslip(array $scope, int $payslip, bool $employeeView): array
    {
        $rows = $this->rows($scope, 't.payslip_id', $payslip);
        foreach ($rows as &$row) {
            $row['facts'] = json_decode($row['facts'], true, flags: JSON_THROW_ON_ERROR);
            $row['payable_delta_minor'] = (int) $row['gross_delta_minor'] - (int) $row['withholding_delta_minor'];
            if ($employeeView) {
                unset($row['recorded_by'], $row['facts']['notes'], $row['facts']['original_closure']);
            }
        }
        unset($row);
        return $rows;
    }

    private function rows(array $scope, string $filter, int $id): array
    {
        return $this->db->rows(
            'SELECT t.payslip_id, a.id, a.target_run_id, a.original_payslip_id, a.original_line_no, a.employment_id,
                    a.department_id, a.decision_ref, a.original_minor, a.prior_minor, a.recalculated_minor,
                    a.monthly_minor, a.gross_delta_minor, a.withholding_delta_minor, a.facts, a.recorded_by
             FROM payroll_trial_adjustments t JOIN payroll_adjustments a ON a.group_id=t.group_id AND a.company_id=t.company_id AND a.id=t.adjustment_id
             WHERE t.group_id=? AND t.company_id=? AND ' . $filter . '=? ORDER BY t.payslip_id, a.id',
            [...$scope, $id],
        );
    }

    private function original(array $scope, int $payslip, int $line): array
    {
        return $this->db->row(
            "SELECT p.employment_id, p.gross_minor, p.cost_minor, l.department_id, l.base_minor, l.allowance_minor, l.absence_minor,
                    l.starts_on, l.ends_before, l.facts, c.digest, r.id AS run_id, r.period_end,
                    q.withholding_bps, q.withholding_exempt_minor
             FROM payslips p JOIN payslip_segments l ON l.group_id=p.group_id AND l.company_id=p.company_id AND l.payslip_id=p.id
             JOIN payroll_closures c ON c.group_id=p.group_id AND c.company_id=p.company_id AND c.trial_id=p.trial_id
             JOIN payroll_runs r ON r.group_id=c.group_id AND r.company_id=c.company_id AND r.id=c.run_id
             JOIN payroll_trials t ON t.group_id=c.group_id AND t.company_id=c.company_id AND t.id=c.trial_id
             JOIN pay_rules q ON q.group_id=t.group_id AND q.company_id=t.company_id AND q.id=t.rule_id
             WHERE p.group_id=? AND p.company_id=? AND p.id=? AND l.line_no=? AND r.state IN ('closed','published')",
            [...$scope, $payslip, $line],
        ) ?? throw new BusinessError(404, 'original_payroll_line_not_found');
    }

    private function withholding(array $original, int $cost): int
    {
        return $this->round(max(0, $cost - (int) $original['withholding_exempt_minor']) * (int) $original['withholding_bps'], 10000);
    }

    private function round(int $numerator, int $divisor): int
    {
        return ($numerator <=> 0) * intdiv(abs($numerator) + intdiv($divisor, 2), $divisor);
    }

    private function receipt(array $row): array
    {
        $facts = json_decode($row['facts'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['payload_digest'], $row['facts']);
        foreach (['original_gross_minor', 'prior_gross_minor', 'recalculated_gross_minor'] as $amount) {
            $row[$amount] = $facts[$amount];
        }
        $row['payable_delta_minor'] = (int) $row['gross_delta_minor'] - (int) $row['withholding_delta_minor'];
        return $row;
    }
}
