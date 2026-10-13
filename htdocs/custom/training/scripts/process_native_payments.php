<?php
/**
 * Process durable native-payment checkout work.
 *
 * Configure TRAINING_NATIVE_PAYMENT_WORKFLOW_USER_ID to the ID of a dedicated,
 * active internal Dolibarr user with only the rights required by the Training
 * checkout finalizer. Invoke from the server's trusted cron environment:
 *
 * php htdocs/custom/training/scripts/process_native_payments.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__, 3).'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dirname(__DIR__).'/class/trainingaccess.class.php';
require_once dirname(__DIR__).'/class/trainingstore.class.php';
require_once dirname(__DIR__).'/class/trainingcheckoutservice.class.php';

global $db, $conf, $user;

$workflowUserId = (int) ($conf->global->TRAINING_NATIVE_PAYMENT_WORKFLOW_USER_ID ?? 0);
if ($workflowUserId < 1) {
    fwrite(STDERR, "TRAINING_NATIVE_PAYMENT_WORKFLOW_USER_ID is not configured.\n");
    exit(2);
}

$workflowUser = new User($db);
if ($workflowUser->fetch($workflowUserId) <= 0 || (int) $workflowUser->statut !== 1 || !empty($workflowUser->socid)) {
    fwrite(STDERR, "Configured native-payment workflow user is not an active internal user.\n");
    exit(2);
}
$workflowUser->getrights('', 1);
$user = $workflowUser;

$entity = (int) $conf->entity;
$queueTable = $db->prefix().'training_native_payment_queue';
$now = $db->idate(dol_now());
$escapedNow = $db->escape($now);

// Reconcile missed trigger writes. Native invoice/payment recording must not
// be rolled back just because a TMS queue insert failed inside the trigger.
$checkoutTable = $db->prefix().'training_checkout_session';
$invoiceTable = $db->prefix().'facture';
$reconcileSql = 'INSERT INTO '.$queueTable;
$reconcileSql .= ' (entity, fk_checkout_session, fk_invoice, status, attempts, datec, changed_at)';
$reconcileSql .= " SELECT c.entity, c.rowid, c.native_commercial_object_id, 'pending', 0, '".$escapedNow."', '".$escapedNow."'";
$reconcileSql .= ' FROM '.$checkoutTable.' c INNER JOIN '.$invoiceTable.' f';
$reconcileSql .= ' ON f.rowid = c.native_commercial_object_id AND f.entity = c.entity';
$reconcileSql .= ' WHERE c.entity = '.$entity." AND c.native_commercial_object_type = 'invoice'";
$reconcileSql .= " AND c.status IN ('payment_redirected', 'paiement_present') AND f.paye = 1";
$reconcileSql .= ' AND NOT EXISTS (SELECT 1 FROM '.$queueTable.' q';
$reconcileSql .= ' WHERE q.entity = c.entity AND q.fk_checkout_session = c.rowid)';
if (!$db->query($reconcileSql)) {
    fwrite(STDERR, "Could not reconcile paid native invoices into the checkout queue.\\n");
    exit(3);
}

// Recover work abandoned by a worker crash. A claimed item is only eligible
// after its lease has expired, so two live workers do not intentionally race.
$recoverExhaustedSql = 'UPDATE '.$queueTable;
$recoverExhaustedSql .= " SET status = 'failed', last_error = 'Worker lease expired after retry limit', changed_at = '".$escapedNow."'";
$recoverExhaustedSql .= " WHERE entity = ".$entity." AND status = 'processing' AND attempts >= 5";
$recoverExhaustedSql .= ' AND changed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)';
if (!$db->query($recoverExhaustedSql)) {
    fwrite(STDERR, "Could not close exhausted native-payment queue items.\n");
    exit(3);
}

$recoverSql = 'UPDATE '.$queueTable;
$recoverSql .= " SET status = 'pending', changed_at = '".$escapedNow."'";
$recoverSql .= " WHERE entity = ".$entity." AND status = 'processing' AND attempts < 5";
$recoverSql .= ' AND changed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)';
if (!$db->query($recoverSql)) {
    fwrite(STDERR, "Could not recover abandoned native-payment queue items.\n");
    exit(3);
}

$rows = array();
$sql = 'SELECT rowid, fk_checkout_session FROM '.$queueTable;
$sql .= ' WHERE entity = '.$entity." AND status IN ('pending', 'failed')";
$sql .= ' AND attempts < 5 ORDER BY rowid ASC LIMIT 50';
$result = $db->query($sql);
if (!$result) {
    fwrite(STDERR, "Could not read native-payment queue.\n");
    exit(3);
}
while ($row = $db->fetch_object($result)) {
    $rows[] = $row;
}

$access = new TrainingAccess(
    $workflowUser,
    $entity,
    getEntity('product'),
    getEntity('contact'),
    getEntity('societe'),
    getEntity('user')
);
$store = new TrainingStore($db, $access);
$checkoutService = new TrainingCheckoutService($store);
$failed = 0;
$processed = 0;

foreach ($rows as $row) {
    $queueId = (int) $row->rowid;
    $checkoutId = (int) $row->fk_checkout_session;
    $claim = 'UPDATE '.$queueTable;
    $claim .= " SET status = 'processing', attempts = attempts + 1, changed_at = '".$escapedNow."'";
    $claim .= ' WHERE rowid = '.$queueId.' AND entity = '.$entity;
    $claim .= " AND status IN ('pending', 'failed') AND attempts < 5";
    if (!$db->query($claim)) {
        $failed++;
        continue;
    }
    if ((int) $db->affected_rows() !== 1) {
        continue;
    }

    $processed++;
    try {
        $result = $checkoutService->finalizeNativeCheckout($checkoutId);
        if (empty($result['success'])) {
            $reason = 'Native checkout finalization did not confirm a completed checkout.';
            $update = 'UPDATE '.$queueTable." SET status = 'failed', last_error = '".$db->escape($reason)."', changed_at = '".$escapedNow."'";
            $update .= ' WHERE rowid = '.$queueId.' AND entity = '.$entity." AND status = 'processing'";
            if (!$db->query($update)) {
                $failed++;
                continue;
            }
            $failed++;
            continue;
        }

        $update = 'UPDATE '.$queueTable." SET status = 'completed', last_error = NULL, changed_at = '".$escapedNow."'";
        $update .= ' WHERE rowid = '.$queueId.' AND entity = '.$entity." AND status = 'processing'";
        if (!$db->query($update)) {
            fwrite(STDERR, "Checkout ".$checkoutId." finalized but queue completion could not be persisted; retry is idempotent.\n");
            $failed++;
        }
    } catch (Throwable $e) {
        $message = substr(preg_replace('/[\r\n]+/', ' ', $e->getMessage()), 0, 255);
        $update = 'UPDATE '.$queueTable." SET status = 'failed', last_error = '".$db->escape($message)."', changed_at = '".$escapedNow."'";
        $update .= ' WHERE rowid = '.$queueId.' AND entity = '.$entity." AND status = 'processing'";
        if (!$db->query($update)) {
            fwrite(STDERR, "Checkout ".$checkoutId." failed and queue failure state could not be persisted.\n");
        }
        fwrite(STDERR, "Checkout ".$checkoutId." failed: ".$message."\n");
        $failed++;
    }
}

printf("Native payment queue: processed=%d failed=%d entity=%d workflow_user=%d\n", $processed, $failed, $entity, $workflowUserId);
exit($failed > 0 ? 1 : 0);
