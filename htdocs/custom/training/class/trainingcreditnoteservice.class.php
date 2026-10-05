<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingcreditnoteadapter.class.php';
require_once __DIR__.'/trainingpriceservice.class.php';

/**
 * Credit note allocation service.
 * Links standard Dolibarr credit notes (Facture with type=2) to training enrollments.
 * Handles credit note deductions from frozen prices.
 * Tracks which credit notes reduce which enrollment fees.
 */
final class TrainingCreditNoteService
{
    private TrainingStore $s;
    private TrainingCreditNoteAdapter $creditNotes;
    private TrainingPriceService $prices;

    public function __construct(TrainingStore $store, string $baseCurrency) {
        $this->s = $store;
        $this->creditNotes = new TrainingCreditNoteAdapter($store, $baseCurrency);
        $this->prices = new TrainingPriceService($store);
    }

    private function allow(string $right): void {
        $this->s->access->requireDomain('session', 'read');
        $this->s->access->requireDomain('creditnote', $right);
        $this->s->access->requireInvoiceRead();
        $this->s->access->requireContactRead();
    }

    /**
     * Get enrollment details.
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
     * Get allocated credit notes for an enrollment.
     */
    private function allocatedCreditNotes(int $enrollmentId): array {
        return $this->s->rows(
            'SELECT ca.rowid, ca.fk_facture, ca.amount, ca.currency, ca.datec, '.
            'cs.invoice_ref, cs.date_credit '.
            'FROM '.$this->s->table('creditnote_allocation').' ca '.
            'LEFT JOIN '.$this->s->table('creditnote_snapshot').' cs ON cs.fk_allocation = ca.rowid AND cs.entity = ca.entity '.
            'WHERE ca.entity='.$this->s->access->entity().
            ' AND ca.fk_enrollment='.$enrollmentId.
            ' AND ca.status=\'active\''.
            ' ORDER BY ca.datec'
        );
    }

    /**
     * Get remaining balance for an enrollment after credit note deductions.
     * Returns the frozen price minus allocated payments minus credit note deductions.
     */
    public function getEnrollmentBalanceWithCreditNotes(int $sessionId, int $enrollmentId): array {
        $this->allow('read');
        
        $enrollment = $this->enrollment($sessionId, $enrollmentId);
        $priceInfo = $this->getEnrollmentPrice($sessionId, $enrollmentId);
        
        $allocatedCreditNotes = $this->allocatedCreditNotes($enrollmentId);
        
        $totalCreditNotes = '0.00';
        $currency = null;
        
        if ($priceInfo) {
            $currency = $priceInfo['currency'];
            foreach ($allocatedCreditNotes as $creditNote) {
                if ($creditNote->currency === $currency) {
                    $totalCreditNotes = bcadd($totalCreditNotes, $creditNote->amount, 8);
                }
            }
            
            return array(
                'enrollment_id' => $enrollmentId,
                'frozen_price' => $priceInfo['price_ttc'],
                'currency' => $currency,
                'total_credit_notes' => $totalCreditNotes,
                'remaining_after_credits' => bcsub($priceInfo['price_ttc'], $totalCreditNotes, 8)
            );
        }
        
        return array(
            'enrollment_id' => $enrollmentId,
            'frozen_price' => null,
            'currency' => null,
            'total_credit_notes' => $totalCreditNotes,
            'remaining_after_credits' => null
        );
    }

