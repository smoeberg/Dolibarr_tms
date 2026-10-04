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

require_once __DIR__.'/../htdocs/custom/training/class/trainingschedulingservice.class.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingenrollmentservice.class.php';
function bookingStore(MysqlTestDb $db, int $entity = 2, $user = null): TrainingStore {
    $access = new TrainingAccess($user ?? new MysqlTestUser(), $entity, '1,2', '1,2', '1,2');
    $products = function (int $id) use ($db) {
        $r = $db->query('SELECT * FROM tst_product WHERE rowid='.$id)->fetch_object();
        if (!$r) { throw new RuntimeException('TrainingServiceNotAccessible'); }
        return (object) array('id' => $id, 'type' => $r->fk_product_type, 'entity' => $r->entity);
    };
    $contacts = function (int $id) use ($db) {
        $r = $db->query('SELECT * FROM tst_socpeople WHERE rowid='.$id)->fetch_object();
        if (!$r) { throw new RuntimeException('TrainingContactNotAccessible'); }
        $r->id = $id; $r->socid = $r->fk_soc; $r->status = $r->statut; return $r;
    };
    return new TrainingStore($db, $access, $products, $contacts);
}
