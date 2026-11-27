<?php

declare(strict_types=1);

use PeopleSuite\Attendance;
use PeopleSuite\AttendanceCertifications;
use PeopleSuite\Payroll;
use PeopleSuite\PayrollAdjustments;
use PeopleSuite\PayrollCalculator;

$attendance=new Attendance($db,$operations,$people);
$certifications=new AttendanceCertifications($db,$operations,$people,$attendance);
$mobilityPayroll=new Payroll($db,$operations,$attendance,$certifications,new PayrollCalculator(),new PayrollAdjustments($db,$operations));
$organization->grantRole($actor,$alpha,$actor->accountId,'payroll_maker');
$ruleId=$mobilityPayroll->createRule($actor,$alpha,'move-pay-rule',['rule_ref'=>'MOBILITY','title'=>'按自然月折算','proration_basis'=>'actual_days'])['rule_id'];
$sealMonth=function (string $start,string $end) use ($mobilityPayroll,$certifications,$actor,$financeReviewer,$alpha,$candidates,$ruleId,$attendance) {
    foreach ($candidates as [, $employmentId]) {
        $certifications->certify($actor,$alpha,$employmentId,"cert-$start-$employmentId",['starts_on'=>$start,'ends_before'=>$end,'expected_shifts'=>0,
            'decision_ref'=>"C-$start-$employmentId",'notes'=>'合成用例已核对月份内零排班']);
    }
    $run=$mobilityPayroll->createRun($actor,$alpha,"run-$start",['period_start'=>$start]);
    $id=$run['run_id'];
    $state=$mobilityPayroll->trial($actor,$alpha,$id,"trial-$start",['version'=>1,'rule_id'=>$ruleId,'attendance_watermark'=>$attendance->watermark($actor,$alpha)]);
    verify($state['eligible_count']===2 && $state['blocked_count']===0,'Both employees have payable periods.');
    foreach (['submit','close','publish'] as $action) {
        $state=$mobilityPayroll->review($action==='submit'?$actor:$financeReviewer,$alpha,$id,$action,"$action-$start",['version'=>$state['version'],
            'decision_ref'=>strtoupper($action).'-'.$start,'notes'=>'核对工资明细与部门归属']);
    }
    return $mobilityPayroll->verifyClosure($financeReviewer,$alpha,$id);
};
$paySegments=function (int $run,int $worker) use ($db,$alpha) {
    return $db->rows("SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(s.facts,'$.assignment_id')) AS UNSIGNED) assignment_id,s.department_id,s.cost_minor,s.payslip_id,s.line_no FROM payroll_closures c
        JOIN payslips p ON p.group_id=c.group_id AND p.company_id=c.company_id AND p.trial_id=c.trial_id
        JOIN payslip_segments s ON s.group_id=p.group_id AND s.company_id=p.company_id AND s.payslip_id=p.id
        WHERE c.company_id=? AND c.run_id=? AND p.employment_id=? ORDER BY s.payslip_id,s.line_no",[$alpha,$run,$worker]);
};
