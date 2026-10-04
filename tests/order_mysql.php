<?php
// A2: Order line allocation tests
// Tests mirror the billing_mysql.php tests for invoice lines

// Load required classes
require_once __DIR__.'/mysql.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingorderservice.class.php';

function orderStore($db) {
    return new TrainingStore($db, new TrainingAccess($db, 1, new TestUser(), array('training')));
}

// Create test product
$db->query("INSERT INTO tst_product (rowid, ref, label, price, tva_tx, entity, type) VALUES (2001, 'ORDER-PROD-001', 'Order Test Product', 1000.00, 25.00, 1, 1)");

// Create test customer
$db->query("INSERT INTO tst_societe (rowid, nom, entity) VALUES (5001, 'Test Customer', 1)");

// Create course profile and version
$db->query("INSERT INTO tst_training_course_profile (rowid, entity, fk_product, datec, fk_user_author) VALUES (2, 1, 2001, NOW(), 7)");
$db->query("INSERT INTO tst_training_course_version (rowid, entity, fk_course_profile, version_number, status, program_json, duration_minutes, datec, fk_user_author, published_at, fk_user_publisher) VALUES (2, 1, 2, 1, 'published', '[]', 480, NOW(), 7, NOW(), 7)");

// Create session
$db->query("INSERT INTO tst_training_session (rowid, entity, ref, label, fk_course_version, status, capacity, timezone, datec, fk_user_author) VALUES (2, 1, 'ORDER-SES-001', 'Order Test Session', 2, 'open', 10, 'Europe/Copenhagen', NOW(), 7)");

// Create slots
$db->query("INSERT INTO tst_training_session_slot (rowid, entity, fk_session, position, start_utc, end_utc) VALUES (3, 1, 2, 1, '2026-10-20 07:00:00', '2026-10-20 16:00:00'), (4, 1, 2, 2, '2026-10-21 07:00:00', '2026-10-21 16:00:00')");

// Create contacts
for ($contactId = 31; $contactId <= 35; $contactId++) {
    $db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES ("
        .$contactId.", 1, 'Order', 'Participant ".$contactId."', 1)");
}

// Create order schema (simplified for testing)
$db->query("CREATE TABLE IF NOT EXISTS tst_commande (
    rowid integer NOT NULL PRIMARY KEY,
    entity integer NOT NULL,
    ref varchar(30) NOT NULL,
    fk_soc integer NOT NULL,
    fk_statut integer NOT NULL DEFAULT 0,
    type integer NOT NULL DEFAULT 0,
    multicurrency_code varchar(3) NULL,
    multicurrency_tx decimal(24,8) NULL
) ENGINE=InnoDB");

$db->query("CREATE TABLE IF NOT EXISTS tst_commandedet (
    rowid integer NOT NULL PRIMARY KEY,
    fk_commande integer NOT NULL,
    entity integer NOT NULL,
    fk_product integer NOT NULL,
    product_type integer NOT NULL DEFAULT 0,
    description varchar(255) NULL,
    qty decimal(24,8) NOT NULL DEFAULT 1,
    subprice decimal(24,8) NOT NULL DEFAULT 0,
    remise_percent decimal(16,8) NOT NULL DEFAULT 0,
    tva_tx decimal(16,8) NOT NULL DEFAULT 0,
    total_ht decimal(24,8) NOT NULL DEFAULT 0,
    total_tva decimal(24,8) NOT NULL DEFAULT 0,
    total_localtax1 decimal(24,8) NOT NULL DEFAULT 0,
    total_localtax2 decimal(24,8) NOT NULL DEFAULT 0,
    total_ttc decimal(24,8) NOT NULL DEFAULT 0
) ENGINE=InnoDB");

// Create test order
$db->query("INSERT INTO tst_commande (rowid, entity, ref, fk_soc, fk_statut, type) VALUES (1001, 1, 'ORDER-001', 5001, 1, 0)");

// Create order line
$db->query("INSERT INTO tst_commandedet (rowid, fk_commande, entity, fk_product, product_type, description, qty, subprice, tva_tx, total_ht, total_ttc) VALUES (1001, 1001, 1, 2001, 1, 'Order line for test', 2, 1000.00, 25.00, 2000.00, 2500.00)");

