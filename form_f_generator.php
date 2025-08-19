<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';
session_start();

// Check if visit_id is provided
$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$visit_id) {
    die('Visit ID is required.');
}

// Fetch visit and patient details
$stmt = $pdo->prepare("SELECT v.*, p.full_name, p.gender, p.dob, p.address, p.contact_number FROM visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = ?");
$stmt->execute([$visit_id]);
$visit = $stmt->fetch();

if (!$visit) {
    die('Visit not found.');
}

// Calculate patient age
$dob = new DateTime($visit['dob']);
$now = new DateTime();
$age = $now->diff($dob)->y;

// Fetch form data if form_id is provided
$form_data = null;
if ($form_id) {
    $stmt = $pdo->prepare("SELECT * FROM form_f_records WHERE id = ?");
    $stmt->execute([$form_id]);
    $form_data = $stmt->fetch();
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

// Handle form submission to create Form F
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_form_f'])) {
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
            
            // Redirect to generate PDF
            header("Location: form_f_generator.php?visit_id=$visit_id&form_id=$form_id&action=generate_pdf");
            exit;
        } else {
            $error = 'Failed to create Form F.';
        }
    }
}

// Generate PDF from Word template
if (isset($_GET['action']) && $_GET['action'] === 'generate_pdf' && $form_data) {
    generateFormFPDF($form_data, $visit);
    exit;
}

function generateFormFPDF($form_data, $visit) {
    // Create a simple HTML-based PDF since Word processing requires additional libraries
    $html = generateFormFHTML($form_data, $visit);
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Form_F_' . $form_data['form_number'] . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Use a simple HTML to PDF conversion approach
    // For production, consider using libraries like TCPDF, mPDF, or wkhtmltopdf
    echo generateSimplePDF($html);
}

