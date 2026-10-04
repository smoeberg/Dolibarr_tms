<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingorderservice.class.php';
$access = trainingAccess(); $store = new TrainingStore($db, $access);
$order = new TrainingOrderService($store, $conf->currency);
$id = GETPOSTINT('id'); $orderId = GETPOSTINT('order_id'); $lineId = GETPOSTINT('line_id');
try { $session = $store->session($id); $participants = $order->participants($id); } catch (Throwable $e) { accessforbidden(); }
$error = ''; $view = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!in_array(GETPOST('action', 'aZ09'), array('allocate','void'), true)) { throw new RuntimeException('TrainingInvalidTransition'); }
        $revision = GETPOST('revision', 'alphanohtml');
        if (!preg_match('/^[0-9]{1,9}$/D', $revision)) { throw new RuntimeException('TrainingOrderConflict'); }
        if (GETPOST('action', 'aZ09') === 'void') {
            $order->voidLine($id, $orderId, $lineId, (int) $revision, GETPOST('reason', 'alphanohtml'));
            header('Location: '.dol_buildpath('/training/order.php', 1).'?id='.$id.'&order_id='.$orderId.'&line_id='.$lineId.'&saved=1'); exit;
        }
        $weights = array();
        foreach ($participants as $participant) {
            $raw = GETPOST('weight_'.(int) $participant->rowid, 'alphanohtml');
            if ($raw === '' || $raw === '0') { continue; }
            if (!preg_match('/^[1-9][0-9]{0,4}$/D', $raw) || (int) $raw > 10000) { throw new RuntimeException('TrainingInvalidOrderShares'); }
            $weights[(int) $participant->rowid] = (int) $raw;
        }
        $order->allocate($id, $orderId, $lineId, (int) $revision, $weights, GETPOST('reason', 'alphanohtml'));
        header('Location: '.dol_buildpath('/training/order.php', 1).'?id='.$id.'&order_id='.$orderId.'&line_id='.$lineId.'&saved=1'); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
