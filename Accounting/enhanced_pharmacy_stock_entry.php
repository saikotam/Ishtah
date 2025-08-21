<?php
// enhanced_pharmacy_stock_entry.php - Enhanced pharmacy stock entry with purchase invoice integration
require_once '../includes/db.php';
require_once '../includes/log.php';
require_once 'accounting.php';

$accounting = new AccountingSystem($pdo);
$success = false;
$error = '';
$message = '';
$form_data = [];
$validation_errors = [];

// Preserve form data function
function preserveFormValue($field, $default = '') {
    global $form_data;
    return isset($form_data[$field]) ? htmlspecialchars($form_data[$field]) : $default;
}

// Validation functions
function validateRequired($value, $field_name) {
    if (empty(trim($value))) {
        return "$field_name is required";
    }
    return null;
}

function validateNumeric($value, $field_name, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return "$field_name must be a valid number";
    }
    if ($min !== null && $value < $min) {
        return "$field_name must be at least $min";
    }
    if ($max !== null && $value > $max) {
        return "$field_name must not exceed $max";
    }
    return null;
}

function validateDate($value, $field_name) {
    if (!empty($value) && !DateTime::createFromFormat('Y-m-d', $value)) {
        return "$field_name must be a valid date";
    }
    return null;
}

function validateGSTIN($value) {
    if (!empty($value) && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $value)) {
        return "GSTIN must be in valid format (e.g., 22AAAAA0000A1Z5)";
    }
    return null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store form data for preservation
    $form_data = $_POST;
    
    // Server-side validation
    if (isset($_POST['action']) && $_POST['action'] === 'add_stock_with_invoice') {
        // Validate invoice fields
        if ($error = validateRequired($_POST['invoice_number'] ?? '', 'Invoice Number')) {
            $validation_errors['invoice_number'] = $error;
        }
        if ($error = validateRequired($_POST['invoice_date'] ?? '', 'Invoice Date')) {
            $validation_errors['invoice_date'] = $error;
        }
        if ($error = validateDate($_POST['invoice_date'] ?? '', 'Invoice Date')) {
            $validation_errors['invoice_date'] = $error;
        }
        if ($error = validateRequired($_POST['supplier_name'] ?? '', 'Supplier Name')) {
            $validation_errors['supplier_name'] = $error;
        }
        if ($error = validateGSTIN($_POST['supplier_gstin'] ?? '')) {
            $validation_errors['supplier_gstin'] = $error;
        }
        
        // Validate medicines
        $medicines = json_decode($_POST['medicines'] ?? '[]', true);
        if (empty($medicines)) {
            $validation_errors['medicines'] = 'At least one medicine is required';
        } else {
            foreach ($medicines as $index => $medicine) {
                if ($error = validateRequired($medicine['medicine_name'] ?? '', "Medicine Name for item " . ($index + 1))) {
                    $validation_errors["medicine_name_$index"] = $error;
                }
                if ($error = validateNumeric($medicine['quantity'] ?? '', "Quantity for item " . ($index + 1), 0.001)) {
                    $validation_errors["quantity_$index"] = $error;
                }
                if ($error = validateNumeric($medicine['purchase_price'] ?? '', "Purchase Price for item " . ($index + 1), 0)) {
                    $validation_errors["purchase_price_$index"] = $error;
                }
            }
        }
        
        // Validate discount percentages
        if (!empty($_POST['discount_percent'])) {
            if ($error = validateNumeric($_POST['discount_percent'], 'Discount Percent', 0, 100)) {
                $validation_errors['discount_percent'] = $error;
            }
        }
        if (!empty($_POST['spot_discount_percent'])) {
            if ($error = validateNumeric($_POST['spot_discount_percent'], 'Spot Discount Percent', 0, 100)) {
                $validation_errors['spot_discount_percent'] = $error;
            }
        }
    } else if (isset($_POST['action']) && $_POST['action'] === 'simple_stock') {
        // Validate simple stock entry
        if ($error = validateRequired($_POST['medicine_name'] ?? '', 'Medicine Name')) {
            $validation_errors['medicine_name'] = $error;
        }
        if ($error = validateNumeric($_POST['quantity'] ?? '', 'Quantity', 0.001)) {
            $validation_errors['quantity'] = $error;
        }
        if (!empty($_POST['purchase_price'])) {
            if ($error = validateNumeric($_POST['purchase_price'], 'Purchase Price', 0)) {
                $validation_errors['purchase_price'] = $error;
            }
        }
        if (!empty($_POST['sale_price'])) {
            if ($error = validateNumeric($_POST['sale_price'], 'Sale Price', 0)) {
                $validation_errors['sale_price'] = $error;
            }
        }
        if (!empty($_POST['expiry_date'])) {
            if ($error = validateDate($_POST['expiry_date'], 'Expiry Date')) {
                $validation_errors['expiry_date'] = $error;
            }
        }
    }
    
    // Only proceed if no validation errors
    if (empty($validation_errors)) {
        try {
            $pdo->beginTransaction();
        
        if (isset($_POST['action']) && $_POST['action'] === 'add_stock_with_invoice') {
            // First create/update purchase invoice
            $invoice_data = [
                'invoice_number' => $_POST['invoice_number'],
                'invoice_date' => $_POST['invoice_date'],
                'supplier_name' => $_POST['supplier_name'],
                'supplier_gstin' => $_POST['supplier_gstin'] ?? null,
                'supplier_address' => $_POST['supplier_address'] ?? null,
                'supplier_state' => $_POST['supplier_state'] ?? 'Maharashtra',
                'category' => 'Medicine',
                'is_gst_eligible' => isset($_POST['is_gst_eligible']),
                'payment_method' => $_POST['payment_method'] ?? 'CASH',
                'notes' => $_POST['notes'] ?? null
            ];
            
            // Check if invoice already exists
            $stmt = $pdo->prepare("SELECT id FROM purchase_invoices WHERE invoice_number = ? AND supplier_name = ?");
            $stmt->execute([$invoice_data['invoice_number'], $invoice_data['supplier_name']]);
            $existing_invoice = $stmt->fetch();
            
            $medicines = json_decode($_POST['medicines'], true);
            $subtotal = 0;
            $total_gst = 0;
            
            // Calculate totals
            foreach ($medicines as $medicine) {
                $amount = floatval($medicine['quantity']) * floatval($medicine['purchase_price']);
                $subtotal += $amount;
                
                if ($invoice_data['is_gst_eligible']) {
                    $gst_rate = floatval($medicine['gst_percent']);
                    $gst_amount = ($amount * $gst_rate) / 100;
                    $total_gst += $gst_amount;
                }
            }
            
            $discount_amount = ($subtotal * floatval($_POST['discount_percent'] ?? 0)) / 100;
            $spot_discount_amount = (($subtotal - $discount_amount) * floatval($_POST['spot_discount_percent'] ?? 0)) / 100;
            $total_amount = $subtotal - $discount_amount - $spot_discount_amount + $total_gst;
            
            if ($existing_invoice) {
                $invoice_id = $existing_invoice['id'];
                // Update existing invoice totals
                $stmt = $pdo->prepare("
                    UPDATE purchase_invoices SET 
                        subtotal = subtotal + ?,
                        total_gst = total_gst + ?,
                        total_amount = total_amount + ?,
                        balance_amount = balance_amount + ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$subtotal - $discount_amount - $spot_discount_amount, $total_gst, $total_amount, $total_amount, $invoice_id]);
            } else {
                // Create new invoice
                $stmt = $pdo->prepare("
                    INSERT INTO purchase_invoices (
                        invoice_number, invoice_date, supplier_name, supplier_gstin, supplier_address, supplier_state,
                        subtotal, discount_percent, discount_amount, spot_discount_percent, spot_discount_amount,
                        total_gst, total_amount, balance_amount, is_gst_eligible, payment_method, 
                        invoice_type, category, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PURCHASE', 'Medicine', ?, 'System')
                ");
                
                $stmt->execute([
                    $invoice_data['invoice_number'],
                    $invoice_data['invoice_date'],
                    $invoice_data['supplier_name'],
                    $invoice_data['supplier_gstin'],
                    $invoice_data['supplier_address'],
                    $invoice_data['supplier_state'],
                    $subtotal,
                    $_POST['discount_percent'] ?? 0,
                    $discount_amount,
                    $_POST['spot_discount_percent'] ?? 0,
                    $spot_discount_amount,
                    $total_gst,
                    $total_amount,
                    $total_amount,
                    $invoice_data['is_gst_eligible'] ? 1 : 0,
                    $invoice_data['payment_method']
                ]);
                
                $invoice_id = $pdo->lastInsertId();
            }
            
            // Add medicines to stock and invoice items
            foreach ($medicines as $medicine) {
                // Add to pharmacy stock
                $stmt = $pdo->prepare("
                    INSERT INTO pharmacy_stock (
                        medicine_name, batch_no, expiry_date, quantity, purchase_price, sale_price, 
                        gst_percent, supplier_name, box_number, unit_type, hsn_code, purchase_invoice_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $medicine['medicine_name'],
                    $medicine['batch_no'] ?? null,
                    $medicine['expiry_date'] ?? null,
                    $medicine['quantity'],
                    $medicine['purchase_price'],
                    $medicine['sale_price'],
                    $medicine['gst_percent'] ?? 12.00,
                    $invoice_data['supplier_name'],
                    $medicine['box_number'] ?? null,
                    $medicine['unit_type'] ?? 'Strip',
                    $medicine['hsn_code'] ?? null,
                    $invoice_id
                ]);
                
                // Add to purchase invoice items
                $amount = floatval($medicine['quantity']) * floatval($medicine['purchase_price']);
                $gst_rate = floatval($medicine['gst_percent'] ?? 0);
                $gst_amount = ($amount * $gst_rate) / 100;
                
                // Split GST based on state
                $is_intra_state = ($invoice_data['supplier_state'] === 'Maharashtra');
                $cgst_amount = $is_intra_state ? $gst_amount / 2 : 0;
                $sgst_amount = $is_intra_state ? $gst_amount / 2 : 0;
                $igst_amount = $is_intra_state ? 0 : $gst_amount;
                
                $stmt = $pdo->prepare("
                    INSERT INTO purchase_invoice_items (
                        purchase_invoice_id, item_name, item_description, hsn_code,
                        quantity, unit, rate, amount, gst_rate,
                        cgst_rate, sgst_rate, igst_rate,
                        cgst_amount, sgst_amount, igst_amount, total_amount,
                        batch_number, expiry_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $invoice_id,
                    $medicine['medicine_name'],
                    'Pharmacy stock - ' . ($medicine['batch_no'] ?? ''),
                    $medicine['hsn_code'] ?? null,
                    $medicine['quantity'],
                    $medicine['unit_type'] ?? 'Strip',
                    $medicine['purchase_price'],
                    $amount,
                    $gst_rate,
                    $is_intra_state ? $gst_rate / 2 : 0,
                    $is_intra_state ? $gst_rate / 2 : 0,
                    $is_intra_state ? 0 : $gst_rate,
                    $cgst_amount,
                    $sgst_amount,
                    $igst_amount,
                    $amount + $gst_amount,
                    $medicine['batch_no'] ?? null,
                    $medicine['expiry_date'] ?? null
                ]);
            }
            
            // Create/update GST input credit register if eligible
            if ($invoice_data['is_gst_eligible'] && $total_gst > 0) {
                $invoice_date = new DateTime($invoice_data['invoice_date']);
                
                // Check if entry exists
                $stmt = $pdo->prepare("
                    SELECT id FROM gst_input_credit_register 
                    WHERE purchase_invoice_id = ? AND gst_period_month = ? AND gst_period_year = ?
                ");
                $stmt->execute([$invoice_id, $invoice_date->format('n'), $invoice_date->format('Y')]);
                $existing_credit = $stmt->fetch();
                
                $cgst_total = array_sum(array_map(function($m) use ($invoice_data) {
                    $amount = floatval($m['quantity']) * floatval($m['purchase_price']);
                    $gst_amount = ($amount * floatval($m['gst_percent'] ?? 0)) / 100;
                    return ($invoice_data['supplier_state'] === 'Maharashtra') ? $gst_amount / 2 : 0;
                }, $medicines));
                
                $sgst_total = $cgst_total; // Same as CGST for intra-state
                
                $igst_total = array_sum(array_map(function($m) use ($invoice_data) {
                    $amount = floatval($m['quantity']) * floatval($m['purchase_price']);
                    $gst_amount = ($amount * floatval($m['gst_percent'] ?? 0)) / 100;
                    return ($invoice_data['supplier_state'] !== 'Maharashtra') ? $gst_amount : 0;
                }, $medicines));
                
                if ($existing_credit) {
                    $stmt = $pdo->prepare("
                        UPDATE gst_input_credit_register SET
                            cgst_credit = cgst_credit + ?,
                            sgst_credit = sgst_credit + ?,
                            igst_credit = igst_credit + ?,
                            total_credit = total_credit + ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$cgst_total, $sgst_total, $igst_total, $total_gst, $existing_credit['id']]);
                } else {
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
            }
            
            $pdo->commit();
            $success = true;
            $message = 'Stock added successfully with purchase invoice integration! Invoice ID: ' . $invoice_id;
            
            // Clear form data on successful submission
            $form_data = [];
            
            // Log the action
            log_action('Pharmacy', 'Enhanced Stock Entry', 'Invoice: ' . $invoice_data['invoice_number'] . ', Items: ' . count($medicines));
            
        } else {
            // Legacy simple stock entry
            $stmt = $pdo->prepare("INSERT INTO pharmacy_stock (medicine_name, batch_no, expiry_date, quantity, purchase_price, sale_price, gst_percent, supplier_name, box_number, unit_type, hsn_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([
                $_POST['medicine_name'],
                $_POST['batch_no'] ?? null,
                $_POST['expiry_date'] ?? null,
                $_POST['quantity'],
                $_POST['purchase_price'] ?? 0,
                $_POST['sale_price'] ?? 0,
                $_POST['gst_percent'] ?? 12.00,
                $_POST['supplier_name'] ?? null,
                $_POST['box_number'] ?? null,
                $_POST['unit_type'],
                $_POST['hsn_code'] ?? null
            ])) {
                $success = true;
                $message = 'Stock entry saved successfully!';
                
                // Clear form data on successful submission
                $form_data = [];
                
                log_action('Reception', 'Simple Stock Entry', 'Medicine: ' . $_POST['medicine_name'] . ', Qty: ' . $_POST['quantity'] . ' ' . $_POST['unit_type']);
            } else {
                $error = 'Failed to save stock. Please try again.';
            }
            
            $pdo->commit();
        }
        
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Database Error: ' . $e->getMessage();
            // Form data is preserved in $form_data for redisplay
        }
    } else {
        $error = 'Please correct the validation errors below.';
    }
}

