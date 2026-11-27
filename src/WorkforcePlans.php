<?php

declare(strict_types=1);

namespace PeopleSuite;

final class WorkforcePlans
{
    public function __construct(private readonly Database $db, private readonly Operations $operations)
    {
    }

    public static function readCsv($stream): array
    {
        $fields = ['reference','department_id','effective_from','ends_before','fte_bp','monthly_cost_minor'];
        $header = fgetcsv($stream,4096,',','"','');
        if ($header) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        if ($header !== $fields) {
            throw new BusinessError(422,'invalid_plan_columns',['expected'=>$fields]);
        }
        $entries = [];
        while (($row = fgetcsv($stream,4096,',','"','')) !== false) {
            if (count($row)!==count($fields) || count($entries)>=50000) {
                throw new BusinessError(422,'invalid_plan_row',['line'=>count($entries)+2]);
            }
            $entries[] = array_combine($fields,$row);
        }
        return $entries;
    }

    public function register(Access $actor,int $company,int $after): array
    {
        $actor->company($company,'hr','payroll_reviewer');
        return ['scenarios'=>$this->db->rows('SELECT id,name,scenario_code,plan_year,revision,currency,created_at FROM workforce_scenarios
            WHERE group_id=? AND company_id=? AND id>? ORDER BY id LIMIT 100',[$actor->groupId,$company,$after])];
    }

    public function publish(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company, 'hr');
        $year = Input::integer($input['year'] ?? null, 'year', 2000, 2199);
        $code = Input::code($input['code'] ?? null, 'code');
        $name = Input::text($input['name'] ?? null, 'name', 160);
        $prior = Input::integer($input['previous_revision'] ?? null, 'previous_revision', 0);
        $entries = $this->entries($input['entries'] ?? null, $year);
        return $this->operations->once($actor, $company, 'workforce.publish', $key, $input,
            function () use ($actor, $company, $year, $code, $name, $prior, $entries): array {
                $scope = [$actor->groupId, $company];
                $organization = $this->db->row('SELECT currency FROM companies WHERE group_id=? AND id=? FOR UPDATE', $scope);
                $last = $this->db->row('SELECT MAX(revision) AS rev FROM workforce_scenarios
                    WHERE group_id=? AND company_id=? AND plan_year=? AND scenario_code=?', [...$scope,$year,$code]);
                if ((int)$last['rev'] !== $prior) {
                    throw new BusinessError(409, 'scenario_revision_changed');
                }
                $departments = array_column($this->db->rows('SELECT id,effective_from FROM departments
                    WHERE group_id=? AND company_id=?', $scope),'effective_from','id');
                foreach ($entries as $entry) {
                    if (!isset($departments[$entry['department_id']]) || $departments[$entry['department_id']] > $entry['effective_from']) {
                        throw new BusinessError(422, 'department_unavailable', ['reference'=>$entry['reference']]);
                    }
                }
                $id = $this->db->insert('INSERT INTO workforce_scenarios
                    (group_id,company_id,plan_year,scenario_code,name,revision,currency,created_by) VALUES (?,?,?,?,?,?,?,?)',
                    [...$scope,$year,$code,$name,$prior+1,$organization['currency'],$actor->accountId]);
                foreach (array_chunk($entries,500) as $chunk) {
                    $values = [];
                    foreach ($chunk as $entry) {
                        array_push($values,...[...$scope,$id,...array_values($entry)]);
                    }
                    $sql = 'INSERT INTO workforce_plan_entries
                        (group_id,company_id,scenario_id,reference_code,department_id,effective_from,ends_before,fte_bp,monthly_cost_minor) VALUES ';
                    $this->db->run($sql.implode(',',array_fill(0,count($chunk),'(?,?,?,?,?,?,?,?,?)')),$values);
                }
                $this->db->run('INSERT INTO workforce_plan_months
                    (group_id,company_id,scenario_id,department_id,period_start,monthly_fte_bp,cost_minor)
                    WITH RECURSIVE months AS (
                        SELECT CAST(? AS DATE) first_day, 1 n
                        UNION ALL SELECT DATE_ADD(first_day, INTERVAL 1 MONTH), n+1 FROM months WHERE n<12
                    )
                    SELECT e.group_id,e.company_id,e.scenario_id,e.department_id,m.first_day,
                        SUM(IF(e.ends_before>LAST_DAY(m.first_day), e.fte_bp, 0)),
                        SUM(ROUND(e.monthly_cost_minor * DATEDIFF(LEAST(e.ends_before,DATE_ADD(m.first_day,INTERVAL 1 MONTH)),
                            GREATEST(e.effective_from,m.first_day)) / DAY(LAST_DAY(m.first_day)),0))
                    FROM workforce_plan_entries e JOIN months m ON e.effective_from<=LAST_DAY(m.first_day) AND e.ends_before>m.first_day
                    WHERE e.group_id=? AND e.company_id=? AND e.scenario_id=?
                    GROUP BY e.group_id,e.company_id,e.scenario_id,e.department_id,m.first_day', ["$year-01-01",...$scope,$id]);
                $result = ['scenario_id'=>$id,'revision'=>$prior+1,'entries'=>count($entries),'currency'=>$organization['currency']];
                $this->operations->audit($actor,$company,'workforce_scenario',$id,'published',$result);
                return $result;
            });
    }

    private function entries(mixed $rows, int $year): array
    {
        if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows)>50000) {
            throw new BusinessError(422, 'invalid_field', ['field'=>'entries']);
        }
        $entries = [];
        foreach ($rows as $row) {
            $ref = Input::code($row['reference'] ?? null, 'reference');
            $from = Input::date($row['effective_from'] ?? null, 'effective_from');
            $until = Input::date($row['ends_before'] ?? null, 'ends_before');
            if ($until <= $from || $until <= "$year-01-01" || $from >= ($year+1).'-01-01' || isset($entries[$ref])) {
                throw new BusinessError(422, 'invalid_plan_interval_or_reference', ['reference'=>$ref]);
            }
            $entries[$ref] = ['reference'=>$ref,'department_id'=>Input::integer($row['department_id'] ?? null,'department_id'),
                'effective_from'=>$from,'ends_before'=>$until,'fte_bp'=>Input::integer($row['fte_bp'] ?? null,'fte_bp',1,10000),
                'monthly_cost_minor'=>Input::integer($row['monthly_cost_minor'] ?? null,'monthly_cost_minor',0,1000000000000)];
        }
        return array_values($entries);
    }

    public function comparison(Access $actor,int $company,int $id): array
    {
        return $this->db->transaction(function () use ($actor,$company,$id) {
            $plan=$this->detail($actor,$company,$id);
            $year=$plan['plan_year'];
            $scope=[$actor->groupId,$company];
            $months='WITH RECURSIVE months AS (SELECT CAST(? AS DATE) month_start,1 n UNION ALL
                SELECT DATE_ADD(month_start,INTERVAL 1 MONTH),n+1 FROM months WHERE n<12) ';
            $filled=$this->db->rows($months.'SELECT a.department_id,m.month_start period_start,SUM(COALESCE(f.fte_bp,10000)) occupied_bp
                FROM assignments a JOIN months m ON a.effective_from<=LAST_DAY(m.month_start) AND (a.effective_until IS NULL OR a.effective_until>LAST_DAY(m.month_start))
                LEFT JOIN headcount_slot_workloads f ON f.slot_id=a.slot_id WHERE a.group_id=? AND a.company_id=? GROUP BY a.department_id,m.month_start',
                ["$year-01-01",...$scope]);
            $capacity=$this->db->rows($months.'SELECT r.department_id,m.month_start period_start,SUM(b.capacity)*10000 capacity_bp
                FROM headcount_budgets b JOIN months m ON b.starts_on<=LAST_DAY(m.month_start) AND b.ends_before>LAST_DAY(m.month_start)
                JOIN position_revisions r ON r.group_id=b.group_id AND r.company_id=b.company_id AND r.position_id=b.position_id AND r.active=1
                AND r.effective_from<=LAST_DAY(m.month_start) AND (r.effective_until IS NULL OR r.effective_until>LAST_DAY(m.month_start))
                WHERE b.group_id=? AND b.company_id=? GROUP BY r.department_id,m.month_start',["$year-01-01",...$scope]);
            $departments=array_column($this->db->rows('SELECT id,name FROM departments WHERE group_id=? AND company_id=?',$scope),'name','id');
            $rows=[];
            $put=function (int $dept,string $month,array $value) use (&$rows,$departments) {
                $key=$month.':'.$dept;
                $rows[$key]=array_replace($rows[$key] ?? ['department_id'=>$dept,'department'=>$departments[$dept],'period_start'=>$month,
                    'planned_fte_bp'=>0,'planned_cost_minor'=>0,'capacity_bp'=>0,'occupied_bp'=>0,'closed_cost_minor'=>null,'payroll_run_id'=>null],$value);
            };
            foreach ($plan['months'] as $r) $put($r['department_id'],$r['period_start'],['planned_fte_bp'=>$r['monthly_fte_bp'],'planned_cost_minor'=>$r['cost_minor']]);
            foreach ($filled as $r) $put($r['department_id'],$r['period_start'],['occupied_bp'=>(int)$r['occupied_bp']]);
            foreach ($capacity as $r) $put($r['department_id'],$r['period_start'],['capacity_bp'=>(int)$r['capacity_bp']]);
            foreach ((new PayrollCosts($this->db))->closedForPlanning($actor,$company,$year) as $run) {
                foreach ($rows as &$existing) {
                    if ($existing['period_start']===$run['period_start']) {
                        $existing['closed_cost_minor']=0;
                        $existing['payroll_run_id']=$run['run_id'];
                    }
                }
                unset($existing);
                foreach ($run['departments'] as $r) $put($r['department_id'],$run['period_start'],['closed_cost_minor'=>$r['total_cost_minor'],'payroll_run_id'=>$run['run_id']]);
            }
            foreach ($rows as &$row) {
                $row['vacant_bp']=max(0,$row['capacity_bp']-$row['occupied_bp']);
                $row['variance_fte_bp']=$row['occupied_bp']-$row['planned_fte_bp'];
                $row['variance_cost_minor']=$row['closed_cost_minor']===null?null:$row['closed_cost_minor']-$row['planned_cost_minor'];
            }
            ksort($rows);
            return ['scenario_id'=>$id,'year'=>$year,'currency'=>$plan['currency'],'months'=>array_values($rows)];
        },repeatableRead:true);
    }

    public function detail(Access $actor, int $company, int $id): array
    {
        $actor->company($company,'hr','payroll_reviewer');
        $scope = [$actor->groupId,$company,$id];
        $scenario = $this->db->row('SELECT id scenario_id,scenario_code,plan_year,name,revision,currency FROM workforce_scenarios
            WHERE group_id=? AND company_id=? AND id=?',$scope) ?? throw new BusinessError(404,'scenario_not_found');
        $scenario['months'] = $this->db->rows('SELECT department_id,period_start,monthly_fte_bp,cost_minor FROM workforce_plan_months
            WHERE group_id=? AND company_id=? AND scenario_id=? ORDER BY department_id,period_start',$scope);
        return $scenario;
    }
}
