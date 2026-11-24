<?php

declare(strict_types=1);

namespace PeopleSuite;

final class PayrollExports
{
    public static function payslip(array $report): CsvDownload
    {
        $slip = $report['payslip'];
        $common = ['payslip_id' => $slip['id'], 'trial_id' => $slip['trial_id'], 'employee_code' => $slip['employee_code'],
            'period_start' => $report['period_start'] ?? null, 'period_end' => $report['period_end'] ?? null, 'digest' => $report['digest'] ?? null];
        $rows = [[...$common, ...$slip, 'entry_type' => 'summary']];
        foreach ($report['segments'] as $segment) {
            $rows[] = [...$common, ...$segment, 'entry_type' => 'regular', 'gross_minor' => $segment['base_minor'] + $segment['allowance_minor']];
        }
        foreach ($report['adjustments'] as $adjustment) {
            $rows[] = [...$common, ...$adjustment, 'entry_type' => 'adjustment', 'gross_minor' => $adjustment['gross_delta_minor'],
                'withholding_minor' => $adjustment['withholding_delta_minor'], 'cost_minor' => $adjustment['gross_delta_minor'],
                'payable_minor' => $adjustment['payable_delta_minor'], 'starts_on' => $adjustment['facts']['starts_on'],
                'ends_before' => $adjustment['facts']['ends_before']];
        }
        return new CsvDownload("payslip-{$slip['id']}.csv", [
            'entry_type', 'payslip_id', 'trial_id', 'employee_code', 'period_start', 'period_end', 'digest', 'state',
            'department_id', 'starts_on', 'ends_before', 'base_minor', 'allowance_minor', 'gross_minor', 'absence_minor',
            'withholding_minor', 'net_minor', 'cost_minor', 'payable_minor', 'decision_ref', 'original_payslip_id', 'original_line_no',
        ], $rows);
    }

    public static function costs(array $companies, array $totals = []): CsvDownload
    {
        $rows = [];
        foreach ($companies as $company) {
            $rows[] = [...$company, 'entry_type' => 'company'];
            foreach ($company['departments'] as $department) {
                $rows[] = [...$company, ...$department, 'entry_type' => 'department', 'department_code' => $department['code'],
                    'department_name' => $department['name']];
            }
        }
        foreach ($totals as $amount) {
            $rows[] = [...$amount, 'entry_type' => 'currency'];
        }
        return new CsvDownload('department-costs.csv', [
            'entry_type', 'company_id', 'company_code', 'currency', 'period_start', 'period_end', 'run_id', 'trial_id',
            'run_state', 'digest', 'blocked_count', 'department_id', 'department_code', 'department_name', 'segment_count',
            'adjustment_count', 'company_count', 'base_cost_minor', 'adjustment_minor', 'total_cost_minor',
        ], $rows);
    }

    public static function inquiries(array $rows, bool $employeeView = false): CsvDownload
    {
        $columns = [
            'id', 'payslip_id', 'opened_by', 'reference', 'category', 'question', 'state', 'version', 'opened_at',
            'answered_by', 'decision_ref', 'answer', 'answered_at', 'acknowledged_at',
        ];
        if ($employeeView) {
            $columns = array_values(array_diff($columns, ['opened_by', 'answered_by']));
        }
        return new CsvDownload('payslip-inquiries.csv', $columns, $rows);
    }
}
