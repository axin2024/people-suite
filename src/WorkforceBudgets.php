<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;

final class WorkforceBudgets
{
    public function __construct(private readonly Database $db, private readonly Operations $operations)
    {
    }

    public function assign(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company,'hr');
        $department = Input::integer($input['department_id'] ?? null,'department_id');
        $manager = Input::integer($input['manager_id'] ?? null,'manager_id');
        $grant = $input['granted'] ?? null;
        if (!is_bool($grant)) {
            throw new BusinessError(422,'invalid_field',['field'=>'granted']);
        }
        return $this->operations->once($actor,$company,'workforce.delegate',$key,$input,function () use ($actor,$company,$department,$manager,$grant) {
            $scope = $this->lockDepartment($actor,$company,$department);
            if ($grant) {
                if (!$this->db->row("SELECT 1 FROM company_roles r JOIN accounts a ON a.group_id=r.group_id AND a.id=r.account_id
                    WHERE r.group_id=? AND r.company_id=? AND r.account_id=? AND r.role='manager' AND a.enabled=1",
                    [$actor->groupId,$company,$manager])) {
                    throw new BusinessError(404,'manager_not_found');
                }
                $this->db->run('INSERT INTO workforce_department_managers (group_id,company_id,department_id,manager_id)
                    VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE manager_id=workforce_department_managers.manager_id',[...$scope,$manager]);
            } else {
                $this->db->run('DELETE FROM workforce_department_managers WHERE group_id=? AND company_id=? AND department_id=? AND manager_id=?',[...$scope,$manager]);
            }
            $result = ['department_id'=>$department,'manager_id'=>$manager,'granted'=>$grant];
            $this->operations->audit($actor,$company,'department',$department,'workforce.delegate',$result);
            return $result;
        });
    }

    private function lockDepartment(Access $actor, int $company, int $department): array
    {
        $scope = [$actor->groupId,$company,$department];
        if (!$this->db->row('SELECT id FROM departments WHERE group_id=? AND company_id=? AND id=? FOR UPDATE',$scope)) {
            throw new BusinessError(404,'department_not_found');
        }
        return $scope;
    }

    public function authorizedManager(Access $actor, int $company, int $department): void
    {
        $actor->company($company,'manager');
        if (!$this->db->row('SELECT 1 FROM workforce_department_managers WHERE group_id=? AND company_id=? AND department_id=? AND manager_id=?',
            [$actor->groupId,$company,$department,$actor->accountId])) {
            throw new BusinessError(404,'department_not_found');
        }
    }

    private static function months(array $input): array
    {
        $start = Input::date($input['start_month'] ?? null,'start_month');
        $count = Input::integer($input['months'] ?? null,'months',1,12);
        if (!str_ends_with($start,'-01') || (int)substr($start,5,2)+$count-1>12) {
            throw new BusinessError(422,'invalid_budget_year',['field'=>'start_month']);
        }
        $first = new DateTimeImmutable($start);
        $dates = [];
        for ($i=0;$i<$count;$i++) {
            $dates[] = $first->modify("+$i months")->format('Y-m-d');
        }
        return $dates;
    }

    public function limits(Access $actor, int $company, string $key, array $input): array
    {
        $actor->company($company,'payroll_reviewer');
        $department = Input::integer($input['department_id'] ?? null,'department_id');
        $periods = self::months($input);
        $capacity = Input::integer($input['capacity_bp'] ?? null,'capacity_bp',0,1000000000);
        $amount = Input::integer($input['cost_cap_minor'] ?? null,'cost_cap_minor',0,1000000000000000);
        $revision = Input::integer($input['previous_revision'] ?? null,'previous_revision',0);
        return $this->operations->once($actor,$company,'workforce.limit',$key,$input,function () use ($actor,$company,$department,$periods,$capacity,$amount,$revision) {
            $scope = $this->lockDepartment($actor,$company,$department);
            foreach ($periods as $month) {
                $prior = $this->db->row('SELECT * FROM workforce_budget_limits WHERE group_id=? AND company_id=? AND department_id=? AND period_start=? FOR UPDATE',[...$scope,$month]);
                if (($prior['revision'] ?? 0) !== $revision || ($prior['used_bp'] ?? 0)>$capacity || ($prior['used_minor'] ?? 0)>$amount) {
                    throw new BusinessError(409,'budget_limit_changed',['period'=>$month]);
                }
                $this->db->run('INSERT INTO workforce_budget_limits (group_id,company_id,department_id,period_start,revision,capacity_bp,cost_cap_minor)
                    VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=?,capacity_bp=?,cost_cap_minor=?',
                    [...$scope,$month,$revision+1,$capacity,$amount,$revision+1,$capacity,$amount]);
            }
            $result = ['department_id'=>$department,'months'=>$periods,'limit_rev'=>$revision+1,'capacity_bp'=>$capacity,'cost_cap_minor'=>$amount];
            $this->operations->audit($actor,$company,'department',$department,'workforce.limit',$result);
            return $result;
        });
    }

