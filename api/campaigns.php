<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../classes/Campaign.php';
require_once __DIR__ . '/../classes/Dialer.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$campaign = new Campaign();
$dialer = new Dialer();

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $result = $campaign->getById($_GET['id']);
            } else {
                $filters = array_filter([
                    'name' => $_GET['name'] ?? '',
                    'status' => $_GET['status'] ?? '',
                    'limit' => $_GET['limit'] ?? 50,
                    'offset' => $_GET['offset'] ?? 0
                ]);
                $result = $campaign->getAll($filters);
            }
            break;
            
        case 'POST':
            $action = $input['action'] ?? '';
            
            switch ($action) {
                case 'start':
                    $result = $dialer->startCampaign($input['id']);
                    break;
                    
                case 'pause':
                    $result = $dialer->pauseCampaign($input['id']);
                    break;
                    
                case 'stop':
                    $result = $dialer->stopCampaign($input['id']);
                    break;
                    
                case 'create':
                    $result = ['success' => $campaign->create($input)];
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        case 'PUT':
            if (!isset($input['id'])) {
                throw new Exception('ID required for update');
            }
            $id = $input['id'];
            unset($input['id']);
            $result = ['success' => $campaign->update($id, $input)];
            break;
            
        case 'DELETE':
            if (!isset($_GET['id'])) {
                throw new Exception('ID required for delete');
            }
            $result = ['success' => $campaign->delete($_GET['id'])];
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}