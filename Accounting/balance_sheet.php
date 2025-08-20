<?php
// balance_sheet.php - Balance Sheet Generator
require_once '../includes/db.php';
require_once 'accounting.php';

$accounting = new AccountingSystem($pdo);

// Handle form submissions
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

// Generate Balance Sheet
function generateBalanceSheet($accounting, $as_of_date) {
    // Get Assets, Liabilities, and Equity accounts
    $assets = $accounting->getAccountsByType('ASSET', $as_of_date);
    $liabilities = $accounting->getAccountsByType('LIABILITY', $as_of_date);
    $equity = $accounting->getAccountsByType('EQUITY', $as_of_date);
    
    // Calculate net income for the current year and add to equity
    $fy_dates = getFinancialYearDates();
    $stmt = $accounting->pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN c.account_type = 'REVENUE' THEN jel.credit_amount - jel.debit_amount ELSE 0 END), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN c.account_type = 'EXPENSE' THEN jel.debit_amount - jel.credit_amount ELSE 0 END), 0) as total_expenses
        FROM journal_entries je
        JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
        JOIN chart_of_accounts c ON jel.account_id = c.id
        WHERE je.entry_date BETWEEN ? AND ?
        AND je.status = 'POSTED'
        AND c.account_type IN ('REVENUE', 'EXPENSE')
    ");
    $stmt->execute([$fy_dates['start'], $as_of_date]);
    $income_data = $stmt->fetch();
    
    $net_income = $income_data['total_revenue'] - $income_data['total_expenses'];
    
    // Add current year earnings to equity
    $equity[] = [
        'id' => 'current_earnings',
        'account_code' => '3200',
        'account_name' => 'Current Year Earnings',
        'account_type' => 'EQUITY',
        'account_subtype' => 'Current Earnings',
        'balance' => $net_income,
        'total_debits' => 0,
        'total_credits' => 0
    ];
    
    // Group accounts by subtype
    $grouped_assets = [];
    $grouped_liabilities = [];
    $grouped_equity = [];
    
    foreach ($assets as $asset) {
        if (abs($asset['balance']) > 0.01) {
            $subtype = $asset['account_subtype'] ?: 'Other Assets';
            if (!isset($grouped_assets[$subtype])) {
                $grouped_assets[$subtype] = [];
            }
            $grouped_assets[$subtype][] = $asset;
        }
    }
    
    foreach ($liabilities as $liability) {
        if (abs($liability['balance']) > 0.01) {
            $subtype = $liability['account_subtype'] ?: 'Other Liabilities';
            if (!isset($grouped_liabilities[$subtype])) {
                $grouped_liabilities[$subtype] = [];
            }
            $grouped_liabilities[$subtype][] = $liability;
        }
    }
    
    foreach ($equity as $eq) {
        if (abs($eq['balance']) > 0.01) {
            $subtype = $eq['account_subtype'] ?: 'Capital';
            if (!isset($grouped_equity[$subtype])) {
                $grouped_equity[$subtype] = [];
            }
            $grouped_equity[$subtype][] = $eq;
        }
    }
    
    // Calculate totals
    $total_assets = array_sum(array_column($assets, 'balance')) + $net_income;
    $total_liabilities = array_sum(array_column($liabilities, 'balance'));
    $total_equity = array_sum(array_column($equity, 'balance'));
    
    // Separate current and non-current assets/liabilities
    $current_assets = ['Current Asset'];
    $non_current_assets = ['Fixed Asset'];
    $current_liabilities = ['Current Liability'];
    $non_current_liabilities = ['Long-term Liability'];
    
    return [
        'grouped_assets' => $grouped_assets,
        'grouped_liabilities' => $grouped_liabilities,
        'grouped_equity' => $grouped_equity,
        'total_assets' => $total_assets,
        'total_liabilities' => $total_liabilities,
        'total_equity' => $total_equity,
        'net_income' => $net_income,
        'as_of_date' => $as_of_date,
        'current_assets' => $current_assets,
        'non_current_assets' => $non_current_assets,
        'current_liabilities' => $current_liabilities,
        'non_current_liabilities' => $non_current_liabilities
    ];
}

$balance_sheet = generateBalanceSheet($accounting, $as_of_date);

