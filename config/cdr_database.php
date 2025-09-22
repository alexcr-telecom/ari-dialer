<?php
/**
 * CDR Database Configuration
 * Configuration for connecting to asteriskcdrdb database
 */

require_once 'config.php';

class CdrDatabase {
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
     * Check if CDR database is available and configured
     */
    public function isAvailable() {
        if ($this->available !== null) {
            return $this->available;
        }

        // Check if CDR database configuration exists
        if (!defined('Config::CDR_DB_HOST') ||
            !Config::CDR_DB_HOST ||
            !Config::CDR_DB_NAME ||
            !Config::CDR_DB_USER) {
            error_log("CDR Database not configured - running in standalone mode");
            $this->available = false;
            return false;
        }

        // Test connection
        $this->available = $this->testConnection();
        if (!$this->available) {
            error_log("CDR Database not available - running in standalone mode");
        }

        return $this->available;
    }

    private function connect() {
        if (!$this->isAvailable()) {
            throw new Exception("CDR Database not available");
        }

        try {
            $dsn = Config::getCdrDSN();

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];

            $this->connection = new PDO($dsn, Config::CDR_DB_USER, Config::CDR_DB_PASS, $options);

        } catch (PDOException $e) {
            error_log("CDR Database connection failed: " . $e->getMessage());
            $this->available = false;
            throw new Exception("CDR Database connection failed");
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
            $dsn = Config::getCdrDSN();
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
}