<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';
session_start();

// Define the table creation SQL
$create_table_sql = "
CREATE TABLE IF NOT EXISTS form_f_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    patient_id INT NOT NULL,
    form_number VARCHAR(50) UNIQUE NOT NULL,
    patient_name VARCHAR(255) NOT NULL,
    patient_age INT NOT NULL,
    patient_gender ENUM('Male', 'Female', 'Other') NOT NULL,
    patient_address TEXT NOT NULL,
    patient_phone VARCHAR(20) NOT NULL,
    emergency_contact VARCHAR(255),
    emergency_phone VARCHAR(20),
    scan_type VARCHAR(255) NOT NULL,
    scan_details TEXT,
    referring_doctor VARCHAR(255),
    notes TEXT,
    form_status ENUM('Draft', 'Printed', 'Signed', 'Scanned', 'Completed') DEFAULT 'Draft',
    consent_given BOOLEAN DEFAULT FALSE,
    consent_date DATETIME,
    printed_date DATETIME,
    scanned_date DATETIME,
    scanned_file_path VARCHAR(500),
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_visit_id (visit_id),
    INDEX idx_patient_id (patient_id),
    INDEX idx_form_number (form_number),
    INDEX idx_form_status (form_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

// Create referring_doctors table if it doesn't exist
$create_referring_doctors_sql = "
CREATE TABLE IF NOT EXISTS referring_doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    specialty VARCHAR(255),
    contact_number VARCHAR(20),
    address TEXT,
    incentive_percentage DECIMAL(5,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($create_table_sql);
    $pdo->exec($create_referring_doctors_sql);
} catch (PDOException $e) {
    error_log("Error creating Form F tables: " . $e->getMessage());
}

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/form_f_scans/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique Form F number
function generate_form_f_number($pdo) {
    $prefix = 'FORM-F-';
    $year = date('Y');
    $stmt = $pdo->query("SELECT form_number FROM form_f_records WHERE form_number LIKE '$prefix$year%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    
    if ($last && preg_match('/FORM-F-' . $year . '-(\\d+)/', $last, $m)) {
        $num = intval($m[1]) + 1;
    } else {
        $num = 1;
    }
    return $prefix . $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Check if visit_id is provided
if (!$visit_id) {
    die('Visit ID is required. Please go back and select a visit.');
}

// Fetch visit and patient details
$stmt = $pdo->prepare("SELECT v.*, p.full_name, p.gender, p.dob, p.address, p.contact_number FROM visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = ?");
$stmt->execute([$visit_id]);
$visit = $stmt->fetch();
if (!$visit) {
    die('Visit not found. Please check the visit ID.');
}

// Calculate patient age
$dob = new DateTime($visit['dob']);
$now = new DateTime();
$age = $now->diff($dob)->y;

$success = false;
$error = '';
$form_data = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_form'])) {
        // Create new Form F
        $form_number = generate_form_f_number($pdo);
        $patient_name = trim($_POST['patient_name']);
        $patient_age = intval($_POST['patient_age']);
        $patient_gender = $_POST['patient_gender'];
        $patient_address = trim($_POST['patient_address']);
        $patient_phone = trim($_POST['patient_phone']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $emergency_phone = trim($_POST['emergency_phone']);
        $scan_type = trim($_POST['scan_type']);
        $scan_details = trim($_POST['scan_details']);
        $referring_doctor = trim($_POST['referring_doctor']);
        $notes = trim($_POST['notes']);
        
        // Validate required fields
        if (empty($patient_name) || empty($patient_address) || empty($patient_phone) || empty($scan_type)) {
            $error = 'Please fill in all required fields.';
        } elseif ($patient_age <= 0 || $patient_age > 150) {
            $error = 'Please enter a valid age.';
        } elseif (!in_array($patient_gender, ['Male', 'Female', 'Other'])) {
            $error = 'Please select a valid gender.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO form_f_records (visit_id, patient_id, form_number, patient_name, patient_age, patient_gender, patient_address, patient_phone, emergency_contact, emergency_phone, scan_type, scan_details, referring_doctor, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$visit_id, $visit['patient_id'], $form_number, $patient_name, $patient_age, $patient_gender, $patient_address, $patient_phone, $emergency_contact, $emergency_phone, $scan_type, $scan_details, $referring_doctor, $notes, $_SESSION['user_name'] ?? 'System'])) {
                $form_id = $pdo->lastInsertId();
                log_action('Reception', 'Form F Created', "Form F created: $form_number for patient: $patient_name");
                header("Location: form_f_management.php?visit_id=$visit_id&form_id=$form_id&action=print");
                exit;
            } else {
                $error = 'Failed to create Form F.';
            }
        }
    } elseif (isset($_POST['update_form'])) {
        // Update existing Form F
        $form_id = intval($_POST['form_id']);
        $patient_name = trim($_POST['patient_name']);
        $patient_age = intval($_POST['patient_age']);
        $patient_gender = $_POST['patient_gender'];
        $patient_address = trim($_POST['patient_address']);
        $patient_phone = trim($_POST['patient_phone']);
        $emergency_contact = trim($_POST['emergency_contact']);
        $emergency_phone = trim($_POST['emergency_phone']);
        $scan_type = trim($_POST['scan_type']);
        $scan_details = trim($_POST['scan_details']);
        $referring_doctor = trim($_POST['referring_doctor']);
        $notes = trim($_POST['notes']);
        
        // Validate required fields
        if (empty($patient_name) || empty($patient_address) || empty($patient_phone) || empty($scan_type)) {
            $error = 'Please fill in all required fields.';
        } elseif ($patient_age <= 0 || $patient_age > 150) {
            $error = 'Please enter a valid age.';
        } elseif (!in_array($patient_gender, ['Male', 'Female', 'Other'])) {
            $error = 'Please select a valid gender.';
        } else {
            $stmt = $pdo->prepare("UPDATE form_f_records SET patient_name = ?, patient_age = ?, patient_gender = ?, patient_address = ?, patient_phone = ?, emergency_contact = ?, emergency_phone = ?, scan_type = ?, scan_details = ?, referring_doctor = ?, notes = ? WHERE id = ?");
            
            if ($stmt->execute([$patient_name, $patient_age, $patient_gender, $patient_address, $patient_phone, $emergency_contact, $emergency_phone, $scan_type, $scan_details, $referring_doctor, $notes, $form_id])) {
                $success = true;
                log_action('Reception', 'Form F Updated', "Form F updated: ID $form_id");
            } else {
                $error = 'Failed to update Form F.';
            }
        }
    } elseif (isset($_POST['mark_printed'])) {
        // Mark form as printed
        $form_id = intval($_POST['form_id']);
        $stmt = $pdo->prepare("UPDATE form_f_records SET form_status = 'Printed', printed_date = NOW() WHERE id = ?");
        if ($stmt->execute([$form_id])) {
            $success = true;
            log_action('Reception', 'Form F Printed', "Form F marked as printed: ID $form_id");
        } else {
            $error = 'Failed to mark form as printed.';
        }
    } elseif (isset($_POST['mark_signed'])) {
        // Mark form as signed
        $form_id = intval($_POST['form_id']);
        $stmt = $pdo->prepare("UPDATE form_f_records SET form_status = 'Signed', consent_given = TRUE, consent_date = NOW() WHERE id = ?");
        if ($stmt->execute([$form_id])) {
            $success = true;
            log_action('Reception', 'Form F Signed', "Form F marked as signed: ID $form_id");
        } else {
            $error = 'Failed to mark form as signed.';
        }
    } elseif (isset($_POST['upload_scan'])) {
        // Handle scanned form upload
        $form_id = intval($_POST['form_id']);
        
        if (isset($_FILES['scanned_file']) && $_FILES['scanned_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/form_f_scans/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['scanned_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
            $max_file_size = 10 * 1024 * 1024; // 10MB
            
            if (!in_array($file_extension, $allowed_extensions)) {
                $error = 'Invalid file type. Only PDF, JPG, JPEG, and PNG files are allowed.';
            } elseif ($_FILES['scanned_file']['size'] > $max_file_size) {
                $error = 'File size must be less than 10MB.';
            } else {
                $filename = 'form_f_' . $form_id . '_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $file_extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['scanned_file']['tmp_name'], $filepath)) {
                    $stmt = $pdo->prepare("UPDATE form_f_records SET form_status = 'Scanned', scanned_date = NOW(), scanned_file_path = ? WHERE id = ?");
                    if ($stmt->execute([$filepath, $form_id])) {
                        $success = true;
                        log_action('Reception', 'Form F Scanned', "Form F scanned and uploaded: ID $form_id");
                    } else {
                        $error = 'Failed to update database with scan information.';
                        // Clean up uploaded file if database update fails
                        if (file_exists($filepath)) {
                            unlink($filepath);
                        }
                    }
                } else {
                    $error = 'Failed to upload file. Please try again.';
                }
            }
        } else {
            $upload_error = $_FILES['scanned_file']['error'] ?? 'Unknown error';
            switch ($upload_error) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error = 'File size exceeds the maximum allowed limit.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error = 'File upload was incomplete. Please try again.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error = 'No file was uploaded. Please select a file.';
                    break;
                default:
                    $error = 'File upload error occurred. Please try again.';
            }
        }
    }
}

