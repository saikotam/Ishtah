<?php
// bank_statement_upload.php - Bank Statement Upload and Processing
require_once '../includes/db.php';
require_once 'accounting.php';

$accounting = new AccountingSystem($pdo);
$message = '';
$error = '';
$processed_transactions = [];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bank_statement'])) {
    try {
        $upload_dir = '../uploads/bank_statements/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file = $_FILES['bank_statement'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Validate file type
        if (!in_array($file_extension, ['xlsx', 'xls', 'csv'])) {
            throw new Exception('Please upload only Excel (.xlsx, .xls) or CSV files.');
        }
        
        // Generate unique filename
        $filename = 'bank_statement_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $file_extension;
        $filepath = $upload_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Process the file based on bank type
            $bank_type = $_POST['bank_type'] ?? 'generic';
            $account_id = $_POST['bank_account_id'];
            
            $processed_transactions = processBankStatement($filepath, $bank_type, $account_id, $accounting);
            
            if (!empty($processed_transactions)) {
                $message = count($processed_transactions) . ' transactions processed successfully from bank statement.';
            } else {
                $error = 'No valid transactions found in the uploaded file.';
            }
        } else {
            throw new Exception('Failed to upload file.');
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Function to process bank statement based on bank format
function processBankStatement($filepath, $bank_type, $account_id, $accounting) {
    $transactions = [];
    $file_extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    
    if ($file_extension === 'csv') {
        $transactions = processCsvStatement($filepath, $bank_type);
    } else {
        // For Excel files, we'll use a simple CSV conversion approach
        // In production, you'd want to use PhpSpreadsheet library
        $transactions = processExcelStatement($filepath, $bank_type);
    }
    
    $processed = [];
    foreach ($transactions as $transaction) {
        try {
            $journal_id = createBankTransaction($transaction, $account_id, $accounting);
            if ($journal_id) {
                $transaction['journal_id'] = $journal_id;
                $processed[] = $transaction;
            }
        } catch (Exception $e) {
            // Log error but continue processing other transactions
            error_log("Failed to process transaction: " . $e->getMessage());
        }
    }
    
    return $processed;
}

// Process CSV bank statement
function processCsvStatement($filepath, $bank_type) {
    $transactions = [];
    $handle = fopen($filepath, 'r');
    
    if (!$handle) {
        throw new Exception('Unable to read the uploaded file.');
    }
    
    // Skip header row
    $header = fgetcsv($handle);
    
    while (($data = fgetcsv($handle)) !== FALSE) {
        $transaction = parseBankTransaction($data, $bank_type);
        if ($transaction && abs($transaction['amount']) > 0) {
            $transactions[] = $transaction;
        }
    }
    
    fclose($handle);
    return $transactions;
}

// Process Excel statement (simplified - converts to CSV first)
function processExcelStatement($filepath, $bank_type) {
    // This is a simplified approach. In production, use PhpSpreadsheet
    // For now, we'll assume the Excel file can be read as CSV
    return processCsvStatement($filepath, $bank_type);
}

// Parse individual transaction based on bank format
function parseBankTransaction($data, $bank_type) {
    switch ($bank_type) {
        case 'sbi':
            return parseSBITransaction($data);
        case 'hdfc':
            return parseHDFCTransaction($data);
        case 'icici':
            return parseICICITransaction($data);
        case 'axis':
            return parseAxisTransaction($data);
        default:
            return parseGenericTransaction($data);
    }
}

// SBI Bank format parser
function parseSBITransaction($data) {
    if (count($data) < 6) return null;
    
    return [
        'date' => date('Y-m-d', strtotime($data[0])),
        'description' => trim($data[1]),
        'reference' => $data[2] ?? '',
        'debit' => !empty($data[3]) ? floatval(str_replace(',', '', $data[3])) : 0,
        'credit' => !empty($data[4]) ? floatval(str_replace(',', '', $data[4])) : 0,
        'balance' => !empty($data[5]) ? floatval(str_replace(',', '', $data[5])) : 0,
        'amount' => !empty($data[4]) ? floatval(str_replace(',', '', $data[4])) : -floatval(str_replace(',', '', $data[3]))
    ];
}

// HDFC Bank format parser
function parseHDFCTransaction($data) {
    if (count($data) < 5) return null;
    
    return [
        'date' => date('Y-m-d', strtotime($data[0])),
        'description' => trim($data[1]),
        'reference' => $data[2] ?? '',
        'amount' => floatval(str_replace(',', '', $data[3])),
        'balance' => !empty($data[4]) ? floatval(str_replace(',', '', $data[4])) : 0,
        'debit' => $data[3] < 0 ? abs(floatval(str_replace(',', '', $data[3]))) : 0,
        'credit' => $data[3] > 0 ? floatval(str_replace(',', '', $data[3])) : 0
    ];
}

// ICICI Bank format parser
function parseICICITransaction($data) {
    if (count($data) < 6) return null;
    
    return [
        'date' => date('Y-m-d', strtotime($data[0])),
        'description' => trim($data[2]),
        'reference' => $data[1] ?? '',
        'debit' => !empty($data[3]) ? floatval(str_replace(',', '', $data[3])) : 0,
        'credit' => !empty($data[4]) ? floatval(str_replace(',', '', $data[4])) : 0,
        'balance' => !empty($data[5]) ? floatval(str_replace(',', '', $data[5])) : 0,
        'amount' => !empty($data[4]) ? floatval(str_replace(',', '', $data[4])) : -floatval(str_replace(',', '', $data[3]))
    ];
}

// Axis Bank format parser
function parseAxisTransaction($data) {
    if (count($data) < 5) return null;
    
    return [
        'date' => date('Y-m-d', strtotime($data[0])),
        'description' => trim($data[1]),
        'reference' => $data[2] ?? '',
        'debit' => !empty($data[3]) && $data[3] != '' ? floatval(str_replace(',', '', $data[3])) : 0,
        'credit' => !empty($data[4]) && $data[4] != '' ? floatval(str_replace(',', '', $data[4])) : 0,
        'amount' => (!empty($data[4]) && $data[4] != '') ? floatval(str_replace(',', '', $data[4])) : -floatval(str_replace(',', '', $data[3])),
        'balance' => 0
    ];
}

// Generic format parser
function parseGenericTransaction($data) {
    if (count($data) < 4) return null;
    
    return [
        'date' => date('Y-m-d', strtotime($data[0])),
        'description' => trim($data[1]),
        'reference' => $data[2] ?? '',
        'amount' => floatval(str_replace(',', '', $data[3])),
        'debit' => floatval(str_replace(',', '', $data[3])) < 0 ? abs(floatval(str_replace(',', '', $data[3]))) : 0,
        'credit' => floatval(str_replace(',', '', $data[3])) > 0 ? floatval(str_replace(',', '', $data[3])) : 0,
        'balance' => 0
    ];
}

// Create journal entry for bank transaction
function createBankTransaction($transaction, $bank_account_id, $accounting) {
    global $pdo;
    // Determine the contra account based on transaction description
    $contra_account_id = determineContraAccount($transaction['description']);
    
    if (!$contra_account_id) {
        // Default to miscellaneous income/expense
        global $pdo;
        $contra_account_id = $transaction['amount'] > 0 ? 
            getAccountIdByCode('4100', $pdo) : // Other Income
            getAccountIdByCode('6000', $pdo);   // Professional Fees
    }
    
    $lines = [];
    
    if ($transaction['amount'] > 0) {
        // Money coming into bank (Credit to bank, Debit to income/asset account)
        $lines[] = [
            'account_id' => $bank_account_id,
            'description' => $transaction['description'],
            'debit_amount' => abs($transaction['amount']),
            'credit_amount' => 0
        ];
        $lines[] = [
            'account_id' => $contra_account_id,
            'description' => $transaction['description'],
            'debit_amount' => 0,
            'credit_amount' => abs($transaction['amount'])
        ];
    } else {
        // Money going out of bank (Debit to bank, Credit to expense/liability account)
        $lines[] = [
            'account_id' => $bank_account_id,
            'description' => $transaction['description'],
            'debit_amount' => 0,
            'credit_amount' => abs($transaction['amount'])
        ];
        $lines[] = [
            'account_id' => $contra_account_id,
            'description' => $transaction['description'],
            'debit_amount' => abs($transaction['amount']),
            'credit_amount' => 0
        ];
    }
    
    return $accounting->createJournalEntry(
        $transaction['date'],
        'MANUAL',
        null,
        'Bank Transaction: ' . $transaction['description'],
        $lines,
        'Bank Upload'
    );
}

// Determine contra account based on transaction description
function determineContraAccount($description) {
    $description = strtolower($description);
    
    // Common patterns for automatic account mapping
    $patterns = [
        // Income patterns
        '/consultation|patient|fee/' => '4000', // Consultation Revenue
        '/pharmacy|medicine|drug/' => '4010',   // Pharmacy Sales
        '/lab|test|pathology/' => '4020',       // Laboratory Revenue
        '/ultrasound|scan|imaging/' => '4030',  // Ultrasound Revenue
        
        // Expense patterns
        '/salary|wages|staff/' => '5200',       // Staff Salaries
        '/rent/' => '5300',                     // Rent Expense
        '/electricity|power|utility/' => '5400', // Utilities
        '/medical|supplies/' => '5500',         // Medical Supplies
        '/office|stationery/' => '5600',        // Office Supplies
        '/insurance/' => '5700',                // Insurance
        '/bank|charges|fee/' => '5900',         // Bank Charges
        '/professional|legal|audit/' => '6000', // Professional Fees
        '/marketing|advertisement/' => '6100',   // Marketing
        '/maintenance|repair/' => '6200',        // Maintenance
        
        // Asset patterns
        '/equipment|asset|purchase/' => '1200', // Medical Equipment
        '/computer|laptop|software/' => '1220', // Computer Equipment
        
        // Liability patterns
        '/loan|emi|interest/' => '2100',        // Bank Loan
        '/gst|tax/' => '2010',                  // GST Payable
        '/tds/' => '2020',                      // TDS Payable
    ];
    
    foreach ($patterns as $pattern => $account_code) {
        if (preg_match($pattern, $description)) {
            global $pdo;
            return getAccountIdByCode($account_code, $pdo);
        }
    }
    
    return null;
}

// Helper function to get account ID by code
function getAccountIdByCode($code, $pdo) {
    $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
    $stmt->execute([$code]);
    $result = $stmt->fetch();
    return $result ? $result['id'] : null;
}

// Get bank accounts for dropdown
$stmt = $pdo->prepare("
    SELECT id, account_code, account_name 
    FROM chart_of_accounts 
    WHERE account_code IN ('1000', '1010') 
    AND is_active = 1
    ORDER BY account_code
");
$stmt->execute();
$bank_accounts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Statement Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .upload-area {
            border: 2px dashed #ccc;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            transition: border-color 0.3s;
        }
        .upload-area:hover {
            border-color: #007bff;
        }
        .upload-area.dragover {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .bank-format-card {
            cursor: pointer;
            transition: all 0.3s;
        }
        .bank-format-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .bank-format-card.selected {
            border-color: #007bff;
            background-color: #e7f3ff;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="../index.php">
                    <i class="fas fa-stethoscope"></i> Clinic Management
                </a>
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="accounting_dashboard.php">
                        <i class="fas fa-chart-bar"></i> Accounting Dashboard
                    </a>
                    <a class="nav-link" href="../admin.php">
                        <i class="fas fa-cog"></i> Admin
                    </a>
                </div>
            </div>
        </nav>

        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h2><i class="fas fa-upload text-primary"></i> Bank Statement Upload</h2>
                <p class="text-muted">Upload and process bank statements to automatically create journal entries</p>
            </div>
        </div>

        <!-- Messages -->
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

        <div class="row">
            <!-- Upload Form -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-file-upload"></i> Upload Bank Statement</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <!-- Bank Selection -->
                            <div class="mb-4">
                                <label class="form-label">Select Bank Format</label>
                                <div class="row">
                                    <div class="col-md-2">
                                        <div class="card bank-format-card" data-bank="sbi">
                                            <div class="card-body text-center p-3">
                                                <i class="fas fa-university fa-2x text-primary mb-2"></i>
                                                <h6>SBI</h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="card bank-format-card" data-bank="hdfc">
                                            <div class="card-body text-center p-3">
                                                <i class="fas fa-university fa-2x text-danger mb-2"></i>
                                                <h6>HDFC</h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="card bank-format-card" data-bank="icici">
                                            <div class="card-body text-center p-3">
                                                <i class="fas fa-university fa-2x text-warning mb-2"></i>
                                                <h6>ICICI</h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="card bank-format-card" data-bank="axis">
                                            <div class="card-body text-center p-3">
                                                <i class="fas fa-university fa-2x text-success mb-2"></i>
                                                <h6>Axis</h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="card bank-format-card" data-bank="generic">
                                            <div class="card-body text-center p-3">
                                                <i class="fas fa-university fa-2x text-secondary mb-2"></i>
                                                <h6>Generic</h6>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="bank_type" id="bank_type" required>
                            </div>

                            <!-- Bank Account Selection -->
                            <div class="mb-4">
                                <label class="form-label">Bank Account</label>
                                <select name="bank_account_id" class="form-select" required>
                                    <option value="">Select Bank Account</option>
                                    <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?= $account['id'] ?>">
                                        <?= $account['account_code'] ?> - <?= htmlspecialchars($account['account_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- File Upload Area -->
                            <div class="mb-4">
                                <label class="form-label">Bank Statement File</label>
                                <div class="upload-area" id="uploadArea">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <h5>Drag & Drop or Click to Upload</h5>
                                    <p class="text-muted">Supports Excel (.xlsx, .xls) and CSV files</p>
                                    <input type="file" name="bank_statement" id="fileInput" class="d-none" 
                                           accept=".xlsx,.xls,.csv" required>
                                    <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('fileInput').click()">
                                        <i class="fas fa-folder-open"></i> Browse Files
                                    </button>
                                </div>
                                <div id="fileInfo" class="mt-3 d-none">
                                    <div class="alert alert-info">
                                        <i class="fas fa-file"></i> <span id="fileName"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger float-end" onclick="clearFile()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Processing Options -->
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auto_categorize" id="auto_categorize" checked>
                                    <label class="form-check-label" for="auto_categorize">
                                        Auto-categorize transactions based on description
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="skip_duplicates" id="skip_duplicates" checked>
                                    <label class="form-check-label" for="skip_duplicates">
                                        Skip duplicate transactions
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-upload"></i> Upload & Process Statement
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Instructions -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                    </div>
                    <div class="card-body">
                        <h6>Supported File Formats:</h6>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-file-excel text-success"></i> Excel (.xlsx, .xls)</li>
                            <li><i class="fas fa-file-csv text-info"></i> CSV files</li>
                        </ul>

                        <h6 class="mt-4">Expected Columns:</h6>
                        <ul class="small">
                            <li><strong>Date:</strong> Transaction date</li>
                            <li><strong>Description:</strong> Transaction details</li>
                            <li><strong>Debit/Credit:</strong> Transaction amount</li>
                            <li><strong>Balance:</strong> Account balance (optional)</li>
                        </ul>

                        <h6 class="mt-4">Auto-categorization:</h6>
                        <p class="small text-muted">
                            The system will automatically assign accounts based on transaction descriptions. 
                            You can review and modify these after upload.
                        </p>

                        <div class="alert alert-warning small">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Note:</strong> Always review the processed transactions before finalizing them in your books.
                        </div>
                    </div>
                </div>

                <!-- Bank Format Guide -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6><i class="fas fa-question-circle"></i> Bank Format Guide</h6>
                    </div>
                    <div class="card-body">
                        <div class="accordion" id="bankFormats">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sbiFormat">
                                        SBI Format
                                    </button>
                                </h2>
                                <div id="sbiFormat" class="accordion-collapse collapse" data-bs-parent="#bankFormats">
                                    <div class="accordion-body small">
                                        Date | Description | Ref No | Debit | Credit | Balance
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#hdfcFormat">
                                        HDFC Format
                                    </button>
                                </h2>
                                <div id="hdfcFormat" class="accordion-collapse collapse" data-bs-parent="#bankFormats">
                                    <div class="accordion-body small">
                                        Date | Description | Ref No | Amount | Balance
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Processed Transactions -->
        <?php if (!empty($processed_transactions)): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-check-circle text-success"></i> Processed Transactions</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Reference</th>
                                        <th class="text-end">Debit</th>
                                        <th class="text-end">Credit</th>
                                        <th>Journal Entry</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($processed_transactions as $transaction): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($transaction['date'])) ?></td>
                                        <td><?= htmlspecialchars($transaction['description']) ?></td>
                                        <td><?= htmlspecialchars($transaction['reference']) ?></td>
                                        <td class="text-end">
                                            <?= $transaction['debit'] > 0 ? '₹ ' . number_format($transaction['debit'], 2) : '-' ?>
                                        </td>
                                        <td class="text-end">
                                            <?= $transaction['credit'] > 0 ? '₹ ' . number_format($transaction['credit'], 2) : '-' ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">JE-<?= $transaction['journal_id'] ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Bank selection
        document.querySelectorAll('.bank-format-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.bank-format-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                document.getElementById('bank_type').value = this.dataset.bank;
            });
        });

        // File upload handling
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');

        uploadArea.addEventListener('click', () => fileInput.click());

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                showFileInfo(files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                showFileInfo(e.target.files[0]);
            }
        });

        function showFileInfo(file) {
            fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
            fileInfo.classList.remove('d-none');
        }

        function clearFile() {
            fileInput.value = '';
            fileInfo.classList.add('d-none');
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            if (!document.getElementById('bank_type').value) {
                e.preventDefault();
                alert('Please select a bank format first.');
                return false;
            }
        });
    </script>
</body>
</html>