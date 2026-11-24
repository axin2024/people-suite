<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;

final class PayrollCalculator
{
    public function calculate(
        string $periodStart,
        string $periodEnd,
        array $employment,
        array $assignments,
        array $contracts,
        array $certification,
        array $rule,
    ): array {
        $periodDays = $this->days($periodStart, $periodEnd);
        if ($periodDays < 1 || $periodDays > 31 || ($rule['proration_basis'] === 'fixed_30' && $periodDays !== 30)) {
            throw new BusinessError(422, 'payroll_period_incompatible_with_rule');
        }

        $start = max($periodStart, $employment['starts_on']);
        $end = min($periodEnd, $employment['ends_before'] ?? $periodEnd);
        if ($start >= $end) {
            throw new BusinessError(409, 'employment_outside_pay_period');
        }

        $boundaries = [$start => true, $end => true];
        foreach ($assignments as $row) {
            $this->addBoundary($boundaries, $row['effective_from'], $start, $end);
            $this->addBoundary($boundaries, $row['effective_until'], $start, $end);
        }
        foreach ($contracts as $row) {
            $this->addBoundary($boundaries, $row['effective_from'], $start, $end);
            $this->addBoundary($boundaries, $row['ends_before'], $start, $end);
        }
        $dates = array_keys($boundaries);
        sort($dates, SORT_STRING);

        $segments = $base = $allowance = $absence = [];
        for ($i = 0, $last = count($dates) - 1; $i < $last; $i++) {
            $from = $dates[$i];
            $until = $dates[$i + 1];
            $assignment = $this->assignment($assignments, $from);
            $contract = $this->contract($contracts, $from);
            $days = $this->days($from, $until);
            $absentMinutes = $this->absentMinutes($certification['evidence'], $from, $until);
            $monthly = (int) $contract['monthly_minor'];
            $segments[] = [
                'from' => $from, 'until' => $until, 'days' => $days, 'period_days' => $periodDays,
                'assignment_id' => (int) $assignment['id'], 'department_id' => (int) $assignment['department_id'],
                'contract_id' => (int) $contract['id'], 'contract_monthly_minor' => $monthly,
                'absent_minutes' => $absentMinutes,
            ];
            $base[] = $monthly * $days;
            $allowance[] = (int) $rule['monthly_allowance_minor'] * $days;
            $absence[] = (int) $rule['absence_hourly_minor'] * $absentMinutes;
        }

        $base = $this->allocate($base, $periodDays);
        $allowance = $this->allocate($allowance, $periodDays);
        $absence = $this->allocate($absence, 60);
        foreach ($segments as $index => &$segment) {
            $gross = $base[$index] + $allowance[$index];
            $segment['base_minor'] = $base[$index];
            $segment['allowance_minor'] = $allowance[$index];
            $segment['absence_minor'] = min($absence[$index], $gross);
            $segment['cost_minor'] = $gross - $segment['absence_minor'];
        }
        unset($segment);

        $gross = array_sum($base) + array_sum($allowance);
        $absent = array_sum(array_column($segments, 'absence_minor'));
        $cost = $gross - $absent;
        $taxable = max(0, $cost - (int) $rule['withholding_exempt_minor']);
        $withholding = $this->roundPositive($taxable * (int) $rule['withholding_bps'], 10000);

        return [
            'gross_minor' => $gross, 'absence_minor' => $absent, 'cost_minor' => $cost, 'taxable_minor' => $taxable,
            'withholding_minor' => $withholding, 'net_minor' => $cost - $withholding,
            'segments' => $segments,
        ];
    }

    private function addBoundary(array &$boundaries, ?string $value, string $start, string $end): void
    {
        if ($value !== null && $value > $start && $value < $end) {
            $boundaries[$value] = true;
        }
    }

    private function days(string $from, string $until): int
    {
        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->format('%r%a');
    }

    private function assignment(array $rows, string $day): array
    {
        $match = null;
        foreach ($rows as $row) {
            if ($row['effective_from'] <= $day && ($row['effective_until'] === null || $row['effective_until'] > $day)) {
                if ($match !== null) {
                    throw new BusinessError(409, 'payroll_assignment_overlap', ['workday' => $day]);
                }
                $match = $row;
            }
        }
        return $match ?? throw new BusinessError(409, 'payroll_assignment_missing', ['workday' => $day]);
    }

    private function contract(array $rows, string $day): array
    {
        $match = null;
        foreach ($rows as $row) {
            if ($row['effective_from'] <= $day && ($match === null || $row['effective_from'] > $match['effective_from'])) {
                $match = $row;
            }
        }
        if ($match === null || ($match['ends_before'] !== null && $match['ends_before'] <= $day)) {
            throw new BusinessError(409, 'payroll_contract_missing', ['workday' => $day]);
        }
        return $match;
    }

    private function absentMinutes(array $evidence, string $start, string $end): int
    {
        $minutes = 0;
        foreach ($evidence as $shift) {
            if ($shift['state'] === 'absent' && $shift['workday'] >= $start && $shift['workday'] < $end) {
                $minutes += intdiv((int) $shift['planned_seconds'], 60);
            }
        }
        return $minutes;
    }

    private function allocate(array $numerators, int $divisor): array
    {
        $shares = $remainders = [];
        foreach ($numerators as $index => $numerator) {
            $shares[$index] = intdiv($numerator, $divisor);
            $remainders[$index] = $numerator % $divisor;
        }
        $remaining = $this->roundPositive(array_sum($numerators), $divisor) - array_sum($shares);
        $indices = array_keys($remainders);
        usort($indices, static fn(int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        for ($index = 0; $index < $remaining; $index++) {
            $shares[$indices[$index]]++;
        }
        return $shares;
    }

    private function roundPositive(int $numerator, int $divisor): int
    {
        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }
}
