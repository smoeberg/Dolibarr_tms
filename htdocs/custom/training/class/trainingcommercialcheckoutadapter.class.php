<?php
require_once __DIR__.'/trainingstore.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

/**
 * Native Dolibarr commercial checkout boundary.
 *
 * This adapter creates the native commercial document needed to enter
 * Dolibarr's own online-payment flow. It never talks to Stripe and never
 * writes llx_paiement.
 *
 * The adapter deliberately returns only native commercial/payment URL data.
 * Payment confirmation is handled later by locating a native Paiement and
 * allocating it through TrainingPaymentService.
 */
final class TrainingCommercialCheckoutAdapter
{
    private TrainingStore $store;
    /** @var callable */
    private $invoiceFactory;
    /** @var callable */
    private $paymentUrlFactory;

    /**
     * @param callable|null $invoiceFactory function($db): object
     * @param callable|null $paymentUrlFactory function(int $entity, string $ref): string
     */
    public function __construct(
        TrainingStore $store,
        ?callable $invoiceFactory = null,
        ?callable $paymentUrlFactory = null
    ) {
        $this->store = $store;
        $this->invoiceFactory = $invoiceFactory ?? static function ($db) {
            require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
            return new Facture($db);
        };
        $this->paymentUrlFactory = $paymentUrlFactory ?? static function (int $entity, string $ref): string {
            $url = getOnlinePaymentUrl(0, 'invoice', $ref);
            if ($url === '') {
                throw new RuntimeException('TrainingCheckoutPaymentUrlUnavailable');
            }

            // getOnlinePaymentUrl() does not carry the TMS entity context on
            // its own. Preserve entity isolation explicitly for multi-entity
            // checkout entry points.
            if (!preg_match('/(?:[?&])e=\\d+(?:&|$)/', $url)) {
                $url .= (strpos($url, '?') === false ? '?' : '&').'e='.rawurlencode((string) $entity);
            }

            return $url;
        };
    }

    /**
     * Create and validate one native customer invoice for the checkout.
     *
     * $commercial must contain:
     *   - socid: native Dolibarr third-party id
     *   - product_id: native Dolibarr service id
     *   - price_ht: agreed unit price HT
     *   - price_ttc: agreed unit price TTC
     *   - tva_tx: transaction VAT rate
     *   - currency: entity currency
     *   - qty: number of seats/participants
     *   - description: optional invoice line description
     *
     * The native Product remains catalog master; the supplied price is the
     * transaction price frozen by TMS checkout.
     */
    public function createInvoice(array $commercial): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        $this->store->access->requireInvoiceRead();

        if (!$this->store->access->actorUser()->hasRight('facture', 'creer')) {
            throw new RuntimeException('TrainingCheckoutInvoiceCreateDenied');
        }

        $socid = $this->positiveInt($commercial['socid'] ?? null, 'TrainingCheckoutCustomerRequired');
        $productId = $this->positiveInt($commercial['product_id'] ?? null, 'TrainingCheckoutProductRequired');
        $qty = $this->positiveFloat($commercial['qty'] ?? null, 'TrainingCheckoutQuantityRequired');

        $this->store->access->requireBillingCustomer($socid, $this->store->db);
        $product = $this->store->product($productId);

