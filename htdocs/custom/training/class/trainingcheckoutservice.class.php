<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingcommercialcheckoutadapter.class.php';
require_once __DIR__.'/trainingstripeadapter.class.php';
require_once __DIR__.'/trainingenrollmentservice.class.php';
require_once __DIR__.'/trainingpriceservice.class.php';
require_once __DIR__.'/trainingschedulingservice.class.php';
require_once __DIR__.'/trainingattendanceservice.class.php';
require_once __DIR__.'/trainingoutboxservice.class.php';

/**
 * Complete checkout flow service for Training module.
 * Handles: seat hold, Stripe PaymentIntent creation, webhook processing,
 * payment confirmation, enrollment creation, and reconciliation.
 */
final class TrainingCheckoutService
{
    private TrainingStore $store;
    private ?TrainingStripeAdapter $stripe;
    private TrainingCommercialCheckoutAdapter $commercialCheckout;
    private TrainingEnrollmentService $enrollmentService;
    private TrainingPriceService $priceService;
    private TrainingSchedulingService $schedulingService;
    private TrainingOutboxService $outboxService;

    public function __construct(
        TrainingStore $store,
        ?TrainingStripeAdapter $stripe = null,
        ?TrainingCommercialCheckoutAdapter $commercialCheckout = null
    ) {
        $this->store = $store;
        $this->stripe = $stripe;
        $this->commercialCheckout = $commercialCheckout ?? new TrainingCommercialCheckoutAdapter($store);
        $this->enrollmentService = new TrainingEnrollmentService($store);
        $this->priceService = new TrainingPriceService($store);
        $this->schedulingService = new TrainingSchedulingService($store);
        $this->outboxService = new TrainingOutboxService($store);
    }

    // ========================================================================
    // SEAT HOLD MANAGEMENT
    // ========================================================================

    /**
     * Create a seat hold for a training session.
     * Returns hold ID and reservation key.
     */
    public function createSeatHold(int $sessionId, array $contactIds, int $minutes = 15): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        if (empty($contactIds) || count($contactIds) > 10) {
            throw new InvalidArgumentException('TrainingCheckoutInvalidParticipants');
        }
        
        foreach ($contactIds as $contactId) {
            if (!is_int($contactId) || $contactId < 1) {
                throw new InvalidArgumentException('TrainingCheckoutInvalidContactId');
            }
        }
        
        $key = $this->generateReservationKey();
        $holdId = $this->enrollmentService->reserve($sessionId, $contactIds, $key, $minutes);
        
        $this->logCheckoutEvent('seat_hold_created', array(
            'session_id' => $sessionId,
            'hold_id' => $holdId,
            'participants' => count($contactIds),
            'expires_in_minutes' => $minutes
        ));
        
