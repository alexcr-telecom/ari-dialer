<?php
/**
 * CDR Database Configuration
 * Configuration for connecting to asteriskcdrdb database
 */

require_once 'config.php';

class CdrDatabase {
    private static $instance = null;
    private $connection = null;

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