<?php
/**
 * Database Connection Helper Class
 *
 * Handles database connections and testing for the Asterisk Auto-Dialer
 */

require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $this->connection = new PDO(
                self::getDSN(),
                Config::DB_USER,
                Config::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    PDO::ATTR_TIMEOUT => 30,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        // Check if connection is still alive
        if (!$this->isConnectionAlive()) {
            $this->reconnect();
        }
        return $this->connection;
    }

    /**
     * Check if the database connection is still alive
     * @return bool
     */
    private function isConnectionAlive() {
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Reconnect to the database
     */
    private function reconnect() {
        try {
            $this->connection = new PDO(
                self::getDSN(),
                Config::DB_USER,
                Config::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    PDO::ATTR_TIMEOUT => 30
                ]
            );
            error_log("Database connection restored");
        } catch (PDOException $e) {
            error_log("Database reconnection failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get main application database DSN string
     * @return string
     */
    public static function getDSN() {
        return "mysql:host=" . Config::DB_HOST . ";dbname=" . Config::DB_NAME . ";charset=utf8mb4";
    }

    /**
     * Get Asterisk database DSN string
     * @return string
     */
    public static function getAsteriskDSN() {
        return "mysql:host=" . Config::ASTERISK_DB_HOST . ";dbname=" . Config::ASTERISK_DB_NAME . ";charset=utf8mb4";
    }

    /**
     * Get CDR database DSN string
     * @return string
     */
    public static function getCdrDSN() {
        return "mysql:host=" . Config::CDR_DB_HOST . ";port=" . Config::CDR_DB_PORT . ";dbname=" . Config::CDR_DB_NAME . ";charset=utf8mb4";
    }

    /**
     * Test main application database connection
     * @return array
     */
    public static function testMainDB() {
        try {
            $pdo = new PDO(self::getDSN(), Config::DB_USER, Config::DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->query("SELECT 1");
            return ['status' => 'success', 'message' => 'Main database connection successful'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Main database connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Test Asterisk database connection
     * @return array
     */
    public static function testAsteriskDB() {
        try {
            $pdo = new PDO(self::getAsteriskDSN(), Config::ASTERISK_DB_USER, Config::ASTERISK_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->query("SELECT 1");
            return ['status' => 'success', 'message' => 'Asterisk database connection successful'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Asterisk database connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Test CDR database connection
     * @return array
     */
    public static function testCdrDB() {
        try {
            $pdo = new PDO(self::getCdrDSN(), Config::CDR_DB_USER, Config::CDR_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->query("SELECT 1");
            return ['status' => 'success', 'message' => 'CDR database connection successful'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'CDR database connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get all database connection statuses
     * @return array
     */
    public static function getAllDatabaseStatus() {
        return [
            'main' => self::testMainDB(),
            'asterisk' => self::testAsteriskDB(),
            'cdr' => self::testCdrDB()
        ];
    }

    /**
     * Get main application database connection
     * @return PDO
     */
    public static function getMainConnection() {
        return new PDO(self::getDSN(), Config::DB_USER, Config::DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    /**
     * Get Asterisk database connection
     * @return PDO
     */
    public static function getAsteriskConnection() {
        return new PDO(self::getAsteriskDSN(), Config::ASTERISK_DB_USER, Config::ASTERISK_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    /**
     * Get CDR database connection
     * @return PDO
     */
    public static function getCdrConnection() {
        return new PDO(self::getCdrDSN(), Config::CDR_DB_USER, Config::CDR_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    // CDR Database Management (replaces CdrDatabase class)
    private static $cdrInstance = null;
    private static $cdrConnectionInstance = null;
    private static $cdrAvailable = null;

    /**
     * Get CDR database instance (singleton pattern for CDR)
     * @return self
     */
    public static function getCdrInstance() {
        if (self::$cdrInstance === null) {
            self::$cdrInstance = new self();
        }
        return self::$cdrInstance;
    }

    /**
     * Check if CDR database is available and configured
     * @return bool
     */
    public static function isCdrAvailable() {
        if (self::$cdrAvailable !== null) {
            return self::$cdrAvailable;
        }

        // Check if CDR database configuration exists
        if (!class_exists('Config') ||
            empty(Config::CDR_DB_HOST) ||
            empty(Config::CDR_DB_NAME) ||
            empty(Config::CDR_DB_USER)) {
            error_log("CDR Database not configured - running in standalone mode");
            self::$cdrAvailable = false;
            return false;
        }

        // Test connection
        self::$cdrAvailable = self::testCdrConnectionAvailability();
        if (!self::$cdrAvailable) {
            error_log("CDR Database not available - running in standalone mode");
        }

        return self::$cdrAvailable;
    }

    /**
     * Test CDR database connection availability (internal method)
     * @return bool
     */
    private static function testCdrConnectionAvailability() {
        try {
            $dsn = self::getCdrDSN();
            $pdo = new PDO($dsn, Config::CDR_DB_USER, Config::CDR_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5 // 5 second timeout
            ]);
            $pdo->query("SELECT 1");
            return true;
        } catch (Exception $e) {
            error_log("CDR Database test failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get CDR database connection instance (singleton pattern)
     * @return PDO|null
     */
    public static function getCdrConnectionInstance() {
        if (!self::isCdrAvailable()) {
            return null;
        }

        if (self::$cdrConnectionInstance === null) {
            try {
                $dsn = self::getCdrDSN();
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ];

                self::$cdrConnectionInstance = new PDO($dsn, Config::CDR_DB_USER, Config::CDR_DB_PASS, $options);

            } catch (PDOException $e) {
                error_log("CDR Database connection failed: " . $e->getMessage());
                self::$cdrAvailable = false;
                return null;
            }
        }

        return self::$cdrConnectionInstance;
    }
}