// Load order schema
$orderSchema = str_replace('llx_', 'tst_', file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_zorder.sql'));
for ($i = 0; $i < 2; $i++) {
    $db->connection->multi_query($orderSchema);
    do { if ($result = $db->connection->store_result()) { $result->free(); } } while ($db->connection->more_results() && $db->connection->next_result());
}

$store = orderStore($db);
$orderService = new TrainingOrderService($store, 'DKK');

// Create enrollments
$enrollment1 = $orderService->participants(2); // This will fail, need to use enrollment service
// Let's create enrollments properly
$enrollmentService = new TrainingEnrollmentService($store);
$enrollment1 = $enrollmentService->confirm(2, 31);
$enrollment2 = $enrollmentService->confirm(2, 32);
$enrollment3 = $enrollmentService->confirm(2, 33);

function orderVerify($ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label.PHP_EOL;
}

function orderReject(callable $fn, string $reason): void {
    try { $fn(); } catch (RuntimeException $e) {
        orderVerify($e->getMessage() === $reason, $reason);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$reason);
}

// Test 1: Inspect non-existent order line
orderReject(fn() => $orderService->inspect(2, 9999, 9999), 'TrainingOrderNotAccessible');
echo 'Order tests: Non-existent order line inspection rejected'.PHP_EOL;

// Test 2: Inspect valid order line (not yet allocated)
$view = $orderService->inspect(2, 1001, 1001);
orderVerify($view['mapping'] === null, 'order line not yet allocated');
orderVerify($view['source']['order']->ref === 'ORDER-001', 'order reference correct');
orderVerify($view['source']['line']->fk_product === 2001, 'order line product correct');
orderVerify($view['revision'] === 0, 'initial revision is 0');
echo 'Order tests: Order line inspection works'.PHP_EOL;

// Test 3: Allocate order line to session
$weights = array($enrollment1 => 1, $enrollment2 => 1);
$result = $orderService->allocate(2, 1001, 1001, 0, $weights, 'Initial allocation');
orderVerify($result['id'] > 0, 'order line allocated successfully');
orderVerify($result['revision'] === 1, 'first revision is 1');
echo 'Order tests: Order line allocation works'.PHP_EOL;

// Test 4: Verify allocation
$view = $orderService->inspect(2, 1001, 1001);
orderVerify($view['mapping'] !== null, 'mapping exists after allocation');
orderVerify($view['mapping']->fk_session === 2, 'mapping linked to correct session');
orderVerify($view['mapping']->fk_commande === 1001, 'mapping linked to correct order');
orderVerify($view['mapping']->fk_commandedet === 1001, 'mapping linked to correct order line');
orderVerify(count($view['shares']) === 2, 'two shares created');
echo 'Order tests: Allocation mapping correct'.PHP_EOL;

// Test 5: Try to allocate same line to different session
orderReject(fn() => $orderService->allocate(1, 1001, 1001, 0, array($enrollment1 => 1), 'Duplicate'), 'TrainingOrderLineAlreadyAllocated');
echo 'Order tests: Duplicate allocation rejected'.PHP_EOL;

// Test 6: Try to allocate with wrong product
// Create another product
$db->query("INSERT INTO tst_product (rowid, ref, label, price, tva_tx, entity, type) VALUES (2002, 'ORDER-PROD-002', 'Different Product', 1000.00, 25.00, 1, 1)");
$db->query("INSERT INTO tst_training_course_profile (rowid, entity, fk_product, datec, fk_user_author) VALUES (3, 1, 2002, NOW(), 7)");
$db->query("INSERT INTO tst_training_course_version (rowid, entity, fk_course_profile, version_number, status, program_json, duration_minutes, datec, fk_user_author, published_at, fk_user_publisher) VALUES (3, 1, 3, 1, 'published', '[]', 480, NOW(), 7, NOW(), 7)");
$db->query("INSERT INTO tst_training_session (rowid, entity, ref, label, fk_course_version, status, capacity, timezone, datec, fk_user_author) VALUES (3, 1, 'ORDER-SES-002', 'Different Product Session', 3, 'open', 10, 'Europe/Copenhagen', NOW(), 7)");
$db->query("INSERT INTO tst_training_session_slot (rowid, entity, fk_session, position, start_utc, end_utc) VALUES (5, 1, 3, 1, '2026-10-20 07:00:00', '2026-10-20 16:00:00'), (6, 1, 3, 2, '2026-10-21 07:00:00', '2026-10-21 16:00:00')");

orderReject(fn() => $orderService->allocate(3, 1001, 1001, 0, array($enrollment1 => 1), 'Wrong product'), 'TrainingOrderProductMismatch');
echo 'Order tests: Wrong product allocation rejected'.PHP_EOL;

// Test 7: Update allocation (correction)
$weights2 = array($enrollment1 => 2, $enrollment2 => 1, $enrollment3 => 1);
$result = $orderService->allocate(2, 1001, 1001, 1, $weights2, 'Updated weights');
orderVerify($result['revision'] === 2, 'correction creates new revision');
echo 'Order tests: Allocation correction works'.PHP_EOL;

// Test 8: Verify updated shares
$view = $orderService->inspect(2, 1001, 1001);
orderVerify(count($view['shares']) === 3, 'updated to three shares');
echo 'Order tests: Updated shares correct'.PHP_EOL;

// Test 9: Void allocation
$result = $orderService->voidLine(2, 1001, 1001, 2, 'Voided for testing');
orderVerify($result['revision'] === 3, 'void creates new revision');
echo 'Order tests: Void allocation works'.PHP_EOL;

// Test 10: Verify voided status
$view = $orderService->inspect(2, 1001, 1001);
orderVerify($view['mapping']->status === 'void', 'mapping status is void');
echo 'Order tests: Voided status correct'.PHP_EOL;

// Test 11: Reactivate voided allocation
$result = $orderService->allocate(2, 1001, 1001, 3, array($enrollment1 => 1, $enrollment2 => 1), 'Reactivated');
orderVerify($result['revision'] === 4, 'reactivation creates new revision');
$view = $orderService->inspect(2, 1001, 1001);
orderVerify($view['mapping']->status === 'active', 'mapping status is active again');
echo 'Order tests: Reactivation works'.PHP_EOL;

// Test 12: List allocations for session
$allocations = $orderService->forSession(2);
orderVerify(count($allocations) === 1, 'one allocation for session');
orderVerify($allocations[0]['mapping']->fk_commande === 1001, 'correct order in list');
echo 'Order tests: Session allocation list works'.PHP_EOL;

// Test 13: History
$history = $orderService->history(2, 1001, 1001);
orderVerify(count($history) >= 4, 'history contains multiple events');
echo 'Order tests: History tracking works'.PHP_EOL;

// Test 14: Participants list
$participants = $orderService->participants(2);
orderVerify(count($participants) === 3, 'three participants in session');
echo 'Order tests: Participants list works'.PHP_EOL;

// Test 15: Ineligible order (draft status)
$db->query("INSERT INTO tst_commande (rowid, entity, ref, fk_soc, fk_statut, type) VALUES (1002, 1, 'ORDER-DRAFT', 5001, 0, 0)");
$db->query("INSERT INTO tst_commandedet (rowid, fk_commande, entity, fk_product, product_type, description, qty, subprice, tva_tx, total_ht, total_ttc) VALUES (1002, 1002, 1, 2001, 1, 'Draft order line', 2, 1000.00, 25.00, 2000.00, 2500.00)");

$view = $orderService->inspect(2, 1002, 1002);
orderVerify($view['source']['eligible'] === false, 'draft order is not eligible');
echo 'Order tests: Ineligible order detection works'.PHP_EOL;

// Test 16: Conflict detection
// Simulate another user changing the allocation
orderReject(fn() => $orderService->allocate(2, 1001, 1001, 3, array($enrollment1 => 1), 'Conflict'), 'TrainingOrderConflict');
echo 'Order tests: Conflict detection works'.PHP_EOL;

echo 'All order line allocation MySQL tests passed.'.PHP_EOL;