    private function proposal(array $input): array
    {
        $months = self::months($input);
        return ['start_month'=>$months[0],'months'=>count($months),'fte_bp'=>Input::integer($input['fte_bp'] ?? null,'fte_bp',1,10000),
            'monthly_minor'=>Input::integer($input['monthly_minor'] ?? null,'monthly_minor',0,1000000000000),
            'grade'=>Input::text($input['grade'] ?? null,'grade',48)];
    }

    private function position(Access $actor, int $company, int $department, int $position, string $date): void
    {
        if (!$this->db->row('SELECT 1 FROM position_revisions WHERE group_id=? AND company_id=? AND department_id=? AND position_id=?
            AND effective_from<=? AND (effective_until IS NULL OR effective_until>?) AND active=1',[$actor->groupId,$company,$department,$position,$date,$date])) {
            throw new BusinessError(422,'position_outside_department',['field'=>'position_id']);
        }
    }

    public function submit(Access $actor, int $company, string $key, array $input): array
    {
        $department = Input::integer($input['department_id'] ?? null,'department_id');
        $this->authorizedManager($actor,$company,$department);
        $position = Input::integer($input['position_id'] ?? null,'position_id');
        $terms = $this->proposal($input);
        return $this->operations->once($actor,$company,'budget.submit',$key,$input,function () use ($actor,$company,$department,$position,$terms) {
            $this->lockDepartment($actor,$company,$department);
            $this->authorizedManager($actor,$company,$department);
            $this->position($actor,$company,$department,$position,$terms['start_month']);
            $id = $this->db->insert("INSERT INTO workforce_budget_requests
                (group_id,company_id,department_id,position_id,start_month,months,fte_bp,monthly_minor,grade,state,revision,submitted_by)
                VALUES (?,?,?,?,?,?,?,?,?,'submitted',1,?)",[$actor->groupId,$company,$department,$position,...array_values($terms),$actor->accountId]);
            return $this->record($actor,$company,$id,'部门申报');
        });
    }

    private function request(Access $actor,int $company,int $id,bool $lock=false): array
    {
        return $this->db->row('SELECT * FROM workforce_budget_requests WHERE group_id=? AND company_id=? AND id=?'.($lock?' FOR UPDATE':''),
            [$actor->groupId,$company,$id]) ?? throw new BusinessError(404,'budget_request_not_found');
    }

    public function change(Access $actor,int $company,int $id,string $action,string $key,array $input): array
    {
        $role = match ($action) {'revise'=>'manager','review'=>'hr','approve','return'=>'payroll_reviewer',default=>throw new BusinessError(404,'route_not_found')};
        $actor->company($company,$role);
        $record = $this->request($actor,$company,$id);
        if ($role==='manager') {
            $this->authorizedManager($actor,$company,$record['department_id']);
        }
        $revision = Input::integer($input['budget_rev'] ?? null,'budget_rev');
        $reason = Input::text($input['reason'] ?? null,'reason',400);
        return $this->operations->once($actor,$company,'budget.'.$action,$key,['request_id'=>$id,'data'=>$input],
            function () use ($actor,$company,$id,$action,$reason,$revision,$input,$record) {
                $scope = $this->lockDepartment($actor,$company,$record['department_id']);
                $r = $this->request($actor,$company,$id,true);
                $expected = match ($action) {'revise'=>'adjustment','review'=>'submitted',default=>'reviewed'};
                if ($r['revision']!==$revision || $r['state']!==$expected) {
                    throw new BusinessError(409,'budget_revision_changed');
                }
                if ($action==='revise') {
                    $this->authorizedManager($actor,$company,$r['department_id']);
                    $terms = $this->proposal($input);
                    $this->position($actor,$company,$r['department_id'],$r['position_id'],$terms['start_month']);
                    $this->db->run('UPDATE workforce_budget_requests SET start_month=?,months=?,fte_bp=?,monthly_minor=?,grade=?,submitted_by=? WHERE id=?',
                        [...array_values($terms),$actor->accountId,$id]);
                    $state = 'submitted';
                } elseif ($action==='review') {
                    if ($actor->accountId===$r['submitted_by']) {
                        throw new BusinessError(409,'independent_review_required');
                    }
                    $grade = Input::text($input['grade'] ?? null,'grade',48);
                    $this->db->run('UPDATE workforce_budget_requests SET grade=? WHERE id=?',[$grade,$id]);
                    $state = 'reviewed';
                } else {
                    $reviewer = $this->db->row('SELECT actor_id FROM workforce_budget_history WHERE group_id=? AND company_id=? AND request_id=? AND revision=?',
                        [$actor->groupId,$company,$id,$revision]);
                    if (in_array($actor->accountId,[$reviewer['actor_id'],$r['submitted_by']],true)) {
                        throw new BusinessError(409,'independent_review_required');
                    }
                    $shortfall = $action==='approve' ? $this->reserve($scope,$r) : null;
                    $state = $action==='approve' && $shortfall===null ? 'approved' : 'adjustment';
                }
                $this->db->run('UPDATE workforce_budget_requests SET state=?,revision=revision+1 WHERE id=?',[$state,$id]);
                return $this->record($actor,$company,$id,$reason,$shortfall ?? null);
            });
    }

    private function reserve(array $scope,array $r): ?string
    {
        $periods = self::months($r);
        foreach ($periods as $month) {
            $limit = $this->db->row('SELECT * FROM workforce_budget_limits WHERE group_id=? AND company_id=? AND department_id=? AND period_start=? FOR UPDATE',[...$scope,$month]);
            if (!$limit || $limit['capacity_bp']-$limit['used_bp']<$r['fte_bp'] || $limit['cost_cap_minor']-$limit['used_minor']<$r['monthly_minor']) {
                return $month;
            }
        }
        foreach ($periods as $month) {
            $this->db->run('UPDATE workforce_budget_limits SET used_bp=used_bp+?, used_minor=used_minor+?
                WHERE group_id=? AND company_id=? AND department_id=? AND period_start=?',[$r['fte_bp'],$r['monthly_minor'],...$scope,$month]);
            $this->db->run('INSERT INTO workforce_budget_reservations (group_id,company_id,department_id,request_id,period_start,fte_bp,amount_minor)
                VALUES (?,?,?,?,?,?,?)',[...$scope,$r['id'],$month,$r['fte_bp'],$r['monthly_minor']]);
        }
        return null;
    }

    private function record(Access $actor,int $company,int $id,string $reason,?string $shortfall=null): array
    {
        $r = $this->request($actor,$company,$id);
        $snapshot = ['request_id'=>$id,'budget_rev'=>$r['revision'],'state'=>$r['state'],'position_id'=>$r['position_id'],
            'department_id'=>$r['department_id'],'start_month'=>$r['start_month'],'months'=>$r['months'],'grade'=>$r['grade'],
            'fte_bp'=>$r['fte_bp'],'monthly_minor'=>$r['monthly_minor'],'reserved_minor'=>$r['state']==='approved'?$r['monthly_minor']*$r['months']:0,
            'reason'=>$reason,'shortfall_month'=>$shortfall];
        $this->db->run('INSERT INTO workforce_budget_history (group_id,company_id,request_id,revision,actor_id,snapshot) VALUES (?,?,?,?,?,?)',
            [$actor->groupId,$company,$id,$r['revision'],$actor->accountId,json_encode($snapshot,JSON_THROW_ON_ERROR)]);
        $this->operations->audit($actor,$company,'position_budget',$id,$r['state'],$snapshot);
        return $snapshot;
    }

    public function board(Access $actor,int $company,int $after): array
    {
        $actor->company($company,'hr','manager','payroll_reviewer');
        $full = (int)(bool) array_intersect(['hr','payroll_reviewer'],$actor->roles($company));
        $scope = [$actor->groupId,$company,$full,$actor->accountId];
        $visibility = ' AND (? OR EXISTS (SELECT 1 FROM workforce_department_managers g WHERE g.group_id=d.group_id
            AND g.company_id=d.company_id AND g.department_id=d.id AND g.manager_id=?))';
        $departments = $this->db->rows('SELECT d.id,d.name FROM departments d WHERE d.group_id=? AND d.company_id=?'.$visibility.' ORDER BY d.id',$scope);
        $requests = $this->db->rows('SELECT r.id,r.department_id,d.name department,r.position_id,r.start_month,r.months,r.grade,r.monthly_minor,r.state,r.revision
            FROM workforce_budget_requests r JOIN departments d ON d.group_id=r.group_id AND d.company_id=r.company_id AND d.id=r.department_id
            WHERE d.group_id=? AND d.company_id=?'.$visibility.' AND r.id>? ORDER BY r.id LIMIT 100',[...$scope,$after]);
        return ['departments'=>$departments,'requests'=>$requests];
    }

    public function detail(Access $actor,int $company,int $id): array
    {
        $actor->company($company,'manager','hr','payroll_reviewer');
        $r = $this->request($actor,$company,$id);
        if (!array_intersect(['hr','payroll_reviewer'],$actor->roles($company))) {
            $this->authorizedManager($actor,$company,$r['department_id']);
        }
        $scope = [$actor->groupId,$company,$id];
        $history = $this->db->rows('SELECT actor_id,snapshot,recorded_at FROM workforce_budget_history WHERE group_id=? AND company_id=? AND request_id=? ORDER BY revision',$scope);
        foreach ($history as &$item) {
            $item['snapshot'] = json_decode($item['snapshot'],true,flags:JSON_THROW_ON_ERROR);
        }
        return ['request'=>$r,'history'=>$history,'reservations'=>$this->db->rows('SELECT period_start,fte_bp,amount_minor FROM workforce_budget_reservations
            WHERE group_id=? AND company_id=? AND request_id=? ORDER BY period_start',$scope)];
    }
}
