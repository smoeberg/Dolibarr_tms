<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingpriceservice.class.php';

/**
 * Single financial balance authority for a training enrollment.
 *
 * Dolibarr remains authoritative for Product, Facture and Paiement. Training
 * only owns the enrollment-level allocation relations and their snapshots.
 * This service combines those allocations so payment and credit-note flows
 * cannot each invent a different remaining balance.
 */
final class TrainingFinancialBalanceService
{
    private TrainingStore $s;
    private TrainingPriceService $prices;

    public function __construct(TrainingStore $store)
    {
        $this->s = $store;
        $this->prices = new TrainingPriceService($store);
    }

    public function enrollmentBalance(int $sessionId, int $enrollmentId): array
    {
        $price = $this->prices->getEnrollmentPrice($enrollmentId, $sessionId);
        if ($price === null) {
            return array(
                'enrollment_id' => $enrollmentId,
                'frozen_price' => null,
                'currency' => null,
                'allocated_payments' => '0.00000000',
                'allocated_credit_notes' => '0.00000000',
                'remaining' => null,
                'is_paid' => false,
                'is_overpaid' => false,
            );
        }

        $entity = $this->s->access->entity();
        $paymentRows = $this->s->rows(
            'SELECT amount, currency FROM '.$this->s->table('payment_allocation').
            ' WHERE entity='.$entity.
            ' AND fk_enrollment='.$enrollmentId.
            ' ORDER BY rowid'
        );
        $creditRows = $this->s->rows(
            'SELECT amount, currency FROM '.$this->s->table('creditnote_allocation').
            ' WHERE entity='.$entity.
            ' AND fk_enrollment='.$enrollmentId.
            " AND status='active' ORDER BY rowid"
        );

        $allocatedPayments = '0.00000000';
        foreach ($paymentRows as $row) {
            if ((string) $row->currency === (string) $price['currency']) {
                $allocatedPayments = bcadd($allocatedPayments, (string) $row->amount, 8);
            }
        }

        $allocatedCredits = '0.00000000';
        foreach ($creditRows as $row) {
            if ((string) $row->currency === (string) $price['currency']) {
                $allocatedCredits = bcadd($allocatedCredits, (string) $row->amount, 8);
            }
        }

        $combined = bcadd($allocatedPayments, $allocatedCredits, 8);
        $remaining = bcsub((string) $price['price_ttc'], $combined, 8);

        return array(
            'enrollment_id' => $enrollmentId,
            'frozen_price' => (string) $price['price_ttc'],
            'currency' => (string) $price['currency'],
            'allocated_payments' => $allocatedPayments,
            'allocated_credit_notes' => $allocatedCredits,
            'remaining' => $remaining,
            'is_paid' => bccomp($remaining, '0', 8) <= 0,
            'is_overpaid' => bccomp($remaining, '0', 8) < 0,
        );
    }

    /**
     * Amount of a standard Dolibarr Paiement already allocated by Training.
     * Caller must lock the Paiement before calling this for a write operation.
     */
    public function allocatedPayment(int $paymentId): string
    {
        $rows = $this->s->rows(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM '.$this->s->table('payment_allocation').
            ' WHERE entity='.$this->s->access->entity().
            ' AND fk_paiement='.$paymentId
        );
        return number_format((float) ($rows[0]->total ?? 0), 8, '.', '');
    }

    /**
     * Amount of a standard Dolibarr credit-note line already allocated by Training.
     * Caller must lock the credit-note line before calling this for a write operation.
     */
    public function allocatedCreditNote(int $creditNoteId, int $lineId): string
    {
        $rows = $this->s->rows(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM '.$this->s->table('creditnote_allocation').
            ' WHERE entity='.$this->s->access->entity().
            ' AND fk_facture='.$creditNoteId.
            ' AND fk_facturedet='.$lineId.
            " AND status='active'"
        );
        return number_format((float) ($rows[0]->total ?? 0), 8, '.', '');
    }

    public function assertPaymentSourceCapacity(string $paymentAmount, string $newAllocationTotal, string $alreadyAllocated): void
    {
        $available = bcsub($paymentAmount, $alreadyAllocated, 8);
        if (bccomp($newAllocationTotal, $available, 8) > 0) {
            throw new RuntimeException('TrainingPaymentExceedsPaymentAmount');
        }
    }

    public function assertCreditNoteSourceCapacity(string $creditNoteAmount, string $newAllocationTotal, string $alreadyAllocated): void
    {
        $available = bcsub($creditNoteAmount, $alreadyAllocated, 8);
        if (bccomp($newAllocationTotal, $available, 8) > 0) {
            throw new RuntimeException('TrainingCreditNoteExceedsCreditNoteAmount');
        }
    }

    public function assertEnrollmentCapacity(int $sessionId, int $enrollmentId, string $amount): void
    {
        $balance = $this->enrollmentBalance($sessionId, $enrollmentId);
        if ($balance['remaining'] === null) {
            throw new RuntimeException('TrainingPriceSnapshotRequired');
        }
        if (bccomp($amount, $balance['remaining'], 8) > 0) {
            throw new RuntimeException('TrainingFinancialAllocationExceedsBalance');
        }
    }
}
