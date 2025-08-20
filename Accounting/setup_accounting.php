<?php
// setup_accounting.php - Initialize Accounting System Database
require_once '../includes/db.php';

$message = '';
$error = '';

/**
 * Check if accounting system is properly initialized
 */
function isAccountingSystemInitialized($pdo) {
    try {
        // Check if all required tables exist
        $required_tables = ['chart_of_accounts', 'journal_entries', 'journal_entry_lines', 'account_balances', 'financial_periods'];
        
        foreach ($required_tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                return false;
            }
        }
        
        // Check if chart of accounts has been populated
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM chart_of_accounts");
        $account_count = $stmt->fetch()['count'];
        if ($account_count < 20) { // Should have at least 20 default accounts
            return false;
        }
        
        // Check if financial periods exist
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM financial_periods");
        $period_count = $stmt->fetch()['count'];
        if ($period_count === 0) {
            return false;
        }
        
        // All checks passed
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Initialize the accounting system database
 */
function initializeAccountingSystem($pdo) {
    $sql_file = 'accounting_schema.sql';
    if (!file_exists($sql_file)) {
        throw new Exception('accounting_schema.sql file not found.');
    }
    
    $sql_content = file_get_contents($sql_file);
    
    // Split into individual statements
    $statements = explode(';', $sql_content);
    
    $pdo->beginTransaction();
    
    $executed_statements = 0;
    $failed_statements = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $pdo->exec($statement);
                $executed_statements++;
            } catch (PDOException $e) {
                $failed_statements++;
                // Log the error but continue
                error_log("SQL Statement failed: " . $e->getMessage() . " | Statement: " . substr($statement, 0, 100));
                
                // If it's a critical error (not just duplicate entry), throw exception
                if (strpos($e->getMessage(), 'Duplicate entry') === false && 
                    strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }
    
    $pdo->commit();
    
    // Verify initialization was successful
    if (!isAccountingSystemInitialized($pdo)) {
        throw new Exception('Initialization completed but system verification failed. Please check the database.');
    }
    
    return [
        'executed' => $executed_statements,
        'failed' => $failed_statements
    ];
}

// Check current initialization status
$is_initialized = isAccountingSystemInitialized($pdo);

// Handle POST request for initialization
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'initialize') {
        try {
            $result = initializeAccountingSystem($pdo);
            $message = "Accounting system has been successfully initialized! Executed {$result['executed']} statements.";
            $is_initialized = true;
            
            // Redirect to dashboard after successful initialization
            header('Location: accounting_dashboard.php?initialized=1');
            exit();
            
        } catch (Exception $e) {
            $error = 'Error initializing database: ' . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'reinitialize') {
        try {
            $result = initializeAccountingSystem($pdo);
            $message = "Accounting system has been reinitialized! Executed {$result['executed']} statements.";
            $is_initialized = true;
            
        } catch (Exception $e) {
            $error = 'Error reinitializing database: ' . $e->getMessage();
        }
    }
}

// If system is already initialized and no POST action, redirect to dashboard
if ($is_initialized && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accounting_dashboard.php');
    exit();
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

                        <?php if ($is_initialized): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <strong>System Status:</strong> Accounting system is properly initialized and ready to use.
                            <hr>
                            <p class="mb-0">All required tables and default data are in place. You can proceed to the dashboard or reinitialize if needed.</p>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> <strong>System Status:</strong> Accounting system needs to be initialized.
                            <hr>
                            <p class="mb-0">The system is not properly set up. Please run the initialization process below.</p>
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
                            <?php if (!$is_initialized): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="initialize">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play"></i> Initialize Accounting System
                                </button>
                            </form>
                            <div class="mt-2">
                                <small class="text-muted">This will create all necessary tables and populate default data.</small>
                            </div>
                            <?php else: ?>
                            <a href="accounting_dashboard.php" class="btn btn-success btn-lg">
                                <i class="fas fa-chart-bar"></i> Go to Dashboard
                            </a>
                            <form method="POST" style="display: inline;" class="ms-3">
                                <input type="hidden" name="action" value="reinitialize">
                                <button type="submit" class="btn btn-warning btn-lg" onclick="return confirm('This will recreate the accounting tables and reset all data. Are you sure?')">
                                    <i class="fas fa-redo"></i> Reinitialize System
                                </button>
                            </form>
                            <div class="mt-2">
                                <small class="text-muted">System is already initialized. Use reinitialize only if you need to reset the system.</small>
                            </div>
                            <?php endif; ?>
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
    <script>
        // Add loading state to initialization buttons
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form[method="POST"]');
            
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = form.querySelector('button[type="submit"]');
                    if (button) {
                        const originalText = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        button.disabled = true;
                        
                        // Re-enable button after 30 seconds as failsafe
                        setTimeout(() => {
                            button.innerHTML = originalText;
                            button.disabled = false;
                        }, 30000);
                    }
                });
            });
        });
    </script>
</body>
</html>