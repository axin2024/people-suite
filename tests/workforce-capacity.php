<?php

declare(strict_types=1);

use PeopleSuite\Access;
use PeopleSuite\Accounts;
use PeopleSuite\BusinessError;
use PeopleSuite\Database;
use PeopleSuite\Operations;
use PeopleSuite\Organization;
use PeopleSuite\WorkforceBudgets;
use PeopleSuite\WorkforcePlans;

require __DIR__.'/../src/bootstrap.php';
$db = Database::connect();
if (!str_starts_with($db->row('SELECT DATABASE() name')['name'],'hcm_test_')) {
    throw new RuntimeException('Use an isolated hcm_test_* database.');
}
$accounts = new Accounts($db);
$operations = new Operations($db);
$org = new Organization($db,$operations);
$plans = new WorkforcePlans($db,$operations);
$budget = new WorkforceBudgets($db,$operations);
$group = $accounts->createGroup('forecast-'.bin2hex(random_bytes(5)),'编制容量演练','hr@example.test','人力规划','Example-capacity-1200');
$hr = new Access($db,$group['group_id'],$group['account_id']);
$company = $org->createCompany($hr,'CAP','编制容量演练','Asia/Shanghai','CNY')['company_id'];
$org->grantRole($hr,$company,$hr->accountId,'hr');
$manager = new Access($db,$hr->groupId,$accounts->createAccount($hr->groupId,'manager@example.test','部门经理','Example-capacity-1200'));
$org->grantRole($hr,$company,$manager->accountId,'manager');
$csv = fopen('php://temp','w+');
fputcsv($csv,['reference','department_id','effective_from','ends_before','fte_bp','monthly_cost_minor'],',','"','');
$firstDepartment = $db->transaction(function () use ($db,$hr,$company,$csv) {
    for ($department=1;$department<=500;$department++) {
        $id=$db->insert('INSERT INTO departments (group_id,company_id,code,name,effective_from) VALUES (?,?,?,?,?)',
            [$hr->groupId,$company,'D'.$department,'部门 '.$department,'2027-01-01']);
        $first ??= $id;
        for ($worker=1;$worker<=40;$worker++) {
            fputcsv($csv,["D$department-W$worker",$id,'2027-01-01','2028-01-01',$worker<=20?10000:5000,$worker<=20?100000:50000],',','"','');
        }
    }
    return $first;
});
$budget->assign($hr,$company,'first-department',['department_id'=>$firstDepartment,'manager_id'=>$manager->accountId,'granted'=>true]);
$samples=[];
for ($i=0;$i<=3;$i++) {
    $start=hrtime(true);
    rewind($csv);
    $rows=WorkforcePlans::readCsv($csv);
    $result=$plans->publish($hr,$company,'sample-'.$i,['name'=>'年度人力预测','year'=>2027,'code'=>'CAP','previous_revision'=>$i,'entries'=>$rows]);
    $elapsed=round((hrtime(true)-$start)/1e6,2);
    $months=$plans->detail($hr,$company,$result['scenario_id'])['months'];
    $digest=hash('sha256',json_encode($months,JSON_THROW_ON_ERROR));
    $reference ??= $digest;
    if (count($months)!==6000 || array_sum(array_column($months,'monthly_fte_bp'))!==1800000000
        || array_sum(array_column($months,'cost_minor'))!==18000000000 || $digest!==$reference || $elapsed>90000) {
        throw new RuntimeException('Forecast totals, digest or 90-second capacity requirement failed.');
    }
    $samples[]=['phase'=>$i===0?'warmup':'measured','elapsed_ms'=>$elapsed,'successful'=>1,'rejected'=>0,'timeouts'=>0,
        'department_months'=>count($months),'cost_minor'=>array_sum(array_column($months,'cost_minor')),'monthly_rows_sha256'=>$digest];
}
fclose($csv);
$allowed=$budget->board($manager,$company,0);
if (array_column($allowed['departments'],'id')!==[$firstDepartment]) {
    throw new RuntimeException('Department manager saw departments outside their grant.');
}
try {
    $plans->detail($manager,$company,$result['scenario_id']);
    throw new RuntimeException('Department manager accessed company salary-cost forecast.');
} catch (BusinessError $error) {
    if ($error->status!==404) throw $error;
}
echo json_encode(['at_utc'=>gmdate('c'),'platform'=>php_uname('s').' '.php_uname('m'),'cpu'=>getenv('PROCESSOR_IDENTIFIER')?:php_uname('m'),
    'php'=>PHP_VERSION,'mysql'=>$db->row('SELECT VERSION() version,@@innodb_buffer_pool_size buffer_pool_bytes'),
    'fixture'=>['departments'=>500,'workers'=>20000,'months'=>12,'annual_currency'=>'CNY','input_format'=>'CSV'],
    'manager_visible_departments'=>count($allowed['departments']),'manager_company_cost_access'=>'denied',
    'samples'=>$samples],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
