<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * Database Singleton Connection Manager
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $config = DB_CONFIG;

        try {
            $port = isset($config['port']) && $config['port'] ? ";port={$config['port']}" : '';
            $dsn = "mysql:host={$config['host']}{$port};dbname={$config['dbname']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            // Keep MySQL's clock in sync with PHP's app timezone (Asia/Manila).
            // Without this, CURDATE()/NOW() follow the MySQL server timezone,
            // which can be a DIFFERENT day than PHP's date('Y-m-d') — breaking
            // session lookups (dashboard "Resume" + QR scan) around midnight
            // and in the morning on shared hosts.
            $this->pdo->exec("SET time_zone = " . $this->pdo->quote(date('P')));
        } catch (PDOException $e) {
            error_log('SCPLS DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            if (!headers_sent() && str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
                header('Content-Type: application/json');
                die(json_encode(['success' => false, 'message' => 'Service temporarily unavailable.']));
            }
            die('The system is temporarily unavailable. Please try again in a few minutes.');
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Convenience shortcut: get PDO directly
     */
    public static function connect(): PDO
    {
        return self::getInstance()->getConnection();
    }
}
