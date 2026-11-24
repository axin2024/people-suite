<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Recruitment
{
    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly Organization $organization,
    ) {
    }

    public function request(Access $actor, int $company, int $position, string $key, array $input): array
    {
        $actor->recruitment($company, $position);
        $start = Input::date($input['proposed_start'] ?? null, 'proposed_start');
        $why = Input::text($input['justification'] ?? null, 'justification', 600);
        return $this->operations->once($actor, $company, 'requisition.request', $key, [
            'position_id' => $position, 'proposed_start' => $start, 'justification' => $why,
        ], function () use ($actor, $company, $position, $start, $why): array {
            $this->organization->lockPosition($actor, $company, $position);
            $this->organization->activeRevision($actor, $company, $position, $start);
            $id = $this->db->insert(
                "INSERT INTO requisitions (group_id, company_id, position_id, proposed_start, justification, state)
                 VALUES (?, ?, ?, ?, ?, 'pending')",
                [$actor->groupId, $company, $position, $start, $why],
            );
            return $this->record($actor, $company, 'requisition', $id, 1, 'pending');
        });
    }

    public function open(Access $actor, int $company, int $position, int $id, string $key, int $version): array
    {
        $actor->company($company, 'hr');
        $actor->recruitment($company, $position);
        return $this->operations->once($actor, $company, 'requisition.open', $key, [
            'position_id' => $position, 'id' => $id, 'version' => $version,
        ], function () use ($actor, $company, $position, $id, $version): array {
            $this->organization->lockPosition($actor, $company, $position);
            $row = $this->requisition($actor, $company, $position, $id, true);
            $this->requireState($row, $version, 'pending');
            $this->organization->activeRevision($actor, $company, $position, $row['proposed_start']);
            $this->db->run("UPDATE requisitions SET state='open', version=version+1 WHERE id=?", [$id]);
            return $this->record($actor, $company, 'requisition', $id, $version + 1, 'open');
        });
    }

    public function apply(Access $actor, int $company, int $position, int $req, string $key, array $input): array
    {
        $actor->recruitment($company, $position);
        $ref = Input::code($input['identity_ref'] ?? null, 'identity_ref');
        $name = Input::text($input['display_name'] ?? null, 'display_name', 160);
        return $this->operations->once($actor, $company, 'application.submit', $key, [
            'position_id' => $position, 'requisition_id' => $req, 'identity_ref' => $ref, 'display_name' => $name,
        ], function () use ($actor, $company, $position, $req, $ref, $name): array {
            $requisition = $this->requisition($actor, $company, $position, $req, true);
            if ($requisition['state'] !== 'open') {
                throw new BusinessError(409, 'requisition_not_open');
            }
            $this->db->run(
                'INSERT INTO candidates (group_id, identity_ref, display_name) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE identity_ref=candidates.identity_ref',
                [$actor->groupId, $ref, $name],
            );
            $candidate = $this->db->row('SELECT id, display_name FROM candidates WHERE group_id=? AND identity_ref=?', [
                $actor->groupId, $ref,
            ]);
            if ($candidate['display_name'] !== $name) {
                throw new BusinessError(409, 'candidate_profile_mismatch');
            }
            $existing = $this->db->row(
                'SELECT id, version, state FROM applications WHERE group_id=? AND company_id=? AND requisition_id=? AND candidate_id=?',
                [$actor->groupId, $company, $req, $candidate['id']],
            );
            if ($existing !== null) {
                return [
                    'application_id' => (int) $existing['id'], 'version' => (int) $existing['version'],
                    'state' => $existing['state'], 'candidate_id' => (int) $candidate['id'],
                ];
            }
            $id = $this->db->insert(
                "INSERT INTO applications (group_id, company_id, position_id, requisition_id, candidate_id, state)
                 VALUES (?, ?, ?, ?, ?, 'submitted')",
                [$actor->groupId, $company, $position, $req, $candidate['id']],
            );
            return $this->record($actor, $company, 'application', $id, 1, 'submitted', [
                'candidate_id' => (int) $candidate['id'], 'requisition_id' => $req,
            ]);
        });
    }

    public function interview(Access $actor, int $company, int $position, int $id, string $key, array $input): array
    {
        $actor->recruitment($company, $position);
        $version = Input::integer($input['version'] ?? null, 'version');
        $rating = Input::integer($input['rating'] ?? null, 'rating', 1, 5);
        $notes = Input::text($input['notes'] ?? null, 'notes', 1200);
        return $this->operations->once($actor, $company, 'application.interview', $key, [
            'position_id' => $position, 'id' => $id, 'version' => $version, 'rating' => $rating, 'notes' => $notes,
        ], function () use ($actor, $company, $position, $id, $version, $rating, $notes): array {
            $row = $this->application($actor, $company, $position, $id, true);
            $this->requireState($row, $version, 'submitted', 'interviewed');
            $review = $this->db->insert(
                'INSERT INTO interview_reviews (group_id, company_id, position_id, application_id, author_id, rating, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$actor->groupId, $company, $position, $id, $actor->accountId, $rating, $notes],
            );
            $this->setApplication($id, 'interviewed');
            return $this->record($actor, $company, 'application', $id, $version + 1, 'interviewed', ['review_id' => $review]);
        });
    }

    public function draft(Access $actor, int $company, int $position, int $application, string $key, array $input): array
    {
        $actor->recruitment($company, $position);
        $version = Input::integer($input['version'] ?? null, 'version');
        $start = Input::date($input['effective_on'] ?? null, 'effective_on');
        $minor = Input::integer($input['monthly_minor'] ?? null, 'monthly_minor', 1, 100000000000);
        return $this->operations->once($actor, $company, 'offer.draft', $key, [
            'position_id' => $position, 'application_id' => $application, 'version' => $version,
            'effective_on' => $start, 'monthly_minor' => $minor,
        ], function () use ($actor, $company, $position, $application, $version, $start, $minor): array {
            $this->organization->lockPosition($actor, $company, $position);
            $row = $this->application($actor, $company, $position, $application, true);
            $this->requireState($row, $version, 'interviewed');
            $this->organization->activeRevision($actor, $company, $position, $start);
            $id = $this->db->insert(
                "INSERT INTO offers (group_id, company_id, position_id, application_id, drafted_by, effective_on, monthly_minor, state)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$actor->groupId, $company, $position, $application, $actor->accountId, $start, $minor],
            );
            $this->setApplication($application, 'offered');
            return $this->record($actor, $company, 'offer', $id, 1, 'draft', ['application_id' => $application]);
        });
    }

    public function decide(
        Access $actor,
        int $company,
        int $position,
        int $offer,
        string $action,
        string $key,
        array $input,
    ): array {
        $actor->company($company, 'hr');
        $actor->recruitment($company, $position);
        $version = Input::integer($input['version'] ?? null, 'version');
        $decision = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $next = match ($action) {
            'sign' => 'signed',
            'accept' => 'accepted',
            'revoke' => 'revoked',
            default => throw new BusinessError(404, 'operation_not_found'),
        };
        return $this->operations->once($actor, $company, 'offer.' . $action, $key, [
            'position_id' => $position, 'id' => $offer, 'version' => $version, 'decision_ref' => $decision,
        ], function () use ($actor, $company, $position, $offer, $action, $version, $decision, $next): array {
            $this->organization->lockPosition($actor, $company, $position);
            $scope = [$actor->groupId, $company, $position, $offer];
            $row = $this->db->row('SELECT * FROM offers WHERE group_id=? AND company_id=? AND position_id=? AND id=? FOR UPDATE', $scope);
            if ($row === null) {
                throw new BusinessError(404, 'offer_not_found');
            }
            $allowed = match ($action) {
                'sign' => ['draft'],
                'accept' => ['signed'],
                'revoke' => ['draft', 'signed', 'accepted'],
            };
            $this->requireState($row, $version, ...$allowed);
            $facts = ['decision_ref' => $decision, 'application_id' => (int) $row['application_id']];
            try {
                $this->db->run(
                    'INSERT INTO offer_decisions
                        (group_id, company_id, position_id, offer_id, actor_id, decision_ref, offer_version, action)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$actor->groupId, $company, $position, $offer, $actor->accountId, $decision, $version + 1, $action],
                );
            } catch (\PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                    throw $error;
                }
                throw new BusinessError(409, 'offer_decision_conflict');
            }
            if ($action === 'sign') {
                $this->db->run('UPDATE offers SET signed_by=? WHERE id=?', [$actor->accountId, $offer]);
            } elseif ($action === 'accept') {
                $facts['slot_id'] = $this->organization->reserve($actor, $company, $position, $row['effective_on'], $offer);
                $this->setApplication((int) $row['application_id'], 'accepted');
            } else {
                $this->db->run(
                    'UPDATE headcount_slots SET released_at=CURRENT_TIMESTAMP(6)
                     WHERE group_id=? AND company_id=? AND position_id=? AND offer_id=? AND released_at IS NULL',
                    $scope,
                );
                $this->setApplication((int) $row['application_id'], 'interviewed');
            }
            $this->db->run('UPDATE offers SET state=?, version=version+1, decision_ref=? WHERE id=?', [
                $next, $decision, $offer,
            ]);
            return $this->record($actor, $company, 'offer', $offer, $version + 1, $next, $facts);
        });
    }

    public function requisitions(Access $actor, int $company, int $position, int $after): array
    {
        $actor->recruitment($company, $position);
        return $this->db->rows(
            'SELECT id, proposed_start, justification, state, version FROM requisitions
             WHERE group_id=? AND company_id=? AND position_id=? AND id>? ORDER BY id LIMIT 100',
            [$actor->groupId, $company, $position, $after],
        );
    }

    public function requisitionApplications(Access $actor, int $company, int $position, int $requisition, int $after): array
    {
        $actor->recruitment($company, $position);
        $request = $this->requisition($actor, $company, $position, $requisition, false);
        $applications = $this->db->rows(
            'SELECT a.id, a.state, a.version, c.display_name, c.identity_ref
             FROM applications a JOIN candidates c ON c.group_id=a.group_id AND c.id=a.candidate_id
             WHERE a.group_id=? AND a.company_id=? AND a.position_id=? AND a.requisition_id=? AND a.id>?
             ORDER BY a.id LIMIT 100',
            [$actor->groupId, $company, $position, $requisition, $after],
        );
        return ['requisition' => $request, 'applications' => $applications];
    }

    public function dossier(Access $actor, int $company, int $position, int $id): array
    {
        $actor->recruitment($company, $position);
        $application = $this->application($actor, $company, $position, $id, false);
        $scope = [$actor->groupId, $company, $position, $id];
        return [
            'application' => $application,
            'candidate' => $this->db->row('SELECT identity_ref, display_name FROM candidates WHERE group_id=? AND id=?', [
                $actor->groupId, $application['candidate_id'],
            ]),
            'reviews' => $this->db->rows(
                'SELECT id, author_id, rating, notes, recorded_at FROM interview_reviews
                 WHERE group_id=? AND company_id=? AND position_id=? AND application_id=? ORDER BY id',
                $scope,
            ),
            'offers' => $this->db->rows(
                'SELECT id, drafted_by, signed_by, effective_on, monthly_minor, state, version, decision_ref FROM offers
                 WHERE group_id=? AND company_id=? AND position_id=? AND application_id=? ORDER BY id',
                $scope,
            ),
            'decisions' => $this->db->rows(
                'SELECT d.offer_id, d.actor_id, d.decision_ref, d.offer_version, d.action, d.decided_at
                 FROM offer_decisions d JOIN offers o
                   ON o.group_id=d.group_id AND o.company_id=d.company_id AND o.position_id=d.position_id AND o.id=d.offer_id
                 WHERE o.group_id=? AND o.company_id=? AND o.position_id=? AND o.application_id=?
                 ORDER BY d.offer_id, d.offer_version',
                $scope,
            ),
        ];
    }

    private function requisition(Access $actor, int $company, int $position, int $id, bool $lock): array
    {
        return $this->db->row(
            'SELECT id, proposed_start, justification, state, version FROM requisitions WHERE group_id=? AND company_id=? AND position_id=? AND id=?'
                . ($lock ? ' FOR UPDATE' : ''),
            [$actor->groupId, $company, $position, $id],
        ) ?? throw new BusinessError(404, 'requisition_not_found');
    }

    private function application(Access $actor, int $company, int $position, int $id, bool $lock): array
    {
        return $this->db->row(
            'SELECT id, requisition_id, candidate_id, state, version FROM applications
             WHERE group_id=? AND company_id=? AND position_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$actor->groupId, $company, $position, $id],
        ) ?? throw new BusinessError(404, 'application_not_found');
    }

    private function setApplication(int $id, string $state): void
    {
        $this->db->run('UPDATE applications SET state=?, version=version+1 WHERE id=?', [$state, $id]);
    }

    private function requireState(array $row, int $version, string ...$states): void
    {
        if ((int) $row['version'] !== $version || !in_array($row['state'], $states, true)) {
            throw new BusinessError(409, 'state_conflict', ['version' => (int) $row['version'], 'state' => $row['state']]);
        }
    }

    private function record(Access $actor, int $company, string $type, int $id, int $version, string $state, array $facts = []): array
    {
        $this->operations->audit($actor, $company, $type, $id, $type . '.' . $state, ['version' => $version, ...$facts]);
        return [$type . '_id' => $id, 'version' => $version, 'state' => $state, ...$facts];
    }
}
