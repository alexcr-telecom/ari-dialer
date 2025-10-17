<?php
/**
 * Test ARI POST /channels direct approach
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/ARI.php';

echo "<html><head><title>ARI Direct Call Test</title></head><body>";
echo "<h1>Testing ARI POST /channels Direct Approach</h1>";

try {
    $ari = new ARI();
    
    echo "<h2>Step 1: Testing ARI Connection</h2>";
    $connection = $ari->testConnection();
    if ($connection['success']) {
        echo "✅ " . $connection['message'] . "<br>";
    } else {
        echo "❌ " . $connection['message'] . "<br>";
        exit;
    }
    
    echo "<h2>Step 2: Test Direct Channel Creation</h2>";
    
    // Test parameters
    $testNumber = "1234567890"; // Replace with real test number
    $agentExtension = "101";
    
    echo "Creating outbound call to $testNumber<br>";
    echo "Will connect to agent extension $agentExtension when answered<br><br>";
    
    // Use direct ARI POST /channels approach
    $endpoint = "Local/{$testNumber}@dialer-outbound";
    
    $variables = [
        'CAMPAIGN_ID' => 'test_campaign',
        'LEAD_ID' => 'test_lead_123',
        'AGENT_EXTENSION' => $agentExtension,
        'CAMPAIGN_NAME' => 'Test Campaign'
    ];
    
    echo "POST /channels parameters:<br>";
    echo "Endpoint: $endpoint<br>";
    echo "App: " . Config::ARI_APP . "<br>";
    echo "AppArgs: $agentExtension<br>";
    echo "Variables: " . json_encode($variables) . "<br><br>";
    
    $response = $ari->makeRequest('POST', '/channels', [
        'endpoint' => $endpoint,
        'app' => Config::ARI_APP,
        'appArgs' => $agentExtension,
        'callerId' => $agentExtension,
        'timeout' => 30,
        'variables' => $variables
    ]);
    
    if ($response && isset($response['id'])) {
        echo "✅ Channel created successfully!<br>";
        echo "Channel ID: " . $response['id'] . "<br>";
        echo "Channel State: " . $response['state'] . "<br>";
        echo "Full Response:<br><pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
        
        // Check if ARI app was created
        echo "<h3>Checking ARI Applications</h3>";
        sleep(2); // Give it a moment
        
        try {
            $apps = $ari->makeRequest('GET', '/applications');
            if (!empty($apps)) {
                echo "✅ ARI Applications found:<br>";
                foreach ($apps as $app) {
                    echo "- " . $app['name'] . "<br>";
                }
            } else {
                echo "ℹ️ No applications visible yet (may take a moment)<br>";
            }
        } catch (Exception $e) {
            echo "Applications check: " . $e->getMessage() . "<br>";
        }
        
        // Provide hangup option
        echo "<br><form method='post'>";
        echo "<input type='hidden' name='channel_id' value='" . $response['id'] . "'>";
        echo "<button type='submit' name='hangup'>Hangup Test Call</button>";
        echo "</form>";
        
    } else {
        echo "❌ Channel creation failed<br>";
        echo "Response: " . json_encode($response) . "<br>";
    }
    
    // Handle hangup request
    if (isset($_POST['hangup']) && isset($_POST['channel_id'])) {
        $channelId = $_POST['channel_id'];
        echo "<h3>Hanging up channel: $channelId</h3>";
        
        try {
            $hangupResponse = $ari->hangupChannel($channelId);
            echo "✅ Channel hung up<br>";
        } catch (Exception $e) {
            echo "❌ Hangup failed: " . $e->getMessage() . "<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<br><h2>How It Works</h2>";
echo "<ol>";
echo "<li><strong>ARI POST /channels</strong> creates outbound call to Local/{NUMBER}@dialer-outbound</li>";
echo "<li><strong>dialer-outbound context</strong> routes call to PJSIP/{NUMBER}@trunk1</li>";
echo "<li><strong>When call is answered</strong> (ChannelStateChange event), ARI connects it to agent</li>";
echo "<li><strong>No Stasis() in dialplan needed</strong> - all managed via ARI app parameter</li>";
echo "</ol>";

echo "<br><h2>Monitor</h2>";
echo "<ul>";
echo "<li>Asterisk CLI: <code>sudo asterisk -rvvv</code></li>";
echo "<li>ARI Service logs: <code>sudo journalctl -u asterisk-dialer -f</code></li>";
echo "<li>Check channels: <code>sudo asterisk -rx \"core show channels\"</code></li>";
echo "<li>Check ARI apps: <code>sudo asterisk -rx \"ari show apps\"</code></li>";
echo "</ul>";

echo "</body></html>";
?>