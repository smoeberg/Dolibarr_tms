<?php
/** Disposable CI only: real core bootstrap, native activation, native Product/Contact/User. */
if (getenv('CI') !== 'true') { throw new RuntimeException('Disposable CI environment required'); }
require __DIR__.'/../.ci/dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/lib/ui.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingcatalogservice.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingattendanceservice.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingtrainerservice.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingreportingservice.class.php';
function nativeCheck($ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo 'OK: '.$message.PHP_EOL;
}
function nativeCount(string $table): int {
    global $db;
    $r=$db->query('SELECT COUNT(*) AS n FROM '.$db->prefix().$table);
    nativeCheck((bool) $r, 'Readable table '.$table);
    return (int) $db->fetch_object($r)->n;
}
nativeCheck(DOL_VERSION === '24.0.2' && PHP_VERSION_ID >= 80400, 'Target core and PHP versions');
nativeCheck($user->fetch('', 'admin') > 0 && $user->admin, 'Native administrator created by installer');
$result=activateModule('modTraining');
nativeCheck(empty($result['errors']), 'Native module activation: '.json_encode($result['errors']));
$conf->setValues($db);
$user->getrights();
foreach (array('training','service','societe','facture') as $module) {
    nativeCheck(isModEnabled($module), 'Activated dependency '.$module);
}
nativeCheck(nativeCount('training_course_profile') === 0, 'Clean Training schema');
$r=$db->query("SELECT COUNT(*) AS n FROM ".$db->prefix()."rights_def WHERE module='training'");
nativeCheck((int) $db->fetch_object($r)->n === 24, 'All 24 native permission definitions (incl. B1 checkout rights, 504872-504874)');
$r=$db->query("SELECT COUNT(*) AS n FROM ".$db->prefix()."menu WHERE module='training'");
nativeCheck((int) $db->fetch_object($r)->n === 2, 'Native Training menus registered once');
$product=new Product($db);
$product->ref='TRAINING-CI'; $product->label='Kursus æøå'; $product->description='Standard Dolibarr service';
$product->type=1; $product->status=1; $product->status_buy=0; $product->price=100; $product->price_base_type='HT'; $product->tva_tx=25;
$productId=$product->create($user);
nativeCheck($productId > 0, 'Native service creation: '.$product->error);
$contact=new Contact($db); $contact->firstname='Native'; $contact->lastname='Participant'; $contact->statut=1;
$contactId=$contact->create($user);
nativeCheck($contactId > 0, 'Native contact creation: '.$contact->error);
$access=trainingAccess();
$store=new TrainingStore($db,$access);
$catalog=new TrainingCatalogService($db,$access);
$version=$catalog->createDraft($productId,array('goals'=>'Test','target_audience'=>'CI','units'=>array(array('label'=>'Native runtime','duration_minutes'=>60))));
$catalog->publish($productId,$version);
$scheduling=new TrainingSchedulingService($store);
$session=$scheduling->create($version,'NATIVE-CI','Native installation',1,'Europe/Copenhagen');
$scheduling->replaceSlots($session,array(array('start'=>'2026-10-20T09:00:00+02:00','end'=>'2026-10-20T10:00:00+02:00')));
$scheduling->changeStatus($session,'open');
$enrollments=new TrainingEnrollmentService($store);
$reservation=$enrollments->reserve($session,array($contactId),str_repeat('b',32));
nativeCheck($scheduling->detail($session)['reserved'] === 1,'Native named reservation capacity');
$enrollment=$enrollments->confirmReservation($session,$reservation,'Native coordinator approval')[0];
nativeCheck($scheduling->detail($session)['reserved'] === 0 && $scheduling->detail($session)['occupied'] === 1,'Native conversion replaces reservation without double counting');
$slot=(int) $scheduling->detail($session)['slots'][0]->rowid;
$attendance=new TrainingAttendanceService($store);
(new TrainingTrainerService($store))->change($session,(int) $user->id,0,'active','Native installation test');
nativeCheck(count($attendance->mySessions()) === 1,'Native internal user assignment and own-session listing');
$attendance->record($session,$slot,$enrollment,0,array('status'=>'present'));
nativeCheck((int) $attendance->sheet($session,$slot)['rows'][0]->present_minutes === 60,'Native loaders, booking and attendance round trip');
$report=(new TrainingReportingService($store))->sessions(new TrainingReportFilter(array('search'=>'NATIVE-CI')));
nativeCheck($report['totals']['sessions']===1 && $report['totals']['confirmed']===1 && $report['totals']['reserved']===0,'Native reporting totals match session detail');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingcommercialservice.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/training/class/trainingcommercialcheckoutadapter.class.php';
$company=new Societe($db); $company->name='Native Buyer æøå'; $company->client=1; $company->status=1; $company->code_client='auto';
$companyId=$company->create($user);
nativeCheck($companyId>0,'Native standard third party creation: '.$company->error);
$commercial=new TrainingCommercialService($store);
$commercial->change($session,$enrollment,0,array('buyer'=>$companyId,'payer'=>$companyId,'employer'=>null),'Native commercial registration');
$contact->socid=$companyId;
$contact->update($contactId, $user);
nativeCheck($commercial->detail($session,$enrollment)['parties']['buyer']->nom==='Native Buyer æøå','Native third-party role round trip');

