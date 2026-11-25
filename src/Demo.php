<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Demo
{
    private const GROUP = 'north-demo';
    private const COMPANY = 'DEMO';
    private const EMAILS = [
        'hr' => 'hr@example.test',
        'manager' => 'manager@example.test',
        'employee' => 'employee@example.test',
        'payroll_maker' => 'maker@example.test',
        'payroll_reviewer' => 'reviewer@example.test',
    ];
    private const NAMES = [
        'hr' => '演示人事',
        'manager' => '演示主管',
        'employee' => '陈样例',
        'payroll_maker' => '演示薪资制单',
        'payroll_reviewer' => '演示薪资复核',
    ];
    private const APRIL = '2026-04-01';
    private const MAY = '2026-05-01';
    private const JUNE = '2026-06-01';
    private const ADJUSTMENT = 'APRIL-RATE-CORRECTION';
    private const SHIFTS = [
        'APR-10' => ['2026-04-10', '2026-04-10T09:00:00+08:00', '2026-04-10T17:00:00+08:00'],
        'APR-30' => ['2026-04-30', '2026-04-30T22:00:00+08:00', '2026-05-01T06:00:00+08:00'],
        'MAY-12' => ['2026-05-12', '2026-05-12T09:00:00+08:00', '2026-05-12T17:00:00+08:00'],
    ];

    private readonly Accounts $accounts;
    private readonly Organization $org;
    private readonly Recruitment $recruitment;
    private readonly Employment $employment;
    private readonly WorkforceAccess $workforce;
    private readonly Attendance $attendance;
    private readonly AttendanceImports $imports;
    private readonly AttendanceCertifications $certifications;
    private readonly Payroll $payroll;
    private readonly PayrollAdjustments $adjustments;
    private readonly PayrollCosts $costs;

    public function __construct(private readonly Database $db)
    {
        $ops = new Operations($db);
        $this->accounts = new Accounts($db);
        $this->org = new Organization($db, $ops);
        $this->recruitment = new Recruitment($db, $ops, $this->org);
        $this->employment = new Employment($db, $ops, $this->org);
        $this->workforce = new WorkforceAccess($db, $ops);
        $this->attendance = new Attendance($db, $ops, $this->workforce);
        $this->imports = new AttendanceImports($db, $ops, $this->attendance);
        $this->certifications = new AttendanceCertifications($db, $ops, $this->workforce, $this->attendance);
        $this->adjustments = new PayrollAdjustments($db, $ops);
        $this->payroll = new Payroll(
            $db, $ops, $this->attendance, $this->certifications, new PayrollCalculator(), $this->adjustments,
        );
        $this->costs = new PayrollCosts($db);
    }

    public function run(string $command, string $password): array
    {
        $schema = $this->db->row('SELECT DATABASE() AS name')['name'];
        if (!is_string($schema) || !preg_match('/^hcm_demo_[a-z0-9_]+$/D', $schema)) {
            throw new BusinessError(422, 'demo_requires_dedicated_database');
        }
        $lock = 'people-suite-demo:' . substr(hash('sha256', $schema), 0, 24);
        if ((int) $this->db->row('SELECT GET_LOCK(?, 10) AS acquired', [$lock])['acquired'] !== 1) {
            throw new BusinessError(409, 'demo_already_running');
        }

        try {
            if ($this->db->row('SELECT 1 FROM hcm_groups WHERE code<>? LIMIT 1', [self::GROUP]) !== null) {
                throw new BusinessError(409, 'demo_requires_empty_or_demo_database');
            }
            if ($command === 'demo-check') {
                $actors = $this->actors($password);
                return $this->verify($actors, $this->company($actors['hr']));
            }
            [$actors, $setup] = $this->seed($password);
            if ($command === 'demo-seed') {
                $apr = $setup['april_run']['run_id'];
                $may = $setup['may_run']['run_id'];
                unset($setup['april_run'], $setup['may_run']);
                return [...$setup, 'april_run_id' => $apr, 'may_run_id' => $may];
            }
            return $this->finish($actors, $setup);
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function seed(string $password): array
    {
        if ($this->db->row('SELECT 1 FROM hcm_groups WHERE code=?', [self::GROUP]) === null) {
            $this->accounts->createGroup(self::GROUP, '北岸演示集团', self::EMAILS['hr'], self::NAMES['hr'], $password);
        }
        $hr = $this->accounts->authenticate(self::GROUP, self::EMAILS['hr'], $password);
        $hr->administrator();
        $company = $this->company($hr, create: true);
        foreach (self::EMAILS as $role => $email) {
            if ($this->db->row('SELECT 1 FROM accounts WHERE group_id=? AND email=?', [$hr->groupId, $email]) === null) {
                $this->accounts->createAccount($hr->groupId, $email, self::NAMES[$role], $password);
            }
        }
        $actors = $this->actors($password);
        foreach ($actors as $role => $actor) {
            $this->org->grantRole($hr, $company, $actor->accountId, $role);
        }

        $source = $this->position($hr, $company, 'OPS', '运营组');
        $target = $this->position($hr, $company, 'FIN', '财务组');
        $employment = $this->onboard($hr, $company, $source);
        $this->workforce->linkAccount($hr, $company, $employment, 'demo-link', $actors['employee']->accountId);
        $this->workforce->assignManager($hr, $company, $employment, 'demo-manager', [
            'account_id' => $actors['manager']->accountId, 'effective_from' => self::APRIL,
        ]);
        $this->employment->transfer($hr, $company, $employment, 'demo-transfer', [
            'assignment_version' => 1, 'position_id' => $target, 'effective_from' => '2026-04-16',
        ]);

        $roster = $punches = $shiftIds = [];
        foreach (self::SHIFTS as $ref => [$day, $start, $end]) {
            $roster[] = ['row_ref' => $ref, 'employment_id' => $employment, 'workday' => $day, 'starts_at' => $start, 'ends_at' => $end];
        }
        $rosterResult = $this->import($hr, $company, 'roster', $roster);
        foreach ($rosterResult['rows'] as $row) {
            $ref = $row['row_ref'];
            $shift = $shiftIds[$ref] = $row['outcome']['shift_id'];
            [, $start, $end] = self::SHIFTS[$ref];
            foreach (['in' => $start, 'out' => $end] as $direction => $instant) {
                $event = $ref . '-' . $direction;
                $punches[] = ['row_ref' => $event, 'shift_id' => $shift, 'source' => 'DEMO-GATE', 'event_ref' => $event,
                    'direction' => $direction, 'punched_at' => $instant];
            }
        }
        $punchResult = $this->import($hr, $company, 'punches', $punches);

        $maker = $actors['payroll_maker'];
        $aprilRule = $this->rule($maker, $company, 'DEMO-30', '30 天演示计薪', 'fixed_30');
        $mayRule = $this->rule($maker, $company, 'DEMO-ACTUAL', '自然日演示计薪', 'actual_days');
        $aprilRun = $this->payroll->createRun($maker, $company, 'demo-april', ['period_start' => self::APRIL]);
        $mayRun = $this->payroll->createRun($maker, $company, 'demo-may', ['period_start' => self::MAY]);

        return [$actors, ['group' => self::GROUP, 'company_id' => $company, 'employment_id' => $employment,
            'accounts' => self::EMAILS, 'roster_batch' => $rosterResult['import']['id'], 'punch_batch' => $punchResult['import']['id'],
            'shifts' => $shiftIds, 'april_rule' => $aprilRule, 'may_rule' => $mayRule, 'april_run' => $aprilRun, 'may_run' => $mayRun]];
    }

    private function actors(string $password): array
    {
        return array_map(fn (string $email): Access => $this->accounts->authenticate(self::GROUP, $email, $password), self::EMAILS);
    }

    private function company(Access $hr, bool $create = false): int
    {
        $record = $this->db->row('SELECT id FROM companies WHERE group_id=? AND code=?', [$hr->groupId, self::COMPANY]);
        if ($record !== null) {
            return (int) $record['id'];
        }
        if (!$create) {
            throw new BusinessError(409, 'demo_company_missing');
        }
        return $this->org->createCompany($hr, self::COMPANY, '北岸运营（演示）', 'Asia/Shanghai', 'CNY')['company_id'];
    }

    private function position(Access $hr, int $company, string $code, string $name): int
    {
        $department = $this->org->createDepartment($hr, $company, 'demo-department-' . $code, [
            'code' => $code, 'name' => $name, 'effective_from' => self::APRIL,
        ])['department_id'];
        $position = $this->org->createPosition($hr, $company, 'demo-position-' . $code, [
            'code' => $code . '-01', 'title' => $name . '专员', 'department_id' => $department, 'effective_from' => self::APRIL,
        ])['position_id'];
        $this->org->budget($hr, $company, $position, 'demo-budget-' . $code, [
            'starts_on' => self::APRIL, 'ends_before' => self::JUNE, 'capacity' => 1,
        ]);
        return $position;
    }

    private function onboard(Access $hr, int $company, int $position): int
    {
        $request = $this->recruitment->request($hr, $company, $position, 'demo-recruit', [
            'proposed_start' => self::APRIL, 'justification' => '建立演示任职档案',
        ])['requisition_id'];
        $this->recruitment->open($hr, $company, $position, $request, 'demo-open', 1);
        $application = $this->recruitment->apply($hr, $company, $position, $request, 'demo-application', [
            'identity_ref' => 'DEMO-CANDIDATE', 'display_name' => self::NAMES['employee'],
        ])['application_id'];
        $this->recruitment->interview($hr, $company, $position, $application, 'demo-interview', [
            'version' => 1, 'rating' => 4, 'notes' => '已核对演示岗位所需技能',
        ]);
        $offer = $this->recruitment->draft($hr, $company, $position, $application, 'demo-offer', [
            'version' => 2, 'effective_on' => self::APRIL, 'monthly_minor' => 900000,
        ])['offer_id'];
        foreach (['sign' => 1, 'accept' => 2] as $action => $version) {
            $this->recruitment->decide($hr, $company, $position, $offer, $action, 'demo-offer-' . $action, [
                'version' => $version, 'decision_ref' => 'DEMO-OFFER-' . strtoupper($action),
            ]);
        }
        return $this->employment->onboard($hr, $company, $position, $offer, 'demo-onboard', [
            'version' => 3, 'employee_code' => 'DEMO-001', 'contract_ref' => 'DEMO-CONTRACT-001',
        ])['employment_id'];
    }

    private function import(Access $hr, int $company, string $kind, array $rows): array
    {
        $batch = $this->imports->submit($hr, $company, 'demo-import-' . $kind, ['kind' => $kind, 'rows' => $rows]);
        $result = $this->imports->run($hr, $company, $batch['import_id'], []);
        $this->expect($result['counts']['succeeded'] === count($rows), 'demo_import_incomplete');
        return $result;
    }

    private function rule(Access $maker, int $company, string $reference, string $title, string $basis): int
    {
        return $this->payroll->createRule($maker, $company, 'demo-rule-' . $reference, [
            'rule_ref' => $reference, 'title' => $title, 'proration_basis' => $basis,
        ])['rule_id'];
    }

    private function finish(array $actors, array $setup): array
    {
        $hr = $actors['hr'];
        $maker = $actors['payroll_maker'];
        $reviewer = $actors['payroll_reviewer'];
        $company = $setup['company_id'];
        $employment = $setup['employment_id'];
        $april = $setup['april_run']['run_id'];
        $may = $setup['may_run']['run_id'];
        $blocked = $this->payroll->trial($maker, $company, $april, 'demo-april-blocked', [
            'version' => $setup['april_run']['version'], 'rule_id' => $setup['april_rule'], 'attendance_watermark' => 0,
        ]);
        $this->expect($blocked['blocked_count'] === 1, 'demo_missing_attendance_must_block');
        $current = $this->payroll->detail($reviewer, $company, $april, 0);
        if ($current['trial']['id'] === $blocked['trial_id']) {
            try {
                $this->payroll->review($maker, $company, $april, 'submit', 'demo-blocked-submit', [
                    'version' => $blocked['version'], 'decision_ref' => 'DEMO-CHECK-BLOCKED', 'notes' => '验证缺少考勤确认时不能送审',
                ]);
                throw new BusinessError(409, 'demo_missing_attendance_must_block');
            } catch (BusinessError $error) {
                if ($error->reason !== 'payroll_trial_blocked') {
                    throw $error;
                }
            }
        }
        foreach ($setup['shifts'] as $ref => $shift) {
            $this->attendance->reconcile($actors['manager'], $company, $shift, 'demo-worked-' . $ref, [
                'version' => 3, 'state' => 'worked', 'decision_ref' => 'DEMO-WORKED-' . $ref, 'explanation' => '已核对两条合成设备回执',
            ]);
        }
        $aprilCert = $this->certify($hr, $company, $employment, self::APRIL, self::MAY, 2);
        $mayCert = $this->certify($hr, $company, $employment, self::MAY, self::JUNE, 1);
        $aprilTrial = $this->payroll->trial($maker, $company, $april, 'demo-april-trial', [
            'version' => $blocked['version'], 'rule_id' => $setup['april_rule'], 'attendance_watermark' => $aprilCert['watermark'],
        ]);
        $this->publish($maker, $reviewer, $company, $april, $aprilTrial['version'], 'APRIL');
        $slips = $this->payroll->myPayslips($actors['employee'], $company, 0);
        $original = array_column($slips, 'id', 'period_start')[self::APRIL];
        $decision = [
            'original_payslip_id' => $original, 'original_line_no' => 1, 'decision_ref' => self::ADJUSTMENT,
            'corrected_monthly_minor' => 960000, 'notes' => '补录 4 月上半月生效的演示调薪决定',
        ];
        $adjustment = $this->adjustments->record($maker, $company, $may, 'demo-may-adjustment', $decision);
        $replayed = $this->adjustments->record($maker, $company, $may, 'demo-may-adjustment-replay', $decision);
        $this->expect($adjustment === $replayed && $adjustment['recalculated_gross_minor'] === 930000, 'demo_adjustment_replay_mismatch');
        $mayTrial = $this->payroll->trial($maker, $company, $may, 'demo-may-trial', [
            'version' => $setup['may_run']['version'], 'rule_id' => $setup['may_rule'], 'attendance_watermark' => $mayCert['watermark'],
        ]);
        $this->publish($maker, $reviewer, $company, $may, $mayTrial['version'], 'MAY');
        return $this->verify($actors, $company);
    }

    private function certify(Access $hr, int $company, int $employment, string $start, string $end, int $shifts): array
    {
        return $this->certifications->certify($hr, $company, $employment, 'demo-cert-' . $start, [
            'starts_on' => $start, 'ends_before' => $end, 'expected_shifts' => $shifts,
            'decision_ref' => 'DEMO-CERT-' . $start, 'notes' => '逐班核对演示设备回执',
        ]);
    }

    private function publish(Access $maker, Access $reviewer, int $company, int $id, int $version, string $label): void
    {
        foreach (['submit' => $maker, 'close' => $reviewer, 'publish' => $reviewer] as $action => $actor) {
            $key = 'DEMO-' . $label . '-' . strtoupper($action);
            $result = $this->payroll->review($actor, $company, $id, $action, $key, [
                'version' => $version, 'decision_ref' => $key, 'notes' => '核对演示账期工资和成本',
            ]);
            $version = $result['version'];
        }
    }

    private function verify(array $actors, int $company): array
    {
        $reviewer = $actors['payroll_reviewer'];
        $employee = $actors['employee'];
        $runs = array_column($this->payroll->runs($reviewer, $company, 0), null, 'period_start');
        $slips = array_column($this->payroll->myPayslips($employee, $company, 0), null, 'period_start');
        $this->expect(isset($runs[self::APRIL], $runs[self::MAY], $slips[self::APRIL], $slips[self::MAY]), 'demo_requires_two_published_periods');
        $april = $runs[self::APRIL]['id'];
        $may = $runs[self::MAY]['id'];
        $original = $slips[self::APRIL]['id'];
        $supplemented = $slips[self::MAY]['id'];
        $aprilCost = $this->costs->forRun($reviewer, $company, $april);
        $mayCost = $this->costs->forRun($reviewer, $company, $may);
        $aprilSplit = array_column($aprilCost['departments'], 'base_cost_minor', 'code');
        $maySplit = array_column($mayCost['departments'], null, 'code');
        $aprilProof = $this->payroll->verifyClosure($reviewer, $company, $april);
        $mayProof = $this->payroll->verifyClosure($reviewer, $company, $may);
        $rows = $this->adjustments->forRun($reviewer, $company, $may, 0);
        $originalReceipt = $this->payroll->myPayslip($employee, $company, $original);
        $receipt = $this->payroll->myPayslip($employee, $company, $supplemented);
        $this->expect($aprilProof['valid'] && $mayProof['valid'], 'demo_closure_digest_mismatch');
        $this->expect(
            $slips[self::APRIL]['payable_minor'] === 900000 && $aprilCost['total_cost_minor'] === 900000
                && $aprilSplit === ['OPS' => 450000, 'FIN' => 450000] && $originalReceipt['adjustments'] === []
                && array_column($originalReceipt['segments'], 'starts_on') === [self::APRIL, '2026-04-16']
                && array_column($originalReceipt['segments'], 'ends_before') === ['2026-04-16', self::MAY],
            'demo_april_cost_mismatch',
        );
        $this->expect(
            count($rows) === 1 && count($receipt['adjustments']) === 1 && $rows[0]['decision_ref'] === self::ADJUSTMENT
                && $rows[0]['original_payslip_id'] === $original && $receipt['adjustments'][0]['payable_delta_minor'] === 30000
                && $rows[0]['original_line_no'] === 1 && $rows[0]['original_minor'] === 450000 && $rows[0]['recalculated_minor'] === 480000
                && $rows[0]['id'] === $receipt['adjustments'][0]['id']
                && $maySplit['OPS']['adjustment_minor'] === 30000 && $maySplit['FIN']['base_cost_minor'] === 900000
                && $slips[self::MAY]['payable_minor'] === 930000 && $mayCost['total_cost_minor'] === 930000,
            'demo_may_adjustment_mismatch',
        );
        return ['group' => self::GROUP, 'currency' => 'CNY',
            'april' => ['run' => $april, 'payslip' => $original, 'payable_minor' => 900000, 'cost_split_minor' => $aprilSplit,
                'digest' => $aprilProof['digest']],
            'may' => ['run' => $may, 'payslip' => $supplemented, 'payable_minor' => 930000,
                'adjustment_minor' => 30000, 'adjustment_rows' => 1, 'department' => 'OPS',
                'decision_ref' => self::ADJUSTMENT, 'digest' => $mayProof['digest']]];
    }

    private function expect(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new BusinessError(409, $reason);
        }
    }
}
