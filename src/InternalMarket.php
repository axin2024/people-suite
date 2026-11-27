<?php

declare(strict_types=1);

namespace PeopleSuite;

final class InternalMarket
{
    private readonly TalentSkills $skills;
    private readonly WorkforceBudgets $budget;

    public function __construct(private readonly Database $db,private readonly Operations $ops)
    {
        $this->skills=new TalentSkills($db,$ops);
        $this->budget=new WorkforceBudgets($db,$ops);
    }

    public function post(Access $actor,int $company,string $key,array $input): array
    {
        $budget=Input::integer($input['budget_id'] ?? null,'budget_id');
        $actor->company($company,'manager');
        $record=$this->db->row("SELECT * FROM workforce_budget_requests WHERE group_id=? AND company_id=? AND id=? AND state='approved'",
            [$actor->groupId,$company,$budget]) ?? throw new BusinessError(404,'approved_budget_not_found');
        $this->budget->authorizedManager($actor,$company,$record['department_id']);
        $title=Input::text($input['title'] ?? null,'title',160);
        $description=Input::text($input['description'] ?? null,'description',800);
        $closes=Input::date($input['closes_on'] ?? null,'closes_on');
        $requirements=$input['skills'] ?? null;
        $judges=$input['panel'] ?? null;
        if (!is_array($requirements) || !array_is_list($requirements) || !$requirements || count($requirements)>40) {
            throw new BusinessError(422,'invalid_field',['field'=>'skills']);
        }
        if (!is_array($judges) || !array_is_list($judges) || count($judges)<2 || count($judges)>12) {
            throw new BusinessError(422,'invalid_field',['field'=>'panel']);
        }
        $codes=[];
        foreach ($requirements as $r) {
            $skill=strtoupper(Input::code($r['skill'] ?? null,'skill'));
            if (isset($codes[$skill]) || !is_bool($r['required'] ?? null)) throw new BusinessError(422,'invalid_field',['field'=>'skills']);
            $codes[$skill]=(int)$r['required'];
        }
        $judges=array_map(fn($value)=>Input::integer($value,'panel'),$judges);
        if (count($judges)!==count(array_unique($judges))) throw new BusinessError(422,'invalid_field',['field'=>'panel']);
        return $this->ops->once($actor,$company,'opening.post',$key,$input,function () use ($actor,$company,$record,$budget,$title,$description,$closes,$codes,$judges) {
            $this->budget->authorizedManager($actor,$company,$record['department_id']);
            if ($closes<$this->skills->today($actor,$company)) throw new BusinessError(422,'opening_already_expired',['field'=>'closes_on']);
            $id=$this->db->insert('INSERT INTO internal_openings (group_id,company_id,department_id,position_id,budget_id,posted_by,title,description,closes_on)
                VALUES (?,?,?,?,?,?,?,?,?)',[$actor->groupId,$company,$record['department_id'],$record['position_id'],$budget,$actor->accountId,$title,$description,$closes]);
            foreach ($codes as $code=>$required) {
                $this->db->run('INSERT INTO internal_opening_skills (opening_id,skill,required_skill) VALUES (?,?,?)',[$id,$code,$required]);
            }
            foreach ($judges as $judge) {
                if (!$this->db->row("SELECT 1 FROM company_roles r JOIN accounts a ON a.group_id=r.group_id AND a.id=r.account_id
                    WHERE r.group_id=? AND r.company_id=? AND r.account_id=? AND r.role IN ('hr','manager') AND a.enabled=1",
                    [$actor->groupId,$company,$judge])) throw new BusinessError(422,'invalid_field',['field'=>'panel']);
                $this->db->run('INSERT INTO internal_opening_panel (opening_id,group_id,company_id,account_id) VALUES (?,?,?,?)',[$id,$actor->groupId,$company,$judge]);
            }
            $result=['opening_id'=>$id,'position_id'=>$record['position_id'],'budget_id'=>$budget,'state'=>'open'];
            $this->ops->audit($actor,$company,'internal_opening',$id,'posted',$result);
            return $result;
        });
    }

    public function listing(Access $actor,int $company,string $mode,int $after): array
    {
        $scope=[$actor->groupId,$after];
        $query='SELECT o.id,o.title,o.company_id,c.name company,o.department_id,d.name department,o.position_id,o.closes_on,o.state
            FROM internal_openings o JOIN companies c ON c.group_id=o.group_id AND c.id=o.company_id
            JOIN departments d ON d.group_id=o.group_id AND d.company_id=o.company_id AND d.id=o.department_id WHERE o.group_id=? AND o.id>? ';
        if ($mode==='market') {
            $this->skills->employee($actor,$company);
            $query.=" AND o.state='open' AND o.closes_on>=?";
            $scope[]=$this->skills->today($actor,$company);
        } else {
            $actor->company($company,'manager','hr');
            $query.=" AND o.company_id=? AND (EXISTS (SELECT 1 FROM internal_opening_panel p WHERE p.opening_id=o.id AND p.account_id=?)
                OR (? AND o.posted_by=? AND EXISTS (SELECT 1 FROM workforce_department_managers m
                WHERE m.group_id=o.group_id AND m.company_id=o.company_id AND m.department_id=o.department_id AND m.manager_id=?)))";
            array_push($scope,$company,$actor->accountId,(int)in_array('manager',$actor->roles($company),true),$actor->accountId,$actor->accountId);
        }
        return ['items'=>$this->db->rows($query.' ORDER BY o.id LIMIT 100',$scope)];
    }

