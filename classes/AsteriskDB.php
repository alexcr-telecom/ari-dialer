<?php
/**
 * Asterisk Database Helper
 * For accessing FreePBX/Asterisk database
 */

require_once __DIR__ . '/../config/config.php';

class AsteriskDB {
    private static $instance = null;
    private $connection = null;
    private $available = null;

    private function __construct() {
        // Don't auto-connect, check availability first
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if Asterisk database is available and configured
     */
    public function isAvailable() {
        if ($this->available !== null) {
            return $this->available;
        }

        // Check if Asterisk database configuration exists
        if (!defined('Config::ASTERISK_DB_HOST') ||
            !Config::ASTERISK_DB_HOST ||
            !Config::ASTERISK_DB_NAME ||
            !Config::ASTERISK_DB_USER) {
            error_log("Asterisk/FreePBX Database not configured - running in standalone mode");
            $this->available = false;
            return false;
        }

        // Test connection
        $this->available = $this->testConnection();
        if (!$this->available) {
            error_log("Asterisk/FreePBX Database not available - running in standalone mode");
        }

        return $this->available;
    }

    private function connect() {
        if (!$this->isAvailable()) {
            throw new Exception("Asterisk Database not available");
        }

        try {
            $dsn = Config::getAsteriskDSN();
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];

            $this->connection = new PDO($dsn, Config::ASTERISK_DB_USER, Config::ASTERISK_DB_PASS, $options);

        } catch (PDOException $e) {
            error_log("Asterisk Database connection failed: " . $e->getMessage());
            $this->available = false;
            throw new Exception("Asterisk Database connection failed");
        }
    }

    public function getConnection() {
        if (!$this->isAvailable()) {
            return null;
        }

        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    public function testConnection() {
        try {
            // Don't use getConnection() here to avoid recursion
            $dsn = Config::getAsteriskDSN();
            $pdo = new PDO($dsn, Config::ASTERISK_DB_USER, Config::ASTERISK_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5 // 5 second timeout
            ]);
            $pdo->query("SELECT 1");
            return true;
        } catch (Exception $e) {
            error_log("Asterisk Database test failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all IVRs from the database
     * @return array
     */
    public function getIVRs() {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return [];

            $stmt = $conn->prepare("
                SELECT id, name, description
                FROM ivr_details
                ORDER BY name ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching IVRs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all Queues from the database
     * @return array
     */
    public function getQueues() {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return [];

            $stmt = $conn->prepare("
                SELECT extension, descr as description, maxwait
                FROM queues_config
                ORDER BY extension ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching Queues: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all Extensions from the database
     * @return array
     */
    public function getExtensions() {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return [];

            $stmt = $conn->prepare("
                SELECT extension, name, sipname
                FROM users
                WHERE extension IS NOT NULL AND extension != ''
                ORDER BY extension ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching Extensions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get custom extensions
     * @return array
     */
    public function getCustomExtensions() {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return [];

            $stmt = $conn->prepare("
                SELECT custom_exten as extension, description
                FROM custom_extensions
                ORDER BY custom_exten ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching Custom Extensions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get IVR details by ID
     * @param int $ivrId
     * @return array|null
     */
    public function getIVRById($ivrId) {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return null;

            $stmt = $conn->prepare("
                SELECT * FROM ivr_details WHERE id = ?
            ");
            $stmt->execute([$ivrId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error fetching IVR details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get Queue details by extension
     * @param string $extension
     * @return array|null
     */
    public function getQueueByExtension($extension) {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return null;

            $stmt = $conn->prepare("
                SELECT * FROM queues_config WHERE extension = ?
            ");
            $stmt->execute([$extension]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error fetching Queue details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get Extension details by extension number
     * @param string $extension
     * @return array|null
     */
    public function getExtensionByNumber($extension) {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $conn = $this->getConnection();
            if (!$conn) return null;

            $stmt = $conn->prepare("
                SELECT * FROM users WHERE extension = ?
            ");
            $stmt->execute([$extension]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error fetching Extension details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse extensions_additional.conf for context mapping
     * @return array
     */
    public function parseExtensionsAdditional() {
        if (!$this->isAvailable()) {
            return [];
        }

        $contexts = [];
        $configFile = '/etc/asterisk/extensions_additional.conf';

        if (!file_exists($configFile)) {
            error_log("extensions_additional.conf not found");
            return $contexts;
        }

        try {
            $content = file_get_contents($configFile);
            $lines = explode("\n", $content);
            $currentContext = null;

            foreach ($lines as $line) {
                $line = trim($line);

                // Match context headers like [ivr-1] ; ivr1
                if (preg_match('/^\[([^\]]+)\](?:\s*;\s*(.*))?/', $line, $matches)) {
                    $currentContext = $matches[1];
                    $description = isset($matches[2]) ? trim($matches[2]) : '';

                    // Only track IVR, Queue, and Extension contexts
                    if (preg_match('/^(ivr-|ext-queues|from-did-direct|from-internal)/', $currentContext)) {
                        $contexts[$currentContext] = [
                            'context' => $currentContext,
                            'description' => $description,
                            'type' => $this->getContextType($currentContext),
                            'extensions' => []
                        ];
                    }
                }

                // Match extension lines like: exten => 601,1,Macro(...)
                if ($currentContext && preg_match('/^exten\s*=>\s*([^,]+),([^,]+),(.*)/', $line, $matches)) {
                    $extension = trim($matches[1]);
                    $priority = trim($matches[2]);
                    $application = trim($matches[3]);

                    if ($priority === '1' && isset($contexts[$currentContext])) {
                        $contexts[$currentContext]['extensions'][] = [
                            'extension' => $extension,
                            'application' => $application
                        ];
                    }
                }
            }

            return $contexts;

        } catch (Exception $e) {
            error_log("Error parsing extensions_additional.conf: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get context type based on context name
     * @param string $contextName
     * @return string
     */
    private function getContextType($contextName) {
        if (strpos($contextName, 'ivr-') === 0) {
            return 'ivr';
        } elseif (strpos($contextName, 'ext-queues') === 0) {
            return 'queue';
        } elseif (strpos($contextName, 'from-did-direct') === 0 || strpos($contextName, 'from-internal') === 0) {
            return 'extension';
        }
        return 'other';
    }

    /**
     * Get context information for IVR by ID
     * @param int $ivrId
     * @return array|null
     */
    public function getIVRContext($ivrId) {
        $contexts = $this->parseExtensionsAdditional();
        $contextName = "ivr-{$ivrId}";

        return isset($contexts[$contextName]) ? $contexts[$contextName] : null;
    }

    /**
     * Get context information for Queue by extension
     * @param string $extension
     * @return array|null
     */
    public function getQueueContext($extension) {
        $contexts = $this->parseExtensionsAdditional();

        // Queues are typically in ext-queues context
        if (isset($contexts['ext-queues'])) {
            foreach ($contexts['ext-queues']['extensions'] as $ext) {
                if ($ext['extension'] === $extension) {
                    return [
                        'context' => 'ext-queues',
                        'extension' => $extension,
                        'application' => $ext['application']
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get all contexts with their extensions
     * @return array
     */
    public function getAllContexts() {
        return $this->parseExtensionsAdditional();
    }
}