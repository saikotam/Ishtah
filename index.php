<?php
// index.php - Clinic Reception System Landing Page with Patient Management
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';
require_once 'includes/patient.php';

if (!function_exists('create_consultation_invoice')) {
    die('create_consultation_invoice not loaded! Check for syntax errors in includes/patient.php');
}

// Fetch doctors for visit registration
$doctors = $pdo->query("SELECT id, name, specialty, fees FROM doctors ORDER BY specialty, name")->fetchAll();

// Handle form submissions
$search_results = [];
$selected_patient = null;
$visit_history = [];
$message = '';
$new_visit_id = null;
$edit_mode = false;
$consultation_invoice_id = null;
$consultation_invoice = null;
$consultation_fee = 300; // Default fee, can be changed as needed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['search_query'])) {
        // Validate search query
        $search_query = trim($_POST['search_query']);
        if (strlen($search_query) < 2) {
            $message = 'Search query must be at least 2 characters long.';
        } else {
            $search_results = search_patients($pdo, $search_query);
            if (!empty($search_results)) {
                log_action('Reception', 'Patient Search', 'Searched for: ' . $search_query);
            }
        }
    } elseif (isset($_POST['register_patient'])) {
        // Simple server-side processing for patient registration
        try {
            $id = register_patient($pdo, $_POST);
            $selected_patient = get_patient($pdo, $id);
            $visit_history = get_visits($pdo, $id);
            $message = 'Patient registered successfully!';
            log_action('Reception', 'Patient Registered', 'Patient: ' . $_POST['full_name'] . ', Contact: ' . $_POST['contact_number']);
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['select_patient'])) {
        // Validate patient selection
        if (empty($_POST['patient_id']) || !is_numeric($_POST['patient_id'])) {
            $message = 'Invalid patient selection.';
        } else {
            $selected_patient = get_patient($pdo, $_POST['patient_id']);
            if ($selected_patient) {
                $visit_history = get_visits($pdo, $_POST['patient_id']);
                log_action('Reception', 'Patient Viewed', 'Viewed patient: ' . $selected_patient['full_name'] . ', ID: ' . $selected_patient['id']);
            } else {
                $message = 'Patient not found.';
            }
        }
    } elseif (isset($_POST['collect_consultation_fee'])) {
        // Simple server-side processing for visit registration
        try {
            // Step 1: Create consultation invoice
            $consultation_invoice_id = create_consultation_invoice(
                $pdo,
                $_POST['patient_id'],
                $_POST['consultation_fee'],
                $_POST['payment_mode']
            );
            
            // Step 2: Register visit
            $visit_data = [
                'patient_id' => $_POST['patient_id'],
                'doctor_id' => $_POST['doctor_id'],
                'reason' => 'General Consultation',
                'referred_by' => isset($_POST['referred_by']) ? $_POST['referred_by'] : null
            ];
            $new_visit_id = register_visit($pdo, $visit_data);
            
            // Step 3: Link invoice to visit
            link_consultation_invoice_to_visit($pdo, $consultation_invoice_id, $new_visit_id);
            
            // Get updated data
            $selected_patient = get_patient($pdo, $_POST['patient_id']);
            $visit_history = get_visits($pdo, $_POST['patient_id']);
            $consultation_invoice = get_consultation_invoice($pdo, $consultation_invoice_id);
            
            $message = 'Payment collected and visit registered successfully!';
            log_action('Reception', 'Visit Started', 'Patient: ' . $selected_patient['full_name'] . ', Doctor ID: ' . $_POST['doctor_id'] . ', Invoice: ' . $consultation_invoice_id);
        } catch (Exception $e) {
            $selected_patient = get_patient($pdo, $_POST['patient_id']);
            $visit_history = get_visits($pdo, $_POST['patient_id']);
            $message = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['edit_patient'])) {
        // Validate patient ID for editing
        if (empty($_POST['patient_id']) || !is_numeric($_POST['patient_id'])) {
            $message = 'Invalid patient ID for editing.';
        } else {
            $selected_patient = get_patient($pdo, $_POST['patient_id']);
            if ($selected_patient) {
                $visit_history = get_visits($pdo, $_POST['patient_id']);
                $edit_mode = true;
                log_action('Reception', 'Patient Edit Mode', 'Editing patient: ' . $selected_patient['full_name'] . ', ID: ' . $selected_patient['id']);
            } else {
                $message = 'Patient not found for editing.';
            }
        }
    } elseif (isset($_POST['update_patient'])) {
        // Simple server-side processing for patient update
        try {
            update_patient($pdo, $_POST);
            $selected_patient = get_patient($pdo, $_POST['patient_id']);
            $visit_history = get_visits($pdo, $_POST['patient_id']);
            $message = 'Patient details updated!';
            log_action('Reception', 'Patient Updated', 'Patient: ' . $_POST['full_name'] . ', ID: ' . $_POST['patient_id']);
        } catch (Exception $e) {
            $selected_patient = get_patient($pdo, $_POST['patient_id']);
            $visit_history = get_visits($pdo, $_POST['patient_id']);
            $edit_mode = true;
            $message = 'Error: ' . $e->getMessage();
        }
    }
}

// Fetch today's visits for summary table
$today_visits = $pdo->query("SELECT v.id AS visit_id, v.visit_date, v.reason, p.id AS patient_id, p.full_name, p.dob, p.gender, p.contact_number, p.address, p.lead_source, d.name AS doctor_name, d.specialty FROM visits v JOIN patients p ON v.patient_id = p.id JOIN doctors d ON v.doctor_id = d.id WHERE DATE(v.visit_date) = CURDATE() ORDER BY v.visit_date DESC")->fetchAll();

// Fetch all patients who have ever visited, with last visit date and total visits
$all_visited_patients = $pdo->query("SELECT p.id, p.full_name, MAX(v.visit_date) AS last_visit, COUNT(v.id) AS visit_count FROM patients p JOIN visits v ON p.id = v.patient_id GROUP BY p.id, p.full_name ORDER BY last_visit DESC")->fetchAll();

// Handle day-wise visit filter
$selected_date = isset($_POST['calendar_date']) ? $_POST['calendar_date'] : date('Y-m-d');
$calendar_visits = $pdo->prepare("SELECT v.id AS visit_id, v.visit_date, v.reason, p.id AS patient_id, p.full_name, p.dob, p.gender, p.contact_number, p.address, p.lead_source, d.name AS doctor_name, d.specialty FROM visits v JOIN patients p ON v.patient_id = p.id JOIN doctors d ON v.doctor_id = d.id WHERE DATE(v.visit_date) = ? ORDER BY v.visit_date DESC");
$calendar_visits->execute([$selected_date]);
$calendar_visits = $calendar_visits->fetchAll();

