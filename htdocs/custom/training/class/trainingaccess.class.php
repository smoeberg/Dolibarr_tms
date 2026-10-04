<?php
/** Immutable request context, shared by UI and service entry points. */
final class TrainingAccess
{
    private $user;
    private int $entity;
    private array $productEntities;
    private array $contactEntities;
    private array $thirdpartyEntities;

    public function __construct($user, int $entity, string $productEntities, ?string $contactEntities = null, ?string $thirdpartyEntities = null)
    {
        if ($entity < 1 || !preg_match('/^\d+(,\d+)*$/D', $productEntities)) {
            throw new InvalidArgumentException('TrainingInvalidEntity');
        }
        $this->user = $user;
        $this->entity = $entity;
        $this->productEntities = array_map('intval', explode(',', $productEntities));
        $this->contactEntities = $this->entities($contactEntities ?? (string) $entity);
        $this->thirdpartyEntities = $this->entities($thirdpartyEntities ?? (string) $entity);
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

    public function requireContactRead(): void
    {
        if (!$this->user->hasRight('societe', 'contact', 'lire')) { throw new RuntimeException('TrainingContactNotAccessible'); }
    }
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
}
