<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingpaymentadapter.class.php';
require_once __DIR__.'/trainingpriceservice.class.php';

/**
 * Payment allocation service.
 * Links standard Dolibarr payments (Paiement) to training enrollments.
 * Tracks which payments cover which enrollment fees.
 * Respects the frozen price from A1 (TrainingPriceService).
 */
final class TrainingPaymentService
{
    private TrainingStore $s;
    private TrainingPaymentAdapter $payments;
    private TrainingPriceService $prices;

    public function __construct(TrainingStore $store) {
        $this->s = $store;
        $this->payments = new TrainingPaymentAdapter($store);
        $this->prices = new TrainingPriceService($store);
    }

    private function allow(string $right): void {
        $this->s->access->requireDomain('session', 'read');
        $this->s->access->requireDomain('payment', $right);
        $this->s->access->requirePaymentRead();
        $this->s->access->requireContactRead();
    }

    /**
     * Get enrollment details with price info.
     */
    private function enrollment(int $sessionId, int $enrollmentId): object {
        $rows = $this->s->rows(
            'SELECT e.*, l.fk_socpeople FROM '.$this->s->table('enrollment').' e '.
            'JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity '.
            'WHERE e.rowid='.$enrollmentId.' AND e.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId
        );
        if (!$rows) {
            throw new RuntimeException('TrainingEnrollmentNotFound');
        }
        $this->s->contact((int) $rows[0]->fk_socpeople);
        return $rows[0];
    }

    /**
     * Get price snapshot for an enrollment.
     */
    private function getEnrollmentPrice(int $sessionId, int $enrollmentId): ?array {
        return $this->prices->getEnrollmentPrice($enrollmentId, $sessionId);
    }

    /**
     * Get allocated payments for an enrollment.
     */
    private function allocatedPayments(int $enrollmentId): array {
        return $this->s->rows(
            'SELECT pa.rowid, pa.fk_paiement, pa.amount, pa.currency, pa.datec, ps.paiement_ref, ps.datep as payment_date '.
            'FROM '.$this->s->table('payment_allocation').' pa '.
            'LEFT JOIN '.$this->s->table('payment_snapshot').' ps ON ps.fk_allocation = pa.rowid AND ps.entity = pa.entity '.
            'WHERE pa.entity='.$this->s->access->entity().
            ' AND pa.fk_enrollment='.$enrollmentId.
            ' ORDER BY pa.rowid'
        );
    }

    /**
     * Get remaining balance for an enrollment.
     * Returns the difference between frozen price and allocated payments.
     * Note: This does NOT account for credit note deductions. Use getEnrollmentBalanceWithCreditNotes() for that.
     */
    public function getEnrollmentBalance(int $sessionId, int $enrollmentId): array {
        $this->allow('read');
        
        $enrollment = $this->enrollment($sessionId, $enrollmentId);
        $priceInfo = $this->getEnrollmentPrice($sessionId, $enrollmentId);
        
        $allocated = $this->allocatedPayments($enrollmentId);
        
        $totalAllocated = '0.00';
        $currency = null;
        
        if ($priceInfo) {
            $currency = $priceInfo['currency'];
            foreach ($allocated as $payment) {
                if ($payment->currency === $currency) {
                    $totalAllocated = bcadd($totalAllocated, $payment->amount, 8);
                }
            }
            
            $remaining = bcsub($priceInfo['price_ttc'], $totalAllocated, 8);
            
            return array(
                'enrollment_id' => $enrollmentId,
                'frozen_price' => $priceInfo['price_ttc'],
                'currency' => $currency,
                'allocated_total' => $totalAllocated,
                'remaining' => $remaining,
                'is_paid' => bccomp($remaining, '0', 8) <= 0,
                'is_overpaid' => bccomp($remaining, '0', 8) < 0
            );
        }
        
        // No price snapshot - cannot calculate balance
        return array(
            'enrollment_id' => $enrollmentId,
            'frozen_price' => null,
            'currency' => null,
            'allocated_total' => $totalAllocated,
            'remaining' => null,
            'is_paid' => false,
            'is_overpaid' => false
        );
    }

