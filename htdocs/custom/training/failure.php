<?php
/**
 * Training Checkout Failure Page
 * 
 * This page is shown when a payment fails.
 * It displays the error message and allows the user to retry or cancel.
 */

require_once __DIR__.'/lib/ui.lib.php';

$access = trainingAccess();

$sessionId = GETPOSTINT('session_id');
$error = GETPOST('error', 'aZ09');
$paymentIntentId = GETPOST('payment_intent_id', 'aZ09');

llxHeader('', $langs->trans('TrainingPaymentFailed'));

print '<div class="errorBox">';
print '<h1>'.$langs->trans('TrainingPaymentFailed').'</h1>';

// Show error message
if ($error) {
    print '<p class="error">'.img_picto('error.png', $langs->trans('Error')).' '.trainingEscape($error).'</p>';
} else {
    print '<p class="error">'.img_picto('error.png', $langs->trans('Error')).' '.$langs->trans('TrainingPaymentFailedDesc').'</p>';
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

// Show troubleshooting info
print '<div class="info">';
print '<h2>'.$langs->trans('TrainingTroubleshooting').'</h2>';
print '<p>'.$langs->trans('TrainingPaymentFailedHelp').'</p>';
print '<ul>';
print '<li>'.$langs->trans('TrainingCheckCardDetails').'</li>';
print '<li>'.$langs->trans('TrainingCheckInternetConnection').'</li>';
print '<li>'.$langs->trans('TrainingTryDifferentCard').'</li>';
print '<li>'.$langs->trans('TrainingContactSupport').'</li>';
print '</ul>';
print '</div>';

// Action buttons
print '<div class="center">';

// Clear checkout session
unset($_SESSION['training_checkout']);

print '<a href="'.dol_buildpath('/training/checkout.php', 1).'?session_id='.(int) $sessionId.'" class="button">'.$langs->trans('TrainingTryAgain').'</a> ';
print '<a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingSelectDifferentSession').'</a> ';
print '<a href="'.dol_buildpath('/training/cancel.php', 1).'?session_id='.(int) $sessionId.'&reason=cancelled" class="button">'.$langs->trans('TrainingCancel').'</a>';
print '</div>';

llxFooter();
$db->close();
