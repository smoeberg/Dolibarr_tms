<?php
// Real MySQL, independent worker connections and the production booking services.
$seatStore=bookingStore($db); $seatBooking=new TrainingEnrollmentService($seatStore); $seatSchedule=new TrainingSchedulingService($seatStore);
$seatSlots=array(array('start'=>'2026-10-20T09:00:00+02:00','end'=>'2026-10-20T16:00:00+02:00'),array('start'=>'2026-10-21T09:00:00+02:00','end'=>'2026-10-21T16:00:00+02:00'));
function seatSession(string $ref, int $capacity): int {
    global $seatSchedule,$first,$seatSlots;
    $id=$seatSchedule->create($first,$ref,$ref,$capacity,'Europe/Copenhagen');
    $seatSchedule->replaceSlots($id,$seatSlots); $seatSchedule->changeStatus($id,'open'); return $id;
}
function seatReject(callable $fn, string $reason): void {
    try { $fn(); }
    catch (RuntimeException|InvalidArgumentException $e) { verify($e->getMessage()===$reason,$reason); return; }
    throw new RuntimeException('Expected rejection '.$reason);
}
function seatKey(string $value): string { return hash('sha256',$value); }
function expireSeat(int $id): void {
    global $db; $db->query('UPDATE tst_training_seat_hold SET expires_utc=UTC_TIMESTAMP() WHERE rowid='.$id);
}
$id=seatSession('SEAT-BASICS',3);
$hold=$seatBooking->reserve($id,array(22,21),seatKey('basics'));
$detail=$seatSchedule->detail($id);
verify($detail['occupied']===0 && $detail['reserved']===2 && $detail['available']===1,'named reservations consume capacity separately from enrollments');
$before=$db->count('training_audit');
$expiry=$seatBooking->reservations($id)[0]->expires_utc;
verify($seatBooking->reserve($id,array(21,22),seatKey('basics'))===$hold,'same normalized reservation key returns original ID');
verify($seatBooking->reservations($id)[0]->expires_utc===$expiry && $db->count('training_audit')===$before,'retry does not extend expiry or add audit');
seatReject(fn()=>$seatBooking->reserve($id,array(23),seatKey('basics')),'TrainingReservationKeyConflict');
seatReject(fn()=>$seatBooking->reserve($id,array(21,22),seatKey('basics'),20),'TrainingReservationKeyConflict');
seatReject(fn()=>$seatBooking->reserve($id,array(21,21),seatKey('duplicate')),'TrainingInvalidReservation');
seatReject(fn()=>$seatBooking->reserve($id,array('23'),seatKey('string')),'TrainingInvalidReservation');
seatReject(fn()=>$seatBooking->reserve($id,array(23),'unsafe-key'),'TrainingInvalidReservation');
seatReject(fn()=>$seatBooking->reserve($id,array(23),seatKey('ttl'),61),'TrainingInvalidReservation');
seatReject(fn()=>$seatBooking->reserve($id,array(21),seatKey('other')),'TrainingParticipantReserved');
seatReject(fn()=>$seatBooking->confirm($id,21),'TrainingParticipantReserved');
seatReject(fn()=>$seatBooking->reserve($id,array(23,24),seatKey('too-many')),'TrainingSessionFull');
seatReject(fn()=>$seatSchedule->changeCapacity($id,1),'TrainingCapacityBelowOccupancy');
$seatBooking->confirm($id,23);
seatReject(fn()=>$seatBooking->reserve($id,array(23),seatKey('already')),'TrainingParticipantAlreadyBooked');
$ids=$seatBooking->confirmReservation($id,$hold,'Coordinator accepts group');
$detail=$seatSchedule->detail($id);
verify(count($ids)===2 && $detail['occupied']===3 && $detail['reserved']===0,'atomic group conversion replaces the hold without double counting');
$before=$db->count('training_audit');
verify($seatBooking->confirmReservation($id,$hold,'Repeated approval')===$ids && $db->count('training_audit')===$before,'conversion retry returns original enrollments without audit duplication');
seatReject(fn()=>$seatBooking->releaseReservation($id,$hold,'Invalid release'),'TrainingInvalidTransition');
$seatBooking->cancel($id,$ids[0],'Cancel one member');
verify($seatBooking->confirmReservation($id,$hold,'Retry after cancellation')===$ids && $seatSchedule->detail($id)['occupied']===2,'old conversion retry never resurrects cancelled enrollment');
$replacement=$seatBooking->reserve($id,array(21),seatKey('rebook'));
verify($seatBooking->confirmReservation($id,$replacement,'Explicit new agreement')[0]===$ids[0],'new reservation can explicitly reconfirm original enrollment');

