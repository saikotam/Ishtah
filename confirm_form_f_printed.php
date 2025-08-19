<?php
// confirm_form_f_printed.php - Handle Form F printed confirmation
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['visit_id']) || empty($input['visit_id'])) {
    echo json_encode(['success' => false, 'message' => 'Visit ID is required']);
    exit;
}

$visit_id = intval($input['visit_id']);
$form_f_printed = isset($input['form_f_printed']) ? intval($input['form_f_printed']) : 1;

try {
    // Check if record exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visit_actions WHERE visit_id = ?");
    $stmt->execute([$visit_id]);
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        // Update existing record
        $stmt = $pdo->prepare("UPDATE visit_actions SET form_f_printed = ? WHERE visit_id = ?");
        $stmt->execute([$form_f_printed, $visit_id]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("INSERT INTO visit_actions (visit_id, form_f_printed) VALUES (?, ?)");
        $stmt->execute([$visit_id, $form_f_printed]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Form F printed status updated successfully',
        'form_f_printed' => $form_f_printed
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error updating Form F status: ' . $e->getMessage()
    ]);
}
?>
