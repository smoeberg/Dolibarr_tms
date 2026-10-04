<?php
require_once __DIR__.'/../htdocs/custom/training/class/trainingreportingservice.class.php';
$reporting=new TrainingReportingService(bookingStore($db));
$a=seatSession('REPORT-A',8); $b=seatSession('REPORT-B',2);
$seatBooking->confirm($a,21); $seatBooking->reserve($a,array(22,23),seatKey('report-active'));
$expired=$seatBooking->reserve($a,array(25),seatKey('report-expired')); expireSeat($expired);
$seatBooking->confirm($b,24); $seatSchedule->changeStatus($b,'closed');
$f=new TrainingReportFilter(array('status'=>'','search'=>'REPORT-','from'=>'2026-10-20','to'=>'2026-10-20'));
$r=$reporting->sessions($f);
verify($r['totals']['sessions']===2 && $r['totals']['confirmed']===2 && $r['totals']['reserved']===2 && $r['totals']['capacity']===10 && $r['totals']['available']===6,'report totals exclude expired holds and do not multiply seats by slots or group members');
verify($r['totals']['occupancy_percent']===40.0,'report occupancy uses total occupied over total capacity, not mean percentages');
foreach ($r['rows'] as $row) {
    $detail=$seatSchedule->detail((int) $row->rowid);
    verify($row->confirmed===$detail['occupied'] && $row->reserved===$detail['reserved'] && $row->available===$detail['available'],'report row agrees with session capacity detail');
}
$r=$reporting->sessions(new TrainingReportFilter(array('search'=>'REPORT-')));
verify($r['totals']['sessions']===1 && $r['rows'][0]->ref==='REPORT-A','default report scope includes only open sessions');
$r=$reporting->sessions(new TrainingReportFilter(array('status'=>'','search'=>'REPORT-','from'=>'2026-10-21','to'=>'2026-10-21')));
verify($r['totals']['sessions']===0 && $r['totals']['occupancy_percent']===null,'period is first start, not any teaching block; empty scope has no percentage');
$draft=$seatSchedule->create($first,'REPORT-DRAFT','Unscheduled',1,'Europe/Copenhagen');
$r=$reporting->sessions(new TrainingReportFilter(array('status'=>'','time'=>'unscheduled','search'=>'REPORT-')));
verify(count($r['rows'])===1 && (int) $r['rows'][0]->rowid===$draft,'unscheduled time status is distinct from workflow');
// Time cases relative to database clock, independent of the test run date.
$db->query('UPDATE tst_training_session_slot SET start_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR),end_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE fk_session='.$b);
$r=$reporting->sessions(new TrainingReportFilter(array('status'=>'closed','time'=>'finished','search'=>'REPORT-B')));
verify($r['totals']['sessions']===1,'closed workflow and finished time are independent filters');
$db->query('UPDATE tst_training_session_slot SET start_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),end_utc=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE fk_session='.$b);
$r=$reporting->sessions(new TrainingReportFilter(array('status'=>'closed','time'=>'ongoing','search'=>'REPORT-B')));
verify($r['totals']['sessions']===1,'ongoing spans first to last block even if registration is closed');
$db->query('UPDATE tst_training_session_slot SET start_utc=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),end_utc=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE fk_session='.$b);
$r=$reporting->sessions(new TrainingReportFilter(array('status'=>'closed','time'=>'upcoming','search'=>'REPORT-B')));
verify($r['totals']['sessions']===1,'upcoming is computed from database UTC clock');
$foreign=new TrainingReportingService(bookingStore($db,1));
verify($foreign->sessions(new TrainingReportFilter(array('status'=>'','search'=>'REPORT-')))['totals']['sessions']===0,'entity boundary applies to report totals and rows');
$restrictedStore=new TrainingStore($db,new TrainingAccess(new MysqlTestUser(),2,'2'),fn($id)=>null,fn($id)=>null);
verify((new TrainingReportingService($restrictedStore))->sessions(new TrainingReportFilter(array('status'=>'','search'=>'REPORT-')))['totals']['sessions']===0,'shared service is excluded unless standard product entity is authorized');
$db->query('UPDATE tst_product SET fk_product_type=0 WHERE rowid=11');
verify($reporting->sessions(new TrainingReportFilter(array('status'=>'','search'=>'REPORT-')))['totals']['sessions']===0,'non-service standard product cannot appear in course report');
$db->query('UPDATE tst_product SET fk_product_type=1 WHERE rowid=11');
class NoReportEnrollmentRead extends MysqlTestUser {
    public function hasRight($module,...$keys) { return !($module==='training' && $keys===array('enrollment','read')); }
}
seatReject(fn()=>(new TrainingReportingService(bookingStore($db,2,new NoReportEnrollmentRead())))->sessions($f),'TrainingAccessDenied');
seatReject(fn()=>(new TrainingReportingService(bookingStore($db,2,new OwnAttendanceUser())))->sessions($f),'TrainingAccessDenied');
$db->query('UPDATE tst_training_session SET capacity=1 WHERE rowid='.$a);
seatReject(fn()=>$reporting->sessions(new TrainingReportFilter(array('search'=>'REPORT-A'))),'TrainingReportInvariantError');
$db->query('UPDATE tst_training_session SET capacity=8 WHERE rowid='.$a);
// One bounded result set is paginated after totals: page 2 must retain whole-scope totals.
$values=array();
for ($n=1;$n<=51;$n++) { $values[]="(2,'REPORT-PAGE-".$n."','Page test',".$first.",1,'Europe/Copenhagen',UTC_TIMESTAMP(),7)"; }
$db->query('INSERT INTO tst_training_session (entity,ref,label,fk_course_version,capacity,timezone,datec,fk_user_author) VALUES '.implode(',',$values));
$f=new TrainingReportFilter(array('status'=>'','search'=>'REPORT-PAGE-'));
$p1=$reporting->sessions($f,1); $p2=$reporting->sessions($f,2);
verify(count($p1['rows'])===50 && count($p2['rows'])===1 && $p1['totals']===$p2['totals'] && $p2['totals']['sessions']===51,'pagination preserves filtered whole-scope totals without truncation');
parse_str($f->query(2),$navigation);
verify((new TrainingReportFilter($navigation))->search===$f->search && $navigation['page']==='2','navigation serializes the same validated filter');
for ($chunk=0;$chunk<6;$chunk++) {
    $values=array();
    for ($n=$chunk*1000+1;$n<=min(5001,($chunk+1)*1000);$n++) { $values[]="(2,'REPORT-LIMIT-".$n."','Limit test',".$first.",1,'Europe/Copenhagen',UTC_TIMESTAMP(),7)"; }
    if ($values) { $db->query('INSERT INTO tst_training_session (entity,ref,label,fk_course_version,capacity,timezone,datec,fk_user_author) VALUES '.implode(',',$values)); }
}
seatReject(fn()=>$reporting->sessions(new TrainingReportFilter(array('status'=>'','search'=>'REPORT-LIMIT-'))),'TrainingReportTooBroad');
$db->query("DELETE FROM tst_training_session WHERE entity=2 AND ref LIKE 'REPORT-LIMIT-%'");
echo 'All session reporting MySQL tests passed.'.PHP_EOL;
