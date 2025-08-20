<?php
// accounting_reports.php - Financial reports (P&L, Inventory, GST, Snapshot)
require_once 'includes/db.php';

function get_date_range($type, $date, $custom_start = null, $custom_end = null) {
	$start = $end = '';
	if ($type === 'weekly') {
		$start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
		$end = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
	} elseif ($type === 'monthly') {
		$start = date('Y-m-01', strtotime($date));
		$end = date('Y-m-t', strtotime($date));
	} elseif ($type === 'custom') {
		$start = $custom_start ?: date('Y-m-01');
		$end = $custom_end ?: date('Y-m-t');
	} else { // daily
		$start = $end = $date;
	}
	return [$start, $end];
}

function fetch_scalar($pdo, $sql, $params = []) {
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$value = $stmt->fetchColumn();
	return $value ? floatval($value) : 0.0;
}

// Read filters
$type = isset($_GET['type']) ? $_GET['type'] : 'monthly';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$custom_start = isset($_GET['start']) ? $_GET['start'] : null;
$custom_end = isset($_GET['end']) ? $_GET['end'] : null;
list($start_date, $end_date) = get_date_range($type, $date, $custom_start, $custom_end);

// Revenues (net of GST for pharmacy)
$consultation_revenue = fetch_scalar($pdo, "SELECT SUM(amount) FROM consultation_invoices WHERE DATE(created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$lab_revenue = fetch_scalar($pdo, "SELECT SUM(discounted_amount) FROM lab_bills WHERE DATE(created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$ultrasound_revenue = fetch_scalar($pdo, "SELECT SUM(discounted_total) FROM ultrasound_bills WHERE DATE(created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$pharmacy_gross = fetch_scalar($pdo, "SELECT SUM(discounted_total) FROM pharmacy_bills WHERE DATE(created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$pharmacy_gst = fetch_scalar($pdo, "SELECT SUM(gst_amount) FROM pharmacy_bills WHERE DATE(created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$pharmacy_net_sales = max(0.0, $pharmacy_gross - $pharmacy_gst);

// Direct COGS (Pharmacy only, using purchase price from stock)
$pharmacy_cogs = fetch_scalar(
	$pdo,
	"SELECT SUM(pbi.quantity * ps.purchase_price)
	 FROM pharmacy_bill_items pbi
	 JOIN pharmacy_bills pb ON pbi.bill_id = pb.id
	 JOIN pharmacy_stock ps ON pbi.medicine_id = ps.id
	 WHERE DATE(pb.created_at) BETWEEN ? AND ?",
	[$start_date, $end_date]
);

// Doctor incentives (selling expense/accrual based on ultrasound bill date)
$doctor_incentives_total = fetch_scalar($pdo, "SELECT SUM(di.incentive_amount) FROM doctor_incentives di JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id WHERE DATE(ub.created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$doctor_incentives_paid = fetch_scalar($pdo, "SELECT SUM(di.incentive_amount) FROM doctor_incentives di JOIN ultrasound_bills ub ON di.ultrasound_bill_id = ub.id WHERE di.paid = 1 AND DATE(ub.created_at) BETWEEN ? AND ?", [$start_date, $end_date]);
$doctor_incentives_pending = max(0.0, $doctor_incentives_total - $doctor_incentives_paid);

// Totals and subtotals
$total_net_revenue = $consultation_revenue + $lab_revenue + $ultrasound_revenue + $pharmacy_net_sales;
$gross_profit = $total_net_revenue - $pharmacy_cogs;
$operating_expenses = $doctor_incentives_total; // extend later with other expenses
$net_profit = $gross_profit - $operating_expenses;

// Inventory valuation snapshot (current)
$inventory_value = fetch_scalar($pdo, "SELECT SUM(quantity * purchase_price) FROM pharmacy_stock WHERE quantity > 0", []);

// GST output summary (period)
$gst_output_collected = $pharmacy_gst; // collected on pharmacy sales

function fmt_money($amount) {
	return number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="mb-0">Accounting Reports</h2>
            <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Home</a>
        </div>

        <form method="get" class="row gy-2 gx-2 align-items-end mb-4">
            <div class="col-auto">
                <label class="form-label">Range Type</label>
                <select name="type" class="form-select">
                    <option value="daily" <?= $type==='daily'?'selected':'' ?>>Daily</option>
                    <option value="weekly" <?= $type==='weekly'?'selected':'' ?>>Weekly</option>
                    <option value="monthly" <?= $type==='monthly'?'selected':'' ?>>Monthly</option>
                    <option value="custom" <?= $type==='custom'?'selected':'' ?>>Custom</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label">Ref Date</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Start</label>
                <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">End</label>
                <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                <a href="accounting_reports.php" class="btn btn-secondary ms-2">Reset</a>
            </div>
            <div class="col-12 text-muted">
                Period: <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?>
            </div>
        </form>

        <div class="row g-3">
            <div class="col-12 col-xl-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white"><strong>Profit & Loss (P&L)</strong></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr><th>Category</th><th class="text-end">Amount (₹)</th></tr>
                                </thead>
                                <tbody>
                                    <tr class="table-light"><td colspan="2"><strong>Revenue (Net)</strong></td></tr>
                                    <tr><td>Consultation</td><td class="text-end"><?= fmt_money($consultation_revenue) ?></td></tr>
                                    <tr><td>Lab</td><td class="text-end"><?= fmt_money($lab_revenue) ?></td></tr>
                                    <tr><td>Ultrasound</td><td class="text-end"><?= fmt_money($ultrasound_revenue) ?></td></tr>
                                    <tr><td>Pharmacy (Net of GST)</td><td class="text-end"><?= fmt_money($pharmacy_net_sales) ?></td></tr>
                                    <tr class="table-secondary"><td><strong>Total Net Revenue</strong></td><td class="text-end"><strong><?= fmt_money($total_net_revenue) ?></strong></td></tr>
                                    <tr class="table-light"><td colspan="2"><strong>Cost of Goods Sold</strong></td></tr>
                                    <tr><td>Pharmacy COGS</td><td class="text-end"><?= fmt_money($pharmacy_cogs) ?></td></tr>
                                    <tr class="table-secondary"><td><strong>Gross Profit</strong></td><td class="text-end"><strong><?= fmt_money($gross_profit) ?></strong></td></tr>
                                    <tr class="table-light"><td colspan="2"><strong>Operating Expenses</strong></td></tr>
                                    <tr><td>Doctor Incentives (Accrued)</td><td class="text-end"><?= fmt_money($doctor_incentives_total) ?></td></tr>
                                    <tr class="table-secondary"><td><strong>Net Profit</strong></td><td class="text-end"><strong><?= fmt_money($net_profit) ?></strong></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted small">Note: Pharmacy sales are reported net of GST. Add other expenses outside the system if applicable.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>GST Summary (Period)</strong></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between"><div>Output GST (Pharmacy)</div><div><strong>₹ <?= fmt_money($gst_output_collected) ?></strong></div></div>
                        <div class="text-muted small mt-2">Note: Input GST on purchases is not tracked here.</div>
                    </div>
                </div>
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>Doctor Incentives (Breakdown)</strong></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between"><div>Accrued (in Period)</div><div><strong>₹ <?= fmt_money($doctor_incentives_total) ?></strong></div></div>
                        <div class="d-flex justify-content-between"><div>Paid (in Period)</div><div><strong>₹ <?= fmt_money($doctor_incentives_paid) ?></strong></div></div>
                        <div class="d-flex justify-content-between"><div>Pending (Accrued - Paid)</div><div><strong>₹ <?= fmt_money($doctor_incentives_pending) ?></strong></div></div>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-header bg-white"><strong>Inventory Valuation (Snapshot)</strong></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between"><div>Closing Stock (at Cost)</div><div><strong>₹ <?= fmt_money($inventory_value) ?></strong></div></div>
                        <div class="text-muted small mt-2">Computed from current pharmacy stock: SUM(quantity × purchase_price).</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-3">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white"><strong>Balance Sheet-style Snapshot (Simplified)</strong></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Assets</h6>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">Inventory (Pharmacy)<span>₹ <?= fmt_money($inventory_value) ?></span></li>
                                </ul>
                                <div class="text-muted small mt-2">Cash/Bank and Receivables are not tracked in the system.</div>
                            </div>
                            <div class="col-md-6">
                                <h6>Liabilities & Equity</h6>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">GST Collected (Period)<span>₹ <?= fmt_money($gst_output_collected) ?></span></li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">Doctor Incentives Pending<span>₹ <?= fmt_money($doctor_incentives_pending) ?></span></li>
                                </ul>
                                <div class="text-muted small mt-2">This is a simplified view; full ledger-based balances require a chart-of-accounts.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Auto-toggle custom dates when type changes
    document.addEventListener('DOMContentLoaded', function() {
        const typeSelect = document.querySelector('select[name="type"]');
        const startInput = document.querySelector('input[name="start"]');
        const endInput = document.querySelector('input[name="end"]');
        function toggleCustom() {
            const isCustom = typeSelect.value === 'custom';
            startInput.disabled = !isCustom;
            endInput.disabled = !isCustom;
        }
        typeSelect.addEventListener('change', toggleCustom);
        toggleCustom();
    });
    </script>
</body>
</html>

