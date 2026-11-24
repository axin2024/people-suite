<?php

declare(strict_types=1);

use PeopleSuite\Accounts;
use PeopleSuite\BusinessError;
use PeopleSuite\Database;
use PeopleSuite\Employment;
use PeopleSuite\Leave;
use PeopleSuite\Operations;
use PeopleSuite\Organization;
use PeopleSuite\Recruitment;
use PeopleSuite\Schema;
use PeopleSuite\WorkforceAccess;

require __DIR__ . '/../src/bootstrap.php';

const DEMO_PASSWORD = 'Synthetic-assertions-44';

$db = Database::connect();
$accounts = new Accounts($db);
$operations = new Operations($db);
$organization = new Organization($db, $operations);
$recruitment = new Recruitment($db, $operations, $organization);
$leave = new Leave($db, $operations, new WorkforceAccess($db, $operations));

$mode = $argv[1] ?? '';
if (in_array($mode, ['accept', 'leave'], true)) {
    [$group, $company, $object, $index, $key, $barrier] = array_slice($argv, 2);
    $actor = $accounts->authenticate($group, 'admin@example.test', DEMO_PASSWORD);
    $deadline = microtime(true) + 15;
    touch($barrier . '-' . $index);
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Start signal timed out.');
        }
        clearstatcache(true, $barrier);
        usleep(10000);
    }
    try {
        $result = match ($mode) {
            'accept' => $recruitment->decide($actor, (int) $company, (int) $object, (int) $index, 'accept', $key, [
                'version' => 2, 'decision_ref' => $key,
            ]),
            'leave' => $leave->request($actor, (int) $company, (int) $object, $key, [
                'kind' => 'annual', 'workday' => '2026-04-' . (20 + (int) $index), 'minutes' => 480,
                'reason' => 'Synthetic leave', 'reference' => $key,
            ]),
        };
    } catch (BusinessError $error) {
        $result = ['error' => $error->reason];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}

$schema = new Schema($db);
$schemaName = $db->row('SELECT DATABASE() AS name')['name'];
verify(str_starts_with($schemaName, 'hcm_test_'), 'Use a dedicated hcm_test_* database.');
$schema->migrate();
verify($schema->migrate() === [] && $schema->ready(), 'Migration replay and checksum.');
$group = 'test-' . bin2hex(random_bytes(6));
$ids = $accounts->createGroup($group, 'Workforce fixtures', 'admin@example.test', 'Fixture admin', DEMO_PASSWORD);
$actor = $accounts->authenticate($group, 'ADMIN@example.test', DEMO_PASSWORD);
verify($actor->groupId === $ids['group_id'], 'Group-scoped login.');
reject(fn() => $accounts->authenticate($group, 'admin@example.test', 'wrong-password'), 'invalid_credentials');

$alpha = $organization->createCompany($actor, 'NORTH', 'North Studio', 'Asia/Shanghai', 'CNY')['company_id'];
$beta = $organization->createCompany($actor, 'SOUTH', 'South Studio', 'Asia/Shanghai', 'CNY')['company_id'];
$organization->grantRole($actor, $alpha, $actor->accountId, 'hr');
$organization->grantRole($actor, $beta, $actor->accountId, 'hr');

$recruiterId = $accounts->createAccount($actor->groupId, 'recruiter@example.test', 'Fixture recruiter', DEMO_PASSWORD);
$organization->grantRole($actor, $alpha, $recruiterId, 'recruiter');
$recruiter = $accounts->authenticate($group, 'recruiter@example.test', DEMO_PASSWORD);
$dept = ['code' => 'ROOT', 'name' => 'Operations', 'effective_from' => '2026-04-01'];
$root = $organization->createDepartment($actor, $alpha, 'root', $dept)['department_id'];
verify($organization->createDepartment($actor, $alpha, 'root', array_reverse($dept, true))['department_id'] === $root, 'Operation replay.');
reject(fn() => $organization->createDepartment($actor, $alpha, 'root', [...$dept, 'name' => 'Changed']), 'request_key_conflict');

$child = ['code' => 'LOG', 'name' => 'Logistics', 'effective_from' => '2026-04-02', 'parent_id' => $root];
$warehouse = $organization->createDepartment($actor, $alpha, 'warehouse', $child)['department_id'];
verify(count($organization->departments($actor, $alpha, '2026-04-01')) === 1, 'Effective department dates.');
verify($organization->departments($actor, $alpha, '2026-04-02')[1]['parent_id'] === $root, 'Department tree uses scoped parent.');
reject(fn() => $organization->createDepartment($actor, $beta, 'cross-parent', $child), 'department_not_effective');

