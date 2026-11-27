<?php

declare(strict_types=1);

namespace PeopleSuite;

final class PayrollCosts
{
    public function __construct(private readonly Database $db)
    {
    }

    public function forRun(Access $actor, int $company, int $run): array
    {
        $actor->company($company, 'payroll_maker', 'payroll_reviewer');
        $reports = $this->reports('r.group_id=? AND r.company_id=? AND r.id=?', [$actor->groupId, $company, $run]);
        return $reports[0] ?? throw new BusinessError(404, 'payroll_cost_report_not_found');
    }

    public function forPeriod(Access $actor, mixed $start): array
    {
        $start = Input::date($start, 'period_start');
        if (!str_ends_with($start, '-01')) {
            throw new BusinessError(422, 'payroll_period_must_start_on_first_day');
        }
        $parameters = [$actor->groupId, $actor->accountId, 'payroll_maker', 'payroll_reviewer'];
        if ($this->db->row(
            'SELECT 1 FROM company_roles WHERE group_id=? AND account_id=? AND role IN (?,?) LIMIT 1', $parameters,
        ) === null) {
            throw new BusinessError(404, 'payroll_cost_report_not_found');
        }
        $companies = $this->reports(
            "r.group_id=? AND r.period_start=? AND r.state IN ('closed','published') AND EXISTS (
                SELECT 1 FROM company_roles a WHERE a.group_id=r.group_id AND a.company_id=r.company_id
                 AND a.account_id=? AND a.role IN (?,?)
            )",
            [$actor->groupId, $start, ...array_slice($parameters, 1)],
        );
        $totals = [];
        foreach ($companies as $company) {
            $currency = $company['currency'];
            $totals[$currency] ??= ['currency' => $currency, 'company_count' => 0,
                'base_cost_minor' => 0, 'adjustment_minor' => 0, 'total_cost_minor' => 0];
            $totals[$currency]['company_count']++;
            foreach (['base_cost_minor', 'adjustment_minor', 'total_cost_minor'] as $amount) {
                $totals[$currency][$amount] += $company[$amount];
            }
        }
        ksort($totals);
        return ['period_start' => $start, 'companies' => $companies, 'totals_by_currency' => array_values($totals)];
    }

    public function closedForPlanning(Access $actor,int $company,int $year): array
    {
        $actor->company($company,'hr','payroll_reviewer');
        return $this->reports("r.group_id=? AND r.company_id=? AND r.period_start>=? AND r.period_start<? AND r.state IN ('closed','published')",
            [$actor->groupId,$company,"$year-01-01",($year+1).'-01-01']);
    }

    private function reports(string $filter, array $parameters): array
    {
        $rows = $this->db->rows(
            'WITH selected AS (
                SELECT r.group_id, r.company_id, c.code AS company_code, c.currency, r.id AS run_id,
                       r.period_start, r.period_end, r.state, t.id AS trial_id, t.blocked_count, t.cost_minor AS trial_base,
                       z.digest
                FROM payroll_runs r JOIN companies c ON c.group_id=r.group_id AND c.id=r.company_id
                JOIN payroll_trials t ON t.group_id=r.group_id AND t.company_id=r.company_id AND t.run_id=r.id AND t.revision=r.trial_revision
                LEFT JOIN payroll_closures z ON z.group_id=r.group_id AND z.company_id=r.company_id AND z.run_id=r.id AND z.trial_id=t.id
                WHERE ' . $filter . '
             ), entries AS (
                SELECT x.run_id, s.department_id, SUM(s.cost_minor) AS base_cost, 0 AS adjustment_cost,
                       COUNT(*) AS segment_count, 0 AS adjustment_count
                FROM selected x JOIN payslips p ON p.group_id=x.group_id AND p.company_id=x.company_id AND p.trial_id=x.trial_id
                JOIN payslip_segments s ON s.group_id=p.group_id AND s.company_id=p.company_id AND s.payslip_id=p.id
                WHERE p.state=\'ready\' GROUP BY x.run_id, s.department_id
                UNION ALL
                SELECT x.run_id, a.department_id, 0, SUM(a.gross_delta_minor), 0, COUNT(*)
                FROM selected x JOIN payroll_trial_adjustments t ON t.group_id=x.group_id AND t.company_id=x.company_id AND t.trial_id=x.trial_id
                JOIN payroll_adjustments a ON a.group_id=t.group_id AND a.company_id=t.company_id AND a.id=t.adjustment_id
                JOIN payslips p ON p.group_id=t.group_id AND p.company_id=t.company_id AND p.id=t.payslip_id
                WHERE p.state=\'ready\' GROUP BY x.run_id, a.department_id
             ), department_totals AS (
                SELECT run_id, department_id, SUM(base_cost) AS base_cost, SUM(adjustment_cost) AS adjustment_cost,
                       SUM(segment_count) AS segment_count, SUM(adjustment_count) AS adjustment_count
                FROM entries GROUP BY run_id, department_id
             )
             SELECT x.company_id, x.company_code, x.currency, x.run_id, x.period_start, x.period_end, x.state,
                    x.trial_id, x.blocked_count, x.trial_base, x.digest,
                    y.department_id, d.code AS department_code, d.name AS department_name,
                    y.base_cost, y.adjustment_cost, y.segment_count, y.adjustment_count
             FROM selected x LEFT JOIN department_totals y ON y.run_id=x.run_id
             LEFT JOIN departments d ON d.group_id=x.group_id AND d.company_id=x.company_id AND d.id=y.department_id
             ORDER BY x.company_id, y.department_id',
            $parameters,
        );

        $reports = $expectedBase = [];
        foreach ($rows as $row) {
            $run = $row['run_id'];
            $reports[$run] ??= [
                'company_id' => $row['company_id'], 'company_code' => $row['company_code'], 'currency' => $row['currency'],
                'run_id' => $run, 'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
                'run_state' => $row['state'], 'trial_id' => $row['trial_id'], 'blocked_count' => $row['blocked_count'],
                'digest' => $row['digest'], 'base_cost_minor' => 0, 'adjustment_minor' => 0, 'total_cost_minor' => 0, 'departments' => [],
            ];
            $expectedBase[$run] = (int) $row['trial_base'];
            if ($row['department_id'] === null) {
                continue;
            }
            $base = (int) $row['base_cost'];
            $adjustment = (int) $row['adjustment_cost'];
            $reports[$run]['departments'][] = [
                'department_id' => $row['department_id'], 'code' => $row['department_code'], 'name' => $row['department_name'],
                'base_cost_minor' => $base, 'adjustment_minor' => $adjustment, 'total_cost_minor' => $base + $adjustment,
                'segment_count' => (int) $row['segment_count'], 'adjustment_count' => (int) $row['adjustment_count'],
            ];
            $reports[$run]['base_cost_minor'] += $base;
            $reports[$run]['adjustment_minor'] += $adjustment;
            $reports[$run]['total_cost_minor'] += $base + $adjustment;
        }
        foreach ($reports as $run => $report) {
            if ($report['base_cost_minor'] !== $expectedBase[$run]) {
                throw new BusinessError(409, 'payroll_cost_reconciliation_failed');
            }
        }
        return array_values($reports);
    }
}
