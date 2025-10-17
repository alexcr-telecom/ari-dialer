<?php
/**
 * Leads API Endpoint
 * 
 * Handles CRUD operations for campaign leads
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
    
    public static function validatePhone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if phone number is valid (10-15 digits)
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            return false;
        }
        
        return $phone;
    }
    
    public static function validatePagination($limit, $offset) {
        $limit = max(1, min(1000, (int)$limit)); // Limit between 1-1000
        $offset = max(0, (int)$offset);
        return [$limit, $offset];
    }
    
    public static function sanitizeString($string, $maxLength = 255) {
        return substr(trim(strip_tags($string)), 0, $maxLength);
    }
}

// Initialize classes
$campaign = new Campaign();
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
            if (isset($_GET['campaign_id'])) {
                $campaignId = Validator::validateId($_GET['campaign_id']);
                
                // Get specific lead or list leads for campaign
                if (isset($_GET['id'])) {
                    $leadId = Validator::validateId($_GET['id']);
                    $result = $campaign->getLeadById($campaignId, $leadId);
                    
                    if (!$result) {
                        ApiResponse::error('Lead not found', 404);
                    }
                    
                    ApiResponse::success($result);
                } else {
                    // List leads with filters
                    $filters = ['campaign_id' => $campaignId];
                    
                    if (!empty($_GET['status'])) {
                        $status = Validator::sanitizeString($_GET['status']);
                        if (!in_array($status, ['pending', 'dialed', 'answered', 'busy', 'no_answer', 'failed'])) {
                            ApiResponse::error('Invalid status filter', 400);
                        }
                        $filters['status'] = $status;
                    }
                    
                    if (!empty($_GET['phone'])) {
                        $phone = Validator::validatePhone($_GET['phone']);
                        if (!$phone) {
                            ApiResponse::error('Invalid phone number format', 400);
                        }
                        $filters['phone'] = $phone;
                    }
                    
                    [$limit, $offset] = Validator::validatePagination(
                        $_GET['limit'] ?? 100, 
                        $_GET['offset'] ?? 0
                    );
                    
                    $filters['limit'] = $limit;
                    $filters['offset'] = $offset;
                    
                    $result = $campaign->getLeads($filters);
                    ApiResponse::success($result);
                }
            } else {
                ApiResponse::error('Campaign ID required', 400);
            }
            break;
            
        case 'POST':
            if (empty($input['campaign_id'])) {
                ApiResponse::error('Campaign ID required', 400);
            }
            
            $campaignId = Validator::validateId($input['campaign_id']);
            $action = $input['action'] ?? 'create';
            
            switch ($action) {
                case 'create':
                    if (empty($input['phone'])) {
                        ApiResponse::error('Phone number required', 400);
                    }
                    
                    $phone = Validator::validatePhone($input['phone']);
                    if (!$phone) {
                        ApiResponse::error('Invalid phone number format', 400);
                    }
                    
                    $leadData = [
                        'campaign_id' => $campaignId,
                        'phone' => $phone,
                        'first_name' => Validator::sanitizeString($input['first_name'] ?? ''),
                        'last_name' => Validator::sanitizeString($input['last_name'] ?? ''),
                        'email' => filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
                        'status' => 'pending',
                        'priority' => max(1, min(10, (int)($input['priority'] ?? 5)))
                    ];
                    
                    $result = $campaign->addLead($leadData);
                    
                    if ($result) {
                        ApiResponse::success(['id' => $result], 'Lead created successfully', 201);
                    } else {
                        ApiResponse::error('Failed to create lead', 500);
                    }
                    break;
                    
                case 'bulk_import':
                    if (empty($input['leads']) || !is_array($input['leads'])) {
                        ApiResponse::error('Leads array required for bulk import', 400);
                    }
                    
                    $leads = [];
                    $errors = [];
                    
                    foreach ($input['leads'] as $index => $leadData) {
                        if (empty($leadData['phone'])) {
                            $errors[] = "Row $index: Phone number required";
                            continue;
                        }
                        
                        $phone = Validator::validatePhone($leadData['phone']);
                        if (!$phone) {
                            $errors[] = "Row $index: Invalid phone number format";
                            continue;
                        }
                        
                        $leads[] = [
                            'campaign_id' => $campaignId,
                            'phone' => $phone,
                            'first_name' => Validator::sanitizeString($leadData['first_name'] ?? ''),
                            'last_name' => Validator::sanitizeString($leadData['last_name'] ?? ''),
                            'email' => filter_var($leadData['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
                            'status' => 'pending',
                            'priority' => max(1, min(10, (int)($leadData['priority'] ?? 5)))
                        ];
                    }
                    
                    if (!empty($errors)) {
                        ApiResponse::error('Validation errors in bulk import', 400, $errors);
                    }
                    
                    $result = $campaign->bulkImportLeads($leads);
                    
                    if ($result['success']) {
                        ApiResponse::success([
                            'imported' => $result['imported'],
                            'skipped' => $result['skipped'],
                            'total' => count($input['leads'])
                        ], 'Bulk import completed', 201);
                    } else {
                        ApiResponse::error('Bulk import failed', 500);
                    }
                    break;
                    
                default:
                    ApiResponse::error('Invalid action', 400);
            }
            break;
            
        case 'PUT':
            if (empty($input['campaign_id'])) {
                ApiResponse::error('Campaign ID required', 400);
            }
            
            if (empty($input['id'])) {
                ApiResponse::error('Lead ID required', 400);
            }
            
            $campaignId = Validator::validateId($input['campaign_id']);
            $leadId = Validator::validateId($input['id']);
            
            // Sanitize update data
            $updateData = [];
            $allowedFields = ['phone', 'first_name', 'last_name', 'email', 'status', 'priority'];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    switch ($field) {
                        case 'phone':
                            $phone = Validator::validatePhone($input[$field]);
                            if (!$phone) {
                                ApiResponse::error('Invalid phone number format', 400);
                            }
                            $updateData[$field] = $phone;
                            break;
                        case 'email':
                            $email = filter_var($input[$field], FILTER_VALIDATE_EMAIL);
                            $updateData[$field] = $email ?: '';
                            break;
                        case 'status':
                            $status = Validator::sanitizeString($input[$field]);
                            if (!in_array($status, ['pending', 'dialed', 'answered', 'busy', 'no_answer', 'failed'])) {
                                ApiResponse::error('Invalid status', 400);
                            }
                            $updateData[$field] = $status;
                            break;
                        case 'priority':
                            $updateData[$field] = max(1, min(10, (int)$input[$field]));
                            break;
                        default:
                            $updateData[$field] = Validator::sanitizeString($input[$field]);
                    }
                }
            }
            
            if (empty($updateData)) {
                ApiResponse::error('No valid fields to update', 400);
            }
            
            $result = $campaign->updateLead($campaignId, $leadId, $updateData);
            
            if ($result) {
                ApiResponse::success(null, 'Lead updated successfully');
            } else {
                ApiResponse::error('Failed to update lead or lead not found', 404);
            }
            break;
            
        case 'DELETE':
            if (empty($_GET['campaign_id'])) {
                ApiResponse::error('Campaign ID required', 400);
            }
            
            if (empty($_GET['id'])) {
                ApiResponse::error('Lead ID required', 400);
            }
            
            $campaignId = Validator::validateId($_GET['campaign_id']);
            $leadId = Validator::validateId($_GET['id']);
            
            $result = $campaign->deleteLead($campaignId, $leadId);
            
            if ($result) {
                ApiResponse::success(null, 'Lead deleted successfully');
            } else {
                ApiResponse::error('Failed to delete lead or lead not found', 404);
            }
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Leads API Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    ApiResponse::error('Internal server error', 500);
}