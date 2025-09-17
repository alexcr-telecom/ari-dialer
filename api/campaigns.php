<?php
/**
 * Campaigns API Endpoint
 * 
 * Handles CRUD operations for campaigns and campaign control actions
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
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

require_once __DIR__ . '/../classes/Campaign.php';
require_once __DIR__ . '/../classes/Dialer.php';

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
    public static function validateId($id) {
        if (!$id || !is_numeric($id) || $id <= 0) {
            ApiResponse::error('Invalid ID provided', 400);
        }
        return (int)$id;
    }
    
    public static function validatePagination($limit, $offset) {
        $limit = max(1, min(100, (int)$limit)); // Limit between 1-100
        $offset = max(0, (int)$offset);
        return [$limit, $offset];
    }
    
    public static function sanitizeString($string, $maxLength = 255) {
        return substr(trim(strip_tags($string)), 0, $maxLength);
    }
}

// Initialize classes
$campaign = new Campaign();
$dialer = new Dialer();
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Parse JSON input for POST/PUT requests
    $input = [];
    if (in_array($method, ['POST', 'PUT'])) {
        $raw_input = file_get_contents('php://input');
        if ($raw_input) {
            $input = json_decode($raw_input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                ApiResponse::error('Invalid JSON in request body', 400);
            }
        }
    }

    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $id = Validator::validateId($_GET['id']);
                $result = $campaign->getById($id);
                
                if (!$result) {
                    ApiResponse::error('Campaign not found', 404);
                }
                
                ApiResponse::success($result);
            } else {
                // List campaigns with filters
                $filters = [];
                
                if (!empty($_GET['name'])) {
                    $filters['name'] = Validator::sanitizeString($_GET['name']);
                }
                
                if (!empty($_GET['status'])) {
                    $status = Validator::sanitizeString($_GET['status']);
                    if (!in_array($status, ['active', 'paused', 'stopped', 'completed'])) {
                        ApiResponse::error('Invalid status filter', 400);
                    }
                    $filters['status'] = $status;
                }
                
                $pagination = Validator::validatePagination(
                    $_GET['limit'] ?? 50,
                    $_GET['offset'] ?? 0
                );
                $limit = $pagination[0];
                $offset = $pagination[1];
                
                $filters['limit'] = $limit;
                $filters['offset'] = $offset;
                
                $result = $campaign->getAll($filters);
                ApiResponse::success($result);
            }
            break;
            
        case 'POST':
            if (empty($input['action'])) {
                ApiResponse::error('Action required', 400);
            }
            
            $action = Validator::sanitizeString($input['action']);
            
            switch ($action) {
                case 'start':
                    if (empty($input['id'])) {
                        ApiResponse::error('Campaign ID required', 400);
                    }
                    $id = Validator::validateId($input['id']);
                    $result = $dialer->startCampaign($id);
                    
                    if ($result['success']) {
                        ApiResponse::success(null, 'Campaign started successfully');
                    } else {
                        ApiResponse::error($result['message'] ?? 'Failed to start campaign', 500);
                    }
                    break;
                    
                case 'pause':
                    if (empty($input['id'])) {
                        ApiResponse::error('Campaign ID required', 400);
                    }
                    $id = Validator::validateId($input['id']);
                    $result = $dialer->pauseCampaign($id);
                    
                    if ($result['success']) {
                        ApiResponse::success(null, 'Campaign paused successfully');
                    } else {
                        ApiResponse::error($result['message'] ?? 'Failed to pause campaign', 500);
                    }
                    break;
                    
                case 'stop':
                    if (empty($input['id'])) {
                        ApiResponse::error('Campaign ID required', 400);
                    }
                    $id = Validator::validateId($input['id']);
                    $result = $dialer->stopCampaign($id);
                    
                    if ($result['success']) {
                        ApiResponse::success(null, 'Campaign stopped successfully');
                    } else {
                        ApiResponse::error($result['message'] ?? 'Failed to stop campaign', 500);
                    }
                    break;
                    
                case 'create':
                    // Validate required fields
                    $requiredFields = ['name', 'context', 'max_calls_per_minute'];
                    foreach ($requiredFields as $field) {
                        if (empty($input[$field])) {
                            ApiResponse::error("Field '$field' is required", 400);
                        }
                    }
                    
                    // Sanitize input
                    $campaignData = [
                        'name' => Validator::sanitizeString($input['name']),
                        'context' => Validator::sanitizeString($input['context']),
                        'max_calls_per_minute' => max(1, min(1000, (int)$input['max_calls_per_minute'])),
                        'agent_extension' => Validator::sanitizeString($input['agent_extension'] ?? ''),
                        'caller_id' => Validator::sanitizeString($input['caller_id'] ?? ''),
                        'description' => Validator::sanitizeString($input['description'] ?? '', 1000)
                    ];
                    
                    $result = $campaign->create($campaignData);
                    
                    if ($result) {
                        ApiResponse::success(['id' => $result], 'Campaign created successfully', 201);
                    } else {
                        ApiResponse::error('Failed to create campaign', 500);
                    }
                    break;
                    
                default:
                    ApiResponse::error('Invalid action', 400);
            }
            break;
            
        case 'PUT':
            if (empty($input['id'])) {
                ApiResponse::error('Campaign ID required', 400);
            }
            
            $id = Validator::validateId($input['id']);
            unset($input['id']);
            
            // Sanitize update data
            $updateData = [];
            $allowedFields = ['name', 'context', 'max_calls_per_minute', 'agent_extension', 'caller_id', 'description'];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    switch ($field) {
                        case 'max_calls_per_minute':
                            $updateData[$field] = max(1, min(1000, (int)$input[$field]));
                            break;
                        case 'description':
                            $updateData[$field] = Validator::sanitizeString($input[$field], 1000);
                            break;
                        default:
                            $updateData[$field] = Validator::sanitizeString($input[$field]);
                    }
                }
            }
            
            if (empty($updateData)) {
                ApiResponse::error('No valid fields to update', 400);
            }
            
            $result = $campaign->update($id, $updateData);
            
            if ($result) {
                ApiResponse::success(null, 'Campaign updated successfully');
            } else {
                ApiResponse::error('Failed to update campaign or campaign not found', 404);
            }
            break;
            
        case 'DELETE':
            if (empty($_GET['id'])) {
                ApiResponse::error('Campaign ID required', 400);
            }
            
            $id = Validator::validateId($_GET['id']);
            $result = $campaign->delete($id);
            
            if ($result) {
                ApiResponse::success(null, 'Campaign deleted successfully');
            } else {
                ApiResponse::error('Failed to delete campaign or campaign not found', 404);
            }
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    ApiResponse::error('Internal server error', 500);
}