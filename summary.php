<?php
// summary.php - Clinic Summary Page
require_once 'includes/db.php';

// Helper: Get date range based on type and date
function get_date_range($type, $date) {
    $start = $end = '';
    if ($type === 'weekly') {
        $start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $end = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    } elseif ($type === 'monthly') {
        $start = date('Y-m-01', strtotime($date));
        $end = date('Y-m-t', strtotime($date));
    } else { // daily
        $start = $end = $date;
    }
    return [$start, $end];
}

// Handle reset button
if (isset($_GET['reset'])) {
    header('Location: summary.php?type=daily&date=' . date('Y-m-d'));
    exit;
}

$type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
list($start_date, $end_date) = get_date_range($type, $date);

// PHARMACY SUMMARY
$pharmacy_summary = [12 => ['total' => 0, 'gst' => 0, 'discount' => 0], 18 => ['total' => 0, 'gst' => 0, 'discount' => 0]];
$pharmacy_totals = [
    'total_sales' => 0,
    'total_gst' => 0,
    'total_discount' => 0,
];
$sql = "SELECT pb.id AS bill_id, pb.invoice_number, pb.discount_type, pb.discount_value, pb.discounted_total, pb.total_amount, pb.gst_amount, pb.created_at,
               pbi.gst_percent, SUM(pbi.price) AS category_total
        FROM pharmacy_bills pb
        JOIN pharmacy_bill_items pbi ON pb.id = pbi.bill_id
        WHERE DATE(pb.created_at) BETWEEN ? AND ?
        GROUP BY pb.id, pbi.gst_percent";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$bill_discounts = [];
$pharmacy_bill_ids = [];
$pharmacy_bill_info = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $gst = floatval($row['gst_percent']);
    $cat_total = floatval($row['category_total']);
    $pharmacy_summary[$gst]['total'] += $cat_total;
    // Calculate GST for this category
    $cat_gst = $cat_total * $gst / (100 + $gst);
    $pharmacy_summary[$gst]['gst'] += $cat_gst;
    // Distribute bill-level discount proportionally to category
    $bill_id = $row['bill_id'];
    if (!isset($bill_discounts[$bill_id])) {
        // Calculate bill-level discount
        $discount = 0;
        if ($row['discount_type'] === 'percent' && $row['discount_value'] > 0) {
            $discount = $row['total_amount'] * $row['discount_value'] / 100;
        } elseif ($row['discount_type'] === 'rupees' && $row['discount_value'] > 0) {
            $discount = $row['discount_value'];
        }
        $bill_discounts[$bill_id] = [
            'discount' => $discount,
            'total' => $row['total_amount']
        ];
        // For bill listing
        $pharmacy_bill_ids[$bill_id] = true;
        $pharmacy_bill_info[$bill_id] = [
            'invoice_number' => $row['invoice_number'],
            'date' => $row['created_at'],
            'total' => $row['discounted_total'] ?? $row['total_amount'],
        ];
    }
    // Proportional discount for this category
    $cat_discount = 0;
    if ($bill_discounts[$bill_id]['total'] > 0) {
        $cat_discount = $bill_discounts[$bill_id]['discount'] * ($cat_total / $bill_discounts[$bill_id]['total']);
    }
    $pharmacy_summary[$gst]['discount'] += $cat_discount;
    $pharmacy_totals['total_sales'] += $cat_total;
    $pharmacy_totals['total_gst'] += $cat_gst;
    $pharmacy_totals['total_discount'] += $cat_discount;
}
// Sort bills by date desc
usort($pharmacy_bill_info, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });

// Get pharmacy bills list for the period (separate query)
$sql = "SELECT id AS bill_id, invoice_number, discounted_total, total_amount, created_at, visit_id 
        FROM pharmacy_bills 
        WHERE DATE(created_at) BETWEEN ? AND ? 
        ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$pharmacy_bill_list = [];



while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pharmacy_bill_list[] = [
        'invoice_number' => $row['invoice_number'],
        'date' => $row['created_at'],
        'total' => $row['discounted_total'] ?? $row['total_amount'],
        'visit_id' => $row['visit_id']
    ];
}