    /**
     * Get credit note allocation summary for a session.
     */
    public function sessionCreditNoteSummary(int $sessionId): array {
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
            'total_credit_notes' => '0.00',
            'total_remaining_after_credits' => '0.00',
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
                $summary['total_credit_notes'] = bcadd($summary['total_credit_notes'], $balance['total_credit_notes'], 8);
                $summary['total_remaining_after_credits'] = bcadd($summary['total_remaining_after_credits'], $balance['remaining_after_credits'] ?? '0.00', 8);
            }
        }
        
        return $summary;
    }

    /**
     * Allocate a credit note to enrollments.
     * Distributes credit note amount across enrollments based on their remaining balance.
     */
    public function allocateCreditNote(int $sessionId, int $creditNoteId, int $lineId, array $allocationMap, string $reason = ''): array {
        $this->allow('write');
        
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingCreditNoteReasonRequired');
        }
        
        return $this->s->transaction(function () use ($sessionId, $creditNoteId, $lineId, $allocationMap, $reason) {
            $creditNoteData = $this->creditNotes->line($creditNoteId, $lineId, true);
            $this->s->session($sessionId, true);
            
            if (!$creditNoteData['eligible']) {
                throw new RuntimeException('TrainingCreditNoteNotEligible');
            }
            
            $creditNoteLine = $creditNoteData['line'];
            $creditNoteAmount = $creditNoteData['snapshot']['line']['total_ttc'];
            $creditNoteCurrency = $creditNoteData['snapshot']['currency'];
            
            // Validate allocation map
            $totalAllocated = '0.00';
            $allocationDetails = array();
            
            foreach ($allocationMap as $enrollmentId => $amount) {
                $enrollment = $this->enrollment($sessionId, $enrollmentId);
                if ($enrollment->status !== 'confirmed') {
                    throw new RuntimeException('TrainingCreditNoteConfirmedRequired');
                }
                
                $priceInfo = $this->getEnrollmentPrice($sessionId, $enrollmentId);
                
                // Check currency match
                if ($priceInfo['currency'] !== $creditNoteCurrency) {
                    throw new RuntimeException('TrainingCreditNoteCurrencyMismatch');
                }
                
                // Validate amount
                if (!preg_match('/^[0-9]+\.?[0-9]{0,8}$/', $amount)) {
                    throw new RuntimeException('TrainingCreditNoteInvalidAmount');
                }
                
                $amountDecimal = number_format((float) $amount, 8, '.', '');
                
                // Check if allocation exceeds frozen price
                if ($priceInfo['price_ttc'] !== null && bccomp($amountDecimal, $priceInfo['price_ttc'], 8) > 0) {
                    throw new RuntimeException('TrainingCreditNoteExceedsFrozenPrice');
                }
                
                // Check if allocation exceeds remaining balance after existing credit notes
                $balance = $this->getEnrollmentBalanceWithCreditNotes($sessionId, $enrollmentId);
                if ($balance['remaining_after_credits'] !== null && bccomp($amountDecimal, $balance['remaining_after_credits'], 8) > 0) {
                    throw new RuntimeException('TrainingCreditNoteExceedsRemainingBalance');
                }
                
                $totalAllocated = bcadd($totalAllocated, $amountDecimal, 8);
                $allocationDetails[$enrollmentId] = array(
                    'amount' => $amountDecimal,
                    'currency' => $creditNoteCurrency
                );
            }
            
            // Check if total allocated exceeds credit note amount
            if (bccomp($totalAllocated, $creditNoteAmount, 8) > 0) {
                throw new RuntimeException('TrainingCreditNoteExceedsCreditNoteAmount');
            }
            
            // Create allocations
            $actor = $this->s->access->actor();
            $entity = $this->s->access->entity();
            $now = $this->s->now();
            $allocationIds = array();
            
            foreach ($allocationDetails as $enrollmentId => $details) {
                // Create allocation
                $this->s->query(
                    'INSERT INTO '.$this->s->table('creditnote_allocation').
                    ' (entity, fk_enrollment, fk_facture, fk_facturedet, fk_soc, amount, currency, datec, fk_user_author, changed_at, fk_user_modifier, status, revision, source_hash, snapshot_json) VALUES ('
                    .$entity.', '.$enrollmentId.', '.$creditNoteId.', '.$lineId.', '.(int) $creditNoteLine->fk_soc.', '
                    .$this->s->text($details['amount']).', '.$this->s->text($details['currency']).', '
                    .$now.', '.$actor.', '.$now.', '.$actor.', \'active\', 1, '
                    .$this->s->text($creditNoteData['hash']).', '.$this->s->text($creditNoteData['snapshot_json']).')'
                );
                
                $allocationId = (int) $this->s->db->last_insert_id($this->s->table('creditnote_allocation'));
                $allocationIds[] = $allocationId;
                
                // Create snapshot
                $this->s->query(
                    'INSERT INTO '.$this->s->table('creditnote_snapshot').
                    ' (entity, fk_allocation, fk_facture, invoice_ref, amount, currency, date_credit, fk_soc, fk_user_author, datec) VALUES ('
                    .$entity.', '.$allocationId.', '.$creditNoteId.', '
                    .$this->s->text($creditNoteData['snapshot']['invoice_ref']).', '
                    .$this->s->text($creditNoteLine->total_ttc).', '
                    .$this->s->text($creditNoteCurrency).', '
                    .$this->s->text($creditNoteLine->date_credit ?? $creditNoteData['snapshot']['invoice_ref']).', '
                    .(int) $creditNoteLine->fk_soc.', '.$actor.', '.$now.')'
                );
                
                // Track balance adjustment
                $this->s->query(
                    'INSERT INTO '.$this->s->table('creditnote_balance').
                    ' (entity, fk_enrollment, fk_allocation, adjustment_type, amount, currency, datec, fk_user_author) VALUES ('
                    .$entity.', '.$enrollmentId.', '.$allocationId.', \'credit\', '
                    .$this->s->text($details['amount']).', '.$this->s->text($details['currency']).', '
                    .$now.', '.$actor.')'
                );
                
                $this->s->audit(
                    'creditnote_allocation',
                    $allocationId,
                    'allocated',
                    array(
                        'session_id' => $sessionId,
                        'enrollment_id' => $enrollmentId,
                        'credit_note_id' => $creditNoteId,
                        'line_id' => $lineId,
                        'amount' => $details['amount'],
                        'currency' => $details['currency'],
                        'invoice_ref' => $creditNoteData['snapshot']['invoice_ref'],
                        'reason' => trim($reason)
                    )
                );
            }
            
            return array(
                'allocation_ids' => $allocationIds,
                'total_allocated' => $totalAllocated,
                'credit_note_id' => $creditNoteId
            );
        });
    }

    /**
     * Adjust an existing credit note allocation.
     */
    public function adjustAllocation(int $sessionId, int $creditNoteId, int $allocationId, string $newAmount, string $reason): array {
        $this->allow('write');
        $this->s->access->requireDomain('creditnote', 'correct');
        
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingCreditNoteReasonRequired');
        }
        
        if (!preg_match('/^[0-9]+\.?[0-9]{0,8}$/', $newAmount)) {
            throw new RuntimeException('TrainingCreditNoteInvalidAmount');
        }
        
        return $this->s->transaction(function () use ($sessionId, $creditNoteId, $allocationId, $newAmount, $reason) {
            $this->s->session($sessionId, true);
            
            // Get existing allocation
            $rows = $this->s->rows(
                'SELECT * FROM '.$this->s->table('creditnote_allocation').
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity().
                ' FOR UPDATE'
            );
            
            if (!$rows) {
                throw new RuntimeException('TrainingCreditNoteAllocationNotFound');
            }
            
            $allocation = $rows[0];
            $enrollmentId = (int) $allocation->fk_enrollment;
            
            // Verify enrollment
            $enrollment = $this->enrollment($sessionId, $enrollmentId);
            
            // Get credit note line
            $creditNoteData = $this->creditNotes->line($creditNoteId, (int) $allocation->fk_facturedet, true);
            
            $priceInfo = $this->getEnrollmentPrice($sessionId, $enrollmentId);
            
            // Check currency
            if ($priceInfo['currency'] !== $creditNoteData['snapshot']['currency']) {
                throw new RuntimeException('TrainingCreditNoteCurrencyMismatch');
            }
            
            // Check new amount against frozen price
            $newAmountDecimal = number_format((float) $newAmount, 8, '.', '');
            if ($priceInfo['price_ttc'] !== null && bccomp($newAmountDecimal, $priceInfo['price_ttc'], 8) > 0) {
                throw new RuntimeException('TrainingCreditNoteExceedsFrozenPrice');
            }
            
            // Check new amount against remaining balance
            $balance = $this->getEnrollmentBalanceWithCreditNotes($sessionId, $enrollmentId);
            // Temporarily subtract the old amount from the balance check
            $balanceRemaining = bcsub($balance['remaining_after_credits'] ?? $priceInfo['price_ttc'], $allocation->amount, 8);
            $balanceRemaining = bcadd($balanceRemaining, $newAmountDecimal, 8);
            if ($balanceRemaining !== null && bccomp($newAmountDecimal, $balanceRemaining, 8) > 0) {
                throw new RuntimeException('TrainingCreditNoteExceedsRemainingBalance');
            }
            
            // Update allocation
            $oldAmount = $allocation->amount;
            $this->s->query(
                'UPDATE '.$this->s->table('creditnote_allocation').
                ' SET amount='.$this->s->text($newAmountDecimal).
                ', changed_at='.$this->s->now().
                ', fk_user_modifier='.$this->s->access->actor().
                ', revision='.((int) $allocation->revision + 1).
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            // Update balance adjustment
            $this->s->query(
                'UPDATE '.$this->s->table('creditnote_balance').
                ' SET amount='.$this->s->text($newAmountDecimal).
                ' WHERE fk_allocation='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            $this->s->audit(
                'creditnote_allocation',
                $allocationId,
                'adjusted',
                array(
                    'session_id' => $sessionId,
                    'enrollment_id' => $enrollmentId,
                    'credit_note_id' => $creditNoteId,
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
     * Remove a credit note allocation.
     */
    public function removeAllocation(int $sessionId, int $creditNoteId, int $allocationId, string $reason): void {
        $this->allow('write');
        $this->s->access->requireDomain('creditnote', 'correct');
        
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingCreditNoteReasonRequired');
        }
        
        $this->s->transaction(function () use ($sessionId, $creditNoteId, $allocationId, $reason) {
            $this->s->session($sessionId, true);
            
            // Get existing allocation
            $rows = $this->s->rows(
                'SELECT * FROM '.$this->s->table('creditnote_allocation').
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity().
                ' FOR UPDATE'
            );
            
            if (!$rows) {
                throw new RuntimeException('TrainingCreditNoteAllocationNotFound');
            }
            
            $allocation = $rows[0];
            $enrollmentId = (int) $allocation->fk_enrollment;
            
            // Verify enrollment
            $enrollment = $this->enrollment($sessionId, $enrollmentId);
            
            // Void the allocation instead of deleting (audit trail)
            $this->s->query(
                'UPDATE '.$this->s->table('creditnote_allocation').
                ' SET status=\'voided\', changed_at='.$this->s->now().
                ', fk_user_modifier='.$this->s->access->actor().
                ', revision='.((int) $allocation->revision + 1).
                ' WHERE rowid='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            // Update balance adjustment to reflect void
            $this->s->query(
                'UPDATE '.$this->s->table('creditnote_balance').
                ' SET adjustment_type=\'credit_voided\' '.
                ' WHERE fk_allocation='.$allocationId.' AND entity='.$this->s->access->entity()
            );
            
            $this->s->audit(
                'creditnote_allocation',
                $allocationId,
                'removed',
                array(
                    'session_id' => $sessionId,
                    'enrollment_id' => $enrollmentId,
                    'credit_note_id' => $creditNoteId,
                    'amount' => $allocation->amount,
                    'currency' => $allocation->currency,
                    'reason' => trim($reason)
                )
            );
        });
    }

    /**
     * Get all credit note allocations for a session.
     */
    public function sessionAllocations(int $sessionId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        
        $rows = $this->s->rows(
            'SELECT ca.rowid, ca.fk_facture, ca.fk_facturedet, ca.fk_enrollment, ca.amount, ca.currency, ca.datec, '.
            'cs.invoice_ref, cs.date_credit, ca.status, ca.revision, '.
            'f.ref as credit_note_ref, f.total_ttc as credit_note_total '.
            'FROM '.$this->s->table('creditnote_allocation').' ca '.
            'LEFT JOIN '.$this->s->table('creditnote_snapshot').' cs ON cs.fk_allocation = ca.rowid AND cs.entity = ca.entity '.
            'LEFT JOIN '.$this->s->db->prefix().'facture f ON f.rowid = ca.fk_facture '.
            'JOIN '.$this->s->table('enrollment').' e ON e.rowid = ca.fk_enrollment AND e.entity = ca.entity '.
            'WHERE ca.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId.
            ' ORDER BY ca.datec, ca.rowid'
        );
        
        return $rows;
    }

    /**
     * Get credit note allocations for a specific enrollment.
     */
    public function enrollmentAllocations(int $sessionId, int $enrollmentId): array {
        $this->allow('read');
        $this->enrollment($sessionId, $enrollmentId);
        
        return $this->s->rows(
            'SELECT ca.rowid, ca.fk_facture, ca.fk_facturedet, ca.amount, ca.currency, ca.datec, '.
            'cs.invoice_ref, cs.date_credit, ca.status, ca.revision '.
            'FROM '.$this->s->table('creditnote_allocation').' ca '.
            'LEFT JOIN '.$this->s->table('creditnote_snapshot').' cs ON cs.fk_allocation = ca.rowid AND cs.entity = ca.entity '.
            'WHERE ca.entity='.$this->s->access->entity().
            ' AND ca.fk_enrollment='.$enrollmentId.
            ' ORDER BY ca.datec, ca.rowid'
        );
    }

    /**
     * Get credit note balance history for an enrollment.
     */
    public function enrollmentCreditNoteHistory(int $sessionId, int $enrollmentId): array {
        $this->allow('read');
        $this->enrollment($sessionId, $enrollmentId);
        
        return $this->s->rows(
            'SELECT cb.rowid, cb.fk_allocation, cb.adjustment_type, cb.amount, cb.currency, cb.datec, '.
            'ca.fk_facture, cs.invoice_ref '.
            'FROM '.$this->s->table('creditnote_balance').' cb '.
            'JOIN '.$this->s->table('creditnote_allocation').' ca ON ca.rowid = cb.fk_allocation AND ca.entity = cb.entity '.
            'LEFT JOIN '.$this->s->table('creditnote_snapshot').' cs ON cs.fk_allocation = ca.rowid AND cs.entity = ca.entity '.
            'WHERE cb.entity='.$this->s->access->entity().
            ' AND cb.fk_enrollment='.$enrollmentId.
            ' ORDER BY cb.datec, cb.rowid'
        );
    }

    /**
     * Get history for a credit note allocation.
     */
    public function history(int $sessionId, int $creditNoteId, int $lineId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        
        $rows = $this->s->rows(
            'SELECT ca.rowid FROM '.$this->s->table('creditnote_allocation').' ca '.
            'JOIN '.$this->s->table('enrollment').' e ON e.rowid = ca.fk_enrollment AND e.entity = ca.entity '.
            'WHERE ca.entity='.$this->s->access->entity().
            ' AND ca.fk_facture='.$creditNoteId.
            ' AND ca.fk_facturedet='.$lineId.
            ' AND e.fk_session='.$sessionId
        );
        
        if (!$rows) {
            return array();
        }
        
        return $this->s->rows(
            'SELECT * FROM '.$this->s->table('audit').
            ' WHERE entity='.$this->s->access->entity().
            ' AND object_type=\'creditnote_allocation\' AND fk_object='.(int) $rows[0]->rowid.
            ' ORDER BY rowid'
        );
    }
}
