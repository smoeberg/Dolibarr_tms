<?php
require_once __DIR__.'/trainingstore.class.php';
/** Assign standard internal users; does not own user identities or resource calendars. */
final class TrainingTrainerService
{
    private TrainingStore $s;
    public function __construct(TrainingStore $store) { $this->s = $store; }
    private function allow(string $right): void {
        $this->s->access->requireDomain('session', 'read'); $this->s->access->requireDomain('trainer', $right); $this->s->access->requireUserRead();
    }
    private function user(int $id, bool $lock = false) {
        $rows = $this->s->rows('SELECT rowid, entity, login, firstname, lastname, statut, fk_soc FROM '.$this->s->db->prefix().'user WHERE rowid='.$id.' AND entity IN ('.$this->s->access->userEntityScope().')'.($lock ? ' FOR UPDATE' : ''));
        if (!$rows) { throw new RuntimeException('TrainingTrainerUserNotAccessible'); }
        return $rows[0];
    }
    public function choices(string $search): array {
        $this->allow('write');
        if (strlen($search)>100) { throw new InvalidArgumentException('TrainingTextTooLong'); }
        $sql = 'SELECT rowid, login, firstname, lastname FROM '.$this->s->db->prefix().'user WHERE entity IN ('.$this->s->access->userEntityScope().') AND statut=1 AND (fk_soc IS NULL OR fk_soc=0)';
        if (trim($search)!=='') { $like=$this->s->text('%'.trim($search).'%');$sql.=' AND (login LIKE '.$like.' OR firstname LIKE '.$like.' OR lastname LIKE '.$like.')'; }
        return $this->s->rows($sql.' ORDER BY lastname, firstname, rowid LIMIT 50');
    }
    public function listForSession(int $sessionId): array {
        $this->allow('read');$this->s->session($sessionId);
        return $this->s->rows('SELECT a.*, t.fk_user, t.active AS trainer_active, u.login, u.firstname, u.lastname, u.statut AS user_active, u.fk_soc AS user_socid FROM '.$this->s->table('trainer_assignment').' a JOIN '.$this->s->table('trainer').' t ON t.rowid=a.fk_trainer AND t.entity=a.entity JOIN '.$this->s->db->prefix().'user u ON u.rowid=t.fk_user WHERE a.entity='.$this->s->access->entity().' AND a.fk_session='.$sessionId.' AND u.entity IN ('.$this->s->access->userEntityScope().') ORDER BY a.rowid');
    }
    public function change(int $sessionId, int $userId, int $expectedRevision, string $status, string $reason): array {
        $this->allow('write');
        if (!in_array($status,array('active','revoked'),true) || $expectedRevision<0) { throw new InvalidArgumentException('TrainingInvalidTrainerAssignment'); }
        if (trim($reason)==='' || strlen($reason)>2000) { throw new InvalidArgumentException('TrainingTrainerReasonRequired'); }
        return $this->s->transaction(function () use ($sessionId,$userId,$expectedRevision,$status,$reason) {
            // Same session mutex as attendance. A write rechecks its assignment inside this mutex.
            $this->s->session($sessionId,true);$user=$this->user($userId,true);
            $profiles=$this->s->rows('SELECT * FROM '.$this->s->table('trainer').' WHERE entity='.$this->s->access->entity().' AND fk_user='.$userId.' FOR UPDATE');
            $trainerId=$profiles ? (int) $profiles[0]->rowid : 0;
            $rows=$trainerId ? $this->s->rows('SELECT * FROM '.$this->s->table('trainer_assignment').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$sessionId.' AND fk_trainer='.$trainerId.' FOR UPDATE') : array();
            $revision=$rows ? (int) $rows[0]->revision : 0;
            if ($revision!==$expectedRevision) { throw new RuntimeException('TrainingTrainerConflict'); }
            if ($rows && $rows[0]->status===$status) { return array('id'=>(int) $rows[0]->rowid,'revision'=>$revision); }
            if ($status==='active' && ((int) $user->statut!==1 || (int) $user->fk_soc>0 || ($profiles && (int) $profiles[0]->active!==1))) { throw new RuntimeException('TrainingTrainerInactive'); }
            if (!$rows && $status==='revoked') { throw new RuntimeException('TrainingTrainerAssignmentNotFound'); }
            if (!$trainerId) {
                $this->s->query('INSERT INTO '.$this->s->table('trainer').' (entity,fk_user,active,datec,fk_user_author) VALUES ('.$this->s->access->entity().','.$userId.',1,'.$this->s->now().','.$this->s->access->actor().')');
                $trainerId=(int) $this->s->db->last_insert_id($this->s->table('trainer'));
            }
            if ($rows) {
                $id=(int) $rows[0]->rowid;
                $this->s->query('UPDATE '.$this->s->table('trainer_assignment').' SET status='.$this->s->text($status).',revision='.($revision+1).',changed_at='.$this->s->now().',fk_user_modifier='.$this->s->access->actor().' WHERE rowid='.$id.' AND entity='.$this->s->access->entity());
            } else {
                $this->s->query('INSERT INTO '.$this->s->table('trainer_assignment').' (entity,fk_session,fk_trainer,status,revision,datec,fk_user_author,changed_at,fk_user_modifier) VALUES ('.$this->s->access->entity().','.$sessionId.','.$trainerId.", 'active',1,".$this->s->now().','.$this->s->access->actor().','.$this->s->now().','.$this->s->access->actor().')');
                $id=(int) $this->s->db->last_insert_id($this->s->table('trainer_assignment'));
            }
            $this->s->audit('trainer_assignment',$id,$status==='active'?'assigned':'revoked',array('session_id'=>$sessionId,'user_id'=>$userId,'revision'=>$revision+1,'before'=>$rows ? $rows[0]->status : null,'after'=>$status,'reason'=>trim($reason)));
            return array('id'=>$id,'revision'=>$revision+1);
        });
    }
    public function history(int $sessionId, int $assignmentId): array {
        $rows=$this->listForSession($sessionId);$found=false;
        foreach ($rows as $row) { if ((int) $row->rowid===$assignmentId) { $found=true;break; } }
        if (!$found) { throw new RuntimeException('TrainingTrainerAssignmentNotFound'); }
        return $this->s->rows('SELECT * FROM '.$this->s->table('audit').' WHERE entity='.$this->s->access->entity()." AND object_type='trainer_assignment' AND fk_object=".$assignmentId.' ORDER BY rowid');
    }
}
