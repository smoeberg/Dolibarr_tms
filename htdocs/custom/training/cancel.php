<?php
/**
 * Training Checkout Cancel Page
 * 
 * This page is shown when a user cancels the payment process.
 * It allows them to restart the checkout or return to the session selection.
 */

require_once __DIR__.'/lib/ui.lib.php';

$access = trainingAccess();

$sessionId = GETPOSTINT('session_id');
$reason = GETPOST('reason', 'aZ09');

llxHeader('', $langs->trans('TrainingPaymentCancelled'));

print '<div class="warningBox">';
print '<h1>'.$langs->trans('TrainingPaymentCancelled').'</h1>';

// Show appropriate message based on reason
if ($reason === 'expired') {
    print '<p class="warning">'.img_picto('warning.png', $langs->trans('Warning')).' '.$langs->trans('TrainingSeatHoldExpired').'</p>';
    print '<p>'.$langs->trans('TrainingSeatHoldExpiredDesc').'</p>';
} else {
    print '<p class="warning">'.img_picto('warning.png', $langs->trans('Warning')).' '.$langs->trans('TrainingPaymentNotCompleted').'</p>';
    print '<p>'.$langs->trans('TrainingYouCanRestart').'</p>';
}

print '</div>';

// Show session info if available
if ($sessionId) {
    try {
        require_once __DIR__.'/class/trainingstore.class.php';
        $store = new TrainingStore($db, $access);
        $session = $store->session($sessionId);
        
        print '<h2>'.$langs->trans('TrainingSelectedSession').'</h2>';
        print '<table class="border centpercent">';
        print '<tr><td width="30%">'.$langs->trans('Ref').'</td><td>'.trainingEscape($session->ref).'</td></tr>';
        print '<tr><td>'.$langs->trans('Label').'</td><td>'.trainingEscape($session->label).'</td></tr>';
        print '</table>';
    } catch (Throwable $e) {
        // Session not found
    }
}

// Action buttons
print '<div class="center">';

// Release seat hold if it exists
$holdId = GETPOSTINT('hold_id');
if ($holdId && $sessionId) {
    try {
        require_once __DIR__.'/class/trainingcheckoutservice.class.php';
        require_once __DIR__.'/class/trainingstripeadapter.class.php';
        
        $stripeConfig = json_decode($conf->global->TRAINING_STRIPE_CONFIG ?? '{}', true);
        if (!empty($stripeConfig['api_key']) && !empty($stripeConfig['webhook_secret'])) {
            $store = new TrainingStore($db, $access);
            $stripe = new TrainingStripeAdapter($store, $stripeConfig['api_key'], $stripeConfig['webhook_secret']);
            $checkout = new TrainingCheckoutService($store, $stripe);
            
            // Release the seat hold
            $checkout->releaseSeatHold($sessionId, $holdId, 'user_cancelled');
        }
    } catch (Throwable $e) {
        // Could not release seat hold
    }
}

// Clear checkout session
unset($_SESSION['training_checkout']);

print '<a href="'.dol_buildpath('/training/checkout.php', 1).'?session_id='.(int) $sessionId.'" class="button">'.$langs->trans('TrainingRestartCheckout').'</a> ';
print '<a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingSelectDifferentSession').'</a> ';
print '<a href="'.dol_buildpath('/training/', 1).'" class="button">'.$langs->trans('TrainingBackToTraining').'</a>';
print '</div>';

llxFooter();
$db->close();