    private function opening(Access $actor,int $id,bool $lock=false): array
    {
        return $this->db->row('SELECT * FROM internal_openings WHERE group_id=? AND id=?'.($lock?' FOR UPDATE':''),[$actor->groupId,$id])
            ?? throw new BusinessError(404,'opening_not_found');
    }

    public function match(array $opening,int $source,int $employee,string $day): array
    {
        $evidence=$this->skills->qualifications($opening['group_id'],$source,$employee,$opening['company_id'],$day);
        $bySkill=[];
        foreach ($evidence as $item) $bySkill[$item['skill']] ??= $item;
        $reasons=[];
        $missing=[];
        foreach ($this->db->rows('SELECT skill,required_skill FROM internal_opening_skills WHERE opening_id=? ORDER BY required_skill DESC,skill',[$opening['id']]) as $r) {
            $item=$bySkill[$r['skill']] ?? null;
            $reasons[]=['skill'=>$r['skill'],'required'=>(bool)$r['required_skill'],'evidence'=>$item];
            if (!$item && $r['required_skill']) $missing[]=$r['skill'];
        }
        return ['match_reasons'=>$reasons,'missing_required'=>$missing,'eligible'=>!$missing];
    }

    public function preview(Access $actor,int $source,int $id): array
    {
        $person=$this->skills->employee($actor,$source);
        $opening=$this->opening($actor,$id);
        $day=$this->skills->today($actor,$opening['company_id']);
        if ($opening['state']!=='open' || $opening['closes_on']<$day) throw new BusinessError(404,'opening_not_found');
        return ['opening'=>$opening]+$this->match($opening,$source,$person['employee_id'],$day);
    }

