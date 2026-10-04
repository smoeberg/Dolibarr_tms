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
    if ($mode === 'capacity') {
        (new TrainingSchedulingService($store))->changeCapacity($sessionId, $value);
        echo json_encode(array('status' => 'changed'));
    } else {
        $id = (new TrainingEnrollmentService($store))->confirm($sessionId, $value);
        echo json_encode(array('status' => 'confirmed', 'id' => $id));
    }
} catch (Throwable $e) {
    $expected = array('TrainingSessionFull', 'TrainingCapacityBelowOccupancy');
    echo json_encode(array('status' => 'rejected', 'reason' => $e->getMessage()));
    if (!in_array($e->getMessage(), $expected, true)) { exit(1); }
}
