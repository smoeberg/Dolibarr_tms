<?php
/** Idempotency gate for the real public-checkout billing identity. */
if (getenv('CI') !== 'true') {
    throw new RuntimeException('Disposable CI environment required');
}
require __DIR__.'/../.ci/dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingcheckoutcustomerservice.class.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
$first = trim((string) ($argv[2] ?? ''));
$last = trim((string) ($argv[3] ?? ''));
$phone = trim((string) ($argv[4] ?? ''));
if ($email === '' || $first === '' || $last === '') {
    throw new RuntimeException('Billing identity arguments are required');
}
if ($user->fetch('', 'admin') <= 0 || !$user->admin) {
    throw new RuntimeException('Native administrator unavailable');
}
$service = new TrainingCheckoutCustomerService($db, $user, (int) $conf->entity);
$one = $service->resolveBillingCustomer(array(
    'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone
));
$two = $service->resolveBillingCustomer(array(
    'first_name' => $first, 'last_name' => $last, 'email' => strtoupper($email), 'phone' => $phone
));
if ((int) $one['customer_id'] !== (int) $two['customer_id']
    || (int) $one['contact_id'] !== (int) $two['contact_id']) {
    throw new RuntimeException('Repeated billing resolution changed customer/contact identity');
}
$companySql = 'SELECT COUNT(*) AS n FROM '.$db->prefix().'societe WHERE entity='.(int) $conf->entity.' AND LOWER(email)='.$db->quote($email);
$contactSql = 'SELECT COUNT(*) AS n FROM '.$db->prefix().'socpeople WHERE entity='.(int) $conf->entity.' AND LOWER(email)='.$db->quote($email);
$companyResult = $db->query($companySql);
$contactResult = $db->query($contactSql);
if (!$companyResult || !$contactResult) {
    throw new RuntimeException('Could not inspect idempotency rows');
}
$companyCount = (int) $db->fetch_object($companyResult)->n;
$contactCount = (int) $db->fetch_object($contactResult)->n;
if ($companyCount !== 1 || $contactCount !== 1) {
    throw new RuntimeException('Expected one customer and one contact for email; company_count='.$companyCount.' contact_count='.$contactCount);
}
$linked = $db->query('SELECT rowid FROM '.$db->prefix().'socpeople WHERE rowid='.(int) $one['contact_id'].' AND fk_soc='.(int) $one['customer_id'].' AND LOWER(email)='.$db->quote($email));
if (!$linked || !$db->fetch_object($linked)) {
    throw new RuntimeException('Billing contact is not linked to its idempotent customer');
}
echo "OK: repeated billing callback reuses one customer and one linked contact\n";