// Handle export formats
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Balance_Sheet_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Account Code', 'Account Name', 'Amount']);
    
    fputcsv($output, ['', 'ASSETS', '']);
    foreach ($balance_sheet['grouped_assets'] as $subtype => $accounts) {
        fputcsv($output, ['', $subtype, '']);
        foreach ($accounts as $account) {
            fputcsv($output, [$account['account_code'], $account['account_name'], $account['balance']]);
        }
    }
    fputcsv($output, ['', 'Total Assets', $balance_sheet['total_assets']]);
    
    fputcsv($output, ['', '', '']);
    fputcsv($output, ['', 'LIABILITIES', '']);
    foreach ($balance_sheet['grouped_liabilities'] as $subtype => $accounts) {
        fputcsv($output, ['', $subtype, '']);
        foreach ($accounts as $account) {
            fputcsv($output, [$account['account_code'], $account['account_name'], $account['balance']]);
        }
    }
    
    fputcsv($output, ['', 'EQUITY', '']);
    foreach ($balance_sheet['grouped_equity'] as $subtype => $accounts) {
        fputcsv($output, ['', $subtype, '']);
        foreach ($accounts as $account) {
            fputcsv($output, [$account['account_code'], $account['account_name'], $account['balance']]);
        }
    }
    fputcsv($output, ['', 'Total Liabilities & Equity', $balance_sheet['total_liabilities'] + $balance_sheet['total_equity']]);
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet</title>
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
        .subsection-header {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .account-row:hover {
            background-color: #f8f9fa;
        }
        .total-row {
            font-weight: bold;
            border-top: 2px solid #333;
            background-color: #e9ecef;
        }
        .grand-total-row {
            font-weight: bold;
            border-top: 3px double #333;
            background-color: #d1ecf1;
            font-size: 1.1em;
        }
        .balance-check {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .balance-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
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
                <a class="navbar-brand" href="index.php">
                    <i class="fas fa-stethoscope"></i> Clinic Management
                </a>
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="accounting_dashboard.php">
                        <i class="fas fa-chart-bar"></i> Accounting Dashboard
                    </a>
                    <a class="nav-link" href="index.php">
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
                            <div class="col-md-6">
                                <label class="form-label">As of Date</label>
                                <input type="date" class="form-control" name="as_of_date" value="<?= $as_of_date ?>" required>
                            </div>
                            <div class="col-md-6">
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
                                            <li><a class="dropdown-item" href="?as_of_date=<?= $as_of_date ?>&format=csv">
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

        <!-- Balance Sheet -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="report-header">
                            <h2 class="mb-1">BALANCE SHEET</h2>
                            <h5 class="text-muted">As of <?= date('d M Y', strtotime($as_of_date)) ?></h5>
                            <p class="text-muted mb-0">Generated on <?= date('d M Y H:i:s') ?></p>
                        </div>

                        <div class="row">
                            <!-- ASSETS COLUMN -->
                            <div class="col-md-6">
                                <div class="financial-section">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr class="section-header">
                                                <th colspan="2" class="p-3">ASSETS</th>
                                                <th class="text-end p-3">Amount (₹)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $current_asset_total = 0;
                                            $non_current_asset_total = 0;
                                            
                                            foreach ($balance_sheet['grouped_assets'] as $subtype => $accounts): 
                                                $subtype_total = array_sum(array_column($accounts, 'balance'));
                                                if (in_array($subtype, $balance_sheet['current_assets'])) {
                                                    $current_asset_total += $subtype_total;
                                                } else {
                                                    $non_current_asset_total += $subtype_total;
                                                }
                                            ?>
                                            <tr class="subsection-header">
                                                <td colspan="3" class="fw-bold ps-3"><?= htmlspecialchars($subtype) ?></td>
                                            </tr>
                                            <?php foreach ($accounts as $account): ?>
                                            <tr class="account-row">
                                                <td class="ps-4"><?= $account['account_code'] ?></td>
                                                <td><?= htmlspecialchars($account['account_name']) ?></td>
                                                <td class="text-end"><?= number_format(abs($account['balance']), 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-light">
                                                <td colspan="2" class="ps-3"><strong>Total <?= htmlspecialchars($subtype) ?></strong></td>
                                                <td class="text-end"><strong><?= number_format($subtype_total, 2) ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Current Assets Summary -->
                                            <tr class="total-row">
                                                <td colspan="2"><strong>TOTAL CURRENT ASSETS</strong></td>
                                                <td class="text-end"><strong><?= number_format($current_asset_total, 2) ?></strong></td>
                                            </tr>
                                            
                                            <!-- Non-Current Assets Summary -->
                                            <tr class="total-row">
                                                <td colspan="2"><strong>TOTAL NON-CURRENT ASSETS</strong></td>
                                                <td class="text-end"><strong><?= number_format($non_current_asset_total, 2) ?></strong></td>
                                            </tr>
                                            
                                            <!-- Total Assets -->
                                            <tr class="grand-total-row">
                                                <td colspan="2"><strong>TOTAL ASSETS</strong></td>
                                                <td class="text-end"><strong><?= number_format($balance_sheet['total_assets'], 2) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- LIABILITIES & EQUITY COLUMN -->
                            <div class="col-md-6">
                                <!-- LIABILITIES -->
                                <div class="financial-section">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr class="section-header">
                                                <th colspan="2" class="p-3">LIABILITIES</th>
                                                <th class="text-end p-3">Amount (₹)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $current_liability_total = 0;
                                            $non_current_liability_total = 0;
                                            
                                            foreach ($balance_sheet['grouped_liabilities'] as $subtype => $accounts): 
                                                $subtype_total = array_sum(array_column($accounts, 'balance'));
                                                if (in_array($subtype, $balance_sheet['current_liabilities'])) {
                                                    $current_liability_total += $subtype_total;
                                                } else {
                                                    $non_current_liability_total += $subtype_total;
                                                }
                                            ?>
                                            <tr class="subsection-header">
                                                <td colspan="3" class="fw-bold ps-3"><?= htmlspecialchars($subtype) ?></td>
                                            </tr>
                                            <?php foreach ($accounts as $account): ?>
                                            <tr class="account-row">
                                                <td class="ps-4"><?= $account['account_code'] ?></td>
                                                <td><?= htmlspecialchars($account['account_name']) ?></td>
                                                <td class="text-end"><?= number_format(abs($account['balance']), 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-light">
                                                <td colspan="2" class="ps-3"><strong>Total <?= htmlspecialchars($subtype) ?></strong></td>
                                                <td class="text-end"><strong><?= number_format($subtype_total, 2) ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <tr class="total-row">
                                                <td colspan="2"><strong>TOTAL LIABILITIES</strong></td>
                                                <td class="text-end"><strong><?= number_format($balance_sheet['total_liabilities'], 2) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- EQUITY -->
                                <div class="financial-section">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr class="section-header">
                                                <th colspan="2" class="p-3">EQUITY</th>
                                                <th class="text-end p-3">Amount (₹)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($balance_sheet['grouped_equity'] as $subtype => $accounts): ?>
                                            <tr class="subsection-header">
                                                <td colspan="3" class="fw-bold ps-3"><?= htmlspecialchars($subtype) ?></td>
                                            </tr>
                                            <?php foreach ($accounts as $account): ?>
                                            <tr class="account-row">
                                                <td class="ps-4"><?= $account['account_code'] ?></td>
                                                <td><?= htmlspecialchars($account['account_name']) ?></td>
                                                <td class="text-end"><?= number_format(abs($account['balance']), 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endforeach; ?>
                                            
                                            <tr class="total-row">
                                                <td colspan="2"><strong>TOTAL EQUITY</strong></td>
                                                <td class="text-end"><strong><?= number_format($balance_sheet['total_equity'], 2) ?></strong></td>
                                            </tr>
                                            
                                            <!-- Total Liabilities & Equity -->
                                            <tr class="grand-total-row">
                                                <td colspan="2"><strong>TOTAL LIABILITIES & EQUITY</strong></td>
                                                <td class="text-end"><strong><?= number_format($balance_sheet['total_liabilities'] + $balance_sheet['total_equity'], 2) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Balance Check -->
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <?php 
                                $balance_difference = $balance_sheet['total_assets'] - ($balance_sheet['total_liabilities'] + $balance_sheet['total_equity']);
                                $is_balanced = abs($balance_difference) < 0.01;
                                ?>
                                <div class="alert <?= $is_balanced ? 'balance-check' : 'balance-error' ?>">
                                    <h6><i class="fas fa-balance-scale"></i> Balance Check</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong>Total Assets:</strong><br>
                                            ₹ <?= number_format($balance_sheet['total_assets'], 2) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Total Liabilities:</strong><br>
                                            ₹ <?= number_format($balance_sheet['total_liabilities'], 2) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Total Equity:</strong><br>
                                            ₹ <?= number_format($balance_sheet['total_equity'], 2) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Balance Status:</strong><br>
                                            <?php if ($is_balanced): ?>
                                                <span class="text-success"><i class="fas fa-check-circle"></i> Balanced</span>
                                            <?php else: ?>
                                                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Out of Balance by ₹ <?= number_format(abs($balance_difference), 2) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Financial Ratios -->
                        <div class="row mt-4 no-print">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-calculator"></i> Key Financial Ratios</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong>Current Ratio:</strong><br>
                                            <?= $current_liability_total > 0 ? number_format($current_asset_total / $current_liability_total, 2) : 'N/A' ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Debt-to-Equity Ratio:</strong><br>
                                            <?= $balance_sheet['total_equity'] > 0 ? number_format($balance_sheet['total_liabilities'] / $balance_sheet['total_equity'], 2) : 'N/A' ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Equity Ratio:</strong><br>
                                            <?= $balance_sheet['total_assets'] > 0 ? number_format(($balance_sheet['total_equity'] / $balance_sheet['total_assets']) * 100, 2) . '%' : 'N/A' ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Working Capital:</strong><br>
                                            ₹ <?= number_format($current_asset_total - $current_liability_total, 2) ?>
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