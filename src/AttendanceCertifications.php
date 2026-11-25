<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;

final class AttendanceCertifications
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly WorkforceAccess $workforce,
        private readonly Attendance $attendance,
    ) {
    }

    public function certify(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $start = Input::date($input['starts_on'] ?? null, 'starts_on');
        $end = Input::date($input['ends_before'] ?? null, 'ends_before');
        $expected = Input::integer($input['expected_shifts'] ?? null, 'expected_shifts', 0, 1000);
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $notes = Input::text($input['notes'] ?? null, 'notes', 600);
        if ($end <= $start || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 31) {
            throw new BusinessError(422, 'invalid_attendance_period');
        }
        return $this->operations->once($actor, $company, 'attendance.certify', $key, [
            'employment_id' => $employment, 'starts_on' => $start, 'ends_before' => $end,
            'expected_shifts' => $expected, 'decision_ref' => $decision, 'notes' => $notes,
        ], function () use ($actor, $company, $employment, $start, $end, $expected, $decision, $notes): array {
            $worker = $this->workforce->lockEmployment($actor, $company, $employment);
            if ($worker['starts_on'] >= $end || ($worker['ends_before'] !== null && $worker['ends_before'] <= $start)) {
                throw new BusinessError(409, 'attendance_period_outside_employment');
            }
            $scope = [$actor->groupId, $company, $employment];
            $cut = $this->attendance->watermark($actor, $company);
            $shifts = $this->db->rows(
                'SELECT s.id, s.workday, s.ends_at-s.starts_at AS planned_seconds, v.state, v.watermark, v.worked_minutes
                 FROM shifts s JOIN attendance_versions v
                     ON v.group_id=s.group_id AND v.company_id=s.company_id AND v.shift_id=s.id
                 WHERE s.group_id=? AND s.company_id=? AND s.employment_id=? AND s.workday>=? AND s.workday<?
                   AND v.watermark=(SELECT MAX(p.watermark) FROM attendance_versions p
                        WHERE p.group_id=s.group_id AND p.company_id=s.company_id AND p.shift_id=s.id AND p.watermark<=?)
                 ORDER BY s.workday, s.starts_at, s.id',
                [...$scope, $start, $end, $cut],
            );

            $total = $worked = $absent = $shiftWatermark = 0;
            $blocking = [];
            foreach ($shifts as $shift) {
                $shiftWatermark = max($shiftWatermark, (int) $shift['watermark']);
                if ($shift['state'] === 'cancelled') {
                    continue;
                }
                $total++;
                if ($shift['workday'] < $worker['starts_on'] || ($worker['ends_before'] !== null && $shift['workday'] >= $worker['ends_before'])) {
                    $blocking[] = ['shift_id' => (int) $shift['id'], 'reason' => 'workday_outside_employment'];
                } elseif (!in_array($shift['state'], ['worked', 'absent', 'excused'], true)) {
                    $blocking[] = ['shift_id' => (int) $shift['id'], 'reason' => $shift['state']];
                } else {
                    $worked += (int) $shift['worked_minutes'];
                    $absent += $shift['state'] === 'absent' ? intdiv((int) $shift['planned_seconds'], 60) : 0;
                }
            }

            if ($total !== $expected || $blocking !== []) {
                throw new BusinessError(409, 'attendance_period_incomplete', [
                    'expected_shifts' => $expected, 'scheduled_shifts' => $total, 'blocking_shifts' => array_slice($blocking, 0, 100),
                    'blocking_count' => count($blocking),
                ]);
            }
            $id = $this->db->insert(
                'INSERT INTO attendance_certifications (
                    group_id, company_id, employment_id, starts_on, ends_before, watermark, shift_watermark,
                    expected_shifts, worked_minutes, absent_minutes, evidence, decision_ref, notes, certified_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $start, $end, $cut, $shiftWatermark, $total, $worked, $absent,
                    json_encode($shifts, JSON_THROW_ON_ERROR), $decision, $notes, $actor->accountId],
            );
            $this->operations->audit($actor, $company, 'attendance_certification', $id, 'attendance.period_certified', [
                'employment_id' => $employment, 'watermark' => $cut, 'shift_watermark' => $shiftWatermark,
            ]);
            return [
                'certification_id' => $id, 'watermark' => $cut, 'expected_shifts' => $total,
                'worked_minutes' => $worked, 'absent_minutes' => $absent,
            ];
        });
    }

    public function history(Access $actor, int $company, int $employment, int $after): array
    {
        $actor->company($company, 'hr');
        $scope = [$actor->groupId, $company, $employment];
        if ($this->db->row('SELECT 1 FROM employments WHERE group_id=? AND company_id=? AND id=?', $scope) === null) {
            throw new BusinessError(404, 'employment_not_found');
        }
        return $this->db->rows(
            'SELECT id, starts_on, ends_before, expected_shifts, worked_minutes, absent_minutes, watermark, decision_ref, certified_at
             FROM attendance_certifications WHERE group_id=? AND company_id=? AND employment_id=? AND id>? ORDER BY id LIMIT 100',
            [...$scope, $after],
        );
    }

    public function forPayroll(
        int $group,
        int $company,
        int $employment,
        string $start,
        string $end,
        int $cut,
    ): array {
        $scope = [$group, $company, $employment, $start, $end, $cut];
        $certificate = $this->db->row(
            'SELECT id, watermark, shift_watermark, worked_minutes, absent_minutes, evidence, decision_ref
             FROM attendance_certifications WHERE group_id=? AND company_id=? AND employment_id=?
               AND starts_on=? AND ends_before=? AND watermark<=?
             ORDER BY watermark DESC, id DESC LIMIT 1',
            $scope,
        );
        if ($certificate === null) {
            throw new BusinessError(409, 'attendance_certificate_missing');
        }
        $latest = $this->db->row(
            'SELECT COALESCE(MAX(v.watermark),0) AS latest FROM shifts s JOIN attendance_versions v
               ON v.group_id=s.group_id AND v.company_id=s.company_id AND v.shift_id=s.id
             WHERE s.group_id=? AND s.company_id=? AND s.employment_id=?
               AND s.workday>=? AND s.workday<? AND v.watermark<=?',
            $scope,
        );
        if ((int) $latest['latest'] !== (int) $certificate['shift_watermark']) {
            throw new BusinessError(409, 'attendance_certificate_stale');
        }
        $certificate['evidence'] = json_decode($certificate['evidence'], true, flags: JSON_THROW_ON_ERROR);
        return $certificate;
    }
}
