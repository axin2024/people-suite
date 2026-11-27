<?php

declare(strict_types=1);

namespace PeopleSuite;

final class WorkforceRollout
{
    public function __construct(private readonly Database $db) {}

    public function admit(int $group,int $company): void
    {
        $r=$this->db->row('SELECT state FROM workforce_rollouts WHERE group_id=? AND company_id=? FOR SHARE',[$group,$company]);
        if (($r['state']??'setup')!=='active') throw new BusinessError(409,'workforce_intake_closed');
    }

    private function differences(): string
    {
        return ' FROM position_revisions r LEFT JOIN workforce_position_baselines b ON b.group_id=r.group_id AND b.company_id=r.company_id AND b.revision_id=r.id
            WHERE r.group_id=? AND r.company_id=? AND (b.revision_id IS NULL OR b.department_id<>r.department_id OR b.active<>r.active
            OR b.effective_from<>r.effective_from OR NOT (b.effective_until<=>r.effective_until) OR BINARY b.title<>BINARY r.title)';
    }

    public function preflight(Access $actor,int $company): array
    {
        $actor->company($company,'hr');
        $scope=[$actor->groupId,$company];
        return $this->db->transaction(function () use ($scope) {
            $counts=[];
            foreach (['positions','position_revisions','headcount_slots','assignments','payroll_closures','payslip_segments'] as $table)
                $counts[$table]=$this->db->row("SELECT COUNT(*) n FROM $table WHERE group_id=? AND company_id=?",$scope)['n'];
            $conflicts=$this->db->rows('SELECT r.id revision_id,p.id overlaps_revision FROM position_revisions r JOIN position_revisions p
                ON p.group_id=r.group_id AND p.company_id=r.company_id AND p.position_id=r.position_id AND p.id<r.id
                AND (r.effective_until IS NULL OR p.effective_from<r.effective_until) AND (p.effective_until IS NULL OR r.effective_from<p.effective_until)
                WHERE r.group_id=? AND r.company_id=? ORDER BY r.id,p.id LIMIT 100',$scope);
            $closures=$this->db->rows('SELECT run_id,digest FROM payroll_closures WHERE group_id=? AND company_id=? ORDER BY run_id',$scope);
            $hash=hash_init('sha256');
            $slots=$this->db->run('SELECT id,position_id,budget_id,offer_id,starts_on,ends_before,released_at FROM headcount_slots
                WHERE group_id=? AND company_id=? ORDER BY id',$scope);
            while ($row=$slots->fetch()) hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");
            return ['counts'=>$counts,'conflicts'=>$conflicts,'closure_digests'=>$closures,'occupancy_digest'=>hash_final($hash)];
        });
    }

    public function run(Access $actor,int $company,string $action,int $limit=100): array
    {
        $actor->company($company,'hr');
        if ($action==='preflight') return $this->preflight($actor,$company);
        $scope=[$actor->groupId,$company];
        return $this->db->transaction(function () use ($actor,$company,$scope,$action,$limit) {
            $pilot=$this->db->row('SELECT state,last_revision_id FROM workforce_rollouts WHERE group_id=? AND company_id=? FOR UPDATE',$scope);
            if (!$pilot) throw new BusinessError(409,'rollout_not_initialized');
            $select='SELECT r.id'.$this->differences().' ORDER BY r.id';
            $count=0;
            if ($action==='backfill') {
                if ($pilot['state']!=='setup') throw new BusinessError(409,'rollout_already_started');
                $ids=array_column($this->db->rows($select.' LIMIT '.Input::integer($limit,'limit',1,500),$scope),'id');
                foreach ($ids as $id) {
                    $r=$this->db->row('SELECT group_id,company_id,position_id,id,department_id,effective_from,effective_until,title,active
                        FROM position_revisions WHERE group_id=? AND company_id=? AND id=? FOR SHARE',[...$scope,$id]);
                    $this->db->run('INSERT INTO workforce_position_baselines (group_id,company_id,position_id,revision_id,department_id,effective_from,effective_until,title,active)
                        VALUES (?,?,?,?,?,?,?,?,?) AS n ON DUPLICATE KEY UPDATE department_id=n.department_id,effective_from=n.effective_from,
                        effective_until=n.effective_until,title=n.title,active=n.active',array_values($r));
                }
                if ($ids) {
                    $pilot['last_revision_id']=max($pilot['last_revision_id'],max($ids));
                    $this->db->run('UPDATE workforce_rollouts SET last_revision_id=? WHERE group_id=? AND company_id=?',[$pilot['last_revision_id'],...$scope]);
                }
                $count=count($ids);
            } elseif ($action==='activate' || $action==='drain') {
                $target=$action==='activate'?'active':'draining';
                if ($pilot['state']==='setup' && ($action==='drain' || $this->db->row($select.' LIMIT 1',$scope) || $this->preflight($actor,$company)['conflicts']))
                    throw new BusinessError(409,'rollout_baseline_incomplete');
                if ($pilot['state']!==$target) {
                    $this->db->run('UPDATE workforce_rollouts SET state=? WHERE group_id=? AND company_id=?',[$target,...$scope]);
                    (new Operations($this->db))->audit($actor,$company,'rollout',$company,'rollout.'.$action,['previous'=>$pilot['state']]);
                    $pilot['state']=$target;
                }
            } elseif ($action!=='status') throw new BusinessError(422,'unknown_rollout_command');
            $pending=[];
            foreach (['budgets'=>"SELECT id FROM workforce_budget_requests WHERE group_id=? AND company_id=? AND state IN ('submitted','reviewed','adjustment')",
                'reviews'=>"SELECT id FROM skill_evidence WHERE group_id=? AND company_id=? AND state='submitted'",
                'applications'=>"SELECT id FROM internal_applications WHERE group_id=? AND company_id=? AND state IN ('applied','reviewed')",
                'moves'=>"SELECT id FROM internal_moves WHERE group_id=? AND (company_id=? OR source_company_id=?) AND state IN ('awaiting_signatures','scheduled')"] as $label=>$sql)
                $pending[$label]=array_column($this->db->rows($sql.' ORDER BY id',$label==='moves'?[...$scope,$company]:$scope),'id');
            return $pilot+['copied'=>$count,'remaining_baseline'=>$pilot['state']==='setup'?$this->db->row('SELECT COUNT(*) n'.$this->differences(),$scope)['n']:0,'pending'=>$pending];
        });
    }
}
