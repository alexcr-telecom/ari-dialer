<?php
/**
 * System Status API Endpoint
 * 
 * Provides system health and status information
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

require_once __DIR__ . '/../classes/ARI.php';
require_once __DIR__ . '/../config/config.php';

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

// Only allow GET requests for this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405);
}

try {
    $ari = new ARI();
    $status = [];
    
    // System Information
    $status['system'] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'php_version' => PHP_VERSION,
        'memory_usage' => [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ],
        'disk_usage' => [
            'free' => disk_free_space(__DIR__),
            'total' => disk_total_space(__DIR__)
        ]
    ];
    
    // Database Status
    try {
        $dsn = "mysql:host=" . Config::DB_HOST . ";dbname=" . Config::DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        $stmt = $pdo->query("SELECT COUNT(*) as campaign_count FROM campaigns");
        $campaigns = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COUNT(*) as lead_count FROM leads");
        $leads = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $status['database'] = [
            'status' => 'connected',
            'host' => Config::DB_HOST,
            'database' => Config::DB_NAME,
            'campaigns' => (int)$campaigns['campaign_count'],
            'leads' => (int)$leads['lead_count']
        ];
    } catch (PDOException $e) {
        $status['database'] = [
            'status' => 'error',
            'error' => 'Connection failed'
        ];
    }
    
    // Asterisk ARI Status
    try {
        $ariResponse = $ari->testConnection();
        
        if ($ariResponse['success']) {
            // Get additional ARI info
            $ariInfo = $ari->makeRequest('GET', '/asterisk/info');
            $channels = $ari->getChannels();
            
            $status['asterisk'] = [
                'status' => 'connected',
                'host' => Config::ARI_HOST . ':' . Config::ARI_PORT,
                'version' => $ariInfo['system']['version'] ?? 'unknown',
                'active_channels' => count($channels),
                'uptime' => $ariInfo['system']['startup_time'] ?? null
            ];
        } else {
            $status['asterisk'] = [
                'status' => 'error',
                'error' => $ariResponse['message']
            ];
        }
    } catch (Exception $e) {
        $status['asterisk'] = [
            'status' => 'error',
            'error' => 'Connection failed'
        ];
    }
    
    // ARI Service Status (WebSocket service)
    $ariServiceLogFile = __DIR__ . '/../logs/ari-service.log';
    if (file_exists($ariServiceLogFile)) {
        $lastLines = [];
        $handle = fopen($ariServiceLogFile, 'r');
        if ($handle) {
            fseek($handle, -2048, SEEK_END); // Read last 2KB
            $contents = fread($handle, 2048);
            fclose($handle);
            
            $lines = explode("\n", $contents);
            $lastLines = array_slice($lines, -10); // Get last 10 lines
            
            // Check if service is running based on recent log entries
            $lastEntry = end($lastLines);
            $isRunning = strpos($lastEntry, 'WebSocket connection established') !== false ||
                        strpos($lastEntry, 'Starting WebSocket event monitoring') !== false;
            
            $status['ari_service'] = [
                'status' => $isRunning ? 'running' : 'unknown',
                'log_file' => $ariServiceLogFile,
                'log_size' => filesize($ariServiceLogFile),
                'last_modified' => date('Y-m-d H:i:s', filemtime($ariServiceLogFile))
            ];
        }
    } else {
        $status['ari_service'] = [
            'status' => 'not_configured',
            'error' => 'Log file not found'
        ];
    }
    
    // Overall Health Check
    $healthChecks = [
        'database' => $status['database']['status'] === 'connected',
        'asterisk' => $status['asterisk']['status'] === 'connected',
        'php' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'memory' => memory_get_usage(true) < (1024 * 1024 * 512) // Less than 512MB
    ];
    
    $overallHealth = array_sum($healthChecks) === count($healthChecks);
    
    $status['health'] = [
        'overall' => $overallHealth ? 'healthy' : 'issues',
        'checks' => $healthChecks,
        'score' => round((array_sum($healthChecks) / count($healthChecks)) * 100, 2)
    ];
    
    // Get system load if available
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $status['system']['load_average'] = [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }
    
    // Service uptime (if available)
    if (file_exists('/proc/uptime')) {
        $uptime = file_get_contents('/proc/uptime');
        $uptime = explode(' ', $uptime);
        $status['system']['uptime_seconds'] = (float)$uptime[0];
        $status['system']['uptime_formatted'] = gmdate('H:i:s', $uptime[0]);
    }
    
    ApiResponse::success($status);
    
} catch (Exception $e) {
    error_log("Status API Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    ApiResponse::error('Failed to retrieve system status', 500);
}