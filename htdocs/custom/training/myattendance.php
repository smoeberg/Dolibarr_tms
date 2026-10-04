<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingattendanceservice.class.php';
$attendance = new TrainingAttendanceService(new TrainingStore($db, trainingAccess()));
try { $sessions = $attendance->mySessions(); } catch (Throwable $e) { accessforbidden(); }
llxHeader('', $langs->trans('TrainingMySessions'));
print '<h2>'.trainingEscape($langs->trans('TrainingMySessions')).'</h2><p>'.trainingEscape($langs->trans('TrainingMySessionsHelp')).'</p>';
if (!$sessions) { print '<p>'.trainingEscape($langs->trans('TrainingNoAssignedSessions')).'</p>'; }
foreach ($sessions as $item) {
    $session = $item['session'];
    print '<h3>'.trainingEscape($session->ref).' · '.trainingEscape($session->label).'</h3><p>'.trainingEscape($langs->trans('TrainingSession'.ucfirst($session->status))).' · '.trainingEscape($session->timezone).'</p><ul>';
    foreach ($item['slots'] as $slot) { print '<li><a href="'.dol_buildpath('/training/attendance.php', 1).'?id='.(int) $session->rowid.'&slot_id='.(int) $slot->rowid.'">'.trainingEscape(trainingLocal($slot->start_utc, $session->timezone)).' — '.trainingEscape(trainingLocal($slot->end_utc, $session->timezone)).'</a></li>'; }
    print '</ul>';
}
llxFooter(); $db->close();
