<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingattendanceservice.class.php';
$access = trainingAccess();
$attendance = new TrainingAttendanceService(new TrainingStore($db, $access));
$id = GETPOSTINT('id'); $slotId = GETPOSTINT('slot_id');
try { $sheet = $attendance->sheet($id, $slotId); $attendanceRights = $attendance->permissions($id); } catch (Throwable $e) { accessforbidden(); }
$error = ''; $conflict = false; $editId = GETPOSTINT('edit_id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = GETPOSTINT('enrollment_id');
    try {
        if (GETPOST('action', 'aZ09') !== 'record') { throw new RuntimeException('TrainingInvalidTransition'); }
        $rawRevision = GETPOST('revision', 'alphanohtml');
        if (!preg_match('/^[0-9]{1,9}$/D', $rawRevision)) { throw new RuntimeException('TrainingAttendanceConflict'); }
        $result = $attendance->record($id, $slotId, $editId, (int) $rawRevision, array('status' => GETPOST('status', 'alphanohtml'), 'arrival_local' => GETPOST('arrival_local', 'alphanohtml'), 'departure_local' => GETPOST('departure_local', 'alphanohtml'), 'arrival_offset' => GETPOST('arrival_offset', 'alphanohtml'), 'departure_offset' => GETPOST('departure_offset', 'alphanohtml')), GETPOST('reason', 'alphanohtml'));
        $_SESSION['training_attendance_saved'] = array('session_id'=>$id, 'slot_id'=>$slotId, 'enrollment_id'=>$editId, 'revision'=>$result['revision']);
        header('Location: '.dol_buildpath('/training/attendance.php', 1).'?id='.$id.'&slot_id='.$slotId); exit;
    } catch (Throwable $e) { $error = trainingError($e); $conflict = $e->getMessage() === 'TrainingAttendanceConflict'; }
}
try { $sheet = $attendance->sheet($id, $slotId); $attendanceRights = $attendance->permissions($id); } catch (Throwable $e) { accessforbidden(); }
$session = $sheet['session']; $slot = $sheet['slot'];
$baseUrl = dol_buildpath('/training/attendance.php', 1).'?id='.$id.'&slot_id='.$slotId;
function trainingAttendanceSummary(?array $state, string $timezone): string {
    global $langs;
    if ($state === null) { return trainingEscape($langs->trans('TrainingAttendanceNot_registered')); }
    $parts = array($langs->trans('TrainingAttendance'.ucfirst($state['status'])));
    if ($state['present_minutes'] !== null) { $parts[] = $langs->trans('TrainingPresentMinutes').': '.$state['present_minutes']; }
    if ($state['late_minutes'] !== null) { $parts[] = $langs->trans('TrainingLateMinutes').': '.$state['late_minutes']; }
    if ($state['arrival_utc'] !== null) {
        $parts[] = trainingLocal($state['arrival_utc'], $timezone).' — '.trainingLocal($state['departure_utc'], $timezone);
    } elseif ($state['status'] === 'present') { $parts[] = $langs->trans('TrainingFullSlotAttestation'); }
    return trainingEscape(implode(' · ', $parts));
}
llxHeader('', $langs->trans('TrainingAttendance'));
print '<a href="'.dol_buildpath('/training/myattendance.php', 1).'">'.trainingEscape($langs->trans('TrainingMySessions')).'</a> · ';
if ($user->hasRight('training', 'session', 'read')) { print '<a href="'.dol_buildpath('/training/session.php', 1).'?id='.$id.'">'.trainingEscape($session->ref).'</a>'; }
else { print trainingEscape($session->ref); }
print '<h2>'.trainingEscape($langs->trans('TrainingAttendance')).'</h2><p>'.trainingEscape(trainingLocal($slot->start_utc, $session->timezone)).' — '.trainingEscape(trainingLocal($slot->end_utc, $session->timezone)).' · '.trainingEscape($session->timezone).'</p>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
if ($conflict) { print '<p><a class="button" href="'.$baseUrl.'">'.trainingEscape($langs->trans('TrainingReloadAttendance')).'</a></p>'; }
$flash = $_SESSION['training_attendance_saved'] ?? null;
if ($flash && $flash['session_id'] === $id && $flash['slot_id'] === $slotId) {
    unset($_SESSION['training_attendance_saved']);
    foreach ($sheet['rows'] as $row) {
        if ((int) $row->enrollment_id !== $flash['enrollment_id']) { continue; }
        print '<div class="ok">'.trainingEscape($langs->trans('TrainingAttendanceSaved')).' '.trainingEscape(trim($row->contact->firstname.' '.$row->contact->lastname)).': '.trainingAttendanceSummary(TrainingAttendanceRecord::state($row), $session->timezone).'</div>';
        if ($row->revision !== $flash['revision']) { print '<div class="warning">'.trainingEscape($langs->trans('TrainingAttendanceChangedAfterSave')).'</div>'; }
    }
}
print '<p>'.trainingEscape($langs->trans('TrainingAttendancePickerHelp')).'</p>';
print '<div class="div-table-responsive"><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('Status')).'</th><th>'.trainingEscape($langs->trans('TrainingArrival')).'</th><th>'.trainingEscape($langs->trans('TrainingDeparture')).'</th><th>'.trainingEscape($langs->trans('TrainingPresentMinutes')).'</th><th>'.trainingEscape($langs->trans('TrainingLateMinutes')).'</th><th></th></tr>';
$editing = null;
foreach ($sheet['rows'] as $row) {
    $canWrite = $session->status !== 'draft' && $attendanceRights['write'] && (!$row->attendance_id || $attendanceRights['correct']);
    if ((int) $row->enrollment_id === $editId && $canWrite && !$conflict) { $editing = $row; }
    print '<tr><td>'.trainingEscape(trim($row->contact->firstname.' '.$row->contact->lastname)).' ('.trainingEscape($langs->trans('TrainingEnrollment'.ucfirst($row->enrollment_status))).')</td><td>'.trainingEscape($langs->trans('TrainingAttendance'.ucfirst($row->status)));
    if ($row->status === 'present' && $row->arrival_utc === null) { print '<br><small>'.trainingEscape($langs->trans('TrainingFullSlotAttestation')).'</small>'; }
    print '</td>';
    foreach (array('arrival_utc','departure_utc') as $field) { print '<td>'.($row->$field === null ? '—' : trainingEscape(trainingLocal($row->$field, $session->timezone))).'</td>'; }
    foreach (array('present_minutes','late_minutes') as $field) { print '<td>'.($row->$field === null ? '—' : (int) $row->$field).'</td>'; }
    print '<td>';
    if ($canWrite) { print '<a class="button" href="'.$baseUrl.'&edit_id='.(int) $row->enrollment_id.'">'.trainingEscape($langs->trans('TrainingEditAttendance')).'</a> '; }
    if ($row->attendance_id) { print '<a href="'.$baseUrl.'&history_id='.(int) $row->enrollment_id.'">'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</a>'; }
    print '</td></tr>';
}
print '</table></div>';
if ($editing) {
    print '<h3>'.trainingEscape($langs->trans('TrainingEditAttendance')).' · '.trainingEscape(trim($editing->contact->firstname.' '.$editing->contact->lastname)).'</h3>';
    trainingForm('record', $id, '/training/attendance.php');
    $posted = $_SERVER['REQUEST_METHOD'] === 'POST';
    $revision = $posted ? GETPOST('revision', 'alphanohtml') : (string) $editing->revision;
    print '<input type="hidden" name="slot_id" value="'.$slotId.'"><input type="hidden" name="enrollment_id" value="'.(int) $editing->enrollment_id.'"><input type="hidden" name="revision" value="'.trainingEscape($revision).'">';
    $selectedStatus = $posted ? GETPOST('status', 'alphanohtml') : $editing->status;
    print '<p><label>'.trainingEscape($langs->trans('Status')).' <select name="status">';
    foreach (array('not_registered','present','absent','excused','late') as $status) { print '<option value="'.$status.'"'.($selectedStatus === $status ? ' selected' : '').'>'.trainingEscape($langs->trans('TrainingAttendance'.ucfirst($status))).'</option>'; }
    print '</select></label></p>';
    $offsets = TrainingLocalTime::offsetChoices($slot->start_utc, $slot->end_utc, $session->timezone);
    foreach (array('arrival','departure') as $field) {
        $utc = $editing->{$field.'_utc'};
        $local = $utc === null ? null : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($session->timezone));
        $value = $posted ? GETPOST($field.'_local', 'alphanohtml') : ($local ? $local->format('Y-m-d\TH:i') : '');
        $offset = $posted ? GETPOST($field.'_offset', 'alphanohtml') : ($local && count($offsets)>1 ? $local->format('P') : '');
        print '<p><label>'.trainingEscape($langs->trans('Training'.ucfirst($field))).' <input type="datetime-local" step="60" name="'.$field.'_local" value="'.trainingEscape($value).'"> '.trainingEscape($session->timezone).'</label> ';
        if (count($offsets)>1) {
            print '<label>'.trainingEscape($langs->trans('TrainingTimeOccurrence')).' <select name="'.$field.'_offset"><option value="">'.trainingEscape($langs->trans('TrainingAutomaticTime')).'</option>';
            foreach ($offsets as $choice) { print '<option value="'.$choice.'"'.($choice === $offset ? ' selected' : '').'>UTC'.$choice.'</option>'; }
            print '</select></label>';
        }
        print '</p>';
    }
    if (count($offsets)>1) { print '<p>'.trainingEscape($langs->trans('TrainingTimeOccurrenceHelp')).'</p>'; }
    if ($editing->attendance_id) { print '<p><label>'.trainingEscape($langs->trans('TrainingCorrectionReason')).' <input name="reason" required maxlength="2000" value="'.trainingEscape($posted ? GETPOST('reason','alphanohtml') : '').'"></label></p>'; }
    print '<button class="button">'.trainingEscape($langs->trans('Save')).'</button> <a href="'.$baseUrl.'">'.trainingEscape($langs->trans('Cancel')).'</a></form>';
}
if (GETPOSTINT('history_id')) {
    print '<h3>'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</h3>';
    try {
        $events = $attendance->history($id, $slotId, GETPOSTINT('history_id'));
        print '<div class="div-table-responsive"><table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('Date')).'</th><th>'.trainingEscape($langs->trans('User')).'</th><th>'.trainingEscape($langs->trans('TrainingBillingBefore')).'</th><th>'.trainingEscape($langs->trans('TrainingBillingAfter')).'</th><th>'.trainingEscape($langs->trans('TrainingCorrectionReason')).'</th></tr>';
        foreach ($events as $event) {
            $meta = json_decode($event->metadata_json, true, 512, JSON_THROW_ON_ERROR);
            print '<tr><td>'.trainingEscape(trainingLocal($event->datec, $session->timezone)).'</td><td>#'.(int) $event->fk_user_actor.'</td><td>'.trainingAttendanceSummary($meta['before'], $session->timezone).'</td><td>'.trainingAttendanceSummary($meta['after'], $session->timezone).'</td><td>'.trainingEscape($meta['reason'] ?? '').'</td></tr>';
        }
        print '</table></div>';
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
llxFooter(); $db->close();
