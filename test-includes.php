<?php
// Test script to verify all includes work properly

echo "<html><head><title>Include Test</title></head><body>";
echo "<h1>Testing Include Paths</h1>";

$tests = [
    'Config' => function() {
        require_once __DIR__ . '/config/config.php';
        return class_exists('Config');
    },
    'Database' => function() {
        require_once __DIR__ . '/config/database.php';
        return class_exists('Database');
    },
    'ErrorHandler' => function() {
        require_once __DIR__ . '/classes/ErrorHandler.php';
        return class_exists('ErrorHandler');
    },
    'Auth' => function() {
        require_once __DIR__ . '/classes/Auth.php';
        return class_exists('Auth');
    },
    'Campaign' => function() {
        require_once __DIR__ . '/classes/Campaign.php';
        return class_exists('Campaign');
    },
    'ARI' => function() {
        require_once __DIR__ . '/classes/ARI.php';
        return class_exists('ARI');
    },
    'Dialer' => function() {
        require_once __DIR__ . '/classes/Dialer.php';
        return class_exists('Dialer');
    },
    'CDR' => function() {
        require_once __DIR__ . '/classes/CDR.php';
        return class_exists('CDR');
    }
];

foreach ($tests as $name => $test) {
    try {
        $result = $test();
        echo $result ? "✅ $name: OK<br>" : "❌ $name: Class not found<br>";
    } catch (Exception $e) {
        echo "❌ $name: Error - " . $e->getMessage() . "<br>";
    }
}

// Test database connection
echo "<br><h2>Testing Database Connection</h2>";
try {
    $db = Database::getInstance()->getConnection();
    echo "✅ Database connection: OK<br>";
    
    // Test a simple query
    $stmt = $db->query("SELECT COUNT(*) as count FROM agents");
    $result = $stmt->fetch();
    echo "✅ Database query test: " . $result['count'] . " agents found<br>";
    
} catch (Exception $e) {
    echo "❌ Database connection: Error - " . $e->getMessage() . "<br>";
}

// Test ARI connection
echo "<br><h2>Testing ARI Connection</h2>";
try {
    $ari = new ARI();
    $result = $ari->testConnection();
    echo $result['success'] ? "✅ ARI connection: " . $result['message'] : "❌ ARI connection: " . $result['message'];
    echo "<br>";
} catch (Exception $e) {
    echo "❌ ARI connection: Error - " . $e->getMessage() . "<br>";
}

// Test API endpoint
echo "<br><h2>Testing API Endpoint</h2>";
$apiUrl = "http://localhost/ari-dialer/api/campaigns.php";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 400) {
    echo "✅ API endpoint: Responding (HTTP 400 expected without auth)<br>";
} else {
    echo "❌ API endpoint: HTTP $httpCode<br>";
}

echo "<br><a href='index.php'>Go to Main Application</a>";
echo "</body></html>";
?>