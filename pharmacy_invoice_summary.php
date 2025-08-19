<?php
// pharmacy_invoice_summary.php - Detailed summary of all pharmacy invoices at item level
require_once 'includes/db.php';

// Helper: Get date range based on type and date
function get_date_range($type, $date, $custom_start = null, $custom_end = null) {
    $start = $end = '';
    if ($type === 'weekly') {
        $start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $end = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    } elseif ($type === 'monthly') {
        $start = date('Y-m-01', strtotime($date));
        $end = date('Y-m-t', strtotime($date));
    } elseif ($type === 'custom' && $custom_start && $custom_end) {
        $start = $custom_start;
        $end = $custom_end;
    } else { // daily
        $start = $end = $date;
    }
    return [$start, $end];
}

$type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$custom_start = isset($_GET['custom_start']) ? $_GET['custom_start'] : null;
$custom_end = isset($_GET['custom_end']) ? $_GET['custom_end'] : null;
list($start_date, $end_date) = get_date_range($type, $date, $custom_start, $custom_end);

// Fetch all pharmacy invoices and their items in the selected date range
$sql = "SELECT pb.id AS bill_id, pb.invoice_number, pb.created_at, pb.total_amount, pb.discount_type, pb.discount_value, pb.discounted_total, pb.gst_amount,\n       p.full_name, pb.visit_id\nFROM pharmacy_bills pb\nJOIN visits v ON pb.visit_id = v.id\nJOIN patients p ON v.patient_id = p.id\nWHERE DATE(pb.created_at) BETWEEN ? AND ?\nORDER BY pb.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all items for these invoices
$invoice_ids = array_column($invoices, 'bill_id');
$items_by_invoice = [];
$hsn_summary = [];
if ($invoice_ids) {
    $in = str_repeat('?,', count($invoice_ids)-1) . '?';
    $sql_items = "SELECT pbi.*, ps.medicine_name, ps.purchase_price, ps.sale_price, ps.gst_percent, ps.hsn_code\n                  FROM pharmacy_bill_items pbi\n                  JOIN pharmacy_stock ps ON pbi.medicine_id = ps.id\n                  WHERE pbi.bill_id IN ($in)\n                  ORDER BY pbi.bill_id, pbi.id";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute($invoice_ids);
    while ($row = $stmt_items->fetch(PDO::FETCH_ASSOC)) {
        $items_by_invoice[$row['bill_id']][] = $row;
        // HSN summary aggregation
        $hsn = $row['hsn_code'] ?: 'N/A';
        $gst_percent = $row['gst_percent'];
        $gst_amt = calc_gst_amount($row['price'], $gst_percent);
        $base_price = $row['price'] - $gst_amt;
        if (!isset($hsn_summary[$hsn])) {
            $hsn_summary[$hsn] = [
                'hsn_code' => $hsn,
                'gst_percent' => $gst_percent,
                'taxable_value' => 0,
                'gst_amount' => 0,
                'total' => 0
            ];
        }
        $hsn_summary[$hsn]['taxable_value'] += $base_price;
        $hsn_summary[$hsn]['gst_amount'] += $gst_amt;
        $hsn_summary[$hsn]['total'] += $row['price'];
    }
}

