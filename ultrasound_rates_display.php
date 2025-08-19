<?php
// ultrasound_rates_display.php - Display all ultrasound scan rates with search (read-only)
require_once 'includes/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM ultrasound_scans WHERE scan_name LIKE ? AND is_active = 1 ORDER BY scan_name");
    $stmt->execute(['%' . $search . '%']);
    $scans = $stmt->fetchAll();
} else {
    $scans = $pdo->query("SELECT * FROM ultrasound_scans WHERE is_active = 1 ORDER BY scan_name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultrasound Scan Rates - Ishtah Clinic</title>
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
        <li class="nav-item">
          <a class="nav-link active" href="ultrasound_rates_display.php">Ultrasound Rates</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
    <h2 class="mb-4">Ultrasound Scan Rates</h2>
    <form class="row mb-3" method="get">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="Search by scan name" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="ultrasound_rates_display.php" class="btn btn-secondary ms-2">Reset</a>
            <a href="index.php" class="btn btn-secondary ms-2">Back to Home</a>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Scan Name</th>
                    <th>Price (₹)</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scans as $i => $scan): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($scan['scan_name']) ?></td>
                    <td><?= number_format($scan['price'], 2) ?></td>
                    <td><?= htmlspecialchars($scan['description'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($scans)): ?>
                <tr><td colspan="4" class="text-center">No ultrasound scans found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
