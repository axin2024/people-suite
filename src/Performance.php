<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Performance
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly WorkforceAccess $workforce,
    ) {
    }

    public function create(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $cycle = [
            'reference' => Input::code($input['reference'] ?? null, 'reference'),
            'title' => Input::text($input['title'] ?? null, 'title', 160),
            'starts_on' => Input::date($input['starts_on'] ?? null, 'starts_on'),
            'ends_before' => Input::date($input['ends_before'] ?? null, 'ends_before'),
        ];
        if ($cycle['starts_on'] >= $cycle['ends_before']) {
            throw new BusinessError(422, 'invalid_performance_period');
        }
        return $this->operations->once($actor, $company, 'performance.cycle_created', $key, $cycle, function () use ($actor, $company, $cycle): array {
            $id = $this->db->insert(
                'INSERT INTO performance_cycles (group_id, company_id, reference, title, starts_on, ends_before, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$actor->groupId, $company, ...array_values($cycle), $actor->accountId],
            );
            $this->operations->audit($actor, $company, 'performance_cycle', $id, 'performance.cycle_created', ['reference' => $cycle['reference']]);
            return ['cycle_id' => $id, 'state' => 'goals', 'version' => 1];
        });
    }

    public function enroll(Access $actor, int $company, int $cycle, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $employment = Input::integer($input['employment_id'] ?? null, 'employment_id');
        $day = Input::date($input['scope_on'] ?? null, 'scope_on');
        return $this->operations->once($actor, $company, 'performance.enrolled', $key, [
            'cycle_id' => $cycle, 'employment_id' => $employment, 'scope_on' => $day,
        ], function () use ($actor, $company, $cycle, $employment, $day): array {
            $scope = [$actor->groupId, $company, $cycle];
            $period = $this->cycle($scope, lock: true);
            $this->requirePhase($period, 'goals');
            if ($day < $period['starts_on'] || $day >= $period['ends_before']) {
                throw new BusinessError(422, 'performance_scope_date_outside_cycle');
            }
            $worker = $this->workforce->lockEmployment($actor, $company, $employment, $day);
            $identity = $this->db->row(
                "SELECT e.employee_code, e.display_name, e.account_id, a.id AS assignment_id, r.manager_account_id
                 FROM employees e JOIN assignments a ON a.group_id=e.group_id AND a.employment_id=? AND a.company_id=?
                 JOIN reporting_lines r ON r.group_id=a.group_id AND r.company_id=a.company_id AND r.employment_id=a.employment_id
                 JOIN company_roles s ON s.group_id=e.group_id AND s.company_id=a.company_id AND s.account_id=e.account_id AND s.role='employee'
                 JOIN company_roles m ON m.group_id=r.group_id AND m.company_id=r.company_id AND m.account_id=r.manager_account_id AND m.role='manager'
                 JOIN accounts u ON u.group_id=s.group_id AND u.id=s.account_id AND u.enabled=1
                 JOIN accounts v ON v.group_id=m.group_id AND v.id=m.account_id AND v.enabled=1
                 WHERE e.group_id=? AND e.id=? AND a.effective_from<=? AND (a.effective_until IS NULL OR a.effective_until>?)
                   AND r.effective_from<=? AND (r.effective_until IS NULL OR r.effective_until>?)",
                [$employment, $company, $actor->groupId, $worker['employee_id'], $day, $day, $day, $day],
            ) ?? throw new BusinessError(409, 'performance_requires_employee_and_manager');
            $id = $this->db->insert(
                'INSERT INTO performance_participants (group_id, company_id, cycle_id, employment_id, assignment_id, employee_account_id,
                        manager_account_id, scope_on, employee_code, employee_name, goals)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $employment, $identity['assignment_id'], $identity['account_id'], $identity['manager_account_id'],
                    $day, $identity['employee_code'], $identity['display_name'], '[]'],
            );
            $this->operations->audit($actor, $company, 'performance_participant', $id, 'performance.enrolled', [
                'cycle_id' => $cycle, 'employment_id' => $employment, 'scope_on' => $day,
            ]);
            return ['participant_id' => $id, 'employment_id' => $employment, 'scope_on' => $day, 'version' => 1];
        });
    }

    public function cycles(Access $actor, int $company, int $after): array
    {
        $scope = [$actor->groupId, $company, $after];
        [$filter, $parameters] = $this->visibleParticipants($actor, $company);
        return $this->db->rows(
            'SELECT c.id, c.reference, c.title, c.starts_on, c.ends_before, c.state, c.version, c.finalized_at
             FROM performance_cycles c WHERE c.group_id=? AND c.company_id=? AND c.id>?' . ($filter === '' ? '' :
                ' AND EXISTS (SELECT 1 FROM performance_participants p WHERE p.group_id=c.group_id AND p.company_id=c.company_id
                              AND p.cycle_id=c.id' . $filter . ')') . ' ORDER BY c.id LIMIT 100',
            [...$scope, ...$parameters],
        );
    }

    public function detail(Access $actor, int $company, int $cycle, int $after): array
    {
        $scope = [$actor->groupId, $company, $cycle];
        [$filter, $parameters] = $this->visibleParticipants($actor, $company);
        if ($filter !== '' && $this->db->row(
            'SELECT 1 FROM performance_participants p WHERE p.group_id=? AND p.company_id=? AND p.cycle_id=?' . $filter . ' LIMIT 1',
            [...$scope, ...$parameters],
        ) === null) {
            throw new BusinessError(404, 'performance_cycle_not_found');
        }
        $period = $this->cycle($scope);
        $people = $this->db->rows(
            'SELECT p.id, p.employment_id, p.employee_code, p.employee_name, p.scope_on, p.version, p.goal_confirmed_at
             FROM performance_participants p WHERE p.group_id=? AND p.company_id=? AND p.cycle_id=? AND p.id>?'
            . $filter . ' ORDER BY p.id LIMIT 100',
            [...$scope, $after, ...$parameters],
        );
        return ['cycle' => $period, 'participants' => $people];
    }

    public function view(Access $actor, int $company, int $cycle, int $id): array
    {
        $scope = [$actor->groupId, $company, $cycle];
        $participant = $this->participant([...$scope, $id]);
        $role = $this->viewRole($actor, $company, $participant);
        $period = $this->cycle($scope);
        $revisions = $this->db->rows(
            'SELECT revision, kind, score, goal_scores, feedback, rationale, decision_ref, recorded_by, recorded_at
             FROM performance_revisions WHERE group_id=? AND company_id=? AND cycle_id=? AND participant_id=? ORDER BY revision',
            [...$scope, $id],
        );
        $visible = [];
        foreach ($revisions as $revision) {
            if ($role === 'employee' && ($period['state'] !== 'finalized' || $revision['kind'] === 'manager')) {
                continue;
            }
            if ($role === 'manager' && $period['state'] !== 'finalized' && $revision['kind'] !== 'manager') {
                continue;
            }
            $revision['goal_scores'] = $revision['goal_scores'] === null ? null : json_decode($revision['goal_scores'], true, flags: JSON_THROW_ON_ERROR);
            if ($role !== 'hr') {
                unset($revision['rationale'], $revision['recorded_by']);
            }
            $visible[] = $revision;
        }
        unset($participant['employee_account_id'], $participant['manager_account_id'], $participant['latest_revision']);
        return ['cycle' => $period, 'participant' => $participant, 'revisions' => $visible];
    }

    public function advance(Access $actor, int $company, int $cycle, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $version = Input::integer($input['version'] ?? null, 'version');
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        return $this->operations->once($actor, $company, 'performance.cycle_advanced', $key, [
            'cycle_id' => $cycle, 'version' => $version, 'decision_ref' => $decision,
        ], function () use ($actor, $company, $cycle, $version, $decision): array {
            $scope = [$actor->groupId, $company, $cycle];
            $period = $this->cycle($scope, lock: true);
            $this->requireVersion($period, $version, 'performance_cycle_conflict');
            [$state, $pending] = match ($period['state']) {
                'goals' => ['evaluation', 'goal_confirmed_at IS NULL'],
                'evaluation' => ['calibration', 'latest_revision<1'],
                'calibration' => ['finalized', 'latest_revision<2'],
                default => throw new BusinessError(409, 'performance_cycle_already_finalized'),
            };
            $progress = $this->db->row(
                'SELECT COUNT(*) AS participants, COALESCE(SUM(' . $pending . '),0) AS pending
                 FROM performance_participants WHERE group_id=? AND company_id=? AND cycle_id=?', $scope,
            );
            if ((int) $progress['participants'] === 0 || (int) $progress['pending'] !== 0) {
                throw new BusinessError(409, 'performance_cycle_incomplete', [
                    'state' => $period['state'], 'participants' => (int) $progress['participants'], 'pending' => (int) $progress['pending'],
                ]);
            }
            $this->db->run(
                "UPDATE performance_cycles SET state=?, version=version+1, finalized_at=IF(?='finalized',CURRENT_TIMESTAMP(6),NULL)
                 WHERE group_id=? AND company_id=? AND id=?", [$state, $state, ...$scope],
            );
            $this->operations->audit($actor, $company, 'performance_cycle', $cycle, 'performance.cycle_advanced', [
                'state' => $state, 'version' => $version + 1, 'decision_ref' => $decision,
            ]);
            return ['cycle_id' => $cycle, 'state' => $state, 'version' => $version + 1];
        });
    }

    public function updateParticipant(Access $actor, int $company, int $cycle, int $id, string $action, string $key, array $input): array
    {
        [$phase, $allowed] = match ($action) {
            'goals' => ['goals', ['employee', 'manager']],
            'confirm-goals' => ['goals', ['manager']],
            'self-assessment' => ['evaluation', ['employee']],
            default => throw new BusinessError(404, 'route_not_found'),
        };
        $actor->company($company, ...$allowed);
        $version = Input::integer($input['version'] ?? null, 'version');
        $value = match ($action) {
            'goals' => $this->goals($input['goals'] ?? null),
            'self-assessment' => Input::text($input['assessment'] ?? null, 'assessment', 2000),
            'confirm-goals' => Input::code($input['decision_ref'] ?? null, 'decision_ref'),
        };
        return $this->operations->once($actor, $company, 'performance.' . $action, $key, [
            'cycle_id' => $cycle, 'participant_id' => $id, 'version' => $version, 'value' => $value,
        ], function () use ($actor, $company, $cycle, $id, $action, $allowed, $phase, $version, $value): array {
            $scope = [$actor->groupId, $company, $cycle];
            $period = $this->cycle($scope, lock: true);
            $participant = $this->participant([...$scope, $id], lock: true);
            $this->requireParticipantActor($actor, $company, $participant, $allowed);
            $this->requirePhase($period, $phase);
            $this->requireVersion($participant, $version, 'performance_participant_conflict');
            if ($phase === 'goals' && $participant['goal_confirmed_at'] !== null) {
                throw new BusinessError(409, 'performance_goals_already_confirmed');
            }
            if ($action === 'confirm-goals' && $participant['goals'] === []) {
                throw new BusinessError(409, 'performance_goals_required');
            }
            if ($action === 'self-assessment' && (int) $participant['latest_revision'] !== 0) {
                throw new BusinessError(409, 'performance_evaluation_already_submitted');
            }
            [$change, $values] = match ($action) {
                'goals' => ['goals=?', [json_encode($value, JSON_THROW_ON_ERROR)]],
                'confirm-goals' => ['goal_confirmed_at=CURRENT_TIMESTAMP(6)', []],
                'self-assessment' => ['self_assessment=?', [$value]],
            };
            $this->db->run('UPDATE performance_participants SET ' . $change . ', version=version+1
                WHERE group_id=? AND company_id=? AND cycle_id=? AND id=?', [...$values, ...$scope, $id]);
            $facts = ['cycle_id' => $cycle, 'version' => $version + 1];
            if ($action === 'confirm-goals') {
                $facts['decision_ref'] = $value;
            }
            $this->operations->audit($actor, $company, 'performance_participant', $id, 'performance.' . $action, $facts);
            return ['participant_id' => $id, 'version' => $version + 1];
        });
    }

    public function recordRevision(Access $actor, int $company, int $cycle, int $id, string $kind, string $key, array $input): array
    {
        [$phase, $role] = match ($kind) {
            'manager' => ['evaluation', 'manager'],
            'calibration' => ['calibration', 'hr'],
            'amendment' => ['finalized', 'hr'],
            default => throw new BusinessError(404, 'route_not_found'),
        };
        $actor->company($company, $role);
        $version = Input::integer($input['version'] ?? null, 'version');
        $rating = [
            'decision_ref' => Input::code($input['decision_ref'] ?? null, 'decision_ref'),
            'feedback' => Input::text($input['feedback'] ?? null, 'feedback', 2000),
            'goal_scores' => $kind === 'manager' ? $this->goalScores($input['goal_scores'] ?? null) : null,
            'score' => $kind === 'manager' ? null : Input::integer($input['score'] ?? null, 'score', 0, 100),
            'rationale' => $kind === 'manager' ? null : Input::text($input['rationale'] ?? null, 'rationale', 1200),
        ];
        return $this->operations->once($actor, $company, 'performance.' . $kind, $key, [
            'cycle_id' => $cycle, 'participant_id' => $id, 'version' => $version, ...$rating,
        ], function () use ($actor, $company, $cycle, $id, $kind, $phase, $role, $version, $rating): array {
            $scope = [$actor->groupId, $company, $cycle];
            $period = $this->cycle($scope, lock: true);
            $participant = $this->participant([...$scope, $id], lock: true);
            if ($role === 'manager') {
                $this->requireParticipantActor($actor, $company, $participant, [$role]);
            } elseif (in_array($actor->accountId, [(int) $participant['employee_account_id'], (int) $participant['manager_account_id']], true)) {
                throw new BusinessError(403, 'performance_independent_calibrator_required');
            }
            $this->requirePhase($period, $phase);
            $this->requireVersion($participant, $version, 'performance_participant_conflict');
            $revision = (int) $participant['latest_revision'] + 1;
            if (($kind === 'manager' && $revision !== 1) || ($kind === 'calibration' && $revision !== 2)) {
                throw new BusinessError(409, 'performance_revision_conflict', ['latest_revision' => $revision - 1]);
            }
            $score = $rating['score'] ?? $this->weightedScore($participant['goals'], $rating['goal_scores']);
            $this->db->run(
                'INSERT INTO performance_revisions (group_id, company_id, cycle_id, participant_id, revision, kind, score,
                    goal_scores, feedback, rationale, decision_ref, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $id, $revision, $kind, $score,
                    $rating['goal_scores'] === null ? null : json_encode($rating['goal_scores'], JSON_THROW_ON_ERROR),
                    $rating['feedback'], $rating['rationale'], $rating['decision_ref'], $actor->accountId],
            );
            $this->db->run(
                'UPDATE performance_participants SET latest_revision=?, version=version+1 WHERE group_id=? AND company_id=? AND cycle_id=? AND id=?',
                [$revision, ...$scope, $id],
            );
            $this->operations->audit($actor, $company, 'performance_participant', $id, 'performance.' . $kind, [
                'cycle_id' => $cycle, 'revision' => $revision, 'version' => $version + 1, 'decision_ref' => $rating['decision_ref'],
            ]);
            return ['participant_id' => $id, 'revision' => $revision, 'version' => $version + 1, 'score' => $score];
        });
    }

    private function cycle(array $scope, bool $lock = false): array
    {
        return $this->db->row(
            'SELECT id, reference, title, starts_on, ends_before, state, version, finalized_at
             FROM performance_cycles WHERE group_id=? AND company_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            $scope,
        ) ?? throw new BusinessError(404, 'performance_cycle_not_found');
    }

    private function participant(array $scope, bool $lock = false): array
    {
        $row = $this->db->row(
            'SELECT id, employment_id, assignment_id, employee_account_id, manager_account_id, scope_on,
                    employee_code, employee_name, goals, goal_confirmed_at, self_assessment, latest_revision, version
             FROM performance_participants WHERE group_id=? AND company_id=? AND cycle_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            $scope,
        ) ?? throw new BusinessError(404, 'performance_participant_not_found');
        $row['goals'] = json_decode($row['goals'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    private function visibleParticipants(Access $actor, int $company): array
    {
        $roles = $actor->roles($company);
        if (in_array('hr', $roles, true)) {
            return ['', []];
        }
        $grants = [];
        foreach (['manager', 'employee'] as $role) {
            if (in_array($role, $roles, true)) {
                $grants[] = 'p.' . $role . '_account_id=?';
            }
        }
        if ($grants === []) {
            throw new BusinessError(404, 'company_not_found');
        }
        return [' AND (' . implode(' OR ', $grants) . ')', array_fill(0, count($grants), $actor->accountId)];
    }

    private function viewRole(Access $actor, int $company, array $participant): string
    {
        if (in_array('hr', $actor->roles($company), true)) {
            return 'hr';
        }
        return $this->requireParticipantActor($actor, $company, $participant, ['employee', 'manager']);
    }

    private function requireParticipantActor(Access $actor, int $company, array $participant, array $allowed): string
    {
        foreach (array_intersect($allowed, $actor->roles($company)) as $role) {
            if ((int) $participant[$role . '_account_id'] === $actor->accountId) {
                return $role;
            }
        }
        throw new BusinessError(404, 'performance_participant_not_found');
    }

    private function requirePhase(array $cycle, string $phase): void
    {
        if ($cycle['state'] !== $phase) {
            throw new BusinessError(409, 'performance_cycle_phase_conflict', ['state' => $cycle['state'], 'version' => $cycle['version']]);
        }
    }

    private function requireVersion(array $row, int $version, string $reason): void
    {
        if ((int) $row['version'] !== $version) {
            throw new BusinessError(409, $reason, ['version' => (int) $row['version']]);
        }
    }

    private function goals(mixed $input): array
    {
        $items = $this->goalItems($input);
        $goals = [];
        foreach ($items as $code => $item) {
            $goals[] = [
                'code' => (string) $code, 'title' => Input::text($item['title'] ?? null, 'goal_title', 160),
                'target' => Input::text($item['target'] ?? null, 'goal_target', 400),
                'weight_bps' => Input::integer($item['weight_bps'] ?? null, 'weight_bps', 1, 10000),
            ];
        }
        if (array_sum(array_column($goals, 'weight_bps')) !== 10000) {
            throw new BusinessError(422, 'performance_goal_weights_must_total_10000');
        }
        return $goals;
    }

    private function goalScores(mixed $input): array
    {
        $scores = [];
        foreach ($this->goalItems($input) as $code => $item) {
            $scores[] = ['code' => (string) $code, 'score' => Input::integer($item['score'] ?? null, 'goal_score', 0, 100)];
        }
        return $scores;
    }

    private function goalItems(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === [] || count($input) > 12) {
            throw new BusinessError(422, 'invalid_performance_goal_list');
        }
        $items = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                throw new BusinessError(422, 'invalid_performance_goal');
            }
            $code = Input::code($item['code'] ?? null, 'goal_code');
            if (isset($items[$code])) {
                throw new BusinessError(422, 'duplicate_performance_goal');
            }
            $items[$code] = $item;
        }
        return $items;
    }

    private function weightedScore(array $goals, array $scores): int
    {
        $weights = array_column($goals, 'weight_bps', 'code');
        $ratings = array_column($scores, 'score', 'code');
        if (array_diff_key($weights, $ratings) !== [] || array_diff_key($ratings, $weights) !== []) {
            throw new BusinessError(422, 'performance_goal_scores_must_match');
        }
        $total = 0;
        foreach ($weights as $code => $weight) {
            $total += $weight * $ratings[$code];
        }
        return intdiv($total + 5000, 10000);
    }
}
