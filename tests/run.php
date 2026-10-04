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
echo 'All unit/service tests passed.'.PHP_EOL;
