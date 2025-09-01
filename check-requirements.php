<?php
/**
 * System Requirements Check for Asterisk Auto-Dialer
 */

echo "<html><head><title>System Requirements Check</title></head><body>";
echo "<h1>Asterisk Auto-Dialer - System Requirements Check</h1>";

$errors = [];
$warnings = [];
$checks = [];

// Check PHP version
$phpVersion = PHP_VERSION;
$minPhpVersion = '7.4.0';
if (version_compare($phpVersion, $minPhpVersion, '>=')) {
    $checks[] = "✅ PHP Version: $phpVersion (minimum $minPhpVersion required)";
} else {
    $errors[] = "❌ PHP Version: $phpVersion (minimum $minPhpVersion required)";
}

// Check required PHP extensions
$requiredExtensions = [
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'curl' => 'cURL',
    'json' => 'JSON',
    'session' => 'Session',
    'mbstring' => 'Multibyte String',
    'openssl' => 'OpenSSL'
];

foreach ($requiredExtensions as $ext => $name) {
    if (extension_loaded($ext)) {
        $checks[] = "✅ $name extension loaded";
    } else {
        $errors[] = "❌ $name extension not loaded";
    }
}

// Check cURL constants
if (extension_loaded('curl')) {
    if (defined('CURL_HTTPAUTH_BASIC')) {
        $checks[] = "✅ cURL HTTP Auth constants available";
    } else {
        $warnings[] = "⚠️ CURL_HTTPAUTH_BASIC constant not defined (will use fallback)";
    }
}

// Check configuration file
if (file_exists(__DIR__ . '/config/config.php')) {
    $checks[] = "✅ Configuration file exists";
    
    // Try to load config
    try {
        require_once __DIR__ . '/config/config.php';
        $checks[] = "✅ Configuration file loads successfully";
        
        // Test database connection
        try {
            $dsn = "mysql:host=" . Config::DB_HOST . ";dbname=" . Config::DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $checks[] = "✅ Database connection successful";
        } catch (PDOException $e) {
            $errors[] = "❌ Database connection failed: " . $e->getMessage();
        }
        
        // Test ARI connection
        $ariUrl = "http://" . Config::ARI_HOST . ":" . Config::ARI_PORT . "/ari/asterisk/info";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $ariUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERPWD => Config::ARI_USER . ':' . Config::ARI_PASS,
            CURLOPT_HTTPAUTH => defined('CURL_HTTPAUTH_BASIC') ? CURL_HTTPAUTH_BASIC : 1,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $errors[] = "❌ ARI connection failed: $error";
        } elseif ($httpCode === 200) {
            $checks[] = "✅ ARI connection successful";
            $ariInfo = json_decode($response, true);
            if ($ariInfo && isset($ariInfo['system']['version'])) {
                $checks[] = "✅ Asterisk version: " . $ariInfo['system']['version'];
            }
        } else {
            $errors[] = "❌ ARI connection failed: HTTP $httpCode";
        }
        
    } catch (Exception $e) {
        $errors[] = "❌ Configuration file error: " . $e->getMessage();
    }
} else {
    $errors[] = "❌ Configuration file missing (copy config/config.php.example to config/config.php)";
}

// Check directories
$directories = [
    'uploads' => __DIR__ . '/uploads',
    'logs' => __DIR__ . '/logs',
    'recordings' => __DIR__ . '/recordings'
];

foreach ($directories as $name => $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    
    if (is_dir($path) && is_writable($path)) {
        $checks[] = "✅ $name directory exists and is writable";
    } elseif (is_dir($path)) {
        $warnings[] = "⚠️ $name directory exists but is not writable";
    } else {
        $errors[] = "❌ $name directory missing and could not be created";
    }
}

// Check web server
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$checks[] = "ℹ️ Web server: $serverSoftware";

// Display results
echo "<h2>Results</h2>";

if (!empty($checks)) {
    echo "<h3>Passed Checks</h3><ul>";
    foreach ($checks as $check) {
        echo "<li>$check</li>";
    }
    echo "</ul>";
}

if (!empty($warnings)) {
    echo "<h3 style='color: orange;'>Warnings</h3><ul>";
    foreach ($warnings as $warning) {
        echo "<li style='color: orange;'>$warning</li>";
    }
    echo "</ul>";
}

if (!empty($errors)) {
    echo "<h3 style='color: red;'>Errors</h3><ul>";
    foreach ($errors as $error) {
        echo "<li style='color: red;'>$error</li>";
    }
    echo "</ul>";
    
    echo "<h3>Next Steps</h3>";
    echo "<p>Please fix the errors above before proceeding. Common solutions:</p>";
    echo "<ul>";
    echo "<li><strong>Missing PHP extensions:</strong> Install via package manager (e.g., <code>apt install php-curl php-mysql</code>)</li>";
    echo "<li><strong>Database connection:</strong> Verify credentials in config/config.php and ensure MySQL is running</li>";
    echo "<li><strong>ARI connection:</strong> Check Asterisk configuration and ensure ARI is enabled</li>";
    echo "<li><strong>Directory permissions:</strong> Run <code>chmod 777 uploads logs recordings</code></li>";
    echo "</ul>";
} else {
    echo "<h3 style='color: green;'>✅ All requirements met!</h3>";
    echo "<p>Your system is ready to run the Asterisk Auto-Dialer.</p>";
    echo "<p><a href='index.php'>Launch Application</a></p>";
}

echo "<hr>";
echo "<h3>System Information</h3>";
echo "<ul>";
echo "<li><strong>PHP Version:</strong> " . PHP_VERSION . "</li>";
echo "<li><strong>Operating System:</strong> " . PHP_OS . "</li>";
echo "<li><strong>Server API:</strong> " . php_sapi_name() . "</li>";
echo "<li><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</li>";
echo "<li><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . "s</li>";
echo "<li><strong>Upload Max Filesize:</strong> " . ini_get('upload_max_filesize') . "</li>";
echo "<li><strong>Current Time:</strong> " . date('Y-m-d H:i:s T') . "</li>";
echo "</ul>";

echo "</body></html>";
?>