if ($orderId && $lineId) {
    try { $view = $order->inspect($id, $orderId, $lineId); } catch (Throwable $e) { $error = trainingError($e); }
}
llxHeader('', $langs->trans('TrainingOrder'));
print '<a href="'.dol_buildpath('/training/session.php', 1).'?id='.$id.'">'.trainingEscape($session->ref).'</a><h2>'.trainingEscape($langs->trans('TrainingOrder')).'</h2>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
if (GETPOSTINT('saved') && !$error && $view) { print '<div class="ok">'.trainingEscape($langs->trans('TrainingOrderSaved')).'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingOrderHelp')).'</p>';
print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>'.trainingEscape($langs->trans('TrainingOrderId')).' <input type="number" min="1" required name="order_id" value="'.($orderId ?: '').'"></label> <label>'.trainingEscape($langs->trans('TrainingOrderLineId')).' <input type="number" min="1" required name="line_id" value="'.($lineId ?: '').'"></label> <button class="button">'.trainingEscape($langs->trans('Show')).'</button></form>';
if ($view) {
    $source = $view['source']; $line = $source['line'];
    print '<h3><a href="'.dol_buildpath('/compta/commande/card.php', 1).'?id='.$orderId.'">'.trainingEscape($source['order']->ref).'</a>  '.trainingEscape($langs->trans('TrainingOrderLineId')).' '.$lineId.'</h3>';
    print '<p>'.trainingEscape($line->description).'  '.trainingEscape($source['snapshot']['currency']).'  HT '.trainingEscape($line->total_ht).'  TTC '.trainingEscape($line->total_ttc).'</p>';
    if ($view['mapping'] && $view['mapping']->status === 'void') { print '<div class="warning">'.trainingEscape($langs->trans('TrainingOrderVoided')).'</div>'; }
    if ($view['mapping'] && $view['mapping']->status === 'active' && !$view['reconciled']) { print '<div class="warning">'.trainingEscape($langs->trans('TrainingOrderUnreconciled')).'</div>'; }
    print '<table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('TrainingOrderWeight')).'</th><th>HT</th><th>TTC</th></tr>';
    $currentWeights = array();
    foreach ($view['shares'] as $share) {
        $currentWeights[(int) $share->enrollment_id] = (int) $share->weight;
        print '<tr><td>'.trainingEscape(trim($share->contact->firstname.' '.$share->contact->lastname)).'</td><td>'.(int) $share->weight.'</td><td>'.trainingEscape($share->amount_ht).'</td><td>'.trainingEscape($share->amount_ttc).'</td></tr>';
    }
    print '</table>';
    if ($source['eligible'] && (!$view['mapping'] || $view['mapping']->status === 'void' || $view['reconciled']) && $user->hasRight('training', 'order', 'write') && (!$view['mapping'] || $user->hasRight('training', 'order', 'correct'))) {
        trainingForm('allocate', $id, '/training/order.php');
        print '<input type="hidden" name="order_id" value="'.$orderId.'"><input type="hidden" name="line_id" value="'.$lineId.'"><input type="hidden" name="revision" value="'.$view['revision'].'">';
        foreach ($participants as $participant) {
            print '<p><label>'.trainingEscape(trim($participant->contact->firstname.' '.$participant->contact->lastname)).' ('.trainingEscape($langs->trans('TrainingEnrollment'.ucfirst($participant->status))).') <input type="number" min="0" max="10000" name="weight_'.(int) $participant->rowid.'" value="'.($currentWeights[(int) $participant->rowid] ?? 0).'"></label></p>';
        }
        if ($view['mapping']) { print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> '; }
        print '<button class="button">'.trainingEscape($langs->trans('TrainingAllocateOrderLine')).'</button></form>';
    } elseif (!$source['eligible']) { print '<div class="warning">'.trainingEscape($langs->trans('TrainingOrderNotEligible')).'</div>'; }
    if ($view['mapping'] && $view['mapping']->status === 'active' && $user->hasRight('training', 'order', 'write') && $user->hasRight('training', 'order', 'correct')) {
        trainingForm('void', $id, '/training/order.php');
        print '<input type="hidden" name="order_id" value="'.$orderId.'"><input type="hidden" name="line_id" value="'.$lineId.'"><input type="hidden" name="revision" value="'.$view['revision'].'"><input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans('TrainingVoidOrder')).'</button></form>';
    }
    if ($view['mapping']) {
        print '<h3>'.trainingEscape($langs->trans('TrainingOrderHistory')).'</h3><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('Date')).'</th><th>'.trainingEscape($langs->trans('User')).'</th><th>'.trainingEscape($langs->trans('TrainingCorrectionReason')).'</th><th>'.trainingEscape($langs->trans('TrainingOrderChanges')).'</th></tr>';
        foreach ($order->history($id, $orderId, $lineId) as $event) {
            $meta = json_decode($event->metadata_json, true);
            print '<tr><td>'.trainingEscape(trainingLocal($event->datec, $session->timezone)).'</td><td>'.(int) $event->fk_user_actor.'</td><td>'.trainingEscape($meta['reason'] ?? '').'</td><td>';
            foreach (array('before','after') as $phase) {
                print '<p>'.trainingEscape($langs->trans($phase === 'before' ? 'TrainingOrderBefore' : 'TrainingOrderAfter')).': ';
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
    foreach ($order->forSession($id) as $allocated) {
        $map = $allocated['mapping'];
        print '<p><a href="'.dol_buildpath('/training/order.php', 1).'?id='.$id.'&order_id='.(int) $map->fk_commande.'&line_id='.(int) $map->fk_commandedet.'">'.trainingEscape($allocated['source']['order']->ref).' / '.(int) $map->fk_commandedet.'</a>  '.trainingEscape($langs->trans($map->status === 'void' ? 'TrainingOrderVoided' : ($allocated['reconciled'] ? 'TrainingOrderReconciled' : 'TrainingOrderUnreconciled'))).'</p>';
    }
} catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
llxFooter(); $db->close();
