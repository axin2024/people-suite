<?php

declare(strict_types=1);

namespace PeopleSuite;

final class TalentSkills
{
    public function __construct(private readonly Database $db, private readonly Operations $ops)
    {
    }

    public function today(Access $actor,int $company): string
    {
        $clock=$this->db->row('SELECT timezone,UTC_TIMESTAMP() utc_now FROM companies WHERE group_id=? AND id=?',[$actor->groupId,$company])
            ?? throw new BusinessError(404,'company_not_found');
        return (new \DateTimeImmutable($clock['utc_now'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($clock['timezone']))->format('Y-m-d');
    }

    public function employee(Access $actor,int $company): array
    {
        $actor->company($company,'employee');
        $day=$this->today($actor,$company);
        return $this->db->row('SELECT e.id employee_id,w.id employment_id,e.display_name FROM employees e JOIN employments w
            ON w.group_id=e.group_id AND w.employee_id=e.id WHERE e.group_id=? AND e.account_id=? AND w.company_id=?
            AND w.starts_on<=? AND (w.ends_before IS NULL OR w.ends_before>?) ORDER BY w.starts_on DESC LIMIT 1',
            [$actor->groupId,$actor->accountId,$company,$day,$day]) ?? throw new BusinessError(404,'employee_profile_not_found');
    }

    private function visibility(mixed $scope): string
    {
        if (!in_array($scope,['private','company','group'],true)) {
            throw new BusinessError(422,'invalid_field',['field'=>'visibility']);
        }
        return $scope;
    }

    public function add(Access $actor,int $company,string $key,array $input): array
    {
        $person=$this->employee($actor,$company);
        $code=strtoupper(Input::code($input['skill'] ?? null,'skill'));
        $title=Input::text($input['title'] ?? null,'title',160);
        $ref=Input::text($input['evidence_ref'] ?? null,'evidence_ref',400);
        $reviewer=Input::integer($input['reviewer_id'] ?? null,'reviewer_id');
        $scope=$this->visibility($input['visibility'] ?? 'private');
        return $this->ops->once($actor,$company,'skill.add',$key,$input,function () use ($actor,$company,$person,$code,$title,$ref,$reviewer,$scope) {
            if ($reviewer===$actor->accountId || !$this->db->row("SELECT 1 FROM accounts a JOIN company_roles r ON r.group_id=a.group_id AND r.account_id=a.id
                WHERE a.group_id=? AND a.id=? AND a.enabled=1 AND r.company_id=? AND r.role='hr'",[$actor->groupId,$reviewer,$company])) {
                throw new BusinessError(422,'independent_skill_reviewer_required',['field'=>'reviewer_id']);
            }
            $id=$this->db->insert('INSERT INTO skill_evidence (group_id,company_id,employee_id,employment_id,skill,title,evidence_ref,reviewer_id,visibility)
                VALUES (?,?,?,?,?,?,?,?,?)',[$actor->groupId,$company,$person['employee_id'],$person['employment_id'],$code,$title,$ref,$reviewer,$scope]);
            $result=['evidence_id'=>$id,'revision'=>1,'state'=>'submitted','visibility'=>$scope];
            $this->ops->audit($actor,$company,'skill_evidence',$id,'submitted',$result);
            return $result;
        });
    }

    public function change(Access $actor,int $company,int $id,string $action,string $key,array $input): array
    {
        $review=$action==='review';
        if ($review) {
            $actor->company($company,'hr');
        } else {
            $person=$this->employee($actor,$company);
        }
        $revision=Input::integer($input['revision'] ?? null,'revision');
        $who=$review?$actor->accountId:$person['employee_id'];
        $scope=[$actor->groupId,$company,$id,$who];
        $sql='SELECT * FROM skill_evidence WHERE group_id=? AND company_id=? AND id=? AND '.($review?'reviewer_id':'employee_id').'=?';
        if (!$this->db->row($sql,$scope)) throw new BusinessError(404,'evidence_not_found');
        return $this->ops->once($actor,$company,'skill.'.$action,$key,['id'=>$id,'data'=>$input],function () use ($actor,$company,$id,$revision,$input,$review,$sql,$scope) {
            $row=$this->db->row($sql.' FOR UPDATE',$scope);
            if (!$row || $row['revision']!==$revision) throw new BusinessError(409,'skill_revision_changed');
            if ($review) {
                $choice=$input['decision'] ?? null;
                if (!in_array($choice,['verified','rejected'],true) || $row['state']!=='submitted') throw new BusinessError(409,'skill_review_not_pending');
                $until=$choice==='verified'?Input::date($input['verified_until'] ?? null,'verified_until'):null;
                $day=$this->today($actor,$company);
                if ($until!==null && $until<$day) throw new BusinessError(422,'invalid_date',['field'=>'verified_until']);
                $reason=Input::text($input['notes'] ?? null,'notes',400);
                $this->db->run('UPDATE skill_evidence SET state=?,verified_from=?,verified_until=?,review_notes=?,revision=revision+1 WHERE id=?',
                    [$choice,$until!==null?$day:null,$until,$reason,$id]);
            } else {
                $this->db->run('UPDATE skill_evidence SET visibility=?,revision=revision+1 WHERE id=?',[$this->visibility($input['visibility'] ?? null),$id]);
            }
            $result=$this->db->row('SELECT id evidence_id,revision,state,visibility,verified_until FROM skill_evidence WHERE id=?',[$id]);
            $this->ops->audit($actor,$company,'skill_evidence',$id,$review?'reviewed':'visibility_changed',$result);
            return $result;
        });
    }

    public function inbox(Access $actor,int $company,string $mode,int $after): array
    {
        if ($mode==='mine') {
            $p=$this->employee($actor,$company);
            $where='employee_id=?';
            $who=$p['employee_id'];
        } else {
            $actor->company($company,'hr');
            $where="reviewer_id=? AND state='submitted'";
            $who=$actor->accountId;
        }
        $today=$this->today($actor,$company);
        return ['items'=>$this->db->rows("SELECT id,skill,title,evidence_ref,visibility,state,revision,verified_until,review_notes,reviewer_id,
            CASE WHEN verified_until IS NULL THEN NULL ELSE DATEDIFF(verified_until,?) END remaining_days FROM skill_evidence
            WHERE group_id=? AND company_id=? AND $where AND id>? ORDER BY id LIMIT 100",[$today,$actor->groupId,$company,$who,$after])];
    }

    public function search(Access $actor,int $company,string $code,int $after): array
    {
        $actor->company($company,'hr');
        $day=$this->today($actor,$company);
        return ['items'=>$this->db->rows("SELECT s.id,s.skill,s.title,s.verified_until,e.display_name FROM skill_evidence s
            JOIN employees e ON e.group_id=s.group_id AND e.id=s.employee_id
            JOIN employments w ON w.group_id=s.group_id AND w.company_id=s.company_id AND w.id=s.employment_id
            WHERE s.group_id=? AND s.skill=? AND s.state='verified' AND s.verified_from<=? AND s.verified_until>=?
            AND (s.visibility='group' OR (s.visibility='company' AND s.company_id=?))
            AND w.starts_on<=? AND (w.ends_before IS NULL OR w.ends_before>?) AND s.id>? ORDER BY s.id LIMIT 100",
            [$actor->groupId,Input::code($code,'skill'),$day,$day,$company,$day,$day,$after])];
    }

    public function qualifications(int $group,int $sourceCompany,int $employee,int $targetCompany,string $day): array
    {
        return $this->db->rows("SELECT id evidence_id,skill,title,evidence_ref,verified_until FROM skill_evidence
            WHERE group_id=? AND company_id=? AND employee_id=? AND state='verified' AND verified_from<=? AND verified_until>=?
            AND (visibility='group' OR (visibility='company' AND company_id=?))
            ORDER BY skill,verified_until DESC,id DESC",[$group,$sourceCompany,$employee,$day,$day,$targetCompany]);
    }
}
