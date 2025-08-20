<?php
// setup_accounting.php - Initialize Accounting System Database
require_once '../includes/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Read and execute the SQL schema
        $sql_file = 'accounting_schema.sql';
        if (file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            
            // Split into individual statements
            $statements = explode(';', $sql_content);
            
            $pdo->beginTransaction();
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                    try {
                        $pdo->exec($statement);
                    } catch (PDOException $e) {
                        // Log but continue - some statements might fail if tables already exist
                        error_log("SQL Statement failed: " . $e->getMessage());
                    }
                }
            }
            
            $pdo->commit();
            $message = 'Accounting system database has been successfully initialized!';
            
        } else {
            throw new Exception('accounting_schema.sql file not found.');
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error initializing database: ' . $e->getMessage();
    }
}

// Check if tables exist
$tables_exist = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'chart_of_accounts'");
    $tables_exist = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Ignore error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting System Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="fas fa-database"></i> Accounting System Setup</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($tables_exist): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Accounting system appears to be already set up.
                        </div>
                        <?php endif; ?>

                        <h5>What this setup will do:</h5>
                        <ul>
                            <li>Create accounting database tables (Chart of Accounts, Journal Entries, etc.)</li>
                            <li>Initialize default chart of accounts for a medical clinic</li>
                            <li>Set up financial periods</li>
                            <li>Configure account balances tracking</li>
                        </ul>

                        <h6 class="mt-4">Default Accounts Included:</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Assets:</strong>
                                <ul class="small">
                                    <li>Cash in Hand</li>
                                    <li>Cash at Bank</li>
                                    <li>Accounts Receivable</li>
                                    <li>Inventory - Pharmacy</li>
                                    <li>Medical Equipment</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <strong>Revenue:</strong>
                                <ul class="small">
                                    <li>Consultation Revenue</li>
                                    <li>Pharmacy Sales</li>
                                    <li>Laboratory Revenue</li>
                                    <li>Ultrasound Revenue</li>
                                </ul>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <strong>Liabilities:</strong>
                                <ul class="small">
                                    <li>Accounts Payable</li>
                                    <li>GST Payable</li>
                                    <li>TDS Payable</li>
                                    <li>Bank Loan</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <strong>Expenses:</strong>
                                <ul class="small">
                                    <li>Doctor Fees</li>
                                    <li>Staff Salaries</li>
                                    <li>Rent Expense</li>
                                    <li>Medical Supplies</li>
                                    <li>And more...</li>
                                </ul>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <?php if (!$tables_exist): ?>
                            <form method="POST" style="display: inline;">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play"></i> Initialize Accounting System
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display: inline;">
                                <button type="submit" class="btn btn-warning btn-lg" onclick="return confirm('This will recreate the accounting tables. Are you sure?')">
                                    <i class="fas fa-redo"></i> Reinitialize System
                                </button>
                            </form>
                            <?php endif; ?>
                            
                            <a href="accounting_dashboard.php" class="btn btn-success btn-lg ms-3">
                                <i class="fas fa-chart-bar"></i> Go to Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6><i class="fas fa-link"></i> Quick Links</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <a href="accounting_dashboard.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                                    <i class="fas fa-tachometer-alt"></i> Accounting Dashboard
                                </a>
                                <a href="chart_of_accounts.php" class="btn btn-outline-info btn-sm mb-2 w-100">
                                    <i class="fas fa-list"></i> Chart of Accounts
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="../admin.php" class="btn btn-outline-secondary btn-sm mb-2 w-100">
                                    <i class="fas fa-cog"></i> Admin Panel
                                </a>
                                <a href="../index.php" class="btn btn-outline-dark btn-sm mb-2 w-100">
                                    <i class="fas fa-home"></i> Main System
                                </a>
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