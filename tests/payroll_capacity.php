<?php

declare(strict_types=1);

use PeopleSuite\Access;
use PeopleSuite\Accounts;
use PeopleSuite\Attendance;
use PeopleSuite\AttendanceCertifications;
use PeopleSuite\Database;
use PeopleSuite\Employment;
use PeopleSuite\Operations;
use PeopleSuite\Organization;
use PeopleSuite\Payroll;
use PeopleSuite\PayrollAdjustments;
use PeopleSuite\PayrollCalculator;
use PeopleSuite\PayrollCosts;
use PeopleSuite\Recruitment;
use PeopleSuite\Schema;
use PeopleSuite\WorkforceAccess;

require __DIR__ . '/../src/bootstrap.php';

const MONTH_START = '2026-04-01';
const CUTOVER = '2026-04-16';
const MONTH_END = '2026-05-01';
const MONTHLY_MINOR = 900000;
const SHIFTS_PER_WORKER = 20;
const FIXTURE_PASSWORD = 'Capacity-example-90210';

$workers = filter_var($argv[1] ?? '1000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
if ($workers === false || $argc > 2) {
    fwrite(STDERR, "Usage: php tests/payroll_capacity.php [workers between 1 and 10000]\n");
    exit(2);
}
$db = Database::connect();
$schema = $db->row('SELECT DATABASE() AS name')['name'];
check(is_string($schema) && str_starts_with($schema, 'hcm_test_'), 'Use a dedicated hcm_test_* database.');
(new Schema($db))->migrate();

$setupStarted = hrtime(true);
$accounts = new Accounts($db);
$ops = new Operations($db);
$org = new Organization($db, $ops);
$recruitment = new Recruitment($db, $ops, $org);
$employment = new Employment($db, $ops, $org);
$workforce = new WorkforceAccess($db, $ops);
$attendance = new Attendance($db, $ops, $workforce);
$certifications = new AttendanceCertifications($db, $ops, $workforce, $attendance);
$payroll = new Payroll($db, $ops, $attendance, $certifications, new PayrollCalculator(), new PayrollAdjustments($db, $ops));

$group = 'capacity-' . bin2hex(random_bytes(6));
$ids = $accounts->createGroup($group, 'Payroll capacity fixtures', 'hr@example.test', 'Fixture HR', FIXTURE_PASSWORD);
$hr = new Access($db, $ids['group_id'], $ids['account_id']);
$company = $org->createCompany($hr, 'CAP', 'Capacity fixture', 'Asia/Shanghai', 'CNY')['company_id'];
$org->grantRole($hr, $company, $hr->accountId, 'hr');
$maker = new Access($db, $hr->groupId, $accounts->createAccount($hr->groupId, 'maker@example.test', 'Fixture maker', FIXTURE_PASSWORD));
$reviewer = new Access($db, $hr->groupId, $accounts->createAccount($hr->groupId, 'reviewer@example.test', 'Fixture reviewer', FIXTURE_PASSWORD));
$org->grantRole($hr, $company, $maker->accountId, 'payroll_maker');
$org->grantRole($hr, $company, $reviewer->accountId, 'payroll_reviewer');

$source = position($org, $hr, $company, 'OPS', 'Operations', $workers);
$target = position($org, $hr, $company, 'FIN', 'Finance', $workers);
$req = $recruitment->request($hr, $company, $source, 'capacity-req', [
    'proposed_start' => MONTH_START, 'justification' => 'Create a reproducible capacity fixture.',
])['requisition_id'];
$recruitment->open($hr, $company, $source, $req, 'capacity-open', 1);
$employmentIds = [];
for ($i = 1; $i <= $workers; $i++) {
    $ref = 'LOAD-' . $i;
    $worker = $recruitment->apply($hr, $company, $source, $req, $ref . '-apply', [
        'identity_ref' => $ref, 'display_name' => 'Capacity Worker ' . $i,
    ])['application_id'];
    $recruitment->interview($hr, $company, $source, $worker, $ref . '-interview', [
        'version' => 1, 'rating' => 4, 'notes' => 'Benchmark fixture.',
    ]);
    $offer = $recruitment->draft($hr, $company, $source, $worker, $ref . '-offer', [
        'version' => 2, 'effective_on' => MONTH_START, 'monthly_minor' => MONTHLY_MINOR,
    ])['offer_id'];
    $recruitment->decide($hr, $company, $source, $offer, 'sign', $ref . '-sign', ['version' => 1, 'decision_ref' => $ref . '-sign']);
    $recruitment->decide($hr, $company, $source, $offer, 'accept', $ref . '-accept', ['version' => 2, 'decision_ref' => $ref . '-accept']);
    $id = $employment->onboard($hr, $company, $source, $offer, $ref . '-onboard', [
        'version' => 3, 'employee_code' => $ref, 'contract_ref' => $ref . '-contract',
    ])['employment_id'];
    $employment->transfer($hr, $company, $id, $ref . '-transfer', [
        'assignment_version' => 1, 'position_id' => $target, 'effective_from' => CUTOVER,
    ]);
    $employmentIds[] = $id;
}

seedAttendance($db, $hr, $company);
foreach ($employmentIds as $id) {
    $certified = $certifications->certify($hr, $company, $id, 'cert-' . $id, [
        'starts_on' => MONTH_START, 'ends_before' => MONTH_END, 'expected_shifts' => SHIFTS_PER_WORKER,
        'decision_ref' => 'cert-' . $id, 'notes' => 'Confirmed synthetic worked shifts.',
    ]);
    check($certified['worked_minutes'] === 480 * SHIFTS_PER_WORKER && $certified['absent_minutes'] === 0, 'Unexpected attendance totals.');
}
$setupSeconds = elapsed($setupStarted);
$rule = $payroll->createRule($maker, $company, 'rule', [
    'rule_ref' => 'FIXED-30', 'title' => 'Fixed 30 day fixture', 'proration_basis' => 'fixed_30',
]);
$run = $payroll->createRun($maker, $company, 'run', ['period_start' => MONTH_START]);
$args = ['version' => $run['version'], 'rule_id' => $rule['rule_id'], 'attendance_watermark' => $attendance->watermark($maker, $company)];
$measurements = [];
$peakMemoryMb = [];
$gross = $workers * MONTHLY_MINOR;

for ($pass = 0; $pass <= 3; $pass++) {
    memory_reset_peak_usage();
    $started = hrtime(true);
    $trial = $payroll->trial($maker, $company, $run['run_id'], 'trial-' . $pass, $args);
    $measurements[] = elapsed($started);
    $peakMemoryMb[] = round(memory_get_peak_usage(true) / 1048576, 2);
    check($trial['eligible_count'] === $workers && $trial['blocked_count'] === 0, 'Trial counts were unexpected.');
    check($trial['gross_minor'] === $gross && $trial['payable_minor'] === $gross && $trial['total_cost_minor'] === $gross, 'Trial totals do not reconcile.');
    $args['version'] = $trial['version'];
}
$started = hrtime(true);
$review = ['version' => $trial['version'], 'decision_ref' => 'SUBMIT', 'notes' => 'Verify capacity fixture totals.'];
$submitted = $payroll->review($maker, $company, $run['run_id'], 'submit', 'submit', $review);
$closed = $payroll->review($reviewer, $company, $run['run_id'], 'close', 'close', [
    ...$review, 'version' => $submitted['version'], 'decision_ref' => 'CLOSE',
]);
check($payroll->verifyClosure($reviewer, $company, $run['run_id'])['valid'], 'Closure digest did not round trip.');
$closureSeconds = elapsed($started);
$cost = (new PayrollCosts($db))->forRun($reviewer, $company, $run['run_id']);
check(count($cost['departments']) === 2 && $cost['total_cost_minor'] === $gross, 'Department cost totals do not reconcile.');
foreach ($cost['departments'] as $department) {
    check($department['base_cost_minor'] === intdiv($gross, 2) && $department['segment_count'] === $workers, 'Department allocation failed.');
}

$measured = array_slice($measurements, 1);
$worst = max($measured);
$environment = $db->row('SELECT VERSION() AS mysql, @@innodb_buffer_pool_size AS innodb_buffer_pool_bytes, @@max_connections AS mysql_max_connections');
printf("%s\n", json_encode([
    'workers' => $workers, 'period_start' => MONTH_START, 'shifts_per_worker' => SHIFTS_PER_WORKER,
    'ready_payslips' => $workers, 'blocked' => $trial['blocked_count'], 'gross_minor' => $gross,
    'department_cost_minor' => intdiv($gross, 2), 'setup_seconds' => $setupSeconds, 'warmup_seconds' => $measurements[0],
    'measured_trial_seconds' => $measured, 'worst_trial_seconds' => $worst, 'slo_seconds' => 300,
    'submit_close_and_verify_seconds' => $closureSeconds, 'memory_limit' => ini_get('memory_limit'),
    'measured_php_peak_memory_mib' => array_slice($peakMemoryMb, 1), 'php' => PHP_VERSION, ...$environment,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
check($worst <= 300, 'Trial exceeded five minutes.');

function position(Organization $org, Access $hr, int $company, string $code, string $name, int $workers): int
{
    $department = $org->createDepartment($hr, $company, 'department-' . $code, [
        'code' => $code, 'name' => $name, 'effective_from' => MONTH_START,
    ])['department_id'];
    $position = $org->createPosition($hr, $company, 'position-' . $code, [
        'code' => $code, 'department_id' => $department, 'title' => $name, 'effective_from' => MONTH_START,
    ])['position_id'];
    $org->budget($hr, $company, $position, 'budget-' . $code, [
        'starts_on' => MONTH_START, 'ends_before' => MONTH_END, 'capacity' => $workers,
    ]);
    return $position;
}

function seedAttendance(Database $db, Access $hr, int $company): void
{
    $days = [];
    for ($day = new DateTimeImmutable(MONTH_START); count($days) < SHIFTS_PER_WORKER; $day = $day->modify('+1 day')) {
        if ((int) $day->format('N') <= 5) {
            $days[] = $day->format('Y-m-d');
        }
    }
    $scope = [$hr->groupId, $company];
    $db->transaction(static function () use ($db, $hr, $scope, $days): void {
        $insert = $db->run(
            "INSERT INTO shifts (group_id, company_id, employment_id, workday, starts_at, ends_at)
             SELECT w.group_id, w.company_id, w.id, d.workday, UNIX_TIMESTAMP(CONCAT(d.workday,' 01:00:00')),
                    UNIX_TIMESTAMP(CONCAT(d.workday,' 09:00:00'))
             FROM employments w CROSS JOIN JSON_TABLE(?, '$[*]' COLUMNS (workday DATE PATH '$')) AS d
             WHERE w.group_id=? AND w.company_id=?",
            [json_encode($days, JSON_THROW_ON_ERROR), ...$scope],
        );
        $shifts = $insert->rowCount();
        $db->run(
            "INSERT INTO attendance_versions
                (group_id, company_id, watermark, shift_id, version, state, worked_minutes, decision_ref, explanation, evidence, actor_id)
             SELECT s.group_id, s.company_id, ROW_NUMBER() OVER (ORDER BY s.id, v.revision), s.id,
                    v.revision, v.state, v.minutes, IF(v.revision=2, CONCAT('fixture-',s.id),NULL),
                    IF(v.revision=2,'Synthetic verified shift',NULL), JSON_OBJECT('fixture',1), ?
             FROM shifts s CROSS JOIN (
                 SELECT 1 AS revision, 'scheduled' AS state, 0 AS minutes UNION ALL SELECT 2, 'worked', 480
             ) v WHERE s.group_id=? AND s.company_id=?",
            [$hr->accountId, ...$scope],
        );
        $db->run('INSERT INTO attendance_watermarks (group_id, company_id, sequence) VALUES (?,?,?)', [...$scope, $shifts * 2]);
    });
}

function elapsed(int $start): float
{
    return round((hrtime(true) - $start) / 1e9, 4);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
