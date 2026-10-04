<?php
require_once __DIR__.'/trainingstore.class.php';
final class TrainingEnrollmentService
{
    private TrainingStore $s;
    public function __construct(TrainingStore $store) { $this->s = $store; }
    private function allow(string $right): void {
        $this->s->access->requireDomain('session', 'read'); $this->s->access->requireDomain('enrollment', $right);
    }
    public function contactChoices(string $search): array {
        $this->allow('write'); $this->s->access->requireContactRead();
        if (strlen($search) > 100) { throw new InvalidArgumentException('TrainingTextTooLong'); }
        $sql = 'SELECT rowid FROM '.$this->s->db->prefix().'socpeople WHERE statut=1 AND entity IN ('.$this->s->access->contactEntityScope().')';
        if (trim($search) !== '') {
            $like = $this->s->text('%'.trim($search).'%');
            $sql .= ' AND (firstname LIKE '.$like.' OR lastname LIKE '.$like.')';
        }
        $contacts = array();
        foreach ($this->s->rows($sql.' ORDER BY lastname, firstname LIMIT 50') as $row) {
            try { $contacts[] = $this->s->contact((int) $row->rowid); } catch (Throwable $e) { /* do not expose restricted contacts */ }
        }
        return $contacts;
    }
    public function listForSession(int $id): array {
        $this->allow('read'); $this->s->session($id);
        $rows = $this->s->rows('SELECT e.*, l.fk_socpeople FROM '.$this->s->table('enrollment').' e JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity WHERE e.entity='.$this->s->access->entity().' AND e.fk_session='.$id.' ORDER BY e.rowid');
        foreach ($rows as $row) {
            $row->contact = null;
            try { $row->contact = $this->s->contact((int) $row->fk_socpeople); }
            catch (Throwable $e) { $row->fk_socpeople = null; }
        }
        return $rows;
    }
    public function confirm(int $id, int $contactId): int {
        $this->allow('write');
        if ((int) $this->s->contact($contactId)->status !== 1) { throw new RuntimeException('TrainingContactInactive'); }
        return $this->s->transaction(function () use ($id, $contactId) {
            $session = $this->s->session($id, true);
            // Lock standard contact before creating its one learner profile.
            $rows = $this->s->rows('SELECT rowid FROM '.$this->s->db->prefix().'socpeople WHERE rowid='.$contactId.' FOR UPDATE');
            if (!$rows) { throw new RuntimeException('TrainingContactNotAccessible'); }
            if ((int) $this->s->contact($contactId)->status !== 1) { throw new RuntimeException('TrainingContactInactive'); }
            $entity = $this->s->access->entity();
            $learner = $this->s->rows('SELECT rowid FROM '.$this->s->table('learner').' WHERE entity='.$entity.' AND fk_socpeople='.$contactId.' FOR UPDATE');
            $learnerId = $learner ? (int) $learner[0]->rowid : 0;
            $existing = $learnerId ? $this->s->rows('SELECT * FROM '.$this->s->table('enrollment').' WHERE entity='.$entity.' AND fk_session='.$id.' AND fk_learner='.$learnerId.' FOR UPDATE') : array();
            if ($existing && $existing[0]->status === 'confirmed') { return (int) $existing[0]->rowid; }
            if ($session->status !== 'open') { throw new RuntimeException('TrainingSessionNotOpen'); }
            if ($this->s->occupied($id, true) >= (int) $session->capacity) { throw new RuntimeException('TrainingSessionFull'); }
            if (!$learnerId) {
                $this->s->query('INSERT INTO '.$this->s->table('learner').' (entity, fk_socpeople, datec, fk_user_author) VALUES ('.$entity.', '.$contactId.', '.$this->s->now().', '.$this->s->access->actor().')');
                $learnerId = (int) $this->s->db->last_insert_id($this->s->table('learner'));
            }
            if ($existing) {
                if ($existing[0]->status !== 'cancelled') { throw new RuntimeException('TrainingInvalidTransition'); }
                $enrollmentId = (int) $existing[0]->rowid;
                $this->s->query('UPDATE '.$this->s->table('enrollment')." SET status='confirmed', cancellation_reason=NULL, changed_at=".$this->s->now().', fk_user_modifier='.$this->s->access->actor().' WHERE rowid='.$enrollmentId.' AND entity='.$entity);
            } else {
                $this->s->query('INSERT INTO '.$this->s->table('enrollment').' (entity, fk_session, fk_learner, status, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('.$entity.', '.$id.', '.$learnerId.", 'confirmed', ".$this->s->now().', '.$this->s->access->actor().', '.$this->s->now().', '.$this->s->access->actor().')');
                $enrollmentId = (int) $this->s->db->last_insert_id($this->s->table('enrollment'));
            }
            $this->s->audit('enrollment', $enrollmentId, 'confirmed', array('session_id' => $id, 'reconfirmed' => (bool) $existing));
            return $enrollmentId;
        });
    }
    public function cancel(int $sessionId, int $enrollmentId, string $reason): void {
        $this->allow('write');
        if (trim($reason) === '' || strlen($reason) > 2000) { throw new InvalidArgumentException('TrainingCancellationReasonRequired'); }
        $this->s->transaction(function () use ($sessionId, $enrollmentId, $reason) {
            $this->s->session($sessionId, true);
            $rows = $this->s->rows('SELECT e.*, l.fk_socpeople FROM '.$this->s->table('enrollment').' e JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity WHERE e.rowid='.$enrollmentId.' AND e.entity='.$this->s->access->entity().' AND e.fk_session='.$sessionId.' FOR UPDATE');
            if (!$rows) { throw new RuntimeException('TrainingEnrollmentNotFound'); }
            $this->s->contact((int) $rows[0]->fk_socpeople);
            if ($rows[0]->status === 'cancelled') { return; }
            if ($rows[0]->status !== 'confirmed') { throw new RuntimeException('TrainingInvalidTransition'); }
            $this->s->query('UPDATE '.$this->s->table('enrollment')." SET status='cancelled', cancellation_reason=".$this->s->text(trim($reason)).', changed_at='.$this->s->now().', fk_user_modifier='.$this->s->access->actor().' WHERE rowid='.$enrollmentId.' AND entity='.$this->s->access->entity());
            $this->s->audit('enrollment', $enrollmentId, 'cancelled', array('session_id' => $sessionId, 'reason' => trim($reason)));
        });
    }
}
