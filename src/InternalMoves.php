<?php

declare(strict_types=1);

namespace PeopleSuite;

final class InternalMoves
{
    private readonly Organization $org;
    private readonly TalentSkills $skills;
    private readonly WorkforceBudgets $budgets;

    public function __construct(private readonly Database $db,private readonly Operations $ops)
    {
        $this->org=new Organization($db,$ops);
        $this->skills=new TalentSkills($db,$ops);
        $this->budgets=new WorkforceBudgets($db,$ops);
    }

    public function reserve(Access $actor,int $company,string $key,array $input): array
    {
        $actor->company($company,'hr');
        $app=Input::integer($input['application_id'] ?? null,'application_id');
        $from=Input::integer($input['from_position'] ?? null,'from_position');
        $to=Input::integer($input['to_position'] ?? null,'to_position');
        $date=Input::date($input['effective_date'] ?? null,'effective_date');
        $rev=Input::integer($input['offer_rev'] ?? null,'offer_rev');
        return $this->ops->once($actor,$company,'internal.reserve',$key,compact('app','from','to','date','rev'),function () use ($actor,$company,$app,$from,$to,$date,$rev) {
            $scope=[$actor->groupId,$company,$app];
            $a=$this->db->row('SELECT a.*,w.assignment_version FROM internal_applications a JOIN employments w
                ON w.group_id=a.group_id AND w.company_id=a.source_company_id AND w.id=a.employment_id
                WHERE a.group_id=? AND a.company_id=? AND a.id=? FOR UPDATE',$scope) ?? throw new BusinessError(404,'application_not_found');
            $o=$this->db->row('SELECT * FROM internal_openings WHERE id=? FOR UPDATE',[$a['opening_id']]);
            if ($a['revision']!==$rev || $a['state']!=='reviewed' || $o['state']!=='open') throw new BusinessError(409,'offer_or_opening_changed');
            if ($o['position_id']!==$to || $from===$to) throw new BusinessError(422,'invalid_field',['field'=>'to_position']);
            if ($date<$this->skills->today($actor,$company)) throw new BusinessError(422,'invalid_field',['field'=>'effective_date']);
            $positions=[$from=>$a['source_company_id'],$to=>$company];
            ksort($positions,SORT_NUMERIC);
            foreach ($positions as $p=>$c) $this->org->lockPosition($actor,$c,$p);
            $this->db->row('SELECT id FROM employees WHERE group_id=? AND id=? FOR UPDATE',[$actor->groupId,$a['employee_id']]);
            $known=$this->db->row('SELECT * FROM assignments WHERE group_id=? AND company_id=? AND employment_id=? ORDER BY version DESC LIMIT 1',
                [$actor->groupId,$a['source_company_id'],$a['employment_id']]);
            if (!$known || $known['position_id']!==$from || $known['effective_until']!==null || $known['effective_from']>=$date ||
                $a['assignment_version']!==$known['version']) throw new BusinessError(409,'source_assignment_changed');
            (new WorkforceAccess($this->db,$this->ops))->requireNewWorkAllowed($actor,$a['source_company_id'],$a['employment_id'],$date);
            $month=substr($date,0,7).'-01';
            $funding=$this->db->row('SELECT fte_bp FROM workforce_budget_reservations WHERE group_id=? AND company_id=? AND request_id=? AND period_start=?',
                [$actor->groupId,$company,$o['budget_id'],$month]);
            if (!$funding) throw new BusinessError(409,'move_budget_month_unfunded');
            if ($a['source_company_id']===$company && !$this->covered($actor->groupId,$company,$a['employment_id'],$date))
                throw new BusinessError(409,'move_contract_conflict');
            $matching=(new InternalMarket($this->db,$this->ops))->match($o,$a['source_company_id'],$a['employee_id'],$date);
            if (!$matching['eligible']) throw new BusinessError(409,'required_evidence_missing',['skills'=>$matching['missing_required']]);
            $target=$this->org->activeRevision($actor,$company,$to,$date);
            if ($target['department_id']!==$o['department_id']) throw new BusinessError(409,'receiving_department_changed');
            if ($this->db->row("SELECT id FROM internal_moves WHERE group_id=? AND source_employment_id=? AND state IN ('awaiting_signatures','scheduled')",
                [$actor->groupId,$a['employment_id']])) throw new BusinessError(409,'employment_move_pending');
            $this->employeeCapacity($actor->groupId,$a['employee_id'],$a['employment_id'],$date,$funding['fte_bp']);
            $slot=$this->org->reserve($actor,$company,$to,$date,fteBp:$funding['fte_bp']);
            $this->org->checkFutureAssignment($actor,$company,$to,$slot);
            $id=$this->db->insert('INSERT INTO internal_moves (group_id,company_id,application_id,opening_id,employee_id,source_company_id,source_employment_id,
                source_assignment_id,source_version,from_position,to_position,source_department,target_department,effective_date,offer_rev,slot_id,reserved_fte,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$scope,$o['id'],$a['employee_id'],$a['source_company_id'],$a['employment_id'],$known['id'],
                $known['version'],$from,$to,$known['department_id'],$o['department_id'],$date,$rev,$slot,$funding['fte_bp'],$actor->accountId]);
            $this->db->run("UPDATE internal_openings SET state='reserved' WHERE id=?",[$o['id']]);
            $this->db->run("UPDATE internal_applications SET state='reserved',revision=revision+1 WHERE id=?",[$app]);
            $result=['move_id'=>$id,'state'=>'awaiting_signatures','revision'=>1,'reserved_fte'=>$funding['fte_bp'],'effective_date'=>$date,
                'required_signers'=>['source_manager','target_manager']];
            $this->ops->audit($actor,$company,'internal_move',$id,'reserved',$result+['application_id'=>$app,'offer_rev'=>$rev]);
            return $result;
        });
    }

