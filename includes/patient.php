<?php
// includes/patient.php - Shared patient and visit management logic
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../Accounting/accounting.php';

// Register a new patient
function register_patient($pdo, $data) {
    // Validation
    $required = ['full_name', 'gender', 'dob', 'contact_number', 'address'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    $stmt = $pdo->prepare("INSERT INTO patients (full_name, gender, dob, contact_number, address, lead_source) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['full_name'],
        $data['gender'],
        $data['dob'],
        $data['contact_number'],
        $data['address'],
        isset($data['lead_source']) ? $data['lead_source'] : null
    ]);
    return $pdo->lastInsertId();
}

// Update patient details
function update_patient($pdo, $data) {
    $required = ['patient_id', 'full_name', 'gender', 'dob', 'contact_number', 'address'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    $stmt = $pdo->prepare("UPDATE patients SET full_name = ?, gender = ?, dob = ?, contact_number = ?, address = ?, lead_source = ? WHERE id = ?");
    $stmt->execute([
        $data['full_name'],
        $data['gender'],
        $data['dob'],
        $data['contact_number'],
        $data['address'],
        isset($data['lead_source']) ? $data['lead_source'] : null,
        $data['patient_id']
    ]);
}

// Search patients by name or contact
function search_patients($pdo, $query) {
    if (empty($query)) {
        throw new Exception("Search query cannot be empty");
    }
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE full_name LIKE ? OR contact_number LIKE ? ORDER BY id DESC LIMIT 20");
    $like = "%$query%";
    $stmt->execute([$like, $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get patient by ID
function get_patient($pdo, $id) {
    if (empty($id)) {
        throw new Exception("Patient ID is required");
    }
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all visits for a patient
function get_visits($pdo, $patient_id) {
    if (empty($patient_id)) {
        throw new Exception("Patient ID is required for visits");
    }
    $stmt = $pdo->prepare("SELECT v.*, d.name AS doctor_name, d.specialty AS doctor_specialty FROM visits v LEFT JOIN doctors d ON v.doctor_id = d.id WHERE v.patient_id = ? ORDER BY v.visit_date DESC");
    $stmt->execute([$patient_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Register a new visit
function register_visit($pdo, $data) {
    $required = ['patient_id', 'doctor_id', 'reason'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    $stmt = $pdo->prepare("INSERT INTO visits (patient_id, doctor_id, reason, referred_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $data['patient_id'],
        $data['doctor_id'],
        $data['reason'],
        isset($data['referred_by']) ? $data['referred_by'] : null
    ]);
    return $pdo->lastInsertId();
}

// Get latest pharmacy and lab invoice numbers for a visit
function get_visit_invoices($pdo, $visit_id) {
    $pharmacy = $pdo->prepare("SELECT invoice_number FROM pharmacy_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
    $pharmacy->execute([$visit_id]);
    $pharmacy_invoice = $pharmacy->fetchColumn();
    $lab = $pdo->prepare("SELECT invoice_number FROM lab_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
    $lab->execute([$visit_id]);
    $lab_invoice = $lab->fetchColumn();
    $ultrasound = $pdo->prepare("SELECT invoice_number FROM ultrasound_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
    $ultrasound->execute([$visit_id]);
    $ultrasound_invoice = $ultrasound->fetchColumn();
    return [
        'pharmacy' => $pharmacy_invoice,
        'lab' => $lab_invoice,
        'ultrasound' => $ultrasound_invoice
    ];
}

// Create a consultation invoice (before visit registration)
function create_consultation_invoice($pdo, $patient_id, $amount, $mode) {
    $stmt = $pdo->prepare("INSERT INTO consultation_invoices (patient_id, amount, mode, paid) VALUES (?, ?, ?, 1)");
    $stmt->execute([$patient_id, $amount, $mode]);
    $invoice_id = $pdo->lastInsertId();
    
    // Create accounting entry for consultation revenue
    try {
        $accounting = new AccountingSystem($pdo);
        // Attempt to get doctor_id from latest visit (if available) for the patient, else null
        $doctor_id = null;
        $stmtDoc = $pdo->prepare("SELECT doctor_id FROM visits WHERE patient_id = ? ORDER BY id DESC LIMIT 1");
        $stmtDoc->execute([$patient_id]);
        $doctor_id = $stmtDoc->fetchColumn();
        $accounting->recordConsultationRevenue($patient_id, $doctor_id ?: 0, $amount, strtolower($mode));
    } catch (Exception $e) {
        error_log('Accounting entry failed for consultation invoice ' . $invoice_id . ': ' . $e->getMessage());
    }
    
    return $invoice_id;
}

// Update consultation invoice with visit_id after visit is registered
function link_consultation_invoice_to_visit($pdo, $invoice_id, $visit_id) {
    $stmt = $pdo->prepare("UPDATE consultation_invoices SET visit_id = ? WHERE id = ?");
    $stmt->execute([$visit_id, $invoice_id]);
}

// Fetch consultation invoice by ID
function get_consultation_invoice($pdo, $invoice_id) {
    $stmt = $pdo->prepare("SELECT * FROM consultation_invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all invoices for a visit (consultation, lab, pharmacy)
function get_all_visit_invoices($pdo, $visit_id) {
    if (empty($visit_id)) {
        throw new Exception("Visit ID is required");
    }
    
    $invoices = [];
    
    try {
        // Get consultation invoices
        $stmt = $pdo->prepare("SELECT id, amount, mode, created_at FROM consultation_invoices WHERE visit_id = ? ORDER BY created_at DESC");
        $stmt->execute([$visit_id]);
        $consultation_invoices = $stmt->fetchAll();
        foreach ($consultation_invoices as $inv) {
            $invoices[] = [
                'type' => 'consultation',
                'id' => $inv['id'],
                'invoice_number' => 'CONS-' . $inv['id'],
                'amount' => $inv['amount'],
                'payment_mode' => $inv['mode'],
                'created_at' => $inv['created_at'],
                'print_url' => 'print_consultation_invoice.php?invoice_id=' . $inv['id']
            ];
        }
        
        // Get lab invoices
        $stmt = $pdo->prepare("SELECT id, invoice_number, discounted_amount, created_at FROM lab_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY created_at DESC");
        $stmt->execute([$visit_id]);
        $lab_invoices = $stmt->fetchAll();
        foreach ($lab_invoices as $inv) {
            $invoices[] = [
                'type' => 'lab',
                'id' => $inv['id'],
                'invoice_number' => $inv['invoice_number'],
                'amount' => $inv['discounted_amount'],
                'payment_mode' => 'Paid',
                'created_at' => $inv['created_at'],
                'print_url' => 'lab_billing.php?visit_id=' . $visit_id . '&invoice_number=' . urlencode($inv['invoice_number']) . '&print=1'
            ];
        }
        
        // Get pharmacy invoices
        $stmt = $pdo->prepare("SELECT id, invoice_number, discounted_total, created_at FROM pharmacy_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY created_at DESC");
        $stmt->execute([$visit_id]);
        $pharmacy_invoices = $stmt->fetchAll();
        foreach ($pharmacy_invoices as $inv) {
            $invoices[] = [
                'type' => 'pharmacy',
                'id' => $inv['id'],
                'invoice_number' => $inv['invoice_number'],
                'amount' => $inv['discounted_total'],
                'payment_mode' => 'Paid',
                'created_at' => $inv['created_at'],
                'print_url' => 'pharmacy_billing.php?visit_id=' . $visit_id . '&invoice_number=' . urlencode($inv['invoice_number']) . '&print=1'
            ];
        }
        
        // Get ultrasound invoices
        $stmt = $pdo->prepare("SELECT id, invoice_number, discounted_total, created_at FROM ultrasound_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY created_at DESC");
        $stmt->execute([$visit_id]);
        $ultrasound_invoices = $stmt->fetchAll();
        foreach ($ultrasound_invoices as $inv) {
            $invoices[] = [
                'type' => 'ultrasound',
                'id' => $inv['id'],
                'invoice_number' => $inv['invoice_number'],
                'amount' => $inv['discounted_total'],
                'payment_mode' => 'Paid',
                'created_at' => $inv['created_at'],
                'print_url' => 'ultrasound_billing.php?visit_id=' . $visit_id . '&invoice_number=' . urlencode($inv['invoice_number']) . '&print=1'
            ];
        }
        
        // Sort all invoices by creation date (newest first)
        usort($invoices, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
    } catch (Exception $e) {
        throw new Exception("Error fetching invoices: " . $e->getMessage());
    }
    
    return $invoices;
} 