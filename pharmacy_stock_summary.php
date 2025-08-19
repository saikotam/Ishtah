<?php
// pharmacy_stock_summary.php - Medicine Search Reference for Receptionist
require_once 'includes/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query = "SELECT * FROM pharmacy_stock";
$params = [];
if ($search) {
    $query .= " AND (medicine_name LIKE ? OR batch_no LIKE ? OR supplier_name LIKE ?)";
    $params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];
}
$query .= " ORDER BY medicine_name, batch_no, expiry_date";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$stock = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Search Reference - Ishtah Clinic</title>
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
          <a class="nav-link active" href="pharmacy_stock_summary.php">Medicines Search</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="lab_test_rates.php">Lab Test Rates</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
    <h2 class="mb-4">Medicine Search Reference</h2>
    <div class="alert alert-info">This page is for reference only. Use the search below to find available medicines in stock.</div>
    <form class="row mb-3" method="get">
        <div class="col-md-6">
            <input type="text" class="form-control" name="search" placeholder="Search by medicine, batch, or supplier" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="pharmacy_stock_summary.php" class="btn btn-secondary ms-2">Reset</a>
            <a href="index.php" class="btn btn-secondary ms-2">Back to Home</a>
        </div>
    </form>
    <div class="mb-3">
        <span class="badge bg-danger">&nbsp;&nbsp;</span> Expired
        <span class="ms-3 badge bg-warning text-dark">&nbsp;&nbsp;</span> Out of Stock
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th>HSN Code</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th>Quantity</th>
                    <th>Unit Type</th>
                    <th>Supplier</th>
                    <th>Box No</th>
                    <th>Sale Price</th>
                    <th>GST %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stock as $item): 
                    $isExpired = false;
                    $isOutOfStock = ($item['quantity'] <= 0);
                    if (!empty($item['expiry_date']) && $item['expiry_date'] !== '0000-00-00') {
                        $isExpired = (strtotime($item['expiry_date']) < strtotime(date('Y-m-d')));
                    }
                    $rowClass = '';
                    if ($isExpired) {
                        $rowClass = 'table-danger';
                    } elseif ($isOutOfStock) {
                        $rowClass = 'table-warning';
                    }
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($item['hsn_code']) ?></td>
                    <td><?= htmlspecialchars($item['batch_no']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= htmlspecialchars($item['unit_type']) ?></td>
                    <td><?= htmlspecialchars($item['supplier_name']) ?></td>
                    <td><?= htmlspecialchars($item['box_number']) ?></td>
                    <td>₹<?= number_format($item['sale_price'],2) ?></td>
                    <td><?= $item['gst_percent'] ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($stock)): ?>
                <tr><td colspan="9" class="text-center">No medicines found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 