// For today's visits, add invoice info
foreach ($today_visits as &$v) {
    $v['invoices'] = get_visit_invoices($pdo, $v['visit_id']);
}
unset($v);
// For calendar visits, add invoice info
foreach ($calendar_visits as &$v) {
    $v['invoices'] = get_visit_invoices($pdo, $v['visit_id']);
}
unset($v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ishtah Clinic Reception</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .clickable-row:hover {
            background-color: #f8f9fa !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        .clickable-row {
            transition: all 0.2s ease;
        }
        
        /* Enhanced validation styles */
        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        .form-control.is-valid,
        .form-select.is-valid {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        
        .valid-feedback {
            display: none;
            color: #198754;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        
        /* Real-time validation feedback */
        .form-control:focus.is-invalid,
        .form-select:focus.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        .form-control:focus.is-valid,
        .form-select:focus.is-valid {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        
        /* Smooth transitions for validation states */
        .form-control,
        .form-select {
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        /* Blinking animation for next action button */
        @keyframes blink {
            0%, 50% { opacity: 1; transform: scale(1); }
            25%, 75% { opacity: 0.7; transform: scale(1.05); }
        }
        
        .next-action-btn {
            animation: blink 2s infinite;
            font-weight: bold;
            border: 2px solid #007bff;
            box-shadow: 0 0 10px rgba(0, 123, 255, 0.3);
            min-width: 120px;
            white-space: nowrap;
            font-size: 0.8rem;
        }
        
        .next-action-btn:hover {
            animation: none;
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(0, 123, 255, 0.5);
            z-index: 10;
        }
        
        .next-action-btn.completed {
            animation: none;
            background-color: #28a745;
            border-color: #28a745;
            color: white;
        }
        
        .next-action-btn .action-icon {
            margin-right: 4px;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">Ishtah Clinic</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link" href="patients.php">View All Patients</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="pharmacy_stock_summary.php">Medicines Search</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="lab_test_rates.php">Lab Test Rates</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="ultrasound_rates_display.php">Ultrasound Rates</a>
        </li>
        <!-- <li class="nav-item">
          <a class="nav-link" href="admin.php">Admin</a>
        </li> -->
      </ul>
    </div>
  </div>
</nav>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center">
            <h1 class="mb-4">Welcome to Ishtah Clinic Reception</h1>
            <p class="lead">Manage patient registrations, doctor visits, lab and pharmacy billing, and more with ease.</p>
        </div>
    </div>
</div>

<!-- Patient Management Buttons -->
<div class="container py-4">
    <h2 class="mb-4">Patient Management</h2>
    <?php if ($message): ?>
        <div class="alert alert-success"> <?= htmlspecialchars($message) ?> </div>
    <?php endif; ?>
    

    
    <div class="row justify-content-center mb-4">
        <div class="col-md-6 text-center">
            <button type="button" class="btn btn-primary btn-lg m-2" data-bs-toggle="modal" data-bs-target="#registerPatientModal">
                Register New Patient
            </button>
            <button type="button" class="btn btn-outline-secondary btn-lg m-2" data-bs-toggle="modal" data-bs-target="#searchPatientModal">
                Search Old Patients
            </button>
        </div>
    </div>
</div>

<!-- Register New Patient Modal -->
<div class="modal fade" id="registerPatientModal" tabindex="-1" aria-labelledby="registerPatientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="registerPatientModalLabel">Register New Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ((isset($_POST['register_patient']) && $message && !strpos($message, 'Error') && $selected_patient) || (isset($_POST['from_register']) && $selected_patient)): ?>
                    <?php if (isset($_POST['register_patient'])): ?>
                        <div class="alert alert-success">
                            <strong>Patient registered successfully!</strong>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Patient Details Section -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Patient Details</h5>
                            <p><strong>Name:</strong> <?= htmlspecialchars($selected_patient['full_name']) ?></p>
                            <p><strong>DOB:</strong> <?= htmlspecialchars($selected_patient['dob']) ?></p>
                            <p><strong>Gender:</strong> <?= htmlspecialchars($selected_patient['gender']) ?></p>
                            <p><strong>Contact:</strong> <?= htmlspecialchars($selected_patient['contact_number']) ?></p>
                            <p><strong>Address:</strong> <?= htmlspecialchars($selected_patient['address']) ?></p>
                            <p><strong>Lead Source:</strong> <?= htmlspecialchars($selected_patient['lead_source']) ?></p>
                        </div>
                    </div>
                    
                                                <!-- Visit Registration Section -->
                            <?php if (!$consultation_invoice): ?>
                                <form method="post">
                                    <input type="hidden" name="patient_id" value="<?= $selected_patient['id'] ?>">
                                    <input type="hidden" name="from_register" value="1">
                                    <div class="row g-3 align-items-end mb-3">
                                        <div class="col-md-6">
                                            <label for="doctor_id" class="form-label mb-0">Doctor <span class="text-danger">*</span></label>
                                            <select name="doctor_id" id="doctor_select_register" class="form-select" required onchange="updateConsultationFee(this.value, 'consultation_fee_register')">
                                                <option value="">Select Doctor</option>
                                                <?php foreach ($doctors as $d): ?>
                                                    <option value="<?= $d['id'] ?>" data-fees="<?= $d['fees'] ?>" <?= (isset($_POST['doctor_id']) && $_POST['doctor_id'] == $d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['specialty']) ?>) - ₹<?= number_format($d['fees'], 2) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a doctor</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="payment_mode" class="form-label mb-0">Payment Mode <span class="text-danger">*</span></label>
                                            <select name="payment_mode" class="form-select" required>
                                                <option value="">Payment Mode</option>
                                                <option value="Cash" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'Cash') ? 'selected' : '' ?>>Cash</option>
                                                <option value="Card" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'Card') ? 'selected' : '' ?>>Card</option>
                                                <option value="UPI" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'UPI') ? 'selected' : '' ?>>UPI</option>
                                                <option value="Other" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'Other') ? 'selected' : '' ?>>Other</option>
                                            </select>
                                            <div class="invalid-feedback">Please select a payment mode</div>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label for="consultation_fee_register" class="form-label mb-0">Consultation Fee (₹)</label>
                                            <input type="number" name="consultation_fee" id="consultation_fee_register" class="form-control" 
                                                   step="0.01" min="0" max="10000" value="<?= $consultation_fee ?>" required readonly>
                                            <div class="invalid-feedback">Consultation fee must be a positive number</div>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <button name="collect_consultation_fee" class="btn btn-success">Collect Payment & Register Visit</button>
                                    </div>
                                </form>
                    <?php endif; ?>
                    
                    <!-- Next Steps Section for Register Modal -->
                    <?php if (isset($_POST['collect_consultation_fee']) && isset($new_visit_id) && $new_visit_id): ?>
                        <div class="alert alert-success">
                            <strong>Payment collected and visit registered successfully!</strong>
                        </div>
                        <div class="alert alert-info">
                            <strong>Next steps for this visit:</strong><br>
                            <a href="prescription.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-primary btn-sm m-1">Print Prescription</a>
                            <a href="lab_billing.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-success btn-sm m-1">Lab Billing</a>
                            <a href="ultrasound_billing.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-info btn-sm m-1">Ultrasound Billing</a>
                            <a href="pharmacy_billing.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-warning btn-sm m-1">Pharmacy Billing</a>
                        </div>
                        <div class="text-center">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    <?php endif; ?>
                    
                <?php elseif (isset($_POST['register_patient']) && $message && !strpos($message, 'Error')): ?>
                    <div class="alert alert-success">
                        <strong>Patient registered successfully!</strong>
                    </div>
                    <div class="text-center">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="register_patient" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="full_name" class="form-control" required 
                                       minlength="2" maxlength="100" pattern="[a-zA-Z\s]+" 
                                       value="<?= isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : '' ?>">
                                <div class="invalid-feedback">Please enter a valid full name (letters and spaces only, 2-100 characters)</div>
                            </div>
                            <div class="col-md-6">
                                <label for="contact_number" class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="tel" name="contact_number" id="contact_number" class="form-control" required 
                                       pattern="[6-9][0-9]{9}" maxlength="10"
                                       value="<?= isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : '' ?>">
                                <div class="invalid-feedback">Please enter a valid 10-digit mobile number starting with 6-9</div>
                            </div>
                            <div class="col-md-4">
                                <label for="dob" class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" name="dob" id="dob" class="form-control" required 
                                       max="<?= date('Y-m-d') ?>"
                                       value="<?= isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : '' ?>">
                                <div class="invalid-feedback">Please select a valid date of birth (not in the future)</div>
                            </div>
                            <div class="col-md-4">
                                <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                <select name="gender" id="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Other') ? 'selected' : '' ?>>Other</option>
                                </select>
                                <div class="invalid-feedback">Please select a gender</div>
                            </div>
                            <div class="col-md-4">
                                <label for="lead_source" class="form-label">Lead Source</label>
                                <input type="text" name="lead_source" id="lead_source" class="form-control" 
                                       maxlength="100"
                                       value="<?= isset($_POST['lead_source']) ? htmlspecialchars($_POST['lead_source']) : '' ?>">
                                <div class="invalid-feedback">Lead source must not exceed 100 characters</div>
                            </div>
                            <div class="col-12">
                                <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea name="address" id="address" class="form-control" rows="3" required 
                                          minlength="10" maxlength="500"><?= isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '' ?></textarea>
                                <div class="invalid-feedback">Please enter a complete address (10-500 characters)</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Register Patient</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Search Patient Modal -->
<div class="modal fade" id="searchPatientModal" tabindex="-1" aria-labelledby="searchPatientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="searchPatientModalLabel">Search and Manage Patients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Search Section -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <form method="post" class="d-flex mb-3">
                            <input type="hidden" name="from_search" value="1">
                            <input type="text" name="search_query" class="form-control me-2" 
                                   placeholder="Search by name or phone" required 
                                   minlength="2" maxlength="50"
                                   value="<?= isset($_POST['search_query']) ? htmlspecialchars($_POST['search_query']) : '' ?>">
                            <button class="btn btn-primary" type="submit">Search</button>
                        </form>
                        <div class="invalid-feedback d-block">Search query must be at least 2 characters long</div>
                        <?php if ($search_results): ?>
                            <div class="list-group">
                                <?php foreach ($search_results as $pat): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="patient_id" value="<?= $pat['id'] ?>">
                                        <input type="hidden" name="from_search" value="1">
                                        <button name="select_patient" class="list-group-item list-group-item-action" type="submit">
                                            <?= htmlspecialchars($pat['full_name']) ?> (<?= htmlspecialchars($pat['contact_number']) ?>)
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Next Steps Section for Search Modal -->
                <?php if (isset($_POST['collect_consultation_fee']) && isset($new_visit_id) && $new_visit_id && isset($_POST['from_search'])): ?>
                    <div class="alert alert-success">
                        <strong>Payment collected and visit registered successfully!</strong>
                    </div>
                    <div class="alert alert-info">
                        <strong>Next steps for this visit:</strong><br>
                        <a href="prescription.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-primary btn-sm m-1">Print Prescription</a>
                        <a href="lab_billing.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-success btn-sm m-1">Lab Billing</a>
                        <a href="ultrasound_billing.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-info btn-sm m-1">Ultrasound Billing</a>
                        <a href="pharmacy_billing.php?visit_id=<?= $new_visit_id ?>" class="btn btn-outline-warning btn-sm m-1">Pharmacy Billing</a>
                    </div>
                    <div class="text-center">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                <?php else: ?>
                
                <!-- Patient Details Section -->
                <?php if ($selected_patient && isset($_POST['from_search'])): ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Patient Details</h5>
                            <?php if ($edit_mode): ?>
                                <form method="post">
                                    <input type="hidden" name="update_patient" value="1">
                                    <input type="hidden" name="patient_id" value="<?= $selected_patient['id'] ?>">
                                    <div class="row g-2">
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" name="full_name" class="form-control" 
                                                   minlength="2" maxlength="100" pattern="[a-zA-Z\s]+"
                                                   value="<?= isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : htmlspecialchars($selected_patient['full_name']) ?>" required>
                                            <div class="invalid-feedback">Please enter a valid full name (letters and spaces only, 2-100 characters)</div>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                            <input type="date" name="dob" class="form-control" 
                                                   max="<?= date('Y-m-d') ?>"
                                                   value="<?= isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : htmlspecialchars($selected_patient['dob']) ?>" required>
                                            <div class="invalid-feedback">Please select a valid date of birth (not in the future)</div>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                                            <select name="gender" class="form-control" required>
                                                <option value="">Gender</option>
                                                <option value="Male" <?= (isset($_POST['gender']) ? ($_POST['gender']==='Male'?'selected':'') : ($selected_patient['gender']==='Male'?'selected':'')) ?>>Male</option>
                                                <option value="Female" <?= (isset($_POST['gender']) ? ($_POST['gender']==='Female'?'selected':'') : ($selected_patient['gender']==='Female'?'selected':'')) ?>>Female</option>
                                                <option value="Other" <?= (isset($_POST['gender']) ? ($_POST['gender']==='Other'?'selected':'') : ($selected_patient['gender']==='Other'?'selected':'')) ?>>Other</option>
                                            </select>
                                            <div class="invalid-feedback">Please select a gender</div>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                            <input type="tel" name="contact_number" class="form-control" 
                                                   pattern="[6-9][0-9]{9}" maxlength="10"
                                                   value="<?= isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : htmlspecialchars($selected_patient['contact_number']) ?>" required>
                                            <div class="invalid-feedback">Please enter a valid 10-digit mobile number starting with 6-9</div>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Address <span class="text-danger">*</span></label>
                                            <input type="text" name="address" class="form-control" 
                                                   minlength="10" maxlength="500"
                                                   value="<?= isset($_POST['address']) ? htmlspecialchars($_POST['address']) : htmlspecialchars($selected_patient['address']) ?>" required>
                                            <div class="invalid-feedback">Please enter a complete address (10-500 characters)</div>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Lead Source</label>
                                            <input type="text" name="lead_source" class="form-control" value="<?= isset($_POST['lead_source']) ? htmlspecialchars($_POST['lead_source']) : htmlspecialchars($selected_patient['lead_source']) ?>">
                                        </div>
                                        <div class="col-md-12">
                                            <button class="btn btn-success" type="submit">Update</button>
                                            <a href="index.php" class="btn btn-secondary ms-2">Cancel</a>
                                        </div>
                                    </div>
                                </form>
                            <?php else: ?>
                                <p><strong>Name:</strong> <?= htmlspecialchars($selected_patient['full_name']) ?></p>
                                <p><strong>DOB:</strong> <?= htmlspecialchars($selected_patient['dob']) ?></p>
                                <p><strong>Gender:</strong> <?= htmlspecialchars($selected_patient['gender']) ?></p>
                                <p><strong>Contact:</strong> <?= htmlspecialchars($selected_patient['contact_number']) ?></p>
                                <p><strong>Address:</strong> <?= htmlspecialchars($selected_patient['address']) ?></p>
                                <p><strong>Lead Source:</strong> <?= htmlspecialchars($selected_patient['lead_source']) ?></p>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="patient_id" value="<?= $selected_patient['id'] ?>">
                                    <button name="edit_patient" class="btn btn-outline-secondary btn-sm">Edit</button>
                                </form>
                            <?php endif; ?>
                            
                            <!-- Visit Registration Section -->
                            <?php if (!$consultation_invoice): ?>
                                <form method="post" class="mt-3">
                                    <input type="hidden" name="patient_id" value="<?= $selected_patient['id'] ?>">
                                    <input type="hidden" name="from_search" value="1">
                                    <div class="row g-3 align-items-end mb-3">
                                        <div class="col-md-6">
                                            <label for="doctor_id" class="form-label mb-0">Doctor <span class="text-danger">*</span></label>
                                            <select name="doctor_id" id="doctor_select_search" class="form-select" required onchange="updateConsultationFee(this.value, 'consultation_fee_search')">
                                                <option value="">Select Doctor</option>
                                                <?php foreach ($doctors as $d): ?>
                                                    <option value="<?= $d['id'] ?>" data-fees="<?= $d['fees'] ?>"><?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['specialty']) ?>) - ₹<?= number_format($d['fees'], 2) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a doctor</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="payment_mode" class="form-label mb-0">Payment Mode <span class="text-danger">*</span></label>
                                            <select name="payment_mode" class="form-select" required>
                                                <option value="">Payment Mode</option>
                                                <option value="Cash">Cash</option>
                                                <option value="Card">Card</option>
                                                <option value="UPI">UPI</option>
                                                <option value="Other">Other</option>
                                            </select>
                                            <div class="invalid-feedback">Please select a payment mode</div>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label for="consultation_fee_search" class="form-label mb-0">Consultation Fee (₹)</label>
                                            <input type="number" name="consultation_fee" id="consultation_fee_search" class="form-control" 
                                                   step="0.01" min="0" max="10000" value="<?= $consultation_fee ?>" required readonly>
                                            <div class="invalid-feedback">Consultation fee must be a positive number</div>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <button name="collect_consultation_fee" class="btn btn-success">Collect Payment & Register Visit</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Visit History Section -->
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Visit History</h5>
                            <?php if ($visit_history && count($visit_history) > 1): ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Visit ID</th>
                                                <th>Doctor</th>
                                                <th>Consultation Invoices</th>
                                                <th>Lab Invoices</th>
                                                <th>Ultrasound Invoices</th>
                                                <th>Pharmacy Invoices</th>
                                                <th>Documents</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($visit_history as $visit): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($visit['visit_date']) ?></td>
                                                <td><?= htmlspecialchars($visit['id']) ?></td>
                                                <td><?= htmlspecialchars($visit['doctor_name'] ?? 'N/A') ?> <?= $visit['doctor_specialty'] ? '(' . htmlspecialchars($visit['doctor_specialty']) . ')' : '' ?></td>
                                                <td>
                                                    <?php
                                                    $stmt = $pdo->prepare("SELECT id FROM consultation_invoices WHERE visit_id = ?");
                                                    $stmt->execute([$visit['id']]);
                                                    $consultation_invoices = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                                    if ($consultation_invoices && count($consultation_invoices) > 0):
                                                        $links = array_map(function($id) {
                                                            return '<a href="print_consultation_invoice.php?invoice_id=' . $id . '" target="" class="btn btn-outline-primary btn-sm m-1">#' . $id . '</a>';
                                                        }, $consultation_invoices);
                                                        echo implode(' ', $links);
                                                    else:
                                                        echo '-';
                                                    endif;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stmt = $pdo->prepare("SELECT invoice_number FROM lab_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC");
                                                    $stmt->execute([$visit['id']]);
                                                    $lab_invoices = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                                    if ($lab_invoices && count($lab_invoices) > 0):
                                                        $links = array_map(function($num) use ($visit) {
                                                            return '<a href="lab_billing.php?visit_id=' . $visit['id'] . '&invoice_number=' . urlencode($num) . '" target="" class="btn btn-outline-success btn-sm m-1">#' . htmlspecialchars($num) . '</a>';
                                                        }, $lab_invoices);
                                                        echo implode(' ', $links);
                                                    else:
                                                        echo '-';
                                                    endif;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stmt = $pdo->prepare("SELECT invoice_number FROM ultrasound_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC");
                                                    $stmt->execute([$visit['id']]);
                                                    $ultrasound_invoices = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                                    if ($ultrasound_invoices && count($ultrasound_invoices) > 0):
                                                        $links = array_map(function($num) use ($visit) {
                                                            return '<a href="ultrasound_billing.php?visit_id=' . $visit['id'] . '&invoice_number=' . urlencode($num) . '" target="" class="btn btn-outline-info btn-sm m-1">#' . htmlspecialchars($num) . '</a>';
                                                        }, $ultrasound_invoices);
                                                        echo implode(' ', $links);
                                                    else:
                                                        echo '-';
                                                    endif;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stmt = $pdo->prepare("SELECT invoice_number FROM pharmacy_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC");
                                                    $stmt->execute([$visit['id']]);
                                                    $pharmacy_invoices = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                                    if ($pharmacy_invoices && count($pharmacy_invoices) > 0):
                                                        $links = array_map(function($num) use ($visit) {
                                                            return '<a href="pharmacy_billing.php?visit_id=' . $visit['id'] . '&invoice_number=' . urlencode($num) . '" target="" class="btn btn-outline-warning btn-sm m-1">#' . htmlspecialchars($num) . '</a>';
                                                        }, $pharmacy_invoices);
                                                        echo implode(' ', $links);
                                                    else:
                                                        echo '-';
                                                    endif;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stmt = $pdo->prepare("SELECT id, filename, original_name, file_type FROM visit_documents WHERE visit_id = ? ORDER BY uploaded_at DESC");
                                                    $stmt->execute([$visit['id']]);
                                                    $documents = $stmt->fetchAll();
                                                    if ($documents && count($documents) > 0):
                                                        $links = array_map(function($doc) {
                                                            $icon = $doc['file_type'] === 'application/pdf' ? '📄' : '🖼️';
                                                            return '<a href="uploads/scans/' . htmlspecialchars($doc['filename']) . '" target="_blank" class="btn btn-outline-info btn-sm m-1" title="' . htmlspecialchars($doc['original_name']) . '">' . $icon . ' ' . htmlspecialchars(substr($doc['original_name'], 0, 15)) . (strlen($doc['original_name']) > 15 ? '...' : '') . '</a>';
                                                        }, $documents);
                                                        echo implode(' ', $links);
                                                    else:
                                                        echo '-';
                                                    endif;
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($visit_history): ?>
                                <p>Only one visit found for this patient.</p>
                            <?php else: ?>
                                <p>No visits found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Today's Visits Table -->