function format_discount($type, $value) {
    if (!$type || !$value) return '-';
    return $type === 'percent' ? $value . '%' : '₹' . $value;
}
function calc_gst_amount($price, $gst_percent) {
    // GST amount = price * gst_percent / (100 + gst_percent)
    return round($price * $gst_percent / (100 + $gst_percent), 2);
}
// GST % wise summary
$gst_percent_summary = [];
foreach ($hsn_summary as $row) {
    $gst = $row['gst_percent'];
    if (!isset($gst_percent_summary[$gst])) {
        $gst_percent_summary[$gst] = [
            'gst_percent' => $gst,
            'taxable_value' => 0,
            'gst_amount' => 0,
            'total' => 0
        ];
    }
    $gst_percent_summary[$gst]['taxable_value'] += $row['taxable_value'];
    $gst_percent_summary[$gst]['gst_amount'] += $row['gst_amount'];
    $gst_percent_summary[$gst]['total'] += $row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Invoice Detailed Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <h2 class="mb-4">Pharmacy Invoice Detailed Summary</h2>
    <form class="row g-3 mb-4" method="get">
        <div class="col-auto">
            <label for="type" class="form-label">Period:</label>
            <select name="type" id="type" class="form-select" onchange="onTypeChange()">
                <option value="daily" <?= $type === 'daily' ? 'selected' : '' ?>>Today</option>
                <option value="weekly" <?= $type === 'weekly' ? 'selected' : '' ?>>This Week</option>
                <option value="monthly" <?= $type === 'monthly' ? 'selected' : '' ?>>This Month</option>
                <option value="custom" <?= $type === 'custom' ? 'selected' : '' ?>>Custom</option>
            </select>
        </div>
        <div class="col-auto" id="dateField" style="display: <?= $type === 'custom' ? 'none' : 'block' ?>;">
            <label for="date" class="form-label">Date:</label>
            <input type="date" name="date" id="date" class="form-control" value="<?= htmlspecialchars($date) ?>">
        </div>
        <div class="col-auto" id="customFields" style="display: <?= $type === 'custom' ? 'flex' : 'none' ?>; gap: 8px;">
            <div>
                <label for="custom_start" class="form-label">From:</label>
                <input type="date" name="custom_start" id="custom_start" class="form-control" value="<?= htmlspecialchars($custom_start) ?>">
            </div>
            <div>
                <label for="custom_end" class="form-label">To:</label>
                <input type="date" name="custom_end" id="custom_end" class="form-control" value="<?= htmlspecialchars($custom_end) ?>">
            </div>
        </div>
        <div class="col-auto align-self-end">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="pharmacy_invoice_summary.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>
    <?php if ($hsn_summary): ?>
    <div class="mb-4">
        <h4>HSN-wise GST Summary</h4>
        <form method="post" style="display:inline;">
            <input type="hidden" name="export_hsn_csv" value="1">
            <button type="submit" class="btn btn-sm btn-success mb-2">Export as CSV</button>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th>HSN Code</th>
                        <th>GST %</th>
                        <th>Taxable Value (₹)</th>
                        <th>GST Amount (₹)</th>
                        <th>Total (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hsn_summary as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['hsn_code']) ?></td>
                        <td><?= number_format($row['gst_percent'],2) ?></td>
                        <td><?= number_format($row['taxable_value'],2) ?></td>
                        <td><?= number_format($row['gst_amount'],2) ?></td>
                        <td><?= number_format($row['total'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mb-4">
        <h4>GST % wise Split Summary</h4>
        <form method="post" style="display:inline;">
            <input type="hidden" name="export_gst_csv" value="1">
            <button type="submit" class="btn btn-sm btn-success mb-2">Export as CSV</button>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th>GST %</th>
                        <th>Taxable Value (₹)</th>
                        <th>GST Amount (₹)</th>
                        <th>Total (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gst_percent_summary as $row): ?>
                    <tr>
                        <td><?= number_format($row['gst_percent'],2) ?></td>
                        <td><?= number_format($row['taxable_value'],2) ?></td>
                        <td><?= number_format($row['gst_amount'],2) ?></td>
                        <td><?= number_format($row['total'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($invoices): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <strong>All Invoice Items (<?= count($invoices) ?> invoices)</strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date/Time</th>
                            <th>Patient</th>
                            <th>Medicine Name</th>
                            <th>Quantity</th>
                            <th>Purchase Price (₹)</th>
                            <th>Sale Price (₹)</th>
                            <th>Discount (₹)</th>
                            <th>GST %</th>
                            <th>GST Amount (₹)</th>
                            <th>Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                            <?php $items = isset($items_by_invoice[$inv['bill_id']]) ? $items_by_invoice[$inv['bill_id']] : []; ?>
                            <?php if ($items): ?>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                        $gst_amt = calc_gst_amount($item['price'], $item['gst_percent']);
                                        $item_discount = isset($item['item_discount']) ? $item['item_discount'] : 0;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                                        <td><?= date('d-M-Y H:i', strtotime($inv['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($inv['full_name']) ?></td>
                                        <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                                        <td><?= htmlspecialchars($item['quantity']) ?></td>
                                        <td><?= number_format($item['purchase_price'], 2) ?></td>
                                        <td><?= number_format($item['sale_price'], 2) ?></td>
                                        <td><?= number_format($item_discount, 2) ?></td>
                                        <td><?= number_format($item['gst_percent'], 2) ?></td>
                                        <td><?= number_format($gst_amt, 2) ?></td>
                                        <td><?= number_format($item['price'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                                    <td><?= date('d-M-Y H:i', strtotime($inv['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($inv['full_name']) ?></td>
                                    <td colspan="8" class="text-center">No items found for this invoice.</td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">No invoices found for selected period.</div>
<?php endif; ?>
</div>
<script>
function onTypeChange() {
    var type = document.getElementById('type').value;
    document.getElementById('dateField').style.display = (type === 'custom') ? 'none' : 'block';
    document.getElementById('customFields').style.display = (type === 'custom') ? 'flex' : 'none';
}
</script>
<?php
// CSV export handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_hsn_csv']) && $hsn_summary) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="hsn_gst_summary.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['HSN Code', 'GST %', 'Taxable Value (₹)', 'GST Amount (₹)', 'Total (₹)']);
    foreach ($hsn_summary as $row) {
        fputcsv($out, [
            $row['hsn_code'],
            $row['gst_percent'],
            number_format($row['taxable_value'],2,'.',''),
            number_format($row['gst_amount'],2,'.',''),
            number_format($row['total'],2,'.','')
        ]);
    }
    fclose($out);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_gst_csv']) && $gst_percent_summary) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="gst_percent_summary.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['GST %', 'Taxable Value (₹)', 'GST Amount (₹)', 'Total (₹)']);
    foreach ($gst_percent_summary as $row) {
        fputcsv($out, [
            $row['gst_percent'],
            number_format($row['taxable_value'],2,'.',''),
            number_format($row['gst_amount'],2,'.',''),
            number_format($row['total'],2,'.','')
        ]);
    }
    fclose($out);
    exit;
}
?>
</body>
</html> 