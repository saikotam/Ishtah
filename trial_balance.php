<?php
// trial_balance.php - Trial Balance Report Generator
require_once 'includes/db.php';
require_once 'includes/accounting.php';

$accounting = new AccountingSystem($pdo);

// Handle form submissions
$as_of_date = isset($_GET['as_of_date']) ? $_GET['as_of_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'html';
$show_zero_balances = isset($_GET['show_zero_balances']) ? true : false;

// Generate Trial Balance
$trial_balance = $accounting->getTrialBalance($as_of_date);

// Calculate totals
$total_debits = array_sum(array_column($trial_balance, 'debit_balance'));
$total_credits = array_sum(array_column($trial_balance, 'credit_balance'));

// Handle export formats
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Trial_Balance_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Account Code', 'Account Name', 'Account Type', 'Debit Balance', 'Credit Balance']);
    
    foreach ($trial_balance as $account) {
        fputcsv($output, [
            $account['account_code'],
            $account['account_name'],
            $account['account_type'],
            $account['debit_balance'],
            $account['credit_balance']
        ]);
    }
    
    fputcsv($output, ['', 'TOTALS', '', $total_debits, $total_credits]);
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Balance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 15px;
        }
        .account-type-header {
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
        .balance-check {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .balance-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        .account-type-asset { border-left-color: #28a745; }
        .account-type-liability { border-left-color: #dc3545; }
        .account-type-equity { border-left-color: #6f42c1; }
        .account-type-revenue { border-left-color: #17a2b8; }
        .account-type-expense { border-left-color: #fd7e14; }
        
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
                            <div class="col-md-4">
                                <label class="form-label">As of Date</label>
                                <input type="date" class="form-control" name="as_of_date" value="<?= $as_of_date ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Options</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="show_zero_balances" 
                                           <?= $show_zero_balances ? 'checked' : '' ?>>
                                    <label class="form-check-label">
                                        Show accounts with zero balances
                                    </label>
                                </div>
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
                                            <li><a class="dropdown-item" href="?as_of_date=<?= $as_of_date ?>&format=csv<?= $show_zero_balances ? '&show_zero_balances=1' : '' ?>">
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

        <!-- Trial Balance -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="report-header">
                            <h2 class="mb-1">TRIAL BALANCE</h2>
                            <h5 class="text-muted">As of <?= date('d M Y', strtotime($as_of_date)) ?></h5>
                            <p class="text-muted mb-0">Generated on <?= date('d M Y H:i:s') ?></p>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Account Code</th>
                                        <th>Account Name</th>
                                        <th>Account Type</th>
                                        <th class="text-end">Debit Balance (₹)</th>
                                        <th class="text-end">Credit Balance (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_type = '';
                                    $type_totals = [
                                        'ASSET' => ['debit' => 0, 'credit' => 0],
                                        'LIABILITY' => ['debit' => 0, 'credit' => 0],
                                        'EQUITY' => ['debit' => 0, 'credit' => 0],
                                        'REVENUE' => ['debit' => 0, 'credit' => 0],
                                        'EXPENSE' => ['debit' => 0, 'credit' => 0]
                                    ];
                                    
                                    foreach ($trial_balance as $account): 
                                        // Add type totals
                                        $type_totals[$account['account_type']]['debit'] += $account['debit_balance'];
                                        $type_totals[$account['account_type']]['credit'] += $account['credit_balance'];
                                        
                                        // Show type header if changed
                                        if ($current_type !== $account['account_type']):
                                            $current_type = $account['account_type'];
                                    ?>
                                    <tr class="account-type-header account-type-<?= strtolower($account['account_type']) ?>">
                                        <td colspan="5" class="p-3">
                                            <strong><?= $account['account_type'] ?> ACCOUNTS</strong>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="account-row">
                                        <td><?= $account['account_code'] ?></td>
                                        <td><?= htmlspecialchars($account['account_name']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $account['account_type'] === 'ASSET' ? 'success' : 
                                                ($account['account_type'] === 'LIABILITY' ? 'danger' : 
                                                ($account['account_type'] === 'EQUITY' ? 'secondary' : 
                                                ($account['account_type'] === 'REVENUE' ? 'info' : 'warning'))) 
                                            ?> bg-opacity-75">
                                                <?= $account['account_type'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?= $account['debit_balance'] > 0 ? number_format($account['debit_balance'], 2) : '-' ?>
                                        </td>
                                        <td class="text-end">
                                            <?= $account['credit_balance'] > 0 ? number_format($account['credit_balance'], 2) : '-' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Type Subtotals -->
                                    <?php foreach ($type_totals as $type => $totals): ?>
                                    <?php if ($totals['debit'] > 0 || $totals['credit'] > 0): ?>
                                    <tr class="table-light">
                                        <td colspan="3"><strong>Total <?= $type ?> Accounts</strong></td>
                                        <td class="text-end"><strong><?= $totals['debit'] > 0 ? number_format($totals['debit'], 2) : '-' ?></strong></td>
                                        <td class="text-end"><strong><?= $totals['credit'] > 0 ? number_format($totals['credit'], 2) : '-' ?></strong></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                    
                                    <!-- Grand Totals -->
                                    <tr class="total-row">
                                        <td colspan="3"><strong>GRAND TOTALS</strong></td>
                                        <td class="text-end"><strong><?= number_format($total_debits, 2) ?></strong></td>
                                        <td class="text-end"><strong><?= number_format($total_credits, 2) ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Balance Check -->
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <?php 
                                $balance_difference = $total_debits - $total_credits;
                                $is_balanced = abs($balance_difference) < 0.01;
                                ?>
                                <div class="alert <?= $is_balanced ? 'balance-check' : 'balance-error' ?>">
                                    <h6><i class="fas fa-balance-scale"></i> Balance Verification</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <strong>Total Debits:</strong><br>
                                            ₹ <?= number_format($total_debits, 2) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Total Credits:</strong><br>
                                            ₹ <?= number_format($total_credits, 2) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Difference:</strong><br>
                                            ₹ <?= number_format(abs($balance_difference), 2) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Status:</strong><br>
                                            <?php if ($is_balanced): ?>
                                                <span class="text-success"><i class="fas fa-check-circle"></i> Balanced</span>
                                            <?php else: ?>
                                                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Out of Balance</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Summary Statistics -->
                        <div class="row mt-4 no-print">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-chart-bar"></i> Summary Statistics</h6>
                                    <div class="row">
                                        <div class="col-md-2">
                                            <strong>Total Accounts:</strong><br>
                                            <?= count($trial_balance) ?>
                                        </div>
                                        <div class="col-md-2">
                                            <strong>Asset Accounts:</strong><br>
                                            <?= count(array_filter($trial_balance, function($a) { return $a['account_type'] === 'ASSET'; })) ?>
                                        </div>
                                        <div class="col-md-2">
                                            <strong>Liability Accounts:</strong><br>
                                            <?= count(array_filter($trial_balance, function($a) { return $a['account_type'] === 'LIABILITY'; })) ?>
                                        </div>
                                        <div class="col-md-2">
                                            <strong>Equity Accounts:</strong><br>
                                            <?= count(array_filter($trial_balance, function($a) { return $a['account_type'] === 'EQUITY'; })) ?>
                                        </div>
                                        <div class="col-md-2">
                                            <strong>Revenue Accounts:</strong><br>
                                            <?= count(array_filter($trial_balance, function($a) { return $a['account_type'] === 'REVENUE'; })) ?>
                                        </div>
                                        <div class="col-md-2">
                                            <strong>Expense Accounts:</strong><br>
                                            <?= count(array_filter($trial_balance, function($a) { return $a['account_type'] === 'EXPENSE'; })) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Account Analysis -->
                        <div class="row mt-4 no-print">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6><i class="fas fa-chart-pie"></i> Largest Debit Balances</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <?php 
                                                $top_debits = array_filter($trial_balance, function($a) { return $a['debit_balance'] > 0; });
                                                usort($top_debits, function($a, $b) { return $b['debit_balance'] <=> $a['debit_balance']; });
                                                $top_debits = array_slice($top_debits, 0, 5);
                                                ?>
                                                <?php foreach ($top_debits as $account): ?>
                                                <tr>
                                                    <td><?= $account['account_code'] ?></td>
                                                    <td><?= htmlspecialchars($account['account_name']) ?></td>
                                                    <td class="text-end">₹ <?= number_format($account['debit_balance'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6><i class="fas fa-chart-pie"></i> Largest Credit Balances</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <?php 
                                                $top_credits = array_filter($trial_balance, function($a) { return $a['credit_balance'] > 0; });
                                                usort($top_credits, function($a, $b) { return $b['credit_balance'] <=> $a['credit_balance']; });
                                                $top_credits = array_slice($top_credits, 0, 5);
                                                ?>
                                                <?php foreach ($top_credits as $account): ?>
                                                <tr>
                                                    <td><?= $account['account_code'] ?></td>
                                                    <td><?= htmlspecialchars($account['account_name']) ?></td>
                                                    <td class="text-end">₹ <?= number_format($account['credit_balance'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </table>
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