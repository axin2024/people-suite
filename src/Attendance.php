<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;
use DateTimeZone;

final class Attendance
{
    private const FINAL_STATES = ['worked', 'absent', 'excused', 'cancelled'];

    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly WorkforceAccess $workforce,
    ) {
    }

    public function schedule(Access $actor, int $company, int $employment, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $day = Input::date($input['workday'] ?? null, 'workday');
        $start = Input::instant($input['starts_at'] ?? null, 'starts_at');
        $end = Input::instant($input['ends_at'] ?? null, 'ends_at');
        if ($end - $start < 60 || $end - $start > 86400) {
            throw new BusinessError(422, 'invalid_shift_duration');
        }
        return $this->operations->once($actor, $company, 'shift.schedule', $key, [
            'employment_id' => $employment, 'workday' => $day, 'starts_at' => $start, 'ends_at' => $end,
        ], function () use ($actor, $company, $employment, $day, $start, $end): array {
            $timezone = $this->db->row('SELECT timezone FROM companies WHERE group_id=? AND id=?', [$actor->groupId, $company]);
            $localStart = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone($timezone['timezone']));
            if ($localStart->format('Y-m-d') !== $day) {
                throw new BusinessError(422, 'shift_must_start_on_workday');
            }
            $this->workforce->lockEmployment($actor, $company, $employment, $day);
            $scope = [$actor->groupId, $company, $employment];
            $overlap = $this->db->row(
                "SELECT id, workday FROM shifts WHERE group_id=? AND company_id=? AND employment_id=? AND state='scheduled'
                 AND starts_at<? AND ends_at>? ORDER BY starts_at, id LIMIT 1",
                [...$scope, $end, $start],
            );
            if ($overlap !== null) {
                throw new BusinessError(409, 'shift_overlap', $overlap);
            }
            $shift = $this->db->insert(
                'INSERT INTO shifts (group_id, company_id, employment_id, workday, starts_at, ends_at) VALUES (?, ?, ?, ?, ?, ?)',
                [...$scope, $day, $start, $end],
            );
            $version = $this->append($actor, $company, $shift, 1, 'scheduled', 0, []);
            $this->operations->audit($actor, $company, 'shift', $shift, 'shift.scheduled', [
                'employment_id' => $employment, 'workday' => $day, 'watermark' => $version['watermark'],
            ]);
            return ['shift_id' => $shift, 'workday' => $day, ...$version];
        });
    }

    public function punch(Access $actor, int $company, int $shiftId, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $source = Input::code($input['source'] ?? null, 'source');
        $event = Input::code($input['event_ref'] ?? null, 'event_ref');
        $direction = Input::text($input['direction'] ?? null, 'direction', 4);
        $instant = Input::instant($input['punched_at'] ?? null, 'punched_at');
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new BusinessError(422, 'invalid_punch_direction');
        }
        $payload = [
            'shift_id' => $shiftId, 'source' => $source, 'event_ref' => $event,
            'direction' => $direction, 'punched_at' => $instant,
        ];
        return $this->operations->once($actor, $company, 'shift.punch', $key, $payload,
            function () use ($actor, $company, $shiftId, $source, $event, $direction, $instant, $payload): array {
                $scope = [$actor->groupId, $company, $source, $event];
                $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $query = 'SELECT id, shift_id, digest FROM punch_events WHERE group_id=? AND company_id=? AND source=? AND event_ref=?';
                $shift = $this->shift($actor, $company, $shiftId);
                $this->workforce->lockEmployment($actor, $company, (int) $shift['employment_id']);
                $existing = $this->db->row($query, $scope);
                if ($existing !== null) {
                    return $this->punchReceipt($existing, $digest, $shift);
                }
                $shift = $this->shift($actor, $company, $shiftId);
                if ($shift['state'] !== 'scheduled') {
                    throw new BusinessError(409, 'shift_cancelled');
                }
                $this->workforce->lockEmployment($actor, $company, (int) $shift['employment_id'], $shift['workday']);
                if ($instant < $shift['starts_at'] - 21600 || $instant > $shift['ends_at'] + 21600) {
                    throw new BusinessError(422, 'punch_outside_shift_window');
                }
                $insert = $this->db->run(
                    'INSERT INTO punch_events
                        (group_id, company_id, employment_id, shift_id, source, event_ref, direction, punched_at, digest)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE event_ref=punch_events.event_ref',
                    [$actor->groupId, $company, $shift['employment_id'], $shiftId, $source, $event, $direction, $instant, $digest],
                );
                $stored = $this->db->row($query, $scope);
                $receipt = $this->punchReceipt($stored, $digest, $shift);
                if ($insert->rowCount() !== 1) {
                    return $receipt;
                }
                $current = $this->version($actor, $company, $shiftId);
                $evidence = $this->derive($actor, $company, $shift);
                if (in_array($current['state'], self::FINAL_STATES, true)) {
                    $evidence['anomalies'][] = 'punch_after_review';
                    $evidence['previous_version'] = (int) $current['version'];
                }
                $state = $evidence['anomalies'] === [] ? 'ready' : 'anomaly';
                $next = $this->append(
                    $actor, $company, $shiftId, (int) $current['version'] + 1,
                    $state, $evidence['worked_minutes'], $evidence,
                );
                $this->operations->audit($actor, $company, 'shift', $shiftId, 'punch.recorded', [
                    'punch_id' => $receipt['punch_id'], 'watermark' => $next['watermark'],
                ]);
                return $receipt;
            },
        );
    }

    public function reconcile(Access $actor, int $company, int $id, string $key, array $input): array
    {
        $state = Input::text($input['state'] ?? null, 'state', 12);
        if (!in_array($state, self::FINAL_STATES, true)) {
            throw new BusinessError(422, 'invalid_attendance_resolution');
        }
        $shift = $this->shift($actor, $company, $id);
        if ($state === 'cancelled') {
            $actor->company($company, 'hr');
        } else {
            $this->workforce->visibility($actor, $company, (int) $shift['employment_id'], $shift['workday'], false);
        }
        $expected = Input::integer($input['version'] ?? null, 'version');
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $explanation = Input::text($input['explanation'] ?? null, 'explanation', 600);
        $minutes = isset($input['worked_minutes']) ? Input::integer($input['worked_minutes'], 'worked_minutes', 1, 1440) : null;
        if ($minutes !== null && $state !== 'worked') {
            throw new BusinessError(422, 'unexpected_worked_minutes');
        }
        return $this->operations->once($actor, $company, 'shift.reconcile', $key, [
            'shift_id' => $id, 'state' => $state, 'version' => $expected, 'decision_ref' => $decision,
            'explanation' => $explanation, 'worked_minutes' => $minutes,
        ], function () use ($actor, $company, $id, $shift, $state, $expected, $decision, $explanation, $minutes): array {
            $employment = (int) $shift['employment_id'];
            $this->workforce->lockEmployment($actor, $company, $employment);
            $role = $this->workforce->visibility($actor, $company, $employment, $shift['workday'], false);
            if ($minutes !== null && $role !== 'hr') {
                throw new BusinessError(403, 'attendance_override_requires_hr');
            }
            $current = $this->version($actor, $company, $id);
            if ((int) $current['version'] !== $expected || $current['state'] === 'cancelled') {
                throw new BusinessError(409, 'attendance_version_conflict', [
                    'current_version' => (int) $current['version'], 'state' => $current['state'],
                ]);
            }
            $evidence = $this->derive($actor, $company, $shift);
            if ($state === 'worked' && $minutes === null && ($evidence['anomalies'] !== [] || $evidence['worked_minutes'] < 1)) {
                throw new BusinessError(409, 'attendance_anomaly_unresolved', ['anomalies' => $evidence['anomalies']]);
            }
            if ($state === 'cancelled' && $evidence['punch_ids'] !== []) {
                throw new BusinessError(409, 'shift_has_punches');
            }
            $worked = $state === 'worked' ? $minutes ?? $evidence['worked_minutes'] : 0;
            $evidence['manual_minutes'] = $minutes !== null;
            if ($state === 'cancelled') {
                $this->db->run("UPDATE shifts SET state='cancelled' WHERE id=?", [$id]);
            }
            $result = $this->append($actor, $company, $id, $expected + 1, $state, $worked, $evidence, $decision, $explanation);
            $this->operations->audit($actor, $company, 'shift', $id, 'attendance.' . $state, [
                'decision_ref' => $decision, 'version' => $result['version'], 'watermark' => $result['watermark'],
            ]);
            return ['shift_id' => $id, ...$result];
        });
    }

    public function roster(Access $actor, int $company, int $employment, string $day): array
    {
        $this->workforce->visibility($actor, $company, $employment, $day, true);
        $exists = $this->db->row('SELECT id FROM employments WHERE group_id=? AND company_id=? AND id=?', [
            $actor->groupId, $company, $employment,
        ]);
        if ($exists === null) {
            throw new BusinessError(404, 'employment_not_found');
        }
        return $this->db->rows(
            'SELECT s.id, s.workday, s.starts_at, s.ends_at, v.state, v.worked_minutes, v.version, v.watermark
             FROM shifts s JOIN attendance_versions v ON v.group_id=s.group_id AND v.company_id=s.company_id AND v.shift_id=s.id
             WHERE s.group_id=? AND s.company_id=? AND s.employment_id=? AND s.workday=?
               AND v.version=(SELECT MAX(prev.version) FROM attendance_versions prev
                              WHERE prev.group_id=s.group_id AND prev.company_id=s.company_id AND prev.shift_id=s.id)
             ORDER BY s.starts_at, s.id',
            [$actor->groupId, $company, $employment, $day],
        );
    }

    public function detail(Access $actor, int $company, int $id): array
    {
        $shift = $this->shift($actor, $company, $id);
        $this->workforce->visibility($actor, $company, (int) $shift['employment_id'], $shift['workday'], true);
        $version = $this->version($actor, $company, $id);
        $version['evidence'] = json_decode($version['evidence'], true, flags: JSON_THROW_ON_ERROR);
        return ['shift' => $shift, 'attendance' => $version];
    }

    public function watermark(Access $actor, int $company): int
    {
        $actor->company($company, 'hr', 'payroll_maker', 'payroll_reviewer');
        return (int) ($this->db->row('SELECT sequence FROM attendance_watermarks WHERE group_id=? AND company_id=?', [
            $actor->groupId, $company,
        ])['sequence'] ?? 0);
    }

    private function shift(Access $actor, int $company, int $id): array
    {
        return $this->db->row(
            'SELECT id, employment_id, workday, starts_at, ends_at, state FROM shifts WHERE group_id=? AND company_id=? AND id=?',
            [$actor->groupId, $company, $id],
        ) ?? throw new BusinessError(404, 'shift_not_found');
    }

    private function version(Access $actor, int $company, int $shift): array
    {
        return $this->db->row(
            'SELECT version, watermark, state, worked_minutes, decision_ref, explanation, evidence, actor_id, recorded_at
             FROM attendance_versions WHERE group_id=? AND company_id=? AND shift_id=? ORDER BY version DESC LIMIT 1',
            [$actor->groupId, $company, $shift],
        ) ?? throw new BusinessError(409, 'attendance_version_missing');
    }

    private function punchReceipt(array $row, string $digest, array $shift): array
    {
        if (!hash_equals($row['digest'], $digest)) {
            throw new BusinessError(409, 'punch_event_conflict');
        }
        return ['punch_id' => (int) $row['id'], 'shift_id' => (int) $row['shift_id'], 'workday' => $shift['workday']];
    }

    private function derive(Access $actor, int $company, array $shift): array
    {
        $rows = $this->db->rows(
            'SELECT id, direction, punched_at FROM punch_events
             WHERE group_id=? AND company_id=? AND shift_id=? ORDER BY punched_at, id',
            [$actor->groupId, $company, $shift['id']],
        );
        $anomalies = [];
        $open = null;
        $seconds = 0;
        foreach ($rows as $row) {
            $instant = (int) $row['punched_at'];
            if ($row['direction'] === 'in') {
                if ($open !== null) {
                    $anomalies[] = 'repeated_in';
                } else {
                    $open = $instant;
                }
                continue;
            }
            if ($open === null || $instant <= $open) {
                $anomalies[] = 'out_without_in';
                continue;
            }
            $seconds += $instant - $open;
            if ($open < $shift['starts_at'] - 900 || $instant > $shift['ends_at'] + 900) {
                $anomalies[] = 'outside_schedule';
            }
            $open = null;
        }
        if ($rows === []) {
            $anomalies[] = 'no_punches';
        } elseif ($open !== null) {
            $anomalies[] = 'missing_out';
        }
        if ($seconds > 86400) {
            $anomalies[] = 'duration_exceeds_day';
        }
        return [
            'punch_ids' => array_column($rows, 'id'), 'worked_minutes' => min(1440, intdiv($seconds, 60)),
            'anomalies' => array_values(array_unique($anomalies)),
        ];
    }

    private function append(
        Access $actor,
        int $company,
        int $shift,
        int $version,
        string $state,
        int $minutes,
        array $evidence,
        ?string $decision = null,
        ?string $explanation = null,
    ): array {
        $scope = [$actor->groupId, $company];
        $this->db->run(
            'INSERT INTO attendance_watermarks (group_id, company_id, sequence) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE sequence=attendance_watermarks.sequence+1',
            $scope,
        );
        $watermark = (int) $this->db->row('SELECT sequence FROM attendance_watermarks WHERE group_id=? AND company_id=?', $scope)['sequence'];
        $this->db->run(
            'INSERT INTO attendance_versions
                (group_id, company_id, watermark, shift_id, version, state, worked_minutes, decision_ref, explanation, evidence, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...$scope, $watermark, $shift, $version, $state, $minutes, $decision, $explanation,
                json_encode($evidence, JSON_THROW_ON_ERROR), $actor->accountId],
        );
        return ['version' => $version, 'watermark' => $watermark, 'state' => $state, 'worked_minutes' => $minutes];
    }
}
