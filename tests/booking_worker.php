<?php
require_once __DIR__.'/mysql_support.php';
$sessionId = (int) ($argv[1] ?? 0);
$value = (int) ($argv[2] ?? 0);
$barrier = $argv[3] ?? '';
$mode = $argv[4] ?? 'confirm';
try {
    $store = bookingStore(new MysqlTestDb());
    file_put_contents($barrier.'/ready-'.$mode.'-'.$value, 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout'); }
        usleep(10000);
    }
    if (str_starts_with($mode, 'billing')) {
        require_once __DIR__.'/../htdocs/custom/training/class/trainingbillingservice.class.php';
        $invoiceId = $value % 10000;
        if ($mode === 'billing-other') { $sessionId = (int) $store->rows("SELECT rowid FROM tst_training_session WHERE ref='BILLING-OTHER' AND entity=2")[0]->rowid; }
        $enrollments = $store->rows("SELECT rowid FROM tst_training_enrollment WHERE fk_session=".$sessionId." AND status='confirmed' ORDER BY rowid");
        $weights = array((int) $enrollments[$value >= 10000 ? 1 : 0]->rowid => 1);
        if ($mode === 'billing-revise') { $weights = array((int) $enrollments[0]->rowid => $value >= 10000 ? 1 : 2, (int) $enrollments[1]->rowid => $value >= 10000 ? 2 : 1); }
        (new TrainingBillingService($store, 'DKK'))->allocate($sessionId, $invoiceId, $invoiceId+300, $mode === 'billing-revise' ? 1 : 0, $weights, 'Concurrent correction');
        echo json_encode(array('status' => 'allocated'));
    } elseif ($mode === 'attendance') {
        require_once __DIR__.'/../htdocs/custom/training/class/trainingattendanceservice.class.php';
        $slot = $store->rows('SELECT rowid FROM tst_training_session_slot WHERE fk_session='.$sessionId.' ORDER BY rowid')[0];
        $enrollment = $store->rows('SELECT rowid FROM tst_training_enrollment WHERE fk_session='.$sessionId)[0];
        (new TrainingAttendanceService($store))->record($sessionId, (int) $slot->rowid, (int) $enrollment->rowid, 0, array('status' => $value === 1 ? 'present' : 'absent'));
        echo json_encode(array('status' => 'recorded'));
    } elseif ($mode === 'capacity') {
        (new TrainingSchedulingService($store))->changeCapacity($sessionId, $value);
        echo json_encode(array('status' => 'changed'));
    } else {
        $id = (new TrainingEnrollmentService($store))->confirm($sessionId, $value);
        echo json_encode(array('status' => 'confirmed', 'id' => $id));
    }
} catch (Throwable $e) {
    $expected = array('TrainingSessionFull', 'TrainingCapacityBelowOccupancy', 'TrainingAttendanceConflict', 'TrainingBillingConflict', 'TrainingInvoiceLineAlreadyAllocated');
    echo json_encode(array('status' => 'rejected', 'reason' => $e->getMessage()));
    if (!in_array($e->getMessage(), $expected, true)) { exit(1); }
}
