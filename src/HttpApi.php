<?php

declare(strict_types=1);

namespace PeopleSuite;

use PDOException;

final class HttpApi
{
    private const SESSION_LIFETIME = 28800;

    private readonly Accounts $accounts;
    private readonly Organization $organization;
    private readonly Recruitment $recruitment;
    private readonly Employment $employment;
    private readonly WorkforceAccess $workforce;
    private readonly Attendance $attendance;
    private readonly AttendanceImports $attendanceImports;
    private readonly AttendanceCertifications $attendanceCertifications;
    private readonly Leave $leave;
    private readonly Payroll $payroll;
    private readonly PayrollAdjustments $payrollAdjustments;

    public function __construct(private readonly Database $db)
    {
        $this->accounts = new Accounts($db);
        $operations = new Operations($db);
        $this->organization = new Organization($db, $operations);
        $this->recruitment = new Recruitment($db, $operations, $this->organization);
        $this->employment = new Employment($db, $operations, $this->organization);
        $this->workforce = new WorkforceAccess($db, $operations);
        $this->attendance = new Attendance($db, $operations, $this->workforce);
        $this->attendanceImports = new AttendanceImports($db, $operations, $this->attendance);
        $this->attendanceCertifications = new AttendanceCertifications($db, $operations, $this->workforce, $this->attendance);
        $this->leave = new Leave($db, $operations, $this->workforce);
        $this->payrollAdjustments = new PayrollAdjustments($db, $operations);
        $this->payroll = new Payroll(
            $db, $operations, $this->attendance, $this->attendanceCertifications, new PayrollCalculator(), $this->payrollAdjustments,
        );
    }

    public function handle(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/healthz') {
            $ready = (new Schema($this->db))->ready();
            return [$ready ? 200 : 503, ['ready' => $ready]];
        }

        if (!str_starts_with($path, '/api/')) {
            throw new BusinessError(404, 'route_not_found');
        }

        $this->startSession();
        if ($method === 'GET' && $path === '/api/session') {
            $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
            return [200, [
                'csrf_token' => $_SESSION['csrf'],
                'authenticated' => $this->hasSession(),
            ]];
        }

