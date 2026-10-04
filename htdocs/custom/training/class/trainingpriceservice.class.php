<?php
require_once __DIR__.'/trainingstore.class.php';

/**
 * Price agreement and snapshot service.
 * Freezes the accepted price (HT, VAT, currency, unit) on enrollment at confirmation time.
 * Catalog price changes must not affect historical enrollments.
 */
final class TrainingPriceService
{
    private TrainingStore $s;

    public function __construct(TrainingStore $store) { $this->s = $store; }

    private function allow(string $right): void {
        $this->s->access->requireDomain('session', 'read');
        $this->s->access->requireDomain('enrollment', $right);
    }

    /**
     * Get the current catalog price for a session's product.
     * Returns the standard service price at the time of query.
     */
    public function getCatalogPrice(int $sessionId): array
    {
        $this->s->access->requireDomain('session', 'read');
        $session = $this->s->session($sessionId);
        
        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
        $product = new Product($this->s->db);
        if ($product->fetch((int) $session->fk_product) <= 0) {
            throw new RuntimeException('TrainingServiceNotAccessible');
        }
        
        $this->s->access->requireService($product);
        
        // Get price from standard product
        $price = (float) $product->price;
        $tva_tx = (float) $product->tva_tx;
        $currency = $product->price_base_type ? $product->price_base_type : 'DKK';
        
        // Calculate TTC price
        $price_ttc = $price * (1 + ($tva_tx / 100));
        
        return array(
            'fk_product' => (int) $session->fk_product,
            'fk_session' => $sessionId,
            'price_ht' => $this->s->text(number_format($price, 8, '.', '')),
            'price_ttc' => $this->s->text(number_format($price_ttc, 8, '.', '')),
            'unit_price_ht' => $this->s->text(number_format($price, 8, '.', '')),
            'unit_price_ttc' => $this->s->text(number_format($price_ttc, 8, '.', '')),
            'tva_tx' => $this->s->text(number_format($tva_tx, 8, '.', '')),
            'currency' => $this->s->text($currency),
            'qty' => $this->s->text('1'),
            'entity' => $this->s->access->entity()
        );
    }

    /**
     * Get the agreed price snapshot for an enrollment.
     * Returns null if no price snapshot exists (legacy enrollments).
     */
    public function getEnrollmentPrice(int $enrollmentId, int $sessionId): ?array
    {
        $this->allow('read');
        
        $rows = $this->s->rows(
            'SELECT * FROM '.$this->s->table('enrollment_price').
            ' WHERE entity='.$this->s->access->entity().
            ' AND fk_enrollment='.$enrollmentId.
            ' AND fk_session='.$sessionId
        );
        
        if (!$rows) {
            return null;
        }
        
        return array(
            'enrollment_id' => (int) $rows[0]->fk_enrollment,
            'session_id' => (int) $rows[0]->fk_session,
            'product_id' => (int) $rows[0]->fk_product,
            'price_ht' => $rows[0]->price_ht,
            'price_ttc' => $rows[0]->price_ttc,
            'unit_price_ht' => $rows[0]->unit_price_ht,
            'unit_price_ttc' => $rows[0]->unit_price_ttc,
            'tva_tx' => $rows[0]->tva_tx,
            'currency' => $rows[0]->currency,
            'qty' => $rows[0]->qty,
            'datec' => $rows[0]->datec,
            'fk_user_author' => (int) $rows[0]->fk_user_author
        );
    }

    /**
     * Create a price snapshot for an enrollment at confirmation time.
     * This is called internally by EnrollmentService::writeConfirmed().
     * Never call this directly from UI - always use confirm() which handles the transaction.
     */
    public function createSnapshot(int $sessionId, int $enrollmentId, int $contactId): void
    {
        $this->allow('write');
        
        // Get catalog price at confirmation time
        $priceData = $this->getCatalogPrice($sessionId);
        
        // Verify we have the session info
        $session = $this->s->session($sessionId);
        
        $entity = $this->s->access->entity();
        $actor = $this->s->access->actor();
        
        // Check if snapshot already exists
        $existing = $this->s->rows(
            'SELECT rowid FROM '.$this->s->table('enrollment_price').
            ' WHERE entity='.$entity.
            ' AND fk_enrollment='.$enrollmentId.
            ' AND fk_session='.$sessionId
        );
        
        if ($existing) {
            return; // Snapshot already exists
        }
        
        // Create the price snapshot
        $this->s->query(
            'INSERT INTO '.$this->s->table('enrollment_price').
            ' (entity, fk_enrollment, fk_session, fk_product, price_ht, price_ttc, tva_tx, currency, unit_price_ht, unit_price_ttc, qty, datec, fk_user_author) VALUES ('
            .$entity.', '.$enrollmentId.', '.$sessionId.', '.$priceData['fk_product'].', '
            .$priceData['price_ht'].', '.$priceData['price_ttc'].', '.$priceData['tva_tx'].', '
            .$priceData['currency'].', '.$priceData['unit_price_ht'].', '.$priceData['unit_price_ttc'].', '
            .$priceData['qty'].', '.$this->s->now().', '.$actor.')'
        );
        
        $this->s->audit('enrollment_price', (int) $this->s->db->last_insert_id($this->s->table('enrollment_price')), 
            'created', 
            array(
                'enrollment_id' => $enrollmentId,
                'session_id' => $sessionId,
                'price_ht' => $priceData['price_ht'],
                'price_ttc' => $priceData['price_ttc'],
                'currency' => $priceData['currency']
            )
        );
    }

    /**
     * Get price information for display on enrollment list.
     * Returns merged data from snapshot or catalog.
     */
    public function getEnrollmentPriceInfo(int $sessionId, int $enrollmentId): array
    {
        $this->allow('read');
        
        $snapshot = $this->getEnrollmentPrice($enrollmentId, $sessionId);
        
        if ($snapshot) {
            return array(
                'has_snapshot' => true,
                'price_ht' => $snapshot['price_ht'],
                'price_ttc' => $snapshot['price_ttc'],
                'currency' => $snapshot['currency'],
                'tva_tx' => $snapshot['tva_tx'],
                'is_frozen' => true,
                'snapshot_date' => $snapshot['datec']
            );
        }
        
        // No snapshot - return current catalog price (legacy)
        try {
            $catalog = $this->getCatalogPrice($sessionId);
            return array(
                'has_snapshot' => false,
                'price_ht' => $catalog['price_ht'],
                'price_ttc' => $catalog['price_ttc'],
                'currency' => trim($catalog['currency'], "'"),
                'tva_tx' => $catalog['tva_tx'],
                'is_frozen' => false,
                'snapshot_date' => null
            );
        } catch (Throwable $e) {
            return array(
                'has_snapshot' => false,
                'price_ht' => null,
                'price_ttc' => null,
                'currency' => null,
                'tva_tx' => null,
                'is_frozen' => false,
                'snapshot_date' => null
            );
        }
    }
}
