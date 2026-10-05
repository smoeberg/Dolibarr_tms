<?php
// A1: Price snapshot tests
// Tests that catalog price is frozen at enrollment confirmation time

require_once __DIR__.'/mysql.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingpriceservice.class.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingenrollmentservice.class.php';

function priceStore($db) {
    return new TrainingStore($db, new TrainingAccess($db, 1, new TestUser(), array('training')));
}

// Dolibarr semantics: price_base_type is HT/TTC, never currency.
$db->query("INSERT INTO tst_product (rowid, ref, label, price, price_ttc, tva_tx, price_base_type, entity, type) VALUES (1001, 'COURSE-001', 'Test Course', 1000.00, 1250.00, 25.00, 'HT', 1, 1)");

$db->query("INSERT INTO tst_training_course_profile (rowid, entity, fk_product, datec, fk_user_author) VALUES (1, 1, 1001, NOW(), 7)");
$db->query("INSERT INTO tst_training_course_version (rowid, entity, fk_course_profile, version_number, status, program_json, duration_minutes, datec, fk_user_author, published_at, fk_user_publisher) VALUES (1, 1, 1, 1, 'published', '[]', 480, NOW(), 7, NOW(), 7)");
$db->query("INSERT INTO tst_training_session (rowid, entity, ref, label, fk_course_version, status, capacity, timezone, datec, fk_user_author) VALUES (1, 1, 'SES-001', 'Test Session', 1, 'open', 10, 'Europe/Copenhagen', NOW(), 7)");
$db->query("INSERT INTO tst_training_session_slot (rowid, entity, fk_session, position, start_utc, end_utc) VALUES (1, 1, 1, 1, '2026-10-20 07:00:00', '2026-10-20 16:00:00'), (2, 1, 1, 2, '2026-10-21 07:00:00', '2026-10-21 16:00:00')");
$db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES (21, 1, 'Test', 'User', 1)");

$store = priceStore($db);
$priceService = new TrainingPriceService($store);
$enrollmentService = new TrainingEnrollmentService($store);

// Test 1: Get catalog price using standard Dolibarr HT price.
$catalogPrice = $priceService->getCatalogPrice(1);
verify($catalogPrice['fk_product'] === 1001, 'catalog price returns correct product');
verify($catalogPrice['currency'] === 'DKK', 'catalog price returns entity currency, not price_base_type');
verify($catalogPrice['price_ht'] === '1000.00000000', 'HT price comes from Dolibarr product price');
verify($catalogPrice['price_ttc'] === '1250.00000000', 'TTC price comes from Dolibarr product price_ttc');
verify($catalogPrice['tva_tx'] === '25.00000000', 'VAT rate is snapshotted');

echo 'Price snapshot tests: Catalog price retrieval works'.PHP_EOL;

// Test 2: Confirm enrollment and create price snapshot.
$enrollmentId = $enrollmentService->confirm(1, 21);
verify($enrollmentId > 0, 'enrollment created successfully');

// Test 3: Verify price snapshot was created.
$priceInfo = $priceService->getEnrollmentPrice($enrollmentId, 1);
verify($priceInfo !== null, 'price snapshot was created');
verify($priceInfo['enrollment_id'] === $enrollmentId, 'price snapshot linked to correct enrollment');
verify($priceInfo['session_id'] === 1, 'price snapshot linked to correct session');
verify($priceInfo['product_id'] === 1001, 'price snapshot linked to correct product');
verify($priceInfo['currency'] === 'DKK', 'snapshot stores currency, not price_base_type');
verify((float) $priceInfo['price_ht'] === 1000.0, 'snapshot stores HT amount');
verify((float) $priceInfo['price_ttc'] === 1250.0, 'snapshot stores TTC amount');

echo 'Price snapshot tests: Snapshot creation works'.PHP_EOL;

