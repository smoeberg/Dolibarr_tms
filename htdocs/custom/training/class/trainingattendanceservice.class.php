<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingattendancerecord.class.php';

final class TrainingAttendanceService
{
    private TrainingStore $s;
    public function __construct(TrainingStore $store) { $this->s = $store; }
    private function allow(int $sessionId, string $right, bool $lock = false): void {
        $this->s->access->requireAttendance($sessionId, $right, $this->s->db, $lock);
    }
    private function slot(int $sessionId, int $slotId) {
        $rows = $this->s->rows('SELECT * FROM '.$this->s->table('session_slot').' WHERE rowid='.$slotId.' AND entity='.$this->s->access->entity().' AND fk_session='.$sessionId);
        if (!$rows) { throw new RuntimeException('TrainingAttendanceSlotNotFound'); }
        return $rows[0];
    }
    private function enrollment(int $sessionId, int $enrollmentId) {
        $sql = 'SELECT e.*, l.fk_socpeople FROM '.$this->s->table('enrollment').' e JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity';
        $rows = $this->s->rows($sql.' WHERE e.rowid='.$enrollmentId.' AND e.entity='.$this->s->access->entity().' AND e.fk_session='.$sessionId);
        if (!$rows) { throw new RuntimeException('TrainingEnrollmentNotFound'); }
        $this->s->contact((int) $rows[0]->fk_socpeople); return $rows[0];
    }
    private function existing(int $sessionId, int $slotId, int $enrollmentId, bool $lock = false): array {
        return $this->s->rows('SELECT * FROM '.$this->s->table('attendance').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$sessionId.' AND fk_session_slot='.$slotId.' AND fk_enrollment='.$enrollmentId.($lock ? ' FOR UPDATE' : ''));
    }
    public function permissions(int $sessionId): array {
        $this->allow($sessionId, 'read'); $this->s->session($sessionId);
        $rights = array('read'=>true, 'write'=>false, 'correct'=>false);
        foreach (array('write','correct') as $right) {
            try { $this->allow($sessionId, $right); $rights[$right] = true; }
            catch (RuntimeException $e) { if ($e->getMessage() !== 'TrainingAccessDenied') { throw $e; } }
        }
        return $rights;
    }
    public function mySessions(): array {
        $this->s->access->attendanceScope('read'); $this->s->access->requireContactRead();
        $sql = 'SELECT DISTINCT a.fk_session FROM '.$this->s->table('trainer_assignment').' a JOIN '.$this->s->table('trainer').' t ON t.rowid=a.fk_trainer AND t.entity=a.entity JOIN '.$this->s->db->prefix().'user u ON u.rowid=t.fk_user';
        $sql .= ' WHERE a.entity='.$this->s->access->entity()." AND a.status='active' AND t.active=1 AND u.rowid=".$this->s->access->actor().' AND u.statut=1 AND (u.fk_soc IS NULL OR u.fk_soc=0) AND u.entity IN ('.$this->s->access->userEntityScope().') ORDER BY a.fk_session';
        $result = array();
        foreach ($this->s->rows($sql) as $row) {
            try { $this->allow((int) $row->fk_session, 'read'); $session = $this->s->session((int) $row->fk_session); }
            catch (RuntimeException $e) { if (in_array($e->getMessage(), array('TrainingAccessDenied','TrainingServiceNotAccessible'), true)) { continue; } throw $e; }
            $result[] = array('session'=>$session, 'slots'=>$this->s->rows('SELECT * FROM '.$this->s->table('session_slot').' WHERE entity='.$this->s->access->entity().' AND fk_session='.(int) $session->rowid.' ORDER BY position'));
        }
        return $result;
    }
    public function sheet(int $sessionId, int $slotId): array {
        $this->allow($sessionId, 'read'); $session = $this->s->session($sessionId); $slot = $this->slot($sessionId, $slotId);
        $sql = 'SELECT e.rowid AS enrollment_id, e.status AS enrollment_status, l.fk_socpeople, a.rowid AS attendance_id, a.status, a.arrival_utc, a.departure_utc, a.present_minutes, a.late_minutes, a.revision';
        $sql .= ' FROM '.$this->s->table('enrollment').' e JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity';
        $sql .= ' LEFT JOIN '.$this->s->table('attendance').' a ON a.fk_enrollment=e.rowid AND a.entity=e.entity AND a.fk_session_slot='.$slotId.' AND a.fk_session=e.fk_session';
        $sql .= ' WHERE e.entity='.$this->s->access->entity().' AND e.fk_session='.$sessionId." AND (e.status='confirmed' OR a.rowid IS NOT NULL) ORDER BY e.rowid";
        $rows = array();
        foreach ($this->s->rows($sql) as $row) {
            try { $row->contact = $this->s->contact((int) $row->fk_socpeople); } catch (Throwable $e) { continue; }
            $row->status = $row->status ?? 'not_registered'; $row->revision = (int) ($row->revision ?? 0); $rows[] = $row;
        }
        return array('session' => $session, 'slot' => $slot, 'rows' => $rows);
    }
    public function history(int $sessionId, int $slotId, int $enrollmentId): array {
        $this->allow($sessionId, 'read'); $this->s->session($sessionId); $this->slot($sessionId, $slotId); $this->enrollment($sessionId, $enrollmentId);
        $rows = $this->existing($sessionId, $slotId, $enrollmentId);
        if (!$rows) { return array(); }
        return $this->s->rows('SELECT * FROM '.$this->s->table('audit').' WHERE entity='.$this->s->access->entity()." AND object_type='attendance' AND fk_object=".(int) $rows[0]->rowid.' ORDER BY rowid');
    }
    public function record(int $sessionId, int $slotId, int $enrollmentId, int $expectedRevision, array $input, string $reason = ''): array {
        $this->allow($sessionId, 'write');
        if ($expectedRevision < 0) { throw new InvalidArgumentException('TrainingAttendanceConflict'); }
        return $this->s->transaction(function () use ($sessionId, $slotId, $enrollmentId, $expectedRevision, $input, $reason) {
            // Same mutex as enrollment/cancellation: canceled bookings cannot gain new attendance in a race.
            $session = $this->s->session($sessionId, true);
            $this->allow($sessionId, 'write', true);
            if ($session->status === 'draft') { throw new RuntimeException('TrainingAttendanceDraftSession'); }
            $slot = $this->slot($sessionId, $slotId); $enrollment = $this->enrollment($sessionId, $enrollmentId);
            $rows = $this->existing($sessionId, $slotId, $enrollmentId, true); $before = $rows ? TrainingAttendanceRecord::state($rows[0]) : null;
            $revision = $rows ? (int) $rows[0]->revision : 0;
            if ($revision !== $expectedRevision) { throw new RuntimeException('TrainingAttendanceConflict'); }
            if (!$rows && $enrollment->status !== 'confirmed') { throw new RuntimeException('TrainingAttendanceConfirmedRequired'); }
            $state = TrainingAttendanceRecord::normalize($input, $slot, $session->timezone);
            if ($before === $state) { return array('id' => (int) $rows[0]->rowid, 'revision' => $revision); }
            if (!$rows && $state['status'] === 'not_registered') { throw new RuntimeException('TrainingNoAttendanceToClear'); }
            if ($rows) {
                $this->allow($sessionId, 'correct', true);
                if (trim($reason) === '' || strlen($reason) > 2000) { throw new InvalidArgumentException('TrainingAttendanceReasonRequired'); }
            }
            $fields = array();
            foreach ($state as $key => $value) {
                $fields[] = $key.'='.($value === null ? 'NULL' : (is_int($value) ? (string) $value : $this->s->text($value)));
            }
            $fields[] = 'revision='.($revision + 1); $fields[] = 'changed_at='.$this->s->now(); $fields[] = 'fk_user_modifier='.$this->s->access->actor();
            if ($rows) {
                $id = (int) $rows[0]->rowid;
                $this->s->query('UPDATE '.$this->s->table('attendance').' SET '.implode(', ', $fields).' WHERE rowid='.$id.' AND entity='.$this->s->access->entity());
            } else {
                $this->s->query('INSERT INTO '.$this->s->table('attendance').' SET entity='.$this->s->access->entity().', fk_session='.$sessionId.', fk_session_slot='.$slotId.', fk_enrollment='.$enrollmentId.', datec='.$this->s->now().', fk_user_author='.$this->s->access->actor().', '.implode(', ', $fields));
                $id = (int) $this->s->db->last_insert_id($this->s->table('attendance'));
            }
            $this->s->audit('attendance', $id, $rows ? 'corrected' : 'recorded', array('revision' => $revision + 1, 'before' => $before, 'after' => $state, 'reason' => $rows ? trim($reason) : null));
            return array('id' => $id, 'revision' => $revision + 1);
        });
    }
}
