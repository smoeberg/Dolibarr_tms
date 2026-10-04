<?php
require_once __DIR__.'/lib/bootstrap.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once __DIR__.'/class/trainingcatalogservice.class.php';

if (!isModEnabled('training') || !empty($user->socid) || !$user->hasRight('training', 'course', 'read') || !$user->hasRight('service', 'lire')) {
    accessforbidden();
}
$langs->loadLangs(array('products', 'training@training'));
$id = GETPOSTINT('id');
$object = new Product($db);
if ($id < 1 || $object->fetch($id) <= 0 || (int) $object->type !== Product::TYPE_SERVICE) {
    accessforbidden();
}
restrictedArea($user, 'service', $object->id, 'product&product', '', '');
$access = new TrainingAccess($user, (int) $conf->entity, getEntity('product'));
$catalog = new TrainingCatalogService($db, $access);
try {
    $access->requireService($object);
} catch (Throwable $e) {
    accessforbidden();
}
$action = GETPOST('action', 'aZ09');
$errorKey = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'createDraft') {
            // Small initial UI: one program unit. The service supports ordered multi-unit input.
            $rawMinutes = GETPOST('duration_minutes', 'alphanohtml');
            if (!preg_match('/^[1-9][0-9]{0,4}$/D', $rawMinutes)) {
                throw new InvalidArgumentException('TrainingInvalidUnits');
            }
            $catalog->createDraft($id, array(
                'goals' => GETPOST('goals', 'restricthtml'),
                'prerequisites' => GETPOST('prerequisites', 'restricthtml'),
                'target_audience' => GETPOST('target_audience', 'restricthtml'),
                'modality' => GETPOST('modality', 'aZ09'),
                'units' => array(array('label' => GETPOST('unit_label', 'alphanohtml'), 'duration_minutes' => (int) $rawMinutes)),
            ));
        } elseif ($action === 'publish') {
            $catalog->publish($id, GETPOSTINT('version_id'));
        } else {
            throw new InvalidArgumentException('TrainingInvalidTransition');
        }
        header('Location: '.dol_buildpath('/training/course.php', 1).'?id='.$id);
        exit;
    } catch (Throwable $e) {
        $errorKey = $e->getMessage();
        // Display only known application errors, never SQL/runtime exception details.
        $known = array('TrainingInvalidUnits', 'TrainingInvalidModality', 'TrainingTextTooLong', 'TrainingPublishNeedsGoalsAndAudience', 'TrainingAccessDenied', 'TrainingServiceNotAccessible', 'TrainingVersionNotFound', 'TrainingInvalidTransition', 'TrainingDatabaseError');
        if (!in_array($errorKey, $known, true)) {
            $errorKey = 'TrainingDatabaseError';
        }
    }
}
try {
    $versions = $catalog->versions($id);
} catch (Throwable $e) {
    $versions = array();
    $errorKey = 'TrainingDatabaseError';
}
llxHeader('', $langs->trans('TrainingCourse'));
print dol_get_fiche_head(product_prepare_head($object), 'training', $langs->trans('TrainingCourse'), -1, 'service');
print '<h2>'.dol_escape_htmltag($object->ref).' — '.dol_escape_htmltag($object->label).'</h2>';
print '<p>'.$langs->trans('TrainingStandardMaster').'</p>';
if ($errorKey) {
    print '<div class="error">'.dol_escape_htmltag($langs->trans($errorKey)).'</div>';
}
print '<table class="liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Version').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('TrainingMinutes').'</th><th>'.$langs->trans('TrainingProgram').'</th><th></th></tr>';
foreach ($versions as $version) {
    $program = json_decode($version->program_json, true);
    print '<tr><td>'.((int) $version->version_number).'</td><td>'.dol_escape_htmltag($langs->trans($version->status === 'published' ? 'TrainingPublished' : 'TrainingDraft')).'</td><td>'.((int) $version->duration_minutes).'</td><td>';
    if ($version->status === 'published') {
        print '<strong>'.dol_escape_htmltag($version->product_label_snapshot).'</strong><br>';
    }
    foreach (($program['units'] ?? array()) as $unit) {
        print dol_escape_htmltag($unit['label']).' ('.((int) $unit['duration_minutes']).')<br>';
    }
    print dol_nl2br(dol_escape_htmltag($program['goals'] ?? '')).'</td><td>';
    if ($version->status === 'draft' && $user->hasRight('training', 'course', 'publish')) {
        print '<form method="post" action="'.dol_buildpath('/training/course.php', 1).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="version_id" value="'.((int) $version->rowid).'"><input type="hidden" name="action" value="publish">';
        print '<button class="button" type="submit">'.$langs->trans('TrainingPublish').'</button></form>';
    }
    print '</td></tr>';
}
print '</table>';
if ($user->hasRight('training', 'course', 'write')) {
    print '<h3>'.$langs->trans('TrainingNewDraft').'</h3><form method="post" action="'.dol_buildpath('/training/course.php', 1).'">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="createDraft">';
    foreach (array('goals' => 'TrainingGoals', 'prerequisites' => 'TrainingPrerequisites', 'target_audience' => 'TrainingAudience') as $field => $label) {
        print '<p><label for="'.$field.'">'.$langs->trans($label).'</label><br><textarea id="'.$field.'" name="'.$field.'" rows="3" class="quatrevingtpercent">'.dol_escape_htmltag(GETPOST($field, 'restricthtml')).'</textarea></p>';
    }
    print '<p><label for="modality">'.$langs->trans('TrainingModality').'</label> <select id="modality" name="modality">';
    foreach (array('physical' => 'TrainingPhysical', 'online' => 'TrainingOnline', 'blended' => 'TrainingBlended') as $value => $label) {
        print '<option value="'.$value.'">'.$langs->trans($label).'</option>';
    }
    print '</select></p><p><label for="unit_label">'.$langs->trans('TrainingUnit').'</label> <input id="unit_label" name="unit_label" maxlength="255" required value="'.dol_escape_htmltag(GETPOST('unit_label', 'alphanohtml')).'"></p>';
    print '<p><label for="duration_minutes">'.$langs->trans('TrainingMinutes').'</label> <input id="duration_minutes" name="duration_minutes" type="number" min="1" max="10080" required value="'.dol_escape_htmltag(GETPOST('duration_minutes', 'alphanohtml')).'"></p>';
    print '<button class="button" type="submit">'.$langs->trans('TrainingNewDraft').'</button></form>';
}
print dol_get_fiche_end();
llxFooter();
$db->close();
