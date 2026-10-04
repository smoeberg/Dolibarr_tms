<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingpriceservice.class.php';
final class TrainingEnrollmentService
{
    private TrainingStore $s;
    private TrainingPriceService $priceService;
    public function __construct(TrainingStore $store) { $this->s = $store; $this->priceService = new TrainingPriceService($store); }
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
            // Add price snapshot info
            $priceInfo = $this->priceService->getEnrollmentPriceInfo($id, (int) $row->rowid);
            $row->price_ht = $priceInfo['price_ht'];
            $row->price_ttc = $priceInfo['price_ttc'];
            $row->currency = $priceInfo['currency'];
            $row->tva_tx = $priceInfo['tva_tx'];
            $row->is_price_frozen = $priceInfo['is_frozen'];
        }
        return $rows;
    }
    public function confirm(int $id, int $contactId): int {
        $this->allow('write');
        if ((int) $this->s->contact($contactId)->status !== 1) { throw new RuntimeException('TrainingContactInactive'); }
        return $this->s->transaction(function () use ($id, $contactId) {
            $session = $this->s->session($id, true);
            $at = $this->s->decisionTime();
            [$learnerId, $existing] = $this->participant($id, $contactId);
            if ($existing && $existing[0]->status === 'confirmed') { return (int) $existing[0]->rowid; }
            if ($session->status !== 'open') { throw new RuntimeException('TrainingSessionNotOpen'); }
            if ($this->reservedContact($id, $contactId, $at)) { throw new RuntimeException('TrainingParticipantReserved'); }
            if ($this->s->capacity($id, true, $at)['occupied'] >= (int) $session->capacity) { throw new RuntimeException('TrainingSessionFull'); }
            return $this->writeConfirmed($id, $contactId, $learnerId, $existing);
        });
    }
    private function participant(int $id, int $contactId): array {
            // Lock standard contact before creating its one learner profile.
            $rows = $this->s->rows('SELECT rowid FROM '.$this->s->db->prefix().'socpeople WHERE rowid='.$contactId.' FOR UPDATE');
            if (!$rows) { throw new RuntimeException('TrainingContactNotAccessible'); }
            if ((int) $this->s->contact($contactId)->status !== 1) { throw new RuntimeException('TrainingContactInactive'); }
            $entity = $this->s->access->entity();
            $learner = $this->s->rows('SELECT rowid FROM '.$this->s->table('learner').' WHERE entity='.$entity.' AND fk_socpeople='.$contactId.' FOR UPDATE');
            $learnerId = $learner ? (int) $learner[0]->rowid : 0;
            $existing = $learnerId ? $this->s->rows('SELECT * FROM '.$this->s->table('enrollment').' WHERE entity='.$entity.' AND fk_session='.$id.' AND fk_learner='.$learnerId.' FOR UPDATE') : array();
            return array($learnerId, $existing);
    }
    private function writeConfirmed(int $id, int $contactId, int $learnerId, array $existing): int {
            $entity = $this->s->access->entity();
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
            // Create price snapshot at confirmation time - freezes catalog price
            $this->priceService->createSnapshot($id, $enrollmentId, $contactId);
            $this->s->audit('enrollment', $enrollmentId, 'confirmed', array('session_id' => $id, 'reconfirmed' => (bool) $existing));
            return $enrollmentId;
    }
    private function reservedContact(int $sessionId, int $contactId, string $at, int $except = 0): bool {
        $sql = 'SELECT h.rowid FROM '.$this->s->table('seat_hold').' h JOIN '.$this->s->table('seat_member').' m ON m.fk_seat_hold=h.rowid AND m.entity=h.entity';
        $sql .= ' WHERE h.entity='.$this->s->access->entity().' AND h.fk_session='.$sessionId." AND h.status='active' AND h.expires_utc>".$this->s->text($at).' AND m.fk_socpeople='.$contactId.' AND h.rowid<>'.$except.' FOR UPDATE';
        return (bool) $this->s->rows($sql);
    }
    private function seatHold(int $sessionId, int $holdId, bool $lock = false) {
        $rows = $this->s->rows('SELECT * FROM '.$this->s->table('seat_hold').' WHERE rowid='.$holdId.' AND entity='.$this->s->access->entity().' AND fk_session='.$sessionId.($lock ? ' FOR UPDATE' : ''));
        if (!$rows) { throw new RuntimeException('TrainingReservationNotFound'); }
        return $rows[0];
    }
    private function seatMembers(int $holdId, bool $lock = false): array {
        return $this->s->rows('SELECT * FROM '.$this->s->table('seat_member').' WHERE entity='.$this->s->access->entity().' AND fk_seat_hold='.$holdId.' ORDER BY fk_socpeople'.($lock ? ' FOR UPDATE' : ''));
    }
    private function reason(string $reason): string {
        if (trim($reason) === '' || strlen($reason) > 2000) { throw new InvalidArgumentException('TrainingReservationReasonRequired'); }
        return trim($reason);
    }
    public function reservations(int $sessionId): array {
        $this->allow('read'); $this->s->access->requireContactRead(); $this->s->session($sessionId);
        $at = $this->s->decisionTime();
        $holds = $this->s->rows('SELECT * FROM '.$this->s->table('seat_hold').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$sessionId.' ORDER BY rowid DESC');
        foreach ($holds as $hold) {
            $hold->effective_status = $hold->status === 'active' && $hold->expires_utc <= $at ? 'expired' : $hold->status;
            $hold->members = $this->seatMembers((int) $hold->rowid);
            foreach ($hold->members as $member) {
                $member->contact = null;
                try { $member->contact = $this->s->contact((int) $member->fk_socpeople); }
                catch (Throwable $e) { $member->fk_socpeople = null; $member->fk_enrollment = null; }
            }
        }
        return $holds;
    }
    /** Internal authenticated prototype. Keys are scoped to one entity + session. */
    public function reserve(int $sessionId, array $contactIds, string $key, int $minutes = 15): int {
        $this->allow('write'); $this->s->access->requireContactRead();
        if (!preg_match('/^[a-f0-9]{32,64}$/D', $key) || $minutes < 1 || $minutes > 60 || !$contactIds || count($contactIds) > 100) { throw new InvalidArgumentException('TrainingInvalidReservation'); }
        foreach ($contactIds as $contactId) {
            if (!is_int($contactId) || $contactId < 1) { throw new InvalidArgumentException('TrainingInvalidReservation'); }
            $this->s->contact($contactId);
        }
        sort($contactIds, SORT_NUMERIC);
        if (count(array_unique($contactIds)) !== count($contactIds)) { throw new InvalidArgumentException('TrainingInvalidReservation'); }
        $hash = hash('sha256', json_encode(array($contactIds, $minutes), JSON_THROW_ON_ERROR));
        return $this->s->transaction(function () use ($sessionId, $contactIds, $key, $minutes, $hash) {
            $session = $this->s->session($sessionId, true); $at = $this->s->decisionTime();
            $existing = $this->s->rows('SELECT * FROM '.$this->s->table('seat_hold').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$sessionId.' AND request_key='.$this->s->text($key).' FOR UPDATE');
            if ($existing) {
                if ($existing[0]->request_hash !== $hash) { throw new RuntimeException('TrainingReservationKeyConflict'); }
                // Never extend expiry or revive released/converted holds on retries.
                return (int) $existing[0]->rowid;
            }
            if ($session->status !== 'open') { throw new RuntimeException('TrainingSessionNotOpen'); }
            foreach ($contactIds as $contactId) {
                [, $enrollment] = $this->participant($sessionId, $contactId);
                if ($enrollment && $enrollment[0]->status === 'confirmed') { throw new RuntimeException('TrainingParticipantAlreadyBooked'); }
                if ($this->reservedContact($sessionId, $contactId, $at)) { throw new RuntimeException('TrainingParticipantReserved'); }
            }
            if ($this->s->capacity($sessionId, true, $at)['occupied'] + count($contactIds) > (int) $session->capacity) { throw new RuntimeException('TrainingSessionFull'); }
            $expires = gmdate('Y-m-d H:i:s', strtotime($at.' UTC') + $minutes*60);
            $actor = $this->s->access->actor(); $entity = $this->s->access->entity();
            $this->s->query('INSERT INTO '.$this->s->table('seat_hold').' (entity,fk_session,request_key,request_hash,qty,expires_utc,datec,fk_user_author,changed_at,fk_user_modifier) VALUES ('.$entity.','.$sessionId.','.$this->s->text($key).','.$this->s->text($hash).','.count($contactIds).','.$this->s->text($expires).','.$this->s->text($at).','.$actor.','.$this->s->text($at).','.$actor.')');
            $holdId = (int) $this->s->db->last_insert_id($this->s->table('seat_hold'));
            foreach ($contactIds as $contactId) {
                $this->s->query('INSERT INTO '.$this->s->table('seat_member').' (entity,fk_seat_hold,fk_socpeople) VALUES ('.$entity.','.$holdId.','.$contactId.')');
            }
            $this->s->audit('seat_hold', $holdId, 'reserved', array('session_id'=>$sessionId,'qty'=>count($contactIds),'expires_utc'=>$expires));
            return $holdId;
        });
    }
    /** Explicit coordinator approval; does not verify, imply or record payment. */
    public function confirmReservation(int $sessionId, int $holdId, string $reason): array {
        $this->allow('write'); $this->s->access->requireContactRead(); $reason = $this->reason($reason);
        return $this->s->transaction(function () use ($sessionId, $holdId, $reason) {
            $session = $this->s->session($sessionId, true); $at = $this->s->decisionTime();
            $hold = $this->seatHold($sessionId, $holdId, true); $members = $this->seatMembers($holdId, true);
            if (count($members) !== (int) $hold->qty) { throw new RuntimeException('TrainingReservationIntegrityError'); }
            foreach ($members as $member) { $this->s->contact((int) $member->fk_socpeople); }
            if ($hold->status === 'converted') { return array_map(fn($m)=>(int) $m->fk_enrollment, $members); }
            if ($hold->status !== 'active') { throw new RuntimeException('TrainingInvalidTransition'); }
            if ($session->status !== 'open') { throw new RuntimeException('TrainingSessionNotOpen'); }
            $participants = array();
            foreach ($members as $member) {
                $contactId = (int) $member->fk_socpeople;
                $participants[$contactId] = $this->participant($sessionId, $contactId);
                if ($participants[$contactId][1] && $participants[$contactId][1][0]->status === 'confirmed') { throw new RuntimeException('TrainingParticipantAlreadyBooked'); }
                if ($this->reservedContact($sessionId, $contactId, $at, $holdId)) { throw new RuntimeException('TrainingParticipantReserved'); }
            }
            $capacity = $this->s->capacity($sessionId, true, $at);
            $expired = $hold->expires_utc <= $at;
            $withoutThisHold = $capacity['occupied'] - ($expired ? 0 : (int) $hold->qty);
            if ($withoutThisHold + (int) $hold->qty > (int) $session->capacity) { throw new RuntimeException('TrainingSessionFull'); }
            $ids = array();
            foreach ($members as $member) {
                $contactId = (int) $member->fk_socpeople;
                [$learnerId, $existing] = $participants[$contactId];
                $id = $this->writeConfirmed($sessionId, $contactId, $learnerId, $existing);
                $this->s->query('UPDATE '.$this->s->table('seat_member').' SET fk_enrollment='.$id.' WHERE rowid='.(int) $member->rowid.' AND entity='.$this->s->access->entity());
                $ids[] = $id;
            }
            $this->s->query('UPDATE '.$this->s->table('seat_hold')." SET status='converted',reason=".$this->s->text($reason).',changed_at='.$this->s->text($at).',fk_user_modifier='.$this->s->access->actor().' WHERE rowid='.$holdId.' AND entity='.$this->s->access->entity());
            $this->s->audit('seat_hold', $holdId, 'converted', array('session_id'=>$sessionId,'qty'=>(int) $hold->qty,'after_expiry'=>$expired,'reason'=>$reason));
            return $ids;
        });
    }
    public function releaseReservation(int $sessionId, int $holdId, string $reason): void {
        $this->allow('write'); $this->s->access->requireContactRead(); $reason = $this->reason($reason);
        $this->s->transaction(function () use ($sessionId, $holdId, $reason) {
            $this->s->session($sessionId, true); $hold = $this->seatHold($sessionId, $holdId, true);
            foreach ($this->seatMembers($holdId, true) as $member) { $this->s->contact((int) $member->fk_socpeople); }
            if ($hold->status === 'released') { return; }
            if ($hold->status !== 'active') { throw new RuntimeException('TrainingInvalidTransition'); }
            $this->s->query('UPDATE '.$this->s->table('seat_hold')." SET status='released',reason=".$this->s->text($reason).',changed_at='.$this->s->now().',fk_user_modifier='.$this->s->access->actor().' WHERE rowid='.$holdId.' AND entity='.$this->s->access->entity());
            $this->s->audit('seat_hold', $holdId, 'released', array('session_id'=>$sessionId,'reason'=>$reason));
        });
    }
    public function reservationHistory(int $sessionId, int $holdId): array {
        $this->allow('read'); $this->s->access->requireContactRead(); $this->s->session($sessionId); $this->seatHold($sessionId, $holdId);
        foreach ($this->seatMembers($holdId) as $member) { $this->s->contact((int) $member->fk_socpeople); }
        return $this->s->rows('SELECT * FROM '.$this->s->table('audit').' WHERE entity='.$this->s->access->entity()." AND object_type='seat_hold' AND fk_object=".$holdId.' ORDER BY rowid');
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
