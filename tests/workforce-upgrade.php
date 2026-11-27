<?php

declare(strict_types=1);

use PeopleSuite\Accounts;
use PeopleSuite\BusinessError;
use PeopleSuite\Database;
use PeopleSuite\Operations;
use PeopleSuite\WorkforcePlans;
use PeopleSuite\WorkforceRollout;

require __DIR__.'/../src/bootstrap.php';
$db=Database::connect();
if (!str_starts_with($db->row('SELECT DATABASE() name')['name'],'hcm_demo_')) throw new RuntimeException('An isolated hcm_demo_* database is required.');
$password=rtrim((string)fgets(STDIN),"\r\n");
$hr=(new Accounts($db))->authenticate('north-demo','hr@example.test',$password);
$company=$db->row('SELECT MIN(id) id FROM companies WHERE group_id=?',[$hr->groupId])['id'];
$pilot=new WorkforceRollout($db);
$plans=new WorkforcePlans($db,new Operations($db));
$dept=$db->row('SELECT MIN(id) id FROM departments WHERE group_id=? AND company_id=?',[$hr->groupId,$company])['id'];
$input=['year'=>2027,'code'=>'UPGRADE','name'=>'升级演练成本','previous_revision'=>0,'entries'=>[
    ['reference'=>'SYNTHETIC','department_id'=>$dept,'effective_from'=>'2027-01-01','ends_before'=>'2028-01-01','fte_bp'=>10000,'monthly_cost_minor'=>900000]]];
function denied(callable $call,string $reason): void {
    try { $call(); } catch (BusinessError $error) { if ($error->reason===$reason) return; throw $error; }
    throw new RuntimeException("Expected $reason");
}
function must(bool $result,string $label): void { if (!$result) throw new RuntimeException($label); }
$command=$argv[1]??'';
if ($command==='preflight') {
    $report=$pilot->preflight($hr,$company);
    must($report['counts']['payroll_closures']>0 && $report['counts']['headcount_slots']>0 && $report['conflicts']===[],'Old demo needs a closed payroll and actual occupied slots.');
} elseif ($command==='checkpoint') {
    denied(fn()=>$plans->publish($hr,$company,'first-work',$input),'workforce_intake_closed');
    denied(fn()=>$pilot->run($hr,$company,'activate'),'rollout_baseline_incomplete');
    $report=$pilot->run($hr,$company,'backfill',1);
    must($report['copied']===1 && $report['remaining_baseline']>0,'First checkpoint must leave work for another process.');
} elseif ($command==='resume') {
    $checkpoint=$pilot->run($hr,$company,'status');
    must($checkpoint['last_revision_id']>0,'Expected a committed checkpoint.');
    do { $batch=$pilot->run($hr,$company,'backfill',2); } while ($batch['remaining_baseline']>0);
    must($pilot->run($hr,$company,'backfill',2)['copied']===0,'Replay should not duplicate imported position history.');
    $pilot->run($hr,$company,'activate');
    $receipt=$plans->publish($hr,$company,'first-work',$input);
    $pilot->run($hr,$company,'drain');
    denied(fn()=>$plans->publish($hr,$company,'new-during-drain',array_replace($input,['previous_revision'=>1])),'workforce_intake_closed');
    must($plans->publish($hr,$company,'first-work',$input)==$receipt,'Accepted write must remain replayable during drain.');
    $report=['checkpoint'=>$checkpoint,'batch'=>$batch,'receipt'=>$receipt,'state'=>$pilot->run($hr,$company,'status')];
} elseif ($command==='readback') {
    $receipt=$plans->publish($hr,$company,'first-work',$input);
    $report=['preflight'=>$pilot->preflight($hr,$company),'state'=>$pilot->run($hr,$company,'status'),'plan'=>$plans->detail($hr,$company,$receipt['scenario_id'])];
    must(count($report['plan']['months'])===12 && $report['state']['state']==='draining','Expected the preserved annual scenario and drain state.');
} else throw new RuntimeException('Unknown stage.');
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
