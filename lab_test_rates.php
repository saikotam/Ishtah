<?php
// lab_test_rates.php - Display all lab test rates with search
require_once 'includes/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE test_name LIKE ? ORDER BY test_name");
    $stmt->execute(['%' . $search . '%']);
    $tests = $stmt->fetchAll();
} else {
    $tests = $pdo->query("SELECT * FROM lab_tests ORDER BY test_name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Test Rates - Ishtah Clinic</title>
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
          <a class="nav-link active" href="lab_test_rates.php">Lab Test Rates</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
    <h2 class="mb-4">Lab Test Rates</h2>
    <form class="row mb-3" method="get">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="Search by test name" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="lab_test_rates.php" class="btn btn-secondary ms-2">Reset</a>
            <a href="index.php" class="btn btn-secondary ms-2">Back to Home</a>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Test Name</th>
                    <th>Price (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $i => $test): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($test['test_name']) ?></td>
                    <td><?= number_format($test['price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tests)): ?>
                <tr><td colspan="3" class="text-center">No lab tests found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 