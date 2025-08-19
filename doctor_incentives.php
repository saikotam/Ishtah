<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';
session_start();

// Handle payment updates
if (isset($_POST['mark_paid'])) {
    $incentive_id = intval($_POST['incentive_id']);
    $payment_mode = $_POST['payment_mode'];
    $payment_date = $_POST['payment_date'];
    $notes = $_POST['notes'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE doctor_incentives SET paid = 1, payment_date = ?, payment_mode = ?, notes = ? WHERE id = ?");
    if ($stmt->execute([$payment_date, $payment_mode, $notes, $incentive_id])) {
        $success_message = 'Payment marked as paid successfully!';
        log_action('Reception', 'Doctor Incentive Paid', 'Incentive ID: ' . $incentive_id . ', Payment Mode: ' . $payment_mode);
    } else {
        $error_message = 'Failed to update payment status.';
    }
}

// Handle bulk payment updates
if (isset($_POST['bulk_mark_paid']) && isset($_POST['selected_incentives'])) {
    $selected_incentives = $_POST['selected_incentives'];
    $payment_mode = $_POST['bulk_payment_mode'];
    $payment_date = $_POST['bulk_payment_date'];
    $bulk_notes = $_POST['bulk_notes'] ?? '';
    
    $success_count = 0;
    foreach ($selected_incentives as $incentive_id) {
        $stmt = $pdo->prepare("UPDATE doctor_incentives SET paid = 1, payment_date = ?, payment_mode = ?, notes = ? WHERE id = ?");
        if ($stmt->execute([$payment_date, $payment_mode, $bulk_notes, $incentive_id])) {
            $success_count++;
            log_action('Reception', 'Doctor Incentive Paid (Bulk)', 'Incentive ID: ' . $incentive_id . ', Payment Mode: ' . $payment_mode);
        }
    }
    $success_message = $success_count . ' payments marked as paid successfully!';
}

// Handle referring doctor management
if (isset($_POST['add_doctor'])) {
    $name = trim($_POST['name']);
    $specialty = trim($_POST['specialty']);
    $contact_number = trim($_POST['contact_number']);
    $address = trim($_POST['address']);
    $incentive_percentage = floatval($_POST['incentive_percentage']);
    
    if (!empty($name) && $incentive_percentage >= 0) {
        $stmt = $pdo->prepare("INSERT INTO referring_doctors (name, specialty, contact_number, address, incentive_percentage) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $specialty, $contact_number, $address, $incentive_percentage])) {
            $success_message = 'Referring doctor added successfully!';
            log_action('Reception', 'Referring Doctor Added', 'Name: ' . $name . ', Specialty: ' . $specialty);
        } else {
            $error_message = 'Failed to add referring doctor.';
        }
    } else {
        $error_message = 'Please fill in all required fields.';
    }
}

// Handle doctor status updates
if (isset($_POST['toggle_status'])) {
    $doctor_id = intval($_POST['doctor_id']);
    $new_status = $_POST['new_status'] === '1' ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE referring_doctors SET is_active = ? WHERE id = ?");
    if ($stmt->execute([$new_status, $doctor_id])) {
        $success_message = 'Doctor status updated successfully!';
        log_action('Reception', 'Doctor Status Updated', 'Doctor ID: ' . $doctor_id . ', Status: ' . ($new_status ? 'Active' : 'Inactive'));
    } else {
        $error_message = 'Failed to update doctor status.';
    }
}

// Handle export requests
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Export to PDF functionality
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="doctor_incentives_report.pdf"');
    // PDF generation logic would go here
    exit;
}

