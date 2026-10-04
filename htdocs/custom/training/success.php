<?php
/**
 * Training Checkout Success Page
 * 
 * This page is shown after a successful payment.
 * It displays the confirmation details and sends the user to their confirmation email.
 */

require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingstore.class.php';

$access = trainingAccess();
$store = new TrainingStore($db, $access);

$sessionId = GETPOSTINT('session_id');
$checkoutSessionId = GETPOSTINT('checkout_session_id');

llxHeader('', $langs->trans('TrainingPaymentSuccessful'));

print '<div class="successBox">';
print '<h1>'.$langs->trans('TrainingPaymentSuccessful').'</h1>';
print '<p class="success">'.img_picto('tick.png', $langs->trans('Success')).' '.$langs->trans('TrainingPaymentReceived').'</p>';

// Show session details
if ($sessionId) {
    try {
        $session = $store->session($sessionId);
        print '<h2>'.$langs->trans('TrainingSessionDetails').'</h2>';
        print '<table class="border centpercent">';
        print '<tr><td width="30%">'.$langs->trans('Ref').'</td><td>'.trainingEscape($session->ref).'</td></tr>';
        print '<tr><td>'.$langs->trans('Label').'</td><td>'.trainingEscape($session->label).'</td></tr>';
        print '<tr><td>'.$langs->trans('TrainingCapacity').'</td><td>'.(int) $session->capacity.'</td></tr>';
        print '<tr><td>'.$langs->trans('Status').'</td><td>'.trainingEscape($langs->trans('TrainingSession'.ucfirst($session->status))).'</td></tr>';
        print '</table>';
    } catch (Throwable $e) {
        // Session not found, show generic success
    }
}

// Show checkout session details
if ($checkoutSessionId) {
    try {
        global $db;
        $sql = 'SELECT * FROM '.$db->prefix().'training_checkout_session';
        $sql .= ' WHERE rowid='.$checkoutSessionId.' AND entity='.$store->access->entity();
        
        $result = $db->query($sql);
        if ($result && $row = $db->fetch_object($result)) {
            print '<h2>'.$langs->trans('TrainingCheckoutDetails').'</h2>';
            print '<table class="border centpercent">';
            print '<tr><td width="30%">'.$langs->trans('TrainingCheckoutSessionID').'</td><td>CHK-'.(int) $checkoutSessionId.'</td></tr>';
            print '<tr><td>'.$langs->trans('TrainingTotalAmount').'</td><td>'.price($row->total_amount_ttc, 1, '', 1, 0, 0, $row->currency).'</td></tr>';
            print '<tr><td>'.$langs->trans('TrainingPaymentStatus').'</td><td>'.trainingEscape($langs->trans('TrainingPayment'.ucfirst($row->status))).'</td></tr>';
            print '<tr><td>'.$langs->trans('Date').'</td><td>'.$row->datec.'</td></tr>';
            print '</table>';
        }
    } catch (Throwable $e) {
        // Checkout session not found
    }
}

// Show participants
if ($checkoutSessionId) {
    try {
        global $db;
        $sql = 'SELECT * FROM '.$db->prefix().'training_checkout_participant';
        $sql .= ' WHERE fk_checkout_session='.$checkoutSessionId.' AND entity='.$store->access->entity();
        
        $result = $db->query($sql);
        if ($result && $db->num_rows($result) > 0) {
            print '<h2>'.$langs->trans('TrainingParticipants').'</h2>';
            print '<table class="border centpercent">';
            print '<tr class="liste_titre"><th>#</th><th>'.$langs->trans('FirstName').'</th><th>'.$langs->trans('LastName').'</th><th>'.$langs->trans('Email').'</th><th>'.$langs->trans('Status').'</th></tr>';
            
            $i = 1;
            while ($row = $db->fetch_object($result)) {
                print '<tr>';
                print '<td>'.$i++.'</td>';
                print '<td>'.trainingEscape($row->first_name).'</td>';
                print '<td>'.trainingEscape($row->last_name).'</td>';
                print '<td>'.trainingEscape($row->email).'</td>';
                print '<td>'.trainingEscape($langs->trans('TrainingParticipant'.ucfirst($row->status))).'</td>';
                print '</tr>';
            }
            
            print '</table>';
        }
    } catch (Throwable $e) {
        // Participants not found
    }
}

print '<div class="info">';
print '<p>'.$langs->trans('TrainingConfirmationEmailSent').'</p>';
print '<p>'.$langs->trans('TrainingYouWillReceive').'</p>';
print '</div>';

// Action buttons
print '<div class="center">';
print '<a href="'.dol_buildpath('/training/myattendance.php', 1).'" class="button">'.$langs->trans('TrainingViewMyBookings').'</a> ';
print '<a href="'.dol_buildpath('/training/', 1).'" class="button">'.$langs->trans('TrainingBackToTraining').'</a> ';
print '<a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingMakeAnotherBooking').'</a>';
print '</div>';

// Clear checkout session
unset($_SESSION['training_checkout']);

llxFooter();
$db->close();
