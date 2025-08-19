<?php
require_once 'includes/db.php';
require_once 'includes/log.php';

// Get filter parameters
$doctor_filter = $_GET['doctor_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
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
$incentives = $stmt->fetchAll();

// Calculate totals
$total_amount = array_sum(array_column($incentives, 'incentive_amount'));
$paid_amount = array_sum(array_column(array_filter($incentives, function($i) { return $i['paid'] == 1; }), 'incentive_amount'));
$pending_amount = array_sum(array_column(array_filter($incentives, function($i) { return $i['paid'] == 0; }), 'incentive_amount'));

// Set headers for PDF download
header('Content-Type: text/html');
header('Content-Disposition: attachment; filename="doctor_incentives_report.html"');

// Generate HTML report
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Doctor Incentives Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .summary { margin-bottom: 20px; }
        .summary table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .summary th, .summary td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .summary th { background-color: #f2f2f2; }
        .incentives-table { width: 100%; border-collapse: collapse; }
        .incentives-table th, .incentives-table td { border: 1px solid #ddd; padding: 6px; text-align: left; font-size: 12px; }
        .incentives-table th { background-color: #f2f2f2; font-weight: bold; }
        .paid { color: green; }
        .pending { color: orange; }
        .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Doctor Incentives Report</h1>
        <p>Generated on: <?= date('d/m/Y H:i:s') ?></p>
        <?php if ($date_from || $date_to): ?>
        <p>Period: <?= $date_from ? date('d/m/Y', strtotime($date_from)) : 'Start' ?> - <?= $date_to ? date('d/m/Y', strtotime($date_to)) : 'End' ?></p>
        <?php endif; ?>
    </div>

    <div class="summary">
        <h2>Summary</h2>
        <table>
            <tr>
                <th>Total Incentives</th>
                <th>Paid Amount</th>
                <th>Pending Amount</th>
                <th>Total Records</th>
            </tr>
            <tr>
                <td>₹<?= number_format($total_amount, 2) ?></td>
                <td class="paid">₹<?= number_format($paid_amount, 2) ?></td>
                <td class="pending">₹<?= number_format($pending_amount, 2) ?></td>
                <td><?= count($incentives) ?></td>
            </tr>
        </table>
    </div>

    <div class="incentives">
        <h2>Incentive Details</h2>
        <table class="incentives-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Doctor</th>
                    <th>Specialty</th>
                    <th>Patient</th>
                    <th>Invoice</th>
                    <th>Bill Amount</th>
                    <th>Incentive %</th>
                    <th>Incentive Amount</th>
                    <th>Status</th>
                    <th>Payment Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($incentives as $incentive): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($incentive['bill_date'])) ?></td>
                    <td><?= htmlspecialchars($incentive['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($incentive['specialty']) ?></td>
                    <td><?= htmlspecialchars($incentive['patient_name']) ?></td>
                    <td><?= htmlspecialchars($incentive['invoice_number']) ?></td>
                    <td>₹<?= number_format($incentive['bill_amount'], 2) ?></td>
                    <td><?= $incentive['incentive_percentage'] ?>%</td>
                    <td>₹<?= number_format($incentive['incentive_amount'], 2) ?></td>
                    <td class="<?= $incentive['paid'] ? 'paid' : 'pending' ?>">
                        <?= $incentive['paid'] ? 'Paid' : 'Pending' ?>
                    </td>
                    <td>
                        <?= $incentive['paid'] && $incentive['payment_date'] ? date('d/m/Y', strtotime($incentive['payment_date'])) : '-' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        <p>Report generated by Ishtah Clinic Management System</p>
        <p>This report contains confidential information and should be handled accordingly.</p>
    </div>
</body>
</html>
