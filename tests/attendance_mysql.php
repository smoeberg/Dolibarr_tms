<?php
require_once __DIR__.'/../htdocs/custom/training/class/trainingattendanceservice.class.php';
$attendance = new TrainingAttendanceService($store);
$slotId = (int) $scheduling->detail($sessionId)['slots'][0]->rowid;
$secondSlotId = (int) $scheduling->detail($sessionId)['slots'][1]->rowid;
$sheet = $attendance->sheet($sessionId, $slotId);
verify(count($sheet['rows']) === 2 && $sheet['rows'][0]->status === 'not_registered' && $sheet['rows'][0]->present_minutes === null, 'attendance starts unknown for confirmed enrollments');
bookingReject(fn() => $attendance->record($sessionId, $slotId, $enrollment3, 0, array('status'=>'present')), 'TrainingAttendanceConfirmedRequired');
$record = $attendance->record($sessionId, $slotId, $enrollment1, 0, array('status'=>'present'));
$count = $db->count('training_audit');
verify($attendance->record($sessionId, $slotId, $enrollment1, 1, array('status'=>'present')) === $record && $db->count('training_audit') === $count, 'unchanged current revision is a no-op');
bookingReject(fn() => $attendance->record($sessionId, $slotId, $enrollment1, 0, array('status'=>'absent'), 'Stale browser'), 'TrainingAttendanceConflict');
// InvalidArgumentException is not a RuntimeException: use shared explicit assertion below.
function attendanceReject(callable $fn, string $reason): void {
    try { $fn(); } catch (Throwable $e) { verify($e->getMessage() === $reason, $reason); return; }
    throw new RuntimeException('Expected rejection '.$reason);
}
attendanceReject(fn() => $attendance->record($sessionId, $slotId, $enrollment1, 1, array('status'=>'absent')), 'TrainingAttendanceReasonRequired');
class NoCorrectionUser extends MysqlTestUser {
    public function hasRight($module, ...$keys) { return !($module === 'training' && $keys === array('attendance','correct')); }
}
$noCorrection = new TrainingAttendanceService(bookingStore($db, 2, new NoCorrectionUser()));
attendanceReject(fn() => $noCorrection->record($sessionId, $slotId, $enrollment1, 1, array('status'=>'absent'), 'Attempt'), 'TrainingAccessDenied');
$db->failAudit = true;
attendanceReject(fn() => $attendance->record($sessionId, $slotId, $enrollment1, 1, array('status'=>'absent'), 'Audit rollback'), 'TrainingDatabaseError');
attendanceReject(fn() => $attendance->record($sessionId, $secondSlotId, $enrollment1, 0, array('status'=>'present')), 'TrainingDatabaseError');
$db->failAudit = false;
verify(count($attendance->history($sessionId, $slotId, $enrollment1)) === 1 && $db->count('training_attendance') === 1, 'audit failure rolls back insert and correction');
$late = array('status'=>'present', 'arrival'=>'2026-10-20T09:14:00+02:00', 'departure'=>'2026-10-20T15:00:00+02:00');
$attendance->record($sessionId, $slotId, $enrollment1, 1, $late, 'Corrected from attendance sheet');
$row = $attendance->sheet($sessionId, $slotId)['rows'][0];
verify($row->status === 'late' && (int) $row->present_minutes === 346 && (int) $row->revision === 2, 'correction stores partial presence and increments revision');
$attendance->record($sessionId, $slotId, $enrollment1, 2, array('status'=>'not_registered'), 'Remove mistaken attestation');
verify($attendance->sheet($sessionId, $slotId)['rows'][0]->present_minutes === null && count($attendance->history($sessionId, $slotId, $enrollment1)) === 3, 'clearing retains history and returns to unknown');
$enrollments->cancel($sessionId, $enrollment1, 'Cancelled after attendance');
verify(count($attendance->sheet($sessionId, $slotId)['rows']) === 2, 'cancelled enrollment with attendance remains visible');
$attendance->record($sessionId, $slotId, $enrollment1, 3, array('status'=>'absent'), 'Historical correction after cancellation');
attendanceReject(fn() => $attendance->record($sessionId, $secondSlotId, $enrollment1, 0, array('status'=>'present')), 'TrainingAttendanceConfirmedRequired');
$foreignSlot = (int) $scheduling->detail($rollbackId)['slots'][0]->rowid;
attendanceReject(fn() => $attendance->record($sessionId, $foreignSlot, $enrollment1, 4, array('status'=>'present'), 'Wrong slot'), 'TrainingAttendanceSlotNotFound');
attendanceReject(fn() => $attendance->record($sessionId, $slotId, $rollbackEnrollment, 0, array('status'=>'present')), 'TrainingEnrollmentNotFound');
attendanceReject(fn() => (new TrainingAttendanceService(bookingStore($db, 1)))->sheet($sessionId, $slotId), 'TrainingSessionNotFound');
class NoAttendanceUser extends MysqlTestUser {
    public function hasRight($module, ...$keys) { return !($module === 'training' && $keys[0] === 'attendance'); }
}
attendanceReject(fn() => (new TrainingAttendanceService(bookingStore($db, 2, new NoAttendanceUser())))->sheet($sessionId, $slotId), 'TrainingAccessDenied');
// Reuse the fresh-connection barrier harness. Each worker starts with expected revision zero.
for ($round=1; $round<=3; $round++) {
    $raceId = $scheduling->create($first, 'RACE-ATTENDANCE-'.$round, 'Attendance race', 1, 'Europe/Copenhagen');
    $scheduling->replaceSlots($raceId, $slots); $scheduling->changeStatus($raceId, 'open'); $enrollments->confirm($raceId, 24);
    $results = raceBookings($raceId, array(array('attendance',1),array('attendance',2)));
    verify(count(array_filter($results, fn($r) => $r['status'] === 'recorded')) === 1 && count(array_filter($results, fn($r) => ($r['reason'] ?? '') === 'TrainingAttendanceConflict')) === 1, 'concurrent attendance round '.$round.' rejects stale overwrite');
}
echo 'All attendance MySQL tests passed.'.PHP_EOL;
