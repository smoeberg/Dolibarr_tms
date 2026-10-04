<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingtrainerservice.class.php';
$store = new TrainingStore($db, trainingAccess()); $trainers = new TrainingTrainerService($store); $id = GETPOSTINT('id');
try { $session = $store->session($id); $rows = $trainers->listForSession($id); } catch (Throwable $e) { accessforbidden(); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (GETPOST('action','aZ09') !== 'assignment') { throw new RuntimeException('TrainingInvalidTransition'); }
        $revision = GETPOST('revision','alphanohtml');
        if (!preg_match('/^[0-9]{1,9}$/D',$revision)) { throw new RuntimeException('TrainingTrainerConflict'); }
        $trainers->change($id, GETPOSTINT('user_id'), (int) $revision, GETPOST('next','aZ09'), GETPOST('reason','alphanohtml'));
        header('Location: '.dol_buildpath('/training/trainers.php',1).'?id='.$id); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
try { $rows = $trainers->listForSession($id); } catch (Throwable $e) { accessforbidden(); }
llxHeader('', $langs->trans('TrainingTrainers'));
print '<a href="'.dol_buildpath('/training/session.php',1).'?id='.$id.'">'.trainingEscape($session->ref).'</a><h2>'.trainingEscape($langs->trans('TrainingTrainers')).'</h2>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingTrainerHelp')).'</p><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('User')).'</th><th>'.trainingEscape($langs->trans('Status')).'</th><th></th></tr>';
$known = array();
foreach ($rows as $row) {
    $known[(int) $row->fk_user] = $row;
    print '<tr><td>'.trainingEscape(trim($row->firstname.' '.$row->lastname).' ('.$row->login.')').'</td><td>'.trainingEscape($langs->trans('TrainingAssignment'.ucfirst($row->status)));
    if (!(int) $row->trainer_active || !(int) $row->user_active || (int) $row->user_socid>0) { print ' · '.trainingEscape($langs->trans('TrainingTrainerInactive')); }
    print '</td><td>';
    if ($user->hasRight('training','trainer','write')) {
        trainingForm('assignment',$id,'/training/trainers.php');
        $next = $row->status === 'active' ? 'revoked' : 'active';
        print '<input type="hidden" name="user_id" value="'.(int) $row->fk_user.'"><input type="hidden" name="revision" value="'.(int) $row->revision.'"><input type="hidden" name="next" value="'.$next.'"><input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans($next === 'active' ? 'TrainingAssignTrainer' : 'TrainingRevokeTrainer')).'</button></form>';
    }
    print '<a href="'.dol_buildpath('/training/trainers.php',1).'?id='.$id.'&history_id='.(int) $row->rowid.'">'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</a></td></tr>';
}
print '</table>';
if ($user->hasRight('training','trainer','write')) {
    $search = GETPOST('search','alphanohtml');
    print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>'.trainingEscape($langs->trans('TrainingFindTrainer')).' <input name="search" value="'.trainingEscape($search).'"></label> <button class="button">'.trainingEscape($langs->trans('Search')).'</button></form>';
    try {
        $choices = $trainers->choices($search);
        foreach ($choices as $candidate) {
            if (isset($known[(int) $candidate->rowid])) { continue; }
            trainingForm('assignment',$id,'/training/trainers.php');
            print '<input type="hidden" name="user_id" value="'.(int) $candidate->rowid.'"><input type="hidden" name="revision" value="0"><input type="hidden" name="next" value="active">'.trainingEscape(trim($candidate->firstname.' '.$candidate->lastname).' ('.$candidate->login.')').' <input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans('TrainingAssignTrainer')).'</button></form>';
        }
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
if (GETPOSTINT('history_id')) {
    try {
        $events = $trainers->history($id,GETPOSTINT('history_id'));
        print '<h3>'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</h3><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('Date')).'</th><th>'.trainingEscape($langs->trans('User')).'</th><th>'.trainingEscape($langs->trans('Status')).'</th><th>'.trainingEscape($langs->trans('TrainingCorrectionReason')).'</th></tr>';
        foreach ($events as $event) {
            $meta = json_decode($event->metadata_json,true,512,JSON_THROW_ON_ERROR);
            print '<tr><td>'.trainingEscape(trainingLocal($event->datec,$session->timezone)).'</td><td>#'.(int) $event->fk_user_actor.'</td><td>'.trainingEscape($meta['before'] ? $langs->trans('TrainingAssignment'.ucfirst($meta['before'])) : '—').' → '.trainingEscape($langs->trans('TrainingAssignment'.ucfirst($meta['after']))).'</td><td>'.trainingEscape($meta['reason']).'</td></tr>';
        }
        print '</table>';
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
llxFooter(); $db->close();
