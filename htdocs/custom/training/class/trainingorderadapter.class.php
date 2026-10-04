<?php
require_once __DIR__.'/trainingstore.class.php';

/**
 * Read-only Dolibarr Commande/CommandeDet adapter.
 * Never updates accounting tables. Mirrors TrainingInvoiceAdapter pattern.
 */
final class TrainingOrderAdapter
{
    private TrainingStore $s;
    private string $currency;

    public function __construct(TrainingStore $store, string $baseCurrency) {
        if (!preg_match('/^[A-Z]{3}$/D', $baseCurrency)) { 
            throw new InvalidArgumentException('TrainingInvalidOrderCurrency'); 
        }
        $this->s = $store;
        $this->currency = $baseCurrency;
    }

    /**
     * Get order line details for allocation.
     * Returns validated standard order line data.
     */
    public function line(int $orderId, int $lineId, bool $lock = false): array {
        $this->s->access->requireOrderRead();
        
        // Get order header
        $headers = $this->s->rows(
            'SELECT rowid, entity, ref, type, fk_soc, fk_statut, multicurrency_code, '.
            'CAST(multicurrency_tx AS DECIMAL(24,8)) AS multicurrency_tx '.
            'FROM '.$this->s->db->prefix().'commande '.
            'WHERE rowid='.$orderId.' AND entity='.$this->s->access->entity().
            ($lock ? ' FOR UPDATE' : '')
        );
        
        if (!$headers) { 
            throw new RuntimeException('TrainingOrderNotAccessible'); 
        }
        
        $order = $headers[0];
        $this->s->access->requireBillingCustomer((int) $order->fk_soc, $this->s->db);
        
        // Get order line
        $lines = $this->s->rows(
            'SELECT rowid, fk_commande, fk_product, product_type, description, '.
            'CAST(qty AS DECIMAL(24,8)) AS qty, '.
            'CAST(subprice AS DECIMAL(24,8)) AS subprice, '.
            'CAST(remise_percent AS DECIMAL(16,8)) AS remise_percent, '.
            'CAST(tva_tx AS DECIMAL(16,8)) AS tva_tx, '.
            'CAST(total_ht AS DECIMAL(24,8)) AS total_ht, '.
            'CAST(total_tva AS DECIMAL(24,8)) AS total_tva, '.
            'CAST(total_localtax1 AS DECIMAL(24,8)) AS total_localtax1, '.
            'CAST(total_localtax2 AS DECIMAL(24,8)) AS total_localtax2, '.
            'CAST(total_ttc AS DECIMAL(24,8)) AS total_ttc '.
            'FROM '.$this->s->db->prefix().'commandedet '.
            'WHERE rowid='.$lineId.' AND fk_commande='.$orderId.
            ($lock ? ' FOR UPDATE' : '')
        );
        
        if (!$lines) { 
            throw new RuntimeException('TrainingOrderLineNotFound'); 
        }
        
        $line = $lines[0];
        
        // Build snapshot
        $snapshot = array(
            'order_id' => $orderId,
            'line_id' => $lineId,
            'order_ref' => $order->ref,
            'order_type' => (int) $order->type,
            'customer_id' => (int) $order->fk_soc,
            'currency' => $this->currency,
            'document_currency' => $order->multicurrency_code ?? '',
            'exchange_rate' => $order->multicurrency_tx,
            'line' => (array) $line
        );
        
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        
        // Validate eligibility
        // Only validated orders (type 0 = standard order), status 1=validated or 2=delivered
        // Base currency only (no foreign currency in MVP)
        $eligible = (int) $order->type === 0 && in_array((int) $order->fk_statut, array(1, 2), true)
            && (($order->multicurrency_code ?? '') === '' || $order->multicurrency_code === $this->currency)
            && ($order->multicurrency_tx === null || $order->multicurrency_tx === '1.00000000')
            && (int) $line->product_type === 1 && (int) $line->fk_product > 0;
        
        return array(
            'order' => $order,
            'line' => $line,
            'snapshot' => $snapshot,
            'snapshot_json' => $json,
            'hash' => hash('sha256', $json),
            'eligible' => $eligible
        );
    }

    /**
     * Get order line by line ID only (for existing allocations).
     */
    public function lineById(int $lineId): array {
        $this->s->access->requireOrderRead();
        
        $lines = $this->s->rows(
            'SELECT c.rowid as order_id, cd.* '.
            'FROM '.$this->s->db->prefix().'commandedet cd '.
            'JOIN '.$this->s->db->prefix().'commande c ON c.rowid = cd.fk_commande '.
            'WHERE cd.rowid='.$lineId.
            ' AND cd.entity='.$this->s->access->entity()
        );
        
        if (!$lines) { 
            throw new RuntimeException('TrainingOrderLineNotFound'); 
        }
        
        $line = $lines[0];
        $this->s->access->requireBillingCustomer((int) $line->fk_soc, $this->s->db);
        
        return array(
            'order_id' => (int) $line->order_id,
            'line_id' => $lineId,
            'fk_product' => (int) $line->fk_product,
            'product_type' => (int) $line->product_type
        );
    }
}
