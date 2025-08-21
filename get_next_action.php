<?php
// get_next_action.php - Determine the next pending action for a patient visit
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';

header('Content-Type: application/json');

if (!isset($_GET['visit_id']) || empty($_GET['visit_id'])) {
    echo json_encode(['success' => false, 'message' => 'Visit ID is required']);
    exit;
}

$visit_id = intval($_GET['visit_id']);

try {
    // Get visit information
    $stmt = $pdo->prepare("SELECT v.*, p.full_name FROM visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = ?");
    $stmt->execute([$visit_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$visit) {
        echo json_encode(['success' => false, 'message' => 'Visit not found']);
        exit;
    }
    
    // Check if patient has been sent to consultation (consultation invoice exists)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_invoices WHERE visit_id = ?");
    $stmt->execute([$visit_id]);
    $consultation_done = $stmt->fetchColumn() > 0;
    
    // Check if prescription has been printed (we'll assume it's printed if visit exists)
    $prescription_printed = true; // Visit exists, so prescription should be printed
    
    // Check if prescription has been scanned
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visit_documents WHERE visit_id = ? AND original_name LIKE '%prescription%'");
    $stmt->execute([$visit_id]);
    $prescription_scanned = $stmt->fetchColumn() > 0;
    
    // Check if lab tests invoice exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_bills WHERE visit_id = ? AND invoice_number IS NOT NULL");
    $stmt->execute([$visit_id]);
    $lab_invoice_exists = $stmt->fetchColumn() > 0;
    
    // Check if lab invoice has been printed (we'll assume it's printed if it exists)
    $lab_invoice_printed = $lab_invoice_exists;
    
    // Check if TRF form has been uploaded
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visit_documents WHERE visit_id = ? AND original_name LIKE '%TRF%'");
    $stmt->execute([$visit_id]);
    $trf_uploaded = $stmt->fetchColumn() > 0;
    
    // Check if medicines bill exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pharmacy_bills WHERE visit_id = ? AND invoice_number IS NOT NULL");
    $stmt->execute([$visit_id]);
    $pharmacy_invoice_exists = $stmt->fetchColumn() > 0;
    
    // Check if pharmacy invoice has been printed (we'll assume it's printed if it exists)
    $pharmacy_invoice_printed = $pharmacy_invoice_exists;
    
    // Check if ultrasound bill exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ultrasound_bills WHERE visit_id = ? AND invoice_number IS NOT NULL");
    $stmt->execute([$visit_id]);
    $ultrasound_invoice_exists = $stmt->fetchColumn() > 0;
    
    // Check if ultrasound invoice has been printed (we'll assume it's printed if it exists)
    $ultrasound_invoice_printed = $ultrasound_invoice_exists;
    
    // Check if Form F is needed
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ultrasound_bill_items ubi JOIN ultrasound_bills ub ON ub.id = ubi.bill_id JOIN ultrasound_scans us ON us.id = ubi.scan_id WHERE ub.visit_id = ? AND us.is_form_f_needed = 1");
    $stmt->execute([$visit_id]);
    $form_f_needed = $stmt->fetchColumn() > 0;
    
    // Debug: Get detailed Form F information
    $stmt = $pdo->prepare("SELECT ubi.id, us.scan_name, us.is_form_f_needed FROM ultrasound_bill_items ubi JOIN ultrasound_bills ub ON ub.id = ubi.bill_id JOIN ultrasound_scans us ON us.id = ubi.scan_id WHERE ub.visit_id = ?");
    $stmt->execute([$visit_id]);
    $form_f_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if Form F has been printed (check for confirmation in visit_actions table)
    $stmt = $pdo->prepare("SELECT form_f_printed FROM visit_actions WHERE visit_id = ?");
    $stmt->execute([$visit_id]);
    $form_f_printed = $stmt->fetchColumn();
    
    // If no record exists, create one
    if ($form_f_printed === false) {
        $stmt = $pdo->prepare("INSERT INTO visit_actions (visit_id, form_f_printed) VALUES (?, 0) ON DUPLICATE KEY UPDATE visit_id = visit_id");
        $stmt->execute([$visit_id]);
        $form_f_printed = 0;
    }
    
    // Check if scanned filled Form F has been uploaded
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visit_documents WHERE visit_id = ? AND (original_name LIKE '%form_f%' OR original_name LIKE '%Form F%' OR original_name LIKE '%FORM_F%')");
    $stmt->execute([$visit_id]);
    $form_f_scanned = $stmt->fetchColumn() > 0;
    
    // Determine next action based on workflow
    $next_action = null;
    $action_url = null;
    $action_text = null;
    $action_icon = null;
    
    // Check if there are any pending actions that need to be completed
    $pending_actions = [];
    
    // Check for consultation
    if (!$consultation_done) {
        $pending_actions[] = ['action' => 'consultation', 'text' => 'Send to Consultation', 'icon' => '👨‍⚕️', 'url' => '#', 'priority' => 1];
    }
    
    // Check for prescription scanning
    if (!$prescription_scanned) {
        $pending_actions[] = ['action' => 'scan_prescription', 'text' => 'Scan Prescription', 'icon' => '📄', 'url' => '#', 'priority' => 2];
    }
    
    // Check for TRF upload (if lab invoice exists)
    if ($lab_invoice_exists && !$trf_uploaded) {
        $pending_actions[] = ['action' => 'upload_trf', 'text' => 'Upload TRF Form', 'icon' => '📋', 'url' => '#', 'priority' => 3];
    }
    
    // Check for pharmacy invoice printing
    if ($pharmacy_invoice_exists && !$pharmacy_invoice_printed) {
        $pending_actions[] = ['action' => 'print_pharmacy_invoice', 'text' => 'Print Medicines Bill', 'icon' => '💊', 'url' => 'pharmacy_billing.php?visit_id=' . $visit_id . '&print=1', 'priority' => 4];
    }
    
    // Check for Form F printing
    if ($form_f_needed && !$form_f_printed) {
        $pending_actions[] = ['action' => 'print_form_f', 'text' => 'Print Form F', 'icon' => '📋', 'url' => 'Form F.pdf', 'priority' => 5];
    }
    
    // Check for Form F scanning
    if ($form_f_needed && !$form_f_scanned) {
        $pending_actions[] = ['action' => 'scan_form_f', 'text' => 'Upload Form F', 'icon' => '📄', 'url' => '#', 'priority' => 6];
    }
    
    // Check for ultrasound invoice printing
    if ($ultrasound_invoice_exists && !$ultrasound_invoice_printed) {
        $pending_actions[] = ['action' => 'print_ultrasound_invoice', 'text' => 'Print Scan Bill', 'icon' => '🔬', 'url' => 'ultrasound_billing.php?visit_id=' . $visit_id . '&print=1', 'priority' => 7];
    }
    
    // If there are pending actions, get the highest priority one
    if (!empty($pending_actions)) {
        // Sort by priority
        usort($pending_actions, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
        
        $next_action = $pending_actions[0]['action'];
        $action_text = $pending_actions[0]['text'];
        $action_icon = $pending_actions[0]['icon'];
        $action_url = $pending_actions[0]['url'];
    } else {
        $next_action = 'completed';
        $action_text = 'Completed';
        $action_icon = '✅';
        $action_url = '#';
    }
    
    echo json_encode([
        'success' => true,
        'next_action' => $next_action,
        'action_text' => $action_text,
        'action_icon' => $action_icon,
        'action_url' => $action_url,
        'visit_id' => $visit_id,
        'patient_name' => $visit['full_name'],
        'pending_actions_count' => count($pending_actions),
        'all_pending_actions' => $pending_actions,
        'status' => [
            'consultation_done' => $consultation_done,
            'prescription_scanned' => $prescription_scanned,
            'lab_invoice_exists' => $lab_invoice_exists,
            'lab_invoice_printed' => $lab_invoice_printed,
            'trf_uploaded' => $trf_uploaded,
            'pharmacy_invoice_exists' => $pharmacy_invoice_exists,
            'pharmacy_invoice_printed' => $pharmacy_invoice_printed,
            'ultrasound_invoice_exists' => $ultrasound_invoice_exists,
            'ultrasound_invoice_printed' => $ultrasound_invoice_printed,
            'form_f_needed' => $form_f_needed,
            'form_f_printed' => $form_f_printed,
            'form_f_scanned' => $form_f_scanned
        ],
        'debug' => [
            'form_f_details' => $form_f_details,
            'workflow_step' => 'Current step: ' . $next_action,
            'total_pending_actions' => count($pending_actions)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error determining next action: ' . $e->getMessage()
    ]);
}
?>
