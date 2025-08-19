<?php
// includes/log.php - System-wide logging function
require_once __DIR__ . '/db.php';
function log_action($user, $action, $details) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO system_log (user, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$user, $action, $details]);
} 