function generateFormFHTML($form_data, $visit) {
    $current_date = date('d/m/Y');
    $current_time = date('H:i');
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Form F - Patient Consent Form</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 10px; }
            .form-title { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
            .form-number { font-size: 14px; margin-bottom: 5px; }
            .section { margin-bottom: 20px; }
            .section-title { font-weight: bold; font-size: 14px; margin-bottom: 10px; border-bottom: 1px solid #ccc; }
            .patient-info { display: table; width: 100%; }
            .patient-info-row { display: table-row; }
            .patient-info-cell { display: table-cell; width: 50%; padding: 5px; }
            .signature-area { border: 1px solid #000; height: 60px; margin: 10px 0; display: flex; align-items: center; justify-content: center; }
            .consent-list { margin: 15px 0; }
            .consent-list ol { margin-left: 20px; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; }
            @media print {
                body { margin: 10px; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="form-title">FORM F - PATIENT CONSENT FORM</div>
            <div class="form-number">Form Number: ' . htmlspecialchars($form_data['form_number']) . '</div>
            <div>Date: ' . $current_date . ' | Time: ' . $current_time . '</div>
        </div>
        
        <div class="section">
            <div class="section-title">PATIENT INFORMATION</div>
            <div class="patient-info">
                <div class="patient-info-row">
                    <div class="patient-info-cell"><strong>Patient Name:</strong> ' . htmlspecialchars($form_data['patient_name']) . '</div>
                    <div class="patient-info-cell"><strong>Age:</strong> ' . $form_data['patient_age'] . ' years</div>
                </div>
                <div class="patient-info-row">
                    <div class="patient-info-cell"><strong>Gender:</strong> ' . htmlspecialchars($form_data['patient_gender']) . '</div>
                    <div class="patient-info-cell"><strong>Phone:</strong> ' . htmlspecialchars($form_data['patient_phone']) . '</div>
                </div>
                <div class="patient-info-row">
                    <div class="patient-info-cell"><strong>Emergency Contact:</strong> ' . htmlspecialchars($form_data['emergency_contact']) . '</div>
                    <div class="patient-info-cell"><strong>Emergency Phone:</strong> ' . htmlspecialchars($form_data['emergency_phone']) . '</div>
                </div>
            </div>
            <div style="margin-top: 10px;">
                <strong>Address:</strong> ' . htmlspecialchars($form_data['patient_address']) . '
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">SCAN INFORMATION</div>
            <div class="patient-info">
                <div class="patient-info-row">
                    <div class="patient-info-cell"><strong>Scan Type:</strong> ' . htmlspecialchars($form_data['scan_type']) . '</div>
                    <div class="patient-info-cell"><strong>Referring Doctor:</strong> ' . htmlspecialchars($form_data['referring_doctor']) . '</div>
                </div>
            </div>
            <div style="margin-top: 10px;">
                <strong>Scan Details:</strong><br>
                ' . nl2br(htmlspecialchars($form_data['scan_details'])) . '
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">CONSENT STATEMENT</div>
            <p>I, <strong>' . htmlspecialchars($form_data['patient_name']) . '</strong>, hereby give my informed consent for the ultrasound examination as described above. I understand that:</p>
            <div class="consent-list">
                <ol>
                    <li>This is a diagnostic procedure that uses sound waves to create images of internal organs.</li>
                    <li>The procedure is generally safe and non-invasive.</li>
                    <li>I may be asked to change position or hold my breath during the examination.</li>
                    <li>The results will be interpreted by a qualified medical professional.</li>
                    <li>I have the right to ask questions and withdraw my consent at any time.</li>
                </ol>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">SIGNATURES</div>
            <div class="patient-info">
                <div class="patient-info-row">
                    <div class="patient-info-cell">
                        <strong>Patient Signature:</strong><br>
                        <div class="signature-area">Patient signature here</div>
                        <strong>Date:</strong> _________________
                    </div>
                    <div class="patient-info-cell">
                        <strong>Witness Signature:</strong><br>
                        <div class="signature-area">Witness signature here</div>
                        <strong>Date:</strong> _________________
                    </div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">ADDITIONAL NOTES</div>
            <p>' . nl2br(htmlspecialchars($form_data['notes'])) . '</p>
        </div>
        
        <div class="footer">
            <p>This form is a legal document. Please ensure all information is accurate and complete.</p>
            <p>Generated on: ' . $current_date . ' at ' . $current_time . '</p>
        </div>
    </body>
    </html>';
}

function generateSimplePDF($html) {
    // This is a simplified approach - for production, use proper PDF libraries
    // For now, we'll return HTML that can be printed as PDF
    return $html;
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
    <title>Form F Generator - Patient Consent Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .form-container { max-width: 800px; margin: 0 auto; }
        .template-preview { border: 2px dashed #ccc; padding: 20px; margin: 20px 0; background: #f9f9f9; }
    </style>
</head>
<body>
    <div class="container form-container">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-file-word"></i> Form F Generator</h2>
                    <div>
                        <a href="visit.php?id=<?= $visit_id ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Visit
                        </a>
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Patient Information Card -->
                <div class="card mb-4">
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

                <!-- Form F Creation -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-plus"></i> Create Form F for Patient Signature
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="patient_name" class="form-label">Patient Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="patient_name" name="patient_name" 
                                               value="<?= htmlspecialchars($visit['full_name']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="patient_age" class="form-label">Age <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="patient_age" name="patient_age" 
                                               value="<?= $age ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="patient_gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                        <select class="form-select" id="patient_gender" name="patient_gender" required>
                                            <option value="Male" <?= $visit['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= $visit['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                            <option value="Other" <?= $visit['gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="patient_phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="patient_phone" name="patient_phone" 
                                               value="<?= htmlspecialchars($visit['contact_number']) ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="patient_address" class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="patient_address" name="patient_address" rows="3" required><?= htmlspecialchars($visit['address']) ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="emergency_contact" class="form-label">Emergency Contact</label>
                                        <input type="text" class="form-control" id="emergency_contact" name="emergency_contact">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="emergency_phone" class="form-label">Emergency Phone</label>
                                        <input type="text" class="form-control" id="emergency_phone" name="emergency_phone">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="scan_type" class="form-label">Scan Type <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="scan_type" name="scan_type" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="referring_doctor" class="form-label">Referring Doctor</label>
                                        <select class="form-select" id="referring_doctor" name="referring_doctor">
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
                                <label for="scan_details" class="form-label">Scan Details</label>
                                <textarea class="form-control" id="scan_details" name="scan_details" rows="3" 
                                          placeholder="Describe the specific ultrasound scan to be performed..."></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Any additional notes or special instructions..."></textarea>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="create_form_f" class="btn btn-success btn-lg">
                                    <i class="fas fa-file-pdf"></i> Generate Form F PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Template Preview -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-eye"></i> Form F Template Preview
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="template-preview">
                            <h4><strong>FORM F - PATIENT CONSENT FORM</strong></h4>
                            <p><strong>Form Number:</strong> [Auto-generated]</p>
                            <p><strong>Date:</strong> [Current Date]</p>
                            <hr>
                            <h5><strong>PATIENT INFORMATION</strong></h5>
                            <p><strong>Patient Name:</strong> [Patient Name] | <strong>Age:</strong> [Age] years</p>
                            <p><strong>Gender:</strong> [Gender] | <strong>Phone:</strong> [Phone]</p>
                            <p><strong>Address:</strong> [Address]</p>
                            <p><strong>Emergency Contact:</strong> [Emergency Contact] | <strong>Emergency Phone:</strong> [Emergency Phone]</p>
                            <hr>
                            <h5><strong>SCAN INFORMATION</strong></h5>
                            <p><strong>Scan Type:</strong> [Scan Type] | <strong>Referring Doctor:</strong> [Referring Doctor]</p>
                            <p><strong>Scan Details:</strong> [Scan Details]</p>
                            <hr>
                            <h5><strong>CONSENT STATEMENT</strong></h5>
                            <p>I, [Patient Name], hereby give my informed consent for the ultrasound examination...</p>
                            <hr>
                            <h5><strong>SIGNATURES</strong></h5>
                            <p><strong>Patient Signature:</strong> [Signature Area] | <strong>Date:</strong> [Date]</p>
                            <p><strong>Witness Signature:</strong> [Signature Area] | <strong>Date:</strong> [Date]</p>
                            <hr>
                            <h5><strong>ADDITIONAL NOTES</strong></h5>
                            <p>[Notes]</p>
                        </div>
                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                This template will be auto-filled with patient details and converted to PDF for printing.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
