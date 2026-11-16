<?php
// Database connection (PDO). All credentials come from includes/config.php,
// which reads from environment variables with safe local XAMPP defaults.
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . GIGLINK_DB_HOST . ';port=' . GIGLINK_DB_PORT
            . ';dbname=' . GIGLINK_DB_NAME . ';charset=utf8mb4',
        GIGLINK_DB_USER, GIGLINK_DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    if (GIGLINK_ENV === 'prod') {
        error_log('DB connect failed: ' . $e->getMessage());
        die('Service temporarily unavailable.');
    }
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