        $input = [];
        if ($method === 'POST') {
            if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))
                || !isset($_SESSION['csrf'])) {
                throw new BusinessError(403, 'invalid_csrf_token');
            }
            $input = $this->body();
        }

        if ($method === 'POST' && $path === '/api/login') {
            $actor = $this->accounts->authenticate(
                Input::text($input['group'] ?? null, 'group', 48),
                Input::text($input['email'] ?? null, 'email', 254),
                is_string($input['password'] ?? null) ? $input['password'] : '',
            );
            session_regenerate_id(true);
            $_SESSION = [
                'group_id' => $actor->groupId, 'account_id' => $actor->accountId,
                'expires_at' => time() + self::SESSION_LIFETIME, 'csrf' => bin2hex(random_bytes(32)),
            ];
            return [200, [
                'group_id' => $actor->groupId, 'account_id' => $actor->accountId, 'csrf_token' => $_SESSION['csrf'],
            ]];
        }

        if (!$this->hasSession()) {
            throw new BusinessError(401, 'login_required');
        }
        $actor = $this->accounts->session((int) $_SESSION['group_id'], (int) $_SESSION['account_id']);
        if ($method === 'POST' && $path === '/api/logout') {
            session_regenerate_id(true);
            $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
            return [200, ['csrf_token' => $_SESSION['csrf']]];
        }
        session_write_close();

        try {
            return $this->route($method, $path, $actor, $input);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new BusinessError(409, 'duplicate_business_key');
            }
            if (in_array($error->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw new BusinessError(409, 'write_retry_required');
            }
            throw $error;
        }
    }

    private function route(string $method, string $path, Access $actor, array $input): array
    {
        if ($method === 'GET' && $path === '/api/companies') {
            return [200, ['companies' => $this->db->rows(
                'SELECT c.id, c.code, c.name, c.timezone, c.currency
                 FROM companies c WHERE c.group_id=? AND (
                    EXISTS (SELECT 1 FROM company_roles r WHERE r.group_id=c.group_id AND r.company_id=c.id AND r.account_id=?)
                    OR EXISTS (SELECT 1 FROM group_administrators a WHERE a.group_id=c.group_id AND a.account_id=?)
                 ) ORDER BY c.code, c.id',
                [$actor->groupId, $actor->accountId, $actor->accountId],
            )]];
        }
        if ($method === 'POST' && $path === '/api/companies') {
            return [201, $this->organization->createCompany(
                $actor,
                Input::code($input['code'] ?? null, 'code'),
                Input::text($input['name'] ?? null, 'name', 160),
                Input::text($input['timezone'] ?? null, 'timezone', 64),
                Input::text($input['currency'] ?? null, 'currency', 3),
            )];
        }
        if ($method === 'POST' && $path === '/api/accounts') {
            $actor->administrator();
            return [201, ['account_id' => $this->accounts->createAccount(
                $actor->groupId,
                Input::text($input['email'] ?? null, 'email', 254),
                Input::text($input['display_name'] ?? null, 'display_name', 160),
                is_string($input['password'] ?? null) ? $input['password'] : '',
            )]];
        }
        if (!preg_match('#^/api/companies/([0-9]{1,19})/(.+)$#D', $path, $match)) {
            throw new BusinessError(404, 'route_not_found');
        }
        $company = Input::integer($match[1], 'company_id');
        $resource = $match[2];
        if ($method === 'GET' && $resource === 'departments') {
            return [200, ['departments' => $this->organization->departments(
                $actor, $company, Input::date($_GET['on'] ?? null, 'on'),
            )]];
        }
        if ($method === 'POST' && $resource === 'roles') {
            return [200, $this->organization->grantRole(
                $actor, $company, Input::integer($input['account_id'] ?? null, 'account_id'),
                Input::text($input['role'] ?? null, 'role', 24),
            )];
        }
        if ($method === 'POST' && in_array($resource, ['departments', 'positions'], true)) {
            $key = $this->requestKey();
            $result = $resource === 'departments'
                ? $this->organization->createDepartment($actor, $company, $key, $input)
                : $this->organization->createPosition($actor, $company, $key, $input);
            return [201, $result];
        }
        if ($method === 'GET' && $resource === 'legal-transfers') {
            $after = Input::integer($_GET['after'] ?? 0, 'after', 0);
            return [200, ['incoming' => $this->employment->incomingTransfers($actor, $company, $after)]];
        }
        if ($method === 'POST' && preg_match('#^legal-transfers/([0-9]{1,19})/accept$#D', $resource, $match)) {
            return [200, $this->employment->acceptLegalTransfer(
                $actor, $company, Input::integer($match[1], 'transfer_id'), $this->requestKey(), $input,
            )];
        }
        if (str_starts_with($resource, 'attendance/')) {
            return $this->attendanceRoute($method, $resource, $actor, $company, $input);
        }
        if (str_starts_with($resource, 'payroll/')) {
            return $this->payrollRoute($method, $resource, $actor, $company, $input);
        }
        if (preg_match('#^employments/([0-9]{1,19})/(account|manager|shifts|attendance-certifications|leave(?:/.*)?)$#D', $resource, $match)) {
            return $this->workforceRoute($method, $match[2], $actor, $company, Input::integer($match[1], 'employment_id'), $input);
        }
        if (preg_match('#^employments/([0-9]{1,19})(?:/(contracts|transfers|legal-transfers))?$#D', $resource, $match)) {
            $id = Input::integer($match[1], 'employment_id');
            return match ($method . '/' . ($match[2] ?? '')) {
                'GET/' => [200, array_key_exists('on', $_GET)
                    ? $this->employment->assignmentOn($actor, $company, $id, Input::date($_GET['on'], 'on'))
                    : $this->employment->history($actor, $company, $id)],
                'POST/contracts' => [200, $this->employment->renew($actor, $company, $id, $this->requestKey(), $input)],
                'POST/transfers' => [200, $this->employment->transfer($actor, $company, $id, $this->requestKey(), $input)],
                'POST/legal-transfers' => [200, $this->employment->requestLegalTransfer(
                    $actor, $company, $id, $this->requestKey(), $input,
                )],
                default => throw new BusinessError(404, 'route_not_found'),
            };
        }
        if (!preg_match('#^positions/([0-9]{1,19})/(.+)$#D', $resource, $match)) {
            throw new BusinessError(404, 'route_not_found');
        }
        return $this->position($method, $match[2], $actor, $company, Input::integer($match[1], 'position_id'), $input);
    }

    private function payrollRoute(string $method, string $resource, Access $actor, int $company, array $input): array
    {
        if ($method === 'POST' && $resource === 'payroll/rules') {
            return [201, $this->payroll->createRule($actor, $company, $this->requestKey(), $input)];
        }
        if ($method === 'POST' && $resource === 'payroll/runs') {
            return [201, $this->payroll->createRun($actor, $company, $this->requestKey(), $input)];
        }
        if ($method === 'GET' && preg_match('#^payroll/payslips/([0-9]{1,19})$#D', $resource, $match)) {
            return [200, $this->payroll->payslip($actor, $company, Input::integer($match[1], 'payslip_id'))];
        }
        if ($method === 'GET' && $resource === 'payroll/my-payslips') {
            return [200, ['payslips' => $this->payroll->myPayslips($actor, $company, Input::integer($_GET['after'] ?? 0, 'after', 0))]];
        }
        if ($method === 'GET' && preg_match('#^payroll/my-payslips/([0-9]{1,19})$#D', $resource, $match)) {
            return [200, $this->payroll->myPayslip($actor, $company, Input::integer($match[1], 'payslip_id'))];
        }
        if (!preg_match('#^payroll/runs/([0-9]{1,19})(?:/(trial|submit|return|close|publish|verify-closure|adjustments))?$#D', $resource, $match)) {
            throw new BusinessError(404, 'route_not_found');
        }
        $id = Input::integer($match[1], 'payroll_run_id');
        return match ($method . '/' . ($match[2] ?? '')) {
            'POST/trial' => [200, $this->payroll->trial($actor, $company, $id, $this->requestKey(), $input)],
            'POST/adjustments' => [201, $this->payrollAdjustments->record($actor, $company, $id, $this->requestKey(), $input)],
            'POST/submit', 'POST/return', 'POST/close', 'POST/publish' => [200, $this->payroll->review(
                $actor, $company, $id, $match[2], $this->requestKey(), $input,
            )],
            'GET/verify-closure' => [200, $this->payroll->verifyClosure($actor, $company, $id)],
            'GET/' => [200, $this->payroll->detail($actor, $company, $id, Input::integer($_GET['after'] ?? 0, 'after', 0))],
            default => throw new BusinessError(404, 'route_not_found'),
        };
    }

    private function attendanceRoute(string $method, string $resource, Access $actor, int $company, array $input): array
    {
        if ($method === 'GET' && $resource === 'attendance/watermark') {
            return [200, ['watermark' => $this->attendance->watermark($actor, $company)]];
        }
        if ($method === 'POST' && $resource === 'attendance/imports') {
            return [201, $this->attendanceImports->submit($actor, $company, $this->requestKey(), $input)];
        }
        if (preg_match('#^attendance/imports/([0-9]{1,19})(/run)?$#D', $resource, $match)) {
            $id = Input::integer($match[1], 'import_id');
            return match ($method . ($match[2] ?? '')) {
                'GET' => [200, $this->attendanceImports->status($actor, $company, $id)],
                'POST/run' => [200, $this->attendanceImports->run($actor, $company, $id, $input)],
                default => throw new BusinessError(404, 'route_not_found'),
            };
        }
        if (!preg_match('#^attendance/shifts/([0-9]{1,19})(?:/(punches|resolution))?$#D', $resource, $match)) {
            throw new BusinessError(404, 'route_not_found');
        }
        $shift = Input::integer($match[1], 'shift_id');
        return match ($method . '/' . ($match[2] ?? '')) {
            'GET/' => [200, $this->attendance->detail($actor, $company, $shift)],
            'POST/punches' => [200, $this->attendance->punch($actor, $company, $shift, $this->requestKey(), $input)],
            'POST/resolution' => [200, $this->attendance->reconcile($actor, $company, $shift, $this->requestKey(), $input)],
            default => throw new BusinessError(404, 'route_not_found'),
        };
    }

    private function workforceRoute(
        string $method,
        string $resource,
        Access $actor,
        int $company,
        int $employment,
        array $input,
    ): array {
        if ($method === 'GET' && $resource === 'shifts') {
            return [200, ['shifts' => $this->attendance->roster(
                $actor, $company, $employment, Input::date($_GET['workday'] ?? null, 'workday'),
            )]];
        }
        if ($method === 'GET' && $resource === 'leave') {
            return [200, $this->leave->summary($actor, $company, $employment, Input::date($_GET['workday'] ?? null, 'workday'))];
        }
        if (preg_match('#^leave/([0-9]{1,19})(?:/(approve|deny|cancel))?$#D', $resource, $match)) {
            $leaveId = Input::integer($match[1], 'leave_id');
            if ($method === 'GET' && !isset($match[2])) {
                return [200, $this->leave->detail($actor, $company, $employment, $leaveId)];
            }
            if ($method === 'POST' && isset($match[2])) {
                return [200, $this->leave->decide($actor, $company, $employment, $leaveId, $match[2], $this->requestKey(), $input)];
            }
        }
        if ($method !== 'POST') {
            throw new BusinessError(404, 'route_not_found');
        }
        $key = $this->requestKey();
        return match ($resource) {
            'account' => [200, $this->workforce->linkAccount(
                $actor, $company, $employment, $key, Input::integer($input['account_id'] ?? null, 'account_id'),
            )],
            'manager' => [200, $this->workforce->assignManager($actor, $company, $employment, $key, $input)],
            'shifts' => [201, $this->attendance->schedule($actor, $company, $employment, $key, $input)],
            'attendance-certifications' => [201, $this->attendanceCertifications->certify($actor, $company, $employment, $key, $input)],
            'leave' => [201, $this->leave->request($actor, $company, $employment, $key, $input)],
            'leave/grants' => [200, $this->leave->grant($actor, $company, $employment, $key, $input)],
            default => throw new BusinessError(404, 'route_not_found'),
        };
    }

    private function position(string $method, string $resource, Access $actor, int $company, int $position, array $input): array
    {
        if ($method === 'GET' && $resource === 'revisions') {
            return [200, ['revisions' => $this->organization->history($actor, $company, $position)]];
        }
        if ($method === 'GET' && preg_match('#^applications/([0-9]{1,19})$#D', $resource, $match)) {
            return [200, $this->recruitment->dossier($actor, $company, $position, Input::integer($match[1], 'application_id'))];
        }
        if ($method !== 'POST') {
            throw new BusinessError(404, 'route_not_found');
        }
        if ($resource === 'recruiters') {
            return [200, $this->organization->grantRecruitingPosition(
                $actor, $company, $position, Input::integer($input['account_id'] ?? null, 'account_id'),
            )];
        }

        $key = $this->requestKey();
        $result = match ($resource) {
            'revisions' => $this->organization->revisePosition($actor, $company, $position, $key, $input),
            'budgets' => $this->organization->budget($actor, $company, $position, $key, $input),
            'requisitions' => $this->recruitment->request($actor, $company, $position, $key, $input),
            default => $this->workflow($resource, $actor, $company, $position, $key, $input),
        };
        return [200, $result];
    }

    private function workflow(string $resource, Access $actor, int $company, int $position, string $key, array $input): array
    {
        if (!preg_match('#^(requisitions|applications|offers)/([0-9]{1,19})/([a-z]+)$#D', $resource, $match)) {
            throw new BusinessError(404, 'route_not_found');
        }
        $id = Input::integer($match[2], 'id');
        return match ($match[1] . '/' . $match[3]) {
            'requisitions/open' => $this->recruitment->open(
                $actor, $company, $position, $id, $key, Input::integer($input['version'] ?? null, 'version'),
            ),
            'requisitions/apply' => $this->recruitment->apply($actor, $company, $position, $id, $key, $input),
            'applications/interview' => $this->recruitment->interview($actor, $company, $position, $id, $key, $input),
            'applications/offer' => $this->recruitment->draft($actor, $company, $position, $id, $key, $input),
            'offers/sign', 'offers/accept', 'offers/revoke' => $this->recruitment->decide(
                $actor, $company, $position, $id, $match[3], $key, $input,
            ),
            'offers/onboard' => $this->employment->onboard($actor, $company, $position, $id, $key, $input),
            default => throw new BusinessError(404, 'route_not_found'),
        };
    }

    private function startSession(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('people_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => getenv('HCM_COOKIE_SECURE') !== '0',
        ]);
        if (!session_start()) {
            throw new BusinessError(503, 'session_unavailable');
        }
    }

    private function hasSession(): bool
    {
        return isset($_SESSION['group_id'], $_SESSION['account_id']) && ($_SESSION['expires_at'] ?? 0) > time();
    }

    private function body(): array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($type !== 'application/json') {
            throw new BusinessError(415, 'json_required');
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) {
            throw new BusinessError(413, 'request_too_large');
        }
        $value = json_decode($raw, true, 32);
        if (!is_array($value) || !str_starts_with(ltrim($raw), '{')) {
            throw new BusinessError(400, 'invalid_json_object');
        }
        return $value;
    }

    private function requestKey(): string
    {
        return Input::code($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'request_key');
    }
}
