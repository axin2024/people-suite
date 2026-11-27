<?php

declare(strict_types=1);

$organization->grantRole($actor,$beta,$financeId,'payroll_reviewer');
$organization->grantRole($actor,$beta,$unassigned->accountId,'manager');
$receivingDept=$organization->createDepartment($actor,$beta,'mobility-dept',['code'=>'SUPPORT','name'=>'客户支持','effective_from'=>'2026-01-01'])['department_id'];
$receivingPos=$organization->createPosition($actor,$beta,'mobility-position',['code'=>'SUPPORT-II','title'=>'支持专员','department_id'=>$receivingDept,'effective_from'=>'2026-01-01'])['position_id'];
$budgets->assign($actor,$beta,'receiver-grant',['department_id'=>$receivingDept,'manager_id'=>$unassigned->accountId,'granted'=>true]);
$budgets->limits($financeReviewer,$beta,'receiver-limit',array_replace($limit,['department_id'=>$receivingDept]));
$receivingRequest=$budgets->submit($unassigned,$beta,'receiver-proposal',array_replace($requested,['department_id'=>$receivingDept,'position_id'=>$receivingPos]));
$budgets->change($actor,$beta,$receivingRequest['request_id'],'review','receiver-review',['budget_rev'=>1,'grade'=>'P3','reason'=>'复核接收岗位']);
$budgets->change($financeReviewer,$beta,$receivingRequest['request_id'],'approve','receiver-approve',['budget_rev'=>2,'reason'=>'本法人预算确认']);
$organization->budget($actor,$beta,$receivingPos,'receiver-physical',['starts_on'=>'2027-07-01','ends_before'=>'2028-01-01','capacity'=>1]);
$crossOpening=$market->post($unassigned,$beta,'receiver-opening',['budget_id'=>$receivingRequest['request_id'],'title'=>'支持专员','description'=>'跨法人客户支持',
    'closes_on'=>'2027-07-31','skills'=>[['skill'=>'OPS','required'=>false]],'panel'=>[$actor->accountId,$unassigned->accountId]]);
[$crossEmployee,$crossEmployment]=$notMoving;
$crossApp=$market->apply($crossEmployee,$alpha,$crossOpening['opening_id'],'cross-apply',['motivation'=>'申请集团客户支持岗位']);
$market->score($actor,$beta,$crossApp['application_id'],'cross-score-hr',['score'=>89,'notes'=>'客户场景评审']);
$market->score($unassigned,$beta,$crossApp['application_id'],'cross-score-manager',['score'=>92,'notes'=>'独立面谈通过']);
$crossPreview=$moves->preview($actor,$beta,$crossApp['application_id'],'2027-08-01');
verify($crossPreview['contract_check']==='receiving_confirmation_required','HR sees the receiving contract requirement before committing.');
$crossMove=$moves->reserve($actor,$beta,'cross-reserve',array_replace($input,['application_id'=>$crossApp['application_id'],
    'to_position'=>$receivingPos,'effective_date'=>'2027-08-01']))['move_id'];
$moves->update($budgetManager,$alpha,$crossMove,'cross-source','source-sign',['revision'=>1]+$sourceSign);
reject(fn()=>$moves->update($unassigned,$beta,$crossMove,'cross-no-contract','target-sign',['revision'=>2,'notes'=>'等待合同']),'receiving_contract_required');
$moves->update($actor,$beta,$crossMove,'cross-contract','contract',['revision'=>2,'reference'=>'RECV-2027-8','monthly_minor'=>870000]);
verify(!isset($moves->detail($unassigned,$beta,$crossMove)['contract']['monthly_minor']) &&
    $moves->detail($actor,$beta,$crossMove)['contract']['monthly_minor']===870000,'Receiving manager may sign the move while only HR reads the negotiated salary.');
$moves->update($unassigned,$beta,$crossMove,'cross-target','target-sign',['revision'=>3,'notes'=>'确认生效日与目标合同']);
$db->run("SET timestamp=UNIX_TIMESTAMP('2027-08-02 03:00:00')");
$crossResult=$jobs->applyReservedMove($actor,$beta,$crossMove);
$crossOld=$db->row('SELECT employee_id,ends_before FROM employments WHERE id=?',[$crossEmployment]);
$crossNew=$db->row('SELECT employee_id,starts_on FROM employments WHERE id=?',[$crossResult['employment_id']]);
$crossContract=$db->row('SELECT reference,monthly_minor,effective_from FROM contract_revisions WHERE company_id=? AND employment_id=?',[$beta,$crossResult['employment_id']]);
verify($crossOld['employee_id']===$crossNew['employee_id'] && $crossOld['ends_before']==='2027-08-01' && $crossOld['ends_before']===$crossNew['starts_on'] &&
    $crossContract['reference']==='RECV-2027-8' && $crossContract['monthly_minor']===870000 && $crossContract['effective_from']===$crossNew['starts_on'],
    'Cross-company cutover preserves the person, closes the source employment and activates the confirmed receiving contract on the same date.');
