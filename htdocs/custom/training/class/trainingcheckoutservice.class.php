<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingcommercialcheckoutadapter.class.php';
require_once __DIR__.'/trainingenrollmentservice.class.php';
require_once __DIR__.'/trainingpriceservice.class.php';
require_once __DIR__.'/trainingschedulingservice.class.php';
require_once __DIR__.'/trainingattendanceservice.class.php';
require_once __DIR__.'/trainingoutboxservice.class.php';

/**
 * Authoritative checkout flow service for Training module.
 * Native Dolibarr commercial documents and Paiement records are the only
 * payment authority. TMS owns checkout correlation and allocation only.
 */
final class TrainingCheckoutService
{
    private TrainingStore $store;
    private TrainingCommercialCheckoutAdapter $commercialCheckout;
    private TrainingEnrollmentService $enrollmentService;
    private TrainingPriceService $priceService;
    private TrainingSchedulingService $schedulingService;
    private TrainingOutboxService $outboxService;

    public function __construct(
        TrainingStore $store,
        ?TrainingCommercialCheckoutAdapter $commercialCheckout = null
    ) {
        $this->store = $store;
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

    /**
     * Observe native Dolibarr Paiement records for the correlated invoice.
     *
     * Payment URL/redirect is not payment proof. This method reads Dolibarr's
     * persisted Paiement + PaiementFacture records and advances only when the
     * cumulative native payment amount covers the frozen checkout obligation.
     * Partial payment remains observable but does not advance the checkout.
     * Overpayment is accepted as paid; the excess remains a native accounting
     * concern and is deliberately not allocated by this method.
     */
    public function observeNativePayment(int $checkoutSessionId): array
    {
        $this->store->access->requireDomain('checkout', 'write');

        $rows = $this->store->rows(
            'SELECT rowid, status, currency, total_amount_ttc, native_commercial_object_type, '.
            'native_commercial_object_id, native_commercial_object_ref, native_payment_id, '.
            'native_payment_ref, native_payment_amount '.
            'FROM '.$this->store->table('checkout_session').
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity()
        );
        if (!$rows) {
            throw new RuntimeException('TrainingCheckoutSessionNotFound');
        }

        $checkout = $rows[0];
        $status = (string) $checkout->status;
        if ($status === 'paiement_present') {
            $storedPaidAmount = (float) $checkout->native_payment_amount;
            $storedRequiredAmount = (float) $checkout->total_amount_ttc;
            return array(
                'checkout_session_id' => $checkoutSessionId,
                'status' => 'paiement_present',
                'payment_status' => $storedPaidAmount > $storedRequiredAmount + 0.0000001 ? 'overpaid' : 'paid',
                'paid_amount' => (float) $checkout->native_payment_amount,
                'required_amount' => (float) $checkout->total_amount_ttc,
                'payment_id' => (int) $checkout->native_payment_id,
                'payment_ref' => (string) $checkout->native_payment_ref,
            );
        }
        if ($status !== 'payment_redirected') {
            throw new RuntimeException('TrainingCheckoutInvalidTransition');
        }

        if ((string) $checkout->native_commercial_object_type !== 'invoice' ||
            (int) $checkout->native_commercial_object_id < 1 ||
            trim((string) $checkout->native_commercial_object_ref) === '') {
            throw new RuntimeException('TrainingCheckoutPaymentCorrelationInvalid');
        }

        $invoiceRows = $this->store->rows(
            'SELECT rowid, entity, ref, total_ttc, multicurrency_code '.
            'FROM '.$this->store->db->prefix().'facture'.
            ' WHERE rowid='.(int) $checkout->native_commercial_object_id.
            ' AND entity='.$this->store->access->entity()
        );
        if (!$invoiceRows) {
            throw new RuntimeException('TrainingCheckoutNativeInvoiceNotFound');
        }
        $invoice = $invoiceRows[0];
        if ((string) $invoice->ref !== trim((string) $checkout->native_commercial_object_ref)) {
            throw new RuntimeException('TrainingCheckoutPaymentCorrelationInvalid');
        }

        $invoiceCurrency = strtoupper(trim((string) ($invoice->multicurrency_code ?? '')));
        $checkoutCurrency = strtoupper(trim((string) $checkout->currency));
        if ($invoiceCurrency !== '' && $invoiceCurrency !== $checkoutCurrency) {
            throw new RuntimeException('TrainingCheckoutCurrencyMismatch');
        }

        $required = (float) $checkout->total_amount_ttc;
        if (abs((float) $invoice->total_ttc - $required) > 0.0000001) {
            throw new RuntimeException('TrainingCheckoutNativeInvoiceAmountMismatch');
        }

        $paymentRows = $this->store->rows(
            'SELECT p.rowid AS payment_id, p.ref AS payment_ref, p.datep, pf.amount AS payment_amount '.
            'FROM '.$this->store->db->prefix().'paiement'.' p '.
            'INNER JOIN '.$this->store->db->prefix().'paiement_facture'.' pf ON pf.fk_paiement=p.rowid '.
            'WHERE p.entity='.$this->store->access->entity().
            ' AND pf.fk_facture='.(int) $invoice->rowid.
            ' ORDER BY p.rowid DESC'
        );

        $paidAmount = 0.0;
        foreach ($paymentRows as $payment) {
            $paidAmount += (float) $payment->payment_amount;
        }

        $latestPayment = $paymentRows ? $paymentRows[0] : null;
        $result = array(
            'checkout_session_id' => $checkoutSessionId,
            'status' => 'payment_redirected',
            'payment_status' => $paidAmount <= 0.0 ? 'pending' : ($paidAmount + 0.0000001 < $required ? 'partial' : 'paid'),
            'paid_amount' => $paidAmount,
            'required_amount' => $required,
            'payment_id' => $latestPayment ? (int) $latestPayment->payment_id : 0,
            'payment_ref' => $latestPayment ? (string) $latestPayment->payment_ref : '',
        );

        if ($paidAmount + 0.0000001 < $required) {
            return $result;
        }

        if (!$latestPayment || (int) $latestPayment->payment_id < 1) {
            throw new RuntimeException('TrainingCheckoutNativePaymentMissing');
        }

        $result['status'] = 'paiement_present';
        $result['payment_status'] = $paidAmount > $required + 0.0000001 ? 'overpaid' : 'paid';

        $this->store->query(
            'UPDATE '.$this->store->table('checkout_session').
            ' SET status='.$this->store->text('paiement_present').
            ', native_payment_id='.(int) $latestPayment->payment_id.
            ', native_payment_ref='.$this->store->text((string) $latestPayment->payment_ref).
            ', native_payment_amount='.$this->store->text(number_format($paidAmount, 8, '.', '')).
            ', changed_at='.$this->store->now().
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity().
            ' AND status='.$this->store->text('payment_redirected')
        );

        return $result;
    }

    /**
     * Finalize native checkout from persisted Dolibarr Paiement records.
     * The provider redirect is never treated as proof of payment.
     */
    public function finalizeNativeCheckout(int $checkoutSessionId): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        $this->store->access->requireContactRead();
        $observed = $this->observeNativePayment($checkoutSessionId);
        if ($observed['payment_status'] !== 'paid' && $observed['payment_status'] !== 'overpaid') return $observed + array('success'=>false);
        return $this->store->transaction(function () use ($checkoutSessionId) {
            $rows=$this->store->rows('SELECT * FROM '.$this->store->table('checkout_session').' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity().' FOR UPDATE');
            if (!$rows) throw new RuntimeException('TrainingCheckoutSessionNotFound');
            $checkout=$rows[0];
            if ((string)$checkout->status==='completed') return array('success'=>true,'checkout_session_id'=>$checkoutSessionId,'status'=>'completed');
            $participants=$this->store->rows('SELECT * FROM '.$this->store->table('checkout_participant').' WHERE entity='.$this->store->access->entity().' AND fk_checkout_session='.$checkoutSessionId.' ORDER BY rowid FOR UPDATE');
            if (!$participants) throw new RuntimeException('TrainingCheckoutNoParticipants');
            $enrollmentIds=array();
            foreach($participants as $p){
                $id=(int)$p->fk_enrollment;
                if($id<1)$id=$this->enrollmentService->confirm((int)$checkout->fk_session,(int)$p->fk_contact);
                $enrollmentIds[]=$id;
                $this->store->query('UPDATE '.$this->store->table('checkout_participant').' SET fk_enrollment='.$id.', status='.$this->store->text('confirmed').', changed_at='.$this->store->now().' WHERE rowid='.(int)$p->rowid.' AND entity='.$this->store->access->entity());
            }
            $payments=$this->store->rows('SELECT DISTINCT p.rowid AS payment_id FROM '.$this->store->db->prefix().'paiement p INNER JOIN '.$this->store->db->prefix().'paiement_facture pf ON pf.fk_paiement=p.rowid WHERE p.entity='.$this->store->access->entity().' AND pf.fk_facture='.(int)$checkout->native_commercial_object_id.' ORDER BY p.rowid');
            foreach($payments as $payment){
                $remaining=$this->nativePaymentUnallocatedAmount((int)$payment->payment_id); if($remaining<=0.0000001) continue;
                $map=array();
                foreach($enrollmentIds as $eid){$balance=$this->paymentBalanceForEnrollment((int)$checkout->fk_session,$eid);if($balance>0.0000001){$amount=min($remaining,$balance);$map[$eid]=number_format($amount,8,'.','');$remaining-=$amount;}if($remaining<=0.0000001)break;}
                if($map)$this->paymentService()->allocatePayment((int)$checkout->fk_session,(int)$payment->payment_id,$map,'Native Dolibarr checkout payment');
            }
            $this->store->query('UPDATE '.$this->store->table('checkout_session').' SET status='.$this->store->text('completed').', changed_at='.$this->store->now().', fk_user_modifier='.$this->store->access->actor().' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity());
            $this->enrollmentService->releaseReservation((int)$checkout->fk_session,(int)$checkout->fk_seat_hold,'native_payment_confirmed');
            return array('success'=>true,'checkout_session_id'=>$checkoutSessionId,'status'=>'completed','enrollment_ids'=>$enrollmentIds);
        });
    }

    private function nativePaymentUnallocatedAmount(int $paymentId): float
    {
        $p=$this->store->rows('SELECT amount FROM '.$this->store->db->prefix().'paiement WHERE rowid='.$paymentId.' AND entity='.$this->store->access->entity());
        if(!$p)throw new RuntimeException('TrainingCheckoutNativePaymentMissing');
        $a=$this->store->rows('SELECT COALESCE(SUM(amount),0) AS total FROM '.$this->store->table('payment_allocation').' WHERE entity='.$this->store->access->entity().' AND fk_paiement='.$paymentId);
        return max(0.0,(float)$p[0]->amount-(float)($a[0]->total??0));
    }

    private function paymentBalanceForEnrollment(int $sessionId,int $enrollmentId): float
    {
        $b=$this->paymentService()->getEnrollmentBalance($sessionId,$enrollmentId); return max(0.0,(float)($b['remaining']??0));
    }

    private function paymentService(): TrainingPaymentService
    {
        require_once __DIR__.'/trainingpaymentservice.class.php'; return new TrainingPaymentService($this->store);
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
