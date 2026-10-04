<?php
require_once __DIR__.'/../htdocs/custom/training/class/trainingbillingservice.class.php';
// Use the actual target version's standard table definitions, not approximate field stubs.
foreach (array('facture','facturedet') as $table) {
    $file = __DIR__.'/../.ci/dolibarr/htdocs/install/mysql/tables/llx_'.$table.'.sql';
    if (!is_file($file)) { throw new RuntimeException('Official invoice schema missing: '.$file); }
    $sql = str_replace('llx_', 'tst_', file_get_contents($file));
    $db->connection->multi_query($sql);
    do { if ($result = $db->connection->store_result()) { $result->free(); } } while ($db->connection->more_results() && $db->connection->next_result());
}
$sql = str_replace('llx_', 'tst_', file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_zbilling.sql'));
for ($i=0; $i<2; $i++) {
    $db->connection->multi_query($sql);
    do { if ($result = $db->connection->store_result()) { $result->free(); } } while ($db->connection->more_results() && $db->connection->next_result());
}
$billing = new TrainingBillingService($store, 'DKK');
$billingSession = $scheduling->create($first, 'BILLING-01', 'Invoice allocation test', 3, 'Europe/Copenhagen');
$scheduling->replaceSlots($billingSession, $slots); $scheduling->changeStatus($billingSession, 'open');
$be1 = $enrollments->confirm($billingSession, 21); $be2 = $enrollments->confirm($billingSession, 22); $be3 = $enrollments->confirm($billingSession, 23);
$db->query('INSERT INTO tst_societe VALUES (100,1),(101,3)');
function billingInvoice(MysqlTestDb $db, int $id, int $status=1, int $type=0, int $entity=2, int $customer=99, string $currency='DKK'): void {
    $db->query("INSERT INTO tst_facture (rowid,ref,entity,type,fk_soc,fk_statut,multicurrency_code,multicurrency_tx,total_ht,total_ttc) VALUES (".$id.",'INV-".$id."',".$entity.','.$type.','.$customer.','.$status.",'".$currency."',1,100.00000001,125.00000001)");
    $db->query("INSERT INTO tst_facturedet (rowid,fk_facture,fk_product,product_type,description,qty,subprice,remise_percent,tva_tx,total_ht,total_tva,total_localtax1,total_localtax2,total_ttc) VALUES (".($id+300).','.$id.",11,1,'Standard service invoice',3,33.33333333,0,25,100.00000001,25,0,0,125.00000001)");
}
for ($i=501;$i<=515;$i++) { billingInvoice($db,$i); }
$db->query('UPDATE tst_facture SET fk_statut=0 WHERE rowid=502');
$db->query('UPDATE tst_facture SET fk_statut=3 WHERE rowid=503');
$db->query('UPDATE tst_facture SET type=2 WHERE rowid=504');
$db->query('UPDATE tst_facture SET type=1 WHERE rowid=505');
$db->query('UPDATE tst_facture SET type=3 WHERE rowid=506');
$db->query("UPDATE tst_facture SET multicurrency_code='EUR',multicurrency_tx=7.45 WHERE rowid=507");
$db->query('UPDATE tst_facture SET fk_soc=100 WHERE rowid=508');
$db->query('UPDATE tst_facture SET entity=1 WHERE rowid=509');
$db->query('UPDATE tst_facture SET fk_soc=101 WHERE rowid=510');
$db->query('UPDATE tst_facturedet SET fk_product=12 WHERE rowid=811');
$weights = array($be1=>1,$be2=>1,$be3=>1);
foreach (array(502,503,504,505,506,507) as $invoice) { attendanceReject(fn() => $billing->allocate($billingSession,$invoice,$invoice+300,0,$weights), 'TrainingInvoiceNotEligible'); }
attendanceReject(fn() => $billing->allocate($billingSession,509,809,0,$weights), 'TrainingInvoiceNotAccessible');
attendanceReject(fn() => $billing->allocate($billingSession,510,810,0,$weights), 'TrainingInvoiceNotAccessible');
attendanceReject(fn() => $billing->allocate($billingSession,511,811,0,$weights), 'TrainingInvoiceProductMismatch');
attendanceReject(fn() => $billing->allocate($billingSession,501,812,0,$weights), 'TrainingInvoiceLineNotFound');
$restrictedBilling = new TrainingBillingService(bookingStore($db,2,new RestrictedBookingUser()),'DKK');
attendanceReject(fn() => $restrictedBilling->inspect($billingSession,508,808), 'TrainingInvoiceNotAccessible');
$db->query('INSERT INTO tst_societe_commerciaux VALUES (100,7)');
verify($restrictedBilling->inspect($billingSession,508,808)['source']['eligible'], 'standard commercial customer assignment grants invoice access');
class NoInvoiceReadUser extends MysqlTestUser {
    public function hasRight($module, ...$keys) { return !($module === 'facture' && $keys === array('lire')); }
}
attendanceReject(fn() => (new TrainingBillingService(bookingStore($db,2,new NoInvoiceReadUser()),'DKK'))->allocate($billingSession,501,801,0,$weights), 'TrainingInvoiceNotAccessible');
class NoBillingUser extends MysqlTestUser {
    public function hasRight($module, ...$keys) { return !($module === 'training' && $keys[0] === 'billing'); }
}
attendanceReject(fn() => (new TrainingBillingService(bookingStore($db,2,new NoBillingUser()),'DKK'))->participants($billingSession), 'TrainingAccessDenied');
$count = $db->count('training_audit');
$allocated = $billing->allocate($billingSession,501,801,0,$weights);
verify($db->count('training_audit') === $count+1 && $billing->inspect($billingSession,501,801)['reconciled'], 'allocation creates one audited reconciled line');
$sum = $db->query('SELECT SUM(amount_ht) AS ht,SUM(amount_ttc) AS ttc FROM tst_training_billing_allocation WHERE fk_billing_line='.$allocated['id'])->fetch_object();
verify($sum->ht === '100.00000001' && $sum->ttc === '125.00000001', 'one standard line split over three named participants reconciles exactly');
verify($billing->allocate($billingSession,501,801,1,$weights) === $allocated && $db->count('training_audit') === $count+1, 'repeated current allocation is idempotent');
attendanceReject(fn() => $billing->allocate($billingSession,501,801,0,$weights), 'TrainingBillingConflict');
attendanceReject(fn() => $billing->allocate($billingSession,501,801,1,array($be1=>1)), 'TrainingBillingReasonRequired');
attendanceReject(fn() => $billing->allocate($billingSession,501,801,1,array($enrollment1=>1),'Wrong hold'), 'TrainingEnrollmentNotFound');
class NoBillingCorrectionUser extends MysqlTestUser {
    public function hasRight($module, ...$keys) { return !($module === 'training' && $keys === array('billing','correct')); }
}
attendanceReject(fn() => (new TrainingBillingService(bookingStore($db,2,new NoBillingCorrectionUser()),'DKK'))->allocate($billingSession,501,801,1,array($be1=>1),'Not permitted'), 'TrainingAccessDenied');
$db->failAudit = true;
attendanceReject(fn() => $billing->allocate($billingSession,501,801,1,array($be1=>1),'Audit rollback'), 'TrainingDatabaseError');
attendanceReject(fn() => $billing->allocate($billingSession,512,812,0,$weights), 'TrainingDatabaseError');
$db->failAudit = false;
verify($db->count('training_billing_line') === 1 && count($billing->inspect($billingSession,501,801)['shares']) === 3 && $billing->inspect($billingSession,501,801)['revision'] === 1, 'failed audit restores original shares and removes failed first mapping');
$billing->allocate($billingSession,501,801,1,array($be1=>2,$be2=>1),'Customer confirms revised split');
verify($billing->inspect($billingSession,501,801)['revision'] === 2 && count($billing->history($billingSession,501,801)) === 2, 'correction replaces full shares with before/after audit');
$enrollments->cancel($billingSession,$be1,'Cancelled after invoice allocation');
verify(count($billing->inspect($billingSession,501,801)['shares']) === 2 && $billing->inspect($billingSession,501,801)['reconciled'], 'cancellation preserves invoice allocations and never implies a credit');
attendanceReject(fn() => $billing->allocate($billingSession,512,812,0,array($be1=>1)), 'TrainingBillingConfirmedRequired');
$billing->allocate($billingSession,501,801,2,array($be2=>1),'Remove cancelled participant from mapping only');
verify(count($billing->history($billingSession,501,801)) === 3, 'explicit correction retains removed participant in audit');
$db->query('UPDATE tst_facture SET fk_statut=2 WHERE rowid=501');
verify($billing->inspect($billingSession,501,801)['reconciled'], 'invoice closure does not change immutable line snapshot or imply participant payment');
$db->query('UPDATE tst_facturedet SET total_ht=99 WHERE rowid=801');
verify(!$billing->inspect($billingSession,501,801)['reconciled'], 'changed standard line is flagged unreconciled');
attendanceReject(fn() => $billing->allocate($billingSession,501,801,3,array($be2=>1),'Drift'), 'TrainingBillingSourceChanged');
$db->query('UPDATE tst_facturedet SET total_ht=100.00000001,fk_product=12 WHERE rowid=801');
verify(!$billing->inspect($billingSession,501,801)['reconciled'], 'changed product remains visible as reconciliation warning');
$db->query('UPDATE tst_facturedet SET fk_product=11 WHERE rowid=801');
$db->query('UPDATE tst_facture SET fk_statut=0 WHERE rowid=501');
verify(!$billing->inspect($billingSession,501,801)['reconciled'], 'reopened draft is excluded from reconciled invoicing');
$db->query('UPDATE tst_facture SET fk_statut=2 WHERE rowid=501');
attendanceReject(fn() => (new TrainingBillingService(bookingStore($db,1),'DKK'))->inspect($billingSession,501,801), 'TrainingSessionNotFound');
$otherHold = $scheduling->create($first,'BILLING-OTHER','Other hold',1,'Europe/Copenhagen');
$scheduling->replaceSlots($otherHold,$slots);$scheduling->changeStatus($otherHold,'open');$otherEnrollment=$enrollments->confirm($otherHold,24);
attendanceReject(fn() => $billing->allocate($otherHold,501,801,0,array($otherEnrollment=>1)), 'TrainingInvoiceLineAlreadyAllocated');
// Two independent connections compete for the same line, including across different holds.
$raceInvoice=513;
$results=raceBookings($billingSession,array(array('billing',$raceInvoice),array('billing',$raceInvoice+10000)));
verify(count(array_filter($results,fn($r)=>$r['status']==='allocated'))===1 && count(array_filter($results,fn($r)=>($r['reason']??'')==='TrainingBillingConflict'))===1, 'same invoice line race has one mapping and one revision conflict');
$results=raceBookings($billingSession,array(array('billing-revise',513),array('billing-revise',10513)));
verify(count(array_filter($results,fn($r)=>$r['status']==='allocated'))===1 && count(array_filter($results,fn($r)=>($r['reason']??'')==='TrainingBillingConflict'))===1, 'concurrent corrections cannot overwrite each other');
$results=raceBookings($billingSession,array(array('billing-correct',514),array('billing-other',514)));
verify(count(array_filter($results,fn($r)=>$r['status']==='allocated'))===1 && count(array_filter($results,fn($r)=>($r['reason']??'')==='TrainingInvoiceLineAlreadyAllocated'))===1, 'different holds cannot allocate the same invoice line twice');
attendanceReject(fn() => (new TrainingBillingService(bookingStore($db,2,new NoBillingCorrectionUser()),'DKK'))->voidLine($billingSession,501,801,3,'Unauthorized'), 'TrainingAccessDenied');
$db->failAudit=true;
attendanceReject(fn() => $billing->voidLine($billingSession,501,801,3,'Rollback void'), 'TrainingDatabaseError');
$db->failAudit=false;
verify($billing->inspect($billingSession,501,801)['mapping']->status === 'active', 'void audit failure preserves active allocation');
$voided=$billing->voidLine($billingSession,501,801,3,'Wrong manual association');
verify(!$billing->inspect($billingSession,501,801)['reconciled'] && count($billing->inspect($billingSession,501,801)['shares'])===1 && count($billing->history($billingSession,501,801))===4, 'void excludes active invoicing and preserves shares and audit');
verify($billing->voidLine($billingSession,501,801,4,'Repeated request') === $voided && count($billing->history($billingSession,501,801))===4, 'repeated current void is a no-op');
attendanceReject(fn() => $billing->voidLine($billingSession,501,801,3,'Stale'), 'TrainingBillingConflict');
$db->query('UPDATE tst_facturedet SET total_ht=99,total_ttc=123.75 WHERE rowid=801');
$billing->allocate($billingSession,501,801,4,array($be2=>1),'Explicit reactivation after invoice correction');
verify($billing->inspect($billingSession,501,801)['reconciled'] && $billing->inspect($billingSession,501,801)['revision']===5, 'explicit reactivation captures changed standard source');
$history=$billing->history($billingSession,501,801);
$last=json_decode(end($history)->metadata_json,true);
verify($last['before_source']['line']['total_ht']==='100.00000001' && $last['after_source']['line']['total_ht']==='99.00000000', 'reactivation retains both old and new invoice snapshots in audit');
// Assert this service never changed any invoice figures or created another economic master.
verify($db->count('facture') === 15 && $db->query('SELECT total_ht,total_ttc FROM tst_facture WHERE rowid=501')->fetch_object()->total_ht == 100.00000001, 'allocation leaves standard invoice totals and document count unchanged');
echo 'All billing MySQL tests passed.'.PHP_EOL;
