<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingbillingamount.class.php';
/** Read-only Dolibarr 24.0.2 adapter. Never updates accounting tables. */
final class TrainingInvoiceAdapter
{
    private TrainingStore $s;
    private string $currency;
    public function __construct(TrainingStore $store, string $baseCurrency) {
        if (!preg_match('/^[A-Z]{3}$/D', $baseCurrency)) { throw new InvalidArgumentException('TrainingInvalidBillingCurrency'); }
        $this->s = $store; $this->currency = $baseCurrency;
    }
    public function line(int $invoiceId, int $lineId, bool $lock = false): array {
        $this->s->access->requireInvoiceRead();
        $headers = $this->s->rows('SELECT rowid, entity, ref, type, fk_soc, fk_statut, multicurrency_code, CAST(multicurrency_tx AS DECIMAL(24,8)) AS multicurrency_tx FROM '.$this->s->db->prefix().'facture WHERE rowid='.$invoiceId.' AND entity='.$this->s->access->entity().($lock ? ' FOR UPDATE' : ''));
        if (!$headers) { throw new RuntimeException('TrainingInvoiceNotAccessible'); }
        $invoice = $headers[0];
        $this->s->access->requireBillingCustomer((int) $invoice->fk_soc, $this->s->db);
        $lines = $this->s->rows('SELECT rowid, fk_facture, fk_product, product_type, description, CAST(qty AS DECIMAL(24,8)) AS qty, CAST(subprice AS DECIMAL(24,8)) AS subprice, CAST(remise_percent AS DECIMAL(16,8)) AS remise_percent, CAST(tva_tx AS DECIMAL(16,8)) AS tva_tx, CAST(total_ht AS DECIMAL(24,8)) AS total_ht, CAST(total_tva AS DECIMAL(24,8)) AS total_tva, CAST(total_localtax1 AS DECIMAL(24,8)) AS total_localtax1, CAST(total_localtax2 AS DECIMAL(24,8)) AS total_localtax2, CAST(total_ttc AS DECIMAL(24,8)) AS total_ttc FROM '.$this->s->db->prefix().'facturedet WHERE rowid='.$lineId.' AND fk_facture='.$invoiceId.($lock ? ' FOR UPDATE' : ''));
        if (!$lines) { throw new RuntimeException('TrainingInvoiceLineNotFound'); }
        $line = $lines[0];
        $snapshot = array('invoice_id' => $invoiceId, 'line_id' => $lineId, 'invoice_ref' => $invoice->ref, 'invoice_type' => (int) $invoice->type, 'customer_id' => (int) $invoice->fk_soc, 'currency' => $this->currency, 'document_currency' => $invoice->multicurrency_code ?? '', 'exchange_rate' => $invoice->multicurrency_tx, 'line' => (array) $line);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $eligible = (int) $invoice->type === 0 && in_array((int) $invoice->fk_statut, array(1,2), true)
            && (($invoice->multicurrency_code ?? '') === '' || $invoice->multicurrency_code === $this->currency)
            && ($invoice->multicurrency_tx === null || $invoice->multicurrency_tx === '1.00000000')
            && (int) $line->product_type === 1 && (int) $line->fk_product > 0;
        return array('invoice' => $invoice, 'line' => $line, 'snapshot' => $snapshot, 'snapshot_json' => $json, 'hash' => hash('sha256', $json), 'eligible' => $eligible);
    }
}
