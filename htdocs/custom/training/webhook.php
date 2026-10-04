<?php
/**
 * Stripe Webhook Endpoint for Training Module
 * 
 * Security features:
 * - Signature verification
 * - IP whitelist (Stripe's known IP ranges)
 * - Rate limiting
 * - File logging
 * - Error handling
 */

// Define constants for paths
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 4));
}

require_once DOL_DOCUMENT_ROOT.'/main.inc.php';
require_once __DIR__.'/class/trainingstore.class.php';
require_once __DIR__.'/class/trainingaccess.class.php';
require_once __DIR__.'/class/trainingstripeadapter.class.php';
require_once __DIR__.'/class/trainingcheckoutservice.class.php';

// ========================================================================
// CONFIGURATION
// ========================================================================

// Stripe's known IP ranges for webhooks (as of 2024)
// Source: https://stripe.com/docs/ips
$STRIPE_WEBHOOK_IPS = array(
    '3.18.11.0/22',
    '3.130.192.0/23',
    '13.235.14.237/32',
    '13.235.122.149/32',
    '18.211.135.69/32',
    '35.154.171.200/32',
    '52.15.183.38/32',
    '54.88.130.119/32',
    '54.88.130.237/32',
    '54.187.174.169/32',
    '54.187.205.235/32',
    '54.241.31.99/32',
    '54.241.31.102/32',
    '54.241.31.115/32',
    '52.14.241.134/32',
    '52.14.241.173/32',
    '52.14.241.174/32',
    '52.14.241.180/32',
    '52.14.241.184/32',
    '52.14.241.185/32',
    '52.14.241.203/32',
    '52.14.241.204/32'
);

// Rate limiting configuration
$RATE_LIMIT_MAX_REQUESTS = 100; // Max requests per minute
$RATE_LIMIT_WINDOW = 60; // Seconds

// ========================================================================
// RATE LIMITING
// ========================================================================

session_start();
$rateLimitKey = 'stripe_webhook_rate_limit_' . $_SERVER['REMOTE_ADDR'];
$rateLimitData = $_SESSION[$rateLimitKey] ?? array('count' => 0, 'timestamp' => time());

// Check if rate limit exceeded
if (time() - $rateLimitData['timestamp'] < $RATE_LIMIT_WINDOW) {
    if ($rateLimitData['count'] >= $RATE_LIMIT_MAX_REQUESTS) {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . ($RATE_LIMIT_WINDOW - (time() - $rateLimitData['timestamp'])));
        echo json_encode(array('error' => 'Rate limit exceeded'));
        exit;
    }
    $rateLimitData['count']++;
} else {
    $rateLimitData = array('count' => 1, 'timestamp' => time());
}
$_SESSION[$rateLimitKey] = $rateLimitData;

// ========================================================================
// IP WHITELIST CHECK
// ========================================================================

function ipInRange($ip, $cidr) {
    if (strpos($cidr, ':') !== false) {
        // IPv6
        $ip = inet_pton($ip);
        $network = inet_pton(substr($cidr, 0, strpos($cidr, '/')));
        $mask = (int) substr($cidr, strpos($cidr, '/') + 1);
        
        if ($ip === false || $network === false) {
            return false;
        }
        
        for ($i = 0; $i < 16; $i++) {
            $shift = 124 - ($i * 8);
            $networkBits = ($mask >= $shift + 8) ? 8 : ($mask - $shift);
            $maskBits = ($networkBits > 0) ? (0xff << (8 - $networkBits)) : 0;
            
            $ipByte = ord(substr($ip, $i, 1));
            $networkByte = ord(substr($network, $i, 1));
            
            if (($ipByte & $maskBits) != ($networkByte & $maskBits)) {
                return false;
            }
        }
        return true;
    } else {
        // IPv4
        list($subnet, $mask) = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = ~((1 << (32 - $mask)) - 1);
        return ($ipLong & $maskLong) == ($subnetLong & $maskLong);
    }
}

$clientIp = $_SERVER['REMOTE_ADDR'];
$ipAllowed = false;

foreach ($STRIPE_WEBHOOK_IPS as $cidr) {
    if (ipInRange($clientIp, $cidr)) {
        $ipAllowed = true;
        break;
    }
}

if (!$ipAllowed) {
    logWebhookRequest('IP_BLOCKED', $clientIp, null, null, 'IP not in Stripe whitelist');
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Access denied'));
    exit;
}

// ========================================================================
// REQUEST VALIDATION
// ========================================================================

// Check content type
if (!isset($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== 0) {
    logWebhookRequest('INVALID_CONTENT_TYPE', $clientIp, null, null, 'Content-Type: '.$_SERVER['CONTENT_TYPE']);
    http_response_code(415);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Invalid content type'));
    exit;
}

// Check for signature header
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    logWebhookRequest('MISSING_SIGNATURE', $clientIp, null, null, 'No Stripe-Signature header');
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Missing signature'));
    exit;
}

// ========================================================================
// LOAD CONFIGURATION
// ========================================================================

if (empty($conf->global->TRAINING_STRIPE_CONFIG)) {
    logWebhookRequest('NOT_CONFIGURED', $clientIp, null, null, 'Stripe not configured');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Stripe not configured'));
    exit;
}

$stripeConfig = json_decode($conf->global->TRAINING_STRIPE_CONFIG, true);
if (empty($stripeConfig['api_key']) || empty($stripeConfig['webhook_secret'])) {
    logWebhookRequest('INVALID_CONFIG', $clientIp, null, null, 'Missing API key or webhook secret');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Invalid Stripe configuration'));
    exit;
}

// ========================================================================
// INITIALIZE SERVICES
// ========================================================================

// Create access context (using system user for webhook processing)
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
$systemUser = new User($db);
$systemUser->fetch(1); // Assuming user ID 1 is admin/system user

$access = new TrainingAccess($systemUser, $conf->entity, (string) $conf->entity);
$store = new TrainingStore($db, $access);
$stripe = new TrainingStripeAdapter($store, $stripeConfig['api_key'], $stripeConfig['webhook_secret']);
$checkout = new TrainingCheckoutService($store, $stripe);

// ========================================================================
// PROCESS WEBHOOK
// ========================================================================

// Read raw body
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];

// Log the incoming request
logWebhookRequest('RECEIVED', $clientIp, $signature, null, 'Payload length: '.strlen($payload));

try {
    $result = $checkout->processWebhookEvent($payload, $signature);
    
    // Log successful processing
    logWebhookRequest('SUCCESS', $clientIp, $signature, $result['event_id'] ?? null, json_encode($result));
    
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($result);
} catch (RuntimeException $e) {
    logWebhookRequest('RUNTIME_ERROR', $clientIp, $signature, null, $e->getMessage());
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array('error' => $e->getMessage()));
} catch (Throwable $e) {
    logWebhookRequest('INTERNAL_ERROR', $clientIp, $signature, null, $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Internal server error'));
}

// ========================================================================
// LOGGING HELPER
// ========================================================================

/**
 * Log webhook requests to file.
 */
function logWebhookRequest(string $action, string $ip, ?string $signature, ?string $eventId, ?string $details): void
{
    global $conf;
    
    $logDir = $conf->training->dir_output ?? DOL_DOCUMENT_ROOT.'/documents/training';
    $logFile = $logDir.'/webhook.log';
    
    // Ensure directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf(
        "[%s] %s | IP: %s | Event: %s | Details: %s\n",
        $timestamp,
        $action,
        $ip,
        $eventId ?? 'N/A',
        $details ?? 'N/A'
    );
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