<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Today's Visits</h4>
        <button class="btn btn-outline-primary btn-sm" onclick="loadNextActions()">
            <i class="bi bi-arrow-clockwise"></i> Refresh Actions
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Reason</th>
                    <th>Next Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($today_visits as $v): ?>
                <tr class="clickable-row" data-visit-id="<?= $v['visit_id'] ?>" data-patient-id="<?= $v['patient_id'] ?>" data-patient-name="<?= htmlspecialchars($v['full_name']) ?>" data-patient-dob="<?= htmlspecialchars($v['dob']) ?>" data-patient-gender="<?= htmlspecialchars($v['gender']) ?>" data-patient-contact="<?= htmlspecialchars($v['contact_number']) ?>" data-patient-address="<?= htmlspecialchars($v['address']) ?>" data-patient-lead="<?= htmlspecialchars($v['lead_source']) ?>" data-doctor-name="<?= htmlspecialchars($v['doctor_name']) ?>" data-doctor-specialty="<?= htmlspecialchars($v['specialty']) ?>" data-reason="<?= htmlspecialchars($v['reason']) ?>" data-visit-date="<?= htmlspecialchars($v['visit_date']) ?>" data-lab-invoice="<?= $v['invoices']['lab'] ?? '' ?>" data-pharmacy-invoice="<?= $v['invoices']['pharmacy'] ?? '' ?>" style="cursor: pointer;">
                    <td><?= date('H:i', strtotime($v['visit_date'])) ?></td>
                    <td><?= htmlspecialchars($v['full_name']) ?></td>
                    <td><?= htmlspecialchars($v['doctor_name']) ?> (<?= htmlspecialchars($v['specialty']) ?>)</td>
                    <td><?= htmlspecialchars($v['reason']) ?></td>
                    <td class="text-center">
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <button class="btn btn-sm next-action-btn" data-visit-id="<?= $v['visit_id'] ?>" onclick="event.stopPropagation();" title="Click to perform the next pending action for this patient">
                                <span class="action-icon">⏳</span>
                                <span class="action-text">Loading...</span>
                            </button>
                            <div class="pending-actions-tooltip" data-visit-id="<?= $v['visit_id'] ?>" style="display: none; position: absolute; background: #333; color: white; padding: 8px; border-radius: 4px; font-size: 12px; z-index: 1000; max-width: 300px;"></div>
                            <div class="form-check form-f-checkbox" data-visit-id="<?= $v['visit_id'] ?>" style="display: none;">
                                <input class="form-check-input" type="checkbox" onchange="handleFormFCheckbox(<?= $v['visit_id'] ?>, this)">
                                <label class="form-check-label small">Form F Printed</label>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($today_visits)): ?>
                <tr><td colspan="5" class="text-center">No visits registered today.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Calendar-style Day-wise Visits -->
