<?php
// mark_invoice_printed.php - Mark invoices as printed when print button is clicked
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';
require_once 'includes/log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_POST['invoice_number']) || !isset($_POST['type'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$invoice_number = $_POST['invoice_number'];
$type = $_POST['type'];

try {
    $success = false;
    
    switch ($type) {
        case 'lab':
            $stmt = $pdo->prepare("UPDATE lab_bills SET printed = 1 WHERE invoice_number = ?");
            $stmt->execute([$invoice_number]);
            $success = $stmt->rowCount() > 0;
            break;
            
        case 'pharmacy':
            $stmt = $pdo->prepare("UPDATE pharmacy_bills SET printed = 1 WHERE invoice_number = ?");
            $stmt->execute([$invoice_number]);
            $success = $stmt->rowCount() > 0;
            break;
            
        case 'ultrasound':
            $stmt = $pdo->prepare("UPDATE ultrasound_bills SET printed = 1 WHERE invoice_number = ?");
            $stmt->execute([$invoice_number]);
            $success = $stmt->rowCount() > 0;
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid invoice type']);
            exit;
    }
    
    if ($success) {
        log_action('System', 'Invoice Printed', $type . ' invoice marked as printed: ' . $invoice_number);
        echo json_encode(['success' => true, 'message' => 'Invoice marked as printed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invoice not found or already marked as printed']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
