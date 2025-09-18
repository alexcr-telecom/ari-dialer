<?php
/**
 * Call Logs API Endpoint
 *
 * Handles operations for call logs with detailed information
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

require_once __DIR__ . '/../config/database.php';

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

/**
 * Call Logs Class
 */
class CallLogs {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll($filters = []) {
        $whereClauses = [];
        $params = [];

        // Base query with joins
        $sql = "SELECT
                    cl.id,
                    cl.lead_id,
                    cl.campaign_id,
                    cl.phone_number,
                    cl.agent_extension,
                    cl.channel_id,
                    cl.call_start,
                    cl.call_end,
                    cl.duration,
                    cl.status,
                    cl.disposition,
                    cl.created_at,
                    c.name as campaign_name,
                    l.name as lead_name
                FROM call_logs cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                LEFT JOIN leads l ON cl.lead_id = l.id";

        // Apply filters
        if (!empty($filters['campaign_id'])) {
            $whereClauses[] = "cl.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }

        if (!empty($filters['status'])) {
            $whereClauses[] = "cl.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['phone_number'])) {
            $whereClauses[] = "cl.phone_number LIKE :phone_number";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $whereClauses[] = "cl.call_start >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $whereClauses[] = "cl.call_start <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        // Add WHERE clause if we have filters
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        // Add ordering
        $sql .= " ORDER BY cl.call_start DESC";

        // Add pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $filters['limit'];
            $params[':offset'] = $filters['offset'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT
                    cl.*,
                    c.name as campaign_name,
                    l.name as lead_name
                FROM call_logs cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                LEFT JOIN leads l ON cl.lead_id = l.id
                WHERE cl.id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getStats($campaignId = null) {
        $whereClause = $campaignId ? "WHERE campaign_id = :campaign_id" : "";
        $params = $campaignId ? [':campaign_id' => $campaignId] : [];

        $sql = "SELECT
                    COUNT(*) as total_calls,
                    COUNT(CASE WHEN status = 'answered' THEN 1 END) as answered_calls,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_calls,
                    COUNT(CASE WHEN status = 'initiated' THEN 1 END) as initiated_calls,
                    AVG(duration) as avg_duration,
                    DATE(call_start) as call_date
                FROM call_logs
                $whereClause
                GROUP BY DATE(call_start)
                ORDER BY call_date DESC
                LIMIT 30";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Initialize class
$callLogs = new CallLogs();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Get single call log
                $id = Validator::validateId($_GET['id']);
                $result = $callLogs->getById($id);

                if (!$result) {
                    ApiResponse::error('Call log not found', 404);
                }

                ApiResponse::success($result);
            } elseif (isset($_GET['stats'])) {
                // Get statistics
                $campaignId = !empty($_GET['campaign_id']) ? Validator::validateId($_GET['campaign_id']) : null;
                $result = $callLogs->getStats($campaignId);
                ApiResponse::success($result);
            } else {
                // List call logs with filters
                $filters = [];

                if (!empty($_GET['campaign_id'])) {
                    $filters['campaign_id'] = Validator::validateId($_GET['campaign_id']);
                }

                if (!empty($_GET['status'])) {
                    $status = Validator::sanitizeString($_GET['status']);
                    if (!in_array($status, ['initiated', 'ringing', 'answered', 'failed', 'hung_up'])) {
                        ApiResponse::error('Invalid status filter', 400);
                    }
                    $filters['status'] = $status;
                }

                if (!empty($_GET['phone_number'])) {
                    $filters['phone_number'] = Validator::sanitizeString($_GET['phone_number'], 20);
                }

                if (!empty($_GET['date_from'])) {
                    $filters['date_from'] = Validator::sanitizeString($_GET['date_from'], 10);
                }

                if (!empty($_GET['date_to'])) {
                    $filters['date_to'] = Validator::sanitizeString($_GET['date_to'], 10);
                }

                $pagination = Validator::validatePagination(
                    $_GET['limit'] ?? 50,
                    $_GET['offset'] ?? 0
                );
                $filters['limit'] = $pagination[0];
                $filters['offset'] = $pagination[1];

                $result = $callLogs->getAll($filters);
                ApiResponse::success($result);
            }
            break;

        default:
            ApiResponse::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Call Logs API Error: " . $e->getMessage());
    ApiResponse::error('Internal server error', 500);
}
?>