<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Testing Form F Management...<br>";

// Test database connection
require_once 'includes/db.php';
echo "Database connection: OK<br>";

// Test if referring_doctors table exists
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM referring_doctors");
    $count = $stmt->fetchColumn();
    echo "Referring doctors table: OK (found $count doctors)<br>";
} catch (PDOException $e) {
    echo "Referring doctors table error: " . $e->getMessage() . "<br>";
}

// Test if form_f_records table exists
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM form_f_records");
    $count = $stmt->fetchColumn();
    echo "Form F records table: OK (found $count records)<br>";
} catch (PDOException $e) {
    echo "Form F records table error: " . $e->getMessage() . "<br>";
}

// Test if visits table exists and has data
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM visits");
    $count = $stmt->fetchColumn();
    echo "Visits table: OK (found $count visits)<br>";
} catch (PDOException $e) {
    echo "Visits table error: " . $e->getMessage() . "<br>";
}

echo "<br>Test completed.";
?>
