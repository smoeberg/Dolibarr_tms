<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingpaymentservice.class.php';
$access = trainingAccess(); $store = new TrainingStore($db, $access);
$payment = new TrainingPaymentService($store);
$id = GETPOSTINT('id');
try { $session = $store->session($id); } catch (Throwable $e) { accessforbidden(); }
$error = '';

// Get session summary
try { $summary = $payment->sessionPaymentSummary($id); } catch (Throwable $e) { $summary = null; }

// Get enrollments with balance
$enrollments = array();
if ($user->hasRight('training', 'enrollment', 'read')) {
    try {
        $enrollmentService = new TrainingEnrollmentService($store);
        $enrollmentRows = $enrollmentService->listForSession($id);
        foreach ($enrollmentRows as $row) {
            if ($row->status === 'confirmed') {
                $balance = $payment->getEnrollmentBalance($id, (int) $row->rowid);
                $row->balance = $balance;
                $enrollments[] = $row;
            }
        }
    } catch (Throwable $e) { $error = trainingError($e); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch (GETPOST('action', 'aZ09')) {
            case 'allocate':
                $paymentId = GETPOSTINT('payment_id');
                $allocationMap = array();
                foreach ($enrollments as $enrollment) {
                    $amount = GETPOST('amount_'.(int) $enrollment->rowid, 'alphanohtml');
                    if ($amount !== '' && $amount !== '0' && $amount !== '0.00') {
                        $allocationMap[(int) $enrollment->rowid] = $amount;
                    }
                }
                if (empty($allocationMap)) {
                    throw new RuntimeException('TrainingPaymentNoAllocations');
                }
                $result = $payment->allocatePayment($id, $paymentId, $allocationMap, GETPOST('reason', 'alphanohtml'));
                header('Location: '.dol_buildpath('/training/payment.php', 1).'?id='.$id.'&saved=1'); exit;
                break;
            case 'adjust':
                $allocationId = GETPOSTINT('allocation_id');
                $newAmount = GETPOST('new_amount', 'alphanohtml');
                $payment->adjustAllocation($id, GETPOSTINT('payment_id'), $allocationId, $newAmount, GETPOST('reason', 'alphanohtml'));
                header('Location: '.dol_buildpath('/training/payment.php', 1).'?id='.$id.'&saved=1'); exit;
                break;
            case 'remove':
                $allocationId = GETPOSTINT('allocation_id');
                $payment->removeAllocation($id, GETPOSTINT('payment_id'), $allocationId, GETPOST('reason', 'alphanohtml'));
                header('Location: '.dol_buildpath('/training/payment.php', 1).'?id='.$id.'&saved=1'); exit;
                break;
            default:
                throw new RuntimeException('TrainingInvalidTransition');
        }
    } catch (Throwable $e) { $error = trainingError($e); }
}

llxHeader('', $langs->trans('TrainingPayment'));
print '<a href="'.dol_buildpath('/training/session.php', 1).'?id='.$id.'">'.trainingEscape($session->ref).'</a><h2>'.trainingEscape($langs->trans('TrainingPayment')).'</h2>';

if ($error) { print '<div class="error">'.$error.'</div>'; }
if (GETPOSTINT('saved')) { print '<div class="ok">'.trainingEscape($langs->trans('TrainingPaymentSaved')).'</div>'; }