    public function apply(Access $actor,int $source,int $id,string $key,array $input): array
    {
        $person=$this->skills->employee($actor,$source);
        $text=Input::text($input['motivation'] ?? null,'motivation',800);
        return $this->ops->once($actor,$source,'internal.apply',$key,['opening_id'=>$id,'motivation'=>$text],function () use ($actor,$source,$person,$id,$text) {
            $o=$this->opening($actor,$id,true);
            if ($source!==$o['company_id']) (new WorkforceRollout($this->db))->admit($actor->groupId,$o['company_id']);
            $day=$this->skills->today($actor,$o['company_id']);
            if ($o['state']!=='open' || $o['closes_on']<$day) throw new BusinessError(409,'opening_unavailable');
            if ($this->db->row('SELECT 1 FROM internal_opening_panel WHERE opening_id=? AND account_id=?',[$id,$actor->accountId])) {
                throw new BusinessError(409,'panel_member_cannot_apply');
            }
            (new WorkforceAccess($this->db,$this->ops))->requireNewWorkAllowed($actor,$source,$person['employment_id']);
            $application=$this->db->insert('INSERT INTO internal_applications (group_id,company_id,opening_id,source_company_id,employment_id,employee_id,motivation)
                VALUES (?,?,?,?,?,?,?)',[$actor->groupId,$o['company_id'],$id,$source,$person['employment_id'],$person['employee_id'],$text]);
            $result=['application_id'=>$application,'opening_id'=>$id,'target_company_id'=>$o['company_id'],'offer_rev'=>1,'state'=>'applied'];
            $this->ops->audit($actor,$source,'internal_application',$application,'applied',$result);
            return $result;
        });
    }

    private function panel(Access $actor,int $company,array $o): bool
    {
        $actor->company($company,'manager','hr');
        if ($o['company_id']!==$company) throw new BusinessError(404,'opening_not_found');
        $reviewer=(bool)$this->db->row('SELECT 1 FROM internal_opening_panel WHERE opening_id=? AND account_id=?',[$o['id'],$actor->accountId]);
        if (!$reviewer) {
            if ($o['posted_by']!==$actor->accountId) throw new BusinessError(404,'opening_not_found');
            $this->budget->authorizedManager($actor,$company,$o['department_id']);
        }
        return $reviewer;
    }

    public function candidates(Access $actor,int $company,int $id,int $after): array
    {
        $o=$this->opening($actor,$id);
        $isReviewer=$this->panel($actor,$company,$o);
        $data=$this->db->rows("SELECT a.id,a.source_company_id,a.employee_id,e.display_name,a.motivation,a.state,a.revision
            FROM internal_applications a JOIN employees e ON e.group_id=a.group_id AND e.id=a.employee_id
            WHERE a.group_id=? AND a.company_id=? AND a.opening_id=? AND a.id>? AND a.state<>'withdrawn' ORDER BY a.id LIMIT 100",
            [$actor->groupId,$company,$id,$after]);
        $day=$this->skills->today($actor,$company);
        foreach ($data as &$r) {
            $r+=$this->match($o,$r['source_company_id'],$r['employee_id'],$day);
            $r['scores']=$this->db->rows('SELECT reviewer_id,score,notes FROM internal_interviews WHERE application_id=?
                AND (? OR reviewer_id=?) ORDER BY reviewer_id',[$r['id'],(int)($r['state']!=='applied'),$actor->accountId]);
        }
        return ['opening'=>$o,'can_score'=>$isReviewer,'items'=>$data];
    }

    public function mine(Access $actor,int $company,int $after): array
    {
        $person=$this->skills->employee($actor,$company);
        return ['items'=>$this->db->rows('SELECT a.id,a.opening_id,a.company_id target_company_id,o.title,a.state,a.revision FROM internal_applications a
            JOIN internal_openings o ON o.id=a.opening_id WHERE a.group_id=? AND a.source_company_id=? AND a.employee_id=? AND a.id>? ORDER BY a.id LIMIT 100',
            [$actor->groupId,$company,$person['employee_id'],$after])];
    }

    public function score(Access $actor,int $company,int $id,string $key,array $input): array
    {
        $actor->company($company,'manager','hr');
        $scope=[$actor->groupId,$company,$id];
        $app=$this->db->row('SELECT opening_id FROM internal_applications WHERE group_id=? AND company_id=? AND id=?',$scope) ?? throw new BusinessError(404,'application_not_found');
        $o=$this->opening($actor,$app['opening_id']);
        if (!$this->panel($actor,$company,$o)) throw new BusinessError(404,'application_not_found');
        $score=Input::integer($input['score'] ?? null,'score',1,100);
        $notes=Input::text($input['notes'] ?? null,'notes',600);
        return $this->ops->once($actor,$company,'internal.score',$key,['application_id'=>$id,'score'=>$score,'notes'=>$notes],function () use ($actor,$company,$id,$scope,$score,$notes,$o) {
            $row=$this->db->row('SELECT state FROM internal_applications WHERE group_id=? AND company_id=? AND id=? FOR UPDATE',$scope);
            if ($row['state']!=='applied' || $this->db->row('SELECT 1 FROM internal_interviews WHERE application_id=? AND reviewer_id=?',[$id,$actor->accountId])) {
                throw new BusinessError(409,'interview_already_decided');
            }
            $this->db->run('INSERT INTO internal_interviews (group_id,company_id,application_id,reviewer_id,score,notes) VALUES (?,?,?,?,?,?)',[...$scope,$actor->accountId,$score,$notes]);
            $pending=$this->db->row('SELECT COUNT(*) n FROM internal_opening_panel p WHERE p.opening_id=? AND NOT EXISTS
                (SELECT 1 FROM internal_interviews i WHERE i.application_id=? AND i.reviewer_id=p.account_id)',[$o['id'],$id])['n'];
            if (!$pending) {
                $this->db->run("UPDATE internal_applications SET state='reviewed',revision=revision+1 WHERE id=?",[$id]);
            }
            $result=$this->db->row('SELECT id application_id,revision offer_rev,state FROM internal_applications WHERE id=?',[$id]);
            $this->ops->audit($actor,$company,'internal_application',$id,'interview_scored',$result+['score'=>$score]);
            return $result;
        });
    }

    public function withdraw(Access $actor,int $source,int $id,string $key,array $input): array
    {
        $person=$this->skills->employee($actor,$source);
        $rev=Input::integer($input['revision']??null,'revision');
        $reason=Input::text($input['reason']??null,'reason',400);
        return $this->ops->once($actor,$source,'application.withdraw',$key,compact('id','rev','reason'),function () use ($actor,$source,$id,$rev,$reason,$person) {
            $app=$this->db->row('SELECT state,revision FROM internal_applications WHERE group_id=? AND source_company_id=? AND employee_id=? AND id=? FOR UPDATE',
                [$actor->groupId,$source,$person['employee_id'],$id]) ?? throw new BusinessError(404,'application_not_found');
            if ($app['revision']!==$rev || !in_array($app['state'],['applied','reviewed'],true)) throw new BusinessError(409,'application_changed');
            $this->db->run("UPDATE internal_applications SET state='withdrawn',revision=revision+1 WHERE id=?",[$id]);
            $result=['application_id'=>$id,'state'=>'withdrawn','revision'=>$rev+1];
            $this->ops->audit($actor,$source,'internal_application',$id,'application.withdrawn',$result+['reason'=>$reason]);
            return $result;
        });
    }
}