    public function employeeCapacity(int $group,int $employee,int $replaced,string $day,int $incoming): void
    {
        $rows=$this->db->rows('SELECT e.id employment_id,a.effective_from,a.effective_until,COALESCE(f.fte_bp,10000) bp FROM employments e
            JOIN assignments a ON a.group_id=e.group_id AND a.company_id=e.company_id AND a.employment_id=e.id
            LEFT JOIN headcount_slot_workloads f ON f.slot_id=a.slot_id
            WHERE e.group_id=? AND e.employee_id=? AND e.id<>? AND (a.effective_until IS NULL OR a.effective_until>?)',[$group,$employee,$replaced,$day]);
        $pending=$this->db->rows("SELECT source_employment_id employment_id,effective_date,reserved_fte FROM internal_moves
            WHERE group_id=? AND employee_id=? AND source_employment_id<>? AND state IN ('awaiting_signatures','scheduled')",[$group,$employee,$replaced]);
        $events=[$day=>$incoming];
        $replacements=array_column($pending,'effective_date','employment_id');
        foreach ($pending as $m) {
            $date=max($day,$m['effective_date']);
            $events[$date]=($events[$date]??0)+$m['reserved_fte'];
        }
        foreach ($rows as $r) {
            $from=max($day,$r['effective_from']);
            if (isset($replacements[$r['employment_id']])) $r['effective_until']=min($r['effective_until']??'9999-12-31',$replacements[$r['employment_id']]);
            if ($r['effective_until']!==null && $r['effective_until']<=$from) continue;
            $events[$from]=($events[$from]??0)+$r['bp'];
            if ($r['effective_until']!==null) $events[$r['effective_until']]=($events[$r['effective_until']]??0)-$r['bp'];
        }
        ksort($events);
        $sum=0;
        foreach ($events as $date=>$value) {
            $sum+=$value;
            if ($sum>10000) throw new BusinessError(409,'employee_fte_exhausted',['effective_date'=>$date]);
        }
    }

    private function get(Access $actor,int $company,int $id,bool $lock=false): array
    {
        $m=$this->db->row('SELECT m.*,e.account_id employee_account FROM internal_moves m JOIN employees e ON e.group_id=m.group_id AND e.id=m.employee_id
            WHERE m.group_id=? AND (m.company_id=? OR m.source_company_id=?) AND m.id=?'.($lock?' FOR UPDATE':''),
            [$actor->groupId,$company,$company,$id]) ?? throw new BusinessError(404,'move_not_found');
        $roles=$actor->roles($company);
        if (in_array('hr',$roles,true) || (in_array('employee',$roles,true) && $actor->accountId===$m['employee_account'])) return $m;
        if (in_array('manager',$roles,true)) {
            foreach ([[$m['source_company_id'],$m['source_department']],[$m['company_id'],$m['target_department']]] as [$c,$d]) {
                if ($c===$company && $this->db->row('SELECT 1 FROM workforce_department_managers WHERE group_id=? AND company_id=? AND department_id=? AND manager_id=?',
                    [$actor->groupId,$company,$d,$actor->accountId])) return $m;
            }
        }
        if ($this->db->row('SELECT 1 FROM internal_move_handover WHERE move_id=? AND owner_id=?',[$id,$actor->accountId]) &&
            $company===$m['source_company_id'] && $roles) return $m;
        throw new BusinessError(404,'move_not_found');
    }

    public function board(Access $actor,int $company,int $after): array
    {
        $actor->company($company,'hr','manager','employee');
        $roles=$actor->roles($company);
        $items=$this->db->rows('SELECT m.id,m.effective_date,m.state,m.reserved_fte,m.from_position,m.to_position,m.revision,o.title
            FROM internal_moves m JOIN internal_openings o ON o.id=m.opening_id JOIN employees e ON e.group_id=m.group_id AND e.id=m.employee_id
            WHERE m.group_id=? AND (m.company_id=? OR m.source_company_id=?) AND m.id>? AND (
                ? OR (? AND e.account_id=?) OR (? AND EXISTS (SELECT 1 FROM workforce_department_managers g WHERE g.group_id=m.group_id
                   AND g.company_id=? AND g.manager_id=? AND ((g.department_id=m.source_department AND g.company_id=m.source_company_id)
                   OR (g.department_id=m.target_department AND g.company_id=m.company_id))))
                OR (m.source_company_id=? AND EXISTS(SELECT 1 FROM internal_move_handover h WHERE h.move_id=m.id AND h.owner_id=?)))
            ORDER BY m.id LIMIT 100',[$actor->groupId,$company,$company,$after,(int)in_array('hr',$roles,true),(int)in_array('employee',$roles,true),
                $actor->accountId,(int)in_array('manager',$roles,true),$company,$actor->accountId,$company,$actor->accountId]);
        return ['items'=>$items];
    }

    public function offers(Access $actor,int $company,int $after): array
    {
        $actor->company($company,'hr');
        return ['items'=>$this->db->rows("SELECT a.id,a.revision offer_rev,e.display_name,o.title,o.position_id to_position,
            a.source_company_id,a.employment_id,(SELECT s.position_id FROM assignments s WHERE s.employment_id=a.employment_id ORDER BY s.version DESC LIMIT 1) from_position
            FROM internal_applications a JOIN internal_openings o ON o.id=a.opening_id JOIN employees e ON e.group_id=a.group_id AND e.id=a.employee_id
            WHERE a.group_id=? AND a.company_id=? AND a.state='reviewed' AND o.state='open' AND a.id>? ORDER BY a.id LIMIT 100",[$actor->groupId,$company,$after])];
    }

    public function preview(Access $actor,int $company,int $application,string $day): array
    {
        $actor->company($company,'hr');
        $app=$this->db->row("SELECT a.*,e.display_name FROM internal_applications a JOIN employees e ON e.id=a.employee_id
            WHERE a.group_id=? AND a.company_id=? AND a.id=? AND a.state='reviewed'",[$actor->groupId,$company,$application]) ?? throw new BusinessError(404,'application_not_found');
        $o=$this->db->row('SELECT * FROM internal_openings WHERE id=?',[$app['opening_id']]);
        $source=$this->db->row('SELECT position_id,department_id FROM assignments WHERE employment_id=? ORDER BY version DESC LIMIT 1',[$app['employment_id']]);
        $cross=$app['source_company_id']!==$company;
        $headcount=$this->org->headcount($actor,$company,$o['position_id'],$day);
        $funding=$this->db->row('SELECT fte_bp FROM workforce_budget_reservations WHERE group_id=? AND company_id=? AND request_id=? AND period_start=?',
            [$actor->groupId,$company,$o['budget_id'],substr($day,0,7).'-01']);
        $handover=$this->db->row("SELECT h.owner_id FROM internal_moves m LEFT JOIN internal_move_handover h ON h.move_id=m.id
            WHERE m.group_id=? AND m.source_company_id=? AND m.source_employment_id=? AND m.state IN ('awaiting_signatures','scheduled')",
            [$actor->groupId,$app['source_company_id'],$app['employment_id']]);
        $match=(new InternalMarket($this->db,$this->ops))->match($o,$app['source_company_id'],$app['employee_id'],$day);
        return ['application_id'=>$application,'offer_rev'=>$app['revision'],'employee'=>$app['display_name'],'from_position'=>$source['position_id'],
            'to_position'=>$o['position_id'],'effective_date'=>$day,'target_available_fte'=>$headcount['available'],
            'contract_check'=>$cross?'receiving_confirmation_required':($this->covered($actor->groupId,$company,$app['employment_id'],$day)?'covered':'source_contract_conflict'),
            'missing_required'=>$match['missing_required'],'funded_fte_bp'=>$funding['fte_bp']??0,'current_handover_owner'=>$handover['owner_id']??null,
            'pending_move'=>$handover!==null];
    }

    public function covered(int $group,int $company,int $employment,string $day): bool
    {
        $contract=$this->db->row('SELECT ends_before FROM contract_revisions WHERE group_id=? AND company_id=? AND employment_id=?
            AND effective_from<=? ORDER BY effective_from DESC,version DESC LIMIT 1',[$group,$company,$employment,$day]);
        return $contract && ($contract['ends_before']===null || $contract['ends_before']>$day);
    }

    public function due(Access $actor,int $company,int $after): array
    {
        $actor->company($company,'hr');
        $day=$this->skills->today($actor,$company);
        $batch=$this->db->rows("SELECT id FROM internal_moves WHERE group_id=? AND company_id=? AND state='scheduled'
            AND effective_date<=? AND id>? ORDER BY id LIMIT 51",[$actor->groupId,$company,$day,$after]);
        $items=[];
        $employment=new Employment($this->db,$this->ops,$this->org);
        foreach (array_slice($batch,0,50) as $r) {
            try {
                $items[]=$employment->applyReservedMove($actor,$company,$r['id']);
            } catch (BusinessError $error) {
                $items[]=['move_id'=>$r['id'],'state'=>'scheduled','error'=>$error->reason];
            }
        }
        return ['business_date'=>$day,'items'=>$items,'next_after'=>count($batch)>50?$batch[49]['id']:null];
    }

    public function detail(Access $actor,int $company,int $id): array
    {
        $m=$this->get($actor,$company,$id);
        $contract=$this->db->row('SELECT reference,monthly_minor,ends_before,confirmed_by FROM internal_move_contracts WHERE move_id=?',[$id]);
        $roles=$actor->roles($company);
        $hr=in_array('hr',$roles,true);
        $target=$company===$m['company_id'];
        $self=$actor->accountId===$m['employee_account'];
        if ($contract && !($target && $hr) && !$self) unset($contract['monthly_minor']);
        $signatures=$this->db->rows('SELECT side,actor_id,notes,signed_at FROM internal_move_signatures WHERE move_id=? ORDER BY side',[$id]);
        $handover=$this->db->row('SELECT owner_id,checklist,assigned_by,completed_at FROM internal_move_handover WHERE move_id=?',[$id]);
        $today=$this->skills->today($actor,$m['company_id']);
        $actions=[];
        if ($m['state']==='awaiting_signatures') {
            $cross=$m['source_company_id']!==$m['company_id'];
            if ($cross && $target && $hr && !$contract) $actions[]='contract';
            if (in_array('manager',$roles,true) && !in_array($actor->accountId,array_column($signatures,'actor_id'),true)) {
                foreach (['source','target'] as $side) {
                    $scope=$side==='source'?$m['source_company_id']:$m['company_id'];
                    if ($scope!==$company || in_array($side.'_manager',array_column($signatures,'side'),true)) continue;
                    if ($side==='target' && $cross && !$contract) continue;
                    if ($this->db->row('SELECT 1 FROM workforce_department_managers WHERE group_id=? AND company_id=? AND department_id=? AND manager_id=?',
                        [$actor->groupId,$company,$m[$side.'_department'],$actor->accountId])) $actions[]=$side.'-sign';
                }
            }
        }
        if (($self || $hr) && ($m['state']==='awaiting_signatures' || ($m['state']==='scheduled' && $today<$m['effective_date']))) $actions[]='withdraw';
        if ($handover && $handover['owner_id']===$actor->accountId && !$handover['completed_at'] && $m['state']!=='withdrawn' && $company===$m['source_company_id']) $actions[]='handover-complete';
        if ($target && $hr && $m['state']==='scheduled' && $today>=$m['effective_date']) $actions[]='apply';
        if (in_array('apply',$actions,true)) $actions[]='release-blocked';
        if ($m['result']!==null) $m['result']=json_decode($m['result'],true,flags:JSON_THROW_ON_ERROR);
        return ['move'=>$m,'signatures'=>$signatures,'contract'=>$contract,'handover'=>$handover,'actions'=>$actions];
    }

    public function update(Access $actor,int $company,int $id,string $key,string $action,array $input): array
    {
        $this->get($actor,$company,$id);
        $revision=Input::integer($input['revision'] ?? null,'revision');
        return $this->ops->once($actor,$company,'move.'.$action,$key,['move'=>$id,'data'=>$input],function () use ($actor,$company,$id,$revision,$action,$input) {
            $m=$this->get($actor,$company,$id,true);
            if ($m['revision']!==$revision) throw new BusinessError(409,'move_revision_changed');
            $state=$m['state'];
            if ($action==='handover-complete') {
                $h=$this->db->row('SELECT * FROM internal_move_handover WHERE move_id=? FOR UPDATE',[$id]);
                if (!$h || $h['owner_id']!==$actor->accountId || $company!==$m['source_company_id']) throw new BusinessError(404,'handover_not_found');
                if ($state==='withdrawn' || $h['completed_at']!==null) throw new BusinessError(409,'handover_not_pending');
                $this->db->run('UPDATE internal_move_handover SET completed_at=UTC_TIMESTAMP(6) WHERE move_id=?',[$id]);
            } elseif (in_array($action,['withdraw','release-blocked'],true)) {
                if ($action==='release-blocked') {
                    $actor->company($company,'hr');
                    if ($company!==$m['company_id'] || $state!=='scheduled' || $this->skills->today($actor,$company)<$m['effective_date']) throw new BusinessError(409,'move_not_due');
                    try {
                        (new Employment($this->db,$this->ops,$this->org))->applyReservedMove($actor,$company,$id);
                    } catch (BusinessError $error) {
                        $blockedBy=$error->reason;
                    }
                    // A valid cutover must use the apply action. Throwing here rolls its nested transaction back.
                    if (!isset($blockedBy)) throw new BusinessError(409,'move_ready_to_apply');
                } elseif (!in_array($state,['awaiting_signatures','scheduled'],true) ||
                    ($state==='scheduled' && $this->skills->today($actor,$m['company_id'])>=$m['effective_date'])) throw new BusinessError(409,'move_already_due');
                if ($actor->accountId!==$m['employee_account']) $actor->company($company,'hr');
                $reason=Input::text($input['reason'] ?? null,'reason',400);
                $this->org->lockPosition($actor,$m['company_id'],$m['to_position']);
                $this->db->run('UPDATE headcount_slots SET released_at=UTC_TIMESTAMP(6) WHERE id=? AND released_at IS NULL',[$m['slot_id']]);
                $this->db->run("UPDATE internal_openings SET state='open' WHERE id=?",[$m['opening_id']]);
                $this->db->run("UPDATE internal_applications SET state='reviewed',revision=revision+1 WHERE id=?",[$m['application_id']]);
                $state='withdrawn';
            } else {
                if ($state!=='awaiting_signatures') throw new BusinessError(409,'move_not_pending');
                if ($action==='contract') {
                    if ($company!==$m['company_id']) throw new BusinessError(404,'move_not_found');
                    $actor->company($company,'hr');
                    if ($m['source_company_id']===$company || $this->db->row('SELECT 1 FROM internal_move_contracts WHERE move_id=?',[$id])) throw new BusinessError(409,'move_contract_conflict');
                    $ref=Input::code($input['reference'] ?? null,'reference');
                    $minor=Input::integer($input['monthly_minor'] ?? null,'monthly_minor',1,100000000000);
                    $end=isset($input['ends_before'])?Input::date($input['ends_before'],'ends_before'):null;
                    if ($end!==null && $end<=$m['effective_date']) throw new BusinessError(422,'invalid_date',['field'=>'ends_before']);
                    if ($this->db->row('SELECT id FROM contract_revisions WHERE group_id=? AND company_id=? AND reference=?',[$actor->groupId,$company,$ref]))
                        throw new BusinessError(409,'move_contract_conflict');
                    $this->db->run('INSERT INTO internal_move_contracts (move_id,reference,monthly_minor,ends_before,confirmed_by) VALUES (?,?,?,?,?)',[$id,$ref,$minor,$end,$actor->accountId]);
                } else {
                    $source=$action==='source-sign';
                    if (!$source && $action!=='target-sign') throw new BusinessError(404,'route_not_found');
                    $side=$source?'source_manager':'target_manager';
                    if ($company!==($source?$m['source_company_id']:$m['company_id'])) throw new BusinessError(404,'move_not_found');
                    $this->budgets->authorizedManager($actor,$company,$source?$m['source_department']:$m['target_department']);
                    $note=Input::text($input['notes'] ?? null,'notes',400);
                    if (!$source && $m['source_company_id']!==$m['company_id'] && !$this->db->row('SELECT 1 FROM internal_move_contracts WHERE move_id=?',[$id])) {
                        throw new BusinessError(409,'receiving_contract_required');
                    }
                    if ($this->db->row('SELECT 1 FROM internal_move_signatures WHERE move_id=? AND (side=? OR actor_id=?)',[$id,$side,$actor->accountId])) {
                        throw new BusinessError(409,'independent_signature_required');
                    }
                    if ($source) {
                        $owner=Input::integer($input['owner_id'] ?? null,'owner_id');
                        $list=Input::text($input['checklist'] ?? null,'checklist',1200);
                        if (!$this->db->row('SELECT 1 FROM accounts a JOIN company_roles r ON a.group_id=r.group_id AND a.id=r.account_id
                            WHERE a.group_id=? AND r.company_id=? AND a.id=? AND a.enabled=1',[$actor->groupId,$company,$owner])) throw new BusinessError(404,'handover_owner_not_found');
                        $this->db->run('INSERT INTO internal_move_handover (move_id,owner_id,checklist,assigned_by) VALUES (?,?,?,?)',[$id,$owner,$list,$actor->accountId]);
                    }
                    $this->db->run('INSERT INTO internal_move_signatures (move_id,side,actor_id,notes) VALUES (?,?,?,?)',[$id,$side,$actor->accountId,$note]);
                    if ($this->db->row('SELECT COUNT(*) n FROM internal_move_signatures WHERE move_id=?',[$id])['n']===2) {
                        $state='scheduled';
                        $this->db->run("UPDATE internal_applications SET state='scheduled',revision=revision+1 WHERE id=?",[$m['application_id']]);
                    }
                }
            }
            $this->db->run('UPDATE internal_moves SET state=?,revision=revision+1 WHERE id=?',[$state,$id]);
            $result=['move_id'=>$id,'revision'=>$revision+1,'state'=>$state,'effective_date'=>$m['effective_date']];
            $this->ops->audit($actor,$company,'internal_move',$id,$action,$result+['reason'=>$reason ?? $note ?? null,'blocked_by'=>$blockedBy??null]);
            return $result;
        });
    }
}