// Show summary
if ($summary) {
    print '<h3>'.trainingEscape($langs->trans('TrainingPaymentSummary')).'</h3>';
    print '<table class="liste centpercent">';
    print '<tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingTotalEnrollments')).'</th><th>'.trainingEscape($langs->trans('TrainingTotalFrozenAmount')).'</th><th>'.trainingEscape($langs->trans('TrainingTotalAllocated')).'</th><th>'.trainingEscape($langs->trans('TrainingTotalRemaining')).'</th></tr>';
    print '<tr><td>'.$summary['total_enrollments'].'</td><td>'.trainingEscape($summary['total_frozen_amount']).' '.$summary['currency'].'</td><td>'.trainingEscape($summary['total_allocated']).' '.$summary['currency'].'</td><td>'.trainingEscape($summary['total_remaining']).' '.$summary['currency'].'</td></tr>';
    print '</table>';
    print '<p>'.trainingEscape($langs->trans('TrainingFullyPaid')).': '.$summary['fully_paid'].' | '.trainingEscape($langs->trans('TrainingPartiallyPaid')).': '.$summary['partially_paid'].' | '.trainingEscape($langs->trans('TrainingUnpaid')).': '.$summary['unpaid'].'</p>';
}

// Show enrollments with balance
if ($user->hasRight('training', 'enrollment', 'read')) {
    print '<h3>'.trainingEscape($langs->trans('TrainingEnrollmentBalances')).'</h3>';
    print '<table class="liste centpercent">';
    print '<tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('TrainingFrozenPrice')).'</th><th>'.trainingEscape($langs->trans('TrainingAllocated')).'</th><th>'.trainingEscape($langs->trans('TrainingRemaining')).'</th><th>'.trainingEscape($langs->trans('Status')).'</th></tr>';
    
    foreach ($enrollments as $enrollment) {
        $balance = $enrollment->balance;
        $name = $enrollment->contact ? trim($enrollment->contact->firstname.' '.$enrollment->contact->lastname) : $langs->trans('TrainingRestrictedContact');
        $status = $balance['is_paid'] ? $langs->trans('TrainingPaid') : ($balance['is_overpaid'] ? $langs->trans('TrainingOverpaid') : ($balance['remaining'] !== null && bccomp($balance['remaining'], '0', 8) > 0 ? $langs->trans('TrainingPartiallyPaid') : $langs->trans('TrainingNotPaid')));
        
        print '<tr>';
        print '<td>'.trainingEscape($name).'</td>';
        print '<td>'.($balance['frozen_price'] !== null ? trainingEscape($balance['frozen_price']).' '.$balance['currency'] : '-').'</td>';
        print '<td>'.($balance['allocated_total'] !== null && $balance['allocated_total'] !== '0.00' ? trainingEscape($balance['allocated_total']).' '.$balance['currency'] : '-').'</td>';
        print '<td>'.($balance['remaining'] !== null ? trainingEscape($balance['remaining']).' '.$balance['currency'] : '-').'</td>';
        print '<td>'.trainingEscape($status).'</td>';
        print '</tr>';
    }
    print '</table>';
}

// Allocation form
if ($user->hasRight('training', 'payment', 'write') && !empty($enrollments)) {
    print '<h3>'.trainingEscape($langs->trans('TrainingAllocatePayment')).'</h3>';
    print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>'.trainingEscape($langs->trans('TrainingPaymentId')).' <input type="number" min="1" required name="payment_id" value="'.(GETPOSTINT('payment_id') ?: '').'"></label> <button class="button">'.trainingEscape($langs->trans('Show')).'</button></form>';
    
    $paymentId = GETPOSTINT('payment_id');
    if ($paymentId) {
        trainingForm('allocate', $id, '/training/payment.php');
        print '<input type="hidden" name="payment_id" value="'.$paymentId.'">';
        print '<table class="liste centpercent">';
        print '<tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('TrainingFrozenPrice')).'</th><th>'.trainingEscape($langs->trans('TrainingRemaining')).'</th><th>'.trainingEscape($langs->trans('TrainingAllocateAmount')).'</th></tr>';
        
        foreach ($enrollments as $enrollment) {
            $balance = $enrollment->balance;
            $name = $enrollment->contact ? trim($enrollment->contact->firstname.' '.$enrollment->contact->lastname) : $langs->trans('TrainingRestrictedContact');
            $maxAmount = $balance['remaining'] !== null ? $balance['remaining'] : ($balance['frozen_price'] !== null ? $balance['frozen_price'] : '');
            
            print '<tr>';
            print '<td>'.trainingEscape($name).'</td>';
            print '<td>'.($balance['frozen_price'] !== null ? trainingEscape($balance['frozen_price']).' '.$balance['currency'] : '-').'</td>';
            print '<td>'.($balance['remaining'] !== null ? trainingEscape($balance['remaining']).' '.$balance['currency'] : '-').'</td>';
            print '<td><input type="text" name="amount_'.(int) $enrollment->rowid.'" pattern="[0-9]+(\.[0-9]{1,8})?" placeholder="0.00" title="'.trainingEscape($langs->trans('TrainingPaymentAmountHelp')).'" value=""></td>';
            print '</tr>';
        }
        print '</table>';
        print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans('TrainingAllocatePayment')).'</button></form>';
    }
}

