<?php
/**
 * Test WebSocket connection to ARI
 * This will register the dialer_app automatically
 */

require_once __DIR__ . '/config/config.php';

class WebSocketTest {
    private $url;
    
    public function __construct() {
        // Build WebSocket URL with credentials from config
        $this->url = sprintf(
            'ws://%s:%d/ari/events?api_key=%s:%s&app=%s',
            Config::ARI_HOST,
            Config::ARI_PORT,
            Config::ARI_USER,
            Config::ARI_PASS,
            Config::ARI_APP
        );
    }
    
    public function testConnection() {
        echo "Testing WebSocket connection to ARI...\n";
        echo "URL: " . $this->url . "\n\n";
        
        // Convert ws:// to http:// for cURL test
        $httpUrl = str_replace('ws://', 'http://', $this->url);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $httpUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Connection: Upgrade',
            'Upgrade: websocket',
            'Sec-WebSocket-Version: 13',
            'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
            'Sec-WebSocket-Protocol: echo-protocol'
        ]);
        
        // Follow redirects and get detailed info
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        echo "HTTP Response Code: $httpCode\n";
        echo "Content Type: $contentType\n";
        
        if ($error) {
            echo "cURL Error: $error\n";
        }
        
        if ($response) {
            echo "Response: " . substr($response, 0, 200) . "...\n";
        }
        
        // Check what this means
        switch($httpCode) {
            case 101:
                echo "\n✅ SUCCESS: WebSocket upgrade successful!\n";
                echo "The ARI application 'dialer_app' should now be registered.\n";
                break;
                
            case 400:
                echo "\n⚠️  WebSocket handshake failed, but app may be registered\n";
                break;
                
            case 401:
                echo "\n❌ AUTHENTICATION FAILED: Check ARI_USER and ARI_PASS in config\n";
                break;
                
            case 404:
                echo "\n❌ ENDPOINT NOT FOUND: Check ARI_HOST and ARI_PORT in config\n";
                break;
                
            default:
                echo "\n❓ Unexpected response code: $httpCode\n";
        }
        
        return $httpCode;
    }
    
    public function testWithWscat() {
        echo "\n=== Alternative: Test with wscat ===\n";
        echo "If you have wscat installed, run:\n";
        echo "wscat -c \"" . $this->url . "\"\n";
        echo "\nOr install wscat with: npm install -g wscat\n";
    }
}

// Run the test
$test = new WebSocketTest();
$httpCode = $test->testConnection();
$test->testWithWscat();

// Now check if the app is registered
echo "\n=== Checking ARI Application Registration ===\n";

try {
    require_once __DIR__ . '/classes/ARI.php';
    $ari = new ARI();
    
    $apps = $ari->makeRequest('GET', '/applications');
    
    if (!empty($apps)) {
        echo "✅ Registered ARI Applications:\n";
        foreach ($apps as $app) {
            echo "  - " . $app['name'];
            if (isset($app['channel_ids'])) {
                echo " (channels: " . count($app['channel_ids']) . ")";
            }
            echo "\n";
            
            if ($app['name'] === Config::ARI_APP) {
                echo "    ✅ dialer_app is registered!\n";
            }
        }
    } else {
        echo "ℹ️  No ARI applications found yet\n";
        echo "This is normal - apps are created on first connection\n";
    }
    
} catch (Exception $e) {
    echo "Error checking applications: " . $e->getMessage() . "\n";
}

echo "\n=== Next Steps ===\n";
echo "1. Restart the dialer service: sudo systemctl restart asterisk-dialer\n";
echo "2. Check Asterisk CLI: sudo asterisk -rx 'ari show apps'\n";
echo "3. Make a test call through the campaign interface\n";
?>