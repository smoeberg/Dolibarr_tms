<?php
require_once __DIR__.'/lib/ui.lib.php';
$store = new TrainingStore($db, trainingAccess());
$enrollments = new TrainingEnrollmentService($store); $scheduling = new TrainingSchedulingService($store); $id = GETPOSTINT('id');
try { $detail = $scheduling->detail($id); $rows = $enrollments->reservations($id); } catch (Throwable $e) { accessforbidden(); }
$error = ''; $key = $_SERVER['REQUEST_METHOD'] === 'POST' && GETPOST('action', 'aZ09') === 'reserve' ? GETPOST('request_key', 'alphanohtml') : bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch (GETPOST('action', 'aZ09')) {
            case 'reserve':
                $contacts = GETPOST('contact_ids', 'array'); $ids = array();
                if (!is_array($contacts)) { throw new InvalidArgumentException('TrainingInvalidReservation'); }
                foreach ($contacts as $contact) {
                    if (!is_string($contact) || !preg_match('/^[1-9][0-9]{0,9}$/D', $contact)) { throw new InvalidArgumentException('TrainingInvalidReservation'); }
                    $ids[] = (int) $contact;
                }
                $enrollments->reserve($id, $ids, $key); break;
            case 'confirmReservation':
                $enrollments->confirmReservation($id, GETPOSTINT('hold_id'), GETPOST('reason', 'alphanohtml')); break;
            case 'releaseReservation':
                $enrollments->releaseReservation($id, GETPOSTINT('hold_id'), GETPOST('reason', 'alphanohtml')); break;
            default: throw new RuntimeException('TrainingInvalidTransition');
        }
        header('Location: '.dol_buildpath('/training/reservations.php', 1).'?id='.$id); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
try { $detail = $scheduling->detail($id); $rows = $enrollments->reservations($id); } catch (Throwable $e) { accessforbidden(); }
$session = $detail['session'];
llxHeader('', $langs->trans('TrainingReservations'));
print '<a href="'.dol_buildpath('/training/session.php', 1).'?id='.$id.'">'.trainingEscape($session->ref).'</a><h2>'.trainingEscape($langs->trans('TrainingReservations')).'</h2>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingReservationHelp')).'</p>';
print '<p>'.trainingEscape($langs->trans('TrainingCapacitySummary', $detail['occupied'], $detail['reserved'], $detail['available'], $session->capacity)).'</p>';
if ($user->hasRight('training', 'enrollment', 'write') && $session->status === 'open') {
    $search = GETPOST('contact_search', 'alphanohtml');
    print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>'.trainingEscape($langs->trans('TrainingFindContact')).' <input name="contact_search" value="'.trainingEscape($search).'"></label> <button class="button">'.trainingEscape($langs->trans('Search')).'</button></form>';
    try {
        $contacts = $enrollments->contactChoices($search);
        $selected = GETPOST('contact_ids', 'array');
        if (!is_array($selected)) { $selected = array(); }
        trainingForm('reserve', $id, '/training/reservations.php');
        print '<input type="hidden" name="request_key" value="'.trainingEscape($key).'">';
        foreach ($contacts as $contact) {
            print '<p><label><input type="checkbox" name="contact_ids[]" value="'.(int) $contact->id.'"'.(in_array((string) $contact->id, $selected, true) ? ' checked' : '').'> '.trainingEscape(trim($contact->firstname.' '.$contact->lastname)).'</label></p>';
        }
        print '<button class="button">'.trainingEscape($langs->trans('TrainingReserve15Minutes')).'</button></form>';
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
print '<table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('Ref')).'</th><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('Status')).'</th><th>'.trainingEscape($langs->trans('TrainingReservationExpires')).'</th><th></th></tr>';
foreach ($rows as $row) {
    print '<tr><td>#'.(int) $row->rowid.'</td><td>';
    foreach ($row->members as $member) { print trainingEscape($member->contact ? trim($member->contact->firstname.' '.$member->contact->lastname) : $langs->trans('TrainingContactNotAccessible')).'<br>'; }
    print '</td><td>'.trainingEscape($langs->trans('TrainingReservation'.ucfirst($row->effective_status))).'</td><td>'.trainingEscape(trainingLocal($row->expires_utc, $session->timezone)).'</td><td>';
    if ($row->status === 'active' && $user->hasRight('training', 'enrollment', 'write')) {
        foreach (array('confirmReservation'=>'TrainingConfirmReservation', 'releaseReservation'=>'TrainingReleaseReservation') as $action=>$label) {
            if ($action === 'confirmReservation' && $session->status !== 'open') { continue; }
            trainingForm($action, $id, '/training/reservations.php');
            print '<input type="hidden" name="hold_id" value="'.(int) $row->rowid.'"><input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> <button class="button">'.trainingEscape($langs->trans($label)).'</button></form>';
        }
    }
    print '<a href="'.dol_buildpath('/training/reservations.php', 1).'?id='.$id.'&history_id='.(int) $row->rowid.'">'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</a></td></tr>';
}
print '</table>';
if (GETPOSTINT('history_id')) {
    try {
        $events = $enrollments->reservationHistory($id, GETPOSTINT('history_id'));
        print '<h3>'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</h3><table class="liste centpercent">';
        foreach ($events as $event) {
            $meta = json_decode($event->metadata_json, true, 512, JSON_THROW_ON_ERROR);
            print '<tr><td>'.trainingEscape(trainingLocal($event->datec, $session->timezone)).'</td><td>#'.(int) $event->fk_user_actor.'</td><td>'.trainingEscape($langs->trans('TrainingReservationEvent'.ucfirst($event->action))).'</td><td>'.trainingEscape($meta['reason'] ?? '').'</td></tr>';
        }
        print '</table>';
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
llxFooter(); $db->close();
