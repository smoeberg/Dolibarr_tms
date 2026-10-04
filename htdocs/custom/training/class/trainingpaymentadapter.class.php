<?php
require_once __DIR__.'/trainingstore.class.php';

/**
 * Read-only Dolibarr Paiement adapter.
 * Never updates accounting tables. Provides payment data for allocation.
 */
final class TrainingPaymentAdapter
{
    private TrainingStore $s;

    public function __construct(TrainingStore $store) {
        $this->s = $store;
    }

    /**
     * Get payment details.
     * Returns validated standard payment data.
     */
    public function payment(int $paymentId, bool $lock = false): array {
        $this->s->access->requirePaymentRead();
        
        $rows = $this->s->rows(
            'SELECT p.rowid, p.entity, p.ref, p.amount, p.currency, '.
            'p.datep, p.fk_soc, p.statut as status, '.
            'CAST(p.amount AS DECIMAL(24,8)) AS amount_decimal '.
            'FROM '.$this->s->db->prefix().'paiement p '.
            'WHERE p.rowid='.$paymentId.
            ($lock ? ' FOR UPDATE' : '')
        );
        
        if (!$rows) {
            throw new RuntimeException('TrainingPaymentNotAccessible');
        }
        
        $payment = $rows[0];
        $this->s->access->requireBillingCustomer((int) $payment->fk_soc, $this->s->db);
        
        // Build snapshot for audit
        $snapshot = array(
            'payment_id' => $paymentId,
            'ref' => $payment->ref,
            'amount' => $payment->amount_decimal,
            'currency' => $payment->currency,
            'datep' => $payment->datep,
            'customer_id' => (int) $payment->fk_soc,
            'status' => (int) $payment->status
        );
        
        // Check eligibility: only validated payments (statut = 1)
        $eligible = (int) $payment->status === 1;
        
        return array(
            'payment' => $payment,
            'snapshot' => $snapshot,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'eligible' => $eligible
        );
    }

    /**
     * Get payment by enrollment (for existing allocations).
     */
    public function paymentForEnrollment(int $enrollmentId): array {
        $this->s->access->requirePaymentRead();
        
        $rows = $this->s->rows(
            'SELECT pa.fk_paiement, pa.amount, pa.currency, pa.datec '.
            'FROM '.$this->s->table('payment_allocation').' pa '.
            'WHERE pa.entity='.$this->s->access->entity().
            ' AND pa.fk_enrollment='.$enrollmentId.
            ' ORDER BY pa.rowid'
        );
        
        $result = array();
        foreach ($rows as $row) {
            $result[] = array(
                'payment_id' => (int) $row->fk_paiement,
                'amount' => $row->amount,
                'currency' => $row->currency,
                'datec' => $row->datec
            );
        }
        
        return $result;
    }

    /**
     * Get total allocated amount for an enrollment.
     */
    public function allocatedAmount(int $enrollmentId): array {
        $this->s->access->requirePaymentRead();
        
        $rows = $this->s->rows(
            'SELECT currency, SUM(amount) as total_amount '.
            'FROM '.$this->s->table('payment_allocation').
            ' WHERE entity='.$this->s->access->entity().
            ' AND fk_enrollment='.$enrollmentId.
            ' GROUP BY currency'
        );
        
        $result = array();
        foreach ($rows as $row) {
            $result[$row->currency] = $row->total_amount;
        }
        
        return $result;
    }

    /**
     * Get total allocated amount for a session.
     */
    public function sessionAllocatedAmount(int $sessionId): array {
        $this->s->access->requirePaymentRead();
        
        $rows = $this->s->rows(
            'SELECT currency, SUM(pa.amount) as total_amount '.
            'FROM '.$this->s->table('payment_allocation').' pa '.
            'JOIN '.$this->s->table('enrollment').' e ON e.rowid = pa.fk_enrollment AND e.entity = pa.entity '.
            'WHERE pa.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId.
            ' GROUP BY currency'
        );
        
        $result = array();
        foreach ($rows as $row) {
            $result[$row->currency] = $row->total_amount;
        }
        
        return $result;
    }
}
