<?php
// setup_gst_system.php - Setup GST Input Credit System
require_once '../includes/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'setup_gst_system') {
            $pdo->beginTransaction();
            
            // Read and execute the GST schema
            $sql_file = 'gst_input_credit_schema.sql';
            if (file_exists($sql_file)) {
                $sql_content = file_get_contents($sql_file);
                
                // Split into individual statements
                $statements = explode(';', $sql_content);
                $executed = 0;
                $failed = 0;
                
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement) && !preg_match('/^--/', $statement)) {
                        try {
                            $pdo->exec($statement);
                            $executed++;
                        } catch (PDOException $e) {
                            $failed++;
                            error_log("GST Setup SQL failed: " . $e->getMessage() . " | Statement: " . substr($statement, 0, 100));
                            
                            // Continue for duplicate/exists errors
                            if (strpos($e->getMessage(), 'Duplicate entry') === false && 
                                strpos($e->getMessage(), 'already exists') === false &&
                                strpos($e->getMessage(), 'Duplicate column') === false) {
                                throw $e;
                            }
                        }
                    }
                }
                
                $pdo->commit();
                $message = "GST Input Credit system setup completed! Executed $executed statements ($failed duplicates/warnings).";
                
            } else {
                throw new Exception('gst_input_credit_schema.sql file not found.');
            }
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Setup failed: ' . $e->getMessage();
    }
}

// Check system status
$tables_status = [];
$required_tables = [
    'purchase_invoices' => 'Purchase Invoices',
    'purchase_invoice_items' => 'Purchase Invoice Items', 
    'gst_input_credit_register' => 'GST Input Credit Register',
    'suppliers' => 'Suppliers Master'
];

foreach ($required_tables as $table => $name) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $tables_status[$table] = [
            'name' => $name,
            'exists' => $stmt->rowCount() > 0
        ];
        
        if ($tables_status[$table]['exists']) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $tables_status[$table]['count'] = $stmt->fetch()['count'];
        }
    } catch (Exception $e) {
        $tables_status[$table] = [
            'name' => $name,
            'exists' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Check new accounts
$new_accounts = [
    '1030' => 'GST Input Credit - CGST',
    '1031' => 'GST Input Credit - SGST', 
    '1032' => 'GST Input Credit - IGST',
    '5010' => 'Purchase - Medicine',
    '4200' => 'Purchase Discount Received',
    '4210' => 'Spot Payment Discount'
];

$accounts_status = [];
foreach ($new_accounts as $code => $name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM chart_of_accounts WHERE account_code = ?");
    $stmt->execute([$code]);
    $accounts_status[$code] = [
        'name' => $name,
        'exists' => $stmt->fetch()['count'] > 0
    ];
}

$all_setup = array_reduce($tables_status, function($carry, $table) {
    return $carry && $table['exists'];
}, true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GST Input Credit System Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .setup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        .status-card {
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-ok { background: #d1edff; border-left: 4px solid #0c5460; }
        .status-missing { background: #f8d7da; border-left: 4px solid #721c24; }
        .status-warning { background: #fff3cd; border-left: 4px solid #856404; }
    </style>
</head>
<body>
    <div class="setup-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-cogs"></i> GST Input Credit System Setup</h1>
                    <p class="mb-0">Initialize the enhanced accounting system with GST input credit management</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="accounting_dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- System Status -->
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-database"></i> Database Tables Status</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($tables_status as $table => $status): ?>
                        <div class="status-card <?= $status['exists'] ? 'status-ok' : 'status-missing' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6><?= $status['name'] ?></h6>
                                    <small class="text-muted">Table: <?= $table ?></small>
                                </div>
                                <div class="text-end">
                                    <?php if ($status['exists']): ?>
                                        <i class="fas fa-check-circle text-success fa-2x"></i>
                                        <?php if (isset($status['count'])): ?>
                                        <br><small><?= $status['count'] ?> records</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle text-danger fa-2x"></i>
                                        <br><small>Not found</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-list"></i> Chart of Accounts Status</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($accounts_status as $code => $status): ?>
                        <div class="status-card <?= $status['exists'] ? 'status-ok' : 'status-missing' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6><?= $status['name'] ?></h6>
                                    <small class="text-muted">Account Code: <?= $code ?></small>
                                </div>
                                <div class="text-end">
                                    <?php if ($status['exists']): ?>
                                        <i class="fas fa-check-circle text-success fa-2x"></i>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle text-danger fa-2x"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-rocket"></i> Setup Actions</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($all_setup): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> 
                            <strong>System Ready!</strong><br>
                            All components are properly set up.
                        </div>
                        
                        <div class="d-grid gap-2">
                            <a href="purchase_invoice_entry.php" class="btn btn-primary">
                                <i class="fas fa-file-invoice"></i> Create Purchase Invoice
                            </a>
                            <a href="enhanced_pharmacy_stock_entry.php" class="btn btn-success">
                                <i class="fas fa-pills"></i> Enhanced Stock Entry
                            </a>
                            <a href="gst_input_credit_reports.php" class="btn btn-info">
                                <i class="fas fa-chart-line"></i> GST Reports
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Setup Required</strong><br>
                            Some components need to be initialized.
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="setup_gst_system">
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play"></i> Initialize GST System
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Features Overview -->
                <div class="card mt-3">
                    <div class="card-header bg-warning text-dark">
                        <h6><i class="fas fa-star"></i> New Features</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success"></i> Purchase Invoice Management</li>
                            <li><i class="fas fa-check text-success"></i> GST Input Credit Tracking</li>
                            <li><i class="fas fa-check text-success"></i> Spot Payment Discounts</li>
                            <li><i class="fas fa-check text-success"></i> Supplier Management</li>
                            <li><i class="fas fa-check text-success"></i> Enhanced Stock Entry</li>
                            <li><i class="fas fa-check text-success"></i> Comprehensive Reports</li>
                            <li><i class="fas fa-check text-success"></i> Accounting Integration</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- What This Setup Does -->
        <div class="card mt-4">
            <div class="card-header bg-dark text-white">
                <h5><i class="fas fa-info-circle"></i> What This Setup Includes</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Database Tables Created:</h6>
                        <ul>
                            <li><strong>purchase_invoices</strong> - Master purchase invoice records</li>
                            <li><strong>purchase_invoice_items</strong> - Line items for each invoice</li>
                            <li><strong>gst_input_credit_register</strong> - GST input credit tracking</li>
                            <li><strong>suppliers</strong> - Supplier master data</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>New Chart of Accounts:</h6>
                        <ul>
                            <li><strong>1030-1032</strong> - GST Input Credit accounts</li>
                            <li><strong>5010-5040</strong> - Purchase accounts by category</li>
                            <li><strong>4200-4210</strong> - Discount received accounts</li>
                            <li><strong>2030</strong> - Trade creditors</li>
                        </ul>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <h6>Key Features:</h6>
                        <ul>
                            <li>Complete purchase invoice management with GST calculations</li>
                            <li>Automatic GST input credit registration and tracking</li>
                            <li>Support for both GST-eligible and non-eligible invoices</li>
                            <li>Spot payment discount handling</li>
                            <li>Integration with existing pharmacy stock management</li>
                            <li>Comprehensive reporting and analytics</li>
                            <li>Double-entry accounting integration</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>