    /**
     * Get remaining balance for an enrollment including credit note deductions.
     * Returns frozen price minus allocated payments minus credit note deductions.
     */
    public function getEnrollmentBalanceWithCreditNotes(int $sessionId, int $enrollmentId): array {
        $this->allow('read');
        
        $balance = $this->getEnrollmentBalance($sessionId, $enrollmentId);
        
        if ($balance['frozen_price'] === null || $balance['currency'] === null) {
            $balance['total_credit_notes'] = '0.00';
            $balance['remaining_after_credits'] = null;
            $balance['is_paid_after_credits'] = false;
            return $balance;
        }
        
        // Get credit note allocations for this enrollment
        $creditNoteAllocs = $this->s->rows(
            'SELECT ca.amount, ca.currency FROM '.$this->s->table('creditnote_allocation').' ca '.
            'WHERE ca.entity='.$this->s->access->entity().
            ' AND ca.fk_enrollment='.$enrollmentId.
            ' AND ca.status=\'active\''
        );
        
        $totalCreditNotes = '0.00';
        foreach ($creditNoteAllocs as $cn) {
            if ($cn->currency === $balance['currency']) {
                $totalCreditNotes = bcadd($totalCreditNotes, $cn->amount, 8);
            }
        }
        
        $balance['total_credit_notes'] = $totalCreditNotes;
        $balance['remaining_after_credits'] = bcsub($balance['frozen_price'], bcadd($balance['allocated_total'], $totalCreditNotes, 8), 8);
        $balance['is_paid_after_credits'] = bccomp($balance['remaining_after_credits'], '0', 8) <= 0;
        
        return $balance;
    }

    /**
     * Get payment allocation summary for a session.
     */
    public function sessionPaymentSummary(int $sessionId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        
        $enrollments = $this->s->rows(
            'SELECT e.rowid FROM '.$this->s->table('enrollment').' e '.
            'WHERE e.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId.
            ' AND e.status = \'confirmed\''
        );
        
        $summary = array(
            'total_enrollments' => count($enrollments),
            'total_frozen_amount' => '0.00',
            'total_allocated' => '0.00',
            'total_credit_notes' => '0.00',
            'total_remaining' => '0.00',
            'total_remaining_after_credits' => '0.00',
            'fully_paid' => 0,
            'partially_paid' => 0,
            'unpaid' => 0,
            'currency' => null
        );
        
        if (count($enrollments) === 0) {
            return $summary;
        }
        
        foreach ($enrollments as $enrollment) {
            $balance = $this->getEnrollmentBalanceWithCreditNotes($sessionId, (int) $enrollment->rowid);
            
            if ($balance['currency'] && $summary['currency'] === null) {
                $summary['currency'] = $balance['currency'];
            }
            
            if ($balance['frozen_price'] !== null) {
                $summary['total_frozen_amount'] = bcadd($summary['total_frozen_amount'], $balance['frozen_price'], 8);
                $summary['total_allocated'] = bcadd($summary['total_allocated'], $balance['allocated_total'], 8);
                $summary['total_credit_notes'] = bcadd($summary['total_credit_notes'], $balance['total_credit_notes'], 8);
                $summary['total_remaining'] = bcadd($summary['total_remaining'], $balance['remaining'] ?? '0.00', 8);
                $summary['total_remaining_after_credits'] = bcadd($summary['total_remaining_after_credits'], $balance['remaining_after_credits'] ?? '0.00', 8);
                
                if ($balance['is_paid_after_credits']) {
                    $summary['fully_paid']++;
                } elseif ($balance['remaining_after_credits'] !== null && bccomp($balance['remaining_after_credits'], '0', 8) > 0) {
                    $summary['partially_paid']++;
                } else {
                    $summary['unpaid']++;
                }
            } else {
                $summary['unpaid']++;
            }
        }
        
        return $summary;
    }

