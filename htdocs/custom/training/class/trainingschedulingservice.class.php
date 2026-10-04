<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingslots.class.php';
final class TrainingSchedulingService
{
    private TrainingStore $s;
    public function __construct(TrainingStore $store) { $this->s = $store; }
    private function allow(string $right): void { $this->s->access->requireDomain('session', $right); }
    public function listForProduct(int $productId): array {
        $this->allow('read'); $this->s->product($productId);
        $sql = 'SELECT s.* FROM '.$this->s->table('session').' s JOIN '.$this->s->table('course_version').' v ON v.rowid=s.fk_course_version AND v.entity=s.entity JOIN '.$this->s->table('course_profile').' p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
        return $this->s->rows($sql.' WHERE s.entity='.$this->s->access->entity().' AND p.fk_product='.$productId.' ORDER BY s.rowid DESC');
    }
    public function detail(int $id): array {
        $this->allow('read'); $session = $this->s->session($id);
        $capacity = $this->s->capacity($id);
        return array('session' => $session, 'occupied' => $capacity['confirmed'], 'reserved' => $capacity['reserved'], 'available' => (int) $session->capacity-$capacity['occupied'], 'capacity_at' => $capacity['at'], 'slots' => $this->slots($id));
    }
    private function slots(int $id): array {
        return $this->s->rows('SELECT * FROM '.$this->s->table('session_slot').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$id.' ORDER BY position');
    }
    public function create(int $versionId, string $ref, string $label, int $capacity, string $timezone): int {
        $this->allow('write'); $this->s->version($versionId);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $ref) || trim($label) === '' || strlen($label) > 255 || $capacity < 1 || $capacity > 10000) { throw new InvalidArgumentException('TrainingInvalidSession'); }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) { throw new InvalidArgumentException('TrainingInvalidTimezone'); }
        return $this->s->transaction(function () use ($versionId, $ref, $label, $capacity, $timezone) {
            $this->s->query('INSERT INTO '.$this->s->table('session').' (entity, ref, label, fk_course_version, capacity, timezone, datec, fk_user_author) VALUES ('.$this->s->access->entity().', '.$this->s->text($ref).', '.$this->s->text(trim($label)).', '.$versionId.', '.$capacity.', '.$this->s->text($timezone).', '.$this->s->now().', '.$this->s->access->actor().')');
            $id = (int) $this->s->db->last_insert_id($this->s->table('session'));
            $this->s->audit('session', $id, 'created', array('version_id' => $versionId, 'capacity' => $capacity)); return $id;
        });
    }
    public function replaceSlots(int $id, array $slots): void {
        $this->allow('write');
        $this->s->transaction(function () use ($id, $slots) {
            $session = $this->s->session($id, true);
            if ($session->status !== 'draft') { throw new RuntimeException('TrainingDraftSessionRequired'); }
            $normalized = TrainingSlots::normalize($slots, $session->timezone);
            $this->s->query('DELETE FROM '.$this->s->table('session_slot').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$id);
            foreach ($normalized as $i => $slot) {
                $this->s->query('INSERT INTO '.$this->s->table('session_slot').' (entity, fk_session, position, start_utc, end_utc) VALUES ('.$this->s->access->entity().', '.$id.', '.($i + 1).', '.$this->s->text(gmdate('Y-m-d H:i:s', $slot['start'])).', '.$this->s->text(gmdate('Y-m-d H:i:s', $slot['end'])).')');
            }
            $this->s->audit('session', $id, 'slots_replaced', array('count' => count($normalized)));
        });
    }
    public function changeStatus(int $id, string $next): void {
        $this->allow('write');
        $this->s->transaction(function () use ($id, $next) {
            $session = $this->s->session($id, true);
            if (!in_array($next, array('open', 'closed', 'completed', 'cancelled'), true)) { throw new RuntimeException('TrainingInvalidTransition'); }
            if ($session->status === $next) { return; }
            $allowed = array(
                'draft' => array('open'),
                'open' => array('closed', 'completed', 'cancelled'),
                'closed' => array('open', 'completed', 'cancelled'),
                'completed' => array('closed'),
                'cancelled' => array('open', 'closed')
            );
            if (!in_array($next, $allowed[$session->status] ?? array(), true)) { throw new RuntimeException('TrainingInvalidTransition'); }
            if ($next === 'open') {
                $slots = $this->slots($id); $minutes = 0;
                foreach ($slots as $slot) {
                    $minutes += (strtotime($slot->end_utc.' UTC') - strtotime($slot->start_utc.' UTC')) / 60;
                }
                if (!$slots || (int) $minutes !== $session->program_duration) { throw new RuntimeException('TrainingSlotDurationMismatch'); }
            }
            $this->s->query('UPDATE '.$this->s->table('session').' SET status='.$this->s->text($next).' WHERE rowid='.$id.' AND entity='.$this->s->access->entity());
            $this->s->audit('session', $id, 'status_changed', array('from' => $session->status, 'to' => $next));
        });
    }
    public function changeCapacity(int $id, int $capacity): void {
        $this->allow('write');
        if ($capacity < 1 || $capacity > 10000) { throw new InvalidArgumentException('TrainingInvalidSession'); }
        $this->s->transaction(function () use ($id, $capacity) {
            $session = $this->s->session($id, true);
            if ($capacity < $this->s->capacity($id, true)['occupied']) { throw new RuntimeException('TrainingCapacityBelowOccupancy'); }
            if ((int) $session->capacity === $capacity) { return; }
            $this->s->query('UPDATE '.$this->s->table('session').' SET capacity='.$capacity.' WHERE rowid='.$id.' AND entity='.$this->s->access->entity());
            $this->s->audit('session', $id, 'capacity_changed', array('from' => (int) $session->capacity, 'to' => $capacity));
        });
    }
}