// A8 Fase 3 Step 1-2: native commercial checkout adapter.
// The payment URL factory is injected here so CI verifies the adapter's
// entity-preserving URL contract without depending on external Stripe state.
$nativeProduct=$product;
$nativeProduct->price_ttc=125;
$nativeAdapter=new TrainingCommercialCheckoutAdapter(
    $store,
    null,
    function (int $entity, string $ref): string {
        return 'https://dolibarr.test/public/payment/newpayment.php?invoice='.rawurlencode($ref).'&e='.$entity;
    }
);
$nativeInvoice=$nativeAdapter->createInvoice(array(
    'socid'=>$companyId,
    'product_id'=>$productId,
    'price_ht'=>'100.00000000',
    'price_ttc'=>'125.00000000',
    'tva_tx'=>'25.00000000',
    'currency'=>(string) $conf->currency,
    'qty'=>1,
    'description'=>'Native training checkout'
));
nativeCheck($nativeInvoice['entity']===(int) $conf->entity,'Checkout invoice uses active Dolibarr entity');
nativeCheck($nativeInvoice['product_id']===$productId,'Checkout invoice uses native Product');
nativeCheck($nativeInvoice['price_ht']==='100.00000000' && $nativeInvoice['price_ttc']==='125.00000000','Checkout preserves accepted transaction amount');
nativeCheck($nativeInvoice['invoice_id']>0 && $nativeInvoice['invoice_ref']!=='','Checkout creates validated native invoice');
nativeCheck(strpos($nativeInvoice['payment_url'],'e='.(int) $conf->entity)!==false,'Checkout payment URL preserves entity');

$invoiceCheck=new Facture($db);
nativeCheck($invoiceCheck->fetch((int) $nativeInvoice['invoice_id'])>0 && (int) $invoiceCheck->socid===$companyId,'Native invoice customer is correct');
$invoiceCheck->fetch_lines();
nativeCheck(count($invoiceCheck->lines)===1,'Native checkout invoice has one line');
nativeCheck((int) $invoiceCheck->lines[0]->fk_product===$productId,'Native invoice line points to native Product');
nativeCheck(abs((float) $invoiceCheck->lines[0]->subprice-100.0)<0.000001 && abs((float) $invoiceCheck->total_ttc-125.0)<0.000001,'Native invoice line and total amounts are correct');

