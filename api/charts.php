<?php
/**
 * Charts & Analytics API Endpoint
 * 
 * Provides statistical data for charts and reporting
 * 
 * @version 2.0
 * @author ARI Dialer
 */

// CORS and Security Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Allow CORS for API access (configure as needed)
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

require_once __DIR__ . '/../classes/CDR.php';

/**
 * API Response Helper
 */
class ApiResponse {
    public static function success($data = null, $message = null, $code = 200) {
        http_response_code($code);
        $response = ['success' => true];
        if ($data !== null) $response['data'] = $data;
        if ($message !== null) $response['message'] = $message;
        echo json_encode($response);
        exit;
    }
    
    public static function error($message, $code = 400, $details = null) {
        http_response_code($code);
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code
        ];
        if ($details !== null) $response['details'] = $details;
        echo json_encode($response);
        exit;
    }
}

/**
 * Input Validation Helper
 */
class Validator {
    public static function validateDate($date) {
        if (empty($date)) return null;
        
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if ($dateObj && $dateObj->format('Y-m-d') === $date) {
            return $date;
        }
        
        $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if ($dateObj && $dateObj->format('Y-m-d H:i:s') === $date) {
            return $date;
        }
        
        ApiResponse::error('Invalid date format. Use Y-m-d or Y-m-d H:i:s', 400);
    }
    
    public static function validateId($id) {
        if (empty($id)) return null;
        if (!is_numeric($id) || $id <= 0) {
            ApiResponse::error('Invalid ID provided', 400);
        }
        return (int)$id;
    }
    
    public static function sanitizeString($string) {
        if (empty($string)) return null;
        return trim(strip_tags($string));
    }
}

// Only allow GET requests for this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405);
}

try {
    $cdr = new CDR();
    
    // Validate and sanitize filters
    $filters = [];
    
    $dateFrom = Validator::validateDate($_GET['date_from'] ?? '');
    if ($dateFrom) $filters['date_from'] = $dateFrom;
    
    $dateTo = Validator::validateDate($_GET['date_to'] ?? '');
    if ($dateTo) $filters['date_to'] = $dateTo;
    
    $campaignId = Validator::validateId($_GET['campaign_id'] ?? '');
    if ($campaignId) $filters['campaign_id'] = $campaignId;
    
    $agentExtension = Validator::sanitizeString($_GET['agent_extension'] ?? '');
    if ($agentExtension) $filters['agent_extension'] = $agentExtension;
    
    // Validate date range
    if ($dateFrom && $dateTo) {
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            ApiResponse::error('date_from cannot be after date_to', 400);
        }
        
        // Limit date range to 1 year maximum
        $daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / (60 * 60 * 24);
        if ($daysDiff > 365) {
            ApiResponse::error('Date range cannot exceed 365 days', 400);
        }
    }
    
    // Get chart type from query parameter
    $chartType = $_GET['type'] ?? 'overview';
    
    switch ($chartType) {
        case 'overview':
            $stats = $cdr->getStatistics($filters);
            
            $dispositions = [
                'ANSWERED' => (int)($stats['answered_calls'] ?? 0),
                'BUSY' => (int)($stats['busy_calls'] ?? 0),
                'NO ANSWER' => (int)($stats['no_answer_calls'] ?? 0),
                'FAILED' => (int)($stats['failed_calls'] ?? 0)
            ];
            
            $hourly = $cdr->getCallsByHour($filters);
            $daily = $cdr->getCallsByDate($filters);
            
            ApiResponse::success([
                'dispositions' => $dispositions,
                'hourly_calls' => $hourly,
                'daily_calls' => $daily,
                'statistics' => $stats,
                'filters_applied' => array_keys($filters),
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'dispositions':
            $stats = $cdr->getStatistics($filters);
            $dispositions = [
                'ANSWERED' => (int)($stats['answered_calls'] ?? 0),
                'BUSY' => (int)($stats['busy_calls'] ?? 0),
                'NO ANSWER' => (int)($stats['no_answer_calls'] ?? 0),
                'FAILED' => (int)($stats['failed_calls'] ?? 0)
            ];
            
            ApiResponse::success([
                'dispositions' => $dispositions,
                'total_calls' => array_sum($dispositions),
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'hourly':
            $hourly = $cdr->getCallsByHour($filters);
            ApiResponse::success([
                'hourly_calls' => $hourly,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'daily':
            $daily = $cdr->getCallsByDate($filters);
            ApiResponse::success([
                'daily_calls' => $daily,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'statistics':
            $stats = $cdr->getStatistics($filters);
            ApiResponse::success([
                'statistics' => $stats,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            ApiResponse::error('Invalid chart type. Valid types: overview, dispositions, hourly, daily, statistics', 400);
    }
    
} catch (Exception $e) {
    error_log("Charts API Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    ApiResponse::error('Internal server error', 500);
}