<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingbillingservice.class.php';
$access = trainingAccess(); $store = new TrainingStore($db, $access);
$billing = new TrainingBillingService($store, $conf->currency);
$id = GETPOSTINT('id'); $invoiceId = GETPOSTINT('invoice_id'); $lineId = GETPOSTINT('line_id');
try { $session = $store->session($id); $participants = $billing->participants($id); } catch (Throwable $e) { accessforbidden(); }
$error = ''; $view = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!in_array(GETPOST('action', 'aZ09'), array('allocate','void'), true)) { throw new RuntimeException('TrainingInvalidTransition'); }
        $revision = GETPOST('revision', 'alphanohtml');
        if (!preg_match('/^[0-9]{1,9}$/D', $revision)) { throw new RuntimeException('TrainingBillingConflict'); }
        if (GETPOST('action', 'aZ09') === 'void') {
            $billing->voidLine($id, $invoiceId, $lineId, (int) $revision, GETPOST('reason', 'alphanohtml'));
            header('Location: '.dol_buildpath('/training/billing.php', 1).'?id='.$id.'&invoice_id='.$invoiceId.'&line_id='.$lineId.'&saved=1'); exit;
        }
        $weights = array();
        foreach ($participants as $participant) {
            $raw = GETPOST('weight_'.(int) $participant->rowid, 'alphanohtml');
            if ($raw === '' || $raw === '0') { continue; }
            if (!preg_match('/^[1-9][0-9]{0,4}$/D', $raw) || (int) $raw > 10000) { throw new RuntimeException('TrainingInvalidBillingShares'); }
            $weights[(int) $participant->rowid] = (int) $raw;
        }
        $billing->allocate($id, $invoiceId, $lineId, (int) $revision, $weights, GETPOST('reason', 'alphanohtml'));
        header('Location: '.dol_buildpath('/training/billing.php', 1).'?id='.$id.'&invoice_id='.$invoiceId.'&line_id='.$lineId.'&saved=1'); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
