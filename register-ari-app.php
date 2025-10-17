<?php
/**
 * Register ARI Application
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/ARI.php';

echo "=== Registering ARI Application ===\n";

try {
    $ari = new ARI();
    
    echo "Testing ARI connection...\n";
    $connection = $ari->testConnection();
    if (!$connection['success']) {
        echo "❌ ARI connection failed: " . $connection['message'] . "\n";
        exit(1);
    }
    echo "✅ " . $connection['message'] . "\n\n";
    
    echo "Attempting to register ARI app: " . Config::ARI_APP . "\n";
    
    // Method 1: Try to make a simple request that will create the app
    try {
        echo "Making test request to create application...\n";
        $response = $ari->makeRequest('GET', '/applications/' . Config::ARI_APP);
        echo "✅ Application already exists or was created\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), '404') !== false) {
            echo "Application doesn't exist yet (normal)\n";
        } else {
            echo "Request error: " . $e->getMessage() . "\n";
        }
    }
    
    // Method 2: Create a dummy channel that will register the app
    echo "\nCreating test channel to register ARI app...\n";
    
    try {
        $response = $ari->makeRequest('POST', '/channels', [
            'endpoint' => 'Local/test@from-internal',
            'app' => Config::ARI_APP,
            'appArgs' => 'registration_test'
        ]);
        
        if ($response && isset($response['id'])) {
            $channelId = $response['id'];
            echo "✅ Test channel created: $channelId\n";
            
            // Wait a moment
            sleep(1);
            
            // Hang up the test channel
            try {
                $ari->hangupChannel($channelId);
                echo "✅ Test channel hung up\n";
            } catch (Exception $e) {
                echo "Note: " . $e->getMessage() . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Test channel creation failed: " . $e->getMessage() . "\n";
        echo "This might be because 'test' extension doesn't exist in from-internal\n";
    }
    
    // Check if application is now registered
    echo "\nChecking ARI applications...\n";
    sleep(1);
    
    try {
        $apps = $ari->makeRequest('GET', '/applications');
        if (!empty($apps)) {
            echo "✅ ARI Applications found:\n";
            foreach ($apps as $app) {
                echo "  - " . $app['name'];
                if (isset($app['channel_ids'])) {
                    echo " (channels: " . count($app['channel_ids']) . ")";
                }
                echo "\n";
            }
        } else {
            echo "ℹ️  No applications visible yet\n";
        }
    } catch (Exception $e) {
        echo "Error checking applications: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Alternative Method ===\n";
echo "The ARI application will be automatically created when:\n";
echo "1. You make your first campaign call through the web interface\n";
echo "2. The ARI service connects to WebSocket events\n";
echo "3. Any channel is created with app='" . Config::ARI_APP . "'\n";

echo "\n=== Check Asterisk CLI ===\n";
echo "Run: sudo asterisk -rx \"ari show apps\"\n";
echo "After making a test call, you should see: " . Config::ARI_APP . "\n";
?>