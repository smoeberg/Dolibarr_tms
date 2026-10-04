<?php
/** Immutable request context, shared by UI and service entry points. */
final class TrainingAccess
{
    private $user;
    private int $entity;
    private array $productEntities;

    public function __construct($user, int $entity, string $productEntities)
    {
        if ($entity < 1 || !preg_match('/^\d+(,\d+)*$/D', $productEntities)) {
            throw new InvalidArgumentException('TrainingInvalidEntity');
        }
        $this->user = $user;
        $this->entity = $entity;
        $this->productEntities = array_map('intval', explode(',', $productEntities));
    }

    public function requireRight(string $right): void
    {
        if (empty($this->user->id) || !empty($this->user->socid)
            || !$this->user->hasRight('service', 'lire')
            || !$this->user->hasRight('training', 'course', 'read')
            || !$this->user->hasRight('training', 'course', $right)) {
            throw new RuntimeException('TrainingAccessDenied');
        }
    }

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
