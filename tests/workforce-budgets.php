<?php

declare(strict_types=1);

use PeopleSuite\WorkforceBudgets;

$budgets = new WorkforceBudgets($db,$operations);
$budgetManagerId = $accounts->createAccount($actor->groupId,'dept-manager@example.test','Warehouse manager',DEMO_PASSWORD);
$financeId = $accounts->createAccount($actor->groupId,'finance@example.test','Budget reviewer',DEMO_PASSWORD);
$organization->grantRole($actor,$alpha,$budgetManagerId,'manager');
$organization->grantRole($actor,$alpha,$financeId,'payroll_reviewer');
$budgetManager = $accounts->authenticate($group,'dept-manager@example.test',DEMO_PASSWORD);
$financeReviewer = $accounts->authenticate($group,'finance@example.test',DEMO_PASSWORD);
$requested = ['department_id'=>$warehouse,'position_id'=>$position,'start_month'=>'2027-07-01','months'=>6,
    'monthly_minor'=>180000,'grade'=>'P3','fte_bp'=>10000];
reject(fn()=>$budgets->submit($budgetManager,$alpha,'no-department',$requested),'department_not_found');
$budgets->assign($actor,$alpha,'delegate',['department_id'=>$warehouse,'manager_id'=>$budgetManagerId,'granted'=>true]);
$limit = ['department_id'=>$warehouse,'start_month'=>'2027-01-01','months'=>12,'capacity_bp'=>10000,'cost_cap_minor'=>180000,'previous_revision'=>0];
$budgets->limits($financeReviewer,$alpha,'annual-limit',$limit);
$requestIds = [];
foreach ([$position,$second] as $target) {
    $request = $budgets->submit($budgetManager,$alpha,'propose-'.$target,array_replace($requested,['position_id'=>$target]));
    $budgets->change($actor,$alpha,$request['request_id'],'review','review-'.$target,['grade'=>'P4','budget_rev'=>1,'reason'=>'人力核定职级']);
    $requestIds[] = $request['request_id'];
}
$decisions = raceRequests('budget',$group,$alpha,$warehouse,$requestIds);
$byState = array_column($decisions,null,'state');
verify(isset($byState['approved'],$byState['adjustment']), 'Two independent MySQL sessions compete for the same monthly headcount.');
$winner = $byState['approved'];
$loser = $byState['adjustment'];
verify($loser['shortfall_month']==='2027-07-01' && $loser['reserved_minor']===0,'Losing application stays actionable with the first insufficient month.');
$readback = $budgets->detail($budgetManager,$alpha,$winner['request_id']);
verify(array_column($readback['reservations'],'period_start')===['2027-07-01','2027-08-01','2027-09-01','2027-10-01','2027-11-01','2027-12-01']
    && $winner['reserved_minor']===1080000,'The six month appointment only reserves July through December.');
verify($budgets->change($financeReviewer,$alpha,$winner['request_id'],'approve','race-'.$winner['request_id'],
    ['budget_rev'=>2,'reason'=>'Validate the last available headcount'])['request_id']===$winner['request_id'],'Settlement replay returns the original decision.');
$revised = $budgets->change($budgetManager,$alpha,$loser['request_id'],'revise','revise-budget',
    array_replace($requested,['budget_rev'=>3,'reason'=>'提前至上半年','start_month'=>'2027-01-01','monthly_minor'=>150000]));
$budgets->change($actor,$alpha,$loser['request_id'],'review','new-review',['grade'=>'P3','budget_rev'=>4,'reason'=>'复核调整版']);
$approved = $budgets->change($financeReviewer,$alpha,$loser['request_id'],'approve','new-approval',['budget_rev'=>5,'reason'=>'批准上半年额度']);
$changes = $budgets->detail($actor,$alpha,$loser['request_id'])['history'];
verify($revised['budget_rev']===4 && $changes[0]['snapshot']['monthly_minor']===180000 && $changes[3]['snapshot']['monthly_minor']===150000
    && $approved['reserved_minor']===900000,'Revised costs and prior decisions remain in the request history.');
reject(fn()=>$budgets->limits($financeReviewer,$alpha,'reduce-used',array_replace($limit,['previous_revision'=>1,'capacity_bp'=>0])),'budget_limit_changed');
$budgets->assign($actor,$alpha,'revoke',['department_id'=>$warehouse,'manager_id'=>$budgetManagerId,'granted'=>false]);
reject(fn()=>$budgets->detail($budgetManager,$alpha,$winner['request_id']),'department_not_found');
verify($budgets->board($budgetManager,$alpha,0)['requests']===[],'Revoking a department removes budget rows from its manager.');
