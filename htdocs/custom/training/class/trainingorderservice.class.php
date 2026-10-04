<?php
require_once __DIR__.'/trainingstore.class.php';
require_once __DIR__.'/trainingorderadapter.class.php';
require_once __DIR__.'/trainingbillingamount.class.php';

/**
 * Order line allocation service.
 * Links standard Dolibarr order lines to training sessions and enrollments.
 * Same pattern as TrainingBillingService for invoice lines.
 */
final class TrainingOrderService
{
    private TrainingStore $s;
    private TrainingOrderAdapter $orders;

    public function __construct(TrainingStore $store, string $baseCurrency) {
        $this->s = $store;
        $this->orders = new TrainingOrderAdapter($store, $baseCurrency);
    }

    private function allow(string $right): void {
        $this->s->access->requireDomain('session', 'read');
        $this->s->access->requireDomain('order', $right);
        $this->s->access->requireOrderRead();
        $this->s->access->requireContactRead();
    }

    /**
     * Get order line mapping for a session.
     */
    private function mapping(int $lineId, bool $lock = false): array {
        $rows = $this->s->rows(
            'SELECT * FROM '.$this->s->table('order_line').
            ' WHERE fk_commandedet='.$lineId.
            ($lock ? ' FOR UPDATE' : '')
        );
        if ($rows && (int) $rows[0]->entity !== $this->s->access->entity()) {
            throw new RuntimeException('TrainingOrderNotAccessible');
        }
        if ($rows) {
            $this->s->access->requireBillingCustomer((int) $rows[0]->fk_soc, $this->s->db);
        }
        return $rows;
    }

    /**
     * Get enrollment details.
     */
    private function enrollment(int $sessionId, int $enrollmentId): object {
        $rows = $this->s->rows(
            'SELECT e.*, l.fk_socpeople FROM '.$this->s->table('enrollment').' e '.
            'JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity '.
            'WHERE e.rowid='.$enrollmentId.' AND e.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId
        );
        if (!$rows) { 
            throw new RuntimeException('TrainingEnrollmentNotFound'); 
        }
        $this->s->contact((int) $rows[0]->fk_socpeople);
        return $rows[0];
    }

    /**
     * Get allocation shares for an order line mapping.
     */
    private function shares(int $mappingId): array {
        return $this->s->rows(
            'SELECT fk_enrollment AS enrollment_id, weight, amount_ht, amount_ttc '.
            'FROM '.$this->s->table('order_allocation').
            ' WHERE fk_order_line='.$mappingId.
            ' AND entity='.$this->s->access->entity().
            ' ORDER BY fk_enrollment'
        );
    }