// Test 4: Verify price is frozen after catalog changes.
$db->query("UPDATE tst_product SET price = 1500.00, price_ttc = 1875.00 WHERE rowid = 1001");
$priceInfoAfterChange = $priceService->getEnrollmentPriceInfo(1, $enrollmentId);
verify($priceInfoAfterChange['is_frozen'] === true, 'price is marked as frozen');
verify($priceInfoAfterChange['has_snapshot'] === true, 'price snapshot exists');
verify((float) $priceInfoAfterChange['price_ht'] === 1000.0, 'frozen HT price unchanged after catalog change');
verify((float) $priceInfoAfterChange['price_ttc'] === 1250.0, 'frozen TTC price unchanged after catalog change');

echo 'Price snapshot tests: Price freezing works'.PHP_EOL;

// Test 5: A new enrollment receives the new catalog price.
$db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES (22, 1, 'Test2', 'User2', 1)");
$enrollmentId2 = $enrollmentService->confirm(1, 22);
$priceInfo2 = $priceService->getEnrollmentPrice($enrollmentId2, 1);
verify($priceInfo2 !== null, 'new enrollment has price snapshot');
verify((float) $priceInfo2['price_ht'] === 1500.0, 'new enrollment gets current HT catalog price');
verify((float) $priceInfo2['price_ttc'] === 1875.0, 'new enrollment gets current TTC catalog price');
verify($priceInfo2['currency'] === 'DKK', 'new snapshot keeps entity currency');

echo 'Price snapshot tests: New enrollment gets current catalog price'.PHP_EOL;

// Test 6: Explicit TTC-base catalog price. Dolibarr stores the canonical HT
// value in price and the entered TTC value in price_ttc after normalization.
$db->query("UPDATE tst_product SET price = 1600.00, price_ttc = 2000.00, price_base_type = 'TTC' WHERE rowid = 1001");
$catalogTtcBase = $priceService->getCatalogPrice(1);
verify($catalogTtcBase['currency'] === 'DKK', 'TTC-base catalog still returns entity currency');
verify($catalogTtcBase['price_ht'] === '1600.00000000', 'TTC-base catalog uses canonical Dolibarr HT value');
verify($catalogTtcBase['price_ttc'] === '2000.00000000', 'TTC-base catalog uses canonical Dolibarr TTC value');

$db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES (23, 1, 'Test3', 'User3', 1)");
$enrollmentId3 = $enrollmentService->confirm(1, 23);
$priceInfo3 = $priceService->getEnrollmentPrice($enrollmentId3, 1);
verify((float) $priceInfo3['price_ht'] === 1600.0, 'TTC-base snapshot stores canonical HT value');
verify((float) $priceInfo3['price_ttc'] === 2000.0, 'TTC-base snapshot stores canonical TTC value');

// Test 7: Enrollment list contains frozen price information.
$enrollments = $enrollmentService->listForSession(1);
verify(count($enrollments) >= 3, 'session has multiple enrollments');
foreach ($enrollments as $enrollment) {
    verify(isset($enrollment->price_ht), 'enrollment has price_ht');
    verify(isset($enrollment->price_ttc), 'enrollment has price_ttc');
    verify(isset($enrollment->currency), 'enrollment has currency');
    verify(isset($enrollment->is_price_frozen), 'enrollment has is_price_frozen');
    verify($enrollment->is_price_frozen === true, 'enrollment price is frozen');
}

echo 'Price snapshot tests: Enrollment list with price info works'.PHP_EOL;

// Test 8: Audit logging.
$auditRows = $db->query("SELECT * FROM tst_training_audit WHERE object_type='enrollment_price' ORDER BY rowid")->fetch_all(MYSQLI_ASSOC);
verify(count($auditRows) >= 3, 'price snapshots are audited');
foreach ($auditRows as $audit) {
    $meta = json_decode($audit['metadata_json'], true);
    verify(isset($meta['enrollment_id']), 'audit contains enrollment_id');
    verify(isset($meta['session_id']), 'audit contains session_id');
    verify(isset($meta['price_ht']), 'audit contains price_ht');
    verify(isset($meta['currency']), 'audit contains currency');
}

echo 'Price snapshot tests: Audit logging works'.PHP_EOL;
echo 'All price snapshot MySQL tests passed.'.PHP_EOL;
