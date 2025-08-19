<?php
// visit_list.php - List all visits with actions for lab and pharmacy billing
require_once 'includes/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search) {
    $stmt = $pdo->prepare("SELECT v.*, p.full_name, d.name AS doctor_name, d.specialty FROM visits v JOIN patients p ON v.patient_id = p.id JOIN doctors d ON v.doctor_id = d.id WHERE p.full_name LIKE ? OR d.name LIKE ? ORDER BY v.visit_date DESC");
    $stmt->execute(['%' . $search . '%', '%' . $search . '%']);
} else {
    $stmt = $pdo->query("SELECT v.*, p.full_name, d.name AS doctor_name, d.specialty FROM visits v JOIN patients p ON v.patient_id = p.id JOIN doctors d ON v.doctor_id = d.id ORDER BY v.visit_date DESC");
}
$visits = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit List - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">All Visits</h2>
    <form class="row mb-3" method="get">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="Search by patient or doctor" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="visit_list.php" class="btn btn-secondary ms-2">Reset</a>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Specialty</th>
                    <th>Visit Date</th>
                    <th>Reason</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($visits as $i => $v): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($v['full_name']) ?></td>
                    <td><?= htmlspecialchars($v['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($v['specialty']) ?></td>
                    <td><?= htmlspecialchars($v['visit_date']) ?></td>
                    <td><?= htmlspecialchars($v['reason']) ?></td>
                    <td>
                        <a href="lab_billing.php?visit_id=<?= $v['id'] ?>" class="btn btn-outline-primary btn-sm mb-1">Lab Bill</a>
                        <a href="ultrasound_billing.php?visit_id=<?= $v['id'] ?>" class="btn btn-outline-info btn-sm mb-1">Ultrasound Bill</a>
                        <a href="pharmacy_billing.php?visit_id=<?= $v['id'] ?>" class="btn btn-outline-success btn-sm mb-1">Pharmacy Bill</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($visits)): ?>
                <tr><td colspan="7" class="text-center">No visits found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3">Back to Home</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 