$id=seatSession('SEAT-EXPIRY',1);
$hold=$seatBooking->reserve($id,array(21),seatKey('expires'));
expireSeat($hold);
verify($seatSchedule->detail($id)['reserved']===0 && $seatBooking->reservations($id)[0]->effective_status==='expired','expiry at decision time frees capacity without a job');
verify($seatBooking->reserve($id,array(21),seatKey('expires'))===$hold && $seatSchedule->detail($id)['reserved']===0,'expired request retry cannot revive hold');
$seatBooking->confirm($id,22);
seatReject(fn()=>$seatBooking->confirmReservation($id,$hold,'Late approval'),'TrainingSessionFull');
verify(count($seatBooking->listForSession($id))===1,'late approval failure leaves no partial enrollment');
$otherId=(int) $seatBooking->listForSession($id)[0]->rowid;
$seatBooking->cancel($id,$otherId,'Free seat');
$late=$seatBooking->confirmReservation($id,$hold,'Late approval after fresh capacity check');
verify(count($late)===1 && $seatSchedule->detail($id)['occupied']===1,'late approval succeeds only after new capacity check');
$event=$seatBooking->reservationHistory($id,$hold)[1];
verify(json_decode($event->metadata_json,true)['after_expiry']===true,'audit distinguishes conversion after expiry');

$id=seatSession('SEAT-RELEASE',2);
$hold=$seatBooking->reserve($id,array(21),seatKey('release'));
seatReject(fn()=>$seatBooking->releaseReservation($id,$hold,''),'TrainingReservationReasonRequired');
$seatSchedule->changeStatus($id,'closed');
seatReject(fn()=>$seatBooking->confirmReservation($id,$hold,'Closed approval'),'TrainingSessionNotOpen');
$seatBooking->releaseReservation($id,$hold,'Closed session release');
$before=$db->count('training_audit'); $seatBooking->releaseReservation($id,$hold,'Retry');
verify($seatSchedule->detail($id)['reserved']===0 && $db->count('training_audit')===$before,'release on closed session is idempotent');
seatReject(fn()=>$seatBooking->confirmReservation($id,$hold,'Released approval'),'TrainingInvalidTransition');
verify($seatBooking->reserve($id,array(21),seatKey('release'))===$hold,'release retry returns terminal reservation without revival');

$id=seatSession('SEAT-ACCESS',3);
seatReject(fn()=>$seatBooking->reserve($id,array(30),seatKey('inactive')),'TrainingContactInactive');
seatReject(fn()=>$seatBooking->reserve($id,array(31),seatKey('foreign')),'TrainingContactNotAccessible');
$readonly=new TrainingEnrollmentService(bookingStore($db,2,new ReadOnlyEnrollmentUser()));
seatReject(fn()=>$readonly->reserve($id,array(21),seatKey('readonly')),'TrainingAccessDenied');
$hold=$seatBooking->reserve($id,array(21,22),seatKey('access'));
seatReject(fn()=>$readonly->confirmReservation($id,$hold,'Unauthorized'),'TrainingAccessDenied');
seatReject(fn()=>$readonly->releaseReservation($id,$hold,'Unauthorized'),'TrainingAccessDenied');
$foreign=new TrainingEnrollmentService(bookingStore($db,1));
seatReject(fn()=>$foreign->confirmReservation($id,$hold,'Foreign'),'TrainingSessionNotFound');
$other=seatSession('SEAT-WRONG-HOLD',3);
seatReject(fn()=>$seatBooking->confirmReservation($other,$hold,'Wrong session'),'TrainingReservationNotFound');
$db->query('UPDATE tst_socpeople SET statut=0 WHERE rowid=22');
seatReject(fn()=>$seatBooking->confirmReservation($id,$hold,'Inactive member'),'TrainingContactInactive');
verify($seatSchedule->detail($id)['occupied']===0 && $seatSchedule->detail($id)['reserved']===2,'inactive member rejects the whole group');
$db->query('UPDATE tst_socpeople SET statut=1 WHERE rowid=22');
$db->failAudit=true;
seatReject(fn()=>$seatBooking->confirmReservation($id,$hold,'Fail audit'),'TrainingDatabaseError');
$db->failAudit=false;
verify($seatSchedule->detail($id)['occupied']===0 && $seatSchedule->detail($id)['reserved']===2,'conversion and all learner/enrollment writes roll back on failed audit');
$beforeAudit=$db->count('training_audit'); $db->auditFailureCountdown=3;
seatReject(fn()=>$seatBooking->confirmReservation($id,$hold,'Fail final group audit'),'TrainingDatabaseError');
verify($db->count('training_audit')===$beforeAudit && $seatSchedule->detail($id)['occupied']===0 && $seatSchedule->detail($id)['reserved']===2,'final hold audit failure rolls back both confirmed enrollments and their earlier audit events');
$mapped=$db->query('SELECT COUNT(*) AS n FROM tst_training_seat_member WHERE fk_seat_hold='.$hold.' AND fk_enrollment IS NOT NULL')->fetch_object();
verify((int) $mapped->n===0,'failed final conversion audit leaves no member enrollment links');
$db->failAudit=true;
seatReject(fn()=>$seatBooking->releaseReservation($id,$hold,'Fail release audit'),'TrainingDatabaseError');
$db->failAudit=false;
verify($seatSchedule->detail($id)['reserved']===2,'release audit failure preserves reservation');
$before=$db->count('training_seat_hold'); $db->failAudit=true;
seatReject(fn()=>$seatBooking->reserve($id,array(23),seatKey('fail-reserve')),'TrainingDatabaseError');
$db->failAudit=false;
verify($db->count('training_seat_hold')===$before && $seatSchedule->detail($id)['reserved']===2,'reserve audit failure rolls back hold and member rows');

