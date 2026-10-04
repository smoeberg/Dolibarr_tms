<?php
// Standard custom-directory deployment; no unauthenticated entry points.
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('CSRFCHECK_WITH_TOKEN', 1);
    $main = dirname(__DIR__, 3).'/main.inc.php';
    if (!is_file($main)) {
        http_response_code(500);
        exit('Install training under htdocs/custom/training.');
    }
    require_once $main;
}