// LAB SUMMARY
$lab_summary = [];
$lab_totals = [
    'total_amount' => 0,
    'total_discount' => 0,
    'test_counts' => [],
];
// Test-wise summary (correct grouping)
$sql = "SELECT lbi.test_id, lt.test_name, COUNT(lbi.id) AS test_count, SUM(lbi.amount) AS test_total, SUM(lb.discount_value) AS test_discount
        FROM lab_bill_items lbi
        JOIN lab_bills lb ON lbi.bill_id = lb.id
        JOIN lab_tests lt ON lbi.test_id = lt.id
        WHERE DATE(lb.created_at) BETWEEN ? AND ?
        GROUP BY lbi.test_id, lt.test_name";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $lab_summary[] = [
        'test_name' => $row['test_name'],
        'test_count' => $row['test_count'],
        'test_total' => $row['test_total'],
        'test_discount' => $row['test_discount'],
    ];
    $lab_totals['total_amount'] += floatval($row['test_total']);
    $lab_totals['total_discount'] += floatval($row['test_discount']);
    $lab_totals['test_counts'][$row['test_name']] = $row['test_count'];
}
 // Bill list for the period (separate query)
 $sql = "SELECT id AS bill_id, invoice_number, discounted_amount, amount, created_at, visit_id FROM lab_bills WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC";
 $stmt = $pdo->prepare($sql);
 $stmt->execute([$start_date, $end_date]);
 $lab_bill_info = [];
 while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
     $lab_bill_info[] = [
         'invoice_number' => $row['invoice_number'],
         'date' => $row['created_at'],
         'total' => $row['discounted_amount'] ?? $row['amount'],
         'visit_id' => $row['visit_id']
     ];
 }
// Sort bills by date desc
usort($lab_bill_info, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });

// DOCTOR VISITS SUMMARY
$doctor_visits = [];
$total_visits = 0;
$total_consultation_fees = 0;

$sql = "SELECT d.id, d.name, d.specialty, d.fees, COUNT(v.id) AS visit_count, 
               SUM(COALESCE(ci.amount, d.fees)) AS total_fees
        FROM doctors d
        LEFT JOIN visits v ON d.id = v.doctor_id AND DATE(v.visit_date) BETWEEN ? AND ?
        LEFT JOIN consultation_invoices ci ON v.id = ci.visit_id
        GROUP BY d.id, d.name, d.specialty, d.fees
        HAVING visit_count > 0
        ORDER BY visit_count DESC, d.name";

$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $doctor_visits[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'specialty' => $row['specialty'],
        'fees' => $row['fees'],
        'visit_count' => $row['visit_count'],
        'total_fees' => $row['total_fees']
    ];
    $total_visits += $row['visit_count'];
    $total_consultation_fees += $row['total_fees'];
}