// Fetch form data if form_id is provided
if ($form_id) {
    $stmt = $pdo->prepare("SELECT * FROM form_f_records WHERE id = ?");
    $stmt->execute([$form_id]);
    $form_data = $stmt->fetch();
}

// Fetch all Form F records for this visit
$form_f_records = [];
if ($visit_id) {
    $stmt = $pdo->prepare("SELECT * FROM form_f_records WHERE visit_id = ? ORDER BY created_at DESC");
    $stmt->execute([$visit_id]);
    $form_f_records = $stmt->fetchAll();
}

// Fetch referring doctors for dropdown
try {
    $referring_doctors = $pdo->query("SELECT id, name, specialty FROM referring_doctors WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching referring doctors: " . $e->getMessage());
    $referring_doctors = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form F Management - Patient Consent Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .form-f-container { max-width: 1200px; margin: 0 auto; }
        .status-badge { font-size: 0.8em; }
        .print-only { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .form-f-print { font-size: 12px; line-height: 1.4; }
        }
        .form-f-print {
            border: 2px solid #000;
            padding: 20px;
            margin: 20px 0;
            background: white;
        }
        .signature-area {
            border: 1px solid #ccc;
            height: 80px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f9f9f9;
        }
        .upload-area {
            border: 2px dashed #ccc;
            padding: 20px;
            text-align: center;
            margin: 10px 0;
            background: #f9f9f9;
        }
        .upload-area:hover {
            border-color: #007bff;
            background: #f0f8ff;
        }
    </style>
</head>
<body>
    <div class="container-fluid form-f-container">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <h2><i class="fas fa-file-medical"></i> Form F Management</h2>
                    <div>
                        <a href="visit.php?id=<?= $visit_id ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Visit
                        </a>
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> Operation completed successfully!
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Patient Information Card -->
                <?php if ($visit): ?>
                <div class="card mb-4 no-print">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user"></i> Patient Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Name:</strong> <?= htmlspecialchars($visit['full_name']) ?></p>
                                <p><strong>Age:</strong> <?= $age ?> years</p>
                                <p><strong>Gender:</strong> <?= htmlspecialchars($visit['gender']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Address:</strong> <?= htmlspecialchars($visit['address']) ?></p>
                                <p><strong>Phone:</strong> <?= htmlspecialchars($visit['contact_number']) ?></p>
                                <p><strong>Visit Date:</strong> <?= date('d/m/Y', strtotime($visit['visit_date'])) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form F Records Table -->
                <div class="card mb-4 no-print">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Form F Records
                        </h5>
                        <div>
                            <a href="form_f_generator.php?visit_id=<?= $visit_id ?>" class="btn btn-info me-2">
                                <i class="fas fa-file-word"></i> Form F Generator
                            </a>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createFormModal">
                                <i class="fas fa-plus"></i> Create New Form F
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($form_f_records)): ?>
                            <p class="text-muted">No Form F records found for this visit.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Form Number</th>
                                            <th>Scan Type</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($form_f_records as $record): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($record['form_number']) ?></strong>
                                                </td>
                                                <td><?= htmlspecialchars($record['scan_type']) ?></td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    switch ($record['form_status']) {
                                                        case 'Draft': $status_class = 'bg-secondary'; break;
                                                        case 'Printed': $status_class = 'bg-info'; break;
                                                        case 'Signed': $status_class = 'bg-warning'; break;
                                                        case 'Scanned': $status_class = 'bg-success'; break;
                                                        case 'Completed': $status_class = 'bg-primary'; break;
                                                    }
                                                    ?>
                                                    <span class="badge <?= $status_class ?> status-badge">
                                                        <?= htmlspecialchars($record['form_status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('d/m/Y H:i', strtotime($record['created_at'])) ?></td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <a href="?visit_id=<?= $visit_id ?>&form_id=<?= $record['id'] ?>&action=view" 
                                                           class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                                                <a href="?visit_id=<?= $visit_id ?>&form_id=<?= $record['id'] ?>&action=print" 
                           class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="fas fa-print"></i>
                        </a>
                        <a href="form_f_pdf_generator.php?visit_id=<?= $visit_id ?>&form_id=<?= $record['id'] ?>" 
                           class="btn btn-sm btn-outline-info" target="_blank" title="Generate PDF">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                                                        <a href="?visit_id=<?= $visit_id ?>&form_id=<?= $record['id'] ?>&action=edit" 
                                                           class="btn btn-sm btn-outline-warning">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($record['form_status'] === 'Draft'): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="form_id" value="<?= $record['id'] ?>">
                                                                <button type="submit" name="mark_printed" class="btn btn-sm btn-outline-info">
                                                                    <i class="fas fa-check"></i> Mark Printed
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($record['form_status'] === 'Printed'): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="form_id" value="<?= $record['id'] ?>">
                                                                <button type="submit" name="mark_signed" class="btn btn-sm btn-outline-success">
                                                                    <i class="fas fa-signature"></i> Mark Signed
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Form F Display/Edit Area -->
                <?php if ($form_data && $action): ?>
                    <?php if ($action === 'print'): ?>
                        <!-- Print View -->
                        <div class="form-f-print print-only">
                            <div class="text-center mb-4">
                                <h3><strong>FORM F - PATIENT CONSENT FORM</strong></h3>
                                <p><strong>Form Number:</strong> <?= htmlspecialchars($form_data['form_number']) ?></p>
                                <p><strong>Date:</strong> <?= date('d/m/Y', strtotime($form_data['created_at'])) ?></p>
                            </div>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <p><strong>Patient Name:</strong> <?= htmlspecialchars($form_data['patient_name']) ?></p>
                                    <p><strong>Age:</strong> <?= $form_data['patient_age'] ?> years</p>
                                    <p><strong>Gender:</strong> <?= htmlspecialchars($form_data['patient_gender']) ?></p>
                                </div>
                                <div class="col-6">
                                    <p><strong>Phone:</strong> <?= htmlspecialchars($form_data['patient_phone']) ?></p>
                                    <p><strong>Emergency Contact:</strong> <?= htmlspecialchars($form_data['emergency_contact']) ?></p>
                                    <p><strong>Emergency Phone:</strong> <?= htmlspecialchars($form_data['emergency_phone']) ?></p>
                                </div>
                            </div>

                            <div class="mb-3">
                                <p><strong>Address:</strong> <?= htmlspecialchars($form_data['patient_address']) ?></p>
                            </div>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <p><strong>Scan Type:</strong> <?= htmlspecialchars($form_data['scan_type']) ?></p>
                                </div>
                                <div class="col-6">
                                    <p><strong>Referring Doctor:</strong> <?= htmlspecialchars($form_data['referring_doctor']) ?></p>
                                </div>
                            </div>

                            <div class="mb-3">
                                <p><strong>Scan Details:</strong></p>
                                <p><?= nl2br(htmlspecialchars($form_data['scan_details'])) ?></p>
                            </div>

                            <div class="mb-4">
                                <h5><strong>CONSENT STATEMENT</strong></h5>
                                <p>I, <strong><?= htmlspecialchars($form_data['patient_name']) ?></strong>, hereby give my informed consent for the ultrasound examination as described above. I understand that:</p>
                                <ol>
                                    <li>This is a diagnostic procedure that uses sound waves to create images of internal organs.</li>
                                    <li>The procedure is generally safe and non-invasive.</li>
                                    <li>I may be asked to change position or hold my breath during the examination.</li>
                                    <li>The results will be interpreted by a qualified medical professional.</li>
                                    <li>I have the right to ask questions and withdraw my consent at any time.</li>
                                </ol>
                            </div>

                            <div class="row mb-4">
                                <div class="col-6">
                                    <p><strong>Patient Signature:</strong></p>
                                    <div class="signature-area">
                                        <em>Patient signature here</em>
                                    </div>
                                    <p><strong>Date:</strong> _________________</p>
                                </div>
                                <div class="col-6">
                                    <p><strong>Witness Signature:</strong></p>
                                    <div class="signature-area">
                                        <em>Witness signature here</em>
                                    </div>
                                    <p><strong>Date:</strong> _________________</p>
                                </div>
                            </div>

                            <div class="mb-3">
                                <p><strong>Additional Notes:</strong></p>
                                <p><?= nl2br(htmlspecialchars($form_data['notes'])) ?></p>
                            </div>

                            <div class="text-center mt-4">
                                <p><small>This form is a legal document. Please ensure all information is accurate and complete.</small></p>
                            </div>
                        </div>

                        <div class="text-center no-print">
                            <button onclick="window.print()" class="btn btn-primary btn-lg">
                                <i class="fas fa-print"></i> Print Form F
                            </button>
                            <a href="?visit_id=<?= $visit_id ?>" class="btn btn-secondary btn-lg">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        </div>

                    <?php elseif ($action === 'view'): ?>
                        <!-- View Mode -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-eye"></i> View Form F - <?= htmlspecialchars($form_data['form_number']) ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Form Number:</strong> <?= htmlspecialchars($form_data['form_number']) ?></p>
                                        <p><strong>Patient Name:</strong> <?= htmlspecialchars($form_data['patient_name']) ?></p>
                                        <p><strong>Age:</strong> <?= $form_data['patient_age'] ?> years</p>
                                        <p><strong>Gender:</strong> <?= htmlspecialchars($form_data['patient_gender']) ?></p>
                                        <p><strong>Phone:</strong> <?= htmlspecialchars($form_data['patient_phone']) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Scan Type:</strong> <?= htmlspecialchars($form_data['scan_type']) ?></p>
                                        <p><strong>Referring Doctor:</strong> <?= htmlspecialchars($form_data['referring_doctor']) ?></p>
                                        <p><strong>Status:</strong> 
                                            <span class="badge bg-<?= $form_data['form_status'] === 'Completed' ? 'success' : 'warning' ?>">
                                                <?= htmlspecialchars($form_data['form_status']) ?>
                                            </span>
                                        </p>
                                        <p><strong>Created:</strong> <?= date('d/m/Y H:i', strtotime($form_data['created_at'])) ?></p>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <p><strong>Address:</strong> <?= htmlspecialchars($form_data['patient_address']) ?></p>
                                </div>

                                <div class="mb-3">
                                    <p><strong>Emergency Contact:</strong> <?= htmlspecialchars($form_data['emergency_contact']) ?></p>
                                    <p><strong>Emergency Phone:</strong> <?= htmlspecialchars($form_data['emergency_phone']) ?></p>
                                </div>

                                <div class="mb-3">
                                    <p><strong>Scan Details:</strong></p>
                                    <p><?= nl2br(htmlspecialchars($form_data['scan_details'])) ?></p>
                                </div>

                                <div class="mb-3">
                                    <p><strong>Notes:</strong></p>
                                    <p><?= nl2br(htmlspecialchars($form_data['notes'])) ?></p>
                                </div>

                                <?php if ($form_data['scanned_file_path']): ?>
                                    <div class="mb-3">
                                        <p><strong>Scanned Form:</strong></p>
                                        <a href="<?= htmlspecialchars($form_data['scanned_file_path']) ?>" target="_blank" class="btn btn-outline-primary">
                                            <i class="fas fa-file-pdf"></i> View Scanned Form
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-4">
                                    <a href="?visit_id=<?= $visit_id ?>&form_id=<?= $form_data['id'] ?>&action=print" 
                                       class="btn btn-primary" target="_blank">
                                        <i class="fas fa-print"></i> Print Form
                                    </a>
                                    <a href="?visit_id=<?= $visit_id ?>&form_id=<?= $form_data['id'] ?>&action=edit" 
                                       class="btn btn-warning">
                                        <i class="fas fa-edit"></i> Edit Form
                                    </a>
                                    <a href="?visit_id=<?= $visit_id ?>" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to List
                                    </a>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($action === 'edit'): ?>
                        <!-- Edit Mode -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-edit"></i> Edit Form F - <?= htmlspecialchars($form_data['form_number']) ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <input type="hidden" name="form_id" value="<?= $form_data['id'] ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="patient_name" class="form-label">Patient Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="patient_name" name="patient_name" 
                                                       value="<?= htmlspecialchars($form_data['patient_name']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="patient_age" class="form-label">Age <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" id="patient_age" name="patient_age" 
                                                       value="<?= $form_data['patient_age'] ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="patient_gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                                <select class="form-select" id="patient_gender" name="patient_gender" required>
                                                    <option value="Male" <?= $form_data['patient_gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                                    <option value="Female" <?= $form_data['patient_gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                                    <option value="Other" <?= $form_data['patient_gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="patient_phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="patient_phone" name="patient_phone" 
                                                       value="<?= htmlspecialchars($form_data['patient_phone']) ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="patient_address" class="form-label">Address <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="patient_address" name="patient_address" rows="3" required><?= htmlspecialchars($form_data['patient_address']) ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="emergency_contact" class="form-label">Emergency Contact</label>
                                                <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" 
                                                       value="<?= htmlspecialchars($form_data['emergency_contact']) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="emergency_phone" class="form-label">Emergency Phone</label>
                                                <input type="text" class="form-control" id="emergency_phone" name="emergency_phone" 
                                                       value="<?= htmlspecialchars($form_data['emergency_phone']) ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="scan_type" class="form-label">Scan Type <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="scan_type" name="scan_type" 
                                                       value="<?= htmlspecialchars($form_data['scan_type']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="referring_doctor" class="form-label">Referring Doctor</label>
                                                <input type="text" class="form-control" id="referring_doctor" name="referring_doctor" 
                                                       value="<?= htmlspecialchars($form_data['referring_doctor']) ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="scan_details" class="form-label">Scan Details</label>
                                        <textarea class="form-control" id="scan_details" name="scan_details" rows="3"><?= htmlspecialchars($form_data['scan_details']) ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars($form_data['notes']) ?></textarea>
                                    </div>

                                    <div class="mt-4">
                                        <button type="submit" name="update_form" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Update Form
                                        </button>
                                        <a href="?visit_id=<?= $visit_id ?>" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left"></i> Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Scan Upload Section -->
                <?php if ($form_data && $form_data['form_status'] === 'Signed'): ?>
                    <div class="card mt-4 no-print">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-upload"></i> Upload Scanned Form F
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="form_id" value="<?= $form_data['id'] ?>">
                                
                                <div class="upload-area">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <h5>Upload Scanned Form F</h5>
                                    <p class="text-muted">Please scan the signed Form F and upload it here.</p>
                                    <input type="file" name="scanned_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small class="text-muted">Accepted formats: PDF, JPG, JPEG, PNG (Max 10MB)</small>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" name="upload_scan" class="btn btn-success">
                                        <i class="fas fa-upload"></i> Upload Scanned Form
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Form F Modal -->
    <div class="modal fade" id="createFormModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus"></i> Create New Form F
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_patient_name" class="form-label">Patient Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="modal_patient_name" name="patient_name" 
                                           value="<?= htmlspecialchars($visit['full_name'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_patient_age" class="form-label">Age <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="modal_patient_age" name="patient_age" 
                                           value="<?= $age ?? '' ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_patient_gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select" id="modal_patient_gender" name="patient_gender" required>
                                        <option value="Male" <?= ($visit['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($visit['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                        <option value="Other" <?= ($visit['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_patient_phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="modal_patient_phone" name="patient_phone" 
                                           value="<?= htmlspecialchars($visit['contact_number'] ?? '') ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="modal_patient_address" class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="modal_patient_address" name="patient_address" rows="3" required><?= htmlspecialchars($visit['address'] ?? '') ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_emergency_contact" class="form-label">Emergency Contact</label>
                                    <input type="text" class="form-control" id="modal_emergency_contact" name="emergency_contact">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_emergency_phone" class="form-label">Emergency Phone</label>
                                    <input type="text" class="form-control" id="modal_emergency_phone" name="emergency_phone">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_scan_type" class="form-label">Scan Type <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="modal_scan_type" name="scan_type" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="modal_referring_doctor" class="form-label">Referring Doctor</label>
                                    <select class="form-select" id="modal_referring_doctor" name="referring_doctor">
                                        <option value="">Select Doctor</option>
                                        <?php foreach ($referring_doctors as $doctor): ?>
                                            <option value="<?= htmlspecialchars($doctor['name']) ?>">
                                                <?= htmlspecialchars($doctor['name']) ?> (<?= htmlspecialchars($doctor['specialty']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="modal_scan_details" class="form-label">Scan Details</label>
                            <textarea class="form-control" id="modal_scan_details" name="scan_details" rows="3" 
                                      placeholder="Describe the specific ultrasound scan to be performed..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="modal_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="modal_notes" name="notes" rows="3" 
                                      placeholder="Any additional notes or special instructions..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_form" class="btn btn-success">
                            <i class="fas fa-plus"></i> Create Form F
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill form fields when modal opens
        document.getElementById('createFormModal').addEventListener('show.bs.modal', function () {
            // Form fields are already pre-filled with PHP values
        });

        // File upload validation
        document.querySelector('input[type="file"]')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const maxSize = 10 * 1024 * 1024; // 10MB
            
            if (file && file.size > maxSize) {
                alert('File size must be less than 10MB');
                e.target.value = '';
            }
        });
    </script>
</body>
</html>
