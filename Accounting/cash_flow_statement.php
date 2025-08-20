<?php
// cash_flow_statement.php - Cash Flow Statement Generator
require_once '../includes/db.php';
require_once 'accounting.php';

$accounting = new AccountingSystem($pdo);

// Handle form submissions
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-04-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

// Get financial year dates if not specified
if (!isset($_GET['start_date']) || !isset($_GET['end_date'])) {
    $fy_dates = getFinancialYearDates();
    $start_date = $fy_dates['start'];
    $end_date = $fy_dates['end'];
}

// Generate Cash Flow Statement
function generateCashFlowStatement($accounting, $start_date, $end_date) {
    global $pdo;
    // Get cash accounts
    $cash_accounts = ['1000', '1010']; // Cash in Hand, Cash at Bank
    
    // Operating Activities
    $operating_activities = [];
    
    // Net Income
    $stmt = $pdo->prepare("\n        SELECT \n            COALESCE(SUM(CASE WHEN c.account_type = 'REVENUE' THEN jel.credit_amount - jel.debit_amount ELSE 0 END), 0) as total_revenue,\n            COALESCE(SUM(CASE WHEN c.account_type = 'EXPENSE' THEN jel.debit_amount - jel.credit_amount ELSE 0 END), 0) as total_expenses\n        FROM journal_entries je\n        JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id\n        JOIN chart_of_accounts c ON jel.account_id = c.id\n        WHERE je.entry_date BETWEEN ? AND ?\n        AND je.status = 'POSTED'\n        AND c.account_type IN ('REVENUE', 'EXPENSE')\n    ");
    $stmt->execute([$start_date, $end_date]);
    $income_data = $stmt->fetch();
    $net_income = $income_data['total_revenue'] - $income_data['total_expenses'];
    
    // Changes in working capital
    $working_capital_changes = [];
    
    // Accounts Receivable change
    $ar_change = getAccountChange($accounting, '1020', $start_date, $end_date);
    if (abs($ar_change) > 0) {
        $working_capital_changes['Accounts Receivable'] = -$ar_change; // Increase in AR reduces cash
    }
    
    // Inventory change
    $inventory_change = getAccountChange($accounting, '1100', $start_date, $end_date);
    if (abs($inventory_change) > 0) {
        $working_capital_changes['Inventory'] = -$inventory_change; // Increase in inventory reduces cash
    }
    
    // Accounts Payable change
    $ap_change = getAccountChange($accounting, '2000', $start_date, $end_date);
    if (abs($ap_change) > 0) {
        $working_capital_changes['Accounts Payable'] = $ap_change; // Increase in AP increases cash
    }
    
    $total_working_capital_change = array_sum($working_capital_changes);
    $operating_cash_flow = $net_income + $total_working_capital_change;
    
    // Investing Activities
    $investing_activities = [];
    
    // Equipment purchases
    $equipment_change = getAccountChange($accounting, '1200', $start_date, $end_date);
    if ($equipment_change > 0) {
        $investing_activities['Equipment Purchases'] = -$equipment_change;
    }
    
    $furniture_change = getAccountChange($accounting, '1210', $start_date, $end_date);
    if ($furniture_change > 0) {
        $investing_activities['Furniture Purchases'] = -$furniture_change;
    }
    
    $computer_change = getAccountChange($accounting, '1220', $start_date, $end_date);
    if ($computer_change > 0) {
        $investing_activities['Computer Equipment'] = -$computer_change;
    }
    
    $total_investing_cash_flow = array_sum($investing_activities);
    
    // Financing Activities
    $financing_activities = [];
    
    // Loan changes
    $loan_change = getAccountChange($accounting, '2100', $start_date, $end_date);
    if (abs($loan_change) > 0) {
        $financing_activities['Bank Loan'] = $loan_change;
    }
    
    // Owner's capital changes
    $capital_change = getAccountChange($accounting, '3000', $start_date, $end_date);
    if (abs($capital_change) > 0) {
        $financing_activities['Owner\'s Capital'] = $capital_change;
    }
    
    $total_financing_cash_flow = array_sum($financing_activities);
    
    // Net change in cash
    $net_cash_change = $operating_cash_flow + $total_investing_cash_flow + $total_financing_cash_flow;
    
    // Beginning and ending cash balances
    $beginning_cash = getCashBalance($accounting, date('Y-m-d', strtotime($start_date . ' -1 day')));
    $ending_cash = getCashBalance($accounting, $end_date);
    
    return [
        'net_income' => $net_income,
        'working_capital_changes' => $working_capital_changes,
        'total_working_capital_change' => $total_working_capital_change,
        'operating_cash_flow' => $operating_cash_flow,
        'investing_activities' => $investing_activities,
        'total_investing_cash_flow' => $total_investing_cash_flow,
        'financing_activities' => $financing_activities,
        'total_financing_cash_flow' => $total_financing_cash_flow,
        'net_cash_change' => $net_cash_change,
        'beginning_cash' => $beginning_cash,
        'ending_cash' => $ending_cash,
        'start_date' => $start_date,
        'end_date' => $end_date
    ];
}

