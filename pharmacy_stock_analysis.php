<?php
require_once 'includes/db.php';

// Handle threshold updates
$near_expiry_days = isset($_GET['near_expiry_days']) ? (int)$_GET['near_expiry_days'] : 30;
if ($near_expiry_days < 1) $near_expiry_days = 30;

// Update low stock threshold for a medicine
if (isset($_POST['update_threshold'], $_POST['medicine_id'], $_POST['new_threshold'])) {
    $medicine_id = (int)$_POST['medicine_id'];
    $new_threshold = max(0, (int)$_POST['new_threshold']);
    $stmt = $pdo->prepare("UPDATE pharmacy_stock SET low_stock_threshold = ? WHERE id = ?");
    $stmt->execute([$new_threshold, $medicine_id]);
}

// Near expiry medicines
$expiry_limit = date('Y-m-d', strtotime("+{$near_expiry_days} days"));
$near_expiry = $pdo->query("SELECT * FROM pharmacy_stock WHERE expiry_date IS NOT NULL AND expiry_date != '0000-00-00' AND expiry_date <= '".$expiry_limit."' AND quantity > 0 ORDER BY expiry_date ASC")->fetchAll();

// Low stock medicines (using per-medicine threshold)
$low_stock = $pdo->query("SELECT * FROM pharmacy_stock WHERE quantity <= low_stock_threshold ORDER BY quantity ASC")->fetchAll();

// Most frequently dispensed medicines (last 90 days)
$dispense_stats = $pdo->query("
    SELECT s.medicine_name, SUM(ABS(l.qty_change)) as total_dispensed
    FROM pharmacy_stock_log l
    JOIN pharmacy_stock s ON l.medicine_id = s.id
    WHERE l.action = 'sale' AND l.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY l.medicine_id
    ORDER BY total_dispensed DESC
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Stock Analysis - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">Pharmacy Stock Analysis</h2>
    <form class="row mb-4" method="get">
        <div class="col-md-4">
            <label for="near_expiry_days" class="form-label">Near Expiry Threshold (days):</label>
            <input type="number" class="form-control" id="near_expiry_days" name="near_expiry_days" value="<?= htmlspecialchars($near_expiry_days) ?>" min="1">
        </div>
        <div class="col-md-2 align-self-end">
            <button type="submit" class="btn btn-primary">Update</button>
        </div>
    </form>
    <h4>Near Expiry Medicines (within <?= $near_expiry_days ?> days)</h4>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th>Quantity</th>
                    <th>Unit Type</th>
                    <th>Supplier</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($near_expiry as $item): ?>
                <tr class="table-warning">
                    <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($item['batch_no']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= htmlspecialchars($item['unit_type']) ?></td>
                    <td><?= htmlspecialchars($item['supplier_name']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($near_expiry)): ?>
                <tr><td colspan="6" class="text-center">No near expiry medicines found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <h4>Low Stock Medicines</h4>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Quantity</th>
                    <th>Unit Type</th>
                    <th>Supplier</th>
                    <th>Low Stock Threshold</th>
                    <th>Update Threshold</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock as $item): ?>
                <tr class="table-danger">
                    <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($item['batch_no']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= htmlspecialchars($item['unit_type']) ?></td>
                    <td><?= htmlspecialchars($item['supplier_name']) ?></td>
                    <td><?= $item['low_stock_threshold'] ?></td>
                    <td>
                        <form method="post" class="d-flex align-items-center" style="gap:4px;">
                            <input type="hidden" name="medicine_id" value="<?= $item['id'] ?>">
                            <input type="number" name="new_threshold" value="<?= $item['low_stock_threshold'] ?>" min="0" class="form-control form-control-sm" style="width:70px;">
                            <button type="submit" name="update_threshold" class="btn btn-sm btn-primary">Update</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($low_stock)): ?>
                <tr><td colspan="7" class="text-center">No low stock medicines found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <h4>Most Frequently Dispensed Medicines (Last 90 Days)</h4>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th>Total Dispensed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dispense_stats as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['medicine_name']) ?></td>
                    <td><?= $row['total_dispensed'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($dispense_stats)): ?>
                <tr><td colspan="2" class="text-center">No dispensing data found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3">Back to Home</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 