if ($invoiceId && $lineId) {
    try { $view = $billing->inspect($id, $invoiceId, $lineId); } catch (Throwable $e) { $error = trainingError($e); }
}
llxHeader('', $langs->trans('TrainingBilling'));
print '<a href="'.dol_buildpath('/training/session.php', 1).'?id='.$id.'">'.trainingEscape($session->ref).'</a><h2>'.trainingEscape($langs->trans('TrainingBilling')).'</h2>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
if (GETPOSTINT('saved') && !$error && $view) { print '<div class="ok">'.trainingEscape($langs->trans('TrainingBillingSaved')).'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingBillingHelp')).'</p>';
print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>'.trainingEscape($langs->trans('TrainingInvoiceId')).' <input type="number" min="1" required name="invoice_id" value="'.($invoiceId ?: '').'"></label> <label>'.trainingEscape($langs->trans('TrainingInvoiceLineId')).' <input type="number" min="1" required name="line_id" value="'.($lineId ?: '').'"></label> <button class="button">'.trainingEscape($langs->trans('Show')).'</button></form>';
if ($view) {
    $source = $view['source']; $line = $source['line'];
    print '<h3><a href="'.dol_buildpath('/compta/facture/card.php', 1).'?id='.$invoiceId.'">'.trainingEscape($source['invoice']->ref).'</a> · '.trainingEscape($langs->trans('TrainingInvoiceLineId')).' '.$lineId.'</h3>';
    print '<p>'.trainingEscape($line->description).' · '.trainingEscape($source['snapshot']['currency']).' · HT '.trainingEscape($line->total_ht).' · TTC '.trainingEscape($line->total_ttc).'</p>';
    if ($view['mapping'] && $view['mapping']->status === 'void') { print '<div class="warning">'.trainingEscape($langs->trans('TrainingBillingVoided')).'</div>'; }
    if ($view['mapping'] && $view['mapping']->status === 'active' && !$view['reconciled']) { print '<div class="warning">'.trainingEscape($langs->trans('TrainingBillingUnreconciled')).'</div>'; }
    print '<table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('TrainingBillingWeight')).'</th><th>HT</th><th>TTC</th></tr>';
    $currentWeights = array();
    foreach ($view['shares'] as $share) {
        $currentWeights[(int) $share->enrollment_id] = (int) $share->weight;
        print '<tr><td>'.trainingEscape(trim($share->contact->firstname.' '.$share->contact->lastname)).'</td><td>'.(int) $share->weight.'</td><td>'.trainingEscape($share->amount_ht).'</td><td>'.trainingEscape($share->amount_ttc).'</td></tr>';
    }
    print '</table>';
    if ($source['eligible'] && (!$view['mapping'] || $view['mapping']->status === 'void' || $view['reconciled']) && $user->hasRight('training', 'billing', 'write') && (!$view['mapping'] || $user->hasRight('training', 'billing', 'correct'))) {
        trainingForm('allocate', $id, '/training/billing.php');
        print '<input type="hidden" name="invoice_id" value="'.$invoiceId.'"><input type="hidden" name="line_id" value="'.$lineId.'"><input type="hidden" name="revision" value="'.$view['revision'].'">';
        foreach ($participants as $participant) {
            print '<p><label>'.trainingEscape(trim($participant->contact->firstname.' '.$participant->contact->lastname)).' ('.trainingEscape($langs->trans('TrainingEnrollment'.ucfirst($participant->status))).') <input type="number" min="0" max="10000" name="weight_'.(int) $participant->rowid.'" value="'.($currentWeights[(int) $participant->rowid] ?? 0).'"></label></p>';
        }
        if ($view['mapping']) { print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> '; }
        print '<button class="button">'.trainingEscape($langs->trans('TrainingAllocateInvoiceLine')).'</button></form>';
    } elseif (!$source['eligible']) { print '<div class="warning">'.trainingEscape($langs->trans('TrainingInvoiceNotEligible')).'</div>'; }
    if ($view['mapping'] && $view['mapping']->status === 'active' && $user->hasRight('training', 'billing', 'write') && $user->hasRight('training', 'billing', 'correct')) {
        trainingForm('void', $id, '/training/billing.php');
        print '<input type="hidden" name="invoice_id" value="'.$invoiceId.'"><input type="hidden" name="line_id" value="'.$lineId.'"><input type="hidden" name="revision" value="'.$view['revision'].'"><input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans('TrainingVoidBilling')).'</button></form>';
    }
    if ($view['mapping']) {
        print '<h3>'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</h3><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('Date')).'</th><th>'.trainingEscape($langs->trans('User')).'</th><th>'.trainingEscape($langs->trans('TrainingCorrectionReason')).'</th><th>'.trainingEscape($langs->trans('TrainingBillingChanges')).'</th></tr>';
        foreach ($billing->history($id, $invoiceId, $lineId) as $event) {
            $meta = json_decode($event->metadata_json, true);
            print '<tr><td>'.trainingEscape(trainingLocal($event->datec, $session->timezone)).'</td><td>'.(int) $event->fk_user_actor.'</td><td>'.trainingEscape($meta['reason'] ?? '').'</td><td>';
            foreach (array('before','after') as $phase) {
                print '<p>'.trainingEscape($langs->trans($phase === 'before' ? 'TrainingBillingBefore' : 'TrainingBillingAfter')).': ';
                foreach ($meta[$phase] as $share) { print '#'.(int) $share['enrollment_id'].' HT '.trainingEscape($share['amount_ht']).' / TTC '.trainingEscape($share['amount_ttc']).'; '; }
                print '</p>';
            }
            print '</td></tr>';
        }
        print '</table>';
    }
}
print '<h3>'.trainingEscape($langs->trans('TrainingAllocatedLines')).'</h3>';
try {
    foreach ($billing->forSession($id) as $allocated) {
        $map = $allocated['mapping'];
        print '<p><a href="'.dol_buildpath('/training/billing.php', 1).'?id='.$id.'&invoice_id='.(int) $map->fk_facture.'&line_id='.(int) $map->fk_facturedet.'">'.trainingEscape($allocated['source']['invoice']->ref).' / '.(int) $map->fk_facturedet.'</a> · '.trainingEscape($langs->trans($map->status === 'void' ? 'TrainingBillingVoided' : ($allocated['reconciled'] ? 'TrainingBillingReconciled' : 'TrainingBillingUnreconciled'))).'</p>';
    }
} catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
llxFooter(); $db->close();
