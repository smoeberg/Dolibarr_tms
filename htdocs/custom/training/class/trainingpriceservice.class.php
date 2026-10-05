<?php
require_once __DIR__.'/trainingstore.class.php';

/**
 * Price agreement and snapshot service.
 * Freezes the accepted price (HT, VAT, currency, unit) on enrollment at confirmation time.
 * Catalog price changes must not affect historical enrollments.
 *
 * Dolibarr remains authoritative for the catalog price. Product::$price and
 * Product::$price_ttc are already normalized by Dolibarr according to
 * Product::$price_base_type; price_base_type is NOT a currency field.
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
     * Returns the standard Dolibarr service price at the time of query.
     *
     * Returned monetary values are canonical numeric strings, not SQL literals.
     */
    public function getCatalogPrice(int $sessionId): array
    {
        $this->s->access->requireDomain('session', 'read');
        $session = $this->s->session($sessionId);

        // Delegate to the store's product loader (real Dolibarr Product in
        // production, injected loader in tests) so Dolibarr stays authoritative.
        $product = $this->s->product((int) $session->fk_product);
        $this->s->access->requireService($product);

        // Dolibarr is authoritative. Product::$price is the HT value after
        // Dolibarr normalisation, while Product::$price_ttc is the TTC value.
        // Loader results may omit price columns (e.g. minimal test fixtures);
        // treat them as zero rather than undefined-property warnings.
        $price_ht = isset($product->price) ? (float) $product->price : 0.0;
        $price_ttc = isset($product->price_ttc) ? (float) $product->price_ttc : 0.0;
        $tva_tx = isset($product->tva_tx) ? (float) $product->tva_tx : 0.0;

        // price_base_type is HT/TTC, never the currency. Currency comes from
        // the Dolibarr entity/company configuration.
        $currency = function_exists('getDolGlobalString')
            ? getDolGlobalString('MAIN_MONNAIE')
            : '';
        if ($currency === '') {
            global $conf;
            $currency = !empty($conf->currency) ? (string) $conf->currency : 'DKK';
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('TrainingInvalidCurrency');
        }

        return array(
            'fk_product' => (int) $session->fk_product,
            'fk_session' => $sessionId,
            'price_ht' => number_format($price_ht, 8, '.', ''),
            'price_ttc' => number_format($price_ttc, 8, '.', ''),
            'unit_price_ht' => number_format($price_ht, 8, '.', ''),
            'unit_price_ttc' => number_format($price_ttc, 8, '.', ''),
            'tva_tx' => number_format($tva_tx, 8, '.', ''),
            'currency' => $currency,
            // MVP unit: one course seat/participant per enrollment.
            'qty' => '1.00000000',
            'entity' => $this->s->access->entity()
        );
    }

    public function getEnrollmentPrice(int $enrollmentId, int $sessionId): ?array
    {
        $this->allow('read');
        $rows = $this->s->rows(
            'SELECT * FROM '.$this->s->table('enrollment_price').
            ' WHERE entity='.$this->s->access->entity().
            ' AND fk_enrollment='.$enrollmentId.
            ' AND fk_session='.$sessionId
        );
        if (!$rows) return null;
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

    public function createSnapshot(int $sessionId, int $enrollmentId, int $contactId): void
    {
        $this->allow('write');
        $priceData = $this->getCatalogPrice($sessionId);
        $this->s->session($sessionId);
        $entity = $this->s->access->entity();
        $actor = $this->s->access->actor();

        $existing = $this->s->rows(
            'SELECT rowid FROM '.$this->s->table('enrollment_price').
            ' WHERE entity='.$entity.
            ' AND fk_enrollment='.$enrollmentId.
            ' AND fk_session='.$sessionId
        );
        if ($existing) return;

        // SQL quoting belongs at the persistence boundary. The DTO above stays
        // free of SQL literals so callers cannot accidentally double-quote values.
        $this->s->query(
            'INSERT INTO '.$this->s->table('enrollment_price').
            ' (entity, fk_enrollment, fk_session, fk_product, price_ht, price_ttc, tva_tx, currency, unit_price_ht, unit_price_ttc, qty, datec, fk_user_author) VALUES ('
            .$entity.', '.$enrollmentId.', '.$sessionId.', '.$priceData['fk_product'].', '
            .$this->s->text($priceData['price_ht']).', '.$this->s->text($priceData['price_ttc']).', '.$this->s->text($priceData['tva_tx']).', '
            .$this->s->text($priceData['currency']).', '.$this->s->text($priceData['unit_price_ht']).', '.$this->s->text($priceData['unit_price_ttc']).', '
            .$this->s->text($priceData['qty']).', '.$this->s->now().', '.$actor.')'
        );

        $this->s->audit('enrollment_price', (int) $this->s->db->last_insert_id($this->s->table('enrollment_price')), 'created', array(
            'enrollment_id' => $enrollmentId,
            'session_id' => $sessionId,
            'price_ht' => $priceData['price_ht'],
            'currency' => $priceData['currency']
        ));
    }

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
        try {
            $catalog = $this->getCatalogPrice($sessionId);
            return array(
                'has_snapshot' => false,
                'price_ht' => $catalog['price_ht'],
                'price_ttc' => $catalog['price_ttc'],
                'currency' => $catalog['currency'],
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
