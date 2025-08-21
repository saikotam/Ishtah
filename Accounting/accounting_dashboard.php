<?php
// accounting_dashboard.php - Main Accounting Dashboard
require_once '../includes/db.php';
require_once 'accounting.php';
require_once 'sync_accounting.php';

$accounting = new AccountingSystem($pdo);
// Optional on-demand sync from operational data
$sync_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_operational') {
    try {
        $result = syncAccountingFromOperationalData($pdo);
        $sync_message = "Synced: Pharmacy {$result['pharmacy']}, Lab {$result['lab']}, Ultrasound {$result['ultrasound']}.";
    } catch (Exception $e) {
        $sync_message = 'Sync failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Check if user was redirected from successful initialization
$initialization_success = isset($_GET['initialized']) && $_GET['initialized'] == '1';

// Check if system is properly initialized, redirect to setup if not
function isAccountingSystemInitialized($pdo) {
    try {
        $required_tables = ['chart_of_accounts', 'journal_entries', 'journal_entry_lines', 'account_balances', 'financial_periods'];
        
        foreach ($required_tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                return false;
            }
        }
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM chart_of_accounts");
        $account_count = $stmt->fetch()['count'];
        if ($account_count < 20) {
            return false;
        }
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM financial_periods");
        $period_count = $stmt->fetch()['count'];
        if ($period_count === 0) {
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

if (!isAccountingSystemInitialized($pdo)) {
    // Try to auto-initialize first
    try {
        require_once 'sync_accounting.php';
        ensureAccountingTablesExist($pdo);
        
        // Check again after auto-initialization
        if (!isAccountingSystemInitialized($pdo)) {
            header('Location: setup_accounting.php?auto_init=1');
            exit();
        }
    } catch (Exception $e) {
        header('Location: setup_accounting.php?error=' . urlencode($e->getMessage()));
        exit();
    }
}

// Get current financial year
$current_fy = getFinancialYear();
$fy_dates = getFinancialYearDates();

// Get quick stats
$today = date('Y-m-d');

// Revenue for current month
$current_month_start = date('Y-m-01');
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0) as monthly_revenue
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    JOIN chart_of_accounts c ON jel.account_id = c.id
    WHERE c.account_type = 'REVENUE'
    AND je.entry_date BETWEEN ? AND ?
    AND je.status = 'POSTED'
");
$stmt->execute([$current_month_start, $today]);
$monthly_revenue = $stmt->fetch()['monthly_revenue'] ?? 0;

// Total cash position
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) as cash_position
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    JOIN chart_of_accounts c ON jel.account_id = c.id
    WHERE c.account_code IN ('1000', '1010')
    AND je.entry_date <= ?
    AND je.status = 'POSTED'
");
$stmt->execute([$today]);
$cash_position = $stmt->fetch()['cash_position'] ?? 0;

// Net income YTD
$stmt = $pdo->prepare("
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
$stmt->execute([$fy_dates['start'], $today]);
$income_data = $stmt->fetch();
$ytd_revenue = $income_data['total_revenue'] ?? 0;
$ytd_expenses = $income_data['total_expenses'] ?? 0;
$ytd_net_income = $ytd_revenue - $ytd_expenses;

// Recent journal entries
$stmt = $pdo->prepare("
    SELECT je.*, COUNT(jel.id) as line_count
    FROM journal_entries je
    LEFT JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    GROUP BY je.id
    ORDER BY je.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_entries = $stmt->fetchAll();

// Monthly revenue trend (last 6 months)
$monthly_trend = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end = date('Y-m-t', strtotime("-$i months"));
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0) as revenue
        FROM journal_entries je
        JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
        JOIN chart_of_accounts c ON jel.account_id = c.id
        WHERE c.account_type = 'REVENUE'
        AND je.entry_date BETWEEN ? AND ?
        AND je.status = 'POSTED'
    ");
    $stmt->execute([$month_start, $month_end]);
    $revenue = $stmt->fetch()['revenue'] ?? 0;
    
    $monthly_trend[] = [
        'month' => date('M Y', strtotime($month_start)),
        'revenue' => $revenue
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-card {
            transition: transform 0.2s;
        }
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-card.revenue {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .stat-card.cash {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .stat-card.profit {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .report-link {
            text-decoration: none;
            color: inherit;
        }
        .report-link:hover {
            color: inherit;
            text-decoration: none;
        }
        .quick-action {
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }
        .quick-action:hover {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="../index.php">
                    <i class="fas fa-stethoscope"></i> Clinic Management
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <div class="navbar-nav ms-auto">
                        <a class="nav-link" href="../index.php">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a class="nav-link" href="../summary.php">
                            <i class="fas fa-chart-line"></i> Daily Summary
                        </a>
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="chart_of_accounts.php">Chart of Accounts</a></li>
                                <li><a class="dropdown-item" href="journal_entries.php">Journal Entries</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Dashboard Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <?php if ($initialization_success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <strong>Welcome to the Accounting System!</strong> 
                    The system has been successfully initialized and is ready to use.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if (!empty($sync_message)): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <i class="fas fa-sync-alt"></i> <?= $sync_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <h2><i class="fas fa-chart-bar text-primary"></i> Accounting Dashboard</h2>
                <p class="text-muted">Financial Year: <?= $current_fy ?> | Last Updated: <?= date('d M Y H:i:s') ?></p>
            </div>
        </div>

        <!-- Key Performance Indicators -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card dashboard-card stat-card revenue">
                    <div class="card-body text-center">
                        <i class="fas fa-rupee-sign fa-3x mb-3"></i>
                        <h3>₹ <?= number_format($monthly_revenue, 0) ?></h3>
                        <p class="mb-0">Monthly Revenue</p>
                        <small><?= date('M Y') ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card dashboard-card stat-card cash">
                    <div class="card-body text-center">
                        <i class="fas fa-wallet fa-3x mb-3"></i>
                        <h3>₹ <?= number_format($cash_position, 0) ?></h3>
                        <p class="mb-0">Cash Position</p>
                        <small>As of today</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card dashboard-card stat-card profit">
                    <div class="card-body text-center">
                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                        <h3>₹ <?= number_format($ytd_net_income, 0) ?></h3>
                        <p class="mb-0">Net Income YTD</p>
                        <small><?= $current_fy ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card dashboard-card stat-card">
                    <div class="card-body text-center">
                        <i class="fas fa-percentage fa-3x mb-3"></i>
                        <h3><?= $ytd_revenue > 0 ? number_format(($ytd_net_income / $ytd_revenue) * 100, 1) : 0 ?>%</h3>
                        <p class="mb-0">Profit Margin</p>
                        <small>Year to Date</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Reports -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card dashboard-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-file-alt"></i> Financial Reports</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="sync_operational">
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="fas fa-sync-alt"></i> Sync from operational data
                                </button>
                            </form>
                            <small class="text-muted ms-2">Use this if recent bills are not yet reflected in reports.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <a href="profit_loss_statement.php" class="report-link">
                                    <div class="card quick-action h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-chart-line fa-2x text-success mb-3"></i>
                                            <h6>Profit & Loss Statement</h6>
                                            <p class="text-muted small">View revenue, expenses, and net income</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="balance_sheet.php" class="report-link">
                                    <div class="card quick-action h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-balance-scale fa-2x text-primary mb-3"></i>
                                            <h6>Balance Sheet</h6>
                                            <p class="text-muted small">Assets, liabilities, and equity</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="trial_balance.php" class="report-link">
                                    <div class="card quick-action h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-list-alt fa-2x text-info mb-3"></i>
                                            <h6>Trial Balance</h6>
                                            <p class="text-muted small">All account balances summary</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="cash_flow_statement.php" class="report-link">
                                    <div class="card quick-action h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-exchange-alt fa-2x text-warning mb-3"></i>
                                            <h6>Cash Flow Statement</h6>
                                            <p class="text-muted small">Cash inflows and outflows</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Recent Activity -->
        <div class="row mb-4">
            <!-- Revenue Trend Chart -->
            <div class="col-md-8">
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h6><i class="fas fa-chart-area"></i> Monthly Revenue Trend</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart" height="100"></canvas>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-md-4">
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h6><i class="fas fa-bolt"></i> Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary" onclick="window.location.href='journal_entries.php'">
                                <i class="fas fa-plus"></i> New Journal Entry
                            </button>
                            <button class="btn btn-outline-success" onclick="window.location.href='profit_loss_statement.php'">
                                <i class="fas fa-file-alt"></i> Generate P&L
                            </button>
                            <button class="btn btn-outline-info" onclick="window.location.href='balance_sheet.php'">
                                <i class="fas fa-balance-scale"></i> Generate Balance Sheet
                            </button>
                            <button class="btn btn-outline-warning" onclick="window.location.href='trial_balance.php'">
                                <i class="fas fa-list"></i> View Trial Balance
                            </button>
                            <hr>
                            <small class="text-muted"><strong>GST Input Credit System</strong></small>
                            <button class="btn btn-outline-primary btn-sm mt-1" onclick="window.location.href='purchase_invoice_entry.php'">
                                <i class="fas fa-file-invoice"></i> Purchase Invoice Entry
                            </button>
                            <button class="btn btn-outline-success btn-sm" onclick="window.location.href='enhanced_pharmacy_stock_entry.php'">
                                <i class="fas fa-pills"></i> Stock Entry
                            </button>
                            <button class="btn btn-outline-info btn-sm" onclick="window.location.href='gst_input_credit_reports.php'">
                                <i class="fas fa-chart-line"></i> GST Reports
                            </button>
                            <button class="btn btn-outline-dark btn-sm" onclick="window.location.href='setup_gst_system.php'">
                                <i class="fas fa-cogs"></i> GST System Setup
                            </button>
                            <hr>
                            <button class="btn btn-outline-secondary" onclick="window.location.href='chart_of_accounts.php'">
                                <i class="fas fa-cog"></i> Manage Accounts
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Journal Entries -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card dashboard-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-history"></i> Recent Journal Entries</h6>
                        <a href="journal_entries.php" class="btn btn-sm btn-primary">View All</a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Entry #</th>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Type</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_entries)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            No journal entries found. <a href="journal_entries.php">Create your first entry</a>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($recent_entries as $entry): ?>
                                    <tr>
                                        <td><?= $entry['entry_number'] ?></td>
                                        <td><?= date('d M Y', strtotime($entry['entry_date'])) ?></td>
                                        <td><?= htmlspecialchars(substr($entry['description'], 0, 50)) ?><?= strlen($entry['description']) > 50 ? '...' : '' ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $entry['reference_type'] === 'CONSULTATION' ? 'primary' : 
                                                ($entry['reference_type'] === 'PHARMACY' ? 'success' : 
                                                ($entry['reference_type'] === 'LAB' ? 'info' : 
                                                ($entry['reference_type'] === 'ULTRASOUND' ? 'warning' : 'secondary'))) 
                                            ?>">
                                                <?= $entry['reference_type'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end">₹ <?= number_format($entry['total_debit'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $entry['status'] === 'POSTED' ? 'success' : 'warning' ?>">
                                                <?= $entry['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Health Indicators -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card dashboard-card">
                    <div class="card-header">
                        <h6><i class="fas fa-heartbeat"></i> Financial Health Indicators</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="progress mb-2" style="height: 20px;">
                                        <div class="progress-bar bg-success" style="width: <?= min(100, ($cash_position / 100000) * 100) ?>%"></div>
                                    </div>
                                    <small><strong>Cash Reserves</strong><br>₹ <?= number_format($cash_position, 0) ?> / ₹ 1,00,000 target</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="progress mb-2" style="height: 20px;">
                                        <div class="progress-bar bg-info" style="width: <?= min(100, abs(($ytd_net_income / $ytd_revenue) * 100)) ?>%"></div>
                                    </div>
                                    <small><strong>Profit Margin</strong><br><?= $ytd_revenue > 0 ? number_format(($ytd_net_income / $ytd_revenue) * 100, 1) : 0 ?>%</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="progress mb-2" style="height: 20px;">
                                        <div class="progress-bar bg-warning" style="width: <?= min(100, ($ytd_expenses / ($ytd_revenue ?: 1)) * 100) ?>%"></div>
                                    </div>
                                    <small><strong>Expense Ratio</strong><br><?= $ytd_revenue > 0 ? number_format(($ytd_expenses / $ytd_revenue) * 100, 1) : 0 ?>%</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="progress mb-2" style="height: 20px;">
                                        <div class="progress-bar bg-primary" style="width: <?= min(100, ($monthly_revenue / 50000) * 100) ?>%"></div>
                                    </div>
                                    <small><strong>Monthly Target</strong><br>₹ <?= number_format($monthly_revenue, 0) ?> / ₹ 50,000</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Revenue Trend Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($monthly_trend, 'month')) ?>,
                datasets: [{
                    label: 'Monthly Revenue (₹)',
                    data: <?= json_encode(array_column($monthly_trend, 'revenue')) ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹ ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>