<?php
// purchase_invoice_entry.php - Purchase Invoice Entry with GST Input Credit Management
require_once '../includes/db.php';
require_once 'accounting.php';

$accounting = new AccountingSystem($pdo);
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();
        
        if ($_POST['action'] === 'save_invoice') {
            // Calculate totals
            $subtotal = 0;
            $total_gst = 0;
            $items = json_decode($_POST['items'], true);
            
            foreach ($items as $item) {
                $subtotal += $item['amount'];
                $total_gst += $item['cgst_amount'] + $item['sgst_amount'] + $item['igst_amount'];
            }
            
            // Calculate discounts
            $discount_amount = ($subtotal * floatval($_POST['discount_percent'])) / 100;
            $after_discount = $subtotal - $discount_amount;
            
            $spot_discount_amount = ($after_discount * floatval($_POST['spot_discount_percent'])) / 100;
            $total_amount = $after_discount - $spot_discount_amount + $total_gst;
            
            // Insert purchase invoice
            $stmt = $pdo->prepare("
                INSERT INTO purchase_invoices (
                    invoice_number, invoice_date, supplier_name, supplier_gstin, supplier_address, supplier_state,
                    subtotal, discount_percent, discount_amount, spot_discount_percent, spot_discount_amount,
                    cgst_amount, sgst_amount, igst_amount, total_gst, total_amount, balance_amount,
                    is_gst_eligible, payment_method, payment_status, invoice_type, category, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $cgst_total = array_sum(array_column($items, 'cgst_amount'));
            $sgst_total = array_sum(array_column($items, 'sgst_amount'));
            $igst_total = array_sum(array_column($items, 'igst_amount'));
            
            $stmt->execute([
                $_POST['invoice_number'],
                $_POST['invoice_date'],
                $_POST['supplier_name'],
                $_POST['supplier_gstin'] ?? null,
                $_POST['supplier_address'] ?? null,
                $_POST['supplier_state'] ?? null,
                $subtotal,
                $_POST['discount_percent'] ?? 0,
                $discount_amount,
                $_POST['spot_discount_percent'] ?? 0,
                $spot_discount_amount,
                $cgst_total,
                $sgst_total,
                $igst_total,
                $total_gst,
                $total_amount,
                $total_amount, // Initially balance = total
                $_POST['is_gst_eligible'] === 'true' ? 1 : 0,
                $_POST['payment_method'] ?? 'CASH',
                'PENDING',
                $_POST['invoice_type'] ?? 'PURCHASE',
                $_POST['category'] ?? null,
                $_POST['notes'] ?? null,
                'System'
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Insert invoice items
            foreach ($items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO purchase_invoice_items (
                        purchase_invoice_id, item_name, item_description, hsn_code,
                        quantity, unit, rate, amount, discount_percent, discount_amount,
                        gst_rate, cgst_rate, sgst_rate, igst_rate,
                        cgst_amount, sgst_amount, igst_amount, total_amount,
                        batch_number, expiry_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $invoice_id,
                    $item['item_name'],
                    $item['item_description'] ?? null,
                    $item['hsn_code'] ?? null,
                    $item['quantity'],
                    $item['unit'] ?? 'NOS',
                    $item['rate'],
                    $item['amount'],
                    $item['discount_percent'] ?? 0,
                    $item['discount_amount'] ?? 0,
                    $item['gst_rate'] ?? 0,
                    $item['cgst_rate'] ?? 0,
                    $item['sgst_rate'] ?? 0,
                    $item['igst_rate'] ?? 0,
                    $item['cgst_amount'] ?? 0,
                    $item['sgst_amount'] ?? 0,
                    $item['igst_amount'] ?? 0,
                    $item['total_amount'],
                    $item['batch_number'] ?? null,
                    $item['expiry_date'] ?? null
                ]);
            }
            
            // Create journal entry
            $journal_lines = [];
            
            // Debit: Purchase/Expense account
            $purchase_account_id = getPurchaseAccountId($_POST['category']);
            $journal_lines[] = [
                'account_id' => $purchase_account_id,
                'description' => 'Purchase - ' . $_POST['supplier_name'] . ' - Invoice: ' . $_POST['invoice_number'],
                'debit_amount' => $subtotal - $discount_amount - $spot_discount_amount,
                'credit_amount' => 0
            ];
            
            // Debit: GST Input Credits (if eligible)
            if ($_POST['is_gst_eligible'] === 'true' && $total_gst > 0) {
                if ($cgst_total > 0) {
                    $journal_lines[] = [
                        'account_id' => getAccountIdByCode('1030'), // CGST Input Credit
                        'description' => 'CGST Input Credit - Invoice: ' . $_POST['invoice_number'],
                        'debit_amount' => $cgst_total,
                        'credit_amount' => 0
                    ];
                }
                if ($sgst_total > 0) {
                    $journal_lines[] = [
                        'account_id' => getAccountIdByCode('1031'), // SGST Input Credit
                        'description' => 'SGST Input Credit - Invoice: ' . $_POST['invoice_number'],
                        'debit_amount' => $sgst_total,
                        'credit_amount' => 0
                    ];
                }
                if ($igst_total > 0) {
                    $journal_lines[] = [
                        'account_id' => getAccountIdByCode('1032'), // IGST Input Credit
                        'description' => 'IGST Input Credit - Invoice: ' . $_POST['invoice_number'],
                        'debit_amount' => $igst_total,
                        'credit_amount' => 0
                    ];
                }
            }
            
            // Credit: Supplier/Cash account
            $payment_account_id = getPaymentAccountId($_POST['payment_method']);
            $journal_lines[] = [
                'account_id' => $payment_account_id,
                'description' => 'Payment to ' . $_POST['supplier_name'] . ' - Invoice: ' . $_POST['invoice_number'],
                'debit_amount' => 0,
                'credit_amount' => $total_amount
            ];
            
            // Credit: Discount received (if any)
            if ($discount_amount > 0) {
                $journal_lines[] = [
                    'account_id' => getAccountIdByCode('4200'), // Purchase Discount
                    'description' => 'Purchase Discount - Invoice: ' . $_POST['invoice_number'],
                    'debit_amount' => 0,
                    'credit_amount' => $discount_amount
                ];
            }
            
            if ($spot_discount_amount > 0) {
                $journal_lines[] = [
                    'account_id' => getAccountIdByCode('4210'), // Spot Discount
                    'description' => 'Spot Payment Discount - Invoice: ' . $_POST['invoice_number'],
                    'debit_amount' => 0,
                    'credit_amount' => $spot_discount_amount
                ];
            }
            
            // Create journal entry
            $journal_entry_id = $accounting->createJournalEntry(
                $_POST['invoice_date'],
                'PURCHASE_INVOICE',
                $invoice_id,
                'Purchase Invoice - ' . $_POST['supplier_name'] . ' - ' . $_POST['invoice_number'],
                $journal_lines,
                'System'
            );
            
            // Update invoice with journal entry ID
            $stmt = $pdo->prepare("UPDATE purchase_invoices SET journal_entry_id = ? WHERE id = ?");
            $stmt->execute([$journal_entry_id, $invoice_id]);
            
            // If GST eligible, create input credit register entry
            if ($_POST['is_gst_eligible'] === 'true' && $total_gst > 0) {
                $invoice_date = new DateTime($_POST['invoice_date']);
                $stmt = $pdo->prepare("
                    INSERT INTO gst_input_credit_register (
                        purchase_invoice_id, gst_period_month, gst_period_year, filing_period,
                        cgst_credit, sgst_credit, igst_credit, total_credit, claim_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
                ");
                
                $stmt->execute([
                    $invoice_id,
                    $invoice_date->format('n'),
                    $invoice_date->format('Y'),
                    $invoice_date->format('Y-m'),
                    $cgst_total,
                    $sgst_total,
                    $igst_total,
                    $total_gst
                ]);
            }
            
            $pdo->commit();
            $message = 'Purchase invoice saved successfully! Invoice ID: ' . $invoice_id;
            
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error saving invoice: ' . $e->getMessage();
    }
}

// Helper functions
function getPurchaseAccountId($category) {
    global $pdo;
    $account_codes = [
        'Medicine' => '5010',
        'Medical Equipment' => '5020',
        'Office Supplies' => '5030',
        'Other' => '5040'
    ];
    
    $code = $account_codes[$category] ?? '5040';
    return getAccountIdByCode($code);
}

function getPaymentAccountId($payment_method) {
    $account_codes = [
        'CASH' => '1000',
        'BANK_TRANSFER' => '1010',
        'CHEQUE' => '1010',
        'UPI' => '1010',
        'CREDIT' => '2030'
    ];
    
    $code = $account_codes[$payment_method] ?? '1000';
    return getAccountIdByCode($code);
}

function getAccountIdByCode($code) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
    $stmt->execute([$code]);
    $result = $stmt->fetch();
    return $result ? $result['id'] : null;
}

// Get suppliers for dropdown
$suppliers = $pdo->query("SELECT DISTINCT supplier_name FROM purchase_invoices ORDER BY supplier_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Invoice Entry - GST Input Credit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .invoice-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        .item-row {
            background: #f8f9fa;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .summary-card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .gst-toggle {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 15px;
        }
        .discount-section {
            background: #fff3e0;
            border: 1px solid #ff9800;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        .btn-add-item {
            background: #4caf50;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-add-item:hover {
            background: #45a049;
            transform: translateY(-2px);
        }
        .calculation-display {
            background: #f5f5f5;
            border-left: 4px solid #2196f3;
            padding: 10px 15px;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="invoice-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-file-invoice-dollar"></i> Purchase Invoice Entry</h1>
                    <p class="mb-0">GST Input Credit Management System</p>
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

        <form method="POST" id="invoiceForm">
            <input type="hidden" name="action" value="save_invoice">
            <input type="hidden" name="items" id="itemsJson">
            
            <div class="row">
                <!-- Invoice Details -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-info-circle"></i> Invoice Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Invoice Number *</label>
                                        <input type="text" class="form-control" name="invoice_number" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Invoice Date *</label>
                                        <input type="date" class="form-control" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Supplier Name *</label>
                                        <input type="text" class="form-control" name="supplier_name" list="supplierList" required>
                                        <datalist id="supplierList">
                                            <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Supplier GSTIN</label>
                                        <input type="text" class="form-control" name="supplier_gstin" pattern="[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}" placeholder="22AAAAA0000A1Z5">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Supplier Address</label>
                                <textarea class="form-control" name="supplier_address" rows="2"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Supplier State</label>
                                        <input type="text" class="form-control" name="supplier_state" value="Maharashtra">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Invoice Type</label>
                                        <select class="form-select" name="invoice_type">
                                            <option value="PURCHASE">Purchase</option>
                                            <option value="EXPENSE">Expense</option>
                                            <option value="ASSET">Asset</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Category</label>
                                        <select class="form-select" name="category">
                                            <option value="Medicine">Medicine</option>
                                            <option value="Medical Equipment">Medical Equipment</option>
                                            <option value="Office Supplies">Office Supplies</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- GST Eligibility -->
                    <div class="card mt-3">
                        <div class="card-body">
                            <div class="gst-toggle">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="gstEligible" name="is_gst_eligible" value="true" checked>
                                    <label class="form-check-label" for="gstEligible">
                                        <strong>GST Input Credit Eligible</strong>
                                    </label>
                                </div>
                                <small class="text-muted">Check this if the invoice is eligible for GST input credit claim</small>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Items -->
                    <div class="card mt-3">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-list"></i> Invoice Items</h5>
                            <button type="button" class="btn btn-add-item btn-sm" onclick="addItem()">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="itemsContainer">
                                <!-- Items will be added here dynamically -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary and Payment -->
                <div class="col-md-4">
                    <div class="summary-card">
                        <h5><i class="fas fa-calculator"></i> Invoice Summary</h5>
                        
                        <div class="calculation-display">
                            <div class="d-flex justify-content-between">
                                <span>Subtotal:</span>
                                <span id="subtotalDisplay">₹0.00</span>
                            </div>
                        </div>

                        <!-- Discount Section -->
                        <div class="discount-section">
                            <h6><i class="fas fa-percent"></i> Discounts</h6>
                            
                            <div class="mb-2">
                                <label class="form-label">Regular Discount (%)</label>
                                <input type="number" class="form-control form-control-sm" name="discount_percent" id="discountPercent" step="0.01" min="0" max="100" value="0">
                            </div>
                            
                            <div class="calculation-display">
                                <div class="d-flex justify-content-between">
                                    <span>Discount Amount:</span>
                                    <span id="discountDisplay">₹0.00</span>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Spot Payment Discount (%)</label>
                                <input type="number" class="form-control form-control-sm" name="spot_discount_percent" id="spotDiscountPercent" step="0.01" min="0" max="100" value="0">
                            </div>
                            
                            <div class="calculation-display">
                                <div class="d-flex justify-content-between">
                                    <span>Spot Discount:</span>
                                    <span id="spotDiscountDisplay">₹0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="calculation-display">
                            <div class="d-flex justify-content-between">
                                <span>After Discounts:</span>
                                <span id="afterDiscountDisplay">₹0.00</span>
                            </div>
                        </div>

                        <div class="calculation-display">
                            <div class="d-flex justify-content-between">
                                <span>CGST:</span>
                                <span id="cgstDisplay">₹0.00</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>SGST:</span>
                                <span id="sgstDisplay">₹0.00</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>IGST:</span>
                                <span id="igstDisplay">₹0.00</span>
                            </div>
                        </div>

                        <div class="calculation-display bg-primary text-white">
                            <div class="d-flex justify-content-between">
                                <strong>Total Amount:</strong>
                                <strong id="totalDisplay">₹0.00</strong>
                            </div>
                        </div>

                        <!-- Payment Details -->
                        <div class="mt-3">
                            <h6><i class="fas fa-credit-card"></i> Payment Details</h6>
                            
                            <div class="mb-2">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" name="payment_method">
                                    <option value="CASH">Cash</option>
                                    <option value="BANK_TRANSFER">Bank Transfer</option>
                                    <option value="CHEQUE">Cheque</option>
                                    <option value="UPI">UPI</option>
                                    <option value="CREDIT">Credit</option>
                                </select>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mt-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Any additional notes..."></textarea>
                        </div>

                        <!-- Submit Button -->
                        <div class="mt-3 d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Save Invoice
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCounter = 0;
        let items = [];

        function addItem() {
            itemCounter++;
            const container = document.getElementById('itemsContainer');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'item-row';
            itemDiv.id = `item-${itemCounter}`;
            
            itemDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6><i class="fas fa-box"></i> Item #${itemCounter}</h6>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${itemCounter})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Item Name *</label>
                            <input type="text" class="form-control" data-field="item_name" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">HSN Code</label>
                            <input type="text" class="form-control" data-field="hsn_code">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control" data-field="quantity" step="0.001" min="0" required onchange="calculateItemTotal(${itemCounter})">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">Unit</label>
                            <select class="form-select" data-field="unit">
                                <option value="NOS">Nos</option>
                                <option value="KG">Kg</option>
                                <option value="GM">Gm</option>
                                <option value="LTR">Ltr</option>
                                <option value="ML">ML</option>
                                <option value="BOX">Box</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">Rate *</label>
                            <input type="number" class="form-control" data-field="rate" step="0.01" min="0" required onchange="calculateItemTotal(${itemCounter})">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">Amount</label>
                            <input type="number" class="form-control" data-field="amount" step="0.01" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">GST Rate (%)</label>
                            <select class="form-select" data-field="gst_rate" onchange="updateGSTRates(${itemCounter})">
                                <option value="0">0%</option>
                                <option value="5">5%</option>
                                <option value="12" selected>12%</option>
                                <option value="18">18%</option>
                                <option value="28">28%</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">CGST (%)</label>
                            <input type="number" class="form-control" data-field="cgst_rate" step="0.01" readonly>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">SGST (%)</label>
                            <input type="number" class="form-control" data-field="sgst_rate" step="0.01" readonly>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">IGST (%)</label>
                            <input type="number" class="form-control" data-field="igst_rate" step="0.01" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Batch Number</label>
                            <input type="text" class="form-control" data-field="batch_number">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" data-field="expiry_date">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-2">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" data-field="item_description">
                        </div>
                    </div>
                </div>
                
                <div class="row mt-2">
                    <div class="col-md-3">
                        <small class="text-muted">CGST Amount: ₹<span data-display="cgst_amount">0.00</span></small>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">SGST Amount: ₹<span data-display="sgst_amount">0.00</span></small>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">IGST Amount: ₹<span data-display="igst_amount">0.00</span></small>
                    </div>
                    <div class="col-md-3">
                        <strong class="text-primary">Total: ₹<span data-display="total_amount">0.00</span></strong>
                    </div>
                </div>
            `;
            
            container.appendChild(itemDiv);
            updateGSTRates(itemCounter);
        }

        function removeItem(itemId) {
            const item = document.getElementById(`item-${itemId}`);
            if (item) {
                item.remove();
                calculateTotals();
            }
        }

        function updateGSTRates(itemId) {
            const itemDiv = document.getElementById(`item-${itemId}`);
            const gstRate = parseFloat(itemDiv.querySelector('[data-field="gst_rate"]').value) || 0;
            const supplierState = document.querySelector('[name="supplier_state"]').value;
            const isIntraState = supplierState === 'Maharashtra'; // Assuming clinic is in Maharashtra
            
            if (isIntraState) {
                // CGST + SGST
                itemDiv.querySelector('[data-field="cgst_rate"]').value = gstRate / 2;
                itemDiv.querySelector('[data-field="sgst_rate"]').value = gstRate / 2;
                itemDiv.querySelector('[data-field="igst_rate"]').value = 0;
            } else {
                // IGST
                itemDiv.querySelector('[data-field="cgst_rate"]').value = 0;
                itemDiv.querySelector('[data-field="sgst_rate"]').value = 0;
                itemDiv.querySelector('[data-field="igst_rate"]').value = gstRate;
            }
            
            calculateItemTotal(itemId);
        }

        function calculateItemTotal(itemId) {
            const itemDiv = document.getElementById(`item-${itemId}`);
            const quantity = parseFloat(itemDiv.querySelector('[data-field="quantity"]').value) || 0;
            const rate = parseFloat(itemDiv.querySelector('[data-field="rate"]').value) || 0;
            const amount = quantity * rate;
            
            itemDiv.querySelector('[data-field="amount"]').value = amount.toFixed(2);
            
            // Calculate GST amounts
            const cgstRate = parseFloat(itemDiv.querySelector('[data-field="cgst_rate"]').value) || 0;
            const sgstRate = parseFloat(itemDiv.querySelector('[data-field="sgst_rate"]').value) || 0;
            const igstRate = parseFloat(itemDiv.querySelector('[data-field="igst_rate"]').value) || 0;
            
            const cgstAmount = (amount * cgstRate) / 100;
            const sgstAmount = (amount * sgstRate) / 100;
            const igstAmount = (amount * igstRate) / 100;
            const totalAmount = amount + cgstAmount + sgstAmount + igstAmount;
            
            // Update displays
            itemDiv.querySelector('[data-display="cgst_amount"]').textContent = cgstAmount.toFixed(2);
            itemDiv.querySelector('[data-display="sgst_amount"]').textContent = sgstAmount.toFixed(2);
            itemDiv.querySelector('[data-display="igst_amount"]').textContent = igstAmount.toFixed(2);
            itemDiv.querySelector('[data-display="total_amount"]').textContent = totalAmount.toFixed(2);
            
            calculateTotals();
        }

        function calculateTotals() {
            let subtotal = 0;
            let totalCGST = 0;
            let totalSGST = 0;
            let totalIGST = 0;
            
            const itemDivs = document.querySelectorAll('.item-row');
            itemDivs.forEach(itemDiv => {
                const amount = parseFloat(itemDiv.querySelector('[data-field="amount"]').value) || 0;
                subtotal += amount;
                
                totalCGST += parseFloat(itemDiv.querySelector('[data-display="cgst_amount"]').textContent) || 0;
                totalSGST += parseFloat(itemDiv.querySelector('[data-display="sgst_amount"]').textContent) || 0;
                totalIGST += parseFloat(itemDiv.querySelector('[data-display="igst_amount"]').textContent) || 0;
            });
            
            // Calculate discounts
            const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
            const discountAmount = (subtotal * discountPercent) / 100;
            const afterDiscount = subtotal - discountAmount;
            
            const spotDiscountPercent = parseFloat(document.getElementById('spotDiscountPercent').value) || 0;
            const spotDiscountAmount = (afterDiscount * spotDiscountPercent) / 100;
            const afterSpotDiscount = afterDiscount - spotDiscountAmount;
            
            const totalGST = totalCGST + totalSGST + totalIGST;
            const grandTotal = afterSpotDiscount + totalGST;
            
            // Update displays
            document.getElementById('subtotalDisplay').textContent = `₹${subtotal.toFixed(2)}`;
            document.getElementById('discountDisplay').textContent = `₹${discountAmount.toFixed(2)}`;
            document.getElementById('spotDiscountDisplay').textContent = `₹${spotDiscountAmount.toFixed(2)}`;
            document.getElementById('afterDiscountDisplay').textContent = `₹${afterSpotDiscount.toFixed(2)}`;
            document.getElementById('cgstDisplay').textContent = `₹${totalCGST.toFixed(2)}`;
            document.getElementById('sgstDisplay').textContent = `₹${totalSGST.toFixed(2)}`;
            document.getElementById('igstDisplay').textContent = `₹${totalIGST.toFixed(2)}`;
            document.getElementById('totalDisplay').textContent = `₹${grandTotal.toFixed(2)}`;
        }

        // Event listeners
        document.getElementById('discountPercent').addEventListener('input', calculateTotals);
        document.getElementById('spotDiscountPercent').addEventListener('input', calculateTotals);

        // Form submission
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            // Collect all items data
            const itemsData = [];
            const itemDivs = document.querySelectorAll('.item-row');
            
            itemDivs.forEach(itemDiv => {
                const item = {};
                
                // Get all form fields
                itemDiv.querySelectorAll('[data-field]').forEach(field => {
                    const fieldName = field.getAttribute('data-field');
                    item[fieldName] = field.value;
                });
                
                // Get calculated amounts
                item.cgst_amount = parseFloat(itemDiv.querySelector('[data-display="cgst_amount"]').textContent) || 0;
                item.sgst_amount = parseFloat(itemDiv.querySelector('[data-display="sgst_amount"]').textContent) || 0;
                item.igst_amount = parseFloat(itemDiv.querySelector('[data-display="igst_amount"]').textContent) || 0;
                item.total_amount = parseFloat(itemDiv.querySelector('[data-display="total_amount"]').textContent) || 0;
                
                itemsData.push(item);
            });
            
            document.getElementById('itemsJson').value = JSON.stringify(itemsData);
        });

        // Add first item on load
        document.addEventListener('DOMContentLoaded', function() {
            addItem();
        });
    </script>
</body>
</html>