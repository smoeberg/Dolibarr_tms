<?php
require_once __DIR__.'/../htdocs/custom/training/class/trainingtrainerservice.class.php';
$trainers=new TrainingTrainerService($store);
$ownUser=new OwnAttendanceUser();$ownStore=bookingStore($db,2,$ownUser);
$ownAttendance=new TrainingAttendanceService($ownStore);
$trainerSession=$scheduling->create($first,'TRAINER-01','Assigned session',1,'Europe/Copenhagen');
$scheduling->replaceSlots($trainerSession,$slots);$scheduling->changeStatus($trainerSession,'open');
$trainerEnrollment=$enrollments->confirm($trainerSession,26);
$trainerSlot=(int) $scheduling->detail($trainerSession)['slots'][0]->rowid;
attendanceReject(fn()=>$ownAttendance->sheet($trainerSession,$trainerSlot),'TrainingAccessDenied');
attendanceReject(fn()=>$trainers->change($trainerSession,10,0,'active','Other entity'),'TrainingTrainerUserNotAccessible');
attendanceReject(fn()=>$trainers->change($trainerSession,11,0,'active','Disabled'),'TrainingTrainerInactive');
attendanceReject(fn()=>$trainers->change($trainerSession,12,0,'active','External'),'TrainingTrainerInactive');
attendanceReject(fn()=>(new TrainingTrainerService($ownStore))->change($trainerSession,8,0,'active','Self assignment'),'TrainingAccessDenied');
$assignment=$trainers->change($trainerSession,8,0,'active','Lead instructor');
$count=$db->count('training_audit');
verify($trainers->change($trainerSession,8,1,'active','Repeated current request')===$assignment && $db->count('training_audit')===$count,'assignment repeat is idempotent without duplicate profile or audit');
attendanceReject(fn()=>$trainers->change($trainerSession,8,0,'revoked','Stale manager form'),'TrainingTrainerConflict');
verify(count($ownAttendance->mySessions())===1 && count($ownAttendance->sheet($trainerSession,$trainerSlot)['rows'])===1,'own-only instructor can read assigned hold without global session/attendance rights');
verify($ownAttendance->permissions($trainerSession)===array('read'=>true,'write'=>true,'correct'=>true),'own-scope permissions expose allowed actions');
attendanceReject(fn()=>$ownAttendance->sheet($sessionId,$slotId),'TrainingAccessDenied');
attendanceReject(fn()=>$ownAttendance->history($sessionId,$slotId,$enrollment1),'TrainingAccessDenied');
attendanceReject(fn()=>$ownAttendance->record($sessionId,$slotId,$enrollment1,4,array('status'=>'present'),'Unauthorized hold'),'TrainingAccessDenied');
attendanceReject(fn()=>(new TrainingBillingService($ownStore,'DKK'))->participants($trainerSession),'TrainingAccessDenied');
$ownAttendance->record($trainerSession,$trainerSlot,$trainerEnrollment,0,array('status'=>'present'));
$ownUser->canCorrect=false;
attendanceReject(fn()=>$ownAttendance->record($trainerSession,$trainerSlot,$trainerEnrollment,1,array('status'=>'absent'),'No correction right'),'TrainingAccessDenied');
$ownUser->canCorrect=true;$ownUser->canWrite=false;
attendanceReject(fn()=>$ownAttendance->record($trainerSession,$trainerSlot,$trainerEnrollment,1,array('status'=>'absent'),'Read only'),'TrainingAccessDenied');
$ownUser->canWrite=true;
$ownAttendance->record($trainerSession,$trainerSlot,$trainerEnrollment,1,array('status'=>'absent'),'Corrected from attendance sheet');
$historyCount=count($ownAttendance->history($trainerSession,$trainerSlot,$trainerEnrollment));
$db->failAudit=true;
attendanceReject(fn()=>$trainers->change($trainerSession,8,1,'revoked','Rollback removal'),'TrainingDatabaseError');
$db->failAudit=false;
verify(count($ownAttendance->mySessions())===1,'failed revocation audit leaves assignment and instructor access active');
$trainers->change($trainerSession,8,1,'revoked','Instructor replaced');
verify(count($ownAttendance->mySessions())===0,'revoked instructor has no assigned sessions');
attendanceReject(fn()=>$ownAttendance->sheet($trainerSession,$trainerSlot),'TrainingAccessDenied');
attendanceReject(fn()=>$ownAttendance->history($trainerSession,$trainerSlot,$trainerEnrollment),'TrainingAccessDenied');
attendanceReject(fn()=>$ownAttendance->record($trainerSession,$trainerSlot,$trainerEnrollment,2,array('status'=>'present'),'After revoke'),'TrainingAccessDenied');
verify(count((new TrainingAttendanceService($store))->history($trainerSession,$trainerSlot,$trainerEnrollment))===$historyCount,'coordinator retains historical attendance after assignment removal');
$trainers->change($trainerSession,8,2,'active','Reassigned for follow-up');
$scheduling->changeStatus($trainerSession,'closed');
verify(count($ownAttendance->history($trainerSession,$trainerSlot,$trainerEnrollment))===$historyCount,'active assignment retains historical access on closed sessions');
$db->query('UPDATE tst_user SET statut=0 WHERE rowid=8');
attendanceReject(fn()=>$ownAttendance->sheet($trainerSession,$trainerSlot),'TrainingAccessDenied');
verify(count($ownAttendance->mySessions())===0,'disabled standard user loses own-session access');
$db->query('UPDATE tst_user SET statut=1 WHERE rowid=8');
$db->query('UPDATE tst_training_trainer SET active=0 WHERE fk_user=8 AND entity=2');
attendanceReject(fn()=>$ownAttendance->sheet($trainerSession,$trainerSlot),'TrainingAccessDenied');
$db->query('UPDATE tst_training_trainer SET active=1 WHERE fk_user=8 AND entity=2');
$otherUser=new OwnAttendanceUser();$otherUser->id=9;
attendanceReject(fn()=>(new TrainingAttendanceService(bookingStore($db,2,$otherUser)))->sheet($trainerSession,$trainerSlot),'TrainingAccessDenied');
attendanceReject(fn()=>(new TrainingAttendanceService(bookingStore($db,1,$ownUser)))->sheet($trainerSession,$trainerSlot),'TrainingAccessDenied');
class NoStandardTrainerUserRead extends MysqlTestUser {
    public function hasRight($module,...$keys) { return !($module==='user' && $keys===array('user','lire')); }
}
attendanceReject(fn()=>(new TrainingTrainerService(bookingStore($db,2,new NoStandardTrainerUserRead())))->choices('trainer'),'TrainingTrainerUserNotAccessible');
class RestrictedOwnContactUser extends OwnAttendanceUser {
    public function hasRight($module,...$keys) { return !($module==='societe' && $keys===array('client','voir')) && parent::hasRight($module,...$keys); }
}
$restrictedSession=$scheduling->create($first,'TRAINER-CONTACT','Restricted customer',1,'Europe/Copenhagen');
$scheduling->replaceSlots($restrictedSession,$slots);$scheduling->changeStatus($restrictedSession,'open');$enrollments->confirm($restrictedSession,32);
$restrictedSlot=(int) $scheduling->detail($restrictedSession)['slots'][0]->rowid;
$trainers->change($restrictedSession,8,0,'active','Restricted-contact hold');
verify(count((new TrainingAttendanceService(bookingStore($db,2,new RestrictedOwnContactUser())))->sheet($restrictedSession,$restrictedSlot)['rows'])===0,'assignment never bypasses standard contact/customer access');
for ($round=1;$round<=3;$round++) {
    $raceSession=$scheduling->create($first,'TRAINER-RACE-'.$round,'Revocation race',1,'Europe/Copenhagen');
    $scheduling->replaceSlots($raceSession,$slots);$scheduling->changeStatus($raceSession,'open');$enrollments->confirm($raceSession,26);$trainers->change($raceSession,8,0,'active','Race setup');
    $result=raceBookings($raceSession,array(array('trainerrecord',8),array('trainerrevoke',8)));
    verify(count(array_filter($result,fn($r)=>$r['status']==='revoked'))===1 && count(array_filter($result,fn($r)=>in_array($r['status'],array('recorded','rejected'),true)))===1,'attendance versus assignment removal round '.$round.' serializes');
    $raceSlot=(int) $scheduling->detail($raceSession)['slots'][0]->rowid;
    attendanceReject(fn()=>$ownAttendance->sheet($raceSession,$raceSlot),'TrainingAccessDenied');
    // If recording succeeded, its audit necessarily precedes the removal audit under the mutex.
    $events=$db->query("SELECT object_type,action FROM tst_training_audit WHERE (object_type='trainer_assignment' AND fk_object=(SELECT rowid FROM tst_training_trainer_assignment WHERE fk_session=".$raceSession.")) OR (object_type='attendance' AND fk_object IN (SELECT rowid FROM tst_training_attendance WHERE fk_session=".$raceSession.')) ORDER BY rowid');
    $sequence=array();while ($event=$events->fetch_object()) { $sequence[]=$event->action; }
    verify($sequence===array('assigned','revoked') || $sequence===array('assigned','recorded','revoked'),'no attendance write commits after revocation round '.$round);
}
$db->failAudit=true;
attendanceReject(fn()=>$trainers->change($trainerSession,9,0,'active','Rollback new profile'),'TrainingDatabaseError');
$db->failAudit=false;
verify($db->count('training_trainer')===1,'all session assignments reuse one profile linked to the standard user');
echo 'All trainer assignment/access MySQL tests passed.'.PHP_EOL;
