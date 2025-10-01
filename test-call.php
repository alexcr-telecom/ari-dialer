<?php
/**
 * Test script to verify ARI integration works
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/ARI.php';

echo "<html><head><title>ARI Call Test</title></head><body>";
echo "<h1>Testing ARI Call Origination</h1>";

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
    
    echo "<h2>Step 2: Current ARI Applications</h2>";
    try {
        $apps = $ari->makeRequest('GET', '/applications');
        echo "Current applications: " . json_encode($apps) . "<br>";
    } catch (Exception $e) {
        echo "No applications yet (this is normal): " . $e->getMessage() . "<br>";
    }
    
    echo "<h2>Step 3: Test Call Origination</h2>";
    
    // Test call parameters
    $testNumber = "1234567890"; // Replace with a real test number
    $agentExtension = "101";
    
    echo "Attempting to originate call to $testNumber via extension $agentExtension<br>";
    
    // Create a Local channel that will trigger Stasis
    $endpoint = "Local/$testNumber@from-internal";
    $extension = $agentExtension;
    
    $variables = [
        'CAMPAIGN_ID' => 'test',
        'LEAD_ID' => 'test123'
    ];

    // Test the new CallerID object format (follows ARI schema)
    $callerId = $ari->createCallerID('Test Lead Name', $testNumber);
    echo "Using CallerID object: " . json_encode($callerId) . "<br>";
    echo "This will be sent as 'caller' field in ARI request per schema<br>";

    $response = $ari->originateCall(
        $endpoint,
        $extension,
        'from-internal',
        1,
        $variables,
        $callerId
    );
    
    if ($response && isset($response['id'])) {
        echo "✅ Call originated successfully!<br>";
        echo "Channel ID: " . $response['id'] . "<br>";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "<br>";
        
        echo "<h3>Checking ARI Applications Again</h3>";
        try {
            $apps = $ari->makeRequest('GET', '/applications');
            echo "Applications after call: " . json_encode($apps, JSON_PRETTY_PRINT) . "<br>";
        } catch (Exception $e) {
            echo "Applications: " . $e->getMessage() . "<br>";
        }
        
    } else {
        echo "❌ Call origination failed<br>";
        echo "Response: " . json_encode($response) . "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<br><h2>Next Steps</h2>";
echo "<ul>";
echo "<li>If the call was successful, check Asterisk CLI: <code>sudo asterisk -rvvv</code></li>";
echo "<li>Check ARI applications: <code>sudo asterisk -rx \"ari show apps\"</code></li>";
echo "<li>Check service logs: <code>sudo journalctl -u asterisk-dialer -f</code></li>";
echo "<li>Test the web interface: <a href='index.php'>Login to Dialer</a></li>";
echo "</ul>";

echo "</body></html>";
?>