<?php
/**
 * Complete the checkout created through native_http.php using real Dolibarr
 * Paiement records, then run the same checkout finalizer used by the durable
 * payment worker. Disposable CI only; the module itself is loaded from ZIP.
 */
if (getenv('CI') !== 'true') {
    throw new RuntimeException('Disposable CI environment required');
}
$sessionId = (int) ($argv[1] ?? 0);
if ($sessionId < 1) {
    throw new InvalidArgumentException('Checkout session fixture required');
}
require __DIR__.'/../.ci/dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/lib/ui.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingcheckoutservice.class.php';

global $db, $conf, $user;
if ($user->fetch('', 'admin') <= 0 || !$user->admin) {
    throw new RuntimeException('Native CI administrator unavailable');
}
$user->getrights();
$access = trainingAccess();
$store = new TrainingStore($db, $access);
$rows = $store->rows(
    'SELECT rowid, status, total_amount_ttc, native_commercial_object_id, native_commercial_object_ref '.
    'FROM '.$store->table('checkout_session').
    ' WHERE entity='.$access->entity().' AND fk_session='.$sessionId.
    ' ORDER BY rowid DESC'
);
if (!$rows) {
    throw new RuntimeException('HTTP-created checkout session was not persisted');
}
$checkout = $rows[0];
$checkoutId = (int) $checkout->rowid;
$invoiceId = (int) $checkout->native_commercial_object_id;
if ((string) $checkout->status !== 'payment_redirected' || $invoiceId < 1 || trim((string) $checkout->native_commercial_object_ref) === '') {
    throw new RuntimeException('HTTP checkout did not persist a correlated native invoice and payment redirect');
}
$invoiceRows = $store->rows(
    'SELECT rowid, paye, total_ttc FROM '.$db->prefix().'facture'.
    ' WHERE rowid='.$invoiceId.' AND entity='.$access->entity()
);
if (!$invoiceRows || (int) $invoiceRows[0]->paye !== 0) {
    throw new RuntimeException('HTTP checkout invoice is missing or unexpectedly already paid');
}
$methods = $store->rows('SELECT id FROM '.$db->prefix().'c_paiement WHERE active=1 ORDER BY id LIMIT 1');
if (!$methods) {
    throw new RuntimeException('No active native Dolibarr payment method');
}
$payment = new Paiement($db);
$payment->datepaye = dol_now();
$payment->date = dol_now();
$payment->amounts = array($invoiceId => (float) $checkout->total_amount_ttc);
$payment->paiementid = (int) $methods[0]->id;
$payment->note = 'A9 native HTTP checkout CI';
$paymentId = $payment->create($user, 1, null);
if ($paymentId <= 0) {
    throw new RuntimeException('Native Paiement creation failed: '.$payment->error);
}
$invoiceRows = $store->rows(
    'SELECT paye FROM '.$db->prefix().'facture WHERE rowid='.$invoiceId.' AND entity='.$access->entity()
);
if (!$invoiceRows || (int) $invoiceRows[0]->paye !== 1) {
    throw new RuntimeException('Dolibarr did not mark the HTTP checkout invoice paid');
}
$checkoutService = new TrainingCheckoutService($store);
$observed = $checkoutService->observeNativePayment($checkoutId);
if (($observed['payment_status'] ?? '') !== 'paid') {
    throw new RuntimeException('HTTP checkout did not observe full native payment: '.json_encode($observed));
}
$finalized = $checkoutService->finalizeNativeCheckout($checkoutId);
if (empty($finalized['success']) || ($finalized['status'] ?? '') !== 'completed') {
    throw new RuntimeException('HTTP checkout finalization failed: '.json_encode($finalized));
}
if (count($finalized['enrollment_ids'] ?? array()) !== 1) {
    throw new RuntimeException('HTTP checkout did not confirm exactly one enrollment');
}
$enrollmentId = (int) $finalized['enrollment_ids'][0];
$enrollmentRows = $store->rows(
    'SELECT status FROM '.$store->table('enrollment').
    ' WHERE entity='.$access->entity().' AND rowid='.$enrollmentId.' AND fk_session='.$sessionId
);
if (!$enrollmentRows || (string) $enrollmentRows[0]->status !== 'confirmed') {
    throw new RuntimeException('HTTP checkout enrollment is not confirmed');
}
$resultPath = __DIR__.'/../.ci/native-http-checkout-result.json';
file_put_contents($resultPath, json_encode(array(
    'checkout_session_id' => $checkoutId,
    'session_id' => $sessionId,
    'invoice_id' => $invoiceId,
    'invoice_ref' => (string) $checkout->native_commercial_object_ref,
    'payment_id' => (int) $paymentId,
    'enrollment_id' => $enrollmentId,
    'status' => (string) $enrollmentRows[0]->status,
), JSON_THROW_ON_ERROR));
echo 'OK: HTTP-created checkout -> native Paiement -> confirmed enrollment'.PHP_EOL;