$db->query("INSERT INTO tst_socpeople (rowid,entity,firstname,lastname,statut) VALUES (40,1,'New','First member',1),(41,1,'New','Second member',1)");
$rollbackSession=seatSession('SEAT-NEW-LEARNER-ROLLBACK',2);
$rollbackHold=$seatBooking->reserve($rollbackSession,array(40,41),seatKey('new-learner-rollback'));
$beforeLearners=$db->count('training_learner'); $beforeAudit=$db->count('training_audit'); $db->auditFailureCountdown=3;
seatReject(fn()=>$seatBooking->confirmReservation($rollbackSession,$rollbackHold,'Rollback whole fresh group'),'TrainingDatabaseError');
verify($db->count('training_learner')===$beforeLearners && $db->count('training_audit')===$beforeAudit && $seatSchedule->detail($rollbackSession)['occupied']===0,'late audit failure also rolls back newly created learner profiles for both contacts');

for ($round=1;$round<=3;$round++) {
    $id=seatSession('SEAT-ADMIN-RACE-'.$round,1);
    $results=raceBookings($id,array(array('reserve',21),array('confirm',22)));
    $detail=$seatSchedule->detail($id);
    verify($detail['occupied']+$detail['reserved']===1 && count(array_filter($results,fn($r)=>$r['status']!=='rejected'))===1,'last-seat reservation versus admin booking round '.$round);
    $id=seatSession('SEAT-GROUP-RACE-'.$round,3);
    $results=raceBookings($id,array(array('reservegroup',21),array('reservegroup',23)));
    verify($seatSchedule->detail($id)['reserved']===2 && count(array_filter($results,fn($r)=>$r['status']==='reserved'))===1,'two groups of two compete atomically for three seats round '.$round);
    $id=seatSession('SEAT-SAME-RACE-'.$round,2);
    // Distinct worker mode names for barrier filenames, identical normalized payload/key.
    $results=raceBookings($id,array(array('reservesame',21),array('reservesamealias',21)));
    verify($results[0]['id']===$results[1]['id'] && $seatSchedule->detail($id)['reserved']===1,'concurrent idempotent reservation round '.$round);
    $id=seatSession('SEAT-CAPACITY-RACE-'.$round,2);
    $seatBooking->confirm($id,21);
    $results=raceBookings($id,array(array('reserve',22),array('capacity',1)));
    $detail=$seatSchedule->detail($id);
    verify($detail['occupied']+$detail['reserved']===(int) $detail['session']->capacity && count(array_filter($results,fn($r)=>$r['status']!=='rejected'))===1,'capacity reduction versus reservation round '.$round);
    $id=seatSession('SEAT-CONVERT-RACE-'.$round,1);
    $hold=$seatBooking->reserve($id,array(21),seatKey('convert-'.$round));
    $results=raceBookings($id,array(array('convert',$hold),array('confirm',22)));
    verify($seatSchedule->detail($id)['occupied']===1 && $seatSchedule->detail($id)['reserved']===0 && count(array_filter($results,fn($r)=>$r['status']==='converted'))===1,'active conversion protects held seat against admin booking round '.$round);
    $id=seatSession('SEAT-LATE-RACE-'.$round,1);
    $hold=$seatBooking->reserve($id,array(21),seatKey('late-'.$round)); expireSeat($hold);
    $results=raceBookings($id,array(array('convert',$hold),array('confirm',22)));
    verify($seatSchedule->detail($id)['occupied']===1 && count(array_filter($results,fn($r)=>$r['status']!=='rejected'))===1,'expired conversion versus admin last-seat booking round '.$round);
    $id=seatSession('SEAT-RELEASE-RACE-'.$round,1);
    $hold=$seatBooking->reserve($id,array(21),seatKey('release-'.$round));
    $results=raceBookings($id,array(array('convert',$hold),array('release',$hold)));
    verify(count(array_filter($results,fn($r)=>$r['status']!=='rejected'))===1 && $seatSchedule->detail($id)['reserved']===0,'conversion versus release has one terminal winner round '.$round);
}
echo 'All named reservation, expiry and concurrency MySQL tests passed.'.PHP_EOL;
