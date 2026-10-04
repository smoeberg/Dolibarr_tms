<?php
require_once __DIR__.'/trainingaccess.class.php';
require_once __DIR__.'/trainingprogram.class.php';

/** Only write entry point for the initial catalog slice. */
class TrainingCatalogService
{
    private $db;
    private TrainingAccess $access;
    private $productLoader;

    public function __construct($db, TrainingAccess $access, ?callable $productLoader = null)
    {
        $this->db = $db;
        $this->access = $access;
        $this->productLoader = $productLoader ?? function (int $id) {
            require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
            $product = new Product($this->db);
            if ($product->fetch($id) <= 0) {
                throw new RuntimeException('TrainingServiceNotAccessible');
            }
            return $product;
        };
    }

    private function service(int $id)
    {
        if ($id < 1) {
            throw new InvalidArgumentException('TrainingServiceNotAccessible');
        }
        $product = ($this->productLoader)($id);
        $this->access->requireService($product);
        return $product;
    }

    private function query(string $sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            // Keep SQL and personal data out of user-facing errors.
            throw new RuntimeException('TrainingDatabaseError');
        }
        return $result;
    }

    private function text(string $value): string
    {
        return "'".$this->db->escape($value)."'";
    }

    private function now(): string
    {
        return $this->text($this->db->idate(dol_now()));
    }

    private function begin(): void
    {
        if ($this->db->begin() <= 0) {
            throw new RuntimeException('TrainingDatabaseError');
        }
    }

    private function commit(): void
    {
        if ($this->db->commit() <= 0) {
            throw new RuntimeException('TrainingDatabaseError');
        }
    }

    private function lockService(int $id)
    {
        // All catalog writes lock the standard service first, then its profile/version.
        $r = $this->query('SELECT rowid FROM '.$this->db->prefix().'product WHERE rowid='.$id.' FOR UPDATE');
        if (!$this->db->fetch_object($r)) {
            throw new RuntimeException('TrainingServiceNotAccessible');
        }
        // Recheck type and authorized shared entity after obtaining the lock.
        return $this->service($id);
    }

    private function audit(int $id, string $action, array $metadata): void
    {
        $sql = 'INSERT INTO '.$this->db->prefix().'training_audit';
        $sql .= ' (entity, object_type, fk_object, action, fk_user_actor, datec, metadata_json) VALUES (';
        $sql .= $this->access->entity().", 'course_version', ".$id.', '.$this->text($action).', ';
        $sql .= $this->access->actor().', '.$this->now().', '.$this->text(json_encode($metadata, JSON_THROW_ON_ERROR)).')';
        $this->query($sql);
    }

    public function versions(int $productId): array
    {
        $this->access->requireRight('read');
        $this->service($productId);
        $sql = 'SELECT v.* FROM '.$this->db->prefix().'training_course_version v';
        $sql .= ' INNER JOIN '.$this->db->prefix().'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
        $sql .= ' WHERE v.entity='.$this->access->entity().' AND p.fk_product='.$productId.' ORDER BY v.version_number DESC';
        $r = $this->query($sql);
        $rows = array();
        while ($row = $this->db->fetch_object($r)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function createDraft(int $productId, array $input): int
    {
        $this->access->requireRight('write');
        $this->service($productId);
        $program = TrainingProgram::normalize($input);
        $json = json_encode($program, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 60000) {
            throw new InvalidArgumentException('TrainingTextTooLong');
        }
        $this->begin();
        try {
            $this->lockService($productId);
            $prefix = $this->db->prefix();
            $entity = $this->access->entity();
            $r = $this->query('SELECT rowid FROM '.$prefix.'training_course_profile WHERE entity='.$entity.' AND fk_product='.$productId.' FOR UPDATE');
            $profile = $this->db->fetch_object($r);
            if (!$profile) {
                $this->query('INSERT INTO '.$prefix.'training_course_profile (entity, fk_product, datec, fk_user_author) VALUES ('.$entity.', '.$productId.', '.$this->now().', '.$this->access->actor().')');
                $profileId = (int) $this->db->last_insert_id($prefix.'training_course_profile');
            } else {
                $profileId = (int) $profile->rowid;
            }
            $r = $this->query('SELECT COALESCE(MAX(version_number), 0) AS last_version FROM '.$prefix.'training_course_version WHERE entity='.$entity.' AND fk_course_profile='.$profileId);
            $number = 1 + (int) $this->db->fetch_object($r)->last_version;
            $this->query('INSERT INTO '.$prefix.'training_course_version (entity, fk_course_profile, version_number, status, program_json, duration_minutes, datec, fk_user_author) VALUES ('.$entity.', '.$profileId.', '.$number.", 'draft', ".$this->text($json).', '.$program['duration_minutes'].', '.$this->now().', '.$this->access->actor().')');
            $id = (int) $this->db->last_insert_id($prefix.'training_course_version');
            $this->audit($id, 'draft_created', array('version_number' => $number, 'duration_minutes' => $program['duration_minutes']));
            $this->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function publish(int $productId, int $versionId): void
    {
        $this->access->requireRight('publish');
        $this->service($productId);
        if ($versionId < 1) {
            throw new InvalidArgumentException('TrainingVersionNotFound');
        }
        $this->begin();
        try {
            $product = $this->lockService($productId);
            $prefix = $this->db->prefix();
            $entity = $this->access->entity();
            $sql = 'SELECT v.* FROM '.$prefix.'training_course_version v INNER JOIN '.$prefix.'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
            $sql .= ' WHERE v.rowid='.$versionId.' AND v.entity='.$entity.' AND p.fk_product='.$productId.' FOR UPDATE';
            $row = $this->db->fetch_object($this->query($sql));
            if (!$row) {
                throw new RuntimeException('TrainingVersionNotFound');
            }
            if ($row->status === 'published') {
                // Retried publish does not replace the original snapshot or add another audit event.
                $this->commit();
                return;
            }
            if ($row->status !== 'draft') {
                throw new RuntimeException('TrainingInvalidTransition');
            }
            $program = TrainingProgram::normalize(json_decode($row->program_json, true, 512, JSON_THROW_ON_ERROR));
            TrainingProgram::requirePublishable($program);
            $sql = 'UPDATE '.$prefix.'training_course_version SET status=\'published\', product_ref_snapshot='.$this->text((string) $product->ref);
            $sql .= ', product_label_snapshot='.$this->text((string) $product->label).', description_snapshot='.$this->text((string) $product->description);
            $sql .= ', published_at='.$this->now().', fk_user_publisher='.$this->access->actor();
            $sql .= ' WHERE rowid='.$versionId.' AND entity='.$entity.' AND status=\'draft\'';
            $this->query($sql);
            $this->audit($versionId, 'published', array('version_number' => (int) $row->version_number));
            $this->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