    /**
     * Get credit note allocations for a session (proxy to CreditNoteService).
     */
    public function sessionCreditNoteAllocations(int $sessionId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        
        return $this->s->rows(
            'SELECT ca.rowid, ca.fk_facture, ca.fk_facturedet, ca.fk_enrollment, ca.amount, ca.currency, ca.datec, '
            'cs.invoice_ref, cs.date_credit, ca.status, ca.revision '
            'FROM '.$this->s->table('creditnote_allocation').' ca '.
            'LEFT JOIN '.$this->s->table('creditnote_snapshot').' cs ON cs.fk_allocation = ca.rowid AND cs.entity = ca.entity '.
            'JOIN '.$this->s->table('enrollment').' e ON e.rowid = ca.fk_enrollment AND e.entity = ca.entity '.
            'WHERE ca.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId.
            ' ORDER BY ca.datec, ca.rowid'
        );
    }

    /**
     * Allocate a payment to enrollments.
     * Distributes payment amount across enrollments based on their remaining balance.
     */
    public function allocatePayment(int $sessionId, int $paymentId, array $allocationMap, string $reason = ''): array {
        $this->allow('write');
        
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingPaymentReasonRequired');
        }
        
        return $this->s->transaction(function () use ($sessionId, $paymentId, $allocationMap, $reason) {
            $paymentData = $this->payments->payment($paymentId, true);
            $this->s->session($sessionId, true);
            
            if (!$paymentData['eligible']) {
                throw new RuntimeException('TrainingPaymentNotEligible');
            }
            
            $payment = $paymentData['payment'];
            $paymentAmount = $paymentData['snapshot']['amount'];
            $paymentCurrency = $paymentData['snapshot']['currency'];
            
            // Validate allocation map
            $totalAllocated = '0.00';
            $allocationDetails = array();
            
            foreach ($allocationMap as $enrollmentId => $amount) {
                $enrollment = $this->enrollment($sessionId, $enrollmentId);
                if ($enrollment->status !== 'confirmed') {
                    throw new RuntimeException('TrainingPaymentConfirmedRequired');
                }
                
                $balance = $this->getEnrollmentBalance($sessionId, $enrollmentId);
                
                // Check currency match
                if ($balance['currency'] !== $paymentCurrency) {
                    throw new RuntimeException('TrainingPaymentCurrencyMismatch');
                }
                
                // Validate amount
                if (!preg_match('/^[0-9]+\.?[0-9]{0,8}$/', $amount)) {
                    throw new RuntimeException('TrainingPaymentInvalidAmount');
                }
                
                $amountDecimal = number_format((float) $amount, 8, '.', '');
                
                // Check if allocation exceeds remaining balance
                if ($balance['remaining'] !== null && bccomp($amountDecimal, $balance['remaining'], 8) > 0) {
                    throw new RuntimeException('TrainingPaymentExceedsBalance');
                }
                
                $totalAllocated = bcadd($totalAllocated, $amountDecimal, 8);
                $allocationDetails[$enrollmentId] = array(
                    'amount' => $amountDecimal,
                    'currency' => $paymentCurrency
                );
            }
            
            // Check if total allocated exceeds payment amount
            if (bccomp($totalAllocated, $paymentAmount, 8) > 0) {
                throw new RuntimeException('TrainingPaymentExceedsPaymentAmount');
            }
            
            // Create allocations
            $actor = $this->s->access->actor();
            $entity = $this->s->access->entity();
            $now = $this->s->now();
            $allocationIds = array();
            
            foreach ($allocationDetails as $enrollmentId => $details) {
                // Create allocation
                $this->s->query(
                    'INSERT INTO '.$this->s->table('payment_allocation').
                    ' (entity, fk_enrollment, fk_paiement, fk_soc, amount, currency, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
                    .$entity.', '.$enrollmentId.', '.$paymentId.', '.(int) $payment->fk_soc.', '
                    .$this->s->text($details['amount']).', '.$this->s->text($details['currency']).', '
                    .$now.', '.$actor.', '.$now.', '.$actor.')'
                );
                
                $allocationId = (int) $this->s->db->last_insert_id($this->s->table('payment_allocation'));
                $allocationIds[] = $allocationId;
                
                // Create snapshot
                $this->s->query(
                    'INSERT INTO '.$this->s->table('payment_snapshot').
                    ' (entity, fk_allocation, fk_paiement, paiement_ref, amount, currency, datep, fk_soc, fk_user_author, datec) VALUES ('
                    .$entity.', '.$allocationId.', '.$paymentId.', '
                    .$this->s->text($paymentData['snapshot']['ref']).', '
                    .$this->s->text($paymentData['snapshot']['amount']).', '
                    .$this->s->text($paymentData['snapshot']['currency']).', '
                    .$this->s->text($paymentData['snapshot']['datep']).', '
                    .(int) $payment->fk_soc.', '.$actor.', '.$now.')'
                );
                
                $this->s->audit(
                    'payment_allocation',
                    $allocationId,
                    'allocated',
                    array(
                        'session_id' => $sessionId,
                        'enrollment_id' => $enrollmentId,
                        'payment_id' => $paymentId,
                        'amount' => $details['amount'],
                        'currency' => $details['currency'],
                        'payment_ref' => $paymentData['snapshot']['ref'],
                        'reason' => trim($reason)
                    )
                );
            }
            
            return array(
                'allocation_ids' => $allocationIds,
                'total_allocated' => $totalAllocated,
                'payment_id' => $paymentId
            );
        });
    }

    /**
     * Adjust an existing payment allocation.
     * Change the amount allocated to specific enrollments.
     */
    public function adjustAllocation(int $sessionId, int $paymentId, int $allocationId, string $newAmount, string $reason): array {
        $this->allow('write');
        $this->s->access->requireDomain('payment', 'correct');
        
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingPaymentReasonRequired');
        }
        
        if (!preg_match('/^[0-9]+\.?[0-9]{0,8}$/', $newAmount)) {
            throw new RuntimeException('TrainingPaymentInvalidAmount');
        }
        
        return $this->s->transaction(function () use ($sessionId, $paymentId, $allocationId, $newAmount, $reason) {
            $this->s->session($sessionId, true);
            
            // Get existing allocation
            $rows = $this->s->rows(
                'SELECT * FROM '.$this->s->table('payment_allocation').
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity().
                ' FOR UPDATE'
            );
            
            if (!$rows) {
                throw new RuntimeException('TrainingPaymentAllocationNotFound');
            }
            
            $allocation = $rows[0];
            $enrollmentId = (int) $allocation->fk_enrollment;
            
            // Verify enrollment
            $enrollment = $this->enrollment($sessionId, $enrollmentId);
            
            // Get payment
            $paymentData = $this->payments->payment($paymentId, true);
            
            // Get balance
            $balance = $this->getEnrollmentBalance($sessionId, $enrollmentId);
            
            // Check currency
            if ($balance['currency'] !== $paymentData['snapshot']['currency']) {
                throw new RuntimeException('TrainingPaymentCurrencyMismatch');
            }
            
            // Check new amount against balance
            $newAmountDecimal = number_format((float) $newAmount, 8, '.', '');
            if ($balance['remaining'] !== null && bccomp($newAmountDecimal, $balance['remaining'], 8) > 0) {
                throw new RuntimeException('TrainingPaymentExceedsBalance');
            }
            
            // Update allocation
            $oldAmount = $allocation->amount;
            $this->s->query(
                'UPDATE '.$this->s->table('payment_allocation').
                ' SET amount='.$this->s->text($newAmountDecimal).
                ', changed_at='.$this->s->now().
                ', fk_user_modifier='.$this->s->access->actor().
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            $this->s->audit(
                'payment_allocation',
                $allocationId,
                'adjusted',
                array(
                    'session_id' => $sessionId,
                    'enrollment_id' => $enrollmentId,
                    'payment_id' => $paymentId,
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmountDecimal,
                    'currency' => $allocation->currency,
                    'reason' => trim($reason)
                )
            );
            
            return array(
                'allocation_id' => $allocationId,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmountDecimal
            );
        });
    }

    /**
     * Remove a payment allocation.
     */
    public function removeAllocation(int $sessionId, int $paymentId, int $allocationId, string $reason): void {
        $this->allow('write');
        $this->s->access->requireDomain('payment', 'correct');
        
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingPaymentReasonRequired');
        }
        
        $this->s->transaction(function () use ($sessionId, $paymentId, $allocationId, $reason) {
            $this->s->session($sessionId, true);
            
            // Get existing allocation
            $rows = $this->s->rows(
                'SELECT * FROM '.$this->s->table('payment_allocation').
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity().
                ' FOR UPDATE'
            );
            
            if (!$rows) {
                throw new RuntimeException('TrainingPaymentAllocationNotFound');
            }
            
            $allocation = $rows[0];
            $enrollmentId = (int) $allocation->fk_enrollment;
            
            // Verify enrollment
            $enrollment = $this->enrollment($sessionId, $enrollmentId);
            
            // Delete allocation and snapshot
            $this->s->query(
                'DELETE FROM '.$this->s->table('payment_snapshot').
                ' WHERE fk_allocation='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            $this->s->query(
                'DELETE FROM '.$this->s->table('payment_allocation').
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            $this->s->audit(
                'payment_allocation',
                $allocationId,
                'removed',
                array(
                    'session_id' => $sessionId,
                    'enrollment_id' => $enrollmentId,
                    'payment_id' => $paymentId,
                    'amount' => $allocation->amount,
                    'currency' => $allocation->currency,
                    'reason' => trim($reason)
                )
            );
        });
    }

    /**
     * Get all payment allocations for a session.
     */
    public function sessionAllocations(int $sessionId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        
        $rows = $this->s->rows(
            'SELECT pa.rowid, pa.fk_paiement, pa.fk_enrollment, pa.amount, pa.currency, pa.datec, '.
            'ps.paiement_ref, ps.datep as payment_date, p.ref as payment_ref, p.amount as payment_amount '.
            'FROM '.$this->s->table('payment_allocation').' pa '.
            'LEFT JOIN '.$this->s->table('payment_snapshot').' ps ON ps.fk_allocation = pa.rowid AND ps.entity = pa.entity '.
            'LEFT JOIN '.$this->s->db->prefix().'paiement p ON p.rowid = pa.fk_paiement '.
            'JOIN '.$this->s->table('enrollment').' e ON e.rowid = pa.fk_enrollment AND e.entity = pa.entity '.
            'WHERE pa.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId.
            ' ORDER BY pa.datec, pa.rowid'
        );
        
        return $rows;
    }

    /**
     * Get payment allocations for a specific enrollment.
     */
    public function enrollmentAllocations(int $sessionId, int $enrollmentId): array {
        $this->allow('read');
        $this->enrollment($sessionId, $enrollmentId);
        
        return $this->s->rows(
            'SELECT pa.rowid, pa.fk_paiement, pa.amount, pa.currency, pa.datec, '.
            'ps.paiement_ref, ps.datep as payment_date '.
            'FROM '.$this->s->table('payment_allocation').' pa '.
            'LEFT JOIN '.$this->s->table('payment_snapshot').' ps ON ps.fk_allocation = pa.rowid AND ps.entity = pa.entity '.
            'WHERE pa.entity='.$this->s->access->entity().
            ' AND pa.fk_enrollment='.$enrollmentId.
            ' ORDER BY pa.datec, pa.rowid'
        );
    }
}
