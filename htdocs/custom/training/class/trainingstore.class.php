<?php
require_once __DIR__.'/trainingaccess.class.php';

/** Shared transaction and access primitives, not a second write API. */
final class TrainingStore
{
    public $db;
    public TrainingAccess $access;
    private $productLoader;
    private $contactLoader;

    public function __construct($db, TrainingAccess $access, ?callable $productLoader = null, ?callable $contactLoader = null)
    {
        $this->db = $db;
        $this->access = $access;
        $this->productLoader = $productLoader ?? function (int $id) {
            require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
            $p = new Product($this->db);
            if ($p->fetch($id) <= 0) { throw new RuntimeException('TrainingServiceNotAccessible'); }
            return $p;
        };
        $this->contactLoader = $contactLoader ?? function (int $id) {
            require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
            $p = new Contact($this->db);
            if ($p->fetch($id) <= 0) { throw new RuntimeException('TrainingContactNotAccessible'); }
            return $p;
        };
    }
    public function table(string $name): string { return $this->db->prefix().'training_'.$name; }
    public function text(string $text): string { return "'".$this->db->escape($text)."'"; }
    public function now(): string { return $this->text($this->db->idate(dol_now())); }
    public function query(string $sql) {
        try { $r = $this->db->query($sql); } catch (Throwable $e) { throw new RuntimeException('TrainingDatabaseError', 0, $e); }
        if (!$r) { throw new RuntimeException('TrainingDatabaseError'); }
        return $r;
    }
    public function rows(string $sql): array {
        $r = $this->query($sql); $rows = array();
        while ($row = $this->db->fetch_object($r)) { $rows[] = $row; }
        return $rows;
    }
    public function transaction(callable $fn) {
        if ($this->db->begin() <= 0) { throw new RuntimeException('TrainingDatabaseError'); }
        try {
            $value = $fn();
            if ($this->db->commit() <= 0) { throw new RuntimeException('TrainingDatabaseError'); }
            return $value;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function product(int $id) {
        $p = ($this->productLoader)($id); $this->access->requireService($p); return $p;
    }
    public function contact(int $id) {
        $this->access->requireContactRead();
        if ($id < 1) { throw new RuntimeException('TrainingContactNotAccessible'); }
        $p = ($this->contactLoader)($id); $this->access->requireContact($p, $this->db); return $p;
    }
    public function version(int $id) {
        $sql = 'SELECT v.*, p.fk_product FROM '.$this->table('course_version').' v JOIN '.$this->table('course_profile').' p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
        $rows = $this->rows($sql.' WHERE v.rowid='.$id.' AND v.entity='.$this->access->entity());
        if (!$rows || $rows[0]->status !== 'published') { throw new RuntimeException('TrainingPublishedVersionRequired'); }
        $this->product((int) $rows[0]->fk_product); return $rows[0];
    }
    public function session(int $id, bool $lock = false) {
        $rows = $this->rows('SELECT * FROM '.$this->table('session').' WHERE rowid='.$id.' AND entity='.$this->access->entity().($lock ? ' FOR UPDATE' : ''));
        if (!$rows) { throw new RuntimeException('TrainingSessionNotFound'); }
        $version = $this->version((int) $rows[0]->fk_course_version);
        $rows[0]->fk_product = (int) $version->fk_product;
        $rows[0]->program_duration = (int) $version->duration_minutes;
        return $rows[0];
    }
    public function occupied(int $id, bool $lock = false): int {
        // Current locking read after locking session. No stale COUNT snapshot.
        return count($this->rows('SELECT rowid FROM '.$this->table('enrollment').' WHERE entity='.$this->access->entity().' AND fk_session='.$id." AND status='confirmed'".($lock ? ' FOR UPDATE' : '')));
    }
    public function decisionTime(): string {
        // Server/database UTC, evaluated after obtaining the session mutex for writes.
        return (string) $this->rows('SELECT UTC_TIMESTAMP() AS decision_utc')[0]->decision_utc;
    }
    public function capacity(int $id, bool $lock = false, ?string $at = null): array {
        $at = $at ?? $this->decisionTime();
        $confirmed = $this->occupied($id, $lock);
        $holds = $this->rows('SELECT rowid, qty FROM '.$this->table('seat_hold').' WHERE entity='.$this->access->entity().' AND fk_session='.$id." AND status='active' AND expires_utc>".$this->text($at).($lock ? ' FOR UPDATE' : ''));
        $reserved = 0; foreach ($holds as $hold) { $reserved += (int) $hold->qty; }
        return array('confirmed'=>$confirmed, 'reserved'=>$reserved, 'occupied'=>$confirmed+$reserved, 'at'=>$at);
    }
    public function audit(string $type, int $id, string $action, array $metadata): void {
        $this->query('INSERT INTO '.$this->table('audit').' (entity, object_type, fk_object, action, fk_user_actor, datec, metadata_json) VALUES ('.$this->access->entity().', '.$this->text($type).', '.$id.', '.$this->text($action).', '.$this->access->actor().', '.$this->now().', '.$this->text(json_encode($metadata, JSON_THROW_ON_ERROR)).')');
    }
}
