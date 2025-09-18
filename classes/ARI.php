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

        $data = [
            'endpoint' => $endpoint,
            'extension' => $extension,
            'context' => $context,
            'priority' => $priority,
            'timeout' => 120
        ];

        if ($callerId) {
            $data['callerId'] = $callerId;
        }

        if (!empty($variables)) {
            foreach ($variables as $key => $value) {
                $data['variables[' . $key . ']'] = $value;
            }
        }

        return $this->makeRequest('POST', '/channels', $data);
    }
    
    public function getChannels() {
        return $this->makeRequest('GET', '/channels');
    }
    
    public function getChannel($channelId) {
        return $this->makeRequest('GET', '/channels/' . $channelId);
    }
    
    public function hangupChannel($channelId, $reason = 'normal') {
        return $this->makeRequest('DELETE', '/channels/' . $channelId, ['reason' => $reason]);
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
        $result = $this->makeRequest('POST', '/bridges', ['type' => 'mixing']);
        return $result['id'] ?? false;
    }
    
    public function addChannelToBridge($bridgeId, $channelId) {
        return $this->makeRequest('POST', '/bridges/' . $bridgeId . '/addChannel', [
            'channel' => $channelId
        ]);
    }
    
    public function startRecording($channelId, $name = null) {
        $name = $name ?? 'recording-' . $channelId . '-' . time();
        
        return $this->makeRequest('POST', '/channels/' . $channelId . '/record', [
            'name' => $name,
            'format' => 'wav',
            'maxDurationSeconds' => 3600,
            'maxSilenceSeconds' => 5,
            'ifExists' => 'overwrite'
        ]);
    }
    
    public function getRecordings() {
        return $this->makeRequest('GET', '/recordings/stored');
    }
    
    public function playSound($channelId, $sound, $lang = 'en') {
        return $this->makeRequest('POST', '/channels/' . $channelId . '/play', [
            'media' => 'sound:' . $sound,
            'lang' => $lang
        ]);
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
        
        return $this->makeRequest('POST', '/applications/' . $this->app . '/subscription', $params);
    }
    
    public function makeRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;

        // Log ARI request
        $requestLog = "[ARI REQUEST] " . date('Y-m-d H:i:s') . " - $method $endpoint";
        if ($data) {
            $requestLog .= " | Data: " . json_encode($data);
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

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            if ($method === 'POST' && $endpoint === '/channels') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
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
            $result = $this->makeRequest('GET', '/asterisk/info');
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