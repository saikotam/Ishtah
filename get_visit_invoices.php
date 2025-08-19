<?php
// get_visit_invoices.php - AJAX endpoint to get all invoices for a visit
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';
require_once 'includes/patient.php';

header('Content-Type: application/json');

if (!isset($_GET['visit_id']) || empty($_GET['visit_id'])) {
    echo json_encode(['success' => false, 'message' => 'Visit ID is required']);
    exit;
}

$visit_id = intval($_GET['visit_id']);

try {
    $invoices = get_all_visit_invoices($pdo, $visit_id);
    
    echo json_encode([
        'success' => true,
        'invoices' => $invoices
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading invoices: ' . $e->getMessage()
    ]);
}
?>
