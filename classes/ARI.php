<?php
require_once __DIR__ . '/../config/config.php';

class ARI {
    private $baseUrl;
    private $username;
    private $password;
    private $app;
    
    public function __construct() {
        $this->baseUrl = 'http://' . Config::ARI_HOST . ':' . Config::ARI_PORT . '/ari';
        $this->username = Config::ARI_USER;
        $this->password = Config::ARI_PASS;
        $this->app = Config::ARI_APP;
    }
    
    public function originateCall($endpoint, $extension, $context = null, $priority = 1, $variables = [], $callerId = null) {
        $context = $context ?? Config::ASTERISK_CONTEXT;

        // Build URL parameters (endpoint, extension, context, etc.)
        $params = [
            'endpoint' => $endpoint,
            'extension' => $extension,
            'context' => $context,
            'priority' => $priority,
            'timeout' => 120
        ];

        // Add caller ID as URL parameters (caller[name] and caller[number])
        // Also add connected ID (connected[name] and connected[number])
        if ($callerId) {
            if (is_array($callerId)) {
                // Caller object with name and number
                if (!empty($callerId['name'])) {
                    $params['caller[name]'] = $callerId['name'];
                    $params['connected[name]'] = $callerId['name'];
                }
                if (!empty($callerId['number'])) {
                    $params['caller[number]'] = $callerId['number'];
                    $params['connected[number]'] = $callerId['number'];
                }
            } else {
                // Legacy string format - parse if it contains both name and number
                if (preg_match('/^"([^"]*)" <(.+)>$/', $callerId, $matches)) {
                    $params['caller[name]'] = $matches[1];
                    $params['caller[number]'] = $matches[2];
                    $params['connected[name]'] = $matches[1];
                    $params['connected[number]'] = $matches[2];
                } else {
                    // Just a number
                    $params['caller[number]'] = $callerId;
                    $params['connected[number]'] = $callerId;
                }
            }
        }

        // Build JSON body with variables object
        // ARI requires all variable values to be strings
        $body = null;
        if (!empty($variables)) {
            $stringVariables = [];
            foreach ($variables as $key => $value) {
                $stringVariables[$key] = (string)$value;
            }
            $body = [
                'variables' => $stringVariables
            ];
        }

        // Log the request data for debugging
        error_log("ARI Channel URL params: " . json_encode($params));
        error_log("ARI Channel JSON body: " . ($body ? json_encode($body) : 'null'));

        return $this->makeRequest('POST', '/channels', $params, $body);
    }

    /**
     * Create a caller ID object for ARI calls
     * @param string $name The caller name
     * @param string $number The caller number
     * @return array CallerID object
     */
    public function createCallerID($name = '', $number = '') {
        return [
            'name' => $name,
            'number' => $number
        ];
    }
    
    public function getChannels() {
        return $this->makeRequest('GET', '/channels', null, null);
    }

    public function getChannel($channelId) {
        return $this->makeRequest('GET', '/channels/' . $channelId, null, null);
    }

    public function hangupChannel($channelId, $reason = 'normal') {
        return $this->makeRequest('DELETE', '/channels/' . $channelId, ['reason' => $reason], null);
    }
    
    public function bridgeChannels($channelId1, $channelId2) {
        $bridgeId = $this->createBridge();
        if ($bridgeId) {
            $this->addChannelToBridge($bridgeId, $channelId1);
            $this->addChannelToBridge($bridgeId, $channelId2);
            return $bridgeId;
        }
        return false;
    }
    
    public function createBridge() {
        $result = $this->makeRequest('POST', '/bridges', ['type' => 'mixing'], null);
        return $result['id'] ?? false;
    }

    public function addChannelToBridge($bridgeId, $channelId) {
        return $this->makeRequest('POST', '/bridges/' . $bridgeId . '/addChannel', [
            'channel' => $channelId
        ], null);
    }

    public function startRecording($channelId, $name = null) {
        $name = $name ?? 'recording-' . $channelId . '-' . time();

        return $this->makeRequest('POST', '/channels/' . $channelId . '/record', [
            'name' => $name,
            'format' => 'wav',
            'maxDurationSeconds' => 3600,
            'maxSilenceSeconds' => 5,
            'ifExists' => 'overwrite'
        ], null);
    }

    public function getRecordings() {
        return $this->makeRequest('GET', '/recordings/stored', null, null);
    }

    public function playSound($channelId, $sound, $lang = 'en') {
        return $this->makeRequest('POST', '/channels/' . $channelId . '/play', [
            'media' => 'sound:' . $sound,
            'lang' => $lang
        ], null);
    }
    
    public function getWebSocketUrl() {
        return 'ws://' . Config::ARI_HOST . ':' . Config::ARI_PORT . '/ari/events?api_key=' .
               $this->username . ':' . $this->password . '&app=' . $this->app . '&subscribeAll=true';
    }
    
    public function subscribeToEvents($eventSource = null) {
        $params = ['app' => $this->app];
        if ($eventSource) {
            $params['eventSource'] = $eventSource;
        }

        return $this->makeRequest('POST', '/applications/' . $this->app . '/subscription', $params, null);
    }
    
    public function makeRequest($method, $endpoint, $params = null, $body = null) {
        $url = $this->baseUrl . $endpoint;

        // Add query parameters to URL if provided
        if ($params && is_array($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Log ARI request
        $requestLog = "[ARI REQUEST] " . date('Y-m-d H:i:s') . " - $method $endpoint";
        if ($params) {
            $requestLog .= " | Params: " . json_encode($params);
        }
        if ($body) {
            $requestLog .= " | Body: " . json_encode($body);
        }
        $this->logToErrorLog($requestLog);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => defined('CURL_HTTPAUTH_BASIC') ? CURL_HTTPAUTH_BASIC : 1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        // Send JSON body if provided
        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $jsonData = json_encode($body);
            error_log("ARI Request JSON body: " . $jsonData);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log ARI response
        $responseLog = "[ARI RESPONSE] " . date('Y-m-d H:i:s') . " - $method $endpoint | HTTP: $httpCode";
        if ($error) {
            $responseLog .= " | cURL Error: $error";
        } else {
            $responseLog .= " | Response: " . (strlen($response) > 1000 ? substr($response, 0, 1000) . '...[truncated]' : $response);
        }
        $this->logToErrorLog($responseLog);

        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception('HTTP Error ' . $httpCode . ': ' . $response);
        }

        return json_decode($response, true);
    }
    
    public function testConnection() {
        try {
            $result = $this->makeRequest('GET', '/asterisk/info', null, null);
            return [
                'success' => true,
                'message' => 'Connected to Asterisk ' . ($result['system']['version'] ?? 'Unknown'),
                'data' => $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    private function logToErrorLog($message) {
        $logMessage = $message . PHP_EOL;
        file_put_contents(__DIR__ . '/../logs/error.log', $logMessage, FILE_APPEND | LOCK_EX);
        error_log($message);
    }
}