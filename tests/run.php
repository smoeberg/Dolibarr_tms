<?php
require_once __DIR__.'/../htdocs/custom/training/class/trainingcatalogservice.class.php';

function dol_now() { return 1791072000; }
function check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label.PHP_EOL;
}
function rejects(callable $fn, string $key): void {
    try { $fn(); } catch (Throwable $e) {
        check($e->getMessage() === $key, $key);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$key);
}
class TestUser {
    public $id = 7;
    public $socid = 0;
    public array $rights = array('read', 'write', 'publish', 'lire');
    public function hasRight($module, ...$keys) { return in_array(end($keys), $this->rights, true); }
}
class TestResult {
    public array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
}
class TestDb {
    public string $type = 'mysqli';
    public array $sql = array();
    public array $results = array();
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public bool $failAudit = false;
    public function prefix() { return 'test_'; }
    public function escape($text) { return addslashes($text); }
    public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
    public function begin() { $this->begins++; return 1; }
    public function commit() { $this->commits++; return 1; }
    public function rollback() { $this->rollbacks++; return 1; }
    public function last_insert_id($table) { return 5; }
    public function query($sql) {
        $this->sql[] = $sql;
        if ($this->failAudit && str_contains($sql, 'INSERT INTO test_training_audit')) { return false; }
        if (str_starts_with($sql, 'SELECT')) { return new TestResult(array_shift($this->results) ?? array()); }
        return true;
    }
    public function fetch_object($result) { return array_shift($result->rows) ?? null; }
    public function num_rows($result) { return count($result->rows); }
}
$input = array('goals' => 'Analyse data', 'target_audience' => 'Analysts', 'units' => array(array('label' => 'Data', 'duration_minutes' => 420), array('label' => 'Analysis', 'duration_minutes' => 420)));
$program = TrainingProgram::normalize($input);
check($program['duration_minutes'] === 840 && $program['units'][1]['position'] === 2, 'ordered program and teaching duration');
foreach (array(0, -1, 1.5, '420', 10081) as $minutes) {
    rejects(fn() => TrainingProgram::normalize(array('units' => array(array('label' => 'Unit', 'duration_minutes' => $minutes)))), 'TrainingInvalidUnits');
}
rejects(fn() => TrainingProgram::normalize(array('units' => array())), 'TrainingInvalidUnits');
rejects(fn() => TrainingProgram::normalize($input + array('modality' => 'invalid')), 'TrainingInvalidModality');
rejects(fn() => TrainingProgram::requirePublishable(TrainingProgram::normalize(array('units' => $input['units']))), 'TrainingPublishNeedsGoalsAndAudience');
$user = new TestUser();
$access = new TrainingAccess($user, 2, '1,2');
$product = (object) array('id' => 11, 'entity' => 1, 'type' => 1, 'ref' => 'EXCEL', 'label' => 'Excel', 'description' => 'Standard description');
$access->requireService($product);
check($access->entity() === 2, 'shared standard service does not change module entity');
rejects(fn() => $access->requireService((object) array('id' => 11, 'entity' => 3, 'type' => 1)), 'TrainingServiceNotAccessible');
rejects(fn() => $access->requireService((object) array('id' => 11, 'entity' => 1, 'type' => 0)), 'TrainingServiceNotAccessible');
$user->socid = 9;
rejects(fn() => $access->requireRight('read'), 'TrainingAccessDenied');
$user->socid = 0;
$user->rights = array('read', 'lire');
$db = new TestDb();
$catalog = new TrainingCatalogService($db, $access, fn($id) => $product);
rejects(fn() => $catalog->createDraft(11, $input), 'TrainingAccessDenied');
check($db->begins === 0 && count($db->sql) === 0, 'denied write does not touch database');
$user->rights = array('read', 'write', 'publish', 'lire');
$db->results = array(array((object) array('rowid' => 11)), array(), array((object) array('last_version' => 0)));
check($catalog->createDraft(11, $input) === 5, 'draft created');
check($db->commits === 1 && $db->rollbacks === 0, 'draft and audit committed together');
check(str_contains(implode("\n", $db->sql), 'WHERE entity=2 AND fk_product=11 FOR UPDATE'), 'profile scoped to active entity');
$db = new TestDb();
$db->failAudit = true;
$db->results = array(array((object) array('rowid' => 11)), array(), array((object) array('last_version' => 0)));
$catalog = new TrainingCatalogService($db, $access, fn($id) => $product);
rejects(fn() => $catalog->createDraft(11, $input), 'TrainingDatabaseError');
check($db->commits === 0 && $db->rollbacks === 1, 'audit failure rolls back draft');
$version = (object) array('rowid' => 5, 'version_number' => 1, 'status' => 'draft', 'program_json' => json_encode($program));
$db = new TestDb();
$db->results = array(array((object) array('rowid' => 11)), array($version));
$catalog = new TrainingCatalogService($db, $access, fn($id) => $product);
$catalog->publish(11, 5);
check(str_contains(implode("\n", $db->sql), "product_label_snapshot='Excel'") && $db->commits === 1, 'publication freezes standard title');
$version->status = 'published';
$db = new TestDb();
$db->results = array(array((object) array('rowid' => 11)), array($version));
$catalog = new TrainingCatalogService($db, $access, fn($id) => $product);
$catalog->publish(11, 5);
check(count(array_filter($db->sql, fn($sql) => str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'INSERT'))) === 0, 'repeated publication preserves snapshot and audit');
$db = new TestDb();
$db->results = array(array((object) array('rowid' => 11)), array());
$catalog = new TrainingCatalogService($db, $access, fn($id) => $product);
rejects(fn() => $catalog->publish(11, 99), 'TrainingVersionNotFound');
check($db->rollbacks === 1 && $db->commits === 0, 'foreign or missing version cannot be published');
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures');
require_once __DIR__.'/../htdocs/custom/training/core/modules/modTraining.class.php';
$module = new modTraining(new TestDb());
$module->loadResult = 0;
check($module->init() === -1 && $module->initCalls === 0, 'schema failure prevents activation');
$module->loadResult = 1;
check($module->init() === 1 && $module->initCalls === 1, 'valid schema permits activation');
require_once __DIR__.'/../htdocs/custom/training/class/trainingslots.class.php';
$slot = array('start' => '2026-10-20T09:00:00+02:00', 'end' => '2026-10-20T16:00:00+02:00');
check(TrainingSlots::normalize(array($slot), 'Europe/Copenhagen')[0]['duration_minutes'] === 420, 'timezone-aware teaching minutes');
rejects(fn() => TrainingSlots::normalize(array($slot, $slot), 'Europe/Copenhagen'), 'TrainingSlotsOverlap');
rejects(fn() => TrainingSlots::normalize(array($slot), 'Invalid/Zone'), 'TrainingInvalidTimezone');
rejects(fn() => TrainingSlots::normalize(array(array('start'=>'2026-03-29T02:30:00+01:00','end'=>'2026-03-29T04:30:00+02:00')), 'Europe/Copenhagen'), 'TrainingInvalidSlots');
check(TrainingSlots::normalize(array(array('start'=>'2026-10-25T02:00:00+02:00','end'=>'2026-10-25T02:30:00+01:00')), 'Europe/Copenhagen')[0]['duration_minutes'] === 90, 'explicit offsets disambiguate autumn clock change');
$user->rights = array('read','lire');
$db = new TestDb();
$contact = (object) array('id'=>21,'entity'=>2,'socid'=>99);
$db->results = array(array());
rejects(fn() => $access->requireContact($contact, $db), 'TrainingContactNotAccessible');
$access->requireDomain('session','read');
rejects(fn() => $access->requireDomain('session','write'), 'TrainingAccessDenied');
echo 'All unit/service tests passed.'.PHP_EOL;
require_once __DIR__.'/../htdocs/custom/training/class/trainingattendancerecord.class.php';
$attendanceSlot = (object) array('start_utc'=>'2026-10-20 07:00:00', 'end_utc'=>'2026-10-20 14:00:00');
$normalize = fn($input) => TrainingAttendanceRecord::normalize($input, $attendanceSlot, 'Europe/Copenhagen');
check($normalize(array('status'=>'not_registered'))['present_minutes'] === null, 'unregistered attendance is unknown, not absent');
$full = $normalize(array('status'=>'present'));
check($full['present_minutes'] === 420 && $full['arrival_utc'] === null, 'full-slot attestation does not invent an arrival');
$partial = $normalize(array('status'=>'present', 'arrival'=>'2026-10-20T09:14:00+02:00', 'departure'=>'2026-10-20T15:00:00+02:00'));
check($partial['status'] === 'late' && $partial['late_minutes'] === 14 && $partial['present_minutes'] === 346, 'late and partial attendance uses observed UTC times');
rejects(fn() => $normalize(array('status'=>'late')), 'TrainingAttendanceTimesRequired');
rejects(fn() => $normalize(array('status'=>'absent', 'arrival'=>'2026-10-20T09:00:00+02:00')), 'TrainingAbsentHasTimes');
rejects(fn() => $normalize(array('status'=>'present', 'arrival'=>'2026-10-20T09:00:00+01:00', 'departure'=>'2026-10-20T16:00:00+01:00')), 'TrainingInvalidAttendanceTimes');
rejects(fn() => $normalize(array('status'=>'present', 'arrival'=>'2026-10-20T08:59:00+02:00', 'departure'=>'2026-10-20T16:00:00+02:00')), 'TrainingInvalidAttendanceTimes');
check(count($module->rights) === 13 && count(array_unique(array_column($module->rights, 0))) === 13, 'distinct attendance permissions registered');
echo 'All attendance normalization tests passed.'.PHP_EOL;
require_once __DIR__.'/../htdocs/custom/training/class/trainingbillingamount.class.php';
$split = TrainingBillingAmount::distribute('100.00000001', '125.00000001', array(3=>1,1=>1,2=>1));
check(array_column($split, 'enrollment_id') === array(1,2,3), 'allocation ordering deterministic');
check(array_sum(array_map(fn($r) => TrainingBillingAmount::units($r['amount_ht']), $split)) === 10000000001, 'thirds reconcile exactly to source HT with eight decimals');
check(array_sum(array_map(fn($r) => TrainingBillingAmount::units($r['amount_ttc']), $split)) === 12500000001, 'thirds reconcile exactly to source TTC');
$maximum = TrainingBillingAmount::distribute('9999999999.99999999','9999999999.99999999',array(1=>10000,2=>9999,3=>1));
check(array_sum(array_map(fn($r) => TrainingBillingAmount::units($r['amount_ht']), $maximum)) === TrainingBillingAmount::units('9999999999.99999999'), 'large amounts avoid multiplication overflow');
rejects(fn() => TrainingBillingAmount::distribute('1','1',array(1=>0)), 'TrainingInvalidBillingShares');
rejects(fn() => TrainingBillingAmount::distribute('1','1',array(1=>1.5)), 'TrainingInvalidBillingShares');
rejects(fn() => TrainingBillingAmount::units('-1'), 'TrainingInvalidBillingAmount');
rejects(fn() => TrainingBillingAmount::units('1e2'), 'TrainingInvalidBillingAmount');
rejects(fn() => TrainingBillingAmount::units('1.000000001'), 'TrainingInvalidBillingAmount');
echo 'All exact billing amount tests passed.'.PHP_EOL;
