<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingbillingamount.class.php';

/**
 * Read-only Dolibarr Facture adapter for credit notes.
 * Never updates accounting tables. Provides credit note data for allocation.
 */
final class TrainingCreditNoteAdapter
{
    private TrainingStore $s;
    private string $currency;

    public function __construct(TrainingStore $store, string $baseCurrency) {
        if (!preg_match('/^[A-Z]{3}$/D', $baseCurrency)) {
            throw new InvalidArgumentException('TrainingInvalidBillingCurrency');
        }
        $this->s = $store;
        $this->currency = $baseCurrency;
    }

    /**
     * Get credit note (having type = 2 in Dolibarr) line details.
     * Credit notes have type = 2 in llx_facture.
     */
    public function line(int $invoiceId, int $lineId, bool $lock = false): array {
        $this->s->access->requireInvoiceRead();
        
        $headers = $this->s->rows(
            'SELECT rowid, entity, ref, type, fk_soc, fk_statut, multicurrency_code, '
            'CAST(multicurrency_tx AS DECIMAL(24,8)) AS multicurrency_tx '
            'FROM '.$this->s->db->prefix().'facture '
            'WHERE rowid='.$invoiceId.' AND entity='.$this->s->access->entity().
            ($lock ? ' FOR UPDATE' : '')
        );
        
        if (!$headers) {
            throw new RuntimeException('TrainingInvoiceNotAccessible');
        }
        
        $invoice = $headers[0];
        $this->s->access->requireBillingCustomer((int) $invoice->fk_soc, $this->s->db);
        
        // Credit notes have type = 2
        if ((int) $invoice->type !== 2) {
            throw new RuntimeException('TrainingCreditNoteTypeMismatch');
        }
        
        $lines = $this->s->rows(
            'SELECT rowid, fk_facture, fk_product, product_type, description, '
            'CAST(qty AS DECIMAL(24,8)) AS qty, '
            'CAST(subprice AS DECIMAL(24,8)) AS subprice, '
            'CAST(remise_percent AS DECIMAL(16,8)) AS remise_percent, '
            'CAST(tva_tx AS DECIMAL(16,8)) AS tva_tx, '
            'CAST(total_ht AS DECIMAL(24,8)) AS total_ht, '
            'CAST(total_tva AS DECIMAL(24,8)) AS total_tva, '
            'CAST(total_localtax1 AS DECIMAL(24,8)) AS total_localtax1, '
            'CAST(total_localtax2 AS DECIMAL(24,8)) AS total_localtax2, '
            'CAST(total_ttc AS DECIMAL(24,8)) AS total_ttc '
            'FROM '.$this->s->db->prefix().'facturedet '
            'WHERE rowid='.$lineId.' AND fk_facture='.$invoiceId.
            ($lock ? ' FOR UPDATE' : '')
        );
        
        if (!$lines) {
            throw new RuntimeException('TrainingInvoiceLineNotFound');
        }
        
        $line = $lines[0];
        
        $snapshot = array(
            'invoice_id' => $invoiceId,
            'line_id' => $lineId,
            'invoice_ref' => $invoice->ref,
            'invoice_type' => (int) $invoice->type,
            'customer_id' => (int) $invoice->fk_soc,
            'currency' => $this->currency,
            'document_currency' => $invoice->multicurrency_code ?? '',
            'exchange_rate' => $invoice->multicurrency_tx,
            'line' => (array) $line
        );
        
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        
        // Credit notes are eligible if validated (fk_statut = 1 or 2) and in base currency
        $eligible = in_array((int) $invoice->fk_statut, array(1, 2), true)
            && (($invoice->multicurrency_code ?? '') === '' || $invoice->multicurrency_code === $this->currency)
            && ($invoice->multicurrency_tx === null || $invoice->multicurrency_tx === '1.00000000')
            && (int) $line->product_type === 1 && (int) $line->fk_product > 0;
        
        return array(
            'invoice' => $invoice,
            'line' => $line,
            'snapshot' => $snapshot,
            'snapshot_json' => $json,
            'hash' => hash('sha256', $json),
            'eligible' => $eligible
        );
    }

    /**
     * Get credit note header only.
     */
    public function creditNote(int $invoiceId, bool $lock = false): array {
        $this->s->access->requireInvoiceRead();
        
        $rows = $this->s->rows(
            'SELECT rowid, entity, ref, type, fk_soc, fk_statut, datef as date_credit, '
            'CAST(total_ht AS DECIMAL(24,8)) AS total_ht, '
            'CAST(total_tva AS DECIMAL(24,8)) AS total_tva, '
            'CAST(total_ttc AS DECIMAL(24,8)) AS total_ttc, '
            'multicurrency_code, CAST(multicurrency_tx AS DECIMAL(24,8)) AS multicurrency_tx '
            'FROM '.$this->s->db->prefix().'facture '
            'WHERE rowid='.$invoiceId.' AND entity='.$this->s->access->entity().
            ($lock ? ' FOR UPDATE' : '')
        );
        
        if (!$rows) {
            throw new RuntimeException('TrainingInvoiceNotAccessible');
        }
        
        $invoice = $rows[0];
        
        if ((int) $invoice->type !== 2) {
            throw new RuntimeException('TrainingCreditNoteTypeMismatch');
        }
        
        $this->s->access->requireBillingCustomer((int) $invoice->fk_soc, $this->s->db);
        
        return array(
            'credit_note' => $invoice,
            'is_credit_note' => true,
            'total_ttc' => $invoice->total_ttc,
            'currency' => $invoice->multicurrency_code ?? $this->currency,
            'date_credit' => $invoice->date_credit,
            'ref' => $invoice->ref
        );
    }

    /**
     * Check if an invoice is a credit note.
     */
    public function isCreditNote(int $invoiceId): bool {
        $this->s->access->requireInvoiceRead();
        
        $rows = $this->s->rows(
            'SELECT type FROM '.$this->s->db->prefix().'facture '
            'WHERE rowid='.$invoiceId.' AND entity='.$this->s->access->entity()
        );
        
        return $rows && (int) $rows[0]->type === 2;
    }

    /**
     * Get credit notes for a customer.
     */
    public function creditNotesForCustomer(int $socId): array {
        $this->s->access->requireInvoiceRead();
        $this->s->access->requireBillingCustomer($socId, $this->s->db);
        
        $rows = $this->s->rows(
            'SELECT rowid, ref, datef as date_credit, '
            'CAST(total_ttc AS DECIMAL(24,8)) AS total_ttc, '
            'multicurrency_code '
            'FROM '.$this->s->db->prefix().'facture '
            'WHERE entity='.$this->s->access->entity().
            ' AND fk_soc='.$socId.
            ' AND type=2'
            ' AND fk_statut IN (1,2)'
            ' ORDER BY datef DESC'
        );
        
        return $rows;
    }
}
