<?php

declare(strict_types=1);

use PeopleSuite\Access;
use PeopleSuite\Employment;
use PeopleSuite\InternalMoves;

$db->run("SET timestamp=UNIX_TIMESTAMP('2027-06-15 03:00:00')");
$moves=new InternalMoves($db,$operations);
$jobs=new Employment($db,$operations,$organization);
$destination=$organization->createDepartment($actor,$alpha,'target-dept',['code'=>'DEV','name'=>'人才发展部','effective_from'=>'2026-01-01'])['department_id'];
$targetPos=$organization->createPosition($actor,$alpha,'target-pos',['code'=>'DEVELOP','department_id'=>$destination,'title'=>'发展专员','effective_from'=>'2026-01-01'])['position_id'];
$budgets->assign($actor,$alpha,'dev-manager',['department_id'=>$destination,'manager_id'=>$unassigned->accountId,'granted'=>true]);
$budgets->limits($financeReviewer,$alpha,'dev-limit',array_replace($limit,['department_id'=>$destination]));
$proposal=$budgets->submit($unassigned,$alpha,'dev-proposal',array_replace($requested,['department_id'=>$destination,'position_id'=>$targetPos]));
$budgets->change($actor,$alpha,$proposal['request_id'],'review','dev-review',['budget_rev'=>1,'grade'=>'P4','reason'=>'核定岗位']);
$budgets->change($financeReviewer,$alpha,$proposal['request_id'],'approve','dev-approve',['budget_rev'=>2,'reason'=>'核定下半年']);
$vacancy=$market->post($unassigned,$alpha,'move-opening',['budget_id'=>$proposal['request_id'],'title'=>'发展专员','description'=>'员工发展',
    'closes_on'=>'2027-06-30','skills'=>[['skill'=>'OPS','required'=>false]],'panel'=>[$actor->accountId,$unassigned->accountId]]);
$organization->budget($actor,$alpha,$position,'second-source',['starts_on'=>'2026-05-01','ends_before'=>'2028-01-01','capacity'=>2]);
$req=$recruitment->request($actor,$alpha,$position,'add-candidate-role',['proposed_start'=>'2026-06-01','justification'=>'成本中心增员']);
$recruitment->open($actor,$alpha,$position,$req['requisition_id'],'add-open',1);
$candidate=$recruitment->apply($actor,$alpha,$position,$req['requisition_id'],'add-person',['identity_ref'=>'SYN-CAND-MOVE','display_name'=>'Second employee']);
$recruitment->interview($actor,$alpha,$position,$candidate['application_id'],'add-review',['version'=>1,'rating'=>4,'notes'=>'独立实操']);
$hire=$recruitment->draft($actor,$alpha,$position,$candidate['application_id'],'add-offer',['version'=>2,'effective_on'=>'2026-06-01','monthly_minor'=>900000]);
$recruitment->decide($actor,$alpha,$position,$hire['offer_id'],'sign','add-sign',['version'=>1,'decision_ref'=>'MOVE-ADD-SIGN']);
$recruitment->decide($actor,$alpha,$position,$hire['offer_id'],'accept','add-accept',['version'=>2,'decision_ref'=>'MOVE-ADD-ACCEPT']);
$extra=$jobs->onboard($actor,$alpha,$position,$hire['offer_id'],'add-onboard',['version'=>3,'employee_code'=>'MOVE-002','contract_ref'=>'MOVE-CONTRACT-002']);
$otherMember=new Access($db,$actor->groupId,$accounts->createAccount($actor->groupId,'internal-other@example.test','Second employee',DEMO_PASSWORD));
$organization->grantRole($actor,$alpha,$otherMember->accountId,'employee');
$people->linkAccount($actor,$alpha,$extra['employment_id'],'add-link',$otherMember->accountId);
$candidates=[];
foreach ([[$member,$employmentId],[$otherMember,$extra['employment_id']]] as [$employee,$employment]) {
    $app=$market->apply($employee,$alpha,$vacancy['opening_id'],'apply-move',['motivation'=>'申请发展岗位']);
    $market->score($actor,$alpha,$app['application_id'],'review-'.$app['application_id'],['score'=>91,'notes'=>'人事评审记录']);
    $market->score($unassigned,$alpha,$app['application_id'],'judge-'.$app['application_id'],['score'=>90,'notes'=>'主管独立评审']);
    $candidates[$app['application_id']]=[$employee,$employment];
}
$input=['application_id'=>array_key_first($candidates),'from_position'=>$position,'to_position'=>$targetPos,'effective_date'=>'2027-07-01','offer_rev'=>2];
reject(fn()=>$moves->reserve($actor,$alpha,'no-physical-budget',$input),'budget_not_found');
verify($db->row('SELECT COUNT(*) n FROM internal_moves WHERE opening_id=?',[$vacancy['opening_id']])['n']===0,'Missing physical headcount leaves no move awaiting signatures.');
$organization->budget($actor,$alpha,$targetPos,'target-capacity',['starts_on'=>'2027-07-01','ends_before'=>'2028-01-01','capacity'=>1]);
$organization->budget($actor,$alpha,$targetPos,'next-year-capacity',['starts_on'=>'2028-01-01','ends_before'=>'2029-01-01','capacity'=>1]);
$offerPreview=$moves->preview($actor,$alpha,$input['application_id'],'2027-07-01');
verify($offerPreview['contract_check']==='covered' && $offerPreview['target_available_fte']==1 && $offerPreview['missing_required']===[] &&
    count($moves->offers($actor,$alpha,0)['items'])>=2,'HR can preview the effective date, available headcount and current contract before reserving.');