        return array(
            'hold_id' => $holdId,
            'reservation_key' => $key,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($minutes * 60))
        );
    }

    private function generateReservationKey(): string
    {
        return hash('sha256', uniqid('', true).mt_rand());
    }

    /**
     * Release a seat hold.
     */
    public function releaseSeatHold(int $sessionId, int $holdId, string $reason = 'user_cancelled'): void
    {
        $this->store->access->requireDomain('checkout', 'write');
        $this->enrollmentService->releaseReservation($sessionId, $holdId, $reason);
        
        $this->logCheckoutEvent('seat_hold_released', array(
            'session_id' => $sessionId,
            'hold_id' => $holdId,
            'reason' => $reason
        ));
    }

    // ========================================================================
    // CHECKOUT SESSION MANAGEMENT
    // ========================================================================

    /**
     * Create a checkout session with participants and pricing.
     */
    public function createCheckoutSession(
        int $sessionId,
        array $participantData,
        string $reservationKey,
        int $holdId
    ): array {
        $this->store->access->requireDomain('checkout', 'write');
        $this->store->access->requireContactRead();
        
        $session = $this->store->session($sessionId);
        $this->validateParticipants($participantData);
        
        // Verify seat hold exists and is valid
        $holds = $this->enrollmentService->reservations($sessionId);
        $validHold = null;
        foreach ($holds as $hold) {
            if ((int) $hold->rowid === $holdId && $hold->request_key === $reservationKey) {
                $validHold = $hold;
                break;
            }
        }
        if (!$validHold || $validHold->status !== 'active') {
            throw new RuntimeException('TrainingCheckoutInvalidSeatHold');
        }
        
        // Get catalog price for the session
        $priceInfo = $this->priceService->getCatalogPrice($sessionId);
        $totalAmount = $this->calculateTotalAmount($participantData, $priceInfo);
        
        // Create checkout session record
        $checkoutSessionId = $this->createCheckoutSessionRecord(
            $sessionId,
            $holdId,
            $totalAmount,
            $priceInfo
        );
        
        // Create checkout participants
        $this->createCheckoutParticipants($checkoutSessionId, $sessionId, $participantData);
        
        // Create Stripe PaymentIntent
        $paymentIntent = $this->createStripePaymentIntent(
            $checkoutSessionId,
            $totalAmount,
            $priceInfo['currency'],
            $sessionId,
            $holdId
        );
        
        // Update checkout session with PaymentIntent ID
        $this->updateCheckoutSessionPaymentIntent($checkoutSessionId, $paymentIntent['id']);
        
        $this->logCheckoutEvent('checkout_session_created', array(
            'checkout_session_id' => $checkoutSessionId,
            'session_id' => $sessionId,
            'hold_id' => $holdId,
            'payment_intent_id' => $paymentIntent['id'],
            'total_amount' => $totalAmount,
            'participants' => count($participantData)
        ));
        
        return array(
            'checkout_session_id' => $checkoutSessionId,
            'payment_intent_client_secret' => $paymentIntent['client_secret'],
            'payment_intent_id' => $paymentIntent['id'],
            'total_amount' => $totalAmount,
            'currency' => $priceInfo['currency'],
            'expires_at' => $validHold->expires_utc
        );
    }

    /**
     * Create the A8 native commercial checkout correlation.
     *
     * This path deliberately does not create a Stripe PaymentIntent, write
     * checkout_payment, process webhooks, allocate payment, or confirm
     * enrollment. It only creates the TMS checkout correlation and its native
     * Dolibarr invoice/payment URL.
     */
    public function createNativeCheckoutSession(int $sessionId, array $participantData, string $reservationKey, int $holdId): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        $this->store->access->requireContactRead();
        $session = $this->store->session($sessionId);
        $this->validateParticipants($participantData);
        $holds = $this->enrollmentService->reservations($sessionId);
        $validHold = null;
        foreach ($holds as $hold) {
            if ((int) $hold->rowid === $holdId && $hold->request_key === $reservationKey) {
                $validHold = $hold;
                break;
            }
        }
        if (!$validHold || $validHold->status !== 'active') {
            throw new RuntimeException('TrainingCheckoutInvalidSeatHold');
        }
        $priceInfo = $this->priceService->getCatalogPrice($sessionId);
        $totalAmount = $this->calculateTotalAmount($participantData, $priceInfo);
        $billingCustomerId = $this->resolveNativeBillingCustomer($participantData);
        $checkoutSessionId = $this->createCheckoutSessionRecord($sessionId, $holdId, $totalAmount, $priceInfo);
        $this->createCheckoutParticipants($checkoutSessionId, $sessionId, $participantData);
        $commercial = $this->commercialCheckout->createInvoice(array(
            'socid' => $billingCustomerId,
            'product_id' => (int) $session->fk_product,
            'price_ht' => trim((string) $priceInfo['price_ht'], "'"),
            'price_ttc' => trim((string) $priceInfo['price_ttc'], "'"),
            'tva_tx' => trim((string) $priceInfo['tva_tx'], "'"),
            'currency' => trim((string) $priceInfo['currency'], "'"),
            'qty' => count($participantData),
            'description' => (string) ($session->label ?? 'Training service')
        ));
        $this->storeNativeCommercialCorrelation($checkoutSessionId, $commercial);
        $this->logCheckoutEvent('native_checkout_session_created', array(
            'checkout_session_id' => $checkoutSessionId,
            'session_id' => $sessionId,
            'hold_id' => $holdId,
            'entity' => $commercial['entity'],
            'invoice_id' => $commercial['invoice_id'],
            'invoice_ref' => $commercial['invoice_ref']
        ));
        return array(
            'checkout_session_id' => $checkoutSessionId,
            'entity' => $commercial['entity'],
            'invoice_id' => $commercial['invoice_id'],
            'invoice_ref' => $commercial['invoice_ref'],
            'payment_url' => $commercial['payment_url'],
            'total_amount' => (float) $commercial['price_ttc'] * count($participantData),
            'currency' => $commercial['currency'],
            'expires_at' => $validHold->expires_utc
        );
    }

    /**
     * Prepare the native Dolibarr payment redirect for a correlated checkout.
     *
     * Only a checkout already correlated to a native invoice may enter this
     * transition. The persisted invoice reference is the correlation authority;
     * the payment URL is derived by the native commercial adapter.
     */
    public function prepareNativePaymentRedirect(int $checkoutSessionId): string
    {
        $this->store->access->requireDomain('checkout', 'write');

        $rows = $this->store->rows(
            'SELECT status, native_commercial_object_type, native_commercial_object_id, native_commercial_object_ref, entity '.
            'FROM '.$this->store->table('checkout_session').
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity()
        );
        if (!$rows) {
            throw new RuntimeException('TrainingCheckoutSessionNotFound');
        }

        $checkout = $rows[0];
        $status = (string) $checkout->status;
        if ($status !== 'commercial_created' && $status !== 'payment_redirected') {
            throw new RuntimeException('TrainingCheckoutInvalidTransition');
        }

        if ((string) $checkout->native_commercial_object_type !== 'invoice' ||
            (int) $checkout->native_commercial_object_id < 1 ||
            trim((string) $checkout->native_commercial_object_ref) === '') {
            throw new RuntimeException('TrainingCheckoutPaymentCorrelationInvalid');
        }

        $paymentUrl = $this->commercialCheckout->paymentUrlForInvoice(
            (int) $checkout->entity,
            (string) $checkout->native_commercial_object_ref
        );

        if ($status === 'commercial_created') {
            $this->store->query(
                'UPDATE '.$this->store->table('checkout_session').
                ' SET status='.$this->store->text('payment_redirected').
                ', changed_at='.$this->store->now().
                ', fk_user_modifier='.$this->store->access->actor().
                ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity().
                ' AND status='.$this->store->text('commercial_created')
            );
        }

        return $paymentUrl;
    }

    private function resolveNativeBillingCustomer(array $participantData): int
    {
        $customerId = 0;
        foreach ($participantData as $participant) {
            $contact = $this->store->contact((int) $participant['contact_id']);
            $socid = (int) ($contact->socid ?? 0);
            if ($socid < 1) {
                throw new RuntimeException('TrainingCheckoutBillingCustomerRequired');
            }
            if ($customerId === 0) {
                $customerId = $socid;
            } elseif ($customerId !== $socid) {
                throw new RuntimeException('TrainingCheckoutParticipantsDifferentCustomers');
            }
        }
        return $customerId;
    }

    private function storeNativeCommercialCorrelation(int $checkoutSessionId, array $commercial): void
    {
        $type = 'invoice';
        $id = (int) ($commercial['invoice_id'] ?? 0);
        $ref = trim((string) ($commercial['invoice_ref'] ?? ''));
        $entity = (int) ($commercial['entity'] ?? 0);
        if ($entity !== $this->store->access->entity() || $id < 1 || $ref === '') {
            throw new RuntimeException('TrainingCheckoutCommercialCorrelationInvalid');
        }
        $this->store->query(
            'UPDATE '.$this->store->table('checkout_session').
            ' SET native_commercial_object_type='.$this->store->text($type).
            ', native_commercial_object_id='.$id.
            ', native_commercial_object_ref='.$this->store->text($ref).
            ', status='.$this->store->text('commercial_created').
            ', changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity()
        );
    }

    private function validateParticipants(array $participantData): void
    {
        if (empty($participantData) || count($participantData) > 10) {
            throw new InvalidArgumentException('TrainingCheckoutInvalidParticipantCount');
        }
        
        foreach ($participantData as $participant) {
            if (empty($participant['contact_id']) || !is_int($participant['contact_id'])) {
                throw new InvalidArgumentException('TrainingCheckoutInvalidContactId');
            }
            if (empty($participant['first_name']) || strlen($participant['first_name']) > 128) {
                throw new InvalidArgumentException('TrainingCheckoutInvalidFirstName');
            }
            if (empty($participant['last_name']) || strlen($participant['last_name']) > 128) {
                throw new InvalidArgumentException('TrainingCheckoutInvalidLastName');
            }
            if (!empty($participant['email']) && !filter_var($participant['email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('TrainingCheckoutInvalidEmail');
            }
        }
    }

    private function calculateTotalAmount(array $participantData, array $priceInfo): int
    {
        $count = count($participantData);
        $unitAmount = (int) round(floatval(trim($priceInfo['price_ttc'], "'")) * 100); // Convert to cents
        return $unitAmount * $count;
    }

    private function createCheckoutSessionRecord(
        int $sessionId,
        int $holdId,
        int $totalAmount,
        array $priceInfo
    ): int {
        $entity = $this->store->access->entity();
        $actor = $this->store->access->actor();
        $now = $this->store->now();
        
        $currency = trim($priceInfo['currency'], "'");
        $tvaTx = trim($priceInfo['tva_tx'], "'");
        $priceTtc = trim($priceInfo['price_ttc'], "'");
        
        $metadata = json_encode(array(
            'session_id' => $sessionId,
            'hold_id' => $holdId,
            'unit_price_ttc' => $priceTtc,
            'currency' => $currency
        ), JSON_THROW_ON_ERROR);
        
        $this->store->query(
            'INSERT INTO '.$this->store->table('checkout_session').
            ' (entity, fk_session, session_ref, status, currency, total_amount_ht, total_amount_ttc, tva_tx, seat_hold_id, metadata_json, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
            .$entity.', '.$sessionId.', '.$this->store->text('CHK-'.uniqid()).', '.$this->store->text('pending').', '
            .$this->store->text($currency).', '
            .$this->store->text(number_format($totalAmount / 100 / (1 + ($tvaTx / 100)), 8, '.', '')).', '
            .$this->store->text(number_format($totalAmount / 100, 8, '.', '')).', '
            .$this->store->text($tvaTx).', '
            .$holdId.', '.$this->store->text($metadata).', '
            .$now.', '.$actor.', '.$now.', '.$actor.')'
        );
        
        return (int) $this->store->db->last_insert_id($this->store->table('checkout_session'));
    }

    private function createCheckoutParticipants(
        int $checkoutSessionId,
        int $sessionId,
        array $participantData
    ): void {
        $entity = $this->store->access->entity();
        $actor = $this->store->access->actor();
        $now = $this->store->now();
        
        foreach ($participantData as $participant) {
            $contactId = (int) $participant['contact_id'];
            
            // Get learner ID if exists
            $learnerRows = $this->store->rows(
                'SELECT rowid FROM '.$this->store->table('learner').
                ' WHERE entity='.$entity.' AND fk_socpeople='.$contactId
            );
            $learnerId = $learnerRows ? (int) $learnerRows[0]->rowid : null;
            
            $this->store->query(
                'INSERT INTO '.$this->store->table('checkout_participant').
                ' (entity, fk_checkout_session, fk_contact, fk_learner, first_name, last_name, email, phone, status, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
                .$entity.', '.$checkoutSessionId.', '.$contactId.', '
                .($learnerId ? $learnerId : 'NULL').', '
                .$this->store->text($participant['first_name']).', '
                .$this->store->text($participant['last_name']).', '
                .$this->store->text($participant['email'] ?? '').', '
                .$this->store->text($participant['phone'] ?? '').', '
                .$this->store->text('pending').', '
                .$now.', '.$actor.', '.$now.', '.$actor.')'
            );
        }
    }

    private function createStripePaymentIntent(
        int $checkoutSessionId,
        int $amount,
        string $currency,
        int $sessionId,
        int $holdId
    ): array {
        $metadata = array(
            'checkout_session_id' => $checkoutSessionId,
            'session_id' => $sessionId,
            'hold_id' => $holdId,
            'module' => 'training'
        );
        
        return $this->stripe->createPaymentIntent(array(
            'amount' => $amount,
            'currency' => strtolower($currency),
            'metadata' => $metadata,
            'payment_method_types' => array('card'),
            'capture_method' => 'automatic',
            'confirm' => false
        ));
    }

    private function updateCheckoutSessionPaymentIntent(int $checkoutSessionId, string $paymentIntentId): void
    {
        $this->store->query(
            'UPDATE '.$this->store->table('checkout_session').
            ' SET stripe_payment_intent_id='.$this->store->text($paymentIntentId).
            ', changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity()
        );
    }

    // ========================================================================
    // WEBHOOK PROCESSING
    // ========================================================================

    /**
     * Process a Stripe webhook event.
     */
    public function processWebhookEvent(string $payload, string $signature): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        try {
            // Verify signature
            if (!$this->stripe->verifyWebhookSignature($payload, $signature)) {
                $this->logWebhookEvent('signature_failed', null, $payload, $signature);
                throw new RuntimeException('TrainingCheckoutInvalidSignature');
            }
            
            // Parse event
            $event = $this->stripe->parseWebhookEvent($payload, $signature);
            
            // Store webhook event
            $webhookEventId = $this->storeWebhookEvent($event);
            
            // Handle specific event types
            $result = $this->handleWebhookEvent($event);
            
            // Mark as processed
            $this->markWebhookEventProcessed($webhookEventId);
            
            $this->logCheckoutEvent('webhook_processed', array(
                'event_id' => $event['id'],
                'event_type' => $event['type'],
                'result' => $result
            ));
            
            return array(
                'success' => true,
                'event_id' => $event['id'],
                'event_type' => $event['type'],
                'result' => $result
            );
        } catch (Throwable $e) {
            $this->logWebhookEvent('processing_failed', $e->getMessage(), $payload, $signature);
            throw $e;
        }
    }

    private function storeWebhookEvent(array $event): int
    {
        $entity = $this->store->access->entity();
        $actor = $this->store->access->actor();
        $now = $this->store->now();
        
        $objectType = $event['object']->object ?? 'unknown';
        $objectId = $event['object']->id ?? '';
        
        $this->store->query(
            'INSERT INTO '.$this->store->table('webhook_event').
            ' (entity, event_id, event_type, stripe_object_type, stripe_object_id, payload_json, processed, processing_attempts, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
            .$entity.', '.$this->store->text($event['id']).', '.$this->store->text($event['type']).', '
            .$this->store->text($objectType).', '.$this->store->text($objectId).', '
            .$this->store->text($this->store->db->escape($event['object'] ? json_encode($event['object']) : '{}')).', '
            .'0, 0, '.$now.', '.$actor.', '.$now.', '.$actor.')'
        );
        
        return (int) $this->store->db->last_insert_id($this->store->table('webhook_event'));
    }

    private function markWebhookEventProcessed(int $webhookEventId): void
    {
        $this->store->query(
            'UPDATE '.$this->store->table('webhook_event').
            ' SET processed=1, processing_attempts=processing_attempts+1, '.
            'changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$webhookEventId.' AND entity='.$this->store->access->entity()
        );
    }

    private function handleWebhookEvent(array $event): array
    {
        $object = $event['object'];
        
        switch ($event['type']) {
            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($object);
            
            case 'payment_intent.payment_failed':
                return $this->handlePaymentIntentFailed($object);
            
            case 'payment_intent.canceled':
                return $this->handlePaymentIntentCanceled($object);
            
            case 'charge.succeeded':
                return $this->handleChargeSucceeded($object);
            
            case 'charge.failed':
                return $this->handleChargeFailed($object);
            
            case 'customer.created':
            case 'customer.updated':
            case 'customer.deleted':
                // Customer events are informational for now
                return array('handled' => true, 'action' => 'none');
            
            default:
                $this->logCheckoutEvent('webhook_ignored', array(
                    'event_type' => $event['type']
                ));
                return array('handled' => false, 'reason' => 'unknown_event_type');
        }
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent): array
    {
        $metadata = $paymentIntent->metadata ?? array();
        $checkoutSessionId = $metadata['checkout_session_id'] ?? null;
        $sessionId = $metadata['session_id'] ?? null;
        $holdId = $metadata['hold_id'] ?? null;
        
        if (!$checkoutSessionId || !$sessionId || !$holdId) {
            throw new RuntimeException('TrainingCheckoutMissingMetadata');
        }
        
        // Confirm payment and create enrollments
        return $this->confirmPayment(
            (int) $checkoutSessionId,
            $paymentIntent->id,
            $paymentIntent->charges->data[0]->id ?? null,
            $paymentIntent->amount,
            $paymentIntent->currency
        );
    }

    private function handlePaymentIntentFailed(object $paymentIntent): array
    {
        $metadata = $paymentIntent->metadata ?? array();
        $checkoutSessionId = $metadata['checkout_session_id'] ?? null;
        
        if ($checkoutSessionId) {
            $this->updateCheckoutSessionStatus((int) $checkoutSessionId, 'payment_failed');
        }
        
        return array('handled' => true, 'action' => 'payment_failed');
    }

    private function handlePaymentIntentCanceled(object $paymentIntent): array
    {
        $metadata = $paymentIntent->metadata ?? array();
        $checkoutSessionId = $metadata['checkout_session_id'] ?? null;
        
        if ($checkoutSessionId) {
            $this->updateCheckoutSessionStatus((int) $checkoutSessionId, 'cancelled');
        }
        
        return array('handled' => true, 'action' => 'cancelled');
    }

    private function handleChargeSucceeded(object $charge): array
    {
        // Handle charge succeeded (redundant with payment_intent.succeeded)
        return array('handled' => true, 'action' => 'charge_succeeded');
    }

    private function handleChargeFailed(object $charge): array
    {
        // Handle charge failed
        return array('handled' => true, 'action' => 'charge_failed');
    }

    private function updateCheckoutSessionStatus(int $checkoutSessionId, string $status): void
    {
        $validStatuses = array('pending', 'payment_failed', 'cancelled', 'confirmed', 'completed');
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException('TrainingCheckoutInvalidStatus');
        }
        
        $this->store->query(
            'UPDATE '.$this->store->table('checkout_session').
            ' SET status='.$this->store->text($status).
            ', changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity()
        );
    }

    // ========================================================================
    // PAYMENT CONFIRMATION (Refactored into smaller methods)
    // ========================================================================

    /**
     * Confirm payment and create enrollments.
     * This is the main method called when a PaymentIntent succeeds.
     */
    public function confirmPayment(
        int $checkoutSessionId,
        string $paymentIntentId,
        ?string $chargeId,
        int $amount,
        string $currency
    ): array {
        $this->store->access->requireDomain('checkout', 'write');
        $this->store->access->requireContactRead();
        
        return $this->store->transaction(function () use (
            $checkoutSessionId, $paymentIntentId, $chargeId, $amount, $currency
        ) {
            // Step 1: Load checkout session and verify
            $checkoutSession = $this->loadCheckoutSession($checkoutSessionId);
            
            // Step 2: Load participants
            $participants = $this->loadCheckoutParticipants($checkoutSessionId);
            
            // Step 3: Verify payment amount matches
            $this->verifyPaymentAmount($checkoutSessionId, $amount, $currency);
            
            // Step 4: Update payment record
            $paymentId = $this->createPaymentRecord(
                $checkoutSessionId,
                $paymentIntentId,
                $chargeId,
                $amount,
                $currency
            );
            
            // Step 5: Update seat hold status
            $this->updateSeatHoldStatus($checkoutSession['seat_hold_id']);
            
            // Step 6: Create enrollments for each participant
            $enrollmentIds = $this->createEnrollmentsForParticipants(
                $checkoutSession['fk_session'],
                $participants,
                $paymentId
            );
            
            // Step 7: Update checkout session status
            $this->updateCheckoutSessionStatus($checkoutSessionId, 'confirmed');
            
            // Step 8: Create reconciliation record
            $this->createReconciliationRecord($paymentId, $checkoutSessionId, $amount, $currency);
            
            // Step 9: Queue confirmation emails
            $this->queueConfirmationEmails($checkoutSessionId, $enrollmentIds);
            
            // Step 10: Release seat hold (convert to confirmed)
            $this->releaseSeatHold(
                $checkoutSession['fk_session'],
                $checkoutSession['seat_hold_id'],
                'payment_confirmed'
            );
            
            $this->logCheckoutEvent('payment_confirmed', array(
                'checkout_session_id' => $checkoutSessionId,
                'payment_intent_id' => $paymentIntentId,
                'enrollment_ids' => $enrollmentIds
            ));
            
            return array(
                'success' => true,
                'checkout_session_id' => $checkoutSessionId,
                'enrollment_ids' => $enrollmentIds,
                'payment_id' => $paymentId
            );
        });
    }

    private function loadCheckoutSession(int $checkoutSessionId): array
    {
        $rows = $this->store->rows(
            'SELECT * FROM '.$this->store->table('checkout_session').
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity().' FOR UPDATE'
        );
        
        if (!$rows) {
            throw new RuntimeException('TrainingCheckoutSessionNotFound');
        }
        
        return (array) $rows[0];
    }

    private function loadCheckoutParticipants(int $checkoutSessionId): array
    {
        $rows = $this->store->rows(
            'SELECT * FROM '.$this->store->table('checkout_participant').
            ' WHERE entity='.$this->store->access->entity().
            ' AND fk_checkout_session='.$checkoutSessionId.' FOR UPDATE'
        );
        
        if (empty($rows)) {
            throw new RuntimeException('TrainingCheckoutNoParticipants');
        }
        
        return $rows;
    }

    private function verifyPaymentAmount(int $checkoutSessionId, int $amount, string $currency): void
    {
        $session = $this->loadCheckoutSession($checkoutSessionId);
        $expectedAmount = (int) round(floatval($session->total_amount_ttc) * 100);
        
        if ($amount !== $expectedAmount) {
            throw new RuntimeException('TrainingCheckoutAmountMismatch');
        }
        
        if (strtoupper($currency) !== strtoupper($session->currency)) {
            throw new RuntimeException('TrainingCheckoutCurrencyMismatch');
        }
    }

    private function createPaymentRecord(
        int $checkoutSessionId,
        string $paymentIntentId,
        ?string $chargeId,
        int $amount,
        string $currency
    ): int {
        $entity = $this->store->access->entity();
        $actor = $this->store->access->actor();
        $now = $this->store->now();
        $amountTtc = number_format($amount / 100, 8, '.', '');
        $amountHt = number_format($amount / 100 / (1 + (floatval($this->loadCheckoutSession($checkoutSessionId)['tva_tx']) / 100)), 8, '.', '');
        
        $this->store->query(
            'INSERT INTO '.$this->store->table('checkout_payment').
            ' (entity, fk_checkout_session, amount_ht, amount_ttc, currency, '.
            'stripe_payment_intent_id, stripe_charge_id, payment_status, payment_method_type, '.
            'datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
            .$entity.', '.$checkoutSessionId.', '
            .$this->store->text($amountHt).', '.$this->store->text($amountTtc).', '
            .$this->store->text(strtoupper($currency)).', '
            .$this->store->text($paymentIntentId).', '
            .($chargeId ? $this->store->text($chargeId) : 'NULL').', '
            .$this->store->text('succeeded').', NULL, '
            .$now.', '.$actor.', '.$now.', '.$actor.')'
        );
        
        return (int) $this->store->db->last_insert_id($this->store->table('checkout_payment'));
    }

    private function updateSeatHoldStatus(int $holdId): void
    {
        // Seat hold status is updated via releaseReservation in EnrollmentService
        // This method is kept for future custom status updates
    }

    private function createEnrollmentsForParticipants(
        int $sessionId,
        array $participants,
        int $paymentId
    ): array {
        $enrollmentIds = array();
        $session = $this->store->session($sessionId);
        
        foreach ($participants as $participant) {
            $contactId = (int) $participant->fk_contact;
            
            // Get or create learner
            $learnerRows = $this->store->rows(
                'SELECT rowid FROM '.$this->store->table('learner').
                ' WHERE entity='.$this->store->access->entity().' AND fk_socpeople='.$contactId.' FOR UPDATE'
            );
            
            if ($learnerRows) {
                $learnerId = (int) $learnerRows[0]->rowid;
            } else {
                $this->store->query(
                    'INSERT INTO '.$this->store->table('learner').
                    ' (entity, fk_socpeople, datec, fk_user_author) VALUES ('
                    .$this->store->access->entity().', '.$contactId.', '
                    .$this->store->now().', '.$this->store->access->actor().')'
                );
                $learnerId = (int) $this->store->db->last_insert_id($this->store->table('learner'));
            }
            
            // Create enrollment
            $enrollmentId = $this->enrollmentService->confirm($sessionId, $contactId);
            
            // Update participant with enrollment ID and contact info
            $this->updateCheckoutParticipant(
                (int) $participant->rowid,
                $enrollmentId,
                $learnerId,
                $contactId
            );
            
            // Link payment to billing if applicable
            $this->linkPaymentToBilling($paymentId, $enrollmentId);
            
            $enrollmentIds[] = $enrollmentId;
        }
        
        return $enrollmentIds;
    }

    private function updateCheckoutParticipant(
        int $participantId,
        int $enrollmentId,
        int $learnerId,
        int $contactId
    ): void {
        $this->store->query(
            'UPDATE '.$this->store->table('checkout_participant').
            ' SET fk_enrollment='.$enrollmentId.
            ', fk_learner='.$learnerId.
            ', fk_contact='.$contactId.
            ', status='.$this->store->text('confirmed').
            ', changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$participantId.' AND entity='.$this->store->access->entity()
        );
    }

    private function linkPaymentToBilling(int $paymentId, int $enrollmentId): void
    {
        // This is a placeholder for linking checkout payments to billing lines
        // Full implementation will be in B5 (B2B Invoice Flow)
    }

    private function createReconciliationRecord(
        int $paymentId,
        int $checkoutSessionId,
        int $amount,
        string $currency
    ): int {
        $entity = $this->store->access->entity();
        $actor = $this->store->access->actor();
        $now = $this->store->now();
        $amountDecimal = number_format($amount / 100, 8, '.', '');
        
        $this->store->query(
            'INSERT INTO '.$this->store->table('reconciliation').
            ' (entity, fk_checkout_payment, provider, provider_ref, amount, currency, '.
            'reconciliation_date, status, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
            .$entity.', '.$paymentId.', '
            .$this->store->text('stripe').', '
            .$this->store->text('pi_'.$paymentId).', '
            .$this->store->text($amountDecimal).', '
            .$this->store->text(strtoupper($currency)).', '
            .$this->store->text($now).', '
            .$this->store->text('pending').', '
            .$now.', '.$actor.', '.$now.', '.$actor.')'
        );
        
        return (int) $this->store->db->last_insert_id($this->store->table('reconciliation'));
    }

    private function queueConfirmationEmails(int $checkoutSessionId, array $enrollmentIds): void
    {
        $checkoutSession = $this->loadCheckoutSession($checkoutSessionId);
        
        foreach ($enrollmentIds as $enrollmentId) {
            try {
                $enrollment = $this->getEnrollmentInfo($enrollmentId);
                $contactId = $enrollment['fk_socpeople'];
                
                // Get contact email
                $contact = $this->store->contact($contactId);
                $email = $contact->email ?? '';
                
                if (empty($email)) {
                    continue;
                }
                
                // Get participant name
                $firstName = $contact->firstname ?? '';
                $lastName = $contact->lastname ?? '';
                
                // Generate email subject and body
                $subject = $this->getEmailSubject($checkoutSessionId);
                $bodyText = $this->getEmailBody($checkoutSessionId, $enrollmentId);
                
                // Queue email via OutboxService
                $this->outboxService->queueMessage(
                    'enrollment',
                    $enrollmentId,
                    'confirmation',
                    'contact',
                    $contactId,
                    $email,
                    $subject,
                    $bodyText,
                    null,
                    array(
                        'checkout_session_id' => $checkoutSessionId,
                        'participant_name' => $firstName.' '.$lastName
                    )
                );
            } catch (Throwable $e) {
                $this->logCheckoutEvent('email_queue_failed', array(
                    'enrollment_id' => $enrollmentId,
                    'error' => $e->getMessage()
                ));
            }
        }
    }

    private function getEnrollmentInfo(int $enrollmentId): array
    {
        $rows = $this->store->rows(
            'SELECT e.*, l.fk_socpeople FROM '.$this->store->table('enrollment').' e '
            .'JOIN '.$this->store->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity '
            .'WHERE e.rowid='.$enrollmentId.' AND e.entity='.$this->store->access->entity()
        );
        
        if (!$rows) {
            throw new RuntimeException('TrainingEnrollmentNotFound');
        }
        
        return (array) $rows[0];
    }

    private function getEmailSubject(int $checkoutSessionId): string
    {
        // Use language file for email subject
        // Fallback to default if language file not available
        $session = $this->loadCheckoutSession($checkoutSessionId);
        return 'Training Course Confirmation - Session '.$session['session_ref'];
    }

    private function getEmailBody(int $checkoutSessionId, int $enrollmentId): string
    {
        // Use language file for email body
        // Fallback to default template
        $session = $this->loadCheckoutSession($checkoutSessionId);
        $enrollment = $this->getEnrollmentInfo($enrollmentId);
        
        $body = "Thank you for your booking!\n\n";
        $body .= "Course: Session {$session['session_ref']}\n";
        $body .= "Enrollment ID: {$enrollmentId}\n";
        $body .= "Status: Confirmed\n\n";
        $body .= "You will receive further details shortly.\n";
        
        return $body;
    }

    // ========================================================================
    // RECONCILIATION
    // ========================================================================

    /**
     * Run reconciliation job for pending payments.
     */
    public function runReconciliation(): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        $pendingPayments = $this->getPendingPayments();
        $reconciled = array();
        $failed = array();
        
        foreach ($pendingPayments as $payment) {
            try {
                $stripePayment = $this->stripe->retrievePaymentIntent($payment->stripe_payment_intent_id);
                
                if ($stripePayment['status'] === 'succeeded') {
                    $this->updateReconciliationStatus(
                        $payment->rowid,
                        'completed',
                        $stripePayment['id']
                    );
                    $reconciled[] = $payment->rowid;
                } elseif ($stripePayment['status'] === 'failed') {
                    $this->updateReconciliationStatus(
                        $payment->rowid,
                        'failed',
                        $stripePayment['id']
                    );
                    $failed[] = $payment->rowid;
                }
            } catch (Throwable $e) {
                $failed[] = $payment->rowid;
                $this->logCheckoutEvent('reconciliation_failed', array(
                    'payment_id' => $payment->rowid,
                    'error' => $e->getMessage()
                ));
            }
        }
        
        return array(
            'reconciled' => $reconciled,
            'failed' => $failed
        );
    }

    private function getPendingPayments(): array
    {
        return $this->store->rows(
            'SELECT * FROM '.$this->store->table('checkout_payment').
            ' WHERE entity='.$this->store->access->entity().
            " AND payment_status='succeeded'"
        );
    }

    private function updateReconciliationStatus(int $paymentId, string $status, string $providerRef): void
    {
        $this->store->query(
            'UPDATE '.$this->store->table('reconciliation').
            ' SET status='.$this->store->text($status).
            ', provider_ref='.$this->store->text($providerRef).
            ', changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE fk_checkout_payment='.$paymentId.' AND entity='.$this->store->access->entity()
        );
    }

    // ========================================================================
    // UTILITY METHODS
    // ========================================================================

    /**
     * Log a checkout event for audit purposes.
     */
    private function logCheckoutEvent(string $action, array $metadata): void
    {
        $this->store->audit('checkout', 0, $action, $metadata);
    }

    /**
     * Log a webhook event for debugging.
     */
    private function logWebhookEvent(string $action, ?string $error, string $payload, string $signature): void
    {
        $metadata = array(
            'action' => $action,
            'error' => $error,
            'signature_present' => !empty($signature)
        );
        
        $this->store->audit('webhook', 0, $action, $metadata);
    }

    /**
     * Get checkout session by PaymentIntent ID.
     */
    public function getCheckoutSessionByPaymentIntent(string $paymentIntentId): ?array
    {
        $rows = $this->store->rows(
            'SELECT cs.* FROM '.$this->store->table('checkout_session').' cs '
            .'JOIN '.$this->store->table('checkout_payment').' cp ON cp.fk_checkout_session=cs.rowid AND cp.entity=cs.entity '
            .'WHERE cs.entity='.$this->store->access->entity().
            ' AND cp.stripe_payment_intent_id='.$this->store->text($paymentIntentId)
        );
        
        return $rows ? (array) $rows[0] : null;
    }

    /**
     * Get checkout session status.
     */
    public function getCheckoutSessionStatus(int $checkoutSessionId): string
    {
        $rows = $this->store->rows(
            'SELECT status FROM '.$this->store->table('checkout_session').
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity()
        );
        
        return $rows ? $rows[0]->status : 'not_found';
    }

    /**
     * Get participant list for a checkout session.
     */
    public function getCheckoutParticipants(int $checkoutSessionId): array
    {
        return $this->store->rows(
            'SELECT * FROM '.$this->store->table('checkout_participant').
            ' WHERE entity='.$this->store->access->entity().
            ' AND fk_checkout_session='.$checkoutSessionId.' ORDER BY rowid'
        );
    }
}
