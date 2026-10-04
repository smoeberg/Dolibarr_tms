<?php
// A1: Price snapshot tests
// Tests that catalog price is frozen at enrollment confirmation time

// Load required classes
require_once __DIR__.'/mysql.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingpriceservice.class.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingenrollmentservice.class.php';

function priceStore($db) {
    return new TrainingStore($db, new TrainingAccess($db, 1, new TestUser(), array('training')));
}

// Create test product with price
$db->query("INSERT INTO tst_product (rowid, ref, label, price, tva_tx, price_base_type, entity, type) VALUES (1001, 'COURSE-001', 'Test Course', 1000.00, 25.00, 'DKK', 1, 1)");

// Create course profile and version
$db->query("INSERT INTO tst_training_course_profile (rowid, entity, fk_product, datec, fk_user_author) VALUES (1, 1, 1001, NOW(), 7)");
$db->query("INSERT INTO tst_training_course_version (rowid, entity, fk_course_profile, version_number, status, program_json, duration_minutes, datec, fk_user_author, published_at, fk_user_publisher) VALUES (1, 1, 1, 1, 'published', '[]', 480, NOW(), 7, NOW(), 7)");

// Create session
$db->query("INSERT INTO tst_training_session (rowid, entity, ref, label, fk_course_version, status, capacity, timezone, datec, fk_user_author) VALUES (1, 1, 'SES-001', 'Test Session', 1, 'open', 10, 'Europe/Copenhagen', NOW(), 7)");

// Create slots
$db->query("INSERT INTO tst_training_session_slot (rowid, entity, fk_session, position, start_utc, end_utc) VALUES (1, 1, 1, 1, '2026-10-20 07:00:00', '2026-10-20 16:00:00'), (2, 1, 1, 2, '2026-10-21 07:00:00', '2026-10-21 16:00:00')");

// Create contact
$db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES (21, 1, 'Test', 'User', 1)");

$store = priceStore($db);
$priceService = new TrainingPriceService($store);
$enrollmentService = new TrainingEnrollmentService($store);

// Test 1: Get catalog price
$catalogPrice = $priceService->getCatalogPrice(1);
verify($catalogPrice['fk_product'] === '1001', 'catalog price returns correct product');
verify($catalogPrice['currency'] === "'DKK'", 'catalog price returns correct currency');

echo 'Price snapshot tests: Catalog price retrieval works'.PHP_EOL;

// Test 2: Confirm enrollment and create price snapshot
$enrollmentId = $enrollmentService->confirm(1, 21);
verify($enrollmentId > 0, 'enrollment created successfully');

// Test 3: Verify price snapshot was created
$priceInfo = $priceService->getEnrollmentPrice($enrollmentId, 1);
verify($priceInfo !== null, 'price snapshot was created');
verify($priceInfo['enrollment_id'] === $enrollmentId, 'price snapshot linked to correct enrollment');
verify($priceInfo['session_id'] === 1, 'price snapshot linked to correct session');
verify($priceInfo['product_id'] === 1001, 'price snapshot linked to correct product');

echo 'Price snapshot tests: Snapshot creation works'.PHP_EOL;

// Test 4: Verify price is frozen (not affected by catalog changes)
// Change catalog price
$db->query("UPDATE tst_product SET price = 1500.00 WHERE rowid = 1001");

// Get price info - should still show original price
$priceInfoAfterChange = $priceService->getEnrollmentPriceInfo(1, $enrollmentId);
verify($priceInfoAfterChange['is_frozen'] === true, 'price is marked as frozen');
verify($priceInfoAfterChange['has_snapshot'] === true, 'price snapshot exists');

// The frozen price should be the original 1000, not the new 1500
// Note: We compare the string values as they are stored
$originalPrice = $priceInfo['price_ttc'];
verify($priceInfoAfterChange['price_ttc'] === $originalPrice, 'frozen price unchanged after catalog price change');

echo 'Price snapshot tests: Price freezing works'.PHP_EOL;

// Test 5: New enrollment gets new catalog price
$enrollmentId2 = $enrollmentService->confirm(1, 21); // Re-confirm same contact (should reuse)
// Actually, let's create a new contact
$db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES (22, 1, 'Test2', 'User2', 1)");
$enrollmentId2 = $enrollmentService->confirm(1, 22);

$priceInfo2 = $priceService->getEnrollmentPrice($enrollmentId2, 1);
verify($priceInfo2 !== null, 'new enrollment has price snapshot');
verify($priceInfo2['product_id'] === 1001, 'new enrollment linked to correct product');

// The new enrollment should have the NEW catalog price (1500)
$priceInfo2Display = $priceService->getEnrollmentPriceInfo(1, $enrollmentId2);
verify($priceInfo2Display['is_frozen'] === true, 'new enrollment price is frozen');

// Verify the new price is different from the old
verify($priceInfo2Display['price_ttc'] !== $originalPrice, 'new enrollment has updated catalog price');

echo 'Price snapshot tests: New enrollment gets current catalog price'.PHP_EOL;

// Test 6: List enrollments with price info
$enrollments = $enrollmentService->listForSession(1);
verify(count($enrollments) >= 2, 'session has multiple enrollments');

foreach ($enrollments as $enrollment) {
    verify(isset($enrollment->price_ht), 'enrollment has price_ht');
    verify(isset($enrollment->price_ttc), 'enrollment has price_ttc');
    verify(isset($enrollment->currency), 'enrollment has currency');
    verify(isset($enrollment->is_price_frozen), 'enrollment has is_price_frozen');
    verify($enrollment->is_price_frozen === true, 'enrollment price is frozen');
}

echo 'Price snapshot tests: Enrollment list with price info works'.PHP_EOL;

// Test 7: Audit logging
$auditRows = $db->query("SELECT * FROM tst_training_audit WHERE object_type='enrollment_price' ORDER BY rowid")->fetch_all(MYSQLI_ASSOC);
verify(count($auditRows) >= 2, 'price snapshots are audited');

foreach ($auditRows as $audit) {
    $meta = json_decode($audit['metadata_json'], true);
    verify(isset($meta['enrollment_id']), 'audit contains enrollment_id');
    verify(isset($meta['session_id']), 'audit contains session_id');
    verify(isset($meta['price_ht']), 'audit contains price_ht');
    verify(isset($meta['currency']), 'audit contains currency');
}

echo 'Price snapshot tests: Audit logging works'.PHP_EOL;

echo 'All price snapshot MySQL tests passed.'.PHP_EOL;
