<?php
/**
 * Script to create ARI application in Asterisk
 */

require_once __DIR__ . '/../config/config.php';

function createARIApp() {
    $ariUrl = "http://" . Config::ARI_HOST . ":" . Config::ARI_PORT . "/ari";
    $appName = Config::ARI_APP;
    
    echo "Creating ARI application: $appName\n";
    echo "ARI URL: $ariUrl\n\n";
    
    // First, try to get application info to see if it exists
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$ariUrl/applications/$appName",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => Config::ARI_USER . ':' . Config::ARI_PASS,
        CURLOPT_HTTPAUTH => defined('CURL_HTTPAUTH_BASIC') ? CURL_HTTPAUTH_BASIC : 1,
        CURLOPT_CUSTOMREQUEST => 'GET'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "❌ Error connecting to ARI: $error\n";
        return false;
    }
    
    if ($httpCode === 200) {
        echo "✅ ARI application '$appName' already exists\n";
        $appInfo = json_decode($response, true);
        echo "Application info: " . json_encode($appInfo, JSON_PRETTY_PRINT) . "\n";
        return true;
    }
    
    if ($httpCode === 404) {
        echo "ℹ️ Application doesn't exist, this is normal for new installations\n";
    }
    
    // Test basic ARI connectivity
    echo "Testing ARI connection...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$ariUrl/asterisk/info",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => Config::ARI_USER . ':' . Config::ARI_PASS,
        CURLOPT_HTTPAUTH => defined('CURL_HTTPAUTH_BASIC') ? CURL_HTTPAUTH_BASIC : 1
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "❌ ARI connection failed: $error\n";
        return false;
    }
    
    if ($httpCode !== 200) {
        echo "❌ ARI authentication failed: HTTP $httpCode\n";
        echo "Response: $response\n";
        return false;
    }
    
    $info = json_decode($response, true);
    echo "✅ Connected to Asterisk " . ($info['system']['version'] ?? 'unknown') . "\n\n";
    
    // The application will be automatically created when we connect to the WebSocket
    // or when we make our first request. Let's create a simple connection.
    
    echo "Note: ARI applications are created automatically when:\n";
    echo "1. A WebSocket connection is made to /ari/events?app=$appName\n";
    echo "2. Any ARI request is made that references the application\n";
    echo "3. A Stasis() dialplan application is called with the app name\n\n";
    
    return true;
}

function testWebSocketURL() {
    $wsUrl = "ws://" . Config::ARI_HOST . ":" . Config::ARI_PORT . "/ari/events";
    $params = http_build_query([
        'app' => Config::ARI_APP,
        'api_key' => Config::ARI_USER . ':' . Config::ARI_PASS
    ]);
    
    echo "WebSocket URL for testing: $wsUrl?$params\n";
    echo "\nTo manually test WebSocket connection, you can use:\n";
    echo "wscat -c '$wsUrl?$params'\n\n";
}

function updateDialplan() {
    echo "=== DIALPLAN CONFIGURATION ===\n";
    echo "Add this to your /etc/asterisk/extensions.conf:\n\n";
    
    echo "[from-internal]\n";
    echo "; ARI Dialer Application\n";
    echo "exten => _X.,1,NoOp(Dialer Call: Campaign \${CAMPAIGN_ID} calling \${EXTEN})\n";
    echo " same => n,Set(CALLERID(name)=Campaign \${CAMPAIGN_ID})\n";
    echo " same => n,Stasis(" . Config::ARI_APP . ",\${EXTEN})\n";
    echo " same => n,Hangup()\n\n";
    
    echo "; For internal extensions\n";
    echo "exten => _XXX,1,NoOp(Internal call to \${EXTEN})\n";
    echo " same => n,Dial(PJSIP/\${EXTEN},20)\n";
    echo " same => n,Hangup()\n\n";
    
    echo "After adding this, reload the dialplan:\n";
    echo "sudo asterisk -rx \"dialplan reload\"\n\n";
}

// Run the script
echo "=== ARI APPLICATION SETUP ===\n";

if (createARIApp()) {
    echo "\n";
    testWebSocketURL();
    echo "\n";
    updateDialplan();
    
    echo "=== NEXT STEPS ===\n";
    echo "1. Update your dialplan as shown above\n";
    echo "2. Reload Asterisk dialplan: sudo asterisk -rx \"dialplan reload\"\n";
    echo "3. Start the ARI service: sudo systemctl start asterisk-dialer\n";
    echo "4. Check service status: sudo systemctl status asterisk-dialer\n";
    echo "5. Test a call through the web interface\n\n";
    
    echo "The ARI application will be automatically created when:\n";
    echo "- The dialer service connects to the WebSocket\n";
    echo "- A call is made through the Stasis() dialplan application\n";
} else {
    echo "\n❌ Setup failed. Please check your ARI configuration.\n";
}
?>