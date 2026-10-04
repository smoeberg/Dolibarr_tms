<?php
/** Synthetic MySQL tests; this is not a Dolibarr installation test. */
require_once __DIR__.'/../htdocs/custom/training/class/trainingcatalogservice.class.php';
function dol_now() { return time(); }
function verify(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$message); }
    echo 'PASS: '.$message.PHP_EOL;
}
class MysqlTestDb {
    public mysqli $connection;
    public bool $failAudit = false;
    public function __construct() {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $host = getenv('TRAINING_TEST_MYSQL_HOST');
        $password = getenv('TRAINING_TEST_MYSQL_PASSWORD');
        if (!$host || !$password) { throw new RuntimeException('Set isolated test database environment variables.'); }
        $this->connection = new mysqli($host, 'root', $password, 'training_test');
        $this->connection->set_charset('utf8mb4');
    }
    public function prefix() { return 'tst_'; }
    public function escape($text) { return $this->connection->real_escape_string($text); }
    public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
    public function query($sql) {
        if ($this->failAudit && str_contains($sql, 'INSERT INTO tst_training_audit')) { return false; }
        return $this->connection->query($sql);
    }
    public function fetch_object($result) { return $result->fetch_object(); }
    public function last_insert_id($table) { return $this->connection->insert_id; }
    public function begin() { return $this->connection->begin_transaction() ? 1 : 0; }
    public function commit() { return $this->connection->commit() ? 1 : 0; }
    public function rollback() { return $this->connection->rollback() ? 1 : 0; }
    public function count(string $table): int {
        return (int) $this->connection->query('SELECT COUNT(*) AS n FROM tst_'.$table)->fetch_object()->n;
    }
}
class MysqlTestUser {
    public $id = 7;
    public $socid = 0;
    public function hasRight($module, ...$keys) { return true; }
}
$db = new MysqlTestDb();
// These names exist only in the dedicated training_test schema.
$db->query('DROP TABLE IF EXISTS tst_training_course_version');
$db->query('DROP TABLE IF EXISTS tst_training_course_profile');
$db->query('DROP TABLE IF EXISTS tst_training_audit');
$db->query('DROP TABLE IF EXISTS tst_product');
$db->query('CREATE TABLE tst_product (rowid integer NOT NULL PRIMARY KEY, entity integer NOT NULL, fk_product_type integer NOT NULL) ENGINE=InnoDB');
$db->query('INSERT INTO tst_product VALUES (11, 1, 1)');
$schema = str_replace('llx_', 'tst_', file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_catalog.sql'));
// Apply twice: activation must not destroy existing tables or fail on reactivation.
for ($i = 0; $i < 2; $i++) {
    $db->connection->multi_query($schema);
    do {
        if ($result = $db->connection->store_result()) { $result->free(); }
    } while ($db->connection->more_results() && $db->connection->next_result());
}
verify($db->count('training_course_profile') === 0, 'schema installed twice with nondefault prefix');
$product = (object) array('id' => 11, 'entity' => 1, 'type' => 1, 'ref' => 'KURS-EXCEL', 'label' => 'Excel æøå', 'description' => 'Original sales description');
$access = new TrainingAccess(new MysqlTestUser(), 2, '1,2');
$catalog = new TrainingCatalogService($db, $access, fn($id) => $product);
$input = array('goals' => 'Analyse', 'target_audience' => 'Analysts', 'units' => array(array('label' => 'Data', 'duration_minutes' => 420), array('label' => 'Analysis', 'duration_minutes' => 420)));
$first = $catalog->createDraft(11, $input);
$second = $catalog->createDraft(11, $input);
$versions = $catalog->versions(11);
verify(count($versions) === 2 && (int) $versions[0]->version_number === 2, 'one profile and ordered version numbers');
verify($db->count('training_course_profile') === 1, 'no duplicate course identity');
$catalog->publish(11, $first);
$product->label = 'New catalog title';
$catalog->publish(11, $first);
$row = $db->query('SELECT * FROM tst_training_course_version WHERE rowid='.$first)->fetch_object();
verify($row->product_label_snapshot === 'Excel æøå' && $row->status === 'published', 'repeat publish preserves UTF-8 snapshot');
verify($db->count('training_audit') === 3, 'two drafts and one publication audit');
$other = new TrainingCatalogService($db, new TrainingAccess(new MysqlTestUser(), 1, '1'), fn($id) => $product);
verify(count($other->versions(11)) === 0, 'shared service does not expose other entity versions');
try {
    $other->publish(11, $second);
    throw new LogicException('Foreign publication was accepted');
} catch (RuntimeException $e) {
    verify($e->getMessage() === 'TrainingVersionNotFound', 'cross-entity publication rejected');
}
$db->failAudit = true;
try {
    $catalog->createDraft(11, $input);
    throw new LogicException('Audit failure was ignored');
} catch (RuntimeException $e) {
    verify($e->getMessage() === 'TrainingDatabaseError', 'audit failure reported');
}
$db->failAudit = false;
verify($db->count('training_course_version') === 2, 'failed audit rolls back actual database insert');
try {
    $db->query("INSERT INTO tst_training_course_profile (entity, fk_product, datec, fk_user_author) VALUES (2, 11, NOW(), 7)");
    throw new LogicException('Duplicate profile was accepted');
} catch (mysqli_sql_exception $e) {
    verify($e->getCode() === 1062, 'unique course-service relation enforced by MySQL');
}
try {
    $db->query("INSERT INTO tst_training_course_version (entity, fk_course_profile, version_number, program_json, duration_minutes, datec, fk_user_author) VALUES (999, 1, 1, '{}', 1, NOW(), 7)");
    throw new LogicException('Cross-entity foreign key was accepted');
} catch (mysqli_sql_exception $e) {
    verify($e->getCode() === 1452, 'composite foreign key rejects mismatched entity');
}
echo 'All synthetic MySQL integration tests passed.'.PHP_EOL;
