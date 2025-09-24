<?php
/**
 * ARI WebSocket Client
 * Connects to Asterisk ARI WebSocket, creates application, and receives events
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Dialer.php';

class ARIWebSocketClient {
    private $wsUrl = 'ws://localhost:8088/ari/events?api_key=ari_user:ari_password&app=dialer_app';
    private $httpUrl = 'http://localhost:8088/ari/';
    private $apiKey = 'ari_user:ari_password';
    private $appName = 'dialer_app';
    private $running = false;
    private $dialer;
    private $lastChannelStates = [];
    
    public function __construct() {
        // Initialize dialer for event handling
        $this->dialer = new Dialer();

        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    public function start() {
        $this->log("Starting ARI WebSocket Client...");
        
        // Create ARI application first
        if (!$this->createApplication()) {
            $this->log("Failed to create ARI application. Continuing anyway...");
        }
        
        // Connect to WebSocket
        $this->connectWebSocket();
    }
    
    private function createApplication() {
        $this->log("Creating/Verifying ARI application: " . $this->appName);
        
        try {
            // Check if application exists
            $response = $this->makeHttpRequest('GET', "applications/{$this->appName}");
            
            if ($response['success']) {
                $this->log("Application already exists: " . json_encode($response['data']));
                return true;
            }
            
            // Application doesn't exist, create it
            $this->log("Application doesn't exist, will be created automatically on WebSocket connection");
            return true;
            
        } catch (Exception $e) {
            $this->log("Error checking application: " . $e->getMessage());
            return false;
        }
    }
    
    private function connectWebSocket() {
        $this->log("Connecting to ARI WebSocket: " . $this->wsUrl);
        
        // Use ReactPHP or Ratchet for real WebSocket, but for now we'll simulate with polling
        // In production, you'd want to use a proper WebSocket library
        
        $this->log("Note: Using polling simulation instead of real WebSocket");
        $this->log("For real WebSocket, install: composer require ratchet/pawl");
        
        $this->startEventPolling();
    }
    
    private function startEventPolling() {
        $this->log("Starting event polling mode (simulating WebSocket)...");
        $this->running = true;
        
        while ($this->running) {
            try {
                // Process signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                // Get events from ARI
                $this->checkForEvents();
                
                // Sleep for 1 second
                sleep(1);
                
            } catch (Exception $e) {
                $this->log("Error in polling loop: " . $e->getMessage());
                sleep(2);
            }
        }
    }
    
    private function checkForEvents() {
        // Get current channels and bridges to simulate events
        $channels = $this->getChannels();
        $bridges = $this->getBridges();

        $currentChannelIds = [];

        if (!empty($channels)) {
            foreach ($channels as $channel) {
                $currentChannelIds[] = $channel['id'];
                $this->handleChannelEvent($channel);
            }
        }

        // Check for destroyed channels (channels that were active but are no longer in the list)
        foreach ($this->lastChannelStates as $channelId => $lastState) {
            if (!in_array($channelId, $currentChannelIds)) {
                $this->log("Channel destroyed: $channelId (was in state: $lastState)");
                $this->handleChannelDestroyed($channelId);
                unset($this->lastChannelStates[$channelId]);
            }
        }

        if (!empty($bridges)) {
            foreach ($bridges as $bridge) {
                $this->handleBridgeEvent($bridge);
            }
        }
    }
    
    private function getChannels() {
        $response = $this->makeHttpRequest('GET', 'channels');
        return $response['success'] ? $response['data'] : [];
    }
    
    private function getBridges() {
        $response = $this->makeHttpRequest('GET', 'bridges');
        return $response['success'] ? $response['data'] : [];
    }
    
    private function handleChannelEvent($channel) {
        $channelId = $channel['id'];
        $currentState = $channel['state'];
        $lastState = $this->lastChannelStates[$channelId] ?? null;

        // Only process if state has actually changed
        if ($lastState !== $currentState) {
            $this->log("Channel State Changed: $channelId - $lastState -> $currentState");

            $event = [
                'type' => 'ChannelStateChange',
                'timestamp' => date('c'),
                'channel' => $channel,
                'application' => $this->appName
            ];

            $this->processEvent($event);
            $this->lastChannelStates[$channelId] = $currentState;
        }

    }

    private function handleChannelDestroyed($channelId, $channel = null) {
        $this->log("Processing channel destroyed: $channelId");

        $event = [
            'type' => 'ChannelDestroyed',
            'timestamp' => date('c'),
            'channel' => $channel ?: ['id' => $channelId, 'state' => 'Down'],
            'cause' => 16, // Normal clearing
            'cause_txt' => 'Normal Clearing',
            'application' => $this->appName
        ];

        // Use Dialer's event handling to update call logs
        $this->dialer->handleChannelEvent($event);
    }
    
    private function handleBridgeEvent($bridge) {
        $event = [
            'type' => 'BridgeCreated',
            'timestamp' => date('c'),
            'bridge' => $bridge,
            'application' => $this->appName
        ];
        
        $this->log("Bridge Event: " . $bridge['id'] . " - Type: " . $bridge['bridge_type']);
        $this->processEvent($event);
    }
    
    private function processEvent($event) {
        // Process the event - this is where you'd handle different event types
        switch ($event['type']) {
            case 'ChannelStateChange':
                $this->handleChannelStateChange($event);
                break;

            case 'ChannelDestroyed':
                // Event already processed by handleChannelDestroyed, no additional action needed
                $this->log("Channel destroyed event processed: " . $event['channel']['id']);
                break;

            case 'BridgeCreated':
                $this->handleBridgeCreated($event);
                break;

            default:
                $this->log("Unhandled event type: " . $event['type']);
        }
    }
    
    private function handleChannelStateChange($event) {
        $channel = $event['channel'];
        $this->log("Processing channel state change: {$channel['id']} -> {$channel['state']}");

        // Use Dialer's event handling to update call logs
        $this->dialer->handleChannelEvent($event);
    }
    
    private function handleBridgeCreated($event) {
        $bridge = $event['bridge'];
        $this->log("Processing bridge created: {$bridge['id']}");
    }
    
    // ARI Command Methods
    public function answerChannel($channelId) {
        $this->log("Answering channel: $channelId");
        return $this->makeHttpRequest('POST', "channels/$channelId/answer");
    }
    
    public function hangupChannel($channelId) {
        $this->log("Hanging up channel: $channelId");
        return $this->makeHttpRequest('DELETE', "channels/$channelId");
    }
    
    public function playSound($channelId, $media) {
        $this->log("Playing sound to channel $channelId: $media");
        $data = ['media' => $media];
        return $this->makeHttpRequest('POST', "channels/$channelId/play", $data);
    }
    
    public function createBridge() {
        $this->log("Creating bridge");
        $data = ['type' => 'mixing'];
        return $this->makeHttpRequest('POST', 'bridges', $data);
    }
    
    public function addChannelToBridge($bridgeId, $channelId) {
        $this->log("Adding channel $channelId to bridge $bridgeId");
        $data = ['channel' => $channelId];
        return $this->makeHttpRequest('POST', "bridges/$bridgeId/addChannel", $data);
    }
    
    private function makeHttpRequest($method, $endpoint, $data = null) {
        $url = $this->httpUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                }
                break;
                
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                }
                break;
                
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'message' => "CURL Error: $error"];
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $decodedResponse,
                'http_code' => $httpCode
            ];
        } else {
            return [
                'success' => false,
                'message' => "HTTP $httpCode: " . ($decodedResponse['message'] ?? $response),
                'http_code' => $httpCode
            ];
        }
    }
    
    public function shutdown($signal = null) {
        if ($signal) {
            $this->log("Received signal $signal, shutting down...");
        } else {
            $this->log("Shutting down ARI WebSocket client...");
        }
        
        $this->running = false;
        $this->log("ARI WebSocket client shutdown complete");
        exit(0);
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] ARI-WS-CLIENT: $message\n";
    }
    
    // Interactive command mode
    public function interactiveMode() {
        $this->log("Entering interactive mode. Type 'help' for commands, 'quit' to exit.");
        
        while (true) {
            echo "\nari> ";
            $input = trim(fgets(STDIN));
            
            if ($input === 'quit' || $input === 'exit') {
                break;
            }
            
            $this->processCommand($input);
        }
    }
    
    private function processCommand($command) {
        $parts = explode(' ', $command);
        $cmd = $parts[0];
        
        switch ($cmd) {
            case 'help':
                $this->showHelp();
                break;
                
            case 'channels':
                $channels = $this->getChannels();
                $this->log("Active channels: " . count($channels));
                foreach ($channels as $channel) {
                    echo "  - {$channel['id']} ({$channel['state']}) - {$channel['name']}\n";
                }
                break;
                
            case 'bridges':
                $bridges = $this->getBridges();
                $this->log("Active bridges: " . count($bridges));
                foreach ($bridges as $bridge) {
                    echo "  - {$bridge['id']} ({$bridge['bridge_type']})\n";
                }
                break;
                
            case 'answer':
                if (isset($parts[1])) {
                    $result = $this->answerChannel($parts[1]);
                    $this->log("Answer result: " . json_encode($result));
                } else {
                    echo "Usage: answer <channel_id>\n";
                }
                break;
                
            case 'hangup':
                if (isset($parts[1])) {
                    $result = $this->hangupChannel($parts[1]);
                    $this->log("Hangup result: " . json_encode($result));
                } else {
                    echo "Usage: hangup <channel_id>\n";
                }
                break;
                
            case 'play':
                if (isset($parts[1]) && isset($parts[2])) {
                    $result = $this->playSound($parts[1], $parts[2]);
                    $this->log("Play result: " . json_encode($result));
                } else {
                    echo "Usage: play <channel_id> <sound_file>\n";
                }
                break;
                
            default:
                echo "Unknown command: $cmd. Type 'help' for available commands.\n";
        }
    }
    
    private function showHelp() {
        echo "\nAvailable commands:\n";
        echo "  help                    - Show this help\n";
        echo "  channels                - List active channels\n";
        echo "  bridges                 - List active bridges\n";
        echo "  answer <channel_id>     - Answer a channel\n";
        echo "  hangup <channel_id>     - Hangup a channel\n";
        echo "  play <channel_id> <file> - Play sound to channel\n";
        echo "  quit/exit               - Exit interactive mode\n";
    }
}

// Check command line arguments
if (php_sapi_name() === 'cli') {
    $client = new ARIWebSocketClient();
    
    if (isset($argv[1]) && $argv[1] === 'interactive') {
        $client->interactiveMode();
    } else {
        $client->start();
    }
} else {
    echo "This script must be run from command line\n";
    echo "Usage: php ari-websocket-client.php [interactive]\n";
}
?>