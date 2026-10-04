<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingattendanceservice.class.php';
$access = trainingAccess();
$attendance = new TrainingAttendanceService(new TrainingStore($db, $access));
$id = GETPOSTINT('id'); $slotId = GETPOSTINT('slot_id');
try { $sheet = $attendance->sheet($id, $slotId); } catch (Throwable $e) { accessforbidden(); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (GETPOST('action', 'aZ09') !== 'record') { throw new RuntimeException('TrainingInvalidTransition'); }
        $rawRevision = GETPOST('revision', 'alphanohtml');
        if (!preg_match('/^[0-9]{1,9}$/D', $rawRevision)) { throw new RuntimeException('TrainingAttendanceConflict'); }
        $attendance->record($id, $slotId, GETPOSTINT('enrollment_id'), (int) $rawRevision, array('status' => GETPOST('status', 'alphanohtml'), 'arrival' => GETPOST('arrival', 'alphanohtml'), 'departure' => GETPOST('departure', 'alphanohtml')), GETPOST('reason', 'alphanohtml'));
        header('Location: '.dol_buildpath('/training/attendance.php', 1).'?id='.$id.'&slot_id='.$slotId); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
try { $sheet = $attendance->sheet($id, $slotId); } catch (Throwable $e) { accessforbidden(); }
$session = $sheet['session']; $slot = $sheet['slot'];
llxHeader('', $langs->trans('TrainingAttendance'));
print '<a href="'.dol_buildpath('/training/session.php', 1).'?id='.$id.'">'.trainingEscape($session->ref).'</a>';
print '<h2>'.trainingEscape($langs->trans('TrainingAttendance')).'</h2><p>'.trainingEscape(trainingLocal($slot->start_utc, $session->timezone)).' — '.trainingEscape(trainingLocal($slot->end_utc, $session->timezone)).'</p>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingAttendanceHelp')).'</p>';
print '<table class="liste centpercent"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingParticipant')).'</th><th>'.trainingEscape($langs->trans('Status')).'</th><th>'.trainingEscape($langs->trans('TrainingPresentMinutes')).'</th><th></th></tr>';
foreach ($sheet['rows'] as $row) {
    print '<tr><td>'.trainingEscape(trim($row->contact->firstname.' '.$row->contact->lastname)).' ('.trainingEscape($langs->trans('TrainingEnrollment'.ucfirst($row->enrollment_status))).')</td><td>'.trainingEscape($langs->trans('TrainingAttendance'.ucfirst($row->status))).'</td><td>'.($row->present_minutes === null ? '—' : (int) $row->present_minutes).'</td><td>';
    if ($session->status !== 'draft' && $user->hasRight('training', 'attendance', 'write') && (!$row->attendance_id || $user->hasRight('training', 'attendance', 'correct'))) {
        trainingForm('record', $id, '/training/attendance.php');
        print '<input type="hidden" name="slot_id" value="'.$slotId.'"><input type="hidden" name="enrollment_id" value="'.(int) $row->enrollment_id.'"><input type="hidden" name="revision" value="'.$row->revision.'"><select name="status">';
        foreach (array('not_registered', 'present', 'absent', 'excused', 'late') as $status) { print '<option value="'.$status.'"'.($row->status === $status ? ' selected' : '').'>'.trainingEscape($langs->trans('TrainingAttendance'.ucfirst($status))).'</option>'; }
        print '</select> ';
        foreach (array('arrival', 'departure') as $field) {
            $utc = $row->{$field.'_utc'};
            $value = $utc === null ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($session->timezone))->format('Y-m-d\TH:i:sP');
            print '<label>'.trainingEscape($langs->trans('Training'.ucfirst($field))).' <input name="'.$field.'" value="'.trainingEscape($value).'" placeholder="YYYY-MM-DDTHH:MM:00+02:00"></label> ';
        }
        if ($row->attendance_id) { print '<input name="reason" required maxlength="2000" placeholder="'.trainingEscape($langs->trans('TrainingCorrectionReason')).'"> '; }
        print '<button class="button">'.trainingEscape($langs->trans('Save')).'</button></form>';
    }
    if ($row->attendance_id) { print '<a href="'.dol_buildpath('/training/attendance.php', 1).'?id='.$id.'&slot_id='.$slotId.'&history_id='.(int) $row->enrollment_id.'">'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</a>'; }
    print '</td></tr>';
}
print '</table>';
if (GETPOSTINT('history_id')) {
    print '<h3>'.trainingEscape($langs->trans('TrainingAttendanceHistory')).'</h3>';
    try {
        foreach ($attendance->history($id, $slotId, GETPOSTINT('history_id')) as $event) { print '<pre>'.trainingEscape(json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre>'; }
    } catch (Throwable $e) { print '<div class="error">'.trainingError($e).'</div>'; }
}
llxFooter(); $db->close();
