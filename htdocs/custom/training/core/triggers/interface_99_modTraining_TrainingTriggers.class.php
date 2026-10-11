<?php
/**
 * Durable handoff for native Dolibarr invoice payment completion.
 *
 * The trigger never confirms a TMS enrollment inside Dolibarr's payment
 * transaction. It only queues the correlated checkout for an authenticated
 * workflow worker, keeping payment recording independent from TMS recovery.
 */
class InterfaceTrainingTriggers
{
    /** @var DoliDB */
    public $db;

    public $error = '';
    public $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function run_trigger($action, $object, $user, $langs, $conf)
    {
        if ($action !== 'BILL_PAYED') {
            return 0;
        }

        $invoiceId = (int) ($object->id ?? 0);
        $entity = (int) ($object->entity ?? $conf->entity ?? 0);
        if ($invoiceId < 1 || $entity < 1) {
            $this->error = 'TrainingNativePaymentTriggerInvalidInvoice';
            return -1;
        }

        $checkoutTable = $this->db->prefix().'training_checkout_session';
        $queueTable = $this->db->prefix().'training_native_payment_queue';
        $invoiceTable = $this->db->prefix().'facture';
        $now = $this->db->idate(dol_now());
        $escapedNow = $this->db->escape($now);

        // Resolve only by the persisted TMS -> native invoice correlation.
        // The invoice must belong to the event entity and still be paid.
        $sql = 'SELECT c.rowid AS checkout_id FROM '.$checkoutTable.' c';
        $sql .= ' INNER JOIN '.$invoiceTable.' f ON f.rowid = c.native_commercial_object_id';
        $sql .= ' AND f.entity = c.entity AND f.entity = '.(int) $entity;
        $sql .= ' WHERE c.entity = '.(int) $entity;
        $sql .= ' AND c.native_commercial_object_type = \'invoice\'';
        $sql .= ' AND c.native_commercial_object_id = '.(int) $invoiceId;
        $sql .= ' AND f.paye = 1';
        $result = $this->db->query($sql);
        if (!$result) {
            $this->error = 'TrainingNativePaymentTriggerLookupFailed';
            return -1;
        }

        while ($row = $this->db->fetch_object($result)) {
            $checkoutId = (int) $row->checkout_id;
            if ($checkoutId < 1) {
                continue;
            }

            $insert = 'INSERT INTO '.$queueTable;
            $insert .= ' (entity, fk_checkout_session, fk_invoice, status, attempts, datec, changed_at) VALUES (';
            $insert .= (int) $entity.', '.$checkoutId.', '.(int) $invoiceId.', \'pending\', 0, ';
            $insert .= '\''.$escapedNow.'\', \''.$escapedNow.'\')';
            $insert .= ' ON DUPLICATE KEY UPDATE changed_at = changed_at';
            if (!$this->db->query($insert)) {
                $this->error = 'TrainingNativePaymentTriggerQueueFailed';
                return -1;
            }
        }

        return 1;
    }
}