// Get detailed patient visits for each doctor
$doctor_patient_details = [];
foreach ($doctor_visits as $doctor) {
    $sql = "SELECT v.id AS visit_id, v.visit_date, v.reason, v.referred_by,
                   p.id AS patient_id, p.full_name, p.gender, p.dob, p.contact_number,
                   ci.amount AS consultation_fee, ci.mode AS payment_mode
            FROM visits v
            JOIN patients p ON v.patient_id = p.id
            LEFT JOIN consultation_invoices ci ON v.id = ci.visit_id
            WHERE v.doctor_id = ? AND DATE(v.visit_date) BETWEEN ? AND ?
            ORDER BY v.visit_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$doctor['id'], $start_date, $end_date]);
    
    $doctor_patient_details[$doctor['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Also get overall visit statistics
$overall_stats = [];
$sql = "SELECT COUNT(DISTINCT v.id) AS total_visits,
               COUNT(DISTINCT v.patient_id) AS unique_patients,
               SUM(COALESCE(ci.amount, d.fees)) AS total_consultation_fees
        FROM visits v
        JOIN doctors d ON v.doctor_id = d.id
        LEFT JOIN consultation_invoices ci ON v.id = ci.visit_id
        WHERE DATE(v.visit_date) BETWEEN ? AND ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$overall_stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Ishtah Clinic</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link" href="index.php">Home</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
<div class="container">
    <h1 class="mb-4">
        <i class="bi bi-graph-up"></i> Summary Report
        <small class="text-muted"><?= ucfirst($type) ?> Report for <?= $type === 'daily' ? date('F j, Y', strtotime($date)) : ($type === 'weekly' ? 'Week of ' . date('F j, Y', strtotime($date)) : date('F Y', strtotime($date))) ?></small>
    </h1>
    
         <!-- Summary Cards -->
     <div class="row mb-4">
         <div class="col-md-2">
             <div class="card border-primary">
                 <div class="card-body text-center">
                     <i class="bi bi-people-fill text-primary" style="font-size: 2rem;"></i>
                     <h5 class="card-title mt-2">Total Visits</h5>
                     <h3 class="text-primary"><?= number_format($overall_stats['total_visits'] ?? 0) ?></h3>
                 </div>
             </div>
         </div>
         <div class="col-md-2">
             <div class="card border-success">
                 <div class="card-body text-center">
                     <i class="bi bi-person-check-fill text-success" style="font-size: 2rem;"></i>
                     <h5 class="card-title mt-2">Unique Patients</h5>
                     <h3 class="text-success"><?= number_format($overall_stats['unique_patients'] ?? 0) ?></h3>
                 </div>
             </div>
         </div>
         <div class="col-md-2">
             <div class="card border-info">
                 <div class="card-body text-center">
                     <i class="bi bi-cash-stack text-info" style="font-size: 2rem;"></i>
                     <h5 class="card-title mt-2">Consultation Fees</h5>
                     <h3 class="text-info">₹<?= number_format($overall_stats['total_consultation_fees'] ?? 0, 2) ?></h3>
                 </div>
             </div>
         </div>
         <div class="col-md-2">
             <div class="card border-warning">
                 <div class="card-body text-center">
                     <i class="bi bi-capsule text-warning" style="font-size: 2rem;"></i>
                     <h5 class="card-title mt-2">Pharmacy Sales</h5>
                     <h3 class="text-warning">₹<?= number_format($pharmacy_totals['total_sales'], 2) ?></h3>
                 </div>
             </div>
         </div>
         <div class="col-md-2">
             <div class="card border-danger">
                 <div class="card-body text-center">
                     <i class="bi bi-droplet text-danger" style="font-size: 2rem;"></i>
                     <h5 class="card-title mt-2">Lab Revenue</h5>
                     <h3 class="text-danger">₹<?= number_format($lab_totals['total_amount'], 2) ?></h3>
                 </div>
             </div>
         </div>
         <div class="col-md-2">
             <div class="card border-dark">
                 <div class="card-body text-center">
                     <i class="bi bi-receipt text-dark" style="font-size: 2rem;"></i>
                     <h5 class="card-title mt-2">Total Revenue</h5>
                     <h3 class="text-dark">₹<?= number_format(($overall_stats['total_consultation_fees'] ?? 0) + $pharmacy_totals['total_sales'] + $lab_totals['total_amount'], 2) ?></h3>
                 </div>
             </div>
         </div>
     </div>
    
    <form class="row g-3 mb-4" method="get" id="summaryForm">
        <div class="col-md-3">
            <label for="summaryType" class="form-label">Summary Type</label>
            <select class="form-select" id="summaryType" name="type">
                <option value="daily"<?= $type==='daily'?' selected':''; ?>>Daily</option>
                <option value="weekly"<?= $type==='weekly'?' selected':''; ?>>Weekly</option>
                <option value="monthly"<?= $type==='monthly'?' selected':''; ?>>Monthly</option>
            </select>
        </div>
        <div class="col-md-3" id="dateInputCol">
            <label for="date" class="form-label" id="dateLabel">
                <?php if ($type==='weekly'): ?>Select Week<?php elseif ($type==='monthly'): ?>Select Month<?php else: ?>Select Date<?php endif; ?>
            </label>
            <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($date) ?>">
        </div>
        <div class="col-md-3 align-self-end d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i> Show Summary
            </button>
            <button type="submit" name="reset" value="1" class="btn btn-secondary">
                <i class="bi bi-arrow-clockwise"></i> Reset
            </button>
        </div>
    </form>
    
    <?php if ($type !== 'daily'): ?>
    <div class="alert alert-info">
        <i class="bi bi-calendar-range"></i> 
        <strong>Date Range:</strong> <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>
    </div>
    <?php endif; ?>
    <div class="mb-5">
        <h2><i class="bi bi-capsule"></i> Pharmacy</h2>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Category (GST %)</th>
                    <th>Total Sales</th>
                    <th>Total GST</th>
                    <th>Discounts</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>12%</td>
                    <td><?= number_format($pharmacy_summary[12]['total'],2) ?></td>
                    <td><?= number_format($pharmacy_summary[12]['gst'],2) ?></td>
                    <td><?= number_format($pharmacy_summary[12]['discount'],2) ?></td>
                </tr>
                <tr>
                    <td>18%</td>
                    <td><?= number_format($pharmacy_summary[18]['total'],2) ?></td>
                    <td><?= number_format($pharmacy_summary[18]['gst'],2) ?></td>
                    <td><?= number_format($pharmacy_summary[18]['discount'],2) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th><?= number_format($pharmacy_totals['total_sales'],2) ?></th>
                    <th><?= number_format($pharmacy_totals['total_gst'],2) ?></th>
                    <th><?= number_format($pharmacy_totals['total_discount'],2) ?></th>
                </tr>
            </tfoot>
        </table>

        
        <?php if (!empty($pharmacy_bill_list)): ?>
        <div class="mb-3">
            <h6>Bills in this summary:</h6>
            <table class="table table-sm table-striped">
                <thead><tr><th>Invoice Number</th><th>Date</th><th>Total Amount</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($pharmacy_bill_list as $bill): ?>
                    <tr>
                        <td><?= htmlspecialchars($bill['invoice_number']) ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($bill['date'])) ?></td>
                        <td>₹<?= number_format($bill['total'],2) ?></td>
                        <td>
                            <?php if ($bill['visit_id']): ?>
                            <a href="pharmacy_billing.php?visit_id=<?= $bill['visit_id'] ?>&invoice_number=<?= urlencode($bill['invoice_number']) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                <i class="bi bi-eye"></i> View
                            </a>
                            <?php else: ?>
                            <span class="text-muted">No visit link</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No pharmacy bills found for the selected time period.
        </div>
        

        <?php endif; ?>
    </div>
    <div class="mb-5">
        <h2><i class="bi bi-droplet"></i> Lab</h2>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Test Name</th>
                    <th>Test Count</th>
                    <th>Total Amount</th>
                    <th>Discounts</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lab_summary as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['test_name']) ?></td>
                    <td><?= $row['test_count'] ?></td>
                    <td><?= number_format($row['test_total'],2) ?></td>
                    <td><?= number_format($row['test_discount'],2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th><?= array_sum($lab_totals['test_counts']) ?></th>
                    <th><?= number_format($lab_totals['total_amount'],2) ?></th>
                    <th><?= number_format($lab_totals['total_discount'],2) ?></th>
                </tr>
            </tfoot>
        </table>
        <?php if (!empty($lab_bill_info)): ?>
        <div class="mb-3">
            <h6>Bills in this summary:</h6>
            <table class="table table-sm table-striped">
                <thead><tr><th>Invoice Number</th><th>Date</th><th>Total Amount</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($lab_bill_info as $bill): ?>
                    <tr>
                        <td><?= htmlspecialchars($bill['invoice_number']) ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($bill['date'])) ?></td>
                        <td>₹<?= number_format($bill['total'],2) ?></td>
                                                 <td>
                             <a href="lab_billing.php?visit_id=<?= $bill['visit_id'] ?? '' ?>&invoice_number=<?= urlencode($bill['invoice_number']) ?>" class="btn btn-sm btn-outline-success" target="_blank">
                                 <i class="bi bi-eye"></i> View
                             </a>
                         </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No lab bills found for the selected time period.
        </div>
        <?php endif; ?>
    </div>
    
    <div class="mb-5">
        <h2><i class="bi bi-stethoscope"></i> Doctor Visits</h2>
        
                 <?php if (!empty($doctor_visits)): ?>
         <table class="table table-bordered table-striped">
             <thead class="table-dark">
                 <tr>
                     <th>Rank</th>
                     <th>Doctor Name</th>
                     <th>Specialty</th>
                     <th>Consultation Fee</th>
                     <th>Number of Visits</th>
                     <th>Total Fees Collected</th>
                     <th>Average per Visit</th>
                     <th>Actions</th>
                 </tr>
             </thead>
             <tbody>
                 <?php foreach ($doctor_visits as $index => $doctor): ?>
                 <tr>
                     <td class="text-center">
                         <span class="badge bg-<?= $index < 3 ? 'warning' : 'secondary' ?>"><?= $index + 1 ?></span>
                     </td>
                     <td><strong><?= htmlspecialchars($doctor['name']) ?></strong></td>
                     <td><?= htmlspecialchars($doctor['specialty']) ?></td>
                     <td>₹<?= number_format($doctor['fees'], 2) ?></td>
                     <td class="text-center">
                         <span class="badge bg-primary"><?= $doctor['visit_count'] ?></span>
                     </td>
                     <td>₹<?= number_format($doctor['total_fees'], 2) ?></td>
                     <td>₹<?= number_format($doctor['total_fees'] / $doctor['visit_count'], 2) ?></td>
                     <td class="text-center">
                         <button class="btn btn-sm btn-outline-info" onclick="togglePatientDetails(<?= $doctor['id'] ?>)">
                             <i class="bi bi-eye"></i> View Patients
                         </button>
                     </td>
                 </tr>
                 <!-- Patient Details Row (Collapsible) -->
                 <tr id="patient-details-<?= $doctor['id'] ?>" class="patient-details-row" style="display: none;">
                     <td colspan="8" class="p-0">
                         <div class="card m-2">
                             <div class="card-header bg-light">
                                 <h6 class="mb-0">
                                     <i class="bi bi-people"></i> 
                                     Patient Details for <?= htmlspecialchars($doctor['name']) ?>
                                 </h6>
                             </div>
                             <div class="card-body p-0">
                                 <?php if (isset($doctor_patient_details[$doctor['id']]) && !empty($doctor_patient_details[$doctor['id']])): ?>
                                 <div class="table-responsive">
                                     <table class="table table-sm table-striped mb-0">
                                         <thead class="table-light">
                                             <tr>
                                                 <th>Visit Date</th>
                                                 <th>Patient Name</th>
                                                 <th>Age/Gender</th>
                                                 <th>Contact</th>
                                                 <th>Reason</th>
                                                 <th>Consultation Fee</th>
                                                 <th>Payment Mode</th>
                                                 <th>Actions</th>
                                             </tr>
                                         </thead>
                                         <tbody>
                                             <?php foreach ($doctor_patient_details[$doctor['id']] as $visit): ?>
                                             <tr>
                                                 <td><?= date('M j, Y H:i', strtotime($visit['visit_date'])) ?></td>
                                                 <td><strong><?= htmlspecialchars($visit['full_name']) ?></strong></td>
                                                 <td>
                                                     <?php 
                                                     $dob = new DateTime($visit['dob']);
                                                     $visit_date = new DateTime($visit['visit_date']);
                                                     $age = $visit_date->diff($dob)->y;
                                                     echo $age . ' yrs / ' . htmlspecialchars($visit['gender']);
                                                     ?>
                                                 </td>
                                                 <td><?= htmlspecialchars($visit['contact_number']) ?></td>
                                                 <td><?= htmlspecialchars($visit['reason']) ?></td>
                                                 <td>₹<?= number_format($visit['consultation_fee'] ?? $doctor['fees'], 2) ?></td>
                                                 <td>
                                                     <span class="badge bg-<?= $visit['payment_mode'] === 'Cash' ? 'success' : ($visit['payment_mode'] === 'Card' ? 'primary' : 'warning') ?>">
                                                         <?= htmlspecialchars($visit['payment_mode'] ?? 'N/A') ?>
                                                     </span>
                                                 </td>
                                                                                                   <td>
                                                      <a href="prescription.php?visit_id=<?= $visit['visit_id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                          <i class="bi bi-printer"></i>
                                                      </a>
                                                      <a href="lab_billing.php?visit_id=<?= $visit['visit_id'] ?>" class="btn btn-sm btn-outline-success" target="_blank">
                                                          <i class="bi bi-droplet"></i>
                                                      </a>
                                                                                                             <?php 
                                                       // Get pharmacy invoice for this visit
                                                       $pharmacy_stmt = $pdo->prepare("SELECT invoice_number FROM pharmacy_bills WHERE visit_id = ? AND invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
                                                       $pharmacy_stmt->execute([$visit['visit_id']]);
                                                       $pharmacy_invoice = $pharmacy_stmt->fetchColumn();
                                                       
                                                       if ($pharmacy_invoice): ?>
                                                       <a href="pharmacy_billing.php?visit_id=<?= $visit['visit_id'] ?>&invoice_number=<?= urlencode($pharmacy_invoice) ?>" class="btn btn-sm btn-outline-warning" target="_blank" title="View Pharmacy Bill: <?= $pharmacy_invoice ?>">
                                                           <i class="bi bi-capsule-fill"></i>
                                                       </a>
                                                       <?php else: ?>
                                                       <a href="pharmacy_billing.php?visit_id=<?= $visit['visit_id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Create New Pharmacy Bill">
                                                           <i class="bi bi-capsule"></i>
                                                       </a>
                                                       <?php endif; ?>
                                                  </td>
                                             </tr>
                                             <?php endforeach; ?>
                                         </tbody>
                                     </table>
                                 </div>
                                 <?php else: ?>
                                 <div class="p-3 text-center text-muted">
                                     <i class="bi bi-info-circle"></i> No patient details found for this doctor.
                                 </div>
                                 <?php endif; ?>
                             </div>
                         </div>
                     </td>
                 </tr>
                 <?php endforeach; ?>
             </tbody>
             <tfoot class="table-dark">
                 <tr>
                     <th colspan="4">Total</th>
                     <th class="text-center"><?= number_format($total_visits) ?></th>
                     <th>₹<?= number_format($total_consultation_fees, 2) ?></th>
                     <th>₹<?= $total_visits > 0 ? number_format($total_consultation_fees / $total_visits, 2) : '0.00' ?></th>
                     <th></th>
                 </tr>
             </tfoot>
         </table>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No visits found for the selected time period.
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Dynamically change date input type based on summary type
function updateDateInput() {
    const type = document.getElementById('summaryType').value;
    const dateInputCol = document.getElementById('dateInputCol');
    const dateLabel = document.getElementById('dateLabel');
    let dateInput = document.getElementById('date');
    let value = dateInput.value;
    let newInput;
    if (type === 'weekly') {
        dateLabel.textContent = 'Select Week';
        newInput = document.createElement('input');
        newInput.type = 'week';
        newInput.className = 'form-control';
        newInput.id = 'date';
        newInput.name = 'date';
        // Convert YYYY-MM-DD to YYYY-Www
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            const d = new Date(value);
            const year = d.getFullYear();
            const week = getWeekNumber(d);
            newInput.value = year + '-W' + (week < 10 ? '0' : '') + week;
        } else {
            newInput.value = value;
        }
    } else if (type === 'monthly') {
        dateLabel.textContent = 'Select Month';
        newInput = document.createElement('input');
        newInput.type = 'month';
        newInput.className = 'form-control';
        newInput.id = 'date';
        newInput.name = 'date';
        // Convert YYYY-MM-DD to YYYY-MM
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            newInput.value = value.substring(0,7);
        } else {
            newInput.value = value;
        }
    } else {
        dateLabel.textContent = 'Select Date';
        newInput = document.createElement('input');
        newInput.type = 'date';
        newInput.className = 'form-control';
        newInput.id = 'date';
        newInput.name = 'date';
        // Convert YYYY-Www or YYYY-MM to YYYY-MM-DD
        if (/^\d{4}-W\d{2}$/.test(value)) {
            // Week to date (Monday)
            const parts = value.split('-W');
            const simple = weekToDate(parts[0], parts[1]);
            newInput.value = simple;
        } else if (/^\d{4}-\d{2}$/.test(value)) {
            newInput.value = value + '-01';
        } else {
            newInput.value = value;
        }
    }
    dateInputCol.replaceChild(newInput, dateInput);
}

