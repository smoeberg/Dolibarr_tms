<?php
/** Descriptor test double, not a bundled Dolibarr implementation. */
#[AllowDynamicProperties]
class DolibarrModules
{
    public int $loadResult = 1;
    public int $initCalls = 0;
    protected function _load_tables($dir) { return $this->loadResult; }
    protected function _init($sql, $options) { $this->initCalls++; return 1; }
    protected function _remove($sql, $options) { return 1; }
}
