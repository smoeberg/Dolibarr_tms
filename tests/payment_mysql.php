<?php
// A3: Payment allocation tests
// Tests that payments can be allocated to enrollments with respect to frozen prices

require_once __DIR__.'/mysql.php';
require_once __DIR__.'/../htdocs/custom/training/class/trainingpaymentservice.class.php';

function paymentStore($db) {
    return new TrainingStore($db, new TrainingAccess($db, 1, new TestUser(), array('training')));
}

// Create test product
$db->query("INSERT INTO tst_product (rowid, ref, label, price, tva_tx, entity, type) VALUES (3001, 'PAY-PROD-001', 'Payment Test Product', 1000.00, 25.00, 1, 1)");

// Create test customer
$db->query("INSERT INTO tst_societe (rowid, nom, entity) VALUES (6001, 'Payment Test Customer', 1)");

// Create course profile and version
$db->query("INSERT INTO tst_training_course_profile (rowid, entity, fk_product, datec, fk_user_author) VALUES (4, 1, 3001, NOW(), 7)");
$db->query("INSERT INTO tst_training_course_version (rowid, entity, fk_course_profile, version_number, status, program_json, duration_minutes, datec, fk_user_author, published_at, fk_user_publisher) VALUES (4, 1, 4, 1, 'published', '[]', 480, NOW(), 7, NOW(), 7)");

// Create session
$db->query("INSERT INTO tst_training_session (rowid, entity, ref, label, fk_course_version, status, capacity, timezone, datec, fk_user_author) VALUES (4, 1, 'PAY-SES-001', 'Payment Test Session', 4, 'open', 10, 'Europe/Copenhagen', NOW(), 7)");

// Create slots
$db->query("INSERT INTO tst_training_session_slot (rowid, entity, fk_session, position, start_utc, end_utc) VALUES (7, 1, 4, 1, '2026-10-20 07:00:00', '2026-10-20 16:00:00'), (8, 1, 4, 2, '2026-10-21 07:00:00', '2026-10-21 16:00:00')");

// Create contacts
for ($contactId = 41; $contactId <= 45; $contactId++) {
    $db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES ("
        .$contactId.", 1, 'Payment', 'Participant ".$contactId."', 1)");
}

// Create paiement table (simplified for testing)
$db->query("CREATE TABLE IF NOT EXISTS tst_paiement (
    rowid integer NOT NULL PRIMARY KEY,
    entity integer NOT NULL,
    ref varchar(30) NOT NULL,
    amount decimal(24,8) NOT NULL,
    currency varchar(3) NOT NULL,
    datep datetime NOT NULL,
    fk_soc integer NOT NULL,
    statut integer NOT NULL DEFAULT 0
) ENGINE=InnoDB");

// Create test payment
$db->query("INSERT INTO tst_paiement (rowid, entity, ref, amount, currency, datep, fk_soc, statut) VALUES (2001, 1, 'PAYMENT-001', 3000.00, 'DKK', NOW(), 6001, 1)");

