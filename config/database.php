<?php
/**
 * Database Configuration
 */
return [
    'host'     => '127.0.0.1',
    // start.bat sets SCPLS_DB_PORT to the port the running XAMPP MySQL uses.
    'port'     => (int) (getenv('SCPLS_DB_PORT') ?: 3306),
    'dbname'   => 'scpls_v2',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
