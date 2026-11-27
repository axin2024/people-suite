<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;
use DateTimeZone;

final class MovementReport
{
    public function __construct(private readonly Database $db) {}

    public function month(Access $actor,int $company,string $period): array
    {
        $actor->company($company,'hr','payroll_reviewer');
        if (!str_ends_with($period,'-01')) throw new BusinessError(422,'invalid_field',['field'=>'period']);
        return $this->db->transaction(function () use ($actor,$company,$period) {
            $scope=[$actor->groupId,$company];
            $clock=$this->db->row('SELECT timezone,UTC_TIMESTAMP() utc_now FROM companies WHERE group_id=? AND id=?',$scope);
            $zone=new DateTimeZone($clock['timezone']);
            $utc=new DateTimeZone('UTC');
            $today=(new DateTimeImmutable($clock['utc_now'],$utc))->setTimezone($zone)->setTime(0,0);
            $start=new DateTimeImmutable($period,$zone);
            $end=min($start->modify('+1 month'),$today->modify('+1 day'));
            $until=$end->format('Y-m-d');
            $departments=array_column($this->db->rows('SELECT id,name FROM departments WHERE group_id=? AND company_id=? AND effective_from<? ORDER BY id',
                [...$scope,$until]),'name','id');
            $rows=[];
            foreach ($departments as $id=>$name) $rows[$id]=['department_id'=>$id,'department'=>$name,'internal_fills'=>0,'external_fills'=>0,
                'assignments_ended'=>0,'vacant_days'=>0,'internal_fill_rate'=>null];
            $fills=$this->db->rows('SELECT a.department_id,COUNT(i.employment_id) external_fills,COUNT(*)-COUNT(i.employment_id) internal_fills
                FROM assignments a LEFT JOIN employment_intakes i ON a.version=1 AND i.group_id=a.group_id AND i.company_id=a.company_id AND i.employment_id=a.employment_id
                WHERE a.group_id=? AND a.company_id=? AND a.effective_from>=? AND a.effective_from<? GROUP BY a.department_id',[...$scope,$period,$until]);
            foreach ($fills as $r) {
                $row=&$rows[$r['department_id']];
                $row['internal_fills']=(int)$r['internal_fills'];
                $row['external_fills']=(int)$r['external_fills'];
                $row['internal_fill_rate']=round($row['internal_fills']/($row['external_fills']+$row['internal_fills']),4);
                unset($row);
            }
            foreach ($this->db->rows('SELECT department_id,COUNT(*) n FROM assignments WHERE group_id=? AND company_id=?
                AND effective_until>=? AND effective_until<? GROUP BY department_id',[...$scope,$period,$until]) as $r) $rows[$r['department_id']]['assignments_ended']=(int)$r['n'];
            $vacancies=[];
            $openings=$this->db->rows("SELECT o.id,o.title,o.department_id,o.created_at,m.effective_date
                FROM internal_openings o LEFT JOIN internal_moves m ON m.opening_id=o.id AND m.state='effective'
                WHERE o.group_id=? AND o.company_id=? AND o.created_at<? AND (m.effective_date IS NULL OR m.effective_date>?)
                ORDER BY o.department_id,o.id",[...$scope,$end->setTimezone($utc)->format('Y-m-d H:i:s'),$period]);
            foreach ($openings as $o) {
                $opened=(new DateTimeImmutable($o['created_at'],$utc))->setTimezone($zone)->setTime(0,0);
                $first=max($opened,$start);
                $last=$o['effective_date']===null?$end:min($end,new DateTimeImmutable($o['effective_date'],$zone));
                if ($first>=$last) continue;
                $days=$first->diff($last)->days;
                $rows[$o['department_id']]['vacant_days']+=$days;
                $vacancies[]=['opening_id'=>$o['id'],'department_id'=>$o['department_id'],'title'=>$o['title'],'from'=>$first->format('Y-m-d'),
                    'ends_before'=>$last->format('Y-m-d'),'vacant_days'=>$days];
            }
            return ['period'=>$period,'as_of'=>$today->format('Y-m-d'),'departments'=>array_values($rows),'vacancies'=>$vacancies];
        },repeatableRead:true);
    }
}
