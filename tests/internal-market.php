<?php

declare(strict_types=1);

use PeopleSuite\Access;
use PeopleSuite\InternalMarket;
use PeopleSuite\TalentSkills;
use PeopleSuite\WorkforceAccess;

$people = new WorkforceAccess($db,$operations);
$skills = new TalentSkills($db,$operations);
$market = new InternalMarket($db,$operations);
$member = new Access($db,$actor->groupId,$accounts->createAccount($actor->groupId,'internal-employee@example.test','Internal applicant',DEMO_PASSWORD));
$organization->grantRole($actor,$alpha,$member->accountId,'employee');
$people->linkAccount($actor,$alpha,$employmentId,'internal-link',$member->accountId);
$budgets->assign($actor,$alpha,'delegate-again',['department_id'=>$warehouse,'manager_id'=>$budgetManagerId,'granted'=>true]);
$today = $skills->today($member,$alpha);
$tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$targetBudget = $db->row("SELECT id FROM workforce_budget_requests WHERE company_id=? AND position_id=? AND state='approved'",[$alpha,$second])['id'];
$opening = $market->post($budgetManager,$alpha,'publish-post',['budget_id'=>$targetBudget,'title'=>'人才发展协调员','description'=>'协调跨部门培训',
    'closes_on'=>$tomorrow,'skills'=>[['skill'=>'OPS','required'=>true],['skill'=>'TRAIN','required'=>true],['skill'=>'SQL','required'=>false]],
    'panel'=>[$actor->accountId,$budgetManagerId]]);
$openingId=$opening['opening_id'];
$cert=$skills->add($member,$alpha,'operations-cert',['skill'=>'OPS','title'=>'运营认证','evidence_ref'=>'CERT-OPS-42','reviewer_id'=>$actor->accountId]);
verify($cert['visibility']==='private' && count($skills->inbox($actor,$alpha,'review',0)['items'])===1,'Only chosen reviewer receives the private evidence.');
$skills->change($actor,$alpha,$cert['evidence_id'],'review','certify',['revision'=>1,'decision'=>'verified','verified_until'=>$today,'notes'=>'已核对证号']);
verify($market->preview($member,$alpha,$openingId)['missing_required']===['OPS','TRAIN'],'Verified evidence stays private until employee shares it.');
$skills->change($member,$alpha,$cert['evidence_id'],'visibility','publish-proof',['revision'=>2,'visibility'=>'company']);
$preview=$market->preview($member,$alpha,$openingId);
verify($preview['missing_required']===['TRAIN'] && $preview['match_reasons'][0]['evidence']['evidence_id']===$cert['evidence_id'],'Exactly one hard skill missing; matching includes evidence.');
verify(count($skills->search($actor,$alpha,'OPS',0)['items'])===1 && $skills->search($actor,$beta,'OPS',0)['items']===[],'Company-only evidence does not cross legal entities.');
verify($market->match(['id'=>$openingId,'group_id'=>$actor->groupId,'company_id'=>$alpha],$alpha,$skills->employee($member,$alpha)['employee_id'],$tomorrow)['missing_required']===['OPS','TRAIN'],'Expired certificate is excluded the day after its last valid date.');
verify($market->candidates($budgetManager,$alpha,$openingId,0)['items']===[],'Opening owner sees no candidates before voluntary application.');
$applied=$market->apply($member,$alpha,$openingId,'apply',['motivation'=>'希望参与内部培训岗位']);
verify($market->mine($member,$alpha,0)['items'][0]['id']===$applied['application_id'],'Employee can track their application.');
$unassigned=new Access($db,$actor->groupId,$accounts->createAccount($actor->groupId,'outside-judge@example.test','Other manager',DEMO_PASSWORD));
$organization->grantRole($actor,$alpha,$unassigned->accountId,'manager');
reject(fn()=>$market->candidates($unassigned,$alpha,$openingId,0),'opening_not_found');
verify(count($market->candidates($budgetManager,$alpha,$openingId,0)['items'])===1,'Only the receiving panel can open submitted materials.');
$skills->change($member,$alpha,$cert['evidence_id'],'visibility','hide-proof',['revision'=>3,'visibility'=>'private']);
verify($skills->search($actor,$alpha,'OPS',0)['items']===[] && $market->candidates($budgetManager,$alpha,$openingId,0)['items'][0]['missing_required']===['OPS','TRAIN'],
    'Revocation removes searchable personal details and already submitted candidate evidence immediately.');
$market->score($actor,$alpha,$applied['application_id'],'first-score',['score'=>92,'notes'=>'考察运营经验']);
verify($market->candidates($budgetManager,$alpha,$openingId,0)['items'][0]['scores']===[],'Other reviewer cannot see the first assessment before scoring.');
$confirmed=$market->score($budgetManager,$alpha,$applied['application_id'],'last-score',['score'=>88,'notes'=>'完成独立评审']);
verify($confirmed['state']==='reviewed' && count($market->candidates($actor,$alpha,$openingId,0)['items'][0]['scores'])===2,'Completing both independent scores produces offer revision two.');
$market->withdraw($member,$alpha,$applied['application_id'],'withdraw-application',['revision'=>2,'reason'=>'员工决定结束本次竞聘']);
verify($market->candidates($budgetManager,$alpha,$openingId,0)['items']===[] && $market->mine($member,$alpha,0)['items'][0]['state']==='withdrawn',
    'Withdrawing an application removes candidate details from the panel and preserves the employee record.');
