<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingcommercialservice.class.php';
$service=new TrainingCommercialService(new TrainingStore($db,trainingAccess()));
$id=GETPOSTINT('id'); $enrollmentId=GETPOSTINT('enrollment_id');
try { $detail=$service->detail($id,$enrollmentId); } catch (Throwable $e) { accessforbidden(); }
$error='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (GETPOST('action','aZ09') !== 'roles') { throw new RuntimeException('TrainingInvalidTransition'); }
        $revision=GETPOST('revision','alphanohtml');
        if (!preg_match('/^[0-9]{1,9}$/D',$revision)) { throw new RuntimeException('TrainingCommercialConflict'); }
        $links=array();
        foreach (array('buyer','payer','employer') as $role) {
            if (!GETPOSTISSET($role)) { throw new RuntimeException('TrainingCommercialInvalid'); }
            $value=GETPOST($role,'alphanohtml');
            if ($value !== '' && !preg_match('/^[1-9][0-9]{0,9}$/D',$value)) { throw new RuntimeException('TrainingCommercialInvalid'); }
            $links[$role]=$value === '' ? null : (int) $value;
        }
        $service->change($id,$enrollmentId,(int) $revision,$links,GETPOST('reason','alphanohtml'));
        header('Location: '.dol_buildpath('/training/commercial.php',1).'?id='.$id.'&enrollment_id='.$enrollmentId.'&saved=1'); exit;
    } catch (Throwable $e) { $error=trainingError($e); }
}
try { $detail=$service->detail($id,$enrollmentId); } catch (Throwable $e) { accessforbidden(); }
$session=$detail['session']; $search=GETPOST('search','alphanohtml');
llxHeader('', $langs->trans('TrainingCommercial'));
print '<a href="'.dol_buildpath('/training/session.php',1).'?id='.$id.'">'.trainingEscape($session->ref).'</a><h2>'.trainingEscape($langs->trans('TrainingCommercial')).'</h2>';
print '<p>'.trainingEscape(trim($detail['contact']->firstname.' '.$detail['contact']->lastname)).' · '.trainingEscape($langs->trans('TrainingEnrollment'.ucfirst($detail['enrollment']->status))).'</p>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
if (GETPOSTINT('saved')) { print '<div class="ok">'.trainingEscape($langs->trans('TrainingCommercialSaved')).'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingCommercialHelp')).'</p><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingCommercialRole')).'</th><th>'.trainingEscape($langs->trans('ThirdParty')).'</th></tr>';
foreach ($detail['parties'] as $role=>$party) {
    print '<tr><td>'.trainingEscape($langs->trans('TrainingRole'.ucfirst($role))).'</td><td>';
    if ($party) { print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $party->rowid.'">'.trainingEscape($party->nom).'</a>'; if ((int) $party->status !== 1) { print ' · '.trainingEscape($langs->trans('TrainingCommercialInactive')); } }
    else { print trainingEscape($langs->trans('TrainingCommercialMissing')); }
    print '</td></tr>';
}
print '</table>';
$editable=$detail['enrollment']->status === 'confirmed' && $user->hasRight('training','commercial','write') && (!$detail['revision'] || $user->hasRight('training','commercial','correct'));
if ($editable) {
    print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="enrollment_id" value="'.$enrollmentId.'"><label>'.trainingEscape($langs->trans('TrainingFindThirdparty')).' <input name="search" value="'.trainingEscape($search).'"></label> <button class="button">'.trainingEscape($langs->trans('Search')).'</button></form>';
    try {
        $choices=$service->choices($search); $options=array(); foreach ($choices as $c) { $options[(int) $c->rowid]=$c->nom; }
        foreach ($detail['parties'] as $p) { if ($p) { $options[(int) $p->rowid]=$p->nom; } }
        trainingForm('roles',$id,'/training/commercial.php');
        print '<input type="hidden" name="enrollment_id" value="'.$enrollmentId.'"><input type="hidden" name="revision" value="'.$detail['revision'].'">';
        foreach ($detail['links'] as $role=>$selected) {
            print '<p><label>'.trainingEscape($langs->trans('TrainingRole'.ucfirst($role))).' <select name="'.$role.'"><option value="">'.trainingEscape($langs->trans('TrainingCommercialMissing')).'</option>';
            foreach ($options as $partyId=>$name) { print '<option value="'.$partyId.'"'.($selected === $partyId ? ' selected' : '').'>'.trainingEscape($name).'</option>'; }
            print '</select></label></p>';
        }
        print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans('Save')).'</button></form>';
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
print '<h3>'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</h3>';
try {
    $events=$service->history($id,$enrollmentId);
    print '<table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('Date')).'</th><th>'.trainingEscape($langs->trans('User')).'</th><th>'.trainingEscape($langs->trans('TrainingCommercialRole')).'</th><th>'.trainingEscape($langs->trans('TrainingCorrectionReason')).'</th></tr>';
    foreach ($events as $event) {
        $meta=json_decode($event->metadata_json,true,512,JSON_THROW_ON_ERROR);
        print '<tr><td>'.trainingEscape(trainingLocal($event->datec,$session->timezone)).'</td><td>#'.(int) $event->fk_user_actor.'</td><td>';
        foreach ($meta['after'] as $role=>$partyId) { print trainingEscape($langs->trans('TrainingRole'.ucfirst($role))).': '.($meta['before'][$role] === null ? '—' : '#'.(int) $meta['before'][$role]).' → '.($partyId === null ? '—' : '#'.(int) $partyId).'<br>'; }
        print '</td><td>'.trainingEscape($meta['reason']).'</td></tr>';
    }
    print '</table>';
} catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
llxFooter(); $db->close();
