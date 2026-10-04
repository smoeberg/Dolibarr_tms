<?php
require_once __DIR__.'/mysql_support.php';
$db = new MysqlTestDb();
// These names exist only in the dedicated training_test schema.
foreach (array('training_trainer_assignment','training_trainer','user','training_billing_allocation','training_billing_line','facturedet','facture','training_attendance','training_enrollment','training_session_slot','training_session','training_learner','socpeople','societe_commerciaux','societe') as $table) { $db->query('DROP TABLE IF EXISTS tst_'.$table); }
$db->query('DROP TABLE IF EXISTS tst_training_course_version');
$db->query('DROP TABLE IF EXISTS tst_training_course_profile');
$db->query('DROP TABLE IF EXISTS tst_training_audit');
$db->query('DROP TABLE IF EXISTS tst_product');
$standardUserSchema = str_replace('llx_', 'tst_', file_get_contents(__DIR__.'/../.ci/dolibarr/htdocs/install/mysql/tables/llx_user.sql'));
$standardUserSchema = preg_replace('/^\s*--.*$/m', '', $standardUserSchema); // Match Dolibarr run_sql comment handling.
$db->connection->multi_query($standardUserSchema);
do { if ($result=$db->connection->store_result()) { $result->free(); } } while ($db->connection->more_results() && $db->connection->next_result());
$db->query("INSERT INTO tst_user (rowid,entity,login,firstname,lastname,statut) VALUES (7,2,'coordinator','Coordinator','Test',1),(8,2,'trainer','Trainer','Test',1),(9,2,'othertrainer','Other','Trainer',1),(10,3,'foreign','Foreign','User',1),(11,2,'disabled','Disabled','User',0),(12,2,'external','External','User',1)");
$db->query('UPDATE tst_user SET fk_soc=99 WHERE rowid=12');
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

require __DIR__.'/booking_mysql.php';

require_once __DIR__.'/attendance_mysql.php';

require_once __DIR__.'/billing_mysql.php';

require_once __DIR__.'/attendance_picker_mysql.php';

require_once __DIR__.'/trainer_mysql.php';
