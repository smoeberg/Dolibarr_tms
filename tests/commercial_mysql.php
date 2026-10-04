<?php
require_once __DIR__.'/../htdocs/custom/training/class/trainingcommercialservice.class.php';
// Earlier synthetic fixtures intentionally use a minimal native third-party table.
$db->query('ALTER TABLE tst_societe ADD nom varchar(128), ADD status tinyint DEFAULT 1');
$db->query("INSERT INTO tst_societe (rowid,entity,nom,status) VALUES (300,1,'Buyer æøå',1),(301,2,'Payer',1),(302,1,'Employer',1),(303,3,'Foreign',1),(304,1,'Inactive',0),(305,1,'Literal_%',1)");
$schema=str_replace('llx_','tst_',file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_zcommercial.sql'));
for ($i=0;$i<2;$i++) {
    $db->connection->multi_query($schema);
    do { if ($r=$db->connection->store_result()) { $r->free(); } } while ($db->connection->more_results() && $db->connection->next_result());
}
$commercial=new TrainingCommercialService($seatStore);
$id=seatSession('COMMERCIAL-BASICS',2); $enrollment=$seatBooking->confirm($id,21);
$empty=array('buyer'=>null,'payer'=>null,'employer'=>null); $links=array('buyer'=>300,'payer'=>301,'employer'=>302);
verify($commercial->detail($id,$enrollment)['links']===$empty,'new enrollment leaves all roles explicitly unregistered');
verify($commercial->change($id,$enrollment,0,$links,'Explicit B2B roles')===1,'distinct native buyer/payer/employer saved');
$detail=$commercial->detail($id,$enrollment);
verify($detail['parties']['buyer']->nom==='Buyer æøå' && $detail['links']===$links,'role names come from native third party table');
$db->query("UPDATE tst_societe SET nom='Renamed native buyer' WHERE rowid=300");
verify($commercial->detail($id,$enrollment)['parties']['buyer']->nom==='Renamed native buyer','native name changes appear without duplicate module company fields');
$before=$db->count('training_audit');
verify($commercial->change($id,$enrollment,1,array_reverse($links,true),'Retry')===1 && $db->count('training_audit')===$before,'same role links are a no-op regardless of array key order');
seatReject(fn()=>$commercial->change($id,$enrollment,0,$empty,'Stale'),'TrainingCommercialConflict');
seatReject(fn()=>$commercial->change($id,$enrollment,1,$empty,''),'TrainingCommercialReasonRequired');
seatReject(fn()=>$commercial->change($id,$enrollment,1,array('buyer'=>'300','payer'=>null,'employer'=>null),'Invalid'),'TrainingCommercialInvalid');
seatReject(fn()=>$commercial->change($id,$enrollment,1,array('buyer'=>300,'payer'=>null),'Missing key'),'TrainingCommercialInvalid');
seatReject(fn()=>$commercial->change($id,$enrollment,1,array('buyer'=>303,'payer'=>null,'employer'=>null),'Foreign'),'TrainingThirdpartyNotAccessible');
seatReject(fn()=>$commercial->change($id,$enrollment,1,array('buyer'=>304,'payer'=>null,'employer'=>null),'Inactive'),'TrainingThirdpartyNotAccessible');
verify(count($commercial->choices('Literal_%'))===1,'search treats percent and underscore literally');
verify(count($commercial->choices('Foreign'))===0 && count($commercial->choices('Inactive'))===0,'choices exclude foreign and inactive native parties');
$other=seatSession('COMMERCIAL-WRONG-SESSION',1);
seatReject(fn()=>$commercial->detail($other,$enrollment),'TrainingCommercialEnrollmentNotFound');
seatReject(fn()=>(new TrainingCommercialService(bookingStore($db,1)))->detail($id,$enrollment),'TrainingSessionNotFound');
class CommercialUser extends MysqlTestUser {
    public bool $write=true; public bool $correct=true; public bool $read=true; public bool $all=true; public bool $nativeRead=true;
    public function hasRight($module,...$keys) {
        if ($module==='training' && $keys[0]==='commercial') { return $this->{$keys[1]}; }
        if ($module==='societe' && $keys===array('client','voir')) { return $this->all; }
        if ($module==='societe' && $keys===array('lire')) { return $this->nativeRead; }
        return parent::hasRight($module,...$keys);
    }
}
$userLimited=new CommercialUser(); $userLimited->all=false;
$limited=new TrainingCommercialService(bookingStore($db,2,$userLimited));
seatReject(fn()=>$limited->detail($id,$enrollment),'TrainingThirdpartyNotAccessible');
seatReject(fn()=>$limited->change($id,$enrollment,1,$empty,'Clear inaccessible links'),'TrainingThirdpartyNotAccessible');
verify(count($limited->choices(''))===0,'restricted commercial user sees only assigned native customers');
$db->query('INSERT INTO tst_societe_commerciaux (fk_soc,fk_user) VALUES (300,7),(301,7),(302,7)');
verify($limited->detail($id,$enrollment)['revision']===1 && count($limited->choices(''))===3,'native sales representative assignment grants scoped role access');
$userLimited->correct=false;
seatReject(fn()=>$limited->change($id,$enrollment,1,$empty,'Unauthorized correction'),'TrainingAccessDenied');
$second=$seatBooking->confirm($id,22);
verify($limited->change($id,$second,0,array('buyer'=>300,'payer'=>300,'employer'=>null),'First registration')===1,'write without correct permits first registration and same buyer/payer');
$userLimited->write=false;
seatReject(fn()=>$limited->change($id,$enrollment,1,$empty,'Readonly'),'TrainingAccessDenied');
$userLimited->nativeRead=false;
seatReject(fn()=>$limited->detail($id,$enrollment),'TrainingThirdpartyNotAccessible');
$userLimited->nativeRead=true; $userLimited->read=false;
seatReject(fn()=>$limited->detail($id,$enrollment),'TrainingAccessDenied');
seatReject(fn()=>(new TrainingCommercialService(bookingStore($db,2,new OwnAttendanceUser())))->detail($id,$enrollment),'TrainingAccessDenied');
$db->failAudit=true;
seatReject(fn()=>$commercial->change($id,$enrollment,1,$empty,'Audit fails'),'TrainingDatabaseError');
$db->failAudit=false;
verify($commercial->detail($id,$enrollment)['revision']===1 && $commercial->detail($id,$enrollment)['links']===$links,'failed correction audit rolls back all links and revision');
$fresh=seatSession('COMMERCIAL-INSERT-ROLLBACK',1); $freshEnrollment=$seatBooking->confirm($fresh,21);
$count=$db->count('training_enrollment_commercial'); $db->failAudit=true;
seatReject(fn()=>$commercial->change($fresh,$freshEnrollment,0,$links,'Fail first audit'),'TrainingDatabaseError');
$db->failAudit=false;
verify($db->count('training_enrollment_commercial')===$count && $commercial->detail($fresh,$freshEnrollment)['revision']===0,'failed first audit rolls back inserted commercial record');
$db->query('UPDATE tst_societe SET status=0 WHERE rowid=300');
verify((int) $commercial->detail($id,$enrollment)['parties']['buyer']->status===0,'inactive existing link remains readable');
$changed=$links; $changed['payer']=302;
verify($commercial->change($id,$enrollment,1,$changed,'Keep historical inactive buyer')===2,'correction can retain inactive existing role');
$changed['payer']=300;
seatReject(fn()=>$commercial->change($id,$enrollment,2,$changed,'Assign inactive party to another role'),'TrainingThirdpartyNotAccessible');
$db->query('UPDATE tst_societe SET status=1 WHERE rowid=300');
verify($commercial->change($id,$enrollment,2,$empty,'Clear all roles')===3,'roles can be explicitly cleared with correction rights');
verify(count($commercial->history($id,$enrollment))===3,'history retains before/after IDs, actor, reason and revisions');
$userLimited->read=true;
$db->query('DELETE FROM tst_societe_commerciaux WHERE fk_soc=302 AND fk_user=7');
verify($limited->detail($id,$enrollment)['links']===$empty,'cleared current links can be read');
seatReject(fn()=>$limited->history($id,$enrollment),'TrainingThirdpartyNotAccessible');
$seatBooking->cancel($id,$enrollment,'Cancel commercial enrollment');
verify($commercial->detail($id,$enrollment)['revision']===3,'cancellation retains commercial revision and history');
seatReject(fn()=>$commercial->change($id,$enrollment,3,$links,'Edit canceled'),'TrainingCommercialConfirmedRequired');
$seatBooking->confirm($id,21);
verify($commercial->detail($id,$enrollment)['revision']===3,'explicit reconfirmation retains same enrollment role history');
for ($round=1;$round<=3;$round++) {
    $race=seatSession('COMMERCIAL-RACE-'.$round,1); $re=$seatBooking->confirm($race,21);
    $results=raceBookings($race,array(array('commercial',300),array('commercialalias',301)));
    verify(count(array_filter($results,fn($r)=>$r['status']==='changed'))===1 && $commercial->detail($race,$re)['revision']===1,'concurrent first role registration has one revision winner round '.$round);
    $results=raceBookings($race,array(array('commercialcorrect',300),array('commercialcorrectalias',301)));
    // One request may be an exact retry if it chose the first winner. Both may return success, but only one actual mutation.
    verify($commercial->detail($race,$re)['revision']===2 && count($commercial->history($race,$re))===2,'concurrent revision-one correction never overwrites unseen change round '.$round);
}
echo 'Commercial role links, ACL, audit rollback and concurrency tests passed.'.PHP_EOL;
