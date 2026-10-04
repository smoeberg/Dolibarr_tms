<?php
/**
 * Training Public Catalog API
 * 
 * Endpoints:
 * - GET /api/training/v1/sessions - List available sessions with filters
 * - GET /api/training/v1/sessions/{id} - Get session details
 * 
 * Authentication: None (public API)
 * Rate limiting: Applied via Dolibarr's built-in mechanisms
 * Caching: Implemented with file-based cache
 */

// Ensure we're running in the correct context
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 5));
}

require_once DOL_DOCUMENT_ROOT.'/main.inc.php';
require_once __DIR__.'/../../../class/trainingstore.class.php';
require_once __DIR__.'/../../../class/trainingaccess.class.php';
require_once __DIR__.'/../../../class/trainingschedulingservice.class.php';
require_once __DIR__.'/../../../class/trainingcatalogservice.class.php';

// ========================================================================
// API CONFIGURATION
// ========================================================================

// Cache configuration
define('TRAINING_API_CACHE_DIR', $conf->training->dir_output ?? DOL_DOCUMENT_ROOT.'/documents/training/cache');
define('TRAINING_API_CACHE_TTL', 300); // 5 minutes in seconds

// ========================================================================
// REQUEST ROUTING
// ========================================================================

// Extract path info
$pathInfo = $_SERVER['PATH_INFO'] ?? '/';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Parse the request path
$pathParts = explode('/', trim($pathInfo, '/'));
array_shift($pathParts); // Remove 'api'
array_shift($pathParts); // Remove 'training'
array_shift($pathParts); // Remove 'v1'

$resource = $pathParts[0] ?? '';
$resourceId = $pathParts[1] ?? null;
$action = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ========================================================================
// CORS HEADERS (for public API)
// ========================================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if ($action === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ========================================================================
// CONTENT TYPE
// ========================================================================

header('Content-Type: application/json');

// ========================================================================
// REQUEST HANDLING
// ========================================================================

try {
    // Initialize access context (using anonymous user for public API)
    $access = createPublicAccess();
    $store = new TrainingStore($db, $access);
    $scheduling = new TrainingSchedulingService($store);
    $catalog = new TrainingCatalogService($db, $access);
    
    // Route to appropriate handler
    switch (true) {
        case $resource === 'sessions' && $resourceId === null:
            handleListSessions($scheduling, $catalog);
            break;
        
        case $resource === 'sessions' && $resourceId !== null:
            handleGetSession($scheduling, $catalog, (int) $resourceId);
            break;
        
        default:
            sendErrorResponse(404, 'Endpoint not found');
    }
} catch (Throwable $e) {
    sendErrorResponse(500, 'Internal server error: '.$e->getMessage());
}

// ========================================================================
// ACCESS CONTEXT FOR PUBLIC API
// ========================================================================

/**
 * Create a public access context with limited permissions.
 */
function createPublicAccess(): TrainingAccess
{
    global $conf;
    
    // Create a minimal user object for public access
    $publicUser = (object) array(
        'id' => 0,
        'socid' => null,
        'hasRight' => function($module, $domain, $right) {
            // Public API has read-only access to training data
            if ($module === 'training' && $right === 'read') {
                return true;
            }
            if ($module === 'service' && $right === 'lire') {
                return true;
            }
            return false;
        }
    );
    
    return new TrainingAccess($publicUser, (int) $conf->entity, (string) $conf->entity);
}

// ========================================================================
// SESSION LIST HANDLER
// ========================================================================

/**
 * Handle GET /sessions request.
 * 
 * Filters:
 * - course_id: Filter by course ID
 * - date_from: Filter sessions starting on or after this date (YYYY-MM-DD)
 * - date_to: Filter sessions starting on or before this date (YYYY-MM-DD)
 * - status: Filter by session status (open, closed, completed, cancelled, draft)
 * - capacity_min: Minimum capacity
 * - capacity_max: Maximum capacity
 */
