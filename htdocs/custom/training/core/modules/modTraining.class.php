<?php
/** Training foundation for Dolibarr 24.0.2. */
require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modTraining extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db;
        // Provisional private module ID; check for conflicts before installation.
        $this->numero = 504850;
        $this->rights_class = 'training';
        $this->family = 'products';
        $this->module_position = '90';
        $this->name = 'Training';
        $this->description = 'ModuleTrainingDesc';
        $this->version = '0.8.0';
        $this->const_name = 'MAIN_MODULE_TRAINING';
        $this->picto = 'service';
        $this->editor_name = 'Dolibarr TMS';
        $this->editor_url = 'https://github.com/smoeberg/Dolibarr_tms';
        $this->module_parts = array('moduleforexternal' => 0);
        $this->dirs = array();
        $this->config_page_url = array();
        $this->depends = array('modService', 'modSociete', 'modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('training@training');
        $this->phpmin = array(8, 4);
        $this->need_dolibarr_version = array(24, 0, 2);
        $this->const = array();
        $this->tabs = array(array('data' => 'product:+training:TrainingCourse:training@training:$user->hasRight("training", "course", "read") && $user->hasRight("service", "lire") && $objectoffield->type == 1:/training/course.php?id=__ID__'));
        $this->tabs[] = array('data' => 'product:+trainingsessions:TrainingSessions:training@training:$user->hasRight("training", "session", "read") && $user->hasRight("service", "lire") && $objectoffield->type == 1:/training/sessions.php?product_id=__ID__');
        $this->rights = array();
        foreach (array('read' => 'TrainingReadCourses', 'write' => 'TrainingWriteCourses', 'publish' => 'TrainingPublishCourses') as $key => $label) {
            $r = count($this->rights);
            $this->rights[$r] = array(0 => $this->numero + $r + 1, 1 => $label, 3 => 0, 4 => 'course', 5 => $key);
        }
        foreach (array('session' => array('read' => 'TrainingReadSessions', 'write' => 'TrainingWriteSessions'), 'enrollment' => array('read' => 'TrainingReadEnrollments', 'write' => 'TrainingWriteEnrollments'), 'attendance' => array('read' => 'TrainingReadAttendance', 'write' => 'TrainingWriteAttendance', 'correct' => 'TrainingCorrectAttendance'), 'billing' => array('read' => 'TrainingReadBilling', 'write' => 'TrainingWriteBilling', 'correct' => 'TrainingCorrectBilling'), 'ownattendance' => array('read' => 'TrainingReadOwnAttendance', 'write' => 'TrainingWriteOwnAttendance', 'correct' => 'TrainingCorrectOwnAttendance'), 'trainer' => array('read' => 'TrainingReadTrainers', 'write' => 'TrainingWriteTrainers'), 'commercial' => array('read' => 'TrainingReadCommercial', 'write' => 'TrainingWriteCommercial', 'correct' => 'TrainingCorrectCommercial'), 'checkout' => array('read' => 'TrainingReadCheckout', 'write' => 'TrainingWriteCheckout', 'admin' => 'TrainingAdminCheckout')) as $domain => $rights) {
            foreach ($rights as $key => $label) {
                $r = count($this->rights);
                $this->rights[$r] = array(0 => $this->numero + $r + 1, 1 => $label, 3 => 0, 4 => $domain, 5 => $key);
            }
        }
        $this->menu = array(array('fk_menu'=>'fk_mainmenu=products', 'type'=>'left', 'titre'=>'TrainingMySessions', 'mainmenu'=>'products', 'leftmenu'=>'trainingmine', 'url'=>'/training/myattendance.php', 'langs'=>'training@training', 'position'=>100, 'enabled'=>'isModEnabled("training")', 'perms'=>'$user->hasRight("training", "ownattendance", "read")', 'target'=>'', 'user'=>2));
        $this->menu[] = array('fk_menu'=>'fk_mainmenu=products', 'type'=>'left', 'titre'=>'TrainingOverview', 'mainmenu'=>'products', 'leftmenu'=>'trainingoverview', 'url'=>'/training/overview.php', 'langs'=>'training@training', 'position'=>99, 'enabled'=>'isModEnabled("training")', 'perms'=>'$user->hasRight("training","session","read") && $user->hasRight("training","enrollment","read")', 'target'=>'', 'user'=>2);
    }

    public function init($options = '')
    {
        if ($this->db->type !== 'mysqli' && $this->db->type !== 'mysql') {
            $this->error = 'Training requires a MySQL-compatible Dolibarr driver.';
            return -1;
        }
        $ids = array_map(function ($right) { return (int) $right[0]; }, $this->rights);
        $res = $this->db->query('SELECT id FROM '.$this->db->prefix().'rights_def WHERE id IN ('.implode(',', $ids).') AND module<>\'training\'');
        if (!$res || $this->db->num_rows($res) > 0) {
            $this->error = 'Training permission IDs conflict with an installed module or could not be checked.';
            return -1;
        }
        $result = $this->_load_tables('/training/sql/');
        if ($result <= 0) {
            $dbError = method_exists($this->db, 'lasterror') ? $this->db->lasterror() : '';
            $dbCode = method_exists($this->db, 'errno') ? $this->db->errno() : '';
            $this->error = 'Training schema installation failed; module activation was stopped.'.($dbCode !== '' || $dbError !== '' ? ' DB['.$dbCode.']: '.$dbError : '');
            return -1;
        }
        if ($this->applyCheckoutCorrelationMigration() < 0) {
            return -1;
        }
        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        // Deactivation must retain profiles, versions and audit history.
        return $this->_remove(array(), $options);
    }

    /**
     * A8 Fase 3 trin 3: ensure checkout correlation columns exist on tables created
     * before they were added. Portable across MySQL 8.0 and MariaDB; MySQL does not
     * support `ADD COLUMN IF NOT EXISTS`.
     */
    private function applyCheckoutCorrelationMigration(): int
    {
        $columns = array(
            'native_commercial_object_type' => 'varchar(32) DEFAULT NULL',
            'native_commercial_object_id' => 'integer DEFAULT NULL',
            'native_commercial_object_ref' => 'varchar(64) DEFAULT NULL',
            'native_payment_id' => 'integer DEFAULT NULL',
            'native_payment_ref' => 'varchar(64) DEFAULT NULL',
            'native_payment_amount' => 'decimal(24,8) DEFAULT NULL',
        );
        $existing = array();
        foreach ($columns as $name => $definition) {
            // The lowercase alias is what the query requests, so it is stable across
            // MySQL and MariaDB regardless of information_schema label casing.
            $res = $this->db->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'llx_training_checkout_session' AND column_name = '".$this->db->escape($name)."'");
            if ($res) {
                $row = $this->db->fetch_object($res);
                if ($row && (int) $row->n > 0) {
                    $existing[$name] = true;
                }
            }
        }
        $missing = array();
        foreach ($columns as $name => $definition) {
            if (!isset($existing[$name])) {
                $missing[] = 'ADD COLUMN '.$name.' '.$definition;
            }
        }
        if (empty($missing)) {
            return 1;
        }
        $sql = 'ALTER TABLE llx_training_checkout_session '.implode(', ', $missing);
        if (!$this->db->query($sql)) {
            $dbError = method_exists($this->db, 'lasterror') ? $this->db->lasterror() : '';
            $dbCode = method_exists($this->db, 'errno') ? $this->db->errno() : '';
            $this->error = 'Training checkout correlation migration failed.'.($dbCode !== '' || $dbError !== '' ? ' DB['.$dbCode.']: '.$dbError : '');
            return -1;
        }
        return 1;
    }
}
