<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingdolibarrintegrationservice.class.php';
$access = trainingAccess();
try { $access->requireDomain('session', 'read'); } catch (Throwable $e) { accessforbidden(); }
$store = new TrainingStore($db, $access);
$scheduling = new TrainingSchedulingService($store);
$enrollments = new TrainingEnrollmentService($store);
$nativeIntegration = new TrainingDolibarrIntegrationService($store);
$id = GETPOSTINT('id');
try { $detail = $scheduling->detail($id); } catch (Throwable $e) { accessforbidden(); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch (GETPOST('action', 'aZ09')) {
            case 'slots':
                $lines = preg_split('/\R/', trim(GETPOST('slots', 'alphanohtml'))); $slots = array();
                foreach ($lines as $line) {
                    if (trim($line) === '') { continue; }
                    $parts = explode(';', $line);
                    if (count($parts) !== 2) { throw new InvalidArgumentException('TrainingInvalidSlots'); }
                    $slots[] = array('start' => trim($parts[0]), 'end' => trim($parts[1]));
                }
                $scheduling->replaceSlots($id, $slots); break;
            case 'status': $scheduling->changeStatus($id, GETPOST('next', 'aZ09')); break;
            case 'capacity': $scheduling->changeCapacity($id, trainingCapacity()); break;
            case 'confirm': $enrollments->confirm($id, GETPOSTINT('contact_id')); break;
            case 'cancel': $enrollments->cancel($id, GETPOSTINT('enrollment_id'), GETPOST('reason', 'alphanohtml')); break;
            case 'sync_native': $nativeIntegration->syncSessionAgenda($id); break;
            default: throw new RuntimeException('TrainingInvalidTransition');
        }
        header('Location: '.dol_buildpath('/training/session.php', 1).'?id='.$id); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
try { $detail = $scheduling->detail($id); } catch (Throwable $e) { accessforbidden(); }
$session = $detail['session'];
llxHeader('', $langs->trans('TrainingSession'));
if ($user->hasRight('training','enrollment','read')) { print '<p><a href="'.dol_buildpath('/training/overview.php',1).'">'.trainingEscape($langs->trans('TrainingOverview')).'</a></p>'; }
print '<a href="'.dol_buildpath('/training/sessions.php', 1).'?product_id='.$session->fk_product.'">'.$langs->trans('TrainingSessions').'</a>';
print '<h2>'.trainingEscape($session->ref).' — '.trainingEscape($session->label).'</h2>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
print '<p>'.trainingEscape($langs->trans('TrainingSession'.ucfirst($session->status))).' · '.trainingEscape($langs->trans('TrainingCapacitySummary', $detail['occupied'], $detail['reserved'], $detail['available'], $session->capacity)).' · '.trainingEscape($session->timezone).'</p>';
if ($user->hasRight('training', 'enrollment', 'read')) {
    print '<p><a href="'.dol_buildpath('/training/reservations.php', 1).'?id='.$id.'">'.trainingEscape($langs->trans('TrainingReservations')).'</a></p>';
}
if ($user->hasRight('training', 'billing', 'read') && $user->hasRight('facture', 'lire')) {
    print '<p><a href="'.dol_buildpath('/training/billing.php', 1).'?id='.$id.'">'.trainingEscape($langs->trans('TrainingBilling')).'</a></p>';
}
if ($user->hasRight('training', 'trainer', 'read') && $user->hasRight('user', 'user', 'lire')) {
    print '<p><a href="'.dol_buildpath('/training/trainers.php', 1).'?id='.$id.'">'.trainingEscape($langs->trans('TrainingTrainers')).'</a></p>';
}
print '<h3>'.$langs->trans('TrainingSlots').'</h3><ul>';
$slotText = array();
foreach ($detail['slots'] as $slot) {
    print '<li>'.trainingEscape(trainingLocal($slot->start_utc, $session->timezone)).' — '.trainingEscape(trainingLocal($slot->end_utc, $session->timezone)).($user->hasRight('training', 'attendance', 'read') ? ' <a href="'.dol_buildpath('/training/attendance.php', 1).'?id='.$id.'&slot_id='.(int) $slot->rowid.'">'.trainingEscape($langs->trans('TrainingAttendance')).'</a>' : '').'</li>';
    $zone = new DateTimeZone($session->timezone);
    $start = (new DateTimeImmutable($slot->start_utc, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
    $end = (new DateTimeImmutable($slot->end_utc, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
    $slotText[] = $start.';'.$end;
}
print '</ul>';
if ($user->hasRight('training', 'session', 'write')) {
    if ($session->status === 'draft') {
        trainingForm('slots', $id);
        print '<p>'.$langs->trans('TrainingSlotInputHelp').'</p><textarea name="slots" rows="5" class="quatrevingtpercent">'.trainingEscape(GETPOSTISSET('slots') ? GETPOST('slots', 'alphanohtml') : implode("\n", $slotText)).'</textarea><br><button class="button">'.$langs->trans('Save').'</button></form>';
    }
    trainingForm('status', $id);
    $allowedTransitions = array(
        'draft' => array('open'),
        'open' => array('closed', 'completed', 'cancelled'),
        'closed' => array('open', 'completed', 'cancelled'),
        'completed' => array('closed'),
        'cancelled' => array('open', 'closed')
    );
    $nextOptions = $allowedTransitions[$session->status] ?? array();
    if (!empty($nextOptions)) {
        if (count($nextOptions) === 1) {
            $next = $nextOptions[0];
            print '<input type="hidden" name="next" value="'.$next.'"><button class="button">'.$langs->trans('TrainingSession'.ucfirst($next)).'</button></form>';
        } else {
            print '<select name="next" class="flat">';
            foreach ($nextOptions as $option) {
                print '<option value="'.$option.'">'.$langs->trans('TrainingSession'.ucfirst($option)).'</option>';
            }
            print '</select> <button class="button">'.$langs->trans('TrainingChangeStatus').'</button></form>';
        }
    }
    trainingForm('capacity', $id);
    print '<label>'.$langs->trans('TrainingCapacity').' <input name="capacity" type="number" min="1" max="10000" value="'.(int) $session->capacity.'"></label> <button class="button">'.$langs->trans('Save').'</button></form>';
}
if ($user->hasRight('training', 'enrollment', 'read')) {
    print '<h3>'.$langs->trans('TrainingEnrollments').'</h3><p>'.$langs->trans('TrainingAdministrativeBooking').'</p>';
    try { $rows = $enrollments->listForSession($id); } catch (Throwable $e) { $rows = array(); print '<div class="error">'.trainingError($e).'</div>'; }
    print '<table class="liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('TrainingParticipant').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('TrainingPrice').'</th><th></th></tr>';
    foreach ($rows as $row) {
        $name = $row->contact ? trim($row->contact->firstname.' '.$row->contact->lastname) : $langs->trans('TrainingRestrictedContact');
        $priceDisplay = '';
        if ($row->is_price_frozen && $row->price_ttc !== null) {
            $priceDisplay = $row->price_ttc.' '.$row->currency.' ('.($row->is_price_frozen ? $langs->trans('TrainingPriceFrozen') : $langs->trans('TrainingPriceCurrent')).')';
        } elseif ($row->price_ttc !== null) {
            $priceDisplay = $row->price_ttc.' '.$row->currency;
        }
        print '<tr><td>'.trainingEscape($name).'</td><td>'.trainingEscape($langs->trans('TrainingEnrollment'.ucfirst($row->status))).'</td><td>'.trainingEscape($priceDisplay).'</td><td>';
        if ($row->status === 'confirmed' && $row->contact && $user->hasRight('training', 'enrollment', 'write')) {
            trainingForm('cancel', $id);
            print '<input type="hidden" name="enrollment_id" value="'.(int) $row->rowid.'"><input name="reason" required placeholder="'.trainingEscape($langs->trans('TrainingCancellationReason')).'"><button class="button">'.$langs->trans('TrainingCancelEnrollment').'</button></form>';
        }
        if ($row->contact && $user->hasRight('training','commercial','read') && $user->hasRight('societe','lire')) { print ' <a href="'.dol_buildpath('/training/commercial.php',1).'?id='.$id.'&enrollment_id='.(int) $row->rowid.'">'.trainingEscape($langs->trans('TrainingCommercial')).'</a>'; }
        print '</td></tr>';
    }
    print '</table>';
    if ($session->status === 'open' && $user->hasRight('training', 'enrollment', 'write')) {
        $search = GETPOST('contact_search', 'alphanohtml');
        print '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>'.$langs->trans('TrainingFindContact').' <input name="contact_search" value="'.trainingEscape($search).'"></label> <button class="button">'.$langs->trans('Search').'</button></form>';
        try { $contacts = $enrollments->contactChoices($search); } catch (Throwable $e) { $contacts = array(); print '<div class="error">'.trainingError($e).'</div>'; }
        trainingForm('confirm', $id);
        print '<select name="contact_id" required><option value="">'.$langs->trans('TrainingSelectContact').'</option>';
        foreach ($contacts as $contact) { print '<option value="'.(int) $contact->id.'">'.trainingEscape(trim($contact->firstname.' '.$contact->lastname)).'</option>'; }
        print '</select> <button class="button">'.$langs->trans('TrainingConfirmEnrollment').'</button></form>';
    }
}
llxFooter(); $db->close();
