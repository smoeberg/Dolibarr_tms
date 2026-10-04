<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingreportfilter.class.php';
final class TrainingReportingService
{
    private TrainingStore $s;
    public function __construct(TrainingStore $store) { $this->s=$store; }
    public function sessions(TrainingReportFilter $filter, int $page=1): array {
        $this->s->access->requireDomain('session','read'); $this->s->access->requireDomain('enrollment','read');
        if ($page<1 || $page>1000) { throw new InvalidArgumentException('TrainingInvalidReportFilter'); }
        $entity=$this->s->access->entity(); $p=$this->s->db->prefix(); $t=$p.'training_';
        $time="CASE WHEN b.first_utc IS NULL THEN 'unscheduled' WHEN b.first_utc>UTC_TIMESTAMP() THEN 'upcoming' WHEN b.last_utc>UTC_TIMESTAMP() THEN 'ongoing' ELSE 'finished' END";
        // Separate aggregates prevent slot/member rows multiplying occupancy.
        // One SELECT gives totals and detail rows one InnoDB statement snapshot.
        $sql='SELECT s.rowid,s.ref,s.label,s.status,s.capacity,s.timezone,p.rowid AS product_id,v.product_ref_snapshot AS course_ref,v.product_label_snapshot AS course_label,b.first_utc,b.last_utc,COALESCE(e.confirmed,0) AS confirmed,COALESCE(h.reserved,0) AS reserved,'.$time.' AS time_status,UTC_TIMESTAMP() AS as_of';
        $sql.=' FROM '.$t.'session s JOIN '.$t.'course_version v ON v.rowid=s.fk_course_version AND v.entity=s.entity JOIN '.$t.'course_profile c ON c.rowid=v.fk_course_profile AND c.entity=v.entity JOIN '.$p.'product p ON p.rowid=c.fk_product';
        $sql.=' LEFT JOIN (SELECT fk_session,MIN(start_utc) AS first_utc,MAX(end_utc) AS last_utc FROM '.$t.'session_slot WHERE entity='.$entity.' GROUP BY fk_session) b ON b.fk_session=s.rowid';
        $sql.=' LEFT JOIN (SELECT fk_session,COUNT(*) AS confirmed FROM '.$t."enrollment WHERE entity=".$entity." AND status='confirmed' GROUP BY fk_session) e ON e.fk_session=s.rowid";
        $sql.=' LEFT JOIN (SELECT fk_session,SUM(qty) AS reserved FROM '.$t."seat_hold WHERE entity=".$entity." AND status='active' AND expires_utc>UTC_TIMESTAMP() GROUP BY fk_session) h ON h.fk_session=s.rowid";
        $sql.=' WHERE s.entity='.$entity." AND v.status='published' AND p.fk_product_type=1 AND p.entity IN (".$this->s->access->productEntityScope().')';
        if ($filter->status!=='') { $sql.=' AND s.status='.$this->s->text($filter->status); }
        if ($filter->time!=='') { $sql.=' AND ('.$time.')='.$this->s->text($filter->time); }
        if ($filter->fromUtc!==null) { $sql.=' AND b.first_utc>='.$this->s->text($filter->fromUtc); }
        if ($filter->untilUtc!==null) { $sql.=' AND b.first_utc<'.$this->s->text($filter->untilUtc); }
        if ($filter->search!=='') {
            $like=$this->s->text('%'.$filter->search.'%');
            $sql.=' AND (s.ref LIKE '.$like.' OR s.label LIKE '.$like.' OR v.product_label_snapshot LIKE '.$like.')';
        }
        $rows=$this->s->rows($sql.' ORDER BY b.first_utc DESC,s.rowid DESC LIMIT 5001');
        if (count($rows)>5000) { throw new RuntimeException('TrainingReportTooBroad'); }
        $totals=array('sessions'=>count($rows),'confirmed'=>0,'reserved'=>0,'capacity'=>0,'available'=>0);
        foreach ($rows as $row) {
            $row->confirmed=(int) $row->confirmed; $row->reserved=(int) $row->reserved; $row->capacity=(int) $row->capacity;
            $row->available=$row->capacity-$row->confirmed-$row->reserved;
            if ($row->capacity<1 || $row->available<0) { throw new RuntimeException('TrainingReportInvariantError'); }
            foreach (array('confirmed','reserved','capacity','available') as $key) { $totals[$key]+=$row->$key; }
        }
        $totals['occupancy_percent']=$totals['capacity'] ? round(100*($totals['confirmed']+$totals['reserved'])/$totals['capacity'],2) : null;
        $pages=max(1,(int) ceil(count($rows)/50)); $page=min($page,$pages);
        return array('totals'=>$totals,'rows'=>array_slice($rows,($page-1)*50,50),'page'=>$page,'pages'=>$pages,'as_of'=>$rows ? $rows[0]->as_of : $this->s->decisionTime());
    }
}