function handleListSessions(TrainingSchedulingService $scheduling, TrainingCatalogService $catalog): void
{
    // Get filter parameters
    $courseId = $_GET['course_id'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $status = $_GET['status'] ?? null;
    $capacityMin = $_GET['capacity_min'] ?? null;
    $capacityMax = $_GET['capacity_max'] ?? null;
    $page = (int) ($_GET['page'] ?? 1);
    $limit = min((int) ($_GET['limit'] ?? 50), 100); // Max 100 per page
    $offset = ($page - 1) * $limit;
    
    // Validate parameters
    if ($courseId !== null && (!is_numeric($courseId) || (int) $courseId < 1)) {
        sendErrorResponse(400, 'Invalid course_id parameter');
    }
    
    if ($dateFrom !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        sendErrorResponse(400, 'Invalid date_from parameter. Use YYYY-MM-DD format.');
    }
    
    if ($dateTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        sendErrorResponse(400, 'Invalid date_to parameter. Use YYYY-MM-DD format.');
    }
    
    if ($status !== null && !in_array($status, array('open', 'closed', 'completed', 'cancelled', 'draft'), true)) {
        sendErrorResponse(400, 'Invalid status parameter. Allowed: open, closed, completed, cancelled, draft');
    }
    
    if ($capacityMin !== null && (!is_numeric($capacityMin) || (int) $capacityMin < 1)) {
        sendErrorResponse(400, 'Invalid capacity_min parameter');
    }
    
    if ($capacityMax !== null && (!is_numeric($capacityMax) || (int) $capacityMax < 1)) {
        sendErrorResponse(400, 'Invalid capacity_max parameter');
    }
    
    // Generate cache key
    $cacheKey = generateCacheKey(array(
        'resource' => 'sessions',
        'course_id' => $courseId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $status,
        'capacity_min' => $capacityMin,
        'capacity_max' => $capacityMax,
        'page' => $page,
        'limit' => $limit
    ));
    
    // Try to get from cache
    $cacheData = getCachedResponse($cacheKey);
    if ($cacheData !== null) {
        header('X-Cache: HIT');
        echo $cacheData;
        return;
    }
    
    header('X-Cache: MISS');
    
    // Get sessions
    try {
        $sessions = getFilteredSessions(
            $scheduling,
            $catalog,
            $courseId,
            $dateFrom,
            $dateTo,
            $status,
            $capacityMin,
            $capacityMax,
            $limit,
            $offset
        );
        
        $total = getFilteredSessionsCount(
            $scheduling,
            $courseId,
            $dateFrom,
            $dateTo,
            $status,
            $capacityMin,
            $capacityMax
        );
        
        $response = array(
            'success' => true,
            'data' => $sessions,
            'pagination' => array(
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil($total / $limit)
            )
        );
        
        // Cache the response
        cacheResponse($cacheKey, json_encode($response));
        
        echo json_encode($response, JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        sendErrorResponse(500, 'Failed to retrieve sessions: '.$e->getMessage());
    }
}

/**
 * Get sessions with filters applied.
 */
function getFilteredSessions(
    TrainingSchedulingService $scheduling,
    TrainingCatalogService $catalog,
    ?int $courseId,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $status,
    ?int $capacityMin,
    ?int $capacityMax,
    int $limit,
    int $offset
): array {
    global $db;
    
    $sessions = array();
    $entity = $db->entity;
    
    // Build the query
    $sql = 'SELECT s.rowid, s.ref, s.label, s.fk_course_version, s.capacity, s.status, s.timezone';
    $sql .= ' FROM '.$db->prefix().'training_session s';
    $sql .= ' JOIN '.$db->prefix().'training_course_version v ON v.rowid=s.fk_course_version AND v.entity=s.entity';
    $sql .= ' JOIN '.$db->prefix().'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
    $sql .= ' WHERE s.entity='.$entity.' AND v.status=\'published\'';
    
    $params = array();
    
    if ($courseId !== null) {
        $sql .= ' AND p.fk_product='.$courseId;
    }
    
    if ($status !== null) {
        $sql .= ' AND s.status='.$db->quote($status);
    } else {
        // Default to only open sessions for public API
        $sql .= ' AND s.status=\'open\'';
    }
    
    if ($capacityMin !== null) {
        $sql .= ' AND s.capacity >= '.(int) $capacityMin;
    }
    
    if ($capacityMax !== null) {
        $sql .= ' AND s.capacity <= '.(int) $capacityMax;
    }
    
    // Date filtering - we need to join with session_slot to get dates
    $sql .= ' AND EXISTS (';
    $sql .= '   SELECT 1 FROM '.$db->prefix().'training_session_slot slot';
    $sql .= '   WHERE slot.entity=s.entity AND slot.fk_session=s.rowid';
    
    if ($dateFrom !== null) {
        $sql .= '   AND slot.start_utc >= '.$db->quote($dateFrom.' 00:00:00');
    }
    
    if ($dateTo !== null) {
        $sql .= '   AND slot.start_utc <= '.$db->quote($dateTo.' 23:59:59');
    }
    
    $sql .= ' )';
    $sql .= ' ORDER BY s.rowid DESC LIMIT '.$limit.' OFFSET '.$offset;
    
    $result = $db->query($sql);
    
    if (!$result) {
        throw new RuntimeException('Database query failed');
    }
    
    $sessionIds = array();
    while ($row = $db->fetch_object($result)) {
        $sessionIds[] = (int) $row->rowid;
        $sessions[$row->rowid] = array(
            'session_id' => (int) $row->rowid,
            'ref' => $row->ref,
            'title' => $row->label,
            'status' => $row->status,
            'capacity' => (int) $row->capacity,
            'timezone' => $row->timezone
        );
    }
    
    // Get available seats for each session
    foreach ($sessionIds as $sessionId) {
        try {
            $detail = $scheduling->detail($sessionId);
            $sessions[$sessionId]['available_seats'] = $detail['available'];
            $sessions[$sessionId]['occupied_seats'] = $detail['occupied'];
            $sessions[$sessionId]['slots'] = array_map(function($slot) {
                return array(
                    'start_utc' => $slot->start_utc,
                    'end_utc' => $slot->end_utc,
                    'position' => (int) $slot->position
                );
            }, $detail['slots']);
        } catch (Throwable $e) {
            // Session not accessible, skip
            unset($sessions[$sessionId]);
        }
    }
    
    // Get price information
    foreach (array_keys($sessions) as $sessionId) {
        try {
            $priceInfo = getSessionPrice($sessionId);
            $sessions[$sessionId]['price_ttc'] = $priceInfo['price_ttc'];
            $sessions[$sessionId]['currency'] = $priceInfo['currency'];
        } catch (Throwable $e) {
            $sessions[$sessionId]['price_ttc'] = null;
            $sessions[$sessionId]['currency'] = 'DKK';
        }
    }
    
    return array_values($sessions);
}

/**
 * Get count of sessions matching filters.
 */
function getFilteredSessionsCount(
    TrainingSchedulingService $scheduling,
    ?int $courseId,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $status,
    ?int $capacityMin,
    ?int $capacityMax
): int {
    global $db;
    
    $entity = $db->entity;
    
    $sql = 'SELECT COUNT(*) as count';
    $sql .= ' FROM '.$db->prefix().'training_session s';
    $sql .= ' JOIN '.$db->prefix().'training_course_version v ON v.rowid=s.fk_course_version AND v.entity=s.entity';
    $sql .= ' JOIN '.$db->prefix().'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
    $sql .= ' WHERE s.entity='.$entity.' AND v.status=\'published\'';
    
    if ($courseId !== null) {
        $sql .= ' AND p.fk_product='.$courseId;
    }
    
    if ($status !== null) {
        $sql .= ' AND s.status='.$db->quote($status);
    } else {
        $sql .= ' AND s.status=\'open\'';
    }
    
    if ($capacityMin !== null) {
        $sql .= ' AND s.capacity >= '.(int) $capacityMin;
    }
    
    if ($capacityMax !== null) {
        $sql .= ' AND s.capacity <= '.(int) $capacityMax;
    }
    
    $sql .= ' AND EXISTS (';
    $sql .= '   SELECT 1 FROM '.$db->prefix().'training_session_slot slot';
    $sql .= '   WHERE slot.entity=s.entity AND slot.fk_session=s.rowid';
    
    if ($dateFrom !== null) {
        $sql .= '   AND slot.start_utc >= '.$db->quote($dateFrom.' 00:00:00');
    }
    
    if ($dateTo !== null) {
        $sql .= '   AND slot.start_utc <= '.$db->quote($dateTo.' 23:59:59');
    }
    
    $sql .= ' )';
    
    $result = $db->query($sql);
    if (!$result) {
        return 0;
    }
    
    $row = $db->fetch_object($result);
    return (int) ($row->count ?? 0);
}

/**
 * Get price for a session.
 */
function getSessionPrice(int $sessionId): array
{
    global $db;
    
    $sql = 'SELECT p.price, p.tva_tx, p.price_base_type as currency';
    $sql .= ' FROM '.$db->prefix().'training_session s';
    $sql .= ' JOIN '.$db->prefix().'training_course_version v ON v.rowid=s.fk_course_version AND v.entity=s.entity';
    $sql .= ' JOIN '.$db->prefix().'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
    $sql .= ' JOIN '.$db->prefix().'product prod ON prod.rowid=p.fk_product';
    $sql .= ' WHERE s.rowid='.$sessionId.' AND s.entity='.$db->entity;
    
    $result = $db->query($sql);
    if (!$result || !$db->fetch_object($result)) {
        throw new RuntimeException('Price not found for session');
    }
    
    $row = $db->fetch_object($result);
    $price = (float) $row->price;
    $tvaTx = (float) $row->tva_tx;
    $currency = $row->currency ?: 'DKK';
    
    $priceTtc = $price * (1 + ($tvaTx / 100));
    
    return array(
        'price_ttc' => number_format($priceTtc, 2, '.', ''),
        'currency' => $currency
    );
}

// ========================================================================
// SESSION DETAIL HANDLER
// ========================================================================

/**
 * Handle GET /sessions/{id} request.
 */
function handleGetSession(
    TrainingSchedulingService $scheduling,
    TrainingCatalogService $catalog,
    int $sessionId
): void {
    if ($sessionId < 1) {
        sendErrorResponse(400, 'Invalid session ID');
    }
    
    // Generate cache key
    $cacheKey = generateCacheKey(array(
        'resource' => 'session',
        'id' => $sessionId
    ));
    
    // Try to get from cache
    $cacheData = getCachedResponse($cacheKey);
    if ($cacheData !== null) {
        header('X-Cache: HIT');
        echo $cacheData;
        return;
    }
    
    header('X-Cache: MISS');
    
    try {
        // Get session details
        $detail = $scheduling->detail($sessionId);
        
        // Get course information
        $session = $detail['session'];
        $courseInfo = getCourseInfo((int) $session->fk_course_version);
        
        // Get price information
        $priceInfo = getSessionPrice($sessionId);
        
        // Get enrollment count
        $enrollmentCount = getEnrollmentCount($sessionId);
        
        $response = array(
            'success' => true,
            'data' => array(
                'session_id' => (int) $session->rowid,
                'ref' => $session->ref,
                'label' => $session->label,
                'status' => $session->status,
                'capacity' => (int) $session->capacity,
                'available_seats' => $detail['available'],
                'occupied_seats' => $detail['occupied'],
                'reserved_seats' => $detail['reserved'],
                'timezone' => $session->timezone,
                'currency' => $priceInfo['currency'],
                'price_ttc' => $priceInfo['price_ttc'],
                'course' => $courseInfo,
                'slots' => array_map(function($slot) {
                    return array(
                        'position' => (int) $slot->position,
                        'start_utc' => $slot->start_utc,
                        'end_utc' => $slot->end_utc,
                        'start_local' => convertToLocal($slot->start_utc, $session->timezone),
                        'end_local' => convertToLocal($slot->end_utc, $session->timezone)
                    );
                }, $detail['slots']),
                'enrollments' => array(
                    'count' => $enrollmentCount
                )
            )
        );
        
        // Cache the response
        cacheResponse($cacheKey, json_encode($response));
        
        echo json_encode($response, JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        sendErrorResponse(404, 'Session not found: '.$e->getMessage());
    }
}

/**
 * Get course information for a session.
 */
function getCourseInfo(int $versionId): array
{
    global $db;
    
    $sql = 'SELECT v.version_number, v.product_label_snapshot as label, p.ref as course_ref';
    $sql .= ' FROM '.$db->prefix().'training_course_version v';
    $sql .= ' JOIN '.$db->prefix().'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
    $sql .= ' WHERE v.rowid='.$versionId.' AND v.entity='.$db->entity;
    
    $result = $db->query($sql);
    if (!$result || !$db->fetch_object($result)) {
        throw new RuntimeException('Course version not found');
    }
    
    $row = $db->fetch_object($result);
    return array(
        'version_number' => (int) $row->version_number,
        'label' => $row->label,
        'ref' => $row->course_ref
    );
}

/**
 * Get enrollment count for a session.
 */
function getEnrollmentCount(int $sessionId): int
{
    global $db;
    
    $sql = 'SELECT COUNT(*) as count';
    $sql .= ' FROM '.$db->prefix().'training_enrollment e';
    $sql .= ' WHERE e.fk_session='.$sessionId.' AND e.entity='.$db->entity.' AND e.status=\'confirmed\'';
    
    $result = $db->query($sql);
    if (!$result) {
        return 0;
    }
    
    $row = $db->fetch_object($result);
    return (int) ($row->count ?? 0);
}

/**
 * Convert UTC timestamp to local time string.
 */
function convertToLocal(string $utcTimestamp, string $timezone): string
{
    try {
        $utcDate = new DateTime($utcTimestamp, new DateTimeZone('UTC'));
        $localDate = $utcDate->setTimezone(new DateTimeZone($timezone));
        return $localDate->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $utcTimestamp;
    }
}

// ========================================================================
// CACHING FUNCTIONS
// ========================================================================

/**
 * Generate a cache key from parameters.
 */
function generateCacheKey(array $params): string
{
    ksort($params);
    return 'training_api_'.md5(json_encode($params, JSON_THROW_ON_ERROR));
}

/**
 * Get cached response if exists and not expired.
 */
function getCachedResponse(string $cacheKey): ?string
{
    $cacheFile = getCacheFilePath($cacheKey);
    
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (!$cacheData || !isset($cacheData['timestamp'], $cacheData['data'])) {
        return null;
    }
    
    if (time() - $cacheData['timestamp'] > TRAINING_API_CACHE_TTL) {
        unlink($cacheFile);
        return null;
    }
    
    return $cacheData['data'];
}

/**
 * Cache a response.
 */
function cacheResponse(string $cacheKey, string $data): void
{
    ensureCacheDirExists();
    
    $cacheFile = getCacheFilePath($cacheKey);
    $cacheData = array(
        'timestamp' => time(),
        'data' => $data
    );
    
    file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
}

/**
 * Get cache file path.
 */
function getCacheFilePath(string $cacheKey): string
{
    return TRAINING_API_CACHE_DIR.'/'.$cacheKey.'.json';
}

/**
 * Ensure cache directory exists.
 */
function ensureCacheDirExists(): void
{
    if (!is_dir(TRAINING_API_CACHE_DIR)) {
        mkdir(TRAINING_API_CACHE_DIR, 0755, true);
    }
}

// ========================================================================
// ERROR HANDLING
// ========================================================================

/**
 * Send an error response.
 */
function sendErrorResponse(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(array(
        'success' => false,
        'error' => array(
            'code' => $statusCode,
            'message' => $message
        )
    ));
    exit;
}
