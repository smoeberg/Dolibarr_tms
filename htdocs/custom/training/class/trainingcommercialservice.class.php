<?php
require_once __DIR__.'/trainingstore.class.php';

/** Sole writer of current enrollment role links. No order/price/payment side effects. */
final class TrainingCommercialService
{
    private TrainingStore $store;
    private const ROLES = array('buyer','payer','employer');
    public function __construct(TrainingStore $store) { $this->store = $store; }
    private function authorize(string $right): void {
        $a=$this->store->access;
        $a->requireDomain('session','read'); $a->requireDomain('enrollment','read');
        $a->requireDomain('commercial',$right); $a->requireContactRead(); $a->requireThirdpartyRead();
    }
    private function enrollment(int $sessionId, int $id, bool $lock = false) {
        $s=$this->store;
        $rows=$s->rows('SELECT e.*, l.fk_socpeople FROM '.$s->table('enrollment').' e JOIN '.$s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity WHERE e.entity='.$s->access->entity().' AND e.fk_session='.$sessionId.' AND e.rowid='.$id.($lock ? ' FOR UPDATE' : ''));
        if (!$rows) { throw new RuntimeException('TrainingCommercialEnrollmentNotFound'); }
        $s->contact((int) $rows[0]->fk_socpeople);
        return $rows[0];
    }
    private function thirdparty(int $id, bool $lock = false, bool $active = false) {
        $s=$this->store; $a=$s->access;
        $sql='SELECT t.rowid, t.nom, t.status FROM '.$s->db->prefix().'societe t WHERE t.rowid='.$id.' AND t.entity IN ('.$a->thirdpartyEntityScope().')';
        $sql.=str_replace('%PREFIX%',$s->db->prefix(),$a->thirdpartyRestriction('t'));
        $rows=$s->rows($sql.($lock ? ' FOR UPDATE' : ''));
        if (!$rows || ($active && (int) $rows[0]->status !== 1)) { throw new RuntimeException('TrainingThirdpartyNotAccessible'); }
        return $rows[0];
    }
    private function links($row): array {
        $out=array(); foreach (self::ROLES as $role) { $out[$role]=$row && $row->{'fk_'.$role} !== null ? (int) $row->{'fk_'.$role} : null; } return $out;
    }
    private function checkLinks(array $links): void {
        foreach (array_unique(array_filter($links,fn($id)=>$id !== null)) as $id) { $this->thirdparty($id); }
    }
    private function row(int $id, bool $lock = false) {
        $s=$this->store; $rows=$s->rows('SELECT * FROM '.$s->table('enrollment_commercial').' WHERE entity='.$s->access->entity().' AND fk_enrollment='.$id.($lock ? ' FOR UPDATE' : '')); return $rows[0] ?? null;
    }
    public function detail(int $sessionId, int $enrollmentId): array {
        $this->authorize('read'); $s=$this->store; $session=$s->session($sessionId);
        $enrollment=$this->enrollment($sessionId,$enrollmentId); $row=$this->row($enrollmentId);
        $links=$this->links($row); $parties=array();
        foreach ($links as $role=>$id) { $parties[$role]=$id === null ? null : $this->thirdparty($id); }
        return array('session'=>$session,'enrollment'=>$enrollment,'contact'=>$s->contact((int) $enrollment->fk_socpeople),'revision'=>$row ? (int) $row->revision : 0,'links'=>$links,'parties'=>$parties);
    }
    public function change(int $sessionId, int $enrollmentId, int $revision, array $links, string $reason): int {
        $this->authorize('write');
        if ($revision < 0 || $revision >= 1000000000 || count($links) !== 3 || array_diff(array_keys($links),self::ROLES)) { throw new InvalidArgumentException('TrainingCommercialInvalid'); }
        foreach (self::ROLES as $role) { if (!array_key_exists($role,$links) || ($links[$role] !== null && (!is_int($links[$role]) || $links[$role] < 1))) { throw new InvalidArgumentException('TrainingCommercialInvalid'); } }
        $links=array_replace(array_fill_keys(self::ROLES,null),$links);
        $reason=trim($reason);
        if ($reason === '' || strlen($reason)>2000) { throw new InvalidArgumentException('TrainingCommercialReasonRequired'); }
        $s=$this->store;
        return $s->transaction(function () use ($s,$sessionId,$enrollmentId,$revision,$links,$reason) {
            $s->session($sessionId,true); $e=$this->enrollment($sessionId,$enrollmentId,true);
            if ($e->status !== 'confirmed') { throw new RuntimeException('TrainingCommercialConfirmedRequired'); }
            $row=$this->row($enrollmentId,true); $before=$this->links($row);
            if (($row ? (int) $row->revision : 0) !== $revision) { throw new RuntimeException('TrainingCommercialConflict'); }
            // Existing restricted links cannot be overwritten or cleared without access.
            $this->checkLinks($before);
            if ($row && $before === $links) { return (int) $row->revision; }
            if ($row) { $s->access->requireDomain('commercial','correct'); }
            $ids=array_unique(array_filter(array_values($links),fn($id)=>$id !== null)); sort($ids,SORT_NUMERIC);
            $newIds=array(); foreach (self::ROLES as $role) { if ($links[$role] !== null && $links[$role] !== $before[$role]) { $newIds[]=$links[$role]; } }
            foreach ($ids as $id) { $this->thirdparty($id,true,in_array($id,$newIds,true)); }
            $next=$revision+1; $values=array(); foreach (self::ROLES as $role) { $values[$role]=$links[$role] === null ? 'NULL' : (string) $links[$role]; }
            if ($row) {
                $sql='UPDATE '.$s->table('enrollment_commercial').' SET fk_buyer='.$values['buyer'].', fk_payer='.$values['payer'].', fk_employer='.$values['employer'].', revision='.$next.', changed_at='.$s->now().', fk_user_modifier='.$s->access->actor().' WHERE rowid='.(int) $row->rowid.' AND entity='.$s->access->entity();
            } else {
                $sql='INSERT INTO '.$s->table('enrollment_commercial').' (entity,fk_session,fk_enrollment,fk_buyer,fk_payer,fk_employer,revision,datec,fk_user_author,changed_at,fk_user_modifier) VALUES ('.$s->access->entity().','.$sessionId.','.$enrollmentId.','.$values['buyer'].','.$values['payer'].','.$values['employer'].','.$next.','.$s->now().','.$s->access->actor().','.$s->now().','.$s->access->actor().')';
            }
            $s->query($sql);
            $s->audit('enrollment_commercial',$enrollmentId,'roles_changed',array('session_id'=>$sessionId,'before'=>$before,'after'=>$links,'revision'=>$next,'reason'=>$reason));
            return $next;
        });
    }
    public function choices(string $search): array {
        $this->authorize('read'); $s=$this->store; $search=trim($search);
        if (strlen($search)>200) { throw new InvalidArgumentException('TrainingCommercialInvalid'); }
        $sql='SELECT t.rowid,t.nom FROM '.$s->db->prefix().'societe t WHERE t.status=1 AND t.entity IN ('.$s->access->thirdpartyEntityScope().')';
        $sql.=str_replace('%PREFIX%',$s->db->prefix(),$s->access->thirdpartyRestriction('t'));
        // Escape LIKE wildcard characters too; search remains a literal substring.
        $literal=str_replace(array('!','%','_'),array('!!','!%','!_'),$search);
        $sql.=' AND t.nom LIKE '.$s->text('%'.$literal.'%')." ESCAPE '!' ORDER BY t.nom,t.rowid LIMIT 50";
        return $s->rows($sql);
    }
    public function history(int $sessionId, int $enrollmentId): array {
        $this->detail($sessionId,$enrollmentId); $s=$this->store;
        $events=$s->rows('SELECT * FROM '.$s->table('audit').' WHERE entity='.$s->access->entity()." AND object_type='enrollment_commercial' AND fk_object=".$enrollmentId.' ORDER BY rowid DESC');
        // Validate historical IDs as well; today's ACL must not leak former parties.
        foreach ($events as $event) { $m=json_decode($event->metadata_json,true,512,JSON_THROW_ON_ERROR); $this->checkLinks($m['before']); $this->checkLinks($m['after']); }
        return $events;
    }
}
