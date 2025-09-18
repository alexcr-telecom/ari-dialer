<?php
/**
 * CDR Database Configuration
 * Configuration for connecting to asteriskcdrdb database
 */

class CdrDatabase {
    private static $instance = null;
    private $connection = null;

    // CDR Database Configuration
    // These should match your FreePBX/Asterisk CDR database settings
    private const CDR_HOST = 'localhost';
    private const CDR_PORT = '3306';
    private const CDR_DATABASE = 'asteriskcdrdb';
    private const CDR_USER = 'root';  // Change to freepbxuser if needed
    private const CDR_PASSWORD = 'mahapharata';  // Updated to match current root password

    private function __construct() {
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect() {
        try {
            $dsn = "mysql:host=" . self::CDR_HOST .
                   ";port=" . self::CDR_PORT .
                   ";dbname=" . self::CDR_DATABASE .
                   ";charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];

            $this->connection = new PDO($dsn, self::CDR_USER, self::CDR_PASSWORD, $options);

        } catch (PDOException $e) {
            error_log("CDR Database connection failed: " . $e->getMessage());
            throw new Exception("CDR Database connection failed");
        }
    }

    public function getConnection() {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    public function testConnection() {
        try {
            $this->getConnection()->query("SELECT 1");
            return true;
        } catch (Exception $e) {
            error_log("CDR Database test failed: " . $e->getMessage());
            return false;
        }
    }
}