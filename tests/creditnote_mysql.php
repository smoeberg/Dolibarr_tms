<?php
/** Training credit note allocation MySQL tests. Run with: php tests/creditnote_mysql.php */

require_once __DIR__.'/bootstrap.php';

class TrainingCreditNoteMySQLTest extends TrainingMySQLTestCase
{
    private $store;
    private $creditNote;
    private $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new TrainingStore($this->db, $this->access);
        $this->creditNote = new TrainingCreditNoteService($this->store, 'DKK');
        $this->payment = new TrainingPaymentService($this->store);
    }

    // --- Helper: create test fixtures ---

    private function createSession($fkProduct = null): int
    {
        if ($fkProduct === null) {
            $fkProduct = $this->createProduct();
        }
        $this->db->query('INSERT INTO '.$this->prefix.'training_session (entity, ref, fk_product, fk_course_version, title, capacity, timezone) VALUES (1, ".", '.$fkProduct.', 1, "Test", 100, "Europe/Copenhagen")');
        return (int) $this->db->last_insert_id($this->prefix.'training_session');
    }

    private function createEnrollment($fkSession, $fkLearner): int
    {
        $this->db->query('INSERT INTO '.$this->prefix.'training_learner (entity, fk_socpeople) VALUES (1, '.$fkLearner.')');
        $fkLearnerId = (int) $this->db->last_insert_id($this->prefix.'training_learner');
        $this->db->query('INSERT INTO '.$this->prefix.'training_enrollment (entity, fk_session, fk_learner, status) VALUES (1, '.$fkSession.', '.$fkLearnerId.', \'confirmed\')');
        return (int) $this->db->last_insert_id($this->prefix.'training_enrollment');
    }

    private function createPriceSnapshot($fkEnrollment, $fkSession, $priceTTC = '100.00'): void
    {
        $this->db->query('INSERT INTO '.$this->prefix.'training_enrollment_price (entity, fk_enrollment, fk_session, fk_product, price_ht, price_ttc, tva_tx, currency, unit_price_ht, unit_price_ttc, qty, datec, fk_user_author) VALUES (1, '.$fkEnrollment.', '.$fkSession.', 1, \'80.00\', \''.$priceTTC.'\', \'25.00\', \'DKK\', \'80.00\', \''.$priceTTC.'\', \'1\', NOW(), 1)');
    }

    private function createCreditNote($fkSoc, $amountTTC = '50.00'): array
    {
        // Create credit note header (type=2)
        $this->db->query('INSERT INTO llx_facture (entity, ref, fk_soc, type, fk_statut, datef, total_ht, total_tva, total_ttc, multicurrency_code) VALUES (1, \'CN-001\', '.$fkSoc.', 2, 1, NOW(), \'40.00\', \'10.00\', \''.$amountTTC.'\', \'DKK\')');
        $fkFacture = (int) $this->db->last_insert_id('llx_facture');
        
        // Create credit note line
        $this->db->query('INSERT INTO llx_facturedet (fk_facture, fk_product, product_type, description, qty, subprice, tva_tx, total_ht, total_tva, total_ttc) VALUES ('.$fkFacture.', 1, 1, \'Test Credit\', 1, \'40.00\', \'25.00\', \'40.00\', \'10.00\', \'50.00\')');
        $fkFactureDet = (int) $this->db->last_insert_id('llx_facturedet');
        
        return array('fk_facture' => $fkFacture, 'fk_facturedet' => $fkFactureDet);
    }

    // --- Test cases ---

    public function testCreditNoteAllocationCreatesCorrectBalance(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        // Allocate credit note to enrollment
        $result = $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '50.00'),
            'Test credit note allocation'
        );
        
        $this->assertCount(1, $result['allocation_ids']);
        
        // Check balance with credit notes
        $balance = $this->creditNote->getEnrollmentBalanceWithCreditNotes($fkSession, $fkEnrollment);
        $this->assertEquals('100.00', $balance['frozen_price']);
        $this->assertEquals('50.00', $balance['total_credit_notes']);
        $this->assertEquals('50.00', $balance['remaining_after_credits']);
    }

    public function testCreditNoteAllocationExceedsFrozenPriceThrows(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '200.00');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TrainingCreditNoteExceedsFrozenPrice');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '150.00'),
            'Test'
        );
    }

    public function testCreditNoteAllocationExceedsCreditNoteAmountThrows(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment1 = $this->createEnrollment($fkSession, $fkContact);
        $fkEnrollment2 = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment1, $fkSession, '100.00');
        $this->createPriceSnapshot($fkEnrollment2, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TrainingCreditNoteExceedsCreditNoteAmount');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment1 => '30.00', $fkEnrollment2 => '30.00'),
            'Test'
        );
    }

    public function testCreditNoteCurrencyMismatchThrows(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        // Create credit note in EUR (different currency)
        $this->db->query('INSERT INTO llx_facture (entity, ref, fk_soc, type, fk_statut, datef, total_ht, total_tva, total_ttc, multicurrency_code) VALUES (1, \'CN-EUR-001\', '.$fkSoc.', 2, 1, NOW(), \'40.00\', \'10.00\', \'50.00\', \'EUR\')');
        $fkFacture = (int) $this->db->last_insert_id('llx_facture');
        $this->db->query('INSERT INTO llx_facturedet (fk_facture, fk_product, product_type, description, qty, subprice, tva_tx, total_ht, total_tva, total_ttc) VALUES ('.$fkFacture.', 1, 1, \'Test Credit EUR\', 1, \'40.00\', \'25.00\', \'40.00\', \'10.00\', \'50.00\')');
        $fkFactureDet = (int) $this->db->last_insert_id('llx_facturedet');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TrainingCreditNoteCurrencyMismatch');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $fkFacture,
            $fkFactureDet,
            array($fkEnrollment => '50.00'),
            'Test'
        );
    }

    public function testCreditNoteAdjustAllocation(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $result = $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '30.00'),
            'Initial allocation'
        );
        
        $allocationId = $result['allocation_ids'][0];
        
        // Adjust the allocation
        $adjustResult = $this->creditNote->adjustAllocation(
            $fkSession,
            $creditNote['fk_facture'],
            $allocationId,
            '20.00',
            'Adjustment reason'
        );
        
        $this->assertEquals($allocationId, $adjustResult['allocation_id']);
        $this->assertEquals('30.00', $adjustResult['old_amount']);
        $this->assertEquals('20.00', $adjustResult['new_amount']);
        
        // Verify balance
        $balance = $this->creditNote->getEnrollmentBalanceWithCreditNotes($fkSession, $fkEnrollment);
        $this->assertEquals('20.00', $balance['total_credit_notes']);
        $this->assertEquals('80.00', $balance['remaining_after_credits']);
    }

    public function testCreditNoteRemoveAllocation(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $result = $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '30.00'),
            'Initial allocation'
        );
        
        $allocationId = $result['allocation_ids'][0];
        
        // Remove the allocation
        $this->creditNote->removeAllocation(
            $fkSession,
            $creditNote['fk_facture'],
            $allocationId,
            'Removal reason'
        );
        
        // Verify balance (should be back to full price)
        $balance = $this->creditNote->getEnrollmentBalanceWithCreditNotes($fkSession, $fkEnrollment);
        $this->assertEquals('0.00', $balance['total_credit_notes']);
        $this->assertEquals('100.00', $balance['remaining_after_credits']);
        
        // Check that allocation is voided, not deleted
        $allocs = $this->creditNote->enrollmentAllocations($fkSession, $fkEnrollment);
        $this->assertCount(1, $allocs);
        $this->assertEquals('voided', $allocs[0]->status);
    }

    public function testCreditNoteSessionSummary(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        
        // Create 3 enrollments with different credit note allocations
        $fkEnrollment1 = $this->createEnrollment($fkSession, $fkContact);
        $fkEnrollment2 = $this->createEnrollment($fkSession, $fkContact);
        $fkEnrollment3 = $this->createEnrollment($fkSession, $fkContact);
        
        $this->createPriceSnapshot($fkEnrollment1, $fkSession, '100.00');
        $this->createPriceSnapshot($fkEnrollment2, $fkSession, '200.00');
        $this->createPriceSnapshot($fkEnrollment3, $fkSession, '150.00');
        
        $creditNote1 = $this->createCreditNote($fkSoc, '50.00');
        $creditNote2 = $this->createCreditNote($fkSoc, '100.00');
        
        $this->creditNote->allocateCreditNote($fkSession, $creditNote1['fk_facture'], $creditNote1['fk_facturedet'], array($fkEnrollment1 => '50.00'), 'Test 1');
        $this->creditNote->allocateCreditNote($fkSession, $creditNote2['fk_facture'], $creditNote2['fk_facturedet'], array($fkEnrollment2 => '100.00'), 'Test 2');
        
        $summary = $this->creditNote->sessionCreditNoteSummary($fkSession);
        
        $this->assertEquals(3, $summary['total_enrollments']);
        $this->assertEquals('450.00', $summary['total_frozen_amount']);
        $this->assertEquals('150.00', $summary['total_credit_notes']);
        $this->assertEquals('300.00', $summary['total_remaining_after_credits']);
    }

    public function testCreditNoteAllocationRequiresConfirmedEnrollment(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        
        // Create enrollment but don't confirm it
        $this->db->query('INSERT INTO '.$this->prefix.'training_learner (entity, fk_socpeople) VALUES (1, '.$fkContact.')');
        $fkLearnerId = (int) $this->db->last_insert_id($this->prefix.'training_learner');
        $this->db->query('INSERT INTO '.$this->prefix.'training_enrollment (entity, fk_session, fk_learner, status) VALUES (1, '.$fkSession.', '.$fkLearnerId.', \'draft\')');
        $fkEnrollment = (int) $this->db->last_insert_id($this->prefix.'training_enrollment');
        
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TrainingCreditNoteConfirmedRequired');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '50.00'),
            'Test'
        );
    }

    public function testCreditNoteAllocationRequiresReason(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TrainingCreditNoteReasonRequired');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '50.00'),
            ''
        );
    }

    public function testCreditNoteInvalidAmountThrows(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TrainingCreditNoteInvalidAmount');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => 'invalid-amount'),
            'Test'
        );
    }

    public function testSessionAllocationsReturnsCreditNotes(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '50.00'),
            'Test'
        );
        
        $allocations = $this->creditNote->sessionAllocations($fkSession);
        $this->assertCount(1, $allocations);
        $this->assertEquals($creditNote['fk_facture'], $allocations[0]->fk_facture);
        $this->assertEquals('50.00', $allocations[0]->amount);
    }

    public function testCreditNoteHistoryTracking(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $result = $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '50.00'),
            'Initial allocation'
        );
        
        $allocationId = $result['allocation_ids'][0];
        $this->creditNote->adjustAllocation($fkSession, $creditNote['fk_facture'], $allocationId, '30.00', 'Adjustment');
        
        $history = $this->creditNote->history($fkSession, $creditNote['fk_facture'], $creditNote['fk_facturedet']);
        $this->assertGreaterThanOrEqual(2, count($history));
    }

    public function testCreditNoteBalanceHistory(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        
        $result = $this->creditNote->allocateCreditNote(
            $fkSession,
            $creditNote['fk_facture'],
            $creditNote['fk_facturedet'],
            array($fkEnrollment => '50.00'),
            'Test'
        );
        
        $history = $this->creditNote->enrollmentCreditNoteHistory($fkSession, $fkEnrollment);
        $this->assertCount(1, $history);
        $this->assertEquals('credit', $history[0]->adjustment_type);
        $this->assertEquals('50.00', $history[0]->amount);
    }

    public function testPaymentServiceGetEnrollmentBalanceWithCreditNotes(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '30.00');
        $this->creditNote->allocateCreditNote($fkSession, $creditNote['fk_facture'], $creditNote['fk_facturedet'], array($fkEnrollment => '30.00'), 'Test');
        
        // Test the PaymentService method with credit notes
        $balance = $this->payment->getEnrollmentBalanceWithCreditNotes($fkSession, $fkEnrollment);
        $this->assertEquals('100.00', $balance['frozen_price']);
        $this->assertEquals('30.00', $balance['total_credit_notes']);
        $this->assertEquals('70.00', $balance['remaining_after_credits']);
        $this->assertTrue($balance['is_paid_after_credits']);
    }

    public function testPaymentServiceSessionSummaryWithCreditNotes(): void
    {
        $fkSoc = $this->createSociete();
        $fkContact = $this->createContact($fkSoc);
        $fkSession = $this->createSession();
        $fkEnrollment = $this->createEnrollment($fkSession, $fkContact);
        $this->createPriceSnapshot($fkEnrollment, $fkSession, '100.00');
        
        $creditNote = $this->createCreditNote($fkSoc, '50.00');
        $this->creditNote->allocateCreditNote($fkSession, $creditNote['fk_facture'], $creditNote['fk_facturedet'], array($fkEnrollment => '50.00'), 'Test');
        
        // Add a payment allocation
        $this->createPaiement($fkSoc, '60.00');
        $paymentId = (int) $this->db->last_insert_id('llx_paiement');
        $this->payment->allocatePayment($fkSession, $paymentId, array($fkEnrollment => '60.00'), 'Payment test');
        
        $summary = $this->payment->sessionPaymentSummary($fkSession);
        $this->assertEquals('100.00', $summary['total_frozen_amount']);
        $this->assertEquals('60.00', $summary['total_allocated']);
        $this->assertEquals('50.00', $summary['total_credit_notes']);
        $this->assertEquals('-10.00', $summary['total_remaining_after_credits']);
    }
}
