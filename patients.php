<?php
// patients.php - List all registered patients
require_once 'includes/db.php';
require_once 'includes/patient.php';
require_once 'includes/log.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : null;
if ($search !== null && $search !== '') {
    $patients = search_patients($pdo, $search);
    log_action('Reception', 'Patient Search', 'Searched for: ' . $search);
} else {
    $stmt = $pdo->query("SELECT * FROM patients ORDER BY created_at DESC");
    $patients = $stmt->fetchAll();
}

// For each patient, fetch their visits and corresponding pharmacy/lab invoices
function get_patient_invoices($pdo, $patient_id) {
    // Get all visits for this patient
    $visits = $pdo->prepare("SELECT id FROM visits WHERE patient_id = ?");
    $visits->execute([$patient_id]);
    $visit_ids = array_column($visits->fetchAll(), 'id');
    $pharmacy = [];
    $lab = [];
    if ($visit_ids) {
        $in = str_repeat('?,', count($visit_ids)-1) . '?';
        // Pharmacy invoices
        $stmt = $pdo->prepare("SELECT id, invoice_number, visit_id FROM pharmacy_bills WHERE visit_id IN ($in) AND invoice_number IS NOT NULL");
        $stmt->execute($visit_ids);
        $pharmacy = $stmt->fetchAll();
        // Lab invoices
        $stmt = $pdo->prepare("SELECT id, invoice_number, visit_id FROM lab_bills WHERE visit_id IN ($in) AND invoice_number IS NOT NULL");
        $stmt->execute($visit_ids);
        $lab = $stmt->fetchAll();
    }
    return ['pharmacy' => $pharmacy, 'lab' => $lab];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Patients - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        <li class="nav-item">
          <a class="nav-link" href="pharmacy_stock_summary.php">Medicines Search</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="lab_test_rates.php">Lab Test Rates</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
    <h2 class="mb-4">All Registered Patients</h2>
    <form class="row mb-3" method="get">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="Search by name or contact" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-6">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="patients.php" class="btn btn-secondary ms-2">Reset</a>
            <a href="index.php" class="btn btn-secondary ms-2">Back to Home</a>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>DOB</th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th>Lead Source</th>
                    <th>Registered At</th>
                    <th>Invoices</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $i => $p): ?>
                <?php $invoices = get_patient_invoices($pdo, $p['id']); ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($p['full_name']) ?></td>
                    <td><?= htmlspecialchars($p['gender']) ?></td>
                    <td><?= htmlspecialchars($p['dob']) ?></td>
                    <td><?= htmlspecialchars($p['contact_number']) ?></td>
                    <td><?= htmlspecialchars($p['address']) ?></td>
                    <td><?= htmlspecialchars($p['lead_source']) ?></td>
                    <td><?= htmlspecialchars($p['created_at']) ?></td>
                    <td>
                        <?php foreach ($invoices['pharmacy'] as $inv): ?>
                            <a href="pharmacy_billing.php?visit_id=<?= $inv['visit_id'] ?>" class="btn btn-sm btn-outline-success mb-1" target="_blank">PH: <?= htmlspecialchars($inv['invoice_number']) ?></a><br>
                        <?php endforeach; ?>
                        <?php foreach ($invoices['lab'] as $inv): ?>
                            <a href="lab_billing.php?visit_id=<?= $inv['visit_id'] ?>" class="btn btn-sm btn-outline-primary mb-1" target="_blank">LAB: <?= htmlspecialchars($inv['invoice_number']) ?></a><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($patients)): ?>
                <tr><td colspan="8" class="text-center">No patients found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 