// Fetch current stock
$stock = $pdo->query("SELECT * FROM pharmacy_stock WHERE quantity > 0 ORDER BY medicine_name, batch_no, expiry_date")->fetchAll();

// Get recent suppliers
$recent_suppliers = $pdo->query("
    SELECT DISTINCT supplier_name, supplier_gstin, supplier_address, supplier_state 
    FROM purchase_invoices 
    WHERE supplier_name IS NOT NULL 
    ORDER BY created_at DESC 
    LIMIT 10
")->fetchAll();

// Add missing column to pharmacy_stock if not exists
try {
    $pdo->exec("ALTER TABLE pharmacy_stock ADD COLUMN purchase_invoice_id INT, ADD FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id)");
} catch (Exception $e) {
    // Column might already exist, ignore error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Pharmacy Stock Entry - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        .form-section {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .medicine-row {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .entry-mode-toggle {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .gst-section {
            background: #fff3e0;
            border: 1px solid #ff9800;
            border-radius: 8px;
            padding: 15px;
        }
        .summary-display {
            background: #f5f5f5;
            border-left: 4px solid #4caf50;
            padding: 10px 15px;
            margin: 10px 0;
        }
        .validation-error {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .is-invalid {
            border-color: #dc3545;
        }
        .is-valid {
            border-color: #28a745;
        }
        .form-requirements {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .requirement-item {
            margin-bottom: 5px;
        }
        .requirement-item i {
            margin-right: 8px;
        }
        .field-help {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-pills"></i> Enhanced Pharmacy Stock Entry</h1>
                    <p class="mb-0">Integrated with GST Input Credit Management</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="../index.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="../pharmacy_stock_summary.php" class="btn btn-light me-2">
                        <i class="fas fa-list"></i> Stock Summary
                    </a>
                    <a href="gst_input_credit_reports.php" class="btn btn-success">
                        <i class="fas fa-chart-line"></i> GST Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <?php if ($success): ?>
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

        <?php if (!empty($validation_errors)): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <h6><i class="fas fa-exclamation-circle"></i> Please correct the following errors:</h6>
            <ul class="mb-0">
                <?php foreach ($validation_errors as $field => $error_msg): ?>
                <li><?= htmlspecialchars($error_msg) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Entry Mode Selection -->
        <div class="entry-mode-toggle">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="entry_mode" id="simpleMode" value="simple" checked>
                        <label class="form-check-label" for="simpleMode">
                            <strong>Simple Entry</strong><br>
                            <small class="text-muted">Quick single medicine entry (legacy mode)</small>
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="entry_mode" id="invoiceMode" value="invoice">
                        <label class="form-check-label" for="invoiceMode">
                            <strong>Invoice-based Entry</strong><br>
                            <small class="text-muted">Complete invoice with GST input credit tracking</small>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Simple Entry Form -->
        <div id="simpleEntryForm" class="form-section">
            <h5><i class="fas fa-plus-circle"></i> Simple Stock Entry</h5>
            
            <!-- Form Requirements -->
            <div class="form-requirements">
                <h6><i class="fas fa-info-circle"></i> Required Fields</h6>
                <div class="row">
                    <div class="col-md-6">
                        <div class="requirement-item">
                            <i class="fas fa-check-circle text-success"></i> Medicine Name
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-check-circle text-success"></i> Quantity (minimum 0.001)
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="requirement-item">
                            <i class="fas fa-info-circle text-info"></i> Unit Type (defaults to Strip)
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-info-circle text-info"></i> GST % (defaults to 12%)
                        </div>
                    </div>
                </div>
            </div>
            
            <form method="POST" id="simpleStockForm">
                <input type="hidden" name="action" value="simple_stock">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Medicine Name *</label>
                            <input type="text" class="form-control <?= isset($validation_errors['medicine_name']) ? 'is-invalid' : '' ?>" 
                                   name="medicine_name" value="<?= preserveFormValue('medicine_name') ?>" required>
                            <?php if (isset($validation_errors['medicine_name'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['medicine_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control <?= isset($validation_errors['quantity']) ? 'is-invalid' : '' ?>" 
                                   name="quantity" value="<?= preserveFormValue('quantity') ?>" step="0.001" min="0" required>
                            <?php if (isset($validation_errors['quantity'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['quantity']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Unit Type *</label>
                            <select class="form-select" name="unit_type" required>
                                <option value="Strip" <?= preserveFormValue('unit_type', 'Strip') === 'Strip' ? 'selected' : '' ?>>Strip</option>
                                <option value="Bottle" <?= preserveFormValue('unit_type') === 'Bottle' ? 'selected' : '' ?>>Bottle</option>
                                <option value="Vial" <?= preserveFormValue('unit_type') === 'Vial' ? 'selected' : '' ?>>Vial</option>
                                <option value="Tube" <?= preserveFormValue('unit_type') === 'Tube' ? 'selected' : '' ?>>Tube</option>
                                <option value="Box" <?= preserveFormValue('unit_type') === 'Box' ? 'selected' : '' ?>>Box</option>
                                <option value="Packet" <?= preserveFormValue('unit_type') === 'Packet' ? 'selected' : '' ?>>Packet</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Purchase Price</label>
                            <input type="number" class="form-control <?= isset($validation_errors['purchase_price']) ? 'is-invalid' : '' ?>" 
                                   name="purchase_price" value="<?= preserveFormValue('purchase_price') ?>" step="0.01" min="0">
                            <?php if (isset($validation_errors['purchase_price'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['purchase_price']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Sale Price</label>
                            <input type="number" class="form-control <?= isset($validation_errors['sale_price']) ? 'is-invalid' : '' ?>" 
                                   name="sale_price" value="<?= preserveFormValue('sale_price') ?>" step="0.01" min="0">
                            <?php if (isset($validation_errors['sale_price'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['sale_price']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">GST %</label>
                            <select class="form-select" name="gst_percent">
                                <option value="0" <?= preserveFormValue('gst_percent') === '0' ? 'selected' : '' ?>>0%</option>
                                <option value="5" <?= preserveFormValue('gst_percent') === '5' ? 'selected' : '' ?>>5%</option>
                                <option value="12" <?= preserveFormValue('gst_percent', '12') === '12' ? 'selected' : '' ?>>12%</option>
                                <option value="18" <?= preserveFormValue('gst_percent') === '18' ? 'selected' : '' ?>>18%</option>
                                <option value="28" <?= preserveFormValue('gst_percent') === '28' ? 'selected' : '' ?>>28%</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">HSN Code</label>
                            <input type="text" class="form-control" name="hsn_code" value="<?= preserveFormValue('hsn_code') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Batch No</label>
                            <input type="text" class="form-control" name="batch_no" value="<?= preserveFormValue('batch_no') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control <?= isset($validation_errors['expiry_date']) ? 'is-invalid' : '' ?>" 
                                   name="expiry_date" value="<?= preserveFormValue('expiry_date') ?>">
                            <?php if (isset($validation_errors['expiry_date'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['expiry_date']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Box Number</label>
                            <input type="text" class="form-control" name="box_number" value="<?= preserveFormValue('box_number') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Supplier Name</label>
                    <input type="text" class="form-control" name="supplier_name" value="<?= preserveFormValue('supplier_name') ?>">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Add to Stock
                </button>
            </form>
        </div>

        <!-- Invoice-based Entry Form -->
        <div id="invoiceEntryForm" class="form-section" style="display: none;">
            <h5><i class="fas fa-file-invoice"></i> Invoice-based Stock Entry</h5>
            <form method="POST" id="invoiceForm">
                <input type="hidden" name="action" value="add_stock_with_invoice">
                <input type="hidden" name="medicines" id="medicinesJson">
                
                <!-- Invoice Details -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Invoice Number *</label>
                            <input type="text" class="form-control <?= isset($validation_errors['invoice_number']) ? 'is-invalid' : '' ?>" 
                                   name="invoice_number" value="<?= preserveFormValue('invoice_number') ?>" required>
                            <?php if (isset($validation_errors['invoice_number'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['invoice_number']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Invoice Date *</label>
                            <input type="date" class="form-control <?= isset($validation_errors['invoice_date']) ? 'is-invalid' : '' ?>" 
                                   name="invoice_date" value="<?= preserveFormValue('invoice_date', date('Y-m-d')) ?>" required>
                            <?php if (isset($validation_errors['invoice_date'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['invoice_date']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Supplier Details -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Supplier Name *</label>
                            <input type="text" class="form-control <?= isset($validation_errors['supplier_name']) ? 'is-invalid' : '' ?>" 
                                   name="supplier_name" value="<?= preserveFormValue('supplier_name') ?>" list="supplierList" required>
                            <datalist id="supplierList">
                                <?php foreach ($recent_suppliers as $supplier): ?>
                                <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>" 
                                        data-gstin="<?= htmlspecialchars($supplier['supplier_gstin']) ?>"
                                        data-address="<?= htmlspecialchars($supplier['supplier_address']) ?>"
                                        data-state="<?= htmlspecialchars($supplier['supplier_state']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <?php if (isset($validation_errors['supplier_name'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['supplier_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Supplier GSTIN</label>
                            <input type="text" class="form-control <?= isset($validation_errors['supplier_gstin']) ? 'is-invalid' : '' ?>" 
                                   name="supplier_gstin" value="<?= preserveFormValue('supplier_gstin') ?>">
                            <?php if (isset($validation_errors['supplier_gstin'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['supplier_gstin']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Supplier Address</label>
                            <input type="text" class="form-control" name="supplier_address" value="<?= preserveFormValue('supplier_address') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Supplier State</label>
                            <input type="text" class="form-control" name="supplier_state" value="<?= preserveFormValue('supplier_state', 'Maharashtra') ?>">
                        </div>
                    </div>
                </div>

                <!-- GST Eligibility -->
                <div class="gst-section mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="gstEligible" name="is_gst_eligible" 
                               <?= preserveFormValue('is_gst_eligible') ? 'checked' : (empty($form_data) ? 'checked' : '') ?>>
                        <label class="form-check-label" for="gstEligible">
                            <strong>GST Input Credit Eligible</strong>
                        </label>
                    </div>
                </div>

                <!-- Medicines Section -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6><i class="fas fa-pills"></i> Medicines</h6>
                        <button type="button" class="btn btn-success btn-sm" onclick="addMedicine()">
                            <i class="fas fa-plus"></i> Add Medicine
                        </button>
                    </div>
                    <?php if (isset($validation_errors['medicines'])): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($validation_errors['medicines']) ?>
                        </div>
                    <?php endif; ?>
                    <div id="medicinesContainer">
                        <!-- Medicines will be added here -->
                    </div>
                </div>

                <!-- Discounts -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Discount (%)</label>
                            <input type="number" class="form-control <?= isset($validation_errors['discount_percent']) ? 'is-invalid' : '' ?>" 
                                   name="discount_percent" value="<?= preserveFormValue('discount_percent', '0') ?>" 
                                   step="0.01" min="0" max="100" onchange="calculateSummary()">
                            <?php if (isset($validation_errors['discount_percent'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['discount_percent']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Spot Payment Discount (%)</label>
                            <input type="number" class="form-control <?= isset($validation_errors['spot_discount_percent']) ? 'is-invalid' : '' ?>" 
                                   name="spot_discount_percent" value="<?= preserveFormValue('spot_discount_percent', '0') ?>" 
                                   step="0.01" min="0" max="100" onchange="calculateSummary()">
                            <?php if (isset($validation_errors['spot_discount_percent'])): ?>
                                <div class="validation-error"><?= htmlspecialchars($validation_errors['spot_discount_percent']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="CASH" <?= preserveFormValue('payment_method', 'CASH') === 'CASH' ? 'selected' : '' ?>>Cash</option>
                                <option value="BANK_TRANSFER" <?= preserveFormValue('payment_method') === 'BANK_TRANSFER' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="CHEQUE" <?= preserveFormValue('payment_method') === 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                                <option value="UPI" <?= preserveFormValue('payment_method') === 'UPI' ? 'selected' : '' ?>>UPI</option>
                                <option value="CREDIT" <?= preserveFormValue('payment_method') === 'CREDIT' ? 'selected' : '' ?>>Credit</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <input type="text" class="form-control" name="notes" value="<?= preserveFormValue('notes') ?>">
                        </div>
                    </div>
                </div>

                <!-- Summary Display -->
                <div class="summary-display">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Subtotal: ₹<span id="subtotalDisplay">0.00</span></strong>
                        </div>
                        <div class="col-md-3">
                            <strong>GST: ₹<span id="gstDisplay">0.00</span></strong>
                        </div>
                        <div class="col-md-3">
                            <strong>Discounts: ₹<span id="discountDisplay">0.00</span></strong>
                        </div>
                        <div class="col-md-3">
                            <strong class="text-primary">Total: ₹<span id="totalDisplay">0.00</span></strong>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Save Stock with Invoice
                </button>
            </form>
        </div>

        <!-- Current Stock Display -->
        <div class="form-section">
            <h5><i class="fas fa-warehouse"></i> Current Stock (<?= count($stock) ?> items)</h5>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Batch</th>
                            <th>Expiry</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Purchase Price</th>
                            <th>Sale Price</th>
                            <th>GST%</th>
                            <th>Supplier</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($stock, 0, 10) as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                            <td><?= htmlspecialchars($item['batch_no'] ?? '-') ?></td>
                            <td><?= $item['expiry_date'] ? date('d-m-Y', strtotime($item['expiry_date'])) : '-' ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= htmlspecialchars($item['unit_type']) ?></td>
                            <td>₹<?= number_format($item['purchase_price'], 2) ?></td>
                            <td>₹<?= number_format($item['sale_price'], 2) ?></td>
                            <td><?= $item['gst_percent'] ?>%</td>
                            <td><?= htmlspecialchars($item['supplier_name'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($stock) > 10): ?>
                <p class="text-muted">Showing first 10 items. <a href="../pharmacy_stock_summary.php">View all stock</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let medicineCounter = 0;
        
        // Toggle between entry modes
        document.querySelectorAll('input[name="entry_mode"]').forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'simple') {
                    document.getElementById('simpleEntryForm').style.display = 'block';
                    document.getElementById('invoiceEntryForm').style.display = 'none';
                } else {
                    document.getElementById('simpleEntryForm').style.display = 'none';
                    document.getElementById('invoiceEntryForm').style.display = 'block';
                }
            });
        });

        // Set the correct mode based on form data
        <?php if (!empty($form_data)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($form_data['action']) && $form_data['action'] === 'add_stock_with_invoice'): ?>
            document.getElementById('invoiceMode').checked = true;
            document.getElementById('simpleEntryForm').style.display = 'none';
            document.getElementById('invoiceEntryForm').style.display = 'block';
            <?php else: ?>
            document.getElementById('simpleMode').checked = true;
            document.getElementById('simpleEntryForm').style.display = 'block';
            document.getElementById('invoiceEntryForm').style.display = 'none';
            <?php endif; ?>
        });
        <?php endif; ?>

        function addMedicine() {
            medicineCounter++;
            const container = document.getElementById('medicinesContainer');
            const medicineDiv = document.createElement('div');
            medicineDiv.className = 'medicine-row';
            medicineDiv.id = `medicine-${medicineCounter}`;
            
            medicineDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6>Medicine #${medicineCounter}</h6>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMedicine(${medicineCounter})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-2">
                            <label class="form-label">Medicine Name *</label>
                            <input type="text" class="form-control" data-field="medicine_name" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-2">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control" data-field="quantity" step="0.001" min="0" required onchange="calculateSummary()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-2">
                            <label class="form-label">Unit</label>
                            <select class="form-select" data-field="unit_type">
                                <option value="Strip">Strip</option>
                                <option value="Bottle">Bottle</option>
                                <option value="Vial">Vial</option>
                                <option value="Tube">Tube</option>
                                <option value="Box">Box</option>
                                <option value="Packet">Packet</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-2">
                            <label class="form-label">Purchase Price *</label>
                            <input type="number" class="form-control" data-field="purchase_price" step="0.01" min="0" required onchange="calculateSummary()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-2">
                            <label class="form-label">Sale Price</label>
                            <input type="number" class="form-control" data-field="sale_price" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">GST %</label>
                            <select class="form-select" data-field="gst_percent" onchange="calculateSummary()">
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
                            <label class="form-label">HSN Code</label>
                            <input type="text" class="form-control" data-field="hsn_code">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">Batch No</label>
                            <input type="text" class="form-control" data-field="batch_no">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-2">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" data-field="expiry_date">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Box Number</label>
                            <input type="text" class="form-control" data-field="box_number">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-end mt-4">
                            <strong>Amount: ₹<span class="medicine-amount">0.00</span></strong>
                        </div>
                    </div>
                </div>
            `;
            
            container.appendChild(medicineDiv);
        }

        function removeMedicine(id) {
            const medicine = document.getElementById(`medicine-${id}`);
            if (medicine) {
                medicine.remove();
                calculateSummary();
            }
        }

        function calculateSummary() {
            let subtotal = 0;
            let totalGST = 0;
            
            const medicines = document.querySelectorAll('.medicine-row');
            medicines.forEach(medicine => {
                const quantity = parseFloat(medicine.querySelector('[data-field="quantity"]').value) || 0;
                const price = parseFloat(medicine.querySelector('[data-field="purchase_price"]').value) || 0;
                const gstPercent = parseFloat(medicine.querySelector('[data-field="gst_percent"]').value) || 0;
                
                const amount = quantity * price;
                subtotal += amount;
                
                if (document.getElementById('gstEligible').checked) {
                    totalGST += (amount * gstPercent) / 100;
                }
                
                medicine.querySelector('.medicine-amount').textContent = amount.toFixed(2);
            });
            
            const discountPercent = parseFloat(document.querySelector('[name="discount_percent"]').value) || 0;
            const spotDiscountPercent = parseFloat(document.querySelector('[name="spot_discount_percent"]').value) || 0;
            
            const discountAmount = (subtotal * discountPercent) / 100;
            const afterDiscount = subtotal - discountAmount;
            const spotDiscountAmount = (afterDiscount * spotDiscountPercent) / 100;
            const totalDiscounts = discountAmount + spotDiscountAmount;
            
            const total = subtotal - totalDiscounts + totalGST;
            
            document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
            document.getElementById('gstDisplay').textContent = totalGST.toFixed(2);
            document.getElementById('discountDisplay').textContent = totalDiscounts.toFixed(2);
            document.getElementById('totalDisplay').textContent = total.toFixed(2);
        }

        // Form submission
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            const medicinesData = [];
            const medicines = document.querySelectorAll('.medicine-row');
            
            medicines.forEach(medicine => {
                const medicineData = {};
                medicine.querySelectorAll('[data-field]').forEach(field => {
                    const fieldName = field.getAttribute('data-field');
                    medicineData[fieldName] = field.value;
                });
                medicinesData.push(medicineData);
            });
            
            document.getElementById('medicinesJson').value = JSON.stringify(medicinesData);
        });

        // Auto-populate supplier details
        document.querySelector('[name="supplier_name"]').addEventListener('input', function() {
            const suppliers = document.querySelectorAll('#supplierList option');
            suppliers.forEach(option => {
                if (option.value === this.value) {
                    document.querySelector('[name="supplier_gstin"]').value = option.dataset.gstin || '';
                    document.querySelector('[name="supplier_address"]').value = option.dataset.address || '';
                    document.querySelector('[name="supplier_state"]').value = option.dataset.state || 'Maharashtra';
                }
            });
        });

        // Restore medicines data if form was submitted with errors
        <?php if (!empty($form_data) && isset($form_data['medicines'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const medicinesData = <?= $form_data['medicines'] ?>;
            const medicines = JSON.parse(medicinesData);
            
            medicines.forEach((medicine, index) => {
                addMedicine();
                const medicineRow = document.getElementById(`medicine-${medicineCounter}`);
                
                // Populate fields
                Object.keys(medicine).forEach(field => {
                    const input = medicineRow.querySelector(`[data-field="${field}"]`);
                    if (input) {
                        input.value = medicine[field] || '';
                    }
                });
            });
            
            // Calculate summary after restoring data
            setTimeout(calculateSummary, 100);
            
            // Highlight validation errors for medicines
            <?php foreach ($validation_errors as $field => $error): ?>
                <?php if (preg_match('/^(medicine_name|quantity|purchase_price)_(\d+)$/', $field, $matches)): ?>
                    const errorField = '<?= $matches[1] ?>';
                    const errorIndex = <?= $matches[2] ?>;
                    const medicineRow = document.getElementById(`medicine-${errorIndex + 1}`);
                    if (medicineRow) {
                        const input = medicineRow.querySelector(`[data-field="${errorField}"]`);
                        if (input) {
                            input.classList.add('is-invalid');
                            // Add error message
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'validation-error';
                            errorDiv.textContent = '<?= htmlspecialchars($error) ?>';
                            input.parentNode.appendChild(errorDiv);
                        }
                    }
                <?php endif; ?>
            <?php endforeach; ?>
        });
        <?php else: ?>
        // Add first medicine on load
        document.addEventListener('DOMContentLoaded', function() {
            addMedicine();
        });
        <?php endif; ?>
    </script>
</body>
</html>