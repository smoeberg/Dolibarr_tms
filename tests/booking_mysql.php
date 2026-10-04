<?php
// Included after the catalog MySQL tests; published program $first has 840 minutes.
$db->query('CREATE TABLE tst_socpeople (rowid integer NOT NULL PRIMARY KEY, entity integer NOT NULL, fk_soc integer NULL, firstname varchar(64), lastname varchar(64), statut integer NOT NULL DEFAULT 1) ENGINE=InnoDB');
$db->query('CREATE TABLE tst_societe (rowid integer NOT NULL PRIMARY KEY, entity integer NOT NULL) ENGINE=InnoDB');
$db->query('CREATE TABLE tst_societe_commerciaux (fk_soc integer NOT NULL, fk_user integer NOT NULL) ENGINE=InnoDB');
for ($contactId = 21; $contactId <= 26; $contactId++) {
    $db->query("INSERT INTO tst_socpeople (rowid,entity,firstname,lastname) VALUES (".$contactId.",1,'Synthetic','Participant ".$contactId."')");
}
$schema = str_replace('llx_', 'tst_', file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_sessions.sql'));
for ($i = 0; $i < 2; $i++) {
    $db->connection->multi_query($schema);
    do { if ($result = $db->connection->store_result()) { $result->free(); } }
    while ($db->connection->more_results() && $db->connection->next_result());
}
$store = bookingStore($db);
$scheduling = new TrainingSchedulingService($store);
$enrollments = new TrainingEnrollmentService($store);
function bookingReject(callable $fn, string $reason): void {
    try { $fn(); } catch (RuntimeException $e) { verify($e->getMessage() === $reason, $reason); return; }
    throw new RuntimeException('Expected rejection '.$reason);
}
$slots = array(
    array('start' => '2026-10-20T09:00:00+02:00', 'end' => '2026-10-20T16:00:00+02:00'),
    array('start' => '2026-10-21T09:00:00+02:00', 'end' => '2026-10-21T16:00:00+02:00'),
);
$sessionId = $scheduling->create($first, 'SES-EXCEL-01', 'Excel October', 2, 'Europe/Copenhagen');
bookingReject(fn() => $scheduling->changeStatus($sessionId, 'open'), 'TrainingSlotDurationMismatch');
$scheduling->replaceSlots($sessionId, $slots);
verify($scheduling->detail($sessionId)['slots'][0]->start_utc === '2026-10-20 07:00:00', 'local teaching times persist as UTC');
$scheduling->changeStatus($sessionId, 'open');
$enrollment1 = $enrollments->confirm($sessionId, 21);
$auditCount = $db->count('training_audit');
verify($enrollments->confirm($sessionId, 21) === $enrollment1 && $db->count('training_audit') === $auditCount, 'repeated confirmation uses the same enrollment and audit');
$enrollments->confirm($sessionId, 22);
bookingReject(fn() => $enrollments->confirm($sessionId, 23), 'TrainingSessionFull');
bookingReject(fn() => $scheduling->changeCapacity($sessionId, 1), 'TrainingCapacityBelowOccupancy');
bookingReject(fn() => $scheduling->replaceSlots($sessionId, $slots), 'TrainingDraftSessionRequired');
$enrollments->cancel($sessionId, $enrollment1, 'Administrative cancellation');
$auditCount = $db->count('training_audit');
$enrollments->cancel($sessionId, $enrollment1, 'Repeated request');
verify($db->count('training_audit') === $auditCount, 'repeated cancellation adds no audit event');
$enrollments->confirm($sessionId, 23);
verify($scheduling->detail($sessionId)['occupied'] === 2, 'cancelled participant releases exactly one seat');
$scheduling->changeStatus($sessionId, 'closed');
bookingReject(fn() => $enrollments->confirm($sessionId, 21), 'TrainingSessionNotOpen');
$scheduling->changeStatus($sessionId, 'open');
$db->failAudit = true;
bookingReject(fn() => $scheduling->changeCapacity($sessionId, 3), 'TrainingDatabaseError');
$db->failAudit = false;
verify((int) $scheduling->detail($sessionId)['session']->capacity === 2, 'capacity change rolls back if audit fails');
$otherSchedule = new TrainingSchedulingService(bookingStore($db, 1));
bookingReject(fn() => $otherSchedule->detail($sessionId), 'TrainingSessionNotFound');
bookingReject(fn() => $otherSchedule->create($first, 'WRONG-ENTITY', 'Wrong', 2, 'Europe/Copenhagen'), 'TrainingPublishedVersionRequired');
$db->query("INSERT INTO tst_socpeople (rowid,entity,firstname,lastname,statut) VALUES (30,1,'Inactive','Person',0),(31,3,'Other','Entity',1),(32,1,'Restricted','Customer',1)");
$db->query('INSERT INTO tst_societe VALUES (99,1)');
$db->query('UPDATE tst_socpeople SET fk_soc=99 WHERE rowid=32');
bookingReject(fn() => $enrollments->confirm($sessionId, 30), 'TrainingContactInactive');
bookingReject(fn() => $enrollments->confirm($sessionId, 31), 'TrainingContactNotAccessible');
class RestrictedBookingUser extends MysqlTestUser {
    public function hasRight($module, ...$keys) { return !($module === 'societe' && $keys === array('client', 'voir')); }
}
$restrictedStore = bookingStore($db, 2, new RestrictedBookingUser());
bookingReject(fn() => $restrictedStore->contact(32), 'TrainingContactNotAccessible');
$db->query('INSERT INTO tst_societe_commerciaux VALUES (99,7)');
verify($restrictedStore->contact(32)->id === 32, 'standard commercial assignment permits authorized contact');

function raceBookings(int $sessionId, array $attempts): array {
    $dir = sys_get_temp_dir().'/training-race-'.bin2hex(random_bytes(8)); mkdir($dir);
    $workers = array();
    try {
        foreach ($attempts as [$mode, $value]) {
            $process = proc_open(array(PHP_BINARY, __DIR__.'/booking_worker.php', (string) $sessionId, (string) $value, $dir, $mode), array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Could not start concurrency worker'); }
            fclose($pipes[0]); $workers[] = array($process, $pipes, $mode.'-'.$value);
        }
        $deadline = microtime(true) + 15;
        while (true) {
            $ready = true;
            foreach ($workers as $worker) { if (!is_file($dir.'/ready-'.$worker[2])) { $ready = false; } }
            if ($ready) { break; }
            if (microtime(true) > $deadline) { throw new RuntimeException('Concurrency workers failed to reach barrier'); }
            usleep(10000);
        }
        touch($dir.'/go'); $results = array();
        foreach ($workers as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { throw new RuntimeException('Worker failed: '.$out.' '.$err); }
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        $workers = array(); return $results;
    } finally {
        foreach ($workers as $worker) { if (is_resource($worker[0])) { proc_terminate($worker[0]); } }
        foreach (glob($dir.'/*') as $path) { unlink($path); } rmdir($dir);
    }
}
for ($round = 1; $round <= 5; $round++) {
    $raceId = $scheduling->create($first, 'RACE-SEAT-'.$round, 'Last seat test', 1, 'Europe/Copenhagen');
    $scheduling->replaceSlots($raceId, $slots); $scheduling->changeStatus($raceId, 'open');
    $results = raceBookings($raceId, array(array('confirm',24), array('confirm',25)));
    verify(count(array_filter($results, fn($r) => $r['status'] === 'confirmed')) === 1 && $scheduling->detail($raceId)['occupied'] === 1, 'concurrent last seat round '.$round.': exactly one participant');
    $raceId = $scheduling->create($first, 'RACE-CAPACITY-'.$round, 'Capacity race test', 2, 'Europe/Copenhagen');
    $scheduling->replaceSlots($raceId, $slots); $scheduling->changeStatus($raceId, 'open'); $enrollments->confirm($raceId, 24);
    raceBookings($raceId, array(array('confirm',25), array('capacity',1)));
    $detail = $scheduling->detail($raceId);
    verify($detail['occupied'] <= (int) $detail['session']->capacity, 'capacity reduction versus booking round '.$round.' preserves invariant');
}
echo 'All booking and concurrency MySQL tests passed.'.PHP_EOL;