function getAccountChange($accounting, $account_code, $start_date, $end_date) {
    global $pdo;
    $stmt = $pdo->prepare("\n        SELECT id FROM chart_of_accounts WHERE account_code = ?\n    ");
    $stmt->execute([$account_code]);
    $account = $stmt->fetch();
    
    if (!$account) return 0;
    
    $beginning_balance = $accounting->getAccountBalance($account['id'], date('Y-m-d', strtotime($start_date . ' -1 day')));
    $ending_balance = $accounting->getAccountBalance($account['id'], $end_date);
    
    return $ending_balance - $beginning_balance;
}

function getCashBalance($accounting, $as_of_date) {
    global $pdo;
    $cash_accounts = ['1000', '1010'];
    $total_cash = 0;
    
    foreach ($cash_accounts as $code) {
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
        $stmt->execute([$code]);
        $account = $stmt->fetch();
        
        if ($account) {
            $total_cash += $accounting->getAccountBalance($account['id'], $as_of_date);
        }
    }
    
    return $total_cash;
}

$cash_flow = generateCashFlowStatement($accounting, $start_date, $end_date);

// Handle export formats
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Cash_Flow_Statement_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Cash Flow Statement', 'Amount']);
    
    fputcsv($output, ['OPERATING ACTIVITIES', '']);
    fputcsv($output, ['Net Income', $cash_flow['net_income']]);
    
    foreach ($cash_flow['working_capital_changes'] as $item => $amount) {
        fputcsv($output, [$item, $amount]);
    }
    fputcsv($output, ['Net Cash from Operating Activities', $cash_flow['operating_cash_flow']]);
    
    fputcsv($output, ['', '']);
    fputcsv($output, ['INVESTING ACTIVITIES', '']);
    foreach ($cash_flow['investing_activities'] as $item => $amount) {
        fputcsv($output, [$item, $amount]);
    }
    fputcsv($output, ['Net Cash from Investing Activities', $cash_flow['total_investing_cash_flow']]);
    
    fputcsv($output, ['', '']);
    fputcsv($output, ['FINANCING ACTIVITIES', '']);
    foreach ($cash_flow['financing_activities'] as $item => $amount) {
        fputcsv($output, [$item, $amount]);
    }
    fputcsv($output, ['Net Cash from Financing Activities', $cash_flow['total_financing_cash_flow']]);
    
    fputcsv($output, ['', '']);
    fputcsv($output, ['Net Increase in Cash', $cash_flow['net_cash_change']]);
    fputcsv($output, ['Beginning Cash Balance', $cash_flow['beginning_cash']]);
    fputcsv($output, ['Ending Cash Balance', $cash_flow['ending_cash']]);
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Flow Statement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 15px;
        }
        .financial-section {
            margin-bottom: 25px;
        }
        .section-header {
            background-color: #f8f9fa;
            font-weight: bold;
            border-left: 4px solid #007bff;
        }
        .cash-flow-item {
            padding: 8px 0;
        }
        .total-row {
            font-weight: bold;
            border-top: 2px solid #333;
            background-color: #e9ecef;
        }
        .positive-cash { color: #28a745; }
        .negative-cash { color: #dc3545; }
        
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 no-print">
            <div class="container-fluid">
                <a class="navbar-brand" href="../index.php">
                    <i class="fas fa-stethoscope"></i> Clinic Management
                </a>
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="accounting_dashboard.php">
                        <i class="fas fa-chart-bar"></i> Accounting Dashboard
                    </a>
                    <a class="nav-link" href="../index.php">
                        <i class="fas fa-home"></i> Home
                    </a>
                </div>
            </div>
        </nav>

        <!-- Report Controls -->
        <div class="row mb-4 no-print">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= $start_date ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= $end_date ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> Generate Report
                                    </button>
                                    <div class="dropdown">
                                        <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-download"></i> Export
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&format=csv">
                                                <i class="fas fa-file-csv"></i> Export CSV
                                            </a></li>
                                            <li><a class="dropdown-item" href="#" onclick="window.print()">
                                                <i class="fas fa-print"></i> Print
                                            </a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cash Flow Statement -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="report-header">
                            <h2 class="mb-1">CASH FLOW STATEMENT</h2>
                            <h5 class="text-muted">For the period from <?= date('d M Y', strtotime($start_date)) ?> to <?= date('d M Y', strtotime($end_date)) ?></h5>
                            <p class="text-muted mb-0">Generated on <?= date('d M Y H:i:s') ?></p>
                        </div>

                        <!-- Operating Activities -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="section-header">
                                        <th class="p-3">CASH FLOWS FROM OPERATING ACTIVITIES</th>
                                        <th class="text-end p-3">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="cash-flow-item">
                                        <td>Net Income</td>
                                        <td class="text-end <?= $cash_flow['net_income'] >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <?= number_format($cash_flow['net_income'], 2) ?>
                                        </td>
                                    </tr>
                                    
                                    <?php if (!empty($cash_flow['working_capital_changes'])): ?>
                                    <tr class="table-light">
                                        <td colspan="2"><strong>Adjustments for changes in working capital:</strong></td>
                                    </tr>
                                    <?php foreach ($cash_flow['working_capital_changes'] as $item => $amount): ?>
                                    <tr class="cash-flow-item">
                                        <td class="ps-4"><?= htmlspecialchars($item) ?></td>
                                        <td class="text-end <?= $amount >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <?= number_format($amount, 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td><strong>Net Cash from Operating Activities</strong></td>
                                        <td class="text-end <?= $cash_flow['operating_cash_flow'] >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <strong><?= number_format($cash_flow['operating_cash_flow'], 2) ?></strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Investing Activities -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="section-header">
                                        <th class="p-3">CASH FLOWS FROM INVESTING ACTIVITIES</th>
                                        <th class="text-end p-3">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cash_flow['investing_activities'])): ?>
                                    <tr class="cash-flow-item">
                                        <td class="text-muted">No investing activities during this period</td>
                                        <td class="text-end">-</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($cash_flow['investing_activities'] as $item => $amount): ?>
                                    <tr class="cash-flow-item">
                                        <td><?= htmlspecialchars($item) ?></td>
                                        <td class="text-end <?= $amount >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <?= number_format($amount, 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td><strong>Net Cash from Investing Activities</strong></td>
                                        <td class="text-end <?= $cash_flow['total_investing_cash_flow'] >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <strong><?= number_format($cash_flow['total_investing_cash_flow'], 2) ?></strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Financing Activities -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="section-header">
                                        <th class="p-3">CASH FLOWS FROM FINANCING ACTIVITIES</th>
                                        <th class="text-end p-3">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cash_flow['financing_activities'])): ?>
                                    <tr class="cash-flow-item">
                                        <td class="text-muted">No financing activities during this period</td>
                                        <td class="text-end">-</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($cash_flow['financing_activities'] as $item => $amount): ?>
                                    <tr class="cash-flow-item">
                                        <td><?= htmlspecialchars($item) ?></td>
                                        <td class="text-end <?= $amount >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <?= number_format($amount, 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <tr class="total-row">
                                        <td><strong>Net Cash from Financing Activities</strong></td>
                                        <td class="text-end <?= $cash_flow['total_financing_cash_flow'] >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <strong><?= number_format($cash_flow['total_financing_cash_flow'], 2) ?></strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Net Change in Cash -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <tbody>
                                    <tr class="total-row">
                                        <td><strong>NET INCREASE (DECREASE) IN CASH</strong></td>
                                        <td class="text-end <?= $cash_flow['net_cash_change'] >= 0 ? 'positive-cash' : 'negative-cash' ?>">
                                            <strong><?= number_format($cash_flow['net_cash_change'], 2) ?></strong>
                                        </td>
                                    </tr>
                                    <tr class="cash-flow-item">
                                        <td>Cash at beginning of period</td>
                                        <td class="text-end"><?= number_format($cash_flow['beginning_cash'], 2) ?></td>
                                    </tr>
                                    <tr class="total-row">
                                        <td><strong>CASH AT END OF PERIOD</strong></td>
                                        <td class="text-end">
                                            <strong><?= number_format($cash_flow['ending_cash'], 2) ?></strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Cash Flow Analysis -->
                        <div class="row mt-4 no-print">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-chart-line"></i> Cash Flow Analysis</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <strong>Operating Cash Flow Ratio:</strong><br>
                                            <?= $cash_flow['net_income'] != 0 ? number_format(($cash_flow['operating_cash_flow'] / abs($cash_flow['net_income'])) * 100, 1) . '%' : 'N/A' ?>
                                            <small class="text-muted d-block">Operating Cash Flow / Net Income</small>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Cash Flow Trend:</strong><br>
                                            <?php if ($cash_flow['net_cash_change'] > 0): ?>
                                                <span class="text-success"><i class="fas fa-arrow-up"></i> Positive</span>
                                            <?php elseif ($cash_flow['net_cash_change'] < 0): ?>
                                                <span class="text-danger"><i class="fas fa-arrow-down"></i> Negative</span>
                                            <?php else: ?>
                                                <span class="text-warning"><i class="fas fa-minus"></i> Neutral</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Cash Position:</strong><br>
                                            <?php if ($cash_flow['ending_cash'] > $cash_flow['beginning_cash']): ?>
                                                <span class="text-success">Improved</span>
                                            <?php elseif ($cash_flow['ending_cash'] < $cash_flow['beginning_cash']): ?>
                                                <span class="text-warning">Declined</span>
                                            <?php else: ?>
                                                <span class="text-info">Unchanged</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>