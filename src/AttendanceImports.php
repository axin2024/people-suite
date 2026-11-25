<?php

declare(strict_types=1);

namespace PeopleSuite;

final class AttendanceImports
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly Attendance $attendance,
    ) {
    }

    public function submit(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['roster', 'punches'], true)) {
            throw new BusinessError(422, 'invalid_attendance_import_kind');
        }
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 100) {
            throw new BusinessError(422, 'invalid_attendance_import_rows');
        }
        $references = [];
        foreach ($rows as $number => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new BusinessError(422, 'invalid_attendance_import_row', ['row' => $number + 1]);
            }
            $reference = Input::code($row['row_ref'] ?? null, 'row_ref');
            if (isset($references[$reference])) {
                throw new BusinessError(422, 'duplicate_attendance_import_row_ref', ['row_ref' => $reference]);
            }
            $references[$reference] = true;
            $rows[$number]['row_ref'] = $reference;
        }

        return $this->operations->once($actor, $company, 'attendance.import.submit', $key, [
            'kind' => $kind, 'rows' => $rows,
        ], function () use ($actor, $company, $kind, $rows): array {
            $id = $this->db->insert(
                'INSERT INTO attendance_imports (group_id, company_id, owner_id, kind, row_count) VALUES (?, ?, ?, ?, ?)',
                [$actor->groupId, $company, $actor->accountId, $kind, count($rows)],
            );
            foreach ($rows as $number => $row) {
                $this->db->run(
                    'INSERT INTO attendance_import_rows (group_id, company_id, import_id, line_no, row_ref, payload)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$actor->groupId, $company, $id, $number + 1, $row['row_ref'], json_encode($row, JSON_THROW_ON_ERROR)],
                );
            }
            $this->operations->audit($actor, $company, 'attendance_import', $id, 'attendance.import_created', [
                'kind' => $kind, 'rows' => count($rows),
            ]);
            return ['import_id' => $id, 'kind' => $kind, 'row_count' => count($rows)];
        });
    }

    public function run(Access $actor, int $company, int $id, array $input): array
    {
        $import = $this->owned($actor, $company, $id);
        $limit = Input::integer($input['limit'] ?? 25, 'limit', 1, 25);
        $after = Input::integer($input['after_row'] ?? 0, 'after_row', 0, 100);
        $retry = $input['retry_failed'] ?? false;
        if (!is_bool($retry)) {
            throw new BusinessError(422, 'invalid_retry_failed_flag');
        }
        $scope = [$actor->groupId, $company, $id];
        $states = $retry ? "('pending','failed')" : "('pending')";
        $rows = $this->db->rows(
            "SELECT line_no, payload FROM attendance_import_rows
             WHERE group_id=? AND company_id=? AND import_id=? AND state IN {$states} AND line_no>?
             ORDER BY line_no LIMIT " . $limit,
            [...$scope, $after],
        );

        foreach ($rows as $row) {
            $number = (int) $row['line_no'];
            $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
            $key = 'import-' . substr(hash('sha256', $id . ':' . $number), 0, 40);
            try {
                $result = $import['kind'] === 'roster'
                    ? $this->attendance->schedule(
                        $actor, $company, Input::integer($payload['employment_id'] ?? null, 'employment_id'), $key, $payload,
                    )
                    : $this->attendance->punch(
                        $actor, $company, Input::integer($payload['shift_id'] ?? null, 'shift_id'), $key, $payload,
                    );
                $state = 'succeeded';
            } catch (BusinessError $error) {
                $state = 'failed';
                $result = ['error' => $error->reason, 'details' => $error->details, 'status' => $error->status];
            }
            $this->db->run(
                "UPDATE attendance_import_rows SET state=?, outcome=?, attempts=attempts+1
                 WHERE group_id=? AND company_id=? AND import_id=? AND line_no=? AND state<>'succeeded'",
                [$state, json_encode($result, JSON_THROW_ON_ERROR), ...$scope, $number],
            );
        }
        return $this->status($actor, $company, $id);
    }

    public function status(Access $actor, int $company, int $id): array
    {
        $import = $this->owned($actor, $company, $id);
        $rows = $this->db->rows(
            'SELECT line_no, row_ref, state, attempts, outcome FROM attendance_import_rows
             WHERE group_id=? AND company_id=? AND import_id=? ORDER BY line_no',
            [$actor->groupId, $company, $id],
        );
        $counts = ['pending' => 0, 'succeeded' => 0, 'failed' => 0];
        foreach ($rows as &$row) {
            $counts[$row['state']]++;
            if ($row['outcome'] !== null) {
                $row['outcome'] = json_decode($row['outcome'], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        unset($row);
        return ['import' => $import, 'counts' => $counts, 'rows' => $rows];
    }

    public function batches(Access $actor, int $company, int $after): array
    {
        $actor->company($company, 'hr');
        return $this->db->rows(
            'SELECT id, kind, row_count, created_at FROM attendance_imports
             WHERE group_id=? AND company_id=? AND owner_id=? AND id>? ORDER BY id LIMIT 100',
            [$actor->groupId, $company, $actor->accountId, $after],
        );
    }

    private function owned(Access $actor, int $company, int $id): array
    {
        $actor->company($company, 'hr');
        return $this->db->row(
            'SELECT id, kind, row_count, created_at FROM attendance_imports WHERE group_id=? AND company_id=? AND owner_id=? AND id=?',
            [$actor->groupId, $company, $actor->accountId, $id],
        ) ?? throw new BusinessError(404, 'attendance_import_not_found');
    }
}