<div class="container mt-5">
    <h4>Day-wise Visits</h4>
    <div id="daywise-visits-section">
        <form id="daywise-form" method="post" class="mb-3 d-flex align-items-center justify-content-center gap-2">
            <?php
                $prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
                $next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
                $is_today = ($selected_date === date('Y-m-d'));
                $max_date = date('Y-m-d');
            ?>
            <button type="button" id="prevDayBtn" class="btn btn-outline-secondary" title="Previous Day" <?= ($selected_date <= '2000-01-01') ? 'disabled' : '' ?>>
                &larr;
            </button>
            <input type="date" id="calendar_date_input" name="calendar_date" class="form-control text-center" value="<?= htmlspecialchars($selected_date) ?>" max="<?= $max_date ?>" style="width: 160px;">
            <button type="button" id="nextDayBtn" class="btn btn-outline-secondary" title="Next Day" <?= ($selected_date === $max_date) ? 'disabled' : '' ?>>
                &rarr;
            </button>
            <button type="button" id="todayBtn" class="btn btn-outline-primary" <?= $is_today ? 'disabled' : '' ?>>Today</button>
            <button type="submit" id="hiddenSubmit" style="display:none;"></button>
        </form>
        <div id="daywise-visits-table" class="table-responsive">
            <table class="table table-bordered table-striped table-hover table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Time</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (
                        isset($calendar_visits) ? $calendar_visits : [] as $v): ?>
                    <tr class="clickable-row" data-visit-id="<?= $v['visit_id'] ?>" data-patient-id="<?= $v['patient_id'] ?>" data-patient-name="<?= htmlspecialchars($v['full_name']) ?>" data-patient-dob="<?= htmlspecialchars($v['dob']) ?>" data-patient-gender="<?= htmlspecialchars($v['gender']) ?>" data-patient-contact="<?= htmlspecialchars($v['contact_number']) ?>" data-patient-address="<?= htmlspecialchars($v['address']) ?>" data-patient-lead="<?= htmlspecialchars($v['lead_source']) ?>" data-doctor-name="<?= htmlspecialchars($v['doctor_name']) ?>" data-doctor-specialty="<?= htmlspecialchars($v['specialty']) ?>" data-reason="<?= htmlspecialchars($v['reason']) ?>" data-visit-date="<?= htmlspecialchars($v['visit_date']) ?>" data-lab-invoice="<?= $v['invoices']['lab'] ?? '' ?>" data-pharmacy-invoice="<?= $v['invoices']['pharmacy'] ?? '' ?>" style="cursor: pointer;">
                        <td><?= date('H:i', strtotime($v['visit_date'])) ?></td>
                        <td><?= htmlspecialchars($v['full_name']) ?></td>
                        <td><?= htmlspecialchars($v['doctor_name']) ?> (<?= htmlspecialchars($v['specialty']) ?>)</td>
                        <td><?= htmlspecialchars($v['reason']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($calendar_visits)): ?>
                    <tr><td colspan="4" class="text-center">No visits for this day.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Function to update consultation fee when doctor is selected
