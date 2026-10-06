<?php
/** Immutable request context, shared by UI and service entry points. */
final class TrainingAccess
{
    private $user;
    private int $entity;
    private array $productEntities;
    private array $contactEntities;
    private array $thirdpartyEntities;
    private array $userEntities;

    public function __construct($user, int $entity, string $productEntities, ?string $contactEntities = null, ?string $thirdpartyEntities = null, ?string $userEntities = null)
    {
        if ($entity < 1 || !preg_match('/^\d+(,\d+)*$/D', $productEntities)) {
            throw new InvalidArgumentException('TrainingInvalidEntity');
        }
        $this->user = $user;
        $this->entity = $entity;
        $this->productEntities = array_map('intval', explode(',', $productEntities));
        $this->contactEntities = $this->entities($contactEntities ?? (string) $entity);
        $this->thirdpartyEntities = $this->entities($thirdpartyEntities ?? (string) $entity);
        $this->userEntities = $this->entities($userEntities ?? (string) $entity);
    }

    private function entities(string $ids): array
    {
        if (!preg_match('/^\d+(,\d+)*$/D', $ids)) { throw new InvalidArgumentException('TrainingInvalidEntity'); }
        return array_map('intval', explode(',', $ids));
    }

    public function requireRight(string $right): void
    {
        $this->requireDomain('course', $right);
    }

    public function requireDomain(string $domain, string $right): void
    {
        if (empty($this->user->id) || !empty($this->user->socid)
            || !$this->user->hasRight('service', 'lire')
            || !$this->user->hasRight('training', 'course', 'read')
            || !$this->user->hasRight('training', $domain, 'read')
            || !$this->user->hasRight('training', $domain, $right)) {
            throw new RuntimeException('TrainingAccessDenied');
        }
    }

    public function attendanceScope(string $right): string
    {
        if (!in_array($right, array('read','write','correct'), true) || empty($this->user->id) || !empty($this->user->socid)
            || !$this->user->hasRight('service', 'lire') || !$this->user->hasRight('training', 'course', 'read')) {
            throw new RuntimeException('TrainingAccessDenied');
        }
        if ($this->user->hasRight('training', 'session', 'read') && $this->user->hasRight('training', 'attendance', 'read') && $this->user->hasRight('training', 'attendance', $right)) { return 'all'; }
        if ($this->user->hasRight('training', 'ownattendance', 'read') && $this->user->hasRight('training', 'ownattendance', $right)) { return 'own'; }
        throw new RuntimeException('TrainingAccessDenied');
    }
    public function requireAttendance(int $sessionId, string $right, $db, bool $lock = false): void
    {
        $this->requireContactRead();
        if ($this->attendanceScope($right) === 'all') { return; }
        $sql = 'SELECT a.rowid FROM '.$db->prefix().'training_trainer_assignment a JOIN '.$db->prefix().'training_trainer t ON t.rowid=a.fk_trainer AND t.entity=a.entity JOIN '.$db->prefix().'user u ON u.rowid=t.fk_user';
        $sql .= ' WHERE a.entity='.$this->entity.' AND a.fk_session='.$sessionId." AND a.status='active' AND t.active=1 AND u.rowid=".$this->actor().' AND u.statut=1 AND (u.fk_soc IS NULL OR u.fk_soc=0) AND u.entity IN ('.$this->userEntityScope().')';
        try { $r = $db->query($sql.($lock ? ' FOR UPDATE' : '')); }
        catch (Throwable $e) { throw new RuntimeException('TrainingDatabaseError', 0, $e); }
        if (!$r) { throw new RuntimeException('TrainingDatabaseError'); }
        if (!$db->fetch_object($r)) { throw new RuntimeException('TrainingAccessDenied'); }
    }
    public function userEntityScope(): string { return implode(',', $this->userEntities); }
    public function requireUserRead(): void {
        if (!$this->user->hasRight('user', 'user', 'lire')) { throw new RuntimeException('TrainingTrainerUserNotAccessible'); }
    }

    public function requireContact($contact, $db): void
    {
        if (!$this->user->hasRight('societe', 'contact', 'lire') || empty($contact->id)
            || !in_array((int) $contact->entity, $this->contactEntities, true)) {
            throw new RuntimeException('TrainingContactNotAccessible');
        }
        $socid = (int) ($contact->socid ?? 0);
        if ($socid > 0) {
            $sql = 'SELECT s.rowid FROM '.$db->prefix().'societe s WHERE s.rowid='.$socid.' AND s.entity IN ('.implode(',', $this->thirdpartyEntities).')';
            if (!$this->user->hasRight('societe', 'client', 'voir')) {
                $sql .= ' AND EXISTS (SELECT 1 FROM '.$db->prefix().'societe_commerciaux sc WHERE sc.fk_soc=s.rowid AND sc.fk_user='.$this->actor().')';
            }
            $r = $db->query($sql);
            if (!$r || !$db->fetch_object($r)) { throw new RuntimeException('TrainingContactNotAccessible'); }
        }
    }

    public function requireInvoiceRead(): void
    {
        if (!$this->user->hasRight('facture', 'lire')) { throw new RuntimeException('TrainingInvoiceNotAccessible'); }
    }
    public function requireBillingCustomer(int $socid, $db): void
    {
        if ($socid < 1) { throw new RuntimeException('TrainingInvoiceNotAccessible'); }
        $sql = 'SELECT s.rowid FROM '.$db->prefix().'societe s WHERE s.rowid='.$socid.' AND s.entity IN ('.implode(',', $this->thirdpartyEntities).')';
        if (!$this->user->hasRight('societe', 'client', 'voir')) {
            $sql .= ' AND EXISTS (SELECT 1 FROM '.$db->prefix().'societe_commerciaux sc WHERE sc.fk_soc=s.rowid AND sc.fk_user='.$this->actor().')';
        }
        $r = $db->query($sql);
        if (!$r || !$db->fetch_object($r)) { throw new RuntimeException('TrainingInvoiceNotAccessible'); }
    }

    public function thirdpartyEntityScope(): string { return implode(',', $this->thirdpartyEntities); }
    public function thirdpartyRestriction(string $alias): string {
        return $this->user->hasRight('societe', 'client', 'voir') ? '' : ' AND EXISTS (SELECT 1 FROM %PREFIX%societe_commerciaux sc WHERE sc.fk_soc='.$alias.'.rowid AND sc.fk_user='.$this->actor().')';
    }
    public function requireThirdpartyRead(): void {
        if (!$this->user->hasRight('societe', 'lire')) { throw new RuntimeException('TrainingThirdpartyNotAccessible'); }
    }

    public function requireContactRead(): void
    {
        if (!$this->user->hasRight('societe', 'contact', 'lire')) { throw new RuntimeException('TrainingContactNotAccessible'); }
    }
    public function productEntityScope(): string { return implode(',', $this->productEntities); }
    public function contactEntityScope(): string { return implode(',', $this->contactEntities); }

    public function requireService($product): void
    {
        if (empty($product->id) || (int) $product->type !== 1
            || !in_array((int) $product->entity, $this->productEntities, true)) {
            throw new RuntimeException('TrainingServiceNotAccessible');
        }
    }

    public function entity(): int { return $this->entity; }
    public function actor(): int { return (int) $this->user->id; }
    public function actorUser() { return $this->user; }
}
