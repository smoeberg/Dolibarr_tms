<?php
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

/**
 * Idempotent public-checkout customer resolution.
 *
 * MySQL advisory locks serialize creation for a normalized email, including
 * the no-row-yet case that a normal SELECT ... FOR UPDATE cannot protect.
 */
final class TrainingCheckoutCustomerService
{
    private $db;
    private $user;
    private int $entity;

    public function __construct($db, $user, int $entity)
    {
        $this->db = $db;
        $this->user = $user;
        $this->entity = $entity;
    }

    private function emailKey(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('TrainingCheckoutInvalidEmail');
        }
        return $email;
    }

    private function withEmailLock(string $email, callable $callback)
    {
        $email = $this->emailKey($email);
        $lockName = 'training_checkout_'.substr(hash('sha256', $this->entity.':'.$email), 0, 40);
        $lockSql = "SELECT GET_LOCK('".$this->db->escape($lockName)."', 10) AS acquired";
        $lockResult = $this->db->query($lockSql);
        $lock = $lockResult ? $this->db->fetch_object($lockResult) : null;
        if (!$lock || (int) $lock->acquired !== 1) {
            throw new RuntimeException('TrainingCheckoutCustomerLockUnavailable');
        }
        try {
            return $callback($email);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('".$this->db->escape($lockName)."')");
        }
    }

    private function findContactByEmail(string $email)
    {
        $entities = getEntity('contact');
        if (!preg_match('/^\d+(,\d+)*$/', (string) $entities)) {
            throw new RuntimeException('TrainingCheckoutCustomerLookupFailed');
        }
        $sql = 'SELECT rowid, fk_soc FROM '.$this->db->prefix().'socpeople'.
            ' WHERE LOWER(email)='.$this->db->quote($email).
            ' AND entity IN ('.$entities.') ORDER BY rowid LIMIT 1';
        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException('TrainingCheckoutCustomerLookupFailed');
        }
        return $this->db->fetch_object($result) ?: null;
    }

    private function findCompanyByEmail(string $email)
    {
        $entities = getEntity('societe');
        if (!preg_match('/^\d+(,\d+)*$/', (string) $entities)) {
            throw new RuntimeException('TrainingCheckoutCustomerLookupFailed');
        }
        $sql = 'SELECT rowid FROM '.$this->db->prefix().'societe'.
            ' WHERE LOWER(email)='.$this->db->quote($email).
            ' AND entity IN ('.$entities.') ORDER BY rowid LIMIT 1';
        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException('TrainingCheckoutCustomerLookupFailed');
        }
        return $this->db->fetch_object($result) ?: null;
    }

    public function resolveBillingCustomer(array $billing): array
    {
        $email = $this->emailKey((string) ($billing['email'] ?? ''));
        $first = trim((string) ($billing['first_name'] ?? ''));
        $last = trim((string) ($billing['last_name'] ?? ''));
        $phone = trim((string) ($billing['phone'] ?? ''));
        if ($first === '' || $last === '' || strlen($first) > 128 || strlen($last) > 128 || strlen($phone) > 64) {
            throw new RuntimeException('TrainingCheckoutInvalidBillingDetails');
        }

        return $this->withEmailLock($email, function (string $email) use ($first, $last, $phone) {
            $contactRow = $this->findContactByEmail($email);
            $companyRow = $this->findCompanyByEmail($email);

            // Existing contact identity takes precedence: never create a second
            // customer for an email already attached to a Dolibarr customer.
            $socid = $contactRow ? (int) $contactRow->fk_soc : 0;
            if ($socid < 1 && $companyRow) {
                $socid = (int) $companyRow->rowid;
            }

            if ($socid < 1) {
                $company = new Societe($this->db);
                $company->name = trim($first.' '.$last);
                $company->client = 1;
                $company->status = 1;
                $company->code_client = 'auto';
                $company->email = $email;
                $company->phone = $phone;
                $created = $company->create($this->user);
                if ($created <= 0) {
                    throw new RuntimeException('TrainingCheckoutCustomerCreateFailed');
                }
                $socid = (int) $created;
            }

            if ($contactRow) {
                $contactId = (int) $contactRow->rowid;
                if ((int) $contactRow->fk_soc > 0 && (int) $contactRow->fk_soc !== $socid) {
                    throw new RuntimeException('TrainingCheckoutCustomerIdentityConflict');
                }
                $contact = new Contact($this->db);
                if ($contact->fetch($contactId) <= 0) {
                    throw new RuntimeException('TrainingCheckoutCustomerLookupFailed');
                }
                if ((int) ($contact->socid ?? 0) < 1) {
                    $contact->socid = $socid;
                    if ($contact->update($contactId, $this->user) <= 0) {
                        throw new RuntimeException('TrainingCheckoutCustomerLinkFailed');
                    }
                }
            } else {
                $contact = new Contact($this->db);
                $contact->firstname = $first;
                $contact->lastname = $last;
                $contact->email = $email;
                $contact->phone_pro = $phone;
                $contact->socid = $socid;
                $contact->statut = 1;
                $contactId = (int) $contact->create($this->user);
                if ($contactId <= 0) {
                    throw new RuntimeException('TrainingCheckoutCustomerContactCreateFailed');
                }
            }

            return array('customer_id' => $socid, 'contact_id' => $contactId);
        });
    }

    public function resolveParticipantContact(array $participant, int $customerId, int $billingContactId): int
    {
        $email = $this->emailKey((string) ($participant['email'] ?? ''));
        $first = trim((string) ($participant['first_name'] ?? ''));
        $last = trim((string) ($participant['last_name'] ?? ''));
        $phone = trim((string) ($participant['phone'] ?? ''));
        if ($first === '' || $last === '' || strlen($first) > 128 || strlen($last) > 128 || strlen($phone) > 64) {
            throw new RuntimeException('TrainingCheckoutInvalidParticipants');
        }

        return (int) $this->withEmailLock($email, function (string $email) use ($first, $last, $phone, $customerId, $billingContactId) {
            $existing = $this->findContactByEmail($email);
            if ($existing) {
                $existingId = (int) $existing->rowid;
                $existingSocid = (int) $existing->fk_soc;
                if ($existingId === $billingContactId && $existingSocid === $customerId) {
                    return $existingId;
                }
                if ($existingSocid !== $customerId) {
                    throw new RuntimeException('TrainingCheckoutCustomerIdentityConflict');
                }
                return $existingId;
            }

            $contact = new Contact($this->db);
            $contact->firstname = $first;
            $contact->lastname = $last;
            $contact->email = $email;
            $contact->phone_pro = $phone;
            $contact->socid = $customerId;
            $contact->statut = 1;
            $id = (int) $contact->create($this->user);
            if ($id <= 0) {
                throw new RuntimeException('TrainingCheckoutCustomerContactCreateFailed');
            }
            return $id;
        });
    }
}
