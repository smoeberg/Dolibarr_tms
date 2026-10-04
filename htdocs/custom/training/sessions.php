<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once __DIR__.'/class/trainingcatalogservice.class.php';
$access = trainingAccess();
try { $access->requireDomain('session', 'read'); } catch (Throwable $e) { accessforbidden(); }
$store = new TrainingStore($db, $access);
$scheduling = new TrainingSchedulingService($store);
$productId = GETPOSTINT('product_id');
try { $object = $store->product($productId); } catch (Throwable $e) { accessforbidden(); }
restrictedArea($user, 'service', $productId, 'product&product', '', '');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (GETPOST('action', 'aZ09') !== 'createSession') { throw new RuntimeException('TrainingInvalidTransition'); }
        $versionId = GETPOSTINT('version_id');
        $version = $store->version($versionId);
        if ((int) $version->fk_product !== $productId) { throw new RuntimeException('TrainingPublishedVersionRequired'); }
        $id = $scheduling->create($versionId, GETPOST('ref', 'alphanohtml'), GETPOST('label', 'alphanohtml'), trainingCapacity(), GETPOST('timezone', 'alphanohtml'));
        header('Location: '.dol_buildpath('/training/session.php', 1).'?id='.$id); exit;
    } catch (Throwable $e) { $error = trainingError($e); }
}
try {
    $sessions = $scheduling->listForProduct($productId);
    $versions = (new TrainingCatalogService($db, $access))->versions($productId);
} catch (Throwable $e) { $sessions = $versions = array(); $error = trainingError($e); }
llxHeader('', $langs->trans('TrainingSessions'));
print dol_get_fiche_head(product_prepare_head($object), 'trainingsessions', $langs->trans('TrainingSessions'), -1, 'service');
print '<h2>'.trainingEscape($object->ref).' — '.trainingEscape($object->label).'</h2>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
print '<table class="liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('TrainingCapacity').'</th></tr>';
foreach ($sessions as $session) {
    print '<tr><td><a href="'.dol_buildpath('/training/session.php', 1).'?id='.(int) $session->rowid.'">'.trainingEscape($session->ref).'</a></td><td>'.trainingEscape($session->label).'</td><td>'.trainingEscape($langs->trans('TrainingSession'.ucfirst($session->status))).'</td><td>'.(int) $session->capacity.'</td></tr>';
}
print '</table>';
if ($user->hasRight('training', 'session', 'write')) {
    print '<h3>'.$langs->trans('TrainingCreateSession').'</h3>';
    trainingForm('createSession', 0, '/training/sessions.php');
    print '<input type="hidden" name="product_id" value="'.$productId.'"><p><label>'.$langs->trans('Version').' <select name="version_id" required>';
    foreach ($versions as $version) {
        if ($version->status === 'published') { print '<option value="'.(int) $version->rowid.'">'.(int) $version->version_number.' — '.trainingEscape($version->product_label_snapshot).'</option>'; }
    }
    print '</select></label></p>';
    foreach (array('ref' => 'Ref', 'label' => 'Label', 'capacity' => 'TrainingCapacity', 'timezone' => 'TrainingTimezone') as $field => $label) {
        $value = GETPOST($field, 'alphanohtml');
        if (!$value) { $value = $field === 'timezone' ? 'Europe/Copenhagen' : ($field === 'capacity' ? '8' : ''); }
        print '<p><label>'.$langs->trans($label).' <input name="'.$field.'" required value="'.trainingEscape($value).'"'.($field === 'capacity' ? ' type="number" min="1" max="10000"' : '').'></label></p>';
    }
    print '<button class="button">'.$langs->trans('TrainingCreateSession').'</button></form>';
}
print dol_get_fiche_end(); llxFooter(); $db->close();
