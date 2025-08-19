<?php
// pharmacy_stock_log.php - Display pharmacy stock log
require_once 'includes/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query = "SELECT l.*, s.medicine_name FROM pharmacy_stock_log l JOIN pharmacy_stock s ON l.medicine_id = s.id";
$params = [];
if ($search) {
    $query .= " WHERE s.medicine_name LIKE ? OR l.action LIKE ?";
    $params = ['%' . $search . '%', '%' . $search . '%'];
}
$query .= " ORDER BY l.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Stock Log - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">Pharmacy Stock Log</h2>
    <form class="row mb-3" method="get">
        <div class="col-md-4">
            <input type="text" class="form-control" name="search" placeholder="Search by medicine or action" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="pharmacy_stock_log.php" class="btn btn-secondary ms-2">Reset</a>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Medicine</th>
                    <th>Qty Change</th>
                    <th>Action</th>
                    <th>Reason</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['medicine_name']) ?></td>
                    <td><?= $log['qty_change'] ?></td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                    <td><?= htmlspecialchars($log['reason']) ?></td>
                    <td><?= htmlspecialchars($log['user']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center">No log entries found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3">Back to Home</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 