$job = ['code' => 'SHIFT', 'department_id' => $warehouse, 'title' => 'Shift coordinator', 'effective_from' => '2026-04-02'];
$p1 = $organization->createPosition($actor, $alpha, 'shift', $job);
$p2 = $organization->createPosition($actor, $alpha, 'planning', [...$job, 'code' => 'PLAN', 'title' => 'Planner']);
$position = $p1['position_id'];
$second = $p2['position_id'];
$rev = $organization->revisePosition($actor, $alpha, $position, 'revise-shift', [
    ...$job, 'revision_id' => $p1['revision_id'], 'title' => 'Shift lead', 'effective_from' => '2026-04-10', 'active' => true,
]);
$history = $organization->history($actor, $alpha, $position);
verify(count($history) === 2 && $history[0]['effective_until'] === '2026-04-10', 'Position history closes the prior interval.');
verify($history[1]['title'] === 'Shift lead' && $history[1]['id'] === $rev['revision_id'], 'New position title after cutover.');
$budget = ['starts_on' => '2026-04-10', 'ends_before' => '2026-05-01', 'capacity' => 1];
$organization->budget($actor, $alpha, $position, 'april-shift', $budget);
reject(fn() => $organization->budget($actor, $alpha, $position, 'overlap', [
    ...$budget, 'starts_on' => '2026-04-15',
]), 'budget_period_overlap');
$organization->grantRecruitingPosition($actor, $alpha, $position, $recruiter->accountId);
$opening = ['proposed_start' => '2026-04-16', 'justification' => 'New roster'];
reject(fn() => $recruitment->request($recruiter, $alpha, $second, 'out-of-scope', $opening), 'position_not_found');
reject(fn() => $recruitment->request($recruiter, $beta, $position, 'cross-legal', $opening), 'company_not_found');
$req1 = $recruitment->request($recruiter, $alpha, $position, 'request-shift', $opening)['requisition_id'];
$req2 = $recruitment->request($actor, $alpha, $second, 'request-plan', $opening)['requisition_id'];
$recruitment->open($actor, $alpha, $position, $req1, 'open-shift', 1);
$recruitment->open($actor, $alpha, $second, $req2, 'open-plan', 1);

$candidate = ['identity_ref' => 'SYN-CAND-01', 'display_name' => 'Alex Fixture'];
$a1 = $recruitment->apply($recruiter, $alpha, $position, $req1, 'apply-shift', $candidate);
$a2 = $recruitment->apply($actor, $alpha, $second, $req2, 'apply-plan', $candidate);
verify($a1['candidate_id'] === $a2['candidate_id'] && $a1['application_id'] !== $a2['application_id'], 'One person and two applications.');
$recruitment->interview($recruiter, $alpha, $position, $a1['application_id'], 'review-shift', [
    'version' => 1, 'rating' => 4, 'notes' => 'Warehouse shift exercise.',
]);
$recruitment->interview($actor, $alpha, $second, $a2['application_id'], 'review-plan', [
    'version' => 1, 'rating' => 3, 'notes' => 'Planning exercise.',
]);
verify($recruitment->dossier($actor, $alpha, $second, $a2['application_id'])['reviews'][0]['rating'] === 3, 'Retain each evaluation.');
reject(fn() => $recruitment->dossier($recruiter, $alpha, $second, $a2['application_id']), 'position_not_found');
reject(fn() => $recruitment->dossier($recruiter, $alpha, $position, $a2['application_id']), 'application_not_found');

$draft = ['version' => 2, 'effective_on' => '2026-04-16', 'monthly_minor' => 900000];
$offer1 = $recruitment->draft($recruiter, $alpha, $position, $a1['application_id'], 'draft-shift', $draft)['offer_id'];
$other = $recruitment->apply($recruiter, $alpha, $position, $req1, 'apply-other', [
    'identity_ref' => 'SYN-CAND-02', 'display_name' => 'Jordan Fixture',
]);
$recruitment->interview($recruiter, $alpha, $position, $other['application_id'], 'review-other', [
    'version' => 1, 'rating' => 5, 'notes' => 'Alternative candidate exercise.',
]);
$offer2 = $recruitment->draft($recruiter, $alpha, $position, $other['application_id'], 'draft-other', $draft)['offer_id'];
reject(fn() => $recruitment->decide($recruiter, $alpha, $position, $offer1, 'sign', 'sign-no-role', [
    'version' => 1, 'decision_ref' => 'sign-no-role',
]), 'company_not_found');
foreach ([$offer1, $offer2] as $offer) {
    $recruitment->decide($actor, $alpha, $position, $offer, 'sign', 'sign-' . $offer, [
        'version' => 1, 'decision_ref' => 'sign-' . $offer,
    ]);
}

$race = raceRequests('accept', $group, $alpha, $position, [$offer1, $offer2]);
$winners = array_filter($race, fn(array $item): bool => ($item['state'] ?? '') === 'accepted');
$losers = array_filter($race, fn(array $item): bool => ($item['error'] ?? '') === 'headcount_exhausted');
verify(count($winners) === 1 && count($losers) === 1, 'Only one concurrent acceptance wins a one-slot budget.');
$winner = (int) array_key_first($winners);
$loser = (int) array_key_first($losers);
$winnerResponse = $winners[$winner];
verify($recruitment->decide($actor, $alpha, $position, $winner, 'accept', 'race-' . $winner, [
    'version' => 2, 'decision_ref' => 'race-' . $winner,
]) == $winnerResponse, 'Replay after response loss does not consume another slot.');
$error = reject(fn() => $organization->revisePosition($actor, $alpha, $position, 'retire', [
    ...$job, 'revision_id' => $rev['revision_id'], 'effective_from' => '2026-04-20', 'active' => false,
]), 'position_still_occupied');
verify(count($error->details['occupancies']) === 1 && $error->details['occupancies'][0]['offer_id'] === $winner, 'Retirement reports the blocking slot.');

$recruitment->decide($actor, $alpha, $position, $winner, 'revoke', 'revoke-winner', [
    'version' => 3, 'decision_ref' => 'WITHDRAW-001',
]);
$event = $db->row("SELECT facts FROM audit_events WHERE group_id=? AND object_type='offer' AND object_id=? AND action='offer.revoked'", [
    $actor->groupId, $winner,
]);
verify(json_decode($event['facts'], true)['decision_ref'] === 'WITHDRAW-001', 'Revocation reason can be traced to its decision.');
verify((int) $db->row('SELECT COUNT(*) AS decisions FROM offer_decisions WHERE group_id=? AND company_id=? AND offer_id=?', [
    $actor->groupId, $alpha, $winner,
])['decisions'] === 3, 'Signing, acceptance and revocation remain independently attributable.');
$accepted = $recruitment->decide($actor, $alpha, $position, $loser, 'accept', 'race-' . $loser, [
    'version' => 2, 'decision_ref' => 'race-' . $loser,
]);
verify($accepted['state'] === 'accepted' && $accepted['slot_id'] !== $winnerResponse['slot_id'], 'Failed attempt can be retried after release.');
$slotCounts = $db->row(
    'SELECT COUNT(*) AS historical, SUM(released_at IS NULL) AS held FROM headcount_slots WHERE group_id=? AND position_id=?',
    [$actor->groupId, $position],
);
verify((int) $slotCounts['historical'] === 2 && (int) $slotCounts['held'] === 1, 'Keep the released slot and a single active allocation.');

$intake = (new Employment($db, $operations, $organization))->onboard($actor, $alpha, $position, $loser, 'new-employee', [
    'version' => 3, 'employee_code' => 'RACE-EMP', 'contract_ref' => 'RACE-CONTRACT',
]);
$employmentId = $intake['employment_id'];
$leave->grant($actor, $alpha, $employmentId, 'grant-race', [
    'kind' => 'annual', 'year' => 2026, 'minutes' => 480, 'decision_ref' => 'ANNUAL-RACE',
]);
$leaveRace = raceRequests('leave', $group, $alpha, $employmentId, [1, 2]);
verify(count(array_filter($leaveRace, fn(array $item): bool => ($item['state'] ?? '') === 'pending')) === 1, 'Only one leave request can reserve the last day.');
verify(count(array_filter($leaveRace, fn(array $item): bool => ($item['error'] ?? '') === 'leave_balance_insufficient')) === 1, 'The other transaction reports the depleted balance.');
verify($leave->summary($actor, $alpha, $employmentId, '2026-04-21')['balances'][0]['reserved_minutes'] === 480,
    'Two independent MySQL sessions must preserve the exact reserved balance.');

$outside = $accounts->createGroup($group . '-outside', 'Isolated group', 'admin@example.test', 'Separate admin', DEMO_PASSWORD);
$outsider = $accounts->session($outside['group_id'], $outside['account_id']);
reject(fn() => $organization->grantRole($outsider, $alpha, $outsider->accountId, 'hr'), 'company_not_found');
reject(fn() => $recruitment->dossier($outsider, $alpha, $position, $a1['application_id']), 'company_not_found');

echo "Organization, recruitment and leave passed, including independent MySQL sessions competing for a slot and for the last leave day.\n";

function verify(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function reject(callable $operation, string $reason): BusinessError
{
    try {
        $operation();
    } catch (BusinessError $error) {
        verify($error->reason === $reason, "Expected {$reason}, received {$error->reason}.");
        return $error;
    }
    throw new RuntimeException("Expected failure {$reason}.");
}

function raceRequests(string $mode, string $group, int $company, int $object, array $indices): array
{
    $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hcm-race-' . bin2hex(random_bytes(12));
    $workers = [];
    $results = [];
    try {
        foreach ($indices as $index) {
            $options = PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=pdo_mysql'] : [];
            $command = [
                PHP_BINARY, ...$options, __FILE__, $mode,
                $group, (string) $company, (string) $object, (string) $index, 'race-' . $index, $barrier,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start an acceptance process.');
            }
            $workers[$index] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        foreach ($indices as $index) {
            $ready = $barrier . '-' . $index;
            while (!is_file($ready)) {
                if (microtime(true) >= $deadline) {
                throw new RuntimeException('Workers did not reach the start barrier.');
                }
                clearstatcache(true, $ready);
                usleep(10000);
            }
        }
        touch($barrier);
        foreach ($workers as $index => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            unset($workers[$index]);
            verify($code === 0, 'Test worker failed: ' . $errors);
            $results[$index] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($workers as [$process, $pipes]) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if (is_file($barrier)) {
            unlink($barrier);
        }
        foreach ($indices as $index) {
            $ready = $barrier . '-' . $index;
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }
    return $results;
}
