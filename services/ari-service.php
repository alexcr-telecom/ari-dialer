<?php
/**
 * ARI Service - Background service for handling ARI events
 * This script should be run as a daemon to handle WebSocket connections
 * and process ARI events from Asterisk
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/ARI.php';
require_once __DIR__ . '/../classes/Dialer.php';

use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;

class ARIService {
    private $ari;
    private $dialer;
    private $running = false;
    private $websocket = null;
    private $loop;
    private $connector;
    
    public function __construct() {
        $this->ari = new ARI();
        $this->dialer = new Dialer();
        $this->loop = Loop::get();
        $this->connector = new Connector($this->loop);
        
        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    public function start() {
        $this->log("Starting ARI Service...");
        
        // First, create the ARI application
        if (!$this->createARIApplication()) {
            $this->log("Failed to create ARI application. Exiting.");
            return false;
        }
        
        $this->running = true;
        
        // Start WebSocket connection to ARI
        $this->connectWebSocket();
        
        return true;
    }
    
    private function createARIApplication() {
        try {
            $this->log("Checking ARI application: " . Config::ARI_APP);
            
            // Test ARI connection first
            $response = $this->ari->testConnection();
            if (!$response['success']) {
                $this->log("ARI connection failed: " . $response['message']);
                return false;
            }
            
            $this->log("ARI connection successful: " . $response['message']);
            
            // ARI applications are created automatically when:
            // 1. A channel enters Stasis() with the app name
            // 2. A WebSocket connects with the app name
            // 3. We make any request referencing the app
            
            // Just log that we're ready
            $this->log("ARI service ready for application: " . Config::ARI_APP);
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Error setting up ARI application: " . $e->getMessage());
            return false;
        }
    }
    
    private function connectWebSocket() {
        $this->log("Connecting to ARI WebSocket...");
        
        $wsUrl = $this->ari->getWebSocketUrl();
        $this->log("WebSocket URL: $wsUrl");
        
        // Try to establish WebSocket connection
        if ($this->establishWebSocketConnection()) {
            $this->log("WebSocket connection established successfully");
            $this->startWebSocketMode();
        } else {
            $this->log("WebSocket connection failed, falling back to polling mode");
            $this->startPollingMode();
        }
    }
    
    private function establishWebSocketConnection() {
        try {
            $wsUrl = $this->ari->getWebSocketUrl();
            $this->log("Attempting to connect to WebSocket: $wsUrl");
            
            $promise = $this->connector->__invoke($wsUrl);
            
            $promise->then(
                function (WebSocket $conn) {
                    $this->websocket = $conn;
                    $this->log("WebSocket connection established successfully");
                    
                    $conn->on('message', function ($msg) {
                        $this->handleWebSocketMessage($msg->getPayload());
                    });
                    
                    $conn->on('close', function ($code = null, $reason = null) {
                        $this->log("WebSocket connection closed. Code: $code, Reason: $reason");
                        $this->websocket = null;
                        
                        // Attempt to reconnect after 5 seconds
                        $this->loop->addTimer(5, function () {
                            if ($this->running) {
                                $this->log("Attempting to reconnect WebSocket...");
                                $this->establishWebSocketConnection();
                            }
                        });
                    });
                    
                    $conn->on('error', function (Exception $e) {
                        $this->log("WebSocket error: " . $e->getMessage());
                    });
                    
                    return true;
                },
                function (Exception $e) {
                    $this->log("WebSocket connection failed: " . $e->getMessage());
                    
                    // Attempt to reconnect after 10 seconds
                    $this->loop->addTimer(10, function () {
                        if ($this->running) {
                            $this->log("Attempting to reconnect WebSocket...");
                            $this->establishWebSocketConnection();
                        }
                    });
                    
                    return false;
                }
            );
            
            return true;
            
        } catch (Exception $e) {
            $this->log("WebSocket connection error: " . $e->getMessage());
            return false;
        }
    }
    
    private function startWebSocketMode() {
        $this->log("Starting WebSocket event monitoring with ReactPHP event loop");
        
        // Add periodic signal processing for graceful shutdown
        $this->loop->addPeriodicTimer(1.0, function () {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        });
        
        // Start the ReactPHP event loop
        $this->loop->run();
    }
    
    private function startPollingMode() {
        $this->log("Starting polling mode (checking for events every 2 seconds)");
        
        // Add periodic timer for polling
        $this->loop->addPeriodicTimer(2.0, function () {
            try {
                // Get current channels and process events
                $channels = $this->ari->getChannels();
                
                foreach ($channels as $channel) {
                    $this->processChannelEvent($channel);
                }
                
            } catch (Exception $e) {
                $this->log("Error in polling loop: " . $e->getMessage());
            }
        });
        
        // Add periodic signal processing for graceful shutdown
        $this->loop->addPeriodicTimer(1.0, function () {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        });
        
        // Start the ReactPHP event loop
        $this->loop->run();
    }
    
    private function handleWebSocketMessage($message) {
        try {
            $event = json_decode($message, true);
            
            if (!$event) {
                $this->log("Received invalid JSON message: $message");
                return;
            }
            
            $this->log("Received ARI event: " . $event['type'] ?? 'unknown');
            
            // Process the event through the dialer
            $this->dialer->handleChannelEvent($event);
            
        } catch (Exception $e) {
            $this->log("Error processing WebSocket message: " . $e->getMessage());
        }
    }
    
    private function processChannelEvent($channel) {
        // Create a mock event structure for the dialer
        $event = [
            'type' => 'ChannelStateChange',
            'channel' => $channel
        ];
        
        // Process the event through the dialer
        $this->dialer->handleChannelEvent($event);
    }
    
    public function shutdown($signal = null) {
        if ($signal) {
            $this->log("Received signal $signal, shutting down gracefully...");
        } else {
            $this->log("Shutting down ARI service...");
        }
        
        $this->running = false;
        
        if ($this->websocket) {
            // Close WebSocket connection if open
            $this->websocket->close();
            $this->websocket = null;
        }
        
        // Stop the ReactPHP event loop
        if ($this->loop) {
            $this->loop->stop();
        }
        
        $this->log("ARI Service shutdown complete");
        exit(0);
    }
    
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] ARI-SERVICE: $message\n";

        echo $logMessage;

        // Also log to files
        $logFile = __DIR__ . '/../logs/ari-service.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        $errorLogFile = __DIR__ . '/../logs/error.log';
        file_put_contents($errorLogFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    public function makeRequest($method, $endpoint, $data = null) {
        return $this->ari->makeRequest($method, $endpoint, $data);
    }
}

// Check if running from command line
if (php_sapi_name() === 'cli') {
    $service = new ARIService();
    $service->start();
} else {
    // Web interface for service management
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>ARI Service Management</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-5">
            <h1>ARI Service Management</h1>
            
            <div class="alert alert-info">
                <strong>Note:</strong> This service should be run from the command line as a background process.
            </div>
            
            <h3>Manual Commands</h3>
            <div class="card">
                <div class="card-body">
                    <h5>Start the service:</h5>
                    <code>php <?php echo __FILE__; ?> &amp;</code>
                    
                    <h5 class="mt-3">Start with nohup (recommended):</h5>
                    <code>nohup php <?php echo __FILE__; ?> > /dev/null 2>&1 &amp;</code>
                    
                    <h5 class="mt-3">Create systemd service:</h5>
                    <pre class="bg-light p-3">
[Unit]
Description=Asterisk Dialer ARI Service
After=network.target asterisk.service

[Service]
Type=simple
User=www-data
WorkingDirectory=<?php echo __DIR__; ?>
ExecStart=/usr/bin/php <?php echo __FILE__; ?>
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target</pre>
                </div>
            </div>
            
            <?php
            // Test ARI connection
            try {
                $ari = new ARI();
                $result = $ari->testConnection();
                
                if ($result['success']) {
                    echo '<div class="alert alert-success">ARI Connection: ' . $result['message'] . '</div>';
                } else {
                    echo '<div class="alert alert-danger">ARI Connection Failed: ' . $result['message'] . '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error testing ARI: ' . $e->getMessage() . '</div>';
            }
            ?>
            
        </div>
    </body>
    </html>
    <?php
}
?>