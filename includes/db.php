<?php
// includes/db.php - Database connection
// Supports SQLite (default) and MySQL. Edit placeholders below for MySQL.

$DB_DRIVER = getenv('DB_DRIVER') ?: 'mysql'; // 'sqlite' or 'mysql'
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'iwqgalpa_register';
$DB_USER = getenv('DB_USER') ?: 'iwqgalpa_admin';
$DB_PASS = getenv('DB_PASS') ?: 'm7ynkLM)wCmTBiwb';
$DB_CHARSET = getenv('DB_CHARSET') ?: 'utf8mb4';

if ($DB_DRIVER === 'mysql') {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
} else {
    // Temporarily using SQLite for immediate functionality
    $sqlite_path = __DIR__ . '/../database/clinic.sqlite';
    $sqlite_dir = dirname($sqlite_path);

    // Create database directory if it doesn't exist
    if (!is_dir($sqlite_dir)) {
        mkdir($sqlite_dir, 0755, true);
    }

    $dsn = "sqlite:$sqlite_path";
}

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    if ($DB_DRIVER === 'mysql') {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    } else {
        $pdo = new PDO($dsn, null, null, $options);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    }
    
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());

} 
