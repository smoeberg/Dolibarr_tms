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
    public function payment(int $paymentId, bool $lock = false): array
    {
        $entity = $this->s->access->entity();
        $rows = $this->s->rows(
            'SELECT p.rowid, p.entity, p.ref, p.amount, p.multicurrency_amount, p.datep '.
            'FROM '.$this->s->db->prefix().'paiement p '.
            'WHERE p.rowid='.$paymentId.' AND p.entity='.$entity.
            ($lock ? ' FOR UPDATE' : '')
        );

        if (!$rows) {
            throw new RuntimeException('TrainingPaymentNotAccessible');
        }

        $payment = $rows[0];

        // Dolibarr 24.0.2 stores customer and currency context on the invoice
        // allocation (paiement_facture), not on llx_paiement. Resolve it through
        // the native payment -> invoice relation and scope both sides to entity.
        $invoiceRows = $this->s->rows(
            'SELECT f.rowid AS invoice_id, f.fk_soc, '.
            "COALESCE(NULLIF(pf.multicurrency_code, ''), NULLIF(f.multicurrency_code, ''), '') AS currency_code ".
            'FROM '.$this->s->db->prefix().'paiement_facture pf '.
            'INNER JOIN '.$this->s->db->prefix().'facture f ON f.rowid=pf.fk_facture AND f.entity='.$entity.' '.
            'WHERE pf.fk_paiement='.$paymentId.
            ' ORDER BY f.rowid'.
            ($lock ? ' FOR UPDATE' : '')
        );
        if (!$invoiceRows) {
            throw new RuntimeException('TrainingPaymentNotAccessible');
        }

        $companyCurrency = '';
        if (function_exists('getDolGlobalString')) {
            $companyCurrency = (string) getDolGlobalString('MAIN_MONNAIE');
        }
        if ($companyCurrency === '') {
            global $conf;
            $companyCurrency = !empty($conf->currency) ? (string) $conf->currency : 'DKK';
        }
        $companyCurrency = strtoupper(trim($companyCurrency));

        $customerIds = array();
        $currencies = array();
        foreach ($invoiceRows as $invoice) {
            $customerId = (int) $invoice->fk_soc;
            $this->s->access->requireBillingCustomer($customerId, $this->s->db);
            $customerIds[$customerId] = true;
            $code = strtoupper(trim((string) $invoice->currency_code));
            $currencies[$code !== '' ? $code : $companyCurrency] = true;
        }

        // A single native payment cannot safely be allocated using one currency
        // if its linked invoices disagree on currency or customer.
        if (count($customerIds) !== 1 || count($currencies) > 1) {
            throw new RuntimeException('TrainingPaymentAmbiguousInvoiceContext');
        }

        $customerId = (int) array_key_first($customerIds);
        $currency = (string) array_key_first($currencies);
        $amount = ($currency !== $companyCurrency && (float) $payment->multicurrency_amount > 0)
            ? (string) $payment->multicurrency_amount
            : (string) $payment->amount;

        // Preserve the customer context expected by allocation persistence. This
        // is derived from the native invoice relation; llx_paiement has no fk_soc.
        $payment->fk_soc = $customerId;

        $snapshot = array(
            'payment_id' => $paymentId,
            'ref' => $payment->ref,
            'amount' => number_format((float) $amount, 8, '.', ''),
            'currency' => $currency,
            'datep' => $payment->datep,
            'customer_id' => $customerId
        );
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return array(
            'payment' => $payment,
            'snapshot' => $snapshot,
            'snapshot_json' => $snapshotJson,
            'hash' => hash('sha256', $snapshotJson),
            'eligible' => true
        );
    }

    /**
     * Get payment by enrollment (for existing allocations).
     */
    public function paymentForEnrollment(int $enrollmentId): array {
        
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