$raceResults=raceRequests('move',$group,$alpha,$vacancy['opening_id'],array_keys($candidates));
$successful=array_filter($raceResults,fn($r)=>isset($r['move_id']));
verify(count($successful)===1 && count(array_filter($raceResults,fn($r)=>($r['error']??'')==='offer_or_opening_changed'))===1,'Two reviewed candidates compete but only one reserves the opening.');
$appId=array_key_first($successful);
[$moving,$employment]=$candidates[$appId];
$first=$successful[$appId]['move_id'];
verify($organization->headcount($actor,$alpha,$targetPos,'2028-01-15')['available']==0,'Future budget sees the pending appointment beyond its initial budget window.');
verify(in_array($first,array_column($moves->board($moving,$alpha,0)['items'],'id'),true) &&
    $moves->detail($moving,$alpha,$first)['actions']===['withdraw'],'Employee workbench shows their own move and available actions.');
$notMoving=array_values(array_filter($candidates,fn($c)=>$c[1]!==$employment))[0];
verify(!in_array($first,array_column($moves->board($notMoving[0],$alpha,0)['items'],'id'),true),'Other candidate cannot read the reserved move.');
$sourceSign=['owner_id'=>$budgetManagerId,'checklist'=>'归档项目排班，向接手人移交设备清单','notes'=>'原部门已确认交接'];
$moves->update($budgetManager,$alpha,$first,'sign-source','source-sign',['revision'=>1]+$sourceSign);
$moves->update($unassigned,$alpha,$first,'sign-target','target-sign',['revision'=>2,'notes'=>'新部门确认到岗日期']);
reject(fn()=>$jobs->applyReservedMove($actor,$alpha,$first),'move_not_due');
$db->run("SET timestamp=UNIX_TIMESTAMP('2027-06-30 15:59:00')");
require __DIR__.'/mobility-payroll.php';
$juneClosure=$sealMonth('2027-06-01','2027-07-01');
$junePay=$paySegments($juneClosure['run_id'],$employment);
verify($juneClosure['valid'] && count($junePay)===1 && $junePay[0]['department_id']===$warehouse &&
    $junePay[0]['cost_minor']===900000,'The June payslip charges the original department before withdrawal.');
$moves->update($moving,$alpha,$first,'withdraw-before-cutover','withdraw',['revision'=>3,'reason'=>'重新协商交接安排']);
verify($juneClosure===$mobilityPayroll->verifyClosure($financeReviewer,$alpha,$juneClosure['run_id']) && $junePay===$paySegments($juneClosure['run_id'],$employment),
    'Withdrawal preserves the signed payroll digest and the exact original cost segment.');
verify($organization->headcount($actor,$alpha,$targetPos,'2027-07-01')['occupied']==0 && $jobs->assignmentOn($actor,$alpha,$employment,'2027-07-01')['position_id']===$position,
    'Withdrawing a scheduled move restores target headcount and leaves source employment intact.');
verify($organization->headcount($actor,$alpha,$targetPos,'2028-01-15')['available']==1,'Withdrawal also releases the future budget period.');
$latest=$db->row('SELECT revision FROM internal_applications WHERE id=?',[$appId])['revision'];
$op=$moves->reserve($actor,$alpha,'reserve-again',array_replace($input,['application_id'=>$appId,'offer_rev'=>$latest]));
$moveId=$op['move_id'];
$moves->update($unassigned,$alpha,$moveId,'resign-target','target-sign',['revision'=>1,'notes'=>'确认重新录用']);
$moves->update($budgetManager,$alpha,$moveId,'resign-source','source-sign',['revision'=>2]+$sourceSign);
$db->run("SET timestamp=UNIX_TIMESTAMP('2027-06-30 15:59:59')");
reject(fn()=>$jobs->applyReservedMove($actor,$alpha,$moveId),'move_not_due');
$db->run("SET timestamp=UNIX_TIMESTAMP('2027-06-30 16:00:00')");
reject(fn()=>$moves->update($actor,$alpha,$moveId,'deny-release','release-blocked',['revision'=>3,'reason'=>'检查能否正常办理']),'move_ready_to_apply');
$effect=$moves->due($actor,$alpha,0)['items'][0];
verify($effect['move_id']===$moveId && $moves->due($actor,$alpha,0)['items']===[],'Due queue applies the move once at the target local midnight.');
verify($jobs->applyReservedMove($actor,$alpha,$moveId)==$effect && $db->row('SELECT COUNT(*) n FROM assignments WHERE employment_id=? AND effective_from=?',
    [$employment,'2027-07-01'])['n']===1,'Exactly one new assignment is created at Shanghai midnight, even after repeated execution.');
