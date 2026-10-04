<?php
require_once __DIR__.'/trainingstore.class.php';

/**
 * Stripe API adapter for Training module.
 * Read-only adapter pattern: only retrieves data from Stripe, never modifies.
 * All Stripe write operations are handled through TrainingCheckoutService.
 */
final class TrainingStripeAdapter
{
    private TrainingStore $store;
    private string $apiKey;
    private string $webhookSecret;
    private $stripe;

    public function __construct(TrainingStore $store, string $apiKey, string $webhookSecret)
    {
        $this->store = $store;
        $this->apiKey = $apiKey;
        $this->webhookSecret = $webhookSecret;
        $this->stripe = $this->initStripe();
    }

    private function initStripe()
    {
        if (!class_exists('Stripe\Stripe')) {
            throw new RuntimeException('Stripe PHP SDK not installed');
        }
        return \Stripe\Stripe::setApiKey($this->apiKey);
    }

    /**
     * Create a PaymentIntent for a checkout session.
     */
    public function createPaymentIntent(array $params): array
    {
        $this->validatePaymentIntentParams($params);
        
        try {
            $paymentIntent = \Stripe\PaymentIntent::create($params);
            return array(
                'id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'metadata' => $paymentIntent->metadata ?? array()
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), 0, $e);
        }
    }

    private function validatePaymentIntentParams(array $params): void
    {
        if (empty($params['amount']) || !is_int($params['amount']) || $params['amount'] < 0) {
            throw new InvalidArgumentException('TrainingStripeInvalidAmount');
        }
        if (empty($params['currency']) || !preg_match('/^[A-Z]{3}$/', $params['currency'])) {
            throw new InvalidArgumentException('TrainingStripeInvalidCurrency');
        }
        if (empty($params['metadata']) || !is_array($params['metadata'])) {
            throw new InvalidArgumentException('TrainingStripeMetadataRequired');
        }
        if (!isset($params['payment_method_types']) || !is_array($params['payment_method_types'])) {
            $params['payment_method_types'] = array('card');
        }
    }

    /**
     * Retrieve a PaymentIntent by ID.
     */
    public function retrievePaymentIntent(string $id): array
    {
        $this->validateId($id);
        
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($id);
            return array(
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'payment_method' => $paymentIntent->payment_method,
                'payment_method_types' => $paymentIntent->payment_method_types,
                'charges' => $this->extractCharges($paymentIntent),
                'metadata' => $paymentIntent->metadata ?? array(),
                'last_webhook' => $paymentIntent->last_webhook ? $this->formatTimestamp($paymentIntent->last_webhook) : null
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: PaymentIntent not found', 0, $e);
        }
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,255}$/', $id)) {
            throw new InvalidArgumentException('TrainingStripeInvalidId');
        }
    }

    private function extractCharges($paymentIntent): array
    {
        $charges = array();
        if (empty($paymentIntent->charges) || !is_object($paymentIntent->charges)) {
            return $charges;
        }
        foreach ($paymentIntent->charges->data as $charge) {
            $charges[] = array(
                'id' => $charge->id,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
                'status' => $charge->status,
                'payment_method' => $charge->payment_method,
                'receipt_url' => $charge->receipt_url,
                'outcome' => $charge->outcome ? array(
                    'type' => $charge->outcome->type,
                    'network_status' => $charge->outcome->network_status,
                    'reason' => $charge->outcome->reason,
                    'seller_message' => $charge->outcome->seller_message
                ) : null
            );
        }
        return $charges;
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Create a Customer in Stripe.
     */
    public function createCustomer(array $params): array
    {
        $this->validateCustomerParams($params);
        
        try {
            $customer = \Stripe\Customer::create($params);
            return array(
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'metadata' => $customer->metadata ?? array(),
                'created' => $this->formatTimestamp($customer->created)
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), 0, $e);
        }
    }

    private function validateCustomerParams(array $params): void
    {
        if (empty($params['email']) || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('TrainingStripeInvalidEmail');
        }
        if (empty($params['metadata']) || !is_array($params['metadata'])) {
            throw new InvalidArgumentException('TrainingStripeMetadataRequired');
        }
    }

    /**
     * Retrieve a Customer by ID.
     */
    public function retrieveCustomer(string $id): array
    {
        $this->validateId($id);
        
        try {
            $customer = \Stripe\Customer::retrieve($id);
            return array(
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'metadata' => $customer->metadata ?? array(),
                'created' => $this->formatTimestamp($customer->created),
                'payment_methods' => $this->extractPaymentMethods($customer)
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: Customer not found', 0, $e);
        }
    }

    private function extractPaymentMethods($customer): array
    {
        $methods = array();
        if (empty($customer->payment_methods) || !is_object($customer->payment_methods)) {
            return $methods;
        }
        foreach ($customer->payment_methods->data as $pm) {
            $methods[] = array(
                'id' => $pm->id,
                'type' => $pm->type,
                'card' => $pm->type === 'card' ? array(
                    'brand' => $pm->card->brand,
                    'last4' => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year' => $pm->card->exp_year
                ) : null
            );
        }
        return $methods;
    }

    /**
     * List PaymentMethods for a Customer.
     */
    public function listPaymentMethods(string $customerId, array $params = array()): array
    {
        $this->validateId($customerId);
        
        $defaultParams = array(
            'customer' => $customerId,
            'type' => 'card'
        );
        $params = array_merge($defaultParams, $params);
        
        try {
            $paymentMethods = \Stripe\PaymentMethod::all($params);
            $result = array();
            foreach ($paymentMethods->data as $pm) {
                $result[] = array(
                    'id' => $pm->id,
                    'type' => $pm->type,
                    'card' => $pm->type === 'card' ? array(
                        'brand' => $pm->card->brand,
                        'last4' => $pm->card->last4,
                        'exp_month' => $pm->card->exp_month,
                        'exp_year' => $pm->card->exp_year
                    ) : null
                );
            }
            return $result;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Detach a PaymentMethod from a Customer.
     */
    public function detachPaymentMethod(string $paymentMethodId): array
    {
        $this->validateId($paymentMethodId);
        
        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->detach();
            return array(
                'id' => $paymentMethod->id,
                'detached' => true
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Refund a PaymentIntent or Charge.
     */
    public function refundPayment(string $paymentIntentId, array $params = array()): array
    {
        $this->validateId($paymentIntentId);
        
        try {
            // Try as PaymentIntent first
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
            $refund = \Stripe\Refund::create(array_merge($params, array(
                'payment_intent' => $paymentIntentId
            )));
            
            return array(
                'id' => $refund->id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status,
                'payment_intent' => $refund->payment_intent,
                'created' => $this->formatTimestamp($refund->created)
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Try as Charge
            try {
                $refund = \Stripe\Refund::create(array_merge($params, array(
                    'charge' => $paymentIntentId
                )));
                return array(
                    'id' => $refund->id,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'status' => $refund->status,
                    'charge' => $refund->charge,
                    'created' => $this->formatTimestamp($refund->created)
                );
            } catch (\Stripe\Exception\ApiErrorException $e2) {
                throw new RuntimeException('Stripe API error: '.$e2->getMessage(), 0, $e2);
            }
        }
    }

    /**
     * Verify a webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            throw new RuntimeException('Stripe webhook secret not configured');
        }
        
        try {
            return \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            ) !== null;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return false;
        }
    }

    /**
     * Parse a webhook event.
     */
    public function parseWebhookEvent(string $payload, string $signature): array
    {
        if (!$this->verifyWebhookSignature($payload, $signature)) {
            throw new RuntimeException('Stripe webhook signature verification failed');
        }
        
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $this->webhookSecret);
            return array(
                'id' => $event->id,
                'type' => $event->type,
                'object' => $event->data->object,
                'created' => $this->formatTimestamp($event->created),
                'livemode' => $event->livemode,
                'request' => $event->request ? array(
                    'id' => $event->request->id,
                    'idempotency_key' => $event->request->idempotency_key
                ) : null
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Search for PaymentIntents by metadata.
     */
    public function searchPaymentIntents(array $metadata, int $limit = 10): array
    {
        $query = array();
        foreach ($metadata as $key => $value) {
            $query[] = urlencode($key).'='.urlencode($value);
        }
        
        try {
            $paymentIntents = \Stripe\PaymentIntent::all(array(
                'query' => 'metadata[\''.implode('\'] AND metadata[\'', $query).'\']',
                'limit' => $limit
            ));
            
            $result = array();
            foreach ($paymentIntents->data as $pi) {
                $result[] = array(
                    'id' => $pi->id,
                    'status' => $pi->status,
                    'amount' => $pi->amount,
                    'currency' => $pi->currency,
                    'metadata' => $pi->metadata ?? array(),
                    'created' => $this->formatTimestamp($pi->created)
                );
            }
            return $result;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the Stripe API key (masked for logging).
     */
    public function getApiKeyMasked(): string
    {
        return substr($this->apiKey, 0, 4).str_repeat('*', max(0, strlen($this->apiKey) - 8)).substr($this->apiKey, -4);
    }

    /**
     * Check if Stripe is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->webhookSecret);
    }
}