function getWeekNumber(d) {
    d = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay()||7));
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(),0,1));
    const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1)/7);
    return weekNo;
}
function weekToDate(year, week) {
    const simple = new Date(year, 0, 1 + (week - 1) * 7);
    const dow = simple.getDay();
    const ISOweekStart = simple;
    if (dow <= 4)
        ISOweekStart.setDate(simple.getDate() - simple.getDay() + 1);
    else
        ISOweekStart.setDate(simple.getDate() + 8 - simple.getDay());
    return ISOweekStart.toISOString().slice(0,10);
}

document.getElementById('summaryType').addEventListener('change', updateDateInput);
document.addEventListener('DOMContentLoaded', updateDateInput);

// Function to toggle patient details for each doctor
function togglePatientDetails(doctorId) {
    const detailsRow = document.getElementById('patient-details-' + doctorId);
    const button = event.target.closest('button');
    const icon = button.querySelector('i');
    
    if (detailsRow.style.display === 'none') {
        // Show details
        detailsRow.style.display = 'table-row';
        button.classList.remove('btn-outline-info');
        button.classList.add('btn-info');
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
        button.innerHTML = '<i class="bi bi-eye-slash"></i> Hide Patients';
    } else {
        // Hide details
        detailsRow.style.display = 'none';
        button.classList.remove('btn-info');
        button.classList.add('btn-outline-info');
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
        button.innerHTML = '<i class="bi bi-eye"></i> View Patients';
    }
}
</script>
</body>
</html> 