<?php
/**
 * Database Setup Script
 * Run: php setup.php
 */

echo "=== SCPLS v2 Database Setup ===\n\n";

// Create database
try {
    $dbConfig = require __DIR__ . '/config/database.php';
    $port = isset($dbConfig['port']) && $dbConfig['port'] ? ";port={$dbConfig['port']}" : '';
    $pdo = new PDO("mysql:host={$dbConfig['host']}{$port}", $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Drop existing database if exists
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbConfig['dbname']}`");
    echo "[OK] Dropped existing database\n";

    // Create fresh
    $pdo->exec("CREATE DATABASE `{$dbConfig['dbname']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Created database: {$dbConfig['dbname']}\n";

    // Run schema - split by semicolons for compatibility
    $schema = file_get_contents(__DIR__ . '/database/schema.sql');
    $schema = preg_replace('/CREATE DATABASE.*?;/i', '', $schema);
    $schema = preg_replace('/USE.*?;/i', '', $schema);
    $pdo->exec("USE `{$dbConfig['dbname']}`");
    
    $statements = explode(';', $schema);
    $count = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!empty($stmt)) {
            try {
                $pdo->exec($stmt);
                $count++;
            } catch (Exception $e) {
                echo "[WARN] Statement $count failed: " . $e->getMessage() . "\n";
                echo "SQL: " . substr($stmt, 0, 100) . "...\n";
            }
        }
    }
    echo "[OK] Schema executed ($count statements)\n";

    // Run seed data
    $seed = file_get_contents(__DIR__ . '/database/seed.sql');
    $seed = preg_replace('/USE.*?;/i', '', $seed);
    $statements = explode(';', $seed);
    $count = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!empty($stmt)) {
            try {
                $pdo->exec($stmt);
                $count++;
            } catch (Exception $e) {
                echo "[WARN] Seed statement failed: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "[OK] Seed data inserted ($count statements)\n";

    echo "\n=== Setup Complete! ===\n";
    echo "Admin login:   admin / admin123\n";
    echo "Teacher login:  teacher1 / teacher123\n";
    echo "Teacher login:  teacher2 / teacher123\n";

} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
