<?php
// profit_loss_statement.php - Profit & Loss Statement Generator
require_once 'includes/db.php';
require_once 'includes/accounting.php';

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

// Generate P&L Statement
function generateProfitLossStatement($accounting, $start_date, $end_date) {
    $stmt = $accounting->pdo->prepare("
        SELECT 
            c.id,
            c.account_code,
            c.account_name,
            c.account_type,
            c.account_subtype,
            c.normal_balance,
            COALESCE(SUM(jel.debit_amount), 0) as total_debits,
            COALESCE(SUM(jel.credit_amount), 0) as total_credits
        FROM chart_of_accounts c
        LEFT JOIN journal_entry_lines jel ON c.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE c.account_type IN ('REVENUE', 'EXPENSE')
        AND c.is_active = 1
        AND je.entry_date BETWEEN ? AND ?
        AND je.status = 'POSTED'
        GROUP BY c.id, c.account_code, c.account_name, c.account_type, c.account_subtype, c.normal_balance
        HAVING (total_debits + total_credits) > 0
        ORDER BY c.account_type DESC, c.account_code
    ");
    $stmt->execute([$start_date, $end_date]);
    $accounts = $stmt->fetchAll();
    
    $revenue_accounts = [];
    $expense_accounts = [];
    $total_revenue = 0;
    $total_expenses = 0;
    
    foreach ($accounts as $account) {
        $net_balance = $account['total_debits'] - $account['total_credits'];
        $balance = ($account['normal_balance'] === 'DEBIT') ? $net_balance : -$net_balance;
        
        $account_data = [
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'account_subtype' => $account['account_subtype'],
            'balance' => abs($balance),
            'total_debits' => $account['total_debits'],
            'total_credits' => $account['total_credits']
        ];
        
        if ($account['account_type'] === 'REVENUE') {
            $revenue_accounts[] = $account_data;
            $total_revenue += abs($balance);
        } else {
            $expense_accounts[] = $account_data;
            $total_expenses += abs($balance);
        }
    }
    
    // Group expenses by subtype
    $grouped_expenses = [];
    foreach ($expense_accounts as $expense) {
        $subtype = $expense['account_subtype'] ?: 'Other Expenses';
        if (!isset($grouped_expenses[$subtype])) {
            $grouped_expenses[$subtype] = [];
        }
        $grouped_expenses[$subtype][] = $expense;
    }
    
    return [
        'revenue_accounts' => $revenue_accounts,
        'expense_accounts' => $expense_accounts,
        'grouped_expenses' => $grouped_expenses,
        'total_revenue' => $total_revenue,
        'total_expenses' => $total_expenses,
        'net_income' => $total_revenue - $total_expenses,
        'start_date' => $start_date,
        'end_date' => $end_date
    ];
}

$pl_statement = generateProfitLossStatement($accounting, $start_date, $end_date);

// Handle export formats
if ($format === 'pdf') {
    // PDF export (requires TCPDF or similar library)
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="PL_Statement_' . date('Y-m-d') . '.pdf"');
    // PDF generation code would go here
    exit;
} elseif ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="PL_Statement_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Account Code', 'Account Name', 'Amount']);
    
    fputcsv($output, ['', 'REVENUE', '']);
    foreach ($pl_statement['revenue_accounts'] as $account) {
        fputcsv($output, [$account['account_code'], $account['account_name'], $account['balance']]);
    }
    fputcsv($output, ['', 'Total Revenue', $pl_statement['total_revenue']]);
    
    fputcsv($output, ['', '', '']);
    fputcsv($output, ['', 'EXPENSES', '']);
    foreach ($pl_statement['expense_accounts'] as $account) {
        fputcsv($output, [$account['account_code'], $account['account_name'], $account['balance']]);
    }
    fputcsv($output, ['', 'Total Expenses', $pl_statement['total_expenses']]);
    
    fputcsv($output, ['', '', '']);
    fputcsv($output, ['', 'Net Income', $pl_statement['net_income']]);
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Statement</title>
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
        .account-row:hover {
            background-color: #f8f9fa;
        }
        .total-row {
            font-weight: bold;
            border-top: 2px solid #333;
            background-color: #e9ecef;
        }
        .net-income-positive {
            color: #28a745;
            font-weight: bold;
        }
        .net-income-negative {
            color: #dc3545;
            font-weight: bold;
        }
        .print-hide {
            display: none;
        }
        @media print {
            .no-print { display: none !important; }
            .print-hide { display: block !important; }
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

        <!-- P&L Statement -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="report-header">
                            <h2 class="mb-1">PROFIT & LOSS STATEMENT</h2>
                            <h5 class="text-muted">For the period from <?= date('d M Y', strtotime($start_date)) ?> to <?= date('d M Y', strtotime($end_date)) ?></h5>
                            <p class="print-hide text-muted mb-0">Generated on <?= date('d M Y H:i:s') ?></p>
                        </div>

                        <!-- REVENUE SECTION -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="section-header">
                                        <th colspan="2" class="p-3">REVENUE</th>
                                        <th class="text-end p-3">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pl_statement['revenue_accounts'] as $account): ?>
                                    <tr class="account-row">
                                        <td><?= $account['account_code'] ?></td>
                                        <td><?= htmlspecialchars($account['account_name']) ?></td>
                                        <td class="text-end"><?= number_format($account['balance'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>TOTAL REVENUE</strong></td>
                                        <td class="text-end"><strong><?= number_format($pl_statement['total_revenue'], 2) ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- EXPENSES SECTION -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="section-header">
                                        <th colspan="2" class="p-3">EXPENSES</th>
                                        <th class="text-end p-3">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pl_statement['grouped_expenses'] as $subtype => $expenses): ?>
                                    <tr class="table-secondary">
                                        <td colspan="3" class="fw-bold ps-3"><?= htmlspecialchars($subtype) ?></td>
                                    </tr>
                                    <?php foreach ($expenses as $expense): ?>
                                    <tr class="account-row">
                                        <td class="ps-4"><?= $expense['account_code'] ?></td>
                                        <td><?= htmlspecialchars($expense['account_name']) ?></td>
                                        <td class="text-end"><?= number_format($expense['balance'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>TOTAL EXPENSES</strong></td>
                                        <td class="text-end"><strong><?= number_format($pl_statement['total_expenses'], 2) ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- NET INCOME -->
                        <div class="financial-section">
                            <table class="table table-sm">
                                <tbody>
                                    <tr class="table-light">
                                        <td colspan="2"><strong>GROSS PROFIT</strong></td>
                                        <td class="text-end"><strong><?= number_format($pl_statement['total_revenue'] - array_sum(array_map(function($e) { 
                                            return ($e['account_subtype'] === 'Direct Cost') ? $e['balance'] : 0; 
                                        }, $pl_statement['expense_accounts'])), 2) ?></strong></td>
                                    </tr>
                                    <tr class="total-row <?= $pl_statement['net_income'] >= 0 ? 'net-income-positive' : 'net-income-negative' ?>">
                                        <td colspan="2" class="fs-5">
                                            <strong><?= $pl_statement['net_income'] >= 0 ? 'NET PROFIT' : 'NET LOSS' ?></strong>
                                        </td>
                                        <td class="text-end fs-5">
                                            <strong><?= number_format(abs($pl_statement['net_income']), 2) ?></strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary Ratios -->
                        <div class="row mt-4 no-print">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-chart-pie"></i> Key Financial Ratios</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong>Gross Profit Margin:</strong><br>
                                            <?php 
                                            $gross_profit = $pl_statement['total_revenue'] - array_sum(array_map(function($e) { 
                                                return ($e['account_subtype'] === 'Direct Cost') ? $e['balance'] : 0; 
                                            }, $pl_statement['expense_accounts']));
                                            echo $pl_statement['total_revenue'] > 0 ? number_format(($gross_profit / $pl_statement['total_revenue']) * 100, 2) . '%' : '0%';
                                            ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Net Profit Margin:</strong><br>
                                            <?= $pl_statement['total_revenue'] > 0 ? number_format(($pl_statement['net_income'] / $pl_statement['total_revenue']) * 100, 2) . '%' : '0%' ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Expense Ratio:</strong><br>
                                            <?= $pl_statement['total_revenue'] > 0 ? number_format(($pl_statement['total_expenses'] / $pl_statement['total_revenue']) * 100, 2) . '%' : '0%' ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Revenue Growth:</strong><br>
                                            <span class="text-muted">Compared to previous period</span>
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