// A8 Fase 3 Step 3: checkout session -> native invoice correlation.
// A fresh contact: $contactId is already confirmed into the session above.
$checkoutContact=new Contact($db); $checkoutContact->firstname='Native'; $checkoutContact->lastname='Checkout'; $checkoutContact->statut=1;
$checkoutContactId=$checkoutContact->create($user);
nativeCheck($checkoutContactId > 0, 'Native checkout contact creation: '.$checkoutContact->error);
$checkoutContact->socid=$companyId; $checkoutContact->update($checkoutContactId, $user);
$nativeCheckoutHoldId=(int) $enrollments->reserve($session, array($checkoutContactId), str_repeat('f', 32), 15);
$nativeCheckout=new TrainingCheckoutService($store, null, $nativeAdapter);
$nativeCheckoutResult=$nativeCheckout->createNativeCheckoutSession(
    $session,
    array(array('contact_id'=>$checkoutContactId,'first_name'=>'Native','last_name'=>'Checkout','email'=>'native@example.test','phone'=>'')),
    'native-correlation-key',
    $nativeCheckoutHoldId
);
nativeCheck($nativeCheckoutResult['invoice_id']>0,'Checkout correlation returns native invoice id');
nativeCheck($nativeCheckoutResult['invoice_ref']!=='','Checkout correlation returns native invoice ref');
$correlated=$db->query('SELECT entity, native_commercial_object_type, native_commercial_object_id, native_commercial_object_ref, status FROM '.$db->prefix().'training_checkout_session WHERE rowid='.(int) $nativeCheckoutResult['checkout_session_id']);
$correlatedRow=$db->fetch_object($correlated);
nativeCheck((int) $correlatedRow->entity===(int) $conf->entity,'Checkout correlation entity matches active entity');
nativeCheck($correlatedRow->native_commercial_object_type==='invoice','Checkout correlation records native invoice type');
nativeCheck((int) $correlatedRow->native_commercial_object_id===$nativeCheckoutResult['invoice_id'],'Checkout correlation records native invoice id');
nativeCheck($correlatedRow->native_commercial_object_ref===$nativeCheckoutResult['invoice_ref'],'Checkout correlation records native invoice ref');
nativeCheck($correlatedRow->status==='commercial_created','Checkout state advances only to commercial_created');

$tables=array('training_enrollment_commercial','training_seat_hold','training_seat_member','training_course_profile','training_course_version','training_audit','training_session','training_session_slot','training_learner','training_enrollment','training_attendance','training_billing_line','training_billing_allocation','training_trainer','training_trainer_assignment');
$counts=array(); foreach ($tables as $table) { $counts[$table]=nativeCount($table); }
nativeCheck(unActivateModule('modTraining',0) === '', 'Native deactivation');
$conf->setValues($db);
nativeCheck(!isModEnabled('training'),'Module disabled');
foreach ($tables as $table) { nativeCheck(nativeCount($table)===$counts[$table],'Deactivation retains '.$table); }
$result=activateModule('modTraining');
nativeCheck(empty($result['errors']),'Native reactivation');
$conf->setValues($db); $user->getrights();
foreach ($tables as $table) { nativeCheck(nativeCount($table)===$counts[$table],'Reactivation retains '.$table); }
nativeCheck((int) $attendance->sheet($session,$slot)['rows'][0]->present_minutes === 60,'Attendance retained after reactivation');
$otherContact=new Contact($db); $otherContact->firstname='Native'; $otherContact->lastname='Second participant'; $otherContact->statut=1;
$otherContactId=$otherContact->create($user);
nativeCheck($otherContactId>0,'Second native contact for HTTP reservation form');
$httpSession=$scheduling->create($version,'HTTP-SEAT-CI','HTTP reservation form',2,'Europe/Copenhagen');
$scheduling->replaceSlots($httpSession,array(array('start'=>'2026-10-20T09:00:00+02:00','end'=>'2026-10-20T10:00:00+02:00')));
$scheduling->changeStatus($httpSession,'open');
file_put_contents(__DIR__.'/../.ci/native-fixture.json',json_encode(array('product'=>$productId,'session'=>$session,'slot'=>$slot,'enrollment'=>$enrollment,'company'=>$companyId,'reservation_session'=>$httpSession,'contact'=>$contactId,'other_contact'=>$otherContactId)));
echo 'Native Dolibarr installation and lifecycle checks passed.'.PHP_EOL;