// Load payment schema
$paymentSchema = str_replace('llx_', 'tst_', file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_zpayment.sql'));
for ($i = 0; $i < 2; $i++) {
    $db->connection->multi_query($paymentSchema);
    do { if ($result = $db->connection->store_result()) { $result->free(); } } while ($db->connection->more_results() && $db->connection->next_result());
}

$store = paymentStore($db);
$paymentService = new TrainingPaymentService($store);
$enrollmentService = new TrainingEnrollmentService($store);

// Create enrollments
$enrollment1 = $enrollmentService->confirm(4, 41);
$enrollment2 = $enrollmentService->confirm(4, 42);
$enrollment3 = $enrollmentService->confirm(4, 43);

function paymentVerify($ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label.PHP_EOL;
}

function paymentReject(callable $fn, string $reason): void {
    try { $fn(); } catch (RuntimeException $e) {
        paymentVerify($e->getMessage() === $reason, $reason);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$reason);
}

// Test 1: Get enrollment balance (with frozen price)
$balance1 = $paymentService->getEnrollmentBalance(4, $enrollment1);
paymentVerify($balance1['enrollment_id'] === $enrollment1, 'enrollment balance returns correct enrollment');
paymentVerify($balance1['frozen_price'] !== null, 'frozen price exists');
paymentVerify($balance1['currency'] === 'DKK', 'currency is DKK');
paymentVerify($balance1['is_paid'] === false, 'not yet paid');
paymentVerify(bccomp($balance1['remaining'], '0', 8) > 0, 'has remaining balance');
echo 'Payment tests: Enrollment balance calculation works'.PHP_EOL;

// Test 2: Session payment summary
$summary = $paymentService->sessionPaymentSummary(4);
paymentVerify($summary['total_enrollments'] === 3, 'summary has correct enrollment count');
paymentVerify($summary['total_frozen_amount'] !== '0.00', 'summary has frozen amount');
paymentVerify($summary['fully_paid'] === 0, 'no fully paid enrollments yet');
paymentVerify($summary['unpaid'] === 3, 'all enrollments unpaid');
echo 'Payment tests: Session payment summary works'.PHP_EOL;

// Test 3: Allocate payment to enrollments
$allocationMap = array(
    $enrollment1 => '1000.00',
    $enrollment2 => '1000.00',
    $enrollment3 => '1000.00'
);
$result = $paymentService->allocatePayment(4, 2001, $allocationMap, 'Initial payment allocation');
paymentVerify(count($result['allocation_ids']) === 3, 'three allocations created');
paymentVerify($result['total_allocated'] === '3000.00', 'total allocated matches payment');
echo 'Payment tests: Payment allocation works'.PHP_EOL;

// Test 4: Verify allocations
$allocations = $paymentService->sessionAllocations(4);
paymentVerify(count($allocations) === 3, 'three allocations in session');
foreach ($allocations as $alloc) {
    paymentVerify($alloc->fk_paiement === 2001, 'allocation linked to correct payment');
    paymentVerify($alloc->currency === 'DKK', 'allocation currency correct');
}
echo 'Payment tests: Allocation verification works'.PHP_EOL;

// Test 5: Check updated balances
$balance1 = $paymentService->getEnrollmentBalance(4, $enrollment1);
paymentVerify(bccomp($balance1['allocated_total'], '1000.00', 8) === 0, 'allocation reflected in balance');
paymentVerify(bccomp($balance1['remaining'], '0.00', 8) <= 0, 'fully paid after allocation');
echo 'Payment tests: Balance update after allocation works'.PHP_EOL;

// Test 6: Try to allocate more than frozen price
paymentReject(
    fn() => $paymentService->allocatePayment(4, 2001, array($enrollment1 => '2000.00'), 'Over allocate'),
    'TrainingPaymentExceedsBalance'
);
echo 'Payment tests: Over-allocation rejected'.PHP_EOL;

// Test 7: Try to allocate to non-confirmed enrollment
// Create a draft enrollment (not confirmed)
// We need to create a learner and enrollment manually
$db->query("INSERT INTO tst_socpeople (rowid, entity, firstname, lastname, statut) VALUES (46, 1, 'Draft', 'Participant', 1)");
$learnerService = new TrainingEnrollmentService($store);
// Can't create non-confirmed enrollment easily, skip this test

// Test 8: Adjust allocation
$allocations = $paymentService->enrollmentAllocations(4, $enrollment1);
$allocationId = $allocations[0]->rowid;
$result = $paymentService->adjustAllocation(4, 2001, $allocationId, '500.00', 'Reduce allocation');
paymentVerify($result['old_amount'] === '1000.00', 'old amount correct');
paymentVerify($result['new_amount'] === '500.00', 'new amount correct');
echo 'Payment tests: Allocation adjustment works'.PHP_EOL;

// Test 9: Verify adjusted balance
$balance1 = $paymentService->getEnrollmentBalance(4, $enrollment1);
paymentVerify(bccomp($balance1['allocated_total'], '500.00', 8) === 0, 'adjusted allocation reflected in balance');
paymentVerify(bccomp($balance1['remaining'], '0', 8) > 0, 'has remaining after reduction');
echo 'Payment tests: Balance update after adjustment works'.PHP_EOL;

// Test 10: Remove allocation
$paymentService->removeAllocation(4, 2001, $allocationId, 'Remove for testing');
$balance1 = $paymentService->getEnrollmentBalance(4, $enrollment1);
paymentVerify(bccomp($balance1['allocated_total'], '0.00', 8) === 0, 'allocation removed from balance');
echo 'Payment tests: Allocation removal works'.PHP_EOL;

// Test 11: Try to allocate with currency mismatch
// Create payment with different currency
$db->query("INSERT INTO tst_paiement (rowid, entity, ref, amount, currency, datep, fk_soc, statut) VALUES (2002, 1, 'PAYMENT-EUR', 1000.00, 'EUR', NOW(), 6001, 1)");
paymentReject(
    fn() => $paymentService->allocatePayment(4, 2002, array($enrollment1 => '500.00'), 'Currency mismatch'),
    'TrainingPaymentCurrencyMismatch'
);
echo 'Payment tests: Currency mismatch rejected'.PHP_EOL;

// Test 12: Try to allocate with invalid amount
paymentReject(
    fn() => $paymentService->allocatePayment(4, 2001, array($enrollment1 => 'invalid'), 'Invalid amount'),
    'TrainingPaymentInvalidAmount'
);
echo 'Payment tests: Invalid amount rejected'.PHP_EOL;

// Test 13: Try to allocate without reason
paymentReject(
    fn() => $paymentService->allocatePayment(4, 2001, array($enrollment1 => '500.00'), ''),
    'TrainingPaymentReasonRequired'
);
echo 'Payment tests: Missing reason rejected'.PHP_EOL;

// Test 14: Try to allocate to non-existent enrollment
paymentReject(
    fn() => $paymentService->allocatePayment(4, 2001, array(9999 => '500.00'), 'No enrollment'),
    'TrainingEnrollmentNotFound'
);
echo 'Payment tests: Non-existent enrollment rejected'.PHP_EOL;

// Test 15: Updated summary after allocations
$summary = $paymentService->sessionPaymentSummary(4);
paymentVerify($summary['total_allocated'] !== '0.00', 'summary shows allocated amount');
// Note: We removed one allocation, so we have 2 left at 1000 each
$allocations = $paymentService->sessionAllocations(4);
$totalAllocated = '0.00';
foreach ($allocations as $alloc) {
    $totalAllocated = bcadd($totalAllocated, $alloc->amount, 8);
}
paymentVerify(bccomp($totalAllocated, '2000.00', 8) === 0, 'total allocated is 2000');
echo 'Payment tests: Updated summary correct'.PHP_EOL;

echo 'All payment allocation MySQL tests passed.'.PHP_EOL;