    /**
     * List participants for a session.
     */
    public function participants(int $sessionId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        $rows = $this->s->rows(
            'SELECT e.rowid, e.status, l.fk_socpeople FROM '.$this->s->table('enrollment').' e '.
            'JOIN '.$this->s->table('learner').' l ON l.rowid=e.fk_learner AND l.entity=e.entity '.
            'WHERE e.entity='.$this->s->access->entity().
            ' AND e.fk_session='.$sessionId.
            ' ORDER BY e.rowid'
        );
        $result = array();
        foreach ($rows as $row) {
            try {
                $row->contact = $this->s->contact((int) $row->fk_socpeople);
                $result[] = $row;
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'TrainingContactNotAccessible') {
                    throw $e;
                }
            }
        }
        return $result;
    }

    /**
     * List all order line allocations for a session.
     */
    public function forSession(int $sessionId): array {
        $this->allow('read');
        $this->s->session($sessionId);
        $result = array();
        
        $rows = $this->s->rows(
            'SELECT fk_commande, fk_commandedet FROM '.$this->s->table('order_line').
            ' WHERE entity='.$this->s->access->entity().
            ' AND fk_session='.$sessionId.
            ' ORDER BY rowid'
        );
        
        foreach ($rows as $row) {
            try {
                $result[] = $this->inspect($sessionId, (int) $row->fk_commande, (int) $row->fk_commandedet);
            } catch (RuntimeException $e) {
                if (!in_array($e->getMessage(), array('TrainingOrderNotAccessible','TrainingContactNotAccessible'), true)) {
                    throw $e;
                }
            }
        }
        return $result;
    }

    /**
     * Inspect a specific order line allocation.
     */
    public function inspect(int $sessionId, int $orderId, int $lineId): array {
        $this->allow('read');
        $session = $this->s->session($sessionId);
        $source = $this->orders->line($orderId, $lineId);
        $rows = $this->mapping($lineId);
        $mapping = $rows ? $rows[0] : null;
        
        if ($mapping && (int) $mapping->fk_session !== $sessionId) {
            throw new RuntimeException('TrainingOrderLineAlreadyAllocated');
        }
        if (!$mapping && (int) $source['line']->fk_product !== (int) $session->fk_product) {
            throw new RuntimeException('TrainingOrderProductMismatch');
        }
        
        $shares = $mapping ? $this->shares((int) $mapping->rowid) : array();
        foreach ($shares as $share) {
            $share->contact = null;
            try {
                $share->contact = $this->s->contact((int) $this->enrollment($sessionId, (int) $share->enrollment_id)->fk_socpeople);
            } catch (Throwable $e) {
                // Contact not accessible
            }
        }
        
        return array(
            'source' => $source,
            'mapping' => $mapping,
            'shares' => $shares,
            'revision' => $mapping ? (int) $mapping->revision : 0,
            'reconciled' => $mapping && $mapping->status === 'active' && $source['eligible'] && 
                hash_equals($mapping->source_hash, $source['hash'])
        );
    }

    /**
     * Allocate an order line to session enrollments.
     */
    public function allocate(int $sessionId, int $orderId, int $lineId, int $expectedRevision, array $weights, string $reason = ''): array {
        $this->allow('write');
        if ($expectedRevision < 0) {
            throw new RuntimeException('TrainingOrderConflict');
        }
        
        return $this->s->transaction(function () use ($sessionId, $orderId, $lineId, $expectedRevision, $weights, $reason) {
            // Lock order: order header -> line -> session -> mapping
            $source = $this->orders->line($orderId, $lineId, true);
            $session = $this->s->session($sessionId, true);
            
            if (!$source['eligible']) {
                throw new RuntimeException('TrainingOrderNotEligible');
            }
            if ((int) $source['line']->fk_product !== (int) $session->fk_product) {
                throw new RuntimeException('TrainingOrderProductMismatch');
            }
            
            $rows = $this->mapping($lineId, true);
            $mapping = $rows ? $rows[0] : null;
            
            if ($mapping && (int) $mapping->fk_session !== $sessionId) {
                throw new RuntimeException('TrainingOrderLineAlreadyAllocated');
            }
            
            $revision = $mapping ? (int) $mapping->revision : 0;
            if ($expectedRevision !== $revision) {
                throw new RuntimeException('TrainingOrderConflict');
            }
            
            if ($mapping && $mapping->status === 'active' && !hash_equals($mapping->source_hash, $source['hash'])) {
                throw new RuntimeException('TrainingOrderSourceChanged');
            }
            
            // Distribute amounts
            $shares = TrainingBillingAmount::distribute(
                (string) $source['line']->total_ht,
                (string) $source['line']->total_ttc,
                $weights
            );
            
            $before = $mapping ? $this->shares((int) $mapping->rowid) : array();
            $normalized = array();
            foreach ($before as $row) {
                $normalized[] = array(
                    'enrollment_id' => (int) $row->enrollment_id,
                    'weight' => (int) $row->weight,
                    'amount_ht' => $row->amount_ht,
                    'amount_ttc' => $row->amount_ttc
                );
            }
            
            // Validate contact access
            $enrollments = array();
            foreach ($shares as $share) {
                $enrollments[$share['enrollment_id']] = $this->enrollment($sessionId, $share['enrollment_id']);
            }
            
            // Check if this is a no-op
            if ($mapping && $mapping->status === 'active' && $normalized === $shares) {
                return array('id' => (int) $mapping->rowid, 'revision' => $revision);
            }
            
            // Validate all enrollments are confirmed
            foreach ($enrollments as $enrollment) {
                if ($enrollment->status !== 'confirmed') {
                    throw new RuntimeException('TrainingOrderConfirmedRequired');
                }
            }
            
            // Validate access to previous allocations
            foreach ($before as $row) {
                $this->enrollment($sessionId, (int) $row->enrollment_id);
            }
            
            if ($mapping) {
                $this->s->access->requireDomain('order', 'correct');
                if (trim($reason) === '' || strlen($reason) > 2000) {
                    throw new InvalidArgumentException('TrainingOrderReasonRequired');
                }
                $id = (int) $mapping->rowid;
                $this->s->query(
                    'UPDATE '.$this->s->table('order_line').
                    ' SET status=\'active\', source_hash='.$this->s->text($source['hash']).
                    ', snapshot_json='.$this->s->text($source['snapshot_json']).
                    ', fk_soc='.(int) $source['order']->fk_soc.
                    ', currency='.$this->s->text($source['snapshot']['currency']).
                    ', revision='.($revision + 1).
                    ', changed_at='.$this->s->now().
                    ', fk_user_modifier='.$this->s->access->actor().
                    ' WHERE rowid='.$id.' AND entity='.$this->s->access->entity()
                );
                $this->s->query(
                    'DELETE FROM '.$this->s->table('order_allocation').
                    ' WHERE fk_order_line='.$id.' AND entity='.$this->s->access->entity()
                );
            } else {
                $this->s->query(
                    'INSERT INTO '.$this->s->table('order_line').
                    ' (entity, fk_session, fk_commande, fk_commandedet, fk_soc, currency, source_hash, snapshot_json, revision, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
                    .$this->s->access->entity().', '.$sessionId.', '.$orderId.', '.$lineId.', '
                    .(int) $source['order']->fk_soc.', '.$this->s->text($source['snapshot']['currency']).', '
                    .$this->s->text($source['hash']).', '.$this->s->text($source['snapshot_json']).', 1, '
                    .$this->s->now().', '.$this->s->access->actor().', '.$this->s->now().', '.$this->s->access->actor().')'
                );
                $id = (int) $this->s->db->last_insert_id($this->s->table('order_line'));
            }
            
            // Insert allocation shares
            foreach ($shares as $share) {
                $this->s->query(
                    'INSERT INTO '.$this->s->table('order_allocation').
                    ' (entity, fk_order_line, fk_enrollment, weight, amount_ht, amount_ttc) VALUES ('
                    .$this->s->access->entity().', '.$id.', '.$share['enrollment_id'].', '
                    .$share['weight'].', '.$this->s->text($share['amount_ht']).', '
                    .$this->s->text($share['amount_ttc']).')'
                );
            }
            
            $this->s->audit(
                'order_line',
                $id,
                $mapping ? ($mapping->status === 'void' ? 'reactivated' : 'corrected') : 'allocated',
                array(
                    'revision' => $revision + 1,
                    'order_id' => $orderId,
                    'line_id' => $lineId,
                    'source_hash' => $source['hash'],
                    'before_source' => $mapping ? json_decode($mapping->snapshot_json, true, 512, JSON_THROW_ON_ERROR) : null,
                    'after_source' => $source['snapshot'],
                    'before' => $normalized,
                    'after' => $shares,
                    'reason' => $mapping ? trim($reason) : null
                )
            );
            
            return array('id' => $id, 'revision' => $revision + 1);
        });
    }

    /**
     * Void an order line allocation.
     */
    public function voidLine(int $sessionId, int $orderId, int $lineId, int $expectedRevision, string $reason): array {
        $this->allow('write');
        $this->s->access->requireDomain('order', 'correct');
        if (trim($reason) === '' || strlen($reason) > 2000) {
            throw new InvalidArgumentException('TrainingOrderReasonRequired');
        }
        
        return $this->s->transaction(function () use ($sessionId, $orderId, $lineId, $expectedRevision, $reason) {
            $source = $this->orders->line($orderId, $lineId, true);
            $this->s->session($sessionId, true);
            
            $rows = $this->mapping($lineId, true);
            if (!$rows || (int) $rows[0]->fk_session !== $sessionId) {
                throw new RuntimeException('TrainingOrderLineAlreadyAllocated');
            }
            
            $mapping = $rows[0];
            if ((int) $mapping->revision !== $expectedRevision) {
                throw new RuntimeException('TrainingOrderConflict');
            }
            
            $shares = $this->shares((int) $mapping->rowid);
            foreach ($shares as $share) {
                $this->enrollment($sessionId, (int) $share->enrollment_id);
            }
            
            if ($mapping->status === 'void') {
                return array('id' => (int) $mapping->rowid, 'revision' => $expectedRevision);
            }
            
            $this->s->query(
                'UPDATE '.$this->s->table('order_line').
                ' SET status=\'void\', revision='.($expectedRevision+1).
                ', changed_at='.$this->s->now().
                ', fk_user_modifier='.$this->s->access->actor().
                ' WHERE rowid='.(int) $mapping->rowid.' AND entity='.$this->s->access->entity()
            );
            
            $this->s->audit(
                'order_line',
                (int) $mapping->rowid,
                'voided',
                array(
                    'revision' => $expectedRevision+1,
                    'order_id' => $orderId,
                    'line_id' => $lineId,
                    'before_status' => 'active',
                    'after_status' => 'void',
                    'before_source' => json_decode($mapping->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
                    'current_source' => $source['snapshot'],
                    'before' => $shares,
                    'after' => array(),
                    'reason' => trim($reason)
                )
            );
            
            return array('id' => (int) $mapping->rowid, 'revision' => $expectedRevision+1);
        });
    }

    /**
     * Get history for an order line allocation.
     */
    public function history(int $sessionId, int $orderId, int $lineId): array {
        $view = $this->inspect($sessionId, $orderId, $lineId);
        if (!$view['mapping']) {
            return array();
        }
        return $this->s->rows(
            'SELECT * FROM '.$this->s->table('audit').
            ' WHERE entity='.$this->s->access->entity().
            ' AND object_type=\'order_line\' AND fk_object='.(int) $view['mapping']->rowid.
            ' ORDER BY rowid'
        );
    }
}
