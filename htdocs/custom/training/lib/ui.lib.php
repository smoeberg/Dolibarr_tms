<?php
require_once __DIR__.'/bootstrap.lib.php';
require_once __DIR__.'/../class/trainingschedulingservice.class.php';
require_once __DIR__.'/../class/trainingenrollmentservice.class.php';

function trainingAccess(): TrainingAccess {
    global $user, $conf, $langs;
    if (!isModEnabled('training')) { accessforbidden(); }
    $langs->loadLangs(array('products', 'training@training'));
    return new TrainingAccess($user, (int) $conf->entity, getEntity('product'), getEntity('contact'), getEntity('societe'), getEntity('user'));
}
function trainingEscape($text): string { return dol_escape_htmltag((string) $text); }
function trainingCapacity(): int {
    $raw = GETPOST('capacity', 'alphanohtml');
    if (!preg_match('/^[1-9][0-9]{0,4}$/D', $raw) || (int) $raw > 10000) { throw new InvalidArgumentException('TrainingInvalidSession'); }
    return (int) $raw;
}
function trainingError(Throwable $e): string {
    global $langs;
    $key = preg_match('/^Training[A-Za-z]+$/D', $e->getMessage()) ? $e->getMessage() : 'TrainingDatabaseError';
    return trainingEscape($langs->trans($key));
}
function trainingForm(string $action, int $id, string $path = '/training/session.php'): void {
    print '<form method="post" action="'.dol_buildpath($path, 1).'">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.$action.'">';
}
function trainingLocal(string $utc, string $timezone): string {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i P');
}
