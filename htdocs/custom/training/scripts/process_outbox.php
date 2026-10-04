<?php
/**
 * Training Outbox Processing Script
 * 
 * This script processes queued outbox messages (emails, notifications).
 * It can be run:
 * - Via CLI: php process_outbox.php
 * - Via cron: * * * * * /path/to/php /path/to/process_outbox.php
 * - Via web: https://yourdomain.com/training/scripts/process_outbox.php
 * 
 * Security:
 * - CLI: Always allowed
 * - Web: Requires authentication and training_admin_checkout permission
 * 
 * Usage:
 *   php process_outbox.php [--batch-size=20] [--verbose] [--force]
 * 
 * Options:
 *   --batch-size=N    Process N messages per run (default: 20)
 *   --verbose        Show detailed output
 *   --force          Force processing even if already running
 */

// Define constants for paths
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
}

require_once DOL_DOCUMENT_ROOT.'/main.inc.php';
require_once __DIR__.'/../class/trainingstore.class.php';
require_once __DIR__.'/../class/trainingaccess.class.php';
require_once __DIR__.'/../class/trainingoutboxservice.class.php';

// ========================================================================
// CONFIGURATION
// ========================================================================

// Lock file to prevent concurrent execution
$lockFile = sys_get_temp_dir().'/training_outbox_processing.lock';
$maxExecutionTime = 300; // 5 minutes

// ========================================================================
// COMMAND LINE PARSING
// ========================================================================

$options = parseCommandLineArguments();
$batchSize = (int) ($options['batch-size'] ?? 20);
$verbose = isset($options['verbose']);
$force = isset($options['force']);

// ========================================================================
// ACCESS CONTROL
// ========================================================================

// Check if running via CLI or web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Web access - require authentication
    if (empty($user->id) || empty($user->hasRight)) {
        accessforbidden();
    }
    
    // Check permission
    if (!$user->hasRight('training', 'checkout', 'admin')) {
        accessforbidden();
    }
}

// ========================================================================
// LOCKING MECHANISM
// ========================================================================

// Check if another instance is already running
if (file_exists($lockFile) && !$force) {
    $lockContent = file_get_contents($lockFile);
    $lockData = json_decode($lockContent, true);
    
    if ($lockData && isset($lockData['pid'], $lockData['timestamp'])) {
        $lockAge = time() - $lockData['timestamp'];
        
        // If lock is older than max execution time, consider it stale
        if ($lockAge < $maxExecutionTime) {
            if ($verbose) {
                echo "Another instance is already running (PID: {$lockData['pid']}, Age: {$lockAge}s)\n";
            }
            exit(0);
        }
    }
}

// Create lock file
$lockData = array(
    'pid' => getmypid(),
    'timestamp' => time(),
    'batch_size' => $batchSize
);

file_put_contents($lockFile, json_encode($lockData), LOCK_EX);

// Set up cleanup on exit
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

// ========================================================================
// INITIALIZE SERVICES
// ========================================================================

try {
    $access = new TrainingAccess($user, (int) $conf->entity, (string) $conf->entity);
    $store = new TrainingStore($db, $access);
    $outbox = new TrainingOutboxService($store);
} catch (Throwable $e) {
    if ($verbose) {
        echo "Error initializing services: {$e->getMessage()}\n";
    }
    exit(1);
}

// ========================================================================
// PROCESS OUTBOX
// ========================================================================

if ($verbose) {
    echo "Starting outbox processing...\n";
    echo "Batch size: {$batchSize}\n";
    echo "Timestamp: ".date('Y-m-d H:i:s')."\n";
    echo str_repeat("=", 50)."\n";
}

try {
    $stats = $outbox->processBatch($batchSize);
    
    if ($verbose) {
        echo "Processing complete.\n";
        echo str_repeat("-", 50)."\n";
        echo "Statistics:\n";
        echo "  Total messages: {$stats['total']}\n";
        echo "  Processed: {$stats['processed']}\n";
        echo "  Completed: {$stats['completed']}\n";
        echo "  Failed: {$stats['failed']}\n";
        echo "  Skipped: {$stats['skipped']}\n";
    }
    
    // Log to database
    logProcessingRun($db, $user, $stats);
    
    exit(0);
} catch (Throwable $e) {
    if ($verbose) {
        echo "Error: {$e->getMessage()}\n";
        echo $e->getTraceAsString()."\n";
    }
    
    // Log error to database
    logProcessingError($db, $user, $e);
    
    exit(1);
}

// ========================================================================
// HELPER FUNCTIONS
// ========================================================================

/**
 * Parse command line arguments.
 */
function parseCommandLineArguments(): array
{
    global $argv;
    
    $options = array();
    
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2));
            $key = $parts[0];
            $value = $parts[1] ?? true;
            
            $options[$key] = $value;
        }
    }
    
    return $options;
}

/**
 * Log a processing run to the database.
 */
function logProcessingRun($db, $user, array $stats): void
{
    global $conf;
    
    $sql = 'INSERT INTO '.$db->prefix().'training_audit';
    $sql .= ' (entity, object_type, fk_object, action, fk_user_actor, datec, metadata_json)';
    $sql .= ' VALUES ('
        .(int) $conf->entity.', '
        .$db->quote('outbox_processor').', 0, '
        .$db->quote('batch_processed').', '
        .(int) ($user->id ?? 0).', '
        .$db->quote(dol_now()).', '
        .$db->quote(json_encode($stats, JSON_THROW_ON_ERROR)).')';
    
    $db->query($sql);
}

/**
 * Log a processing error to the database.
 */
function logProcessingError($db, $user, Throwable $e): void
{
    global $conf;
    
    $sql = 'INSERT INTO '.$db->prefix().'training_audit';
    $sql .= ' (entity, object_type, fk_object, action, fk_user_actor, datec, metadata_json)';
    $sql .= ' VALUES ('
        .(int) $conf->entity.', '
        .$db->quote('outbox_processor').', 0, '
        .$db->quote('error').', '
        .(int) ($user->id ?? 0).', '
        .$db->quote(dol_now()).', '
        .$db->quote(json_encode(array(
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ), JSON_THROW_ON_ERROR)).')';
    
    $db->query($sql);
}

// ========================================================================
// WEB OUTPUT (if accessed via browser)
// ========================================================================

if (!$isCli) {
    llxHeader('', 'Training Outbox Processor');
    
    echo '<h1>Training Outbox Processor</h1>';
    echo '<p>This script processes queued outbox messages.</p>';
    
    echo '<h2>Configuration</h2>';
    echo '<ul>';
    echo '<li><strong>Batch Size:</strong> '.$batchSize.'</li>';
    echo '<li><strong>Verbose:</strong> '.($verbose ? 'Yes' : 'No').'</li>';
    echo '<li><strong>Force:</strong> '.($force ? 'Yes' : 'No').'</li>';
    echo '</ul>';
    
    echo '<h2>Cron Job Setup</h2>';
    echo '<p>To set up a cron job, add the following line to your crontab:</p>';
    echo '<pre>';
    echo '* * * * * /usr/bin/php '.DOL_DOCUMENT_ROOT.'/custom/training/scripts/process_outbox.php --batch-size=20 --verbose';
    echo '</pre>';
    echo '<p>This will run the processor every minute, processing up to 20 messages at a time.</p>';
    
    echo '<h2>Manual Run</h2>';
    echo '<p>The script has been executed. Check the output above for results.</p>';
    
    llxFooter();
}
