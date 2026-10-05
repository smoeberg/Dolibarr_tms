<?php
/** Real Dolibarr 24.0.2 parent class, packaged descriptor, no simulated base class. */
$official = __DIR__.'/../.ci/dolibarr/htdocs';
if (!is_file($official.'/core/modules/DolibarrModules.class.php')) { throw new RuntimeException('Official Dolibarr checkout missing'); }
define('DOL_DOCUMENT_ROOT', $official);
$zip = new ZipArchive();
if ($zip->open(__DIR__.'/../dist/module_training-0.8.0.zip') !== true) { throw new RuntimeException('Package cannot be opened'); }
$temp = sys_get_temp_dir().'/training-package-'.bin2hex(random_bytes(8));
mkdir($temp);
try {
    if (!$zip->extractTo($temp)) { throw new RuntimeException('Extraction failed'); }
    require_once $temp.'/training/core/modules/modTraining.class.php';
    $descriptor = new modTraining(null);
    if (!($descriptor instanceof DolibarrModules) || $descriptor->version !== '0.8.0' || $descriptor->const_name !== 'MAIN_MODULE_TRAINING' || $descriptor->need_dolibarr_version !== array(24,0,2) || count($descriptor->rights) !== 24) {
        throw new RuntimeException('Invalid official module descriptor');
    }
    echo 'PASS: ZIP extraction and packaged descriptor with real Dolibarr 24.0.2 parent class'.PHP_EOL;
} finally {
    $zip->close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temp);
}