        $priceHt = $this->decimal($commercial['price_ht'] ?? null, 'TrainingCheckoutPriceRequired');
        $priceTtc = $this->decimal($commercial['price_ttc'] ?? null, 'TrainingCheckoutPriceRequired');
        $tvaTx = $this->decimal($commercial['tva_tx'] ?? null, 'TrainingCheckoutVatRequired');
        $currency = strtoupper(trim((string) ($commercial['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('TrainingInvalidCurrency');
        }

        $entity = $this->store->access->entity();
        $this->assertCurrency($currency);
        $this->assertProductPriceAgreement($product, $priceHt, $priceTtc, $tvaTx);
        $description = trim((string) ($commercial['description'] ?? $product->label ?? 'Training service'));
        if ($description === '') {
            $description = 'Training service';
        }

        $invoice = ($this->invoiceFactory)($this->store->db);
        if (!is_object($invoice)) {
            throw new RuntimeException('TrainingCheckoutInvoiceAdapterInvalid');
        }

        $invoice->socid = $socid;
        $invoice->date = function_exists('dol_now') ? dol_now() : time();

        $result = $invoice->create($this->store->access->actorUser());
        if ($result <= 0) {
            throw new RuntimeException($this->nativeError($invoice, 'TrainingCheckoutInvoiceCreateFailed'));
        }

        $lineResult = $invoice->addline(
            $description,
            (float) $priceHt,
            $qty,
            (float) $tvaTx,
            0,
            0,
            $productId,
            0,
            '',
            0,
            'HT',
            (float) $priceTtc,
            1,
            0,
            0,
            '',
            0,
            0,
            '',
            '',
            array(),
            null,
            0,
            '',
            0,
            -1,
            0,
            ''
        );
        if ($lineResult <= 0) {
            $this->deleteDraft($invoice);
            throw new RuntimeException($this->nativeError($invoice, 'TrainingCheckoutInvoiceLineCreateFailed'));
        }

        $validateResult = $invoice->validate($this->store->access->actorUser());
        if ($validateResult <= 0) {
            $this->deleteDraft($invoice);
            throw new RuntimeException($this->nativeError($invoice, 'TrainingCheckoutInvoiceValidateFailed'));
        }

        $invoiceId = (int) ($invoice->id ?? 0);
        $invoiceRef = trim((string) ($invoice->ref ?? ''));
        if ($invoiceId < 1 || $invoiceRef === '') {
            throw new RuntimeException('TrainingCheckoutInvoiceIdentityMissing');
        }

        $paymentUrl = ($this->paymentUrlFactory)($entity, $invoiceRef);
        if (!is_string($paymentUrl) || $paymentUrl === '') {
            throw new RuntimeException('TrainingCheckoutPaymentUrlUnavailable');
        }

        return array(
            'entity' => $entity,
            'invoice_id' => $invoiceId,
            'invoice_ref' => $invoiceRef,
            'socid' => $socid,
            'product_id' => $productId,
            'qty' => number_format($qty, 8, '.', ''),
            'price_ht' => $priceHt,
            'price_ttc' => $priceTtc,
            'tva_tx' => $tvaTx,
            'currency' => $currency,
            'payment_url' => $paymentUrl
        );
    }

    private function assertCurrency(string $currency): void
    {
        global $conf;
        $nativeCurrency = '';
        if (function_exists('getDolGlobalString')) {
            $nativeCurrency = (string) getDolGlobalString('MAIN_MONNAIE');
        }
        if ($nativeCurrency === '' && !empty($conf->currency)) {
            $nativeCurrency = (string) $conf->currency;
        }
        $nativeCurrency = strtoupper(trim($nativeCurrency));
        if ($nativeCurrency !== '' && $nativeCurrency !== $currency) {
            throw new RuntimeException('TrainingCheckoutCurrencyMismatch');
        }
    }

    private function assertProductPriceAgreement($product, string $priceHt, string $priceTtc, string $tvaTx): void
    {
        $nativeHt = number_format((float) ($product->price ?? 0), 8, '.', '');
        $nativeTtc = number_format((float) ($product->price_ttc ?? 0), 8, '.', '');
        $nativeVat = number_format((float) ($product->tva_tx ?? 0), 8, '.', '');

        if ((float) $priceHt !== (float) $nativeHt || (float) $priceTtc !== (float) $nativeTtc || (float) $tvaTx !== (float) $nativeVat) {
            throw new RuntimeException('TrainingCheckoutPriceAgreementMismatch');
        }
    }

    private function positiveInt($value, string $error): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException($error);
        }
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function positiveFloat($value, string $error): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($error);
        }
        $value = (float) $value;
        if ($value <= 0) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function decimal($value, string $error): string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException($error);
        }
        return number_format((float) $value, 8, '.', '');
    }

    private function nativeError($invoice, string $fallback): string
    {
        $parts = array();
        if (!empty($invoice->error)) {
            $parts[] = (string) $invoice->error;
        }
        if (!empty($invoice->errors) && is_array($invoice->errors)) {
            foreach ($invoice->errors as $error) {
                $parts[] = (string) $error;
            }
        }
        return $parts ? $fallback.': '.implode('; ', $parts) : $fallback;
    }

    private function deleteDraft($invoice): void
    {
        if (empty($invoice->id) || !method_exists($invoice, 'delete')) {
            return;
        }
        try {
            $invoice->fetch((int) $invoice->id);
            if (isset($invoice->statut) && (int) $invoice->statut !== 0) {
                return;
            }
            $invoice->delete($this->store->access->actorUser());
        } catch (Throwable $ignored) {
            // The original native error remains authoritative. Cleanup is best effort.
        }
    }
}