// Get filter parameters
$doctor_filter = $_GET['doctor_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? 'pending'; // pending, paid, all
$specialty_filter = $_GET['specialty'] ?? '';

// Build query conditions
$where_conditions = [];
$params = [];

if ($doctor_filter) {
    $where_conditions[] = "di.referring_doctor_id = ?";
    $params[] = $doctor_filter;
}

if ($date_from) {
    $where_conditions[] = "ub.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $where_conditions[] = "ub.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if ($status_filter === 'pending') {
    $where_conditions[] = "di.paid = 0";
} elseif ($status_filter === 'paid') {
    $where_conditions[] = "di.paid = 1";
}

if ($specialty_filter) {
    $where_conditions[] = "rd.specialty LIKE ?";
    $params[] = '%' . $specialty_filter . '%';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch filtered incentives
$query = "
    SELECT di.*, rd.name AS doctor_name, rd.specialty, rd.contact_number, 
           ub.invoice_number, ub.discounted_total, ub.created_at AS bill_date,
           p.full_name AS patient_name
    FROM doctor_incentives di
    JOIN referring_doctors rd ON di.referring_doctor_id = rd.id
    JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id
    JOIN visits v ON ub.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    $where_clause
    ORDER BY di.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$filtered_incentives = $stmt->fetchAll();

// Fetch pending incentives (for summary)
// Get filter parameters
$doctor_filter = $_GET['doctor_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? 'pending'; // pending, paid, all
$specialty_filter = $_GET['specialty'] ?? '';

// Build query conditions
$where_conditions = [];
$params = [];

if ($doctor_filter) {
    $where_conditions[] = "di.referring_doctor_id = ?";
    $params[] = $doctor_filter;
}

if ($date_from) {
    $where_conditions[] = "ub.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $where_conditions[] = "ub.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if ($status_filter === 'pending') {
    $where_conditions[] = "di.paid = 0";
} elseif ($status_filter === 'paid') {
    $where_conditions[] = "di.paid = 1";
}

if ($specialty_filter) {
    $where_conditions[] = "rd.specialty LIKE ?";
    $params[] = '%' . $specialty_filter . '%';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch filtered incentives
$query = "
    SELECT di.*, rd.name AS doctor_name, rd.specialty, rd.contact_number, 
           ub.invoice_number, ub.discounted_total, ub.created_at AS bill_date,
           p.full_name AS patient_name
    FROM doctor_incentives di
    JOIN referring_doctors rd ON di.referring_doctor_id = rd.id
    JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id
    JOIN visits v ON ub.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    $where_clause
    ORDER BY di.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$filtered_incentives = $stmt->fetchAll();

// Fetch pending incentives (for summary)
$pending_incentives = $pdo->query("
    SELECT di.*, rd.name AS doctor_name, rd.specialty, rd.contact_number, 
           ub.invoice_number, ub.discounted_total, ub.created_at AS bill_date,
           p.full_name AS patient_name
    FROM doctor_incentives di
    JOIN referring_doctors rd ON di.referring_doctor_id = rd.id
    JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id
    JOIN visits v ON ub.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    WHERE di.paid = 0
    ORDER BY di.created_at DESC
")->fetchAll();

// Fetch paid incentives (for summary)
$paid_incentives = $pdo->query("
    SELECT di.*, rd.name AS doctor_name, rd.specialty, rd.contact_number, 
           ub.invoice_number, ub.discounted_total, ub.created_at AS bill_date,
           p.full_name AS patient_name
    FROM doctor_incentives di
    JOIN referring_doctors rd ON di.referring_doctor_id = rd.id
    JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id
    JOIN visits v ON ub.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    WHERE di.paid = 1
    ORDER BY di.payment_date DESC
    LIMIT 50
")->fetchAll();

// Fetch referring doctors
$referring_doctors = $pdo->query("SELECT * FROM referring_doctors ORDER BY name")->fetchAll();

// Calculate totals
$total_pending = array_sum(array_column($pending_incentives, 'incentive_amount'));
$total_paid = array_sum(array_column($paid_incentives, 'incentive_amount'));

// Analytics data
$monthly_data = $pdo->query("
    SELECT 
        DATE_FORMAT(ub.created_at, '%Y-%m') as month,
        COUNT(*) as total_bills,
        SUM(di.incentive_amount) as total_incentives,
        SUM(CASE WHEN di.paid = 1 THEN di.incentive_amount ELSE 0 END) as paid_incentives,
        SUM(CASE WHEN di.paid = 0 THEN di.incentive_amount ELSE 0 END) as pending_incentives
    FROM doctor_incentives di
    JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id
    WHERE ub.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(ub.created_at, '%Y-%m')
    ORDER BY month DESC
")->fetchAll();

// Doctor performance data
$doctor_performance = $pdo->query("
    SELECT 
        rd.name,
        rd.specialty,
        COUNT(di.id) as total_referrals,
        SUM(di.incentive_amount) as total_incentives,
        SUM(CASE WHEN di.paid = 1 THEN di.incentive_amount ELSE 0 END) as paid_incentives,
        SUM(CASE WHEN di.paid = 0 THEN di.incentive_amount ELSE 0 END) as pending_incentives,
        AVG(di.incentive_amount) as avg_incentive
    FROM referring_doctors rd
    LEFT JOIN doctor_incentives di ON rd.id = di.referring_doctor_id
    GROUP BY rd.id, rd.name, rd.specialty
    ORDER BY total_incentives DESC
")->fetchAll();

// Specialty analysis
$specialty_analysis = $pdo->query("
    SELECT 
        rd.specialty,
        COUNT(di.id) as total_referrals,
        SUM(di.incentive_amount) as total_incentives,
        AVG(di.incentive_amount) as avg_incentive
    FROM referring_doctors rd
    LEFT JOIN doctor_incentives di ON rd.id = di.referring_doctor_id
    WHERE rd.specialty IS NOT NULL AND rd.specialty != ''
    GROUP BY rd.specialty
    ORDER BY total_incentives DESC
")->fetchAll();

// Analytics data
$monthly_data = $pdo->query("
    SELECT 
        DATE_FORMAT(ub.created_at, '%Y-%m') as month,
        COUNT(*) as total_bills,
        SUM(di.incentive_amount) as total_incentives,
        SUM(CASE WHEN di.paid = 1 THEN di.incentive_amount ELSE 0 END) as paid_incentives,
        SUM(CASE WHEN di.paid = 0 THEN di.incentive_amount ELSE 0 END) as pending_incentives
    FROM doctor_incentives di
    JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id
    WHERE ub.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(ub.created_at, '%Y-%m')
    ORDER BY month DESC
")->fetchAll();

// Doctor performance data
$doctor_performance = $pdo->query("
    SELECT 
        rd.name,
        rd.specialty,
        COUNT(di.id) as total_referrals,
        SUM(di.incentive_amount) as total_incentives,
        SUM(CASE WHEN di.paid = 1 THEN di.incentive_amount ELSE 0 END) as paid_incentives,
        SUM(CASE WHEN di.paid = 0 THEN di.incentive_amount ELSE 0 END) as pending_incentives,
        AVG(di.incentive_amount) as avg_incentive
    FROM referring_doctors rd
    LEFT JOIN doctor_incentives di ON rd.id = di.referring_doctor_id
    GROUP BY rd.id, rd.name, rd.specialty
    ORDER BY total_incentives DESC
")->fetchAll();

// Specialty analysis
$specialty_analysis = $pdo->query("
    SELECT 
        rd.specialty,
        COUNT(di.id) as total_referrals,
        SUM(di.incentive_amount) as total_incentives,
        AVG(di.incentive_amount) as avg_incentive
    FROM referring_doctors rd
    LEFT JOIN doctor_incentives di ON rd.id = di.referring_doctor_id
    WHERE rd.specialty IS NOT NULL AND rd.specialty != ''
    GROUP BY rd.specialty
    ORDER BY total_incentives DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Incentives - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        .filter-section { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .analytics-card { transition: transform 0.2s; }
        .analytics-card:hover { transform: translateY(-2px); }
        .performance-table th { background-color: #f8f9fa; }
        .export-buttons { margin-bottom: 15px; }
        @media print {
            .no-print, .card-header, .btn, .modal, .filter-section { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            .card-body { padding: 0 !important; }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2><i class="bi bi-graph-up"></i> Doctor Incentives Management & Analytics</h2>
                <div>
                    <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Back to Home</a>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
            <?php endif; ?>
            
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-clock"></i> Pending Incentives</h5>
                            <h3>₹<?= number_format($total_pending, 2) ?></h3>
                            <p class="mb-0"><?= count($pending_incentives) ?> pending payments</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-check-circle"></i> Paid Incentives</h5>
                            <h3>₹<?= number_format($total_paid, 2) ?></h3>
                            <p class="mb-0"><?= count($paid_incentives) ?> payments made</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-people"></i> Total Doctors</h5>
                            <h3><?= count($referring_doctors) ?></h3>
                            <p class="mb-0">Referring doctors registered</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-graph-up"></i> Total Incentives</h5>
                            <h3>₹<?= number_format($total_pending + $total_paid, 2) ?></h3>
                            <p class="mb-0">All time total</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Simple Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> Filter Incentives</h5>
                </div>
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Doctor</label>
                            <select name="doctor_id" class="form-select">
                                <option value="">All Doctors</option>
                                <?php foreach ($referring_doctors as $doctor): ?>
                                <option value="<?= $doctor['id'] ?>" <?= $doctor_filter == $doctor['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($doctor['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                            <a href="doctor_incentives.php" class="btn btn-secondary">Clear Filters</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Export Options -->
            <div class="mb-3">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-success" onclick="exportToPDF()">
                                <i class="bi bi-file-pdf"></i> Export to PDF
                            </button>
                            <button type="button" class="btn btn-warning" onclick="printReport()">
                                <i class="bi bi-printer"></i> Print Report
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="includeDoctorName" checked>
                            <label class="form-check-label" for="includeDoctorName">
                                <i class="bi bi-person"></i> Include Doctor Name in Print
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtered Results -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Incentives (<?= count($filtered_incentives) ?> records)</h5>
                    <div>
                        <button type="button" class="btn btn-success btn-sm" onclick="selectAll()">
                            <i class="bi bi-check-all"></i> Select All
                        </button>
                        <button type="button" class="btn btn-warning btn-sm" onclick="bulkPaymentModal()">
                            <i class="bi bi-cash-stack"></i> Bulk Payment
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($filtered_incentives)): ?>
                    <p class="text-muted">No incentives found matching the criteria.</p>
                    <?php else: ?>
                    <form method="post" id="bulkPaymentForm">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllCheckbox"></th>
                                        <th>Date</th>
                                        <th>Doctor</th>
                                        <th>Patient</th>
                                        <th>Invoice</th>
                                        <th>Bill Amount</th>
                                        <th>Incentive %</th>
                                        <th>Incentive Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filtered_incentives as $incentive): ?>
                                    <tr>
                                        <td>
                                            <?php if ($incentive['paid'] == 0): ?>
                                            <input type="checkbox" name="selected_incentives[]" value="<?= $incentive['id'] ?>" class="incentive-checkbox">
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($incentive['bill_date'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($incentive['doctor_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($incentive['specialty']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($incentive['patient_name']) ?></td>
                                        <td><?= htmlspecialchars($incentive['invoice_number']) ?></td>
                                        <td>₹<?= number_format($incentive['bill_amount'], 2) ?></td>
                                        <td><?= $incentive['incentive_percentage'] ?>%</td>
                                        <td><strong>₹<?= number_format($incentive['incentive_amount'], 2) ?></strong></td>
                                        <td>
                                            <?php if ($incentive['paid'] == 1): ?>
                                            <span class="badge bg-success">Paid</span>
                                            <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($incentive['paid'] == 0): ?>
                                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#paymentModal" 
                                                    data-incentive-id="<?= $incentive['id'] ?>" 
                                                    data-doctor-name="<?= htmlspecialchars($incentive['doctor_name']) ?>"
                                                    data-amount="<?= $incentive['incentive_amount'] ?>">
                                                <i class="bi bi-cash"></i> Mark Paid
                                            </button>
                                            <?php else: ?>
                                            <small class="text-muted">Paid on <?= date('d/m/Y', strtotime($incentive['payment_date'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add Referring Doctor -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person-plus"></i> Add New Referring Doctor</h5>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Doctor Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Specialty</label>
                            <input type="text" name="specialty" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Incentive % *</label>
                            <input type="number" name="incentive_percentage" class="form-control" step="0.01" min="0" max="100" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="add_doctor" class="btn btn-primary d-block">
                                <i class="bi bi-plus-circle"></i> Add Doctor
                            </button>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Referring Doctors List -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-people"></i> Referring Doctors</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Specialty</th>
                                    <th>Contact</th>
                                    <th>Incentive %</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referring_doctors as $doctor): ?>
                                <tr>
                                    <td><?= htmlspecialchars($doctor['name']) ?></td>
                                    <td><?= htmlspecialchars($doctor['specialty']) ?></td>
                                    <td><?= htmlspecialchars($doctor['contact_number']) ?></td>
                                    <td><?= $doctor['incentive_percentage'] ?>%</td>
                                    <td>
                                        <span class="badge bg-<?= $doctor['is_active'] ? 'success' : 'danger' ?>">
                                            <?= $doctor['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="doctor_id" value="<?= $doctor['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $doctor['is_active'] ? '0' : '1' ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-sm btn-<?= $doctor['is_active'] ? 'warning' : 'success' ?>">
                                                <?= $doctor['is_active'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash"></i> Mark Payment as Paid</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="incentive_id" id="modalIncentiveId">
                    <div class="mb-3">
                        <label class="form-label">Doctor: <span id="modalDoctorName"></span></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount: ₹<span id="modalAmount"></span></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date *</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Mode *</label>
                        <select name="payment_mode" class="form-select" required>
                            <option value="">Select Payment Mode</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="UPI">UPI</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="mark_paid" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Payment Modal -->
<div class="modal fade" id="bulkPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack"></i> Bulk Payment Processing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Selected Incentives: <span id="bulkSelectedCount">0</span></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total Amount: ₹<span id="bulkTotalAmount">0.00</span></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date *</label>
                        <input type="date" name="bulk_payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Mode *</label>
                        <select name="bulk_payment_mode" class="form-select" required>
                            <option value="">Select Payment Mode</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="UPI">UPI</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="bulk_notes" class="form-control" rows="3" placeholder="Notes for all selected payments..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="bulk_mark_paid" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Process Bulk Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle payment modal
    const paymentModal = document.getElementById('paymentModal');
    if (paymentModal) {
        paymentModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const incentiveId = button.getAttribute('data-incentive-id');
            const doctorName = button.getAttribute('data-doctor-name');
            const amount = button.getAttribute('data-amount');
            
            document.getElementById('modalIncentiveId').value = incentiveId;
            document.getElementById('modalDoctorName').textContent = doctorName;
            document.getElementById('modalAmount').textContent = amount;
        });
    }

    // Handle select all checkbox
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const incentiveCheckboxes = document.querySelectorAll('.incentive-checkbox');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            incentiveCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkPaymentInfo();
        });
    }

    // Handle individual checkboxes
    incentiveCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkPaymentInfo);
    });


});

function selectAll() {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    selectAllCheckbox.checked = !selectAllCheckbox.checked;
    selectAllCheckbox.dispatchEvent(new Event('change'));
}

function updateBulkPaymentInfo() {
    const selectedCheckboxes = document.querySelectorAll('.incentive-checkbox:checked');
    const selectedCount = selectedCheckboxes.length;
    let totalAmount = 0;
    
    selectedCheckboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        const amountCell = row.querySelector('td:nth-child(8) strong');
        if (amountCell) {
            const amountText = amountCell.textContent.replace('₹', '').replace(',', '');
            totalAmount += parseFloat(amountText);
        }
    });
    
    document.getElementById('bulkSelectedCount').textContent = selectedCount;
    document.getElementById('bulkTotalAmount').textContent = totalAmount.toFixed(2);
}

function bulkPaymentModal() {
    const selectedCheckboxes = document.querySelectorAll('.incentive-checkbox:checked');
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one incentive for bulk payment.');
        return;
    }
    
    updateBulkPaymentInfo();
    const modal = new bootstrap.Modal(document.getElementById('bulkPaymentModal'));
    modal.show();
}

function exportToPDF() {
    // Get current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    const exportUrl = 'export_incentives_pdf.php?' + urlParams.toString();
    window.open(exportUrl, '_blank');
}

function exportToExcel() {
    // Get current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    const exportUrl = 'export_incentives_pdf.php?' + urlParams.toString() + '&format=excel';
    window.open(exportUrl, '_blank');
}

function printReport() {
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    
    // Find the incentives table - try multiple selectors
    let table = null;
    
    // Method 1: Look for table within table-responsive
    const tableResponsive = document.querySelector('.table-responsive');
    if (tableResponsive) {
        table = tableResponsive.querySelector('table');
    }
    
    // Method 2: Look for any table with thead (data table)
    if (!table) {
        const tables = document.querySelectorAll('table');
        for (let t of tables) {
            if (t.querySelector('thead')) {
                table = t;
                break;
            }
        }
    }
    
    // Method 3: Look for table with incentive data (has tbody with rows)
    if (!table) {
        const tables = document.querySelectorAll('table');
        for (let t of tables) {
            const tbody = t.querySelector('tbody');
            if (tbody && tbody.querySelectorAll('tr').length > 0) {
                table = t;
                break;
            }
        }
    }
    
    console.log('Table found:', table);
    console.log('Table HTML:', table ? table.outerHTML : 'No table');
    
    if (!table) {
        alert('No incentives table found to print.');
        return;
    }
    
    // Get the table content and clean it for printing
    const tableContent = cleanTableForPrint(table.outerHTML);
    
    // Check if doctor name should be included
    const includeDoctorName = document.getElementById('includeDoctorName').checked;
    
    // Create print-friendly HTML
    const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Doctor Incentives Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
                .filter-info { margin-bottom: 15px; padding: 10px; background-color: #f8f9fa; border-radius: 5px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .paid { color: green; }
                .pending { color: orange; }
                .footer { margin-top: 20px; text-align: center; font-size: 12px; color: #666; }
                .hide-doctor { display: none !important; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Doctor Incentives Report</h2>
                <p>Generated on: ${new Date().toLocaleString()}</p>
            </div>
            
            <div class="filter-info">
                <strong>Filter Criteria:</strong><br>
                ${getFilterSummary()}
            </div>
            
            ${includeDoctorName ? tableContent : removeDoctorColumn(tableContent)}
            
            <div class="footer">
                <p>Report generated by Ishtah Clinic Management System</p>
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(printHTML);
    printWindow.document.close();
    printWindow.focus();
    
    // Wait for content to load then print
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 500);
}

function getFilterSummary() {
    const urlParams = new URLSearchParams(window.location.search);
    const filters = [];
    
    if (urlParams.get('doctor_id')) {
        const doctorSelect = document.querySelector('select[name="doctor_id"]');
        const selectedOption = doctorSelect.options[doctorSelect.selectedIndex];
        filters.push(`Doctor: ${selectedOption.text}`);
    }
    
    if (urlParams.get('specialty')) {
        filters.push(`Specialty: ${urlParams.get('specialty')}`);
    }
    
    if (urlParams.get('date_from')) {
        filters.push(`From: ${urlParams.get('date_from')}`);
    }
    
    if (urlParams.get('date_to')) {
        filters.push(`To: ${urlParams.get('date_to')}`);
    }
    
    if (urlParams.get('status')) {
        const status = urlParams.get('status');
        filters.push(`Status: ${status.charAt(0).toUpperCase() + status.slice(1)}`);
    }
    
    return filters.length > 0 ? filters.join(' | ') : 'All records';
}

function removeDoctorColumn(htmlContent) {
    // Create a temporary div to parse the HTML
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlContent;
    
    // Find the table
    const table = tempDiv.querySelector('table');
    if (!table) return htmlContent;
    
    // Remove the doctor column (3rd column - index 2) from header
    const headerRow = table.querySelector('thead tr');
    if (headerRow) {
        const headerCells = headerRow.querySelectorAll('th');
        if (headerCells.length > 2) {
            headerCells[2].remove(); // Remove doctor column
        }
    }
    
    // Remove the doctor column from all data rows
    const dataRows = table.querySelectorAll('tbody tr');
    dataRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 2) {
            cells[2].remove(); // Remove doctor column
        }
    });
    
    return tempDiv.innerHTML;
}

function cleanTableForPrint(htmlContent) {
    // Create a temporary div to parse the HTML
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlContent;
    
    // Find the table
    const table = tempDiv.querySelector('table');
    if (!table) return htmlContent;
    
    // Remove action buttons and checkboxes from all cells
    const allCells = table.querySelectorAll('td, th');
    allCells.forEach(cell => {
        // Remove buttons
        const buttons = cell.querySelectorAll('button, .btn');
        buttons.forEach(btn => btn.remove());
        
        // Remove checkboxes
        const checkboxes = cell.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => cb.remove());
        
        // Remove any remaining form elements
        const forms = cell.querySelectorAll('form');
        forms.forEach(form => form.remove());
    });
    
    // Remove the entire Actions column (last column)
    const headerRow = table.querySelector('thead tr');
    if (headerRow) {
        const headerCells = headerRow.querySelectorAll('th');
        if (headerCells.length > 0) {
            headerCells[headerCells.length - 1].remove(); // Remove last column (Actions)
        }
    }
    
    // Remove the Actions column from all data rows
    const dataRows = table.querySelectorAll('tbody tr');
    dataRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            cells[cells.length - 1].remove(); // Remove last column (Actions)
        }
    });
    
    return tempDiv.innerHTML;
}


</script>
</body>
</html>