function updateConsultationFee(doctorId, feeFieldId) {
    if (!doctorId) {
        document.getElementById(feeFieldId).value = '<?= $consultation_fee ?>';
        return;
    }
    
    // Determine which select element to use based on the fee field ID
    const selectId = feeFieldId === 'consultation_fee_register' ? 'doctor_select_register' : 'doctor_select_search';
    const select = document.getElementById(selectId);
    const selectedOption = select.options[select.selectedIndex];
    const fees = selectedOption.getAttribute('data-fees');
    
    if (fees) {
        document.getElementById(feeFieldId).value = fees;
    } else {
        document.getElementById(feeFieldId).value = '<?= $consultation_fee ?>';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Modal management
    const registerModal = new bootstrap.Modal(document.getElementById('registerPatientModal'));
    const searchModal = new bootstrap.Modal(document.getElementById('searchPatientModal'));
    
    // Keep modals open after form submissions
    <?php if (isset($_POST['register_patient']) || isset($_POST['from_register'])) : ?>
        registerModal.show();
    <?php elseif (isset($_POST['search_query']) || isset($_POST['from_search']) || isset($_POST['select_patient']) || isset($_POST['edit_patient']) || isset($_POST['update_patient'])): ?>
        searchModal.show();
    <?php endif; ?>
    
    // Don't auto-close modals on successful visit registration - let users see the next steps
    // The modals will show the next steps buttons instead
    
    function attachDaywiseListeners() {
        const form = document.getElementById('daywise-form');
        const tableContainer = document.getElementById('daywise-visits-table');
        const maxDate = document.getElementById('calendar_date_input').max;
        const dateInput = document.getElementById('calendar_date_input');
        const prevBtn = document.getElementById('prevDayBtn');
        const nextBtn = document.getElementById('nextDayBtn');
        const todayBtn = document.getElementById('todayBtn');

        prevBtn && prevBtn.addEventListener('click', function(e) {
            e.preventDefault();
            let d = new Date(dateInput.value);
            d.setDate(d.getDate() - 1);
            dateInput.value = d.toISOString().slice(0,10);
            submitAjax();
        });
        nextBtn && nextBtn.addEventListener('click', function(e) {
            e.preventDefault();
            let d = new Date(dateInput.value);
            d.setDate(d.getDate() + 1);
            let today = new Date(maxDate);
            today.setHours(0,0,0,0);
            if (d > today) return;
            dateInput.value = d.toISOString().slice(0,10);
            submitAjax();
        });
        todayBtn && todayBtn.addEventListener('click', function(e) {
            e.preventDefault();
            let today = new Date(maxDate);
            dateInput.value = today.toISOString().slice(0,10);
            submitAjax();
        });
        dateInput && dateInput.addEventListener('change', function(e) {
            submitAjax();
        });
        form && form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitAjax();
        });
    }

    function submitAjax() {
        const section = document.getElementById('daywise-visits-section');
        const form = document.getElementById('daywise-form');
        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(response => response.text())
        .then(html => {
            // Replace the whole section (form + table)
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newSection = doc.getElementById('daywise-visits-section');
            if (newSection && section) {
                section.replaceWith(newSection);
                attachDaywiseListeners(); // Re-attach listeners after AJAX update
                loadNextActions(); // Reload next actions for the new table
            }
        });
    }

    attachDaywiseListeners(); // Initial attach
    
    // Load next actions for all visits
    loadNextActions();
    
    // Check if we're returning from a billing page with a new bill
    const urlParams = new URLSearchParams(window.location.search);
    const billCreated = urlParams.get('bill_created');
    const visitId = urlParams.get('visit_id');
    
    if (billCreated === 'true' && visitId) {
        // Show success message
        showActionSuccess('Bill created successfully! Next actions updated.');
        
        // Refresh the specific action button for this visit
        setTimeout(() => {
            refreshActionButton(visitId);
        }, 500);
        
        // Clean up URL parameters
        const newUrl = new URL(window.location);
        newUrl.searchParams.delete('bill_created');
        newUrl.searchParams.delete('visit_id');
        window.history.replaceState({}, '', newUrl);
    }
    
    // Add click handlers for visit rows
    document.addEventListener('click', function(e) {
        if (e.target.closest('.clickable-row')) {
            const row = e.target.closest('.clickable-row');
            const visitId = row.dataset.visitId;
            const patientName = row.dataset.patientName;
            const patientDob = row.dataset.patientDob;
            const patientGender = row.dataset.patientGender;
            const patientContact = row.dataset.patientContact;
            const patientAddress = row.dataset.patientAddress;
            const patientLead = row.dataset.patientLead;
            const doctorName = row.dataset.doctorName;
            const doctorSpecialty = row.dataset.doctorSpecialty;
            const reason = row.dataset.reason;
            const visitDate = row.dataset.visitDate;
            const labInvoice = row.dataset.labInvoice;
            const pharmacyInvoice = row.dataset.pharmacyInvoice;
            
            // Populate modal with data
            document.getElementById('visitModalPatientName').textContent = patientName;
            document.getElementById('visitModalPatientDob').textContent = patientDob;
            document.getElementById('visitModalPatientGender').textContent = patientGender;
            document.getElementById('visitModalPatientContact').textContent = patientContact;
            document.getElementById('visitModalPatientAddress').textContent = patientAddress;
            document.getElementById('visitModalPatientLead').textContent = patientLead || 'N/A';
            document.getElementById('visitModalDoctorName').textContent = doctorName + ' (' + doctorSpecialty + ')';
            document.getElementById('visitModalReason').textContent = reason;
            document.getElementById('visitModalVisitDate').textContent = new Date(visitDate).toLocaleString();
            
            // Update action buttons with correct URLs
            document.getElementById('visitModalPrescriptionBtn').href = 'prescription.php?visit_id=' + visitId;
            document.getElementById('visitModalLabBtn').href = 'lab_billing.php?visit_id=' + visitId;
            document.getElementById('visitModalUltrasoundBtn').href = 'ultrasound_billing.php?visit_id=' + visitId;
            document.getElementById('visitModalPharmacyBtn').href = 'pharmacy_billing.php?visit_id=' + visitId;
            
            // Toggle Form F button depending on visit content
            fetch('get_visit_form_f_flag.php?visit_id=' + visitId)
                .then(res => res.json())
                .then(data => {
                    const formFBtn = document.getElementById('visitModalFormFBtn');
                    if (data && data.success && data.requires_form_f) {
                        formFBtn.style.display = 'inline-block';
                    } else {
                        formFBtn.style.display = 'none';
                    }
                })
                .catch(() => {
                    const formFBtn = document.getElementById('visitModalFormFBtn');
                    if (formFBtn) formFBtn.style.display = 'none';
                });
            
            // Set current visit ID for scan functionality
            currentVisitId = visitId;
            
            // Show modal
            const visitModal = new bootstrap.Modal(document.getElementById('visitDetailsModal'));
            visitModal.show();
            
            // Automatically load invoices for this visit
            setTimeout(() => {
                loadVisitInvoices();
            }, 100);
        }
    });
    
    // Load next actions for all visits
    function loadNextActions() {
        console.log('Loading next actions...');
        const actionButtons = document.querySelectorAll('.next-action-btn');
        console.log('Found', actionButtons.length, 'action buttons');
        
        actionButtons.forEach(button => {
            const visitId = button.dataset.visitId;
            if (visitId) {
                console.log('Loading action for visit ID:', visitId);
                // Show loading state
                const iconSpan = button.querySelector('.action-icon');
                const textSpan = button.querySelector('.action-text');
                iconSpan.textContent = '⏳';
                textSpan.textContent = 'Loading...';
                button.classList.remove('completed');
                
                fetch('get_next_action.php?visit_id=' + visitId)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Action data for visit', visitId, ':', data);
                        if (data.success) {
                            iconSpan.textContent = data.action_icon;
                            
                            // Show action text with pending count if there are multiple actions
                            if (data.pending_actions_count > 1) {
                                textSpan.textContent = data.action_text + ` (${data.pending_actions_count} pending)`;
                            } else {
                                textSpan.textContent = data.action_text;
                            }
                            
                            // Set button URL and click handler
                            if (data.action_url && data.action_url !== '#') {
                                button.onclick = function(e) {
                                    e.stopPropagation();
                                    if (data.next_action === 'print_form_f') {
                                        // For Form F, show confirmation dialog instead of opening PDF directly
                                        handleNextAction(data.next_action, visitId, data);
                                    } else {
                                        window.open(data.action_url, '_blank');
                                        // Show success message
                                        showActionSuccess(data.action_text);
                                    }
                                };
                            } else {
                                // For actions that need to open modal or trigger specific behavior
                                button.onclick = function(e) {
                                    e.stopPropagation();
                                    handleNextAction(data.next_action, visitId, data);
                                };
                            }
                            
                            // Add completed class if action is completed
                            if (data.next_action === 'completed') {
                                button.classList.add('completed');
                            }
                            
                            // Show/hide Form F checkbox based on current action
                            const formFCheckbox = document.querySelector(`.form-f-checkbox[data-visit-id="${visitId}"]`);
                            if (formFCheckbox) {
                                if (data.next_action === 'print_form_f') {
                                    formFCheckbox.style.display = 'block';
                                } else {
                                    formFCheckbox.style.display = 'none';
                                }
                            }
                            
                            // Update tooltip with all pending actions if there are multiple
                            const tooltip = document.querySelector(`.pending-actions-tooltip[data-visit-id="${visitId}"]`);
                            if (tooltip && data.all_pending_actions && data.all_pending_actions.length > 1) {
                                let tooltipText = '<strong>All Pending Actions:</strong><br>';
                                data.all_pending_actions.forEach((action, index) => {
                                    tooltipText += `${index + 1}. ${action.text}<br>`;
                                });
                                tooltip.innerHTML = tooltipText;
                                
                                // Add hover events to show/hide tooltip
                                button.addEventListener('mouseenter', function(e) {
                                    const rect = button.getBoundingClientRect();
                                    tooltip.style.left = rect.left + 'px';
                                    tooltip.style.top = (rect.bottom + 5) + 'px';
                                    tooltip.style.display = 'block';
                                });
                                
                                button.addEventListener('mouseleave', function(e) {
                                    tooltip.style.display = 'none';
                                });
                            }
                        } else {
                            console.error('Error loading next action:', data.message);
                            iconSpan.textContent = '❌';
                            textSpan.textContent = 'Error';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading next action:', error);
                        iconSpan.textContent = '❌';
                        textSpan.textContent = 'Error';
                    });
            }
        });
    }
    
    // Show success message for completed actions
    function showActionSuccess(actionText) {
        // Create a temporary success message
        const successDiv = document.createElement('div');
        successDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
        successDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        successDiv.innerHTML = `
            <i class="bi bi-check-circle"></i> ${actionText} action initiated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(successDiv);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (successDiv.parentNode) {
                successDiv.remove();
            }
        }, 3000);
    }
    
    // Refresh a specific action button
    function refreshActionButton(visitId) {
        console.log('refreshActionButton called for visit', visitId);
        const button = document.querySelector(`.next-action-btn[data-visit-id="${visitId}"]`);
        if (button) {
            console.log('Found button for visit', visitId);
            const iconSpan = button.querySelector('.action-icon');
            const textSpan = button.querySelector('.action-text');
            iconSpan.textContent = '⏳';
            textSpan.textContent = 'Loading...';
            button.classList.remove('completed');
            
            fetch('get_next_action.php?visit_id=' + visitId)
                .then(response => response.json())
                .then(data => {
                    console.log('Refreshed action data for visit', visitId, ':', data);
                    if (data.success) {
                        iconSpan.textContent = data.action_icon;
                        
                        // Show action text with pending count if there are multiple actions
                        if (data.pending_actions_count > 1) {
                            textSpan.textContent = data.action_text + ` (${data.pending_actions_count} pending)`;
                        } else {
                            textSpan.textContent = data.action_text;
                        }
                        
                        // Set button URL and click handler
                        if (data.action_url && data.action_url !== '#') {
                            button.onclick = function(e) {
                                e.stopPropagation();
                                if (data.next_action === 'print_form_f') {
                                    // For Form F, show confirmation dialog instead of opening PDF directly
                                    handleNextAction(data.next_action, visitId, data);
                                } else {
                                    window.open(data.action_url, '_blank');
                                    // Show success message
                                    showActionSuccess(data.action_text);
                                }
                            };
                        } else {
                            // For actions that need to open modal or trigger specific behavior
                            button.onclick = function(e) {
                                e.stopPropagation();
                                handleNextAction(data.next_action, visitId, data);
                            };
                        }
                        
                        // Add completed class if action is completed
                        if (data.next_action === 'completed') {
                            button.classList.add('completed');
                        }
                        
                        // Show/hide Form F checkbox based on current action
                        const formFCheckbox = document.querySelector(`.form-f-checkbox[data-visit-id="${visitId}"]`);
                        if (formFCheckbox) {
                            if (data.next_action === 'print_form_f') {
                                formFCheckbox.style.display = 'block';
                            } else {
                                formFCheckbox.style.display = 'none';
                            }
                        }
                        
                        // Update tooltip with all pending actions if there are multiple
                        const tooltip = document.querySelector(`.pending-actions-tooltip[data-visit-id="${visitId}"]`);
                        if (tooltip && data.all_pending_actions && data.all_pending_actions.length > 1) {
                            let tooltipText = '<strong>All Pending Actions:</strong><br>';
                            data.all_pending_actions.forEach((action, index) => {
                                tooltipText += `${index + 1}. ${action.text}<br>`;
                            });
                            tooltip.innerHTML = tooltipText;
                            
                            // Add hover events to show/hide tooltip
                            button.addEventListener('mouseenter', function(e) {
                                const rect = button.getBoundingClientRect();
                                tooltip.style.left = rect.left + 'px';
                                tooltip.style.top = (rect.bottom + 5) + 'px';
                                tooltip.style.display = 'block';
                            });
                            
                            button.addEventListener('mouseleave', function(e) {
                                tooltip.style.display = 'none';
                            });
                        }
                    } else {
                        console.error('Error refreshing next action:', data.message);
                        iconSpan.textContent = '❌';
                        textSpan.textContent = 'Error';
                    }
                })
                .catch(error => {
                    console.error('Error refreshing next action:', error);
                    iconSpan.textContent = '❌';
                    textSpan.textContent = 'Error';
                });
        }
    }
    
    // Handle next action button clicks
    function handleNextAction(action, visitId, data) {
        switch (action) {
            case 'consultation':
                // Open the visit modal to show consultation options
                openVisitModal(visitId);
                break;
            case 'scan_prescription':
            case 'upload_trf':
            case 'scan_form_f':
                // Open the visit modal and trigger scan functionality
                openVisitModal(visitId);
                // Trigger scan after modal opens
                setTimeout(() => {
                    document.getElementById('scanFileInput').click();
                }, 500);
                break;
            case 'print_form_f':
                // Open Form F PDF directly
                window.open('Form F.pdf', '_blank');
                break;
            case 'completed':
                alert('All actions completed for this patient!');
                break;
            default:
                console.log('Unknown action:', action);
        }
    }
    
    // Handle Form F checkbox change
    window.handleFormFCheckbox = function(visitId, checkbox) {
        const formFPrinted = checkbox.checked ? 1 : 0;
        console.log('Form F checkbox changed for visit', visitId, 'checked:', checkbox.checked);
        
        fetch('confirm_form_f_printed.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                visit_id: visitId,
                form_f_printed: formFPrinted
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Form F confirmation response:', data);
            if (data.success) {
                // Refresh the action button to show next step
                console.log('Refreshing action button for visit', visitId);
                refreshActionButton(visitId);
            } else {
                alert('Error updating Form F status: ' + data.message);
                // Revert checkbox if there was an error
                checkbox.checked = !checkbox.checked;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating Form F status. Please try again.');
            // Revert checkbox if there was an error
            checkbox.checked = !checkbox.checked;
        });
    }
    
    // Function to open visit modal with patient data
    function openVisitModal(visitId) {
        // Find the row with this visit ID
        const row = document.querySelector(`tr[data-visit-id="${visitId}"]`);
        if (row) {
            // Extract data from the row
            const patientName = row.dataset.patientName;
            const patientDob = row.dataset.patientDob;
            const patientGender = row.dataset.patientGender;
            const patientContact = row.dataset.patientContact;
            const patientAddress = row.dataset.patientAddress;
            const patientLead = row.dataset.patientLead;
            const doctorName = row.dataset.doctorName;
            const doctorSpecialty = row.dataset.doctorSpecialty;
            const reason = row.dataset.reason;
            const visitDate = row.dataset.visitDate;
            
            // Populate modal with data
            document.getElementById('visitModalPatientName').textContent = patientName;
            document.getElementById('visitModalPatientDob').textContent = patientDob;
            document.getElementById('visitModalPatientGender').textContent = patientGender;
            document.getElementById('visitModalPatientContact').textContent = patientContact;
            document.getElementById('visitModalPatientAddress').textContent = patientAddress;
            document.getElementById('visitModalPatientLead').textContent = patientLead || 'N/A';
            document.getElementById('visitModalDoctorName').textContent = doctorName + ' (' + doctorSpecialty + ')';
            document.getElementById('visitModalReason').textContent = reason;
            document.getElementById('visitModalVisitDate').textContent = new Date(visitDate).toLocaleString();
            
            // Update action buttons with correct URLs
            document.getElementById('visitModalPrescriptionBtn').href = 'prescription.php?visit_id=' + visitId;
            document.getElementById('visitModalLabBtn').href = 'lab_billing.php?visit_id=' + visitId;
            document.getElementById('visitModalUltrasoundBtn').href = 'ultrasound_billing.php?visit_id=' + visitId;
            document.getElementById('visitModalPharmacyBtn').href = 'pharmacy_billing.php?visit_id=' + visitId;
            
            // Toggle Form F button depending on visit content
            fetch('get_visit_form_f_flag.php?visit_id=' + visitId)
                .then(res => res.json())
                .then(data => {
                    const formFBtn = document.getElementById('visitModalFormFBtn');
                    if (data && data.success && data.requires_form_f) {
                        formFBtn.style.display = 'inline-block';
                    } else {
                        formFBtn.style.display = 'none';
                    }
                })
                .catch(() => {
                    const formFBtn = document.getElementById('visitModalFormFBtn');
                    if (formFBtn) formFBtn.style.display = 'none';
                });
            
            // Set current visit ID for scan functionality
            currentVisitId = visitId;
            
            // Show modal
            const visitModal = new bootstrap.Modal(document.getElementById('visitDetailsModal'));
            visitModal.show();
            
            // Automatically load invoices for this visit
            setTimeout(() => {
                loadVisitInvoices();
            }, 100);
        }
    }
    
    // Global variables for scan functionality
    let currentVisitId = null;
    let scannedFile = null;
    
    // Handle scanned file selection
    window.handleScanFile = function(input) {
        const file = input.files[0];
        if (file) {
            scannedFile = file;
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const previewDiv = document.getElementById('scanPreviewContent');
                const previewSection = document.getElementById('scanPreview');
                
                if (file.type.startsWith('image/')) {
                    // Display image preview
                    previewDiv.innerHTML = `
                        <img src="${e.target.result}" class="img-fluid" style="max-height: 300px;" alt="Scanned Document">
                        <p class="mt-2"><strong>File:</strong> ${file.name} (${(file.size / 1024).toFixed(2)} KB)</p>
                    `;
                } else if (file.type === 'application/pdf') {
                    // Display PDF preview
                    previewDiv.innerHTML = `
                        <embed src="${e.target.result}" type="application/pdf" width="100%" height="300px">
                        <p class="mt-2"><strong>File:</strong> ${file.name} (${(file.size / 1024).toFixed(2)} KB)</p>
                    `;
                }
                
                previewSection.style.display = 'block';
            };
            
            reader.readAsDataURL(file);
        }
    };
    
    // Save scanned document
    window.saveScannedDocument = function() {
        if (!scannedFile || !currentVisitId) {
            alert('No file to save or visit ID not found');
            return;
        }
        
        const formData = new FormData();
        formData.append('scanned_file', scannedFile);
        formData.append('visit_id', currentVisitId);
        formData.append('action', 'save_scan');
        
        fetch('save_scan.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Document saved successfully!');
                clearScanPreview();
                // Refresh the specific action button for this visit
                setTimeout(() => {
                    refreshActionButton(currentVisitId);
                }, 500);
            } else {
                alert('Error saving document: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error saving document. Please try again.');
        });
    };
    
    // Clear scan preview
    window.clearScanPreview = function() {
        document.getElementById('scanPreview').style.display = 'none';
        document.getElementById('scanPreviewContent').innerHTML = '';
        document.getElementById('scanFileInput').value = '';
        scannedFile = null;
    };
    
    // Load existing documents for the visit
    window.loadVisitDocuments = function() {
        if (!currentVisitId) {
            alert('No visit ID found');
            return;
        }
        
        const documentsList = document.getElementById('documentsList');
        const existingDocuments = document.getElementById('existingDocuments');
        
        // Show loading
        documentsList.innerHTML = '<p>Loading documents...</p>';
        existingDocuments.style.display = 'block';
        
        fetch('get_visit_documents.php?visit_id=' + currentVisitId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.documents.length > 0) {
                let html = '<div class="row">';
                data.documents.forEach(doc => {
                    const fileSize = (doc.file_size / 1024).toFixed(2);
                    const uploadDate = new Date(doc.uploaded_at).toLocaleString();
                    
                    html += `
                        <div class="col-md-6 mb-2">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">${doc.original_name}</h6>
                                    <p class="card-text small">
                                        <strong>Type:</strong> ${doc.file_type}<br>
                                        <strong>Size:</strong> ${fileSize} KB<br>
                                        <strong>Uploaded:</strong> ${uploadDate}
                                    </p>
                                    <a href="uploads/scans/${doc.filename}" target="_blank" class="btn btn-primary btn-sm">View</a>
                                    <button class="btn btn-danger btn-sm ms-1" onclick="deleteDocument(${doc.id})">Delete</button>
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                documentsList.innerHTML = html;
            } else {
                documentsList.innerHTML = '<p class="text-muted">No documents found for this visit.</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            documentsList.innerHTML = '<p class="text-danger">Error loading documents.</p>';
        });
    };
    
    // Delete document
    window.deleteDocument = function(documentId) {
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
        }
        
        fetch('delete_document.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                document_id: documentId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Document deleted successfully!');
                loadVisitDocuments(); // Reload the documents list
                // Refresh the specific action button for this visit
                setTimeout(() => {
                    refreshActionButton(currentVisitId);
                }, 500);
            } else {
                alert('Error deleting document: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting document. Please try again.');
        });
    };
    
    // Load visit invoices
    window.loadVisitInvoices = function() {
        if (!currentVisitId) {
            alert('No visit ID found');
            return;
        }
        
        const invoicesList = document.getElementById('invoicesList');
        const visitInvoices = document.getElementById('visitInvoices');
        
        // Show loading
        invoicesList.innerHTML = '<p>Loading invoices...</p>';
        visitInvoices.style.display = 'block';
        
        fetch('get_visit_invoices.php?visit_id=' + currentVisitId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.invoices.length > 0) {
                let html = '<div class="table-responsive"><table class="table table-sm table-bordered">';
                html += '<thead><tr><th>Type</th><th>Invoice #</th><th>Amount</th><th>Payment Mode</th><th>Date</th><th>Action</th></tr></thead><tbody>';
                
                data.invoices.forEach(invoice => {
                    const typeClass = {
                        'consultation': 'table-primary',
                        'lab': 'table-success',
                        'ultrasound': 'table-info',
                        'pharmacy': 'table-warning'
                    }[invoice.type] || 'table-secondary';
                    
                    const typeIcon = {
                        'consultation': '👨‍⚕️',
                        'lab': '🧪',
                        'ultrasound': '🔬',
                        'pharmacy': '💊'
                    }[invoice.type] || '📄';
                    
                    const date = invoice.created_at ? new Date(invoice.created_at).toLocaleString() : 'N/A';
                    const amount = '₹' + parseFloat(invoice.amount || 0).toFixed(2);
                    
                    html += `
                        <tr class="${typeClass}">
                            <td>${typeIcon} ${invoice.type.charAt(0).toUpperCase() + invoice.type.slice(1)}</td>
                            <td><strong>${invoice.invoice_number}</strong></td>
                            <td>${amount}</td>
                            <td>${invoice.payment_mode}</td>
                            <td>${date}</td>
                            <td>
                                <a href="${invoice.print_url}" target="_blank" class="btn btn-primary btn-sm">
                                    <i class="bi bi-printer"></i> Print
                                </a>
                            </td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table></div>';
                invoicesList.innerHTML = html;
            } else {
                invoicesList.innerHTML = '<p class="text-muted">No invoices found for this visit.</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            invoicesList.innerHTML = '<p class="text-danger">Error loading invoices.</p>';
        });
    };
});
</script>

<!-- Visit Details Modal -->
<div class="modal fade" id="visitDetailsModal" tabindex="-1" aria-labelledby="visitDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="visitDetailsModalLabel">Visit Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="fw-bold">Patient Information</h6>
                        <p><strong>Name:</strong> <span id="visitModalPatientName"></span></p>
                        <p><strong>Date of Birth:</strong> <span id="visitModalPatientDob"></span></p>
                        <p><strong>Gender:</strong> <span id="visitModalPatientGender"></span></p>
                        <p><strong>Contact:</strong> <span id="visitModalPatientContact"></span></p>
                        <p><strong>Address:</strong> <span id="visitModalPatientAddress"></span></p>
                        <p><strong>Lead Source:</strong> <span id="visitModalPatientLead"></span></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold">Visit Information</h6>
                        <p><strong>Doctor:</strong> <span id="visitModalDoctorName"></span></p>
                        <p><strong>Reason:</strong> <span id="visitModalReason"></span></p>
                        <p><strong>Visit Date:</strong> <span id="visitModalVisitDate"></span></p>
                    </div>
                </div>
                <hr>
                <div class="text-center">
                    <h6 class="fw-bold mb-3">Actions</h6>
                    <button class="btn btn-outline-secondary btn-sm mb-2" onclick="refreshActionButton(currentVisitId)">
                        <i class="bi bi-arrow-clockwise"></i> Refresh Next Action
                    </button>
                    <br>

                    <a id="visitModalPrescriptionBtn" href="#" class="btn btn-outline-primary btn-lg m-2">
                        <i class="bi bi-printer"></i> Print Prescription
                    </a>
                    <a id="visitModalLabBtn" href="#" class="btn btn-outline-success btn-lg m-2">
                        <i class="bi bi-droplet"></i> Lab Billing
                    </a>
                    <a id="visitModalUltrasoundBtn" href="#" class="btn btn-outline-info btn-lg m-2">
                        <i class="bi bi-activity"></i> Ultrasound Billing
                    </a>
                    <a id="visitModalPharmacyBtn" href="#" class="btn btn-outline-warning btn-lg m-2">
                        <i class="bi bi-capsule"></i> Pharmacy Billing
                    </a>
                    <a id="visitModalFormFBtn" href="Form F.pdf" target="_blank" class="btn btn-outline-danger btn-lg m-2" style="display: none;">
                        <i class="bi bi-file-earmark-pdf"></i> Print Form F
                    </a>
                    <button id="visitModalScanBtn" class="btn btn-outline-info btn-lg m-2" onclick="document.getElementById('scanFileInput').click();">
                        <i class="bi bi-scanner"></i> Scan Document
                    </button>
                    <button id="visitModalViewDocsBtn" class="btn btn-outline-secondary btn-lg m-2" onclick="loadVisitDocuments()">
                        <i class="bi bi-folder"></i> View Documents
                    </button>
                    <input type="file" id="scanFileInput" accept="image/*,.pdf" style="display: none;" onchange="handleScanFile(this)">
                </div>
                <div id="scanPreview" class="mt-3" style="display: none;">
                    <h6 class="fw-bold">Scanned Document Preview</h6>
                    <div class="border rounded p-3">
                        <div id="scanPreviewContent"></div>
                        <div class="mt-2">
                            <button class="btn btn-success btn-sm" onclick="saveScannedDocument()">Save Document</button>
                            <button class="btn btn-secondary btn-sm ms-2" onclick="clearScanPreview()">Clear</button>
                        </div>
                    </div>
                </div>
                <div id="existingDocuments" class="mt-3" style="display: none;">
                    <h6 class="fw-bold">Existing Documents</h6>
                    <div id="documentsList" class="border rounded p-3">
                        <!-- Documents will be loaded here -->
                    </div>
                </div>
                
                <!-- Invoices Section -->
                <div id="visitInvoices" class="mt-3" style="display: none;">
                    <h6 class="fw-bold">Generated Invoices</h6>
                    <div id="invoicesList" class="border rounded p-3">
                        <!-- Invoices will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Simple Client-Side Validation Script -->
<script src="js/simple-validation.js"></script>
</body>
</html> 