verify($jobs->assignmentOn($actor,$alpha,$employment,'2027-06-30')['department_id']===$warehouse &&
    $jobs->assignmentOn($actor,$alpha,$employment,'2027-07-01')['department_id']===$destination,'Adjacent months resolve to their respective cost centres.');
$completed=$moves->update($budgetManager,$alpha,$moveId,'handover-done','handover-complete',['revision'=>4]);
$historical=$moves->detail($budgetManager,$alpha,$moveId);
verify($historical['handover']['checklist']===$sourceSign['checklist'] && $historical['handover']['completed_at']!==null,'Source manager retains the completed handover list.');
$db->run("SET timestamp=UNIX_TIMESTAMP('2027-07-31 08:00:00')");
$julyClosure=$sealMonth('2027-07-01','2027-08-01');
$julyPay=$paySegments($julyClosure['run_id'],$employment);
verify($julyClosure['valid'] && count($julyPay)===1 && $julyPay[0]['department_id']===$destination && $julyPay[0]['assignment_id']===$effect['assignment_id'] &&
    $julyPay[0]['cost_minor']===900000 && $juneClosure===$mobilityPayroll->verifyClosure($financeReviewer,$alpha,$juneClosure['run_id']),
    'July payroll charges the actual destination assignment once while the June closure remains verifiable.');
require __DIR__.'/internal-cross-company.php';
$fractional=$organization->createPosition($actor,$alpha,'fractional',['department_id'=>$destination,'code'=>'HALF','title'=>'兼任','effective_from'=>'2027-01-01'])['position_id'];
$organization->budget($actor,$alpha,$fractional,'fractional-limit',['starts_on'=>'2027-01-01','ends_before'=>'2028-01-01','capacity'=>1]);
$db->transaction(fn()=>$organization->reserve($actor,$alpha,$fractional,'2027-06-01',fteBp:5000));
verify($organization->headcount($actor,$alpha,$fractional,'2027-07-01')['available']==0.5,'Half-time reservation leaves half an FTE.');
$db->transaction(fn()=>$organization->reserve($actor,$alpha,$fractional,'2027-07-01',fteBp:5000));
reject(fn()=>$db->transaction(fn()=>$organization->reserve($actor,$alpha,$fractional,'2027-07-02',fteBp:1)),'headcount_exhausted');
$reports=new \PeopleSuite\MovementReport($db);
$june=$reports->month($actor,$alpha,'2027-06-01');
$july=$reports->month($actor,$alpha,'2027-07-01');
$juneDept=array_column($june['departments'],null,'department_id')[$destination];
$julyDept=array_column($july['departments'],null,'department_id')[$destination];
verify($juneDept['internal_fills']===0 && $juneDept['internal_fill_rate']===null && $juneDept['vacant_days']===16 &&
    $julyDept['internal_fills']===1 && $julyDept['internal_fill_rate']==1 && $julyDept['vacant_days']===0,'Opening stays vacant June 15–30 and is filled once on July 1.');
if ($evidencePath=getenv('HCM_MOVE_EVIDENCE')) {
    $record=['group'=>$group,'company_id'=>$alpha,'source_manager'=>'dept-manager@example.test',
        'initial_request'=>array_replace($input,['application_id'=>$appId]),'initial_receipt'=>$successful[$appId],
        'withdrawn'=>$moves->detail($actor,$alpha,$first)['move'],'new_request'=>array_replace($input,['application_id'=>$appId,'offer_rev'=>$latest]),
        'new_receipt'=>$op,'effective'=>$effect,'handover'=>$historical['handover'],'june'=>[$juneClosure,$junePay],'july'=>[$julyClosure,$julyPay],
        'vacancy_june'=>$juneDept,'vacancy_july'=>$julyDept];
    if (file_put_contents($evidencePath,json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n")===false)
        throw new RuntimeException('Could not save the synthetic mobility acceptance record.');
}
$db->run('SET timestamp=0');