// Show existing allocations
if ($user->hasRight('training', 'payment', 'read')) {
    print '<h3>'.trainingEscape($langs->trans('TrainingExistingAllocations')).'</h3>';
    try {
        $allocations = $payment->sessionAllocations($id);
        if (!empty($allocations)) {
            print '<table class="liste centpercent">';
            print '<tr class="liste_titre"><th>'.trainingEscape($langs->trans('Date')).'</th><th>'.trainingEscape($langs->trans('TrainingPaymentRef')).'</th><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('Amount')).'</th><th></th></tr>';
            
            foreach ($allocations as $alloc) {
                $enrollmentRow = null;
                foreach ($enrollments as $e) {
                    if ((int) $e->rowid === (int) $alloc->fk_enrollment) {
                        $enrollmentRow = $e;
                        break;
                    }
                }
                $name = $enrollmentRow && $enrollmentRow->contact ? trim($enrollmentRow->contact->firstname.' '.$enrollmentRow->contact->lastname) : '#'.(int) $alloc->fk_enrollment;
                
                print '<tr>';
                print '<td>'.trainingEscape(trainingLocal($alloc->datec, $session->timezone)).'</td>';
                print '<td><a href="'.dol_buildpath('/compta/paiement/card.php', 1).'?id='.(int) $alloc->fk_paiement.'">'.trainingEscape($alloc->paiement_ref ?: $alloc->payment_ref).'</a></td>';
                print '<td>'.trainingEscape($name).'</td>';
                print '<td>'.trainingEscape($alloc->amount).' '.$alloc->currency.'</td>';
                print '<td>';
                if ($user->hasRight('training', 'payment', 'write') && $user->hasRight('training', 'payment', 'correct')) {
                    trainingForm('adjust', $id, '/training/payment.php');
                    print '<input type="hidden" name="allocation_id" value="'.(int) $alloc->rowid.'">';
                    print '<input type="hidden" name="payment_id" value="'.(int) $alloc->fk_paiement.'">';
                    print '<input type="text" name="new_amount" pattern="[0-9]+(\.[0-9]{1,8})?" placeholder="'.trainingEscape($alloc->amount).'" title="'.trainingEscape($langs->trans('TrainingPaymentAmountHelp')).'">';
                    print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'">';
                    print '<button class="button">'.trainingEscape($langs->trans('TrainingAdjust')).'</button></form> ';
                    
                    trainingForm('remove', $id, '/training/payment.php');
                    print '<input type="hidden" name="allocation_id" value="'.(int) $alloc->rowid.'">';
                    print '<input type="hidden" name="payment_id" value="'.(int) $alloc->fk_paiement.'">';
                    print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'">';
                    print '<button class="button">'.trainingEscape($langs->trans('TrainingRemove')).'</button></form>';
                }
                print '</td>';
                print '</tr>';
            }
            print '</table>';
        } else {
            print '<p>'.trainingEscape($langs->trans('TrainingNoAllocations')).'</p>';
        }
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}

llxFooter(); $db->close();
