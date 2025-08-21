<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// pharmacy_billing.php - Generate pharmacy bill for a visit (with medicine search)
require_once 'includes/db.php';
require_once 'includes/log.php';
session_start();

function generate_pharmacy_invoice_number($pdo) {
    $prefix = 'PH-';
    $stmt = $pdo->query("SELECT invoice_number FROM pharmacy_bills WHERE invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/PH-(\\d+)/', $last, $m)) {
        $num = intval($m[1]) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
if (!$visit_id) {
    die('Invalid visit ID.');
}
// Fetch visit and patient details
$stmt = $pdo->prepare("SELECT v.*, p.full_name, p.gender, p.dob FROM visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = ?");
$stmt->execute([$visit_id]);
$visit = $stmt->fetch();
if (!$visit) {
    die('Visit not found.');
}

// Handle search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$medicines = [];
if ($search !== '') {
    // Fetch all medicines matching the search, regardless of stock
    $stmt = $pdo->prepare("SELECT * FROM pharmacy_stock WHERE medicine_name LIKE ? ORDER BY medicine_name, batch_no");
    $stmt->execute(['%' . $search . '%']);
    $medicines = $stmt->fetchAll();
}

// Handle bill list in session
if (!isset($_SESSION['pharmacy_bill_list'])) {
    $_SESSION['pharmacy_bill_list'] = [];
}
$bill_list = &$_SESSION['pharmacy_bill_list'];

// Add medicine to bill list
if (isset($_POST['add_medicine'])) {
    $med_id = intval($_POST['add_medicine']);
    $qty = intval($_POST['add_quantity']);
    if ($qty > 0) {
        $stmt = $pdo->prepare("SELECT * FROM pharmacy_stock WHERE id = ?");
        $stmt->execute([$med_id]);
        $med = $stmt->fetch();
        if ($med && $med['quantity'] >= $qty) {
            $found = false;
            foreach ($bill_list as $idx => $item) {
                if ($item['medicine_id'] == $med_id) {
                    $bill_list[$idx]['quantity'] += $qty;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $bill_list[] = [
                    'medicine_id' => $med_id,
                    'medicine_name' => $med['medicine_name'],
                    'batch_no' => $med['batch_no'],
                    'expiry_date' => $med['expiry_date'],
                    'unit_price' => $med['sale_price'],
                    'gst_percent' => $med['gst_percent'],
                    'hsn_code' => $med['hsn_code'],
                    'quantity' => $qty,
                    'available' => $med['quantity']
                ];
            }
            // Re-index the array to avoid reference issues
            $_SESSION['pharmacy_bill_list'] = array_values($bill_list);
        }
    }
}
// Remove medicine from bill list
if (isset($_POST['remove_medicine'])) {
    $remove_id = intval($_POST['remove_medicine']);
    $bill_list = array_filter($bill_list, function($item) use ($remove_id) {
        return $item['medicine_id'] != $remove_id;
    });
    $_SESSION['pharmacy_bill_list'] = array_values($bill_list);
}
// Update quantity in bill list
if (isset($_POST['update_quantity'])) {
    $update_id = intval($_POST['update_quantity']);
    $new_qty = intval($_POST['new_quantity']);
    foreach ($bill_list as $idx => $item) {
        if ($item['medicine_id'] == $update_id) {
            // Ensure new quantity is valid
            $max_qty = isset($item['available']) ? intval($item['available']) : 1;
            if ($new_qty >= 1 && $new_qty <= $max_qty) {
                $bill_list[$idx]['quantity'] = $new_qty;
            }
            break;
        }
    }
    $_SESSION['pharmacy_bill_list'] = array_values($bill_list);
}

// Discount handling
$discount_type = isset($_POST['discount_type']) ? $_POST['discount_type'] : (isset($_SESSION['pharmacy_discount_type']) ? $_SESSION['pharmacy_discount_type'] : '');
$discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : (isset($_SESSION['pharmacy_discount_value']) ? $_SESSION['pharmacy_discount_value'] : '');

// Notification for item discount removal
$item_discount_cleared = false;
if (isset($_POST['discount_type'])) {
    // Always clear item discounts when a total bill discount is being applied
    if ($discount_type === 'percent' || $discount_type === 'rupees') {
        $_SESSION['pharmacy_item_discounts'] = [];
        $item_discount_cleared = true;
    }
    $_SESSION['pharmacy_discount_type'] = $discount_type;
    $_SESSION['pharmacy_discount_value'] = $discount_value;
}

// Handle per-item discounts in bill list (now with type)
if (!isset($_SESSION['pharmacy_item_discounts'])) {
    $_SESSION['pharmacy_item_discounts'] = [];
}
$item_discounts = &$_SESSION['pharmacy_item_discounts'];
if (isset($_POST['set_item_discount'])) {
    $item_id = intval($_POST['item_id']);
    $item_discount_type = $_POST['item_discount_type'] ?? 'rupees';
    $item_discount_value = floatval($_POST['item_discount_value'] ?? 0);
    $item_discounts[$item_id] = ['type' => $item_discount_type, 'value' => $item_discount_value];
}

$success = false;
$error = '';
$bill_id = null;
$auto_print = false;
// Debug: Log item discounts before bill generation
error_log('pharmacy_item_discounts: ' . print_r($_SESSION['pharmacy_item_discounts'], true));
if (isset($_POST['final_submit'])) {
    $total = 0;
    $gst_total = 0;
    $items = [];
    $medicines_csv = [];
    $subtotal = 0;
    $total_item_discount = 0;
    foreach ($bill_list as $item) {
        if ($item['quantity'] > 0) {
            $price = $item['unit_price'] * $item['quantity'];
            $item_discount_type = isset($item_discounts[$item['medicine_id']]['type']) ? $item_discounts[$item['medicine_id']]['type'] : 'rupees';
            $item_discount_value = isset($item_discounts[$item['medicine_id']]['value']) ? $item_discounts[$item['medicine_id']]['value'] : 0;
            $item_discount = 0;
            if ($item_discount_type === 'percent') {
                $item_discount = $price * $item_discount_value / 100;
            } else {
                $item_discount = $item_discount_value;
            }
            $discounted_price = max(0, $price - $item_discount);
            $subtotal += $price;
            $total_item_discount += $item_discount;
            $items[] = [
                'medicine_id' => $item['medicine_id'],
                'quantity' => $item['quantity'],
                'price' => $discounted_price,
                'gst_percent' => $item['gst_percent'],
                'medicine_name' => $item['medicine_name'],
                'batch_no' => $item['batch_no'],
                'expiry_date' => $item['expiry_date'],
                'unit_price' => $item['unit_price'],
                'item_discount' => $item_discount,
                'gst' => 0 // will calculate after discount
            ];
            $medicines_csv[] = $item['medicine_name'] . ' x' . $item['quantity'];
        }
    }
    // Apply total discount
    $discount_amount = 0;
    if ($discount_type === 'percent' && $discount_value > 0) {
        $discount_amount = ($subtotal - $total_item_discount) * $discount_value / 100;
    } elseif ($discount_type === 'rupees' && $discount_value > 0) {
        $discount_amount = $discount_value;
    }
    $discounted_total = max(0, $subtotal - $total_item_discount - $discount_amount);
    // Calculate GST on discounted total
    $gst_total = 0;
    foreach ($items as &$item) {
        // Proportion of this item's (after item discount) price in the subtotal after item discounts
        $proportion = ($subtotal - $total_item_discount) > 0 ? $item['price'] / ($subtotal - $total_item_discount) : 0;
        // Proportional total discount for this item
        $item_total_discount = $discount_amount * $proportion;
        // Net price after all discounts
        $net_price = max(0, $item['price'] - $item_total_discount);
        // GST extracted from net price (since MRP is GST-inclusive)
        $item['gst'] = $net_price * $item['gst_percent'] / (100 + $item['gst_percent']);
        $gst_total += $item['gst'];
        $item['price'] = $net_price;
    }
    unset($item);
    if (count($items) > 0) {
        // Generate invoice number
        $invoice_number = generate_pharmacy_invoice_number($pdo);
        // Insert bill
        $stmt = $pdo->prepare("INSERT INTO pharmacy_bills (visit_id, total_amount, gst_amount, discount_type, discount_value, discounted_total, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$visit_id, $subtotal, $gst_total, $discount_type, $discount_value, $discounted_total, $invoice_number])) {
            $bill_id = $pdo->lastInsertId();
            foreach ($items as $item) {
                // Insert bill item
                $stmt = $pdo->prepare("INSERT INTO pharmacy_bill_items (bill_id, medicine_id, quantity, price, gst_percent) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$bill_id, $item['medicine_id'], $item['quantity'], $item['price'], $item['gst_percent']]);
                // Update stock
                $pdo->prepare("UPDATE pharmacy_stock SET quantity = quantity - ? WHERE id = ?")->execute([$item['quantity'], $item['medicine_id']]);
                // Log stock change
                $pdo->prepare("INSERT INTO pharmacy_stock_log (medicine_id, qty_change, action, reason, user) VALUES (?, ?, 'sale', 'Pharmacy bill', 'Reception')")->execute([$item['medicine_id'], -$item['quantity']]);
            }
            // Create accounting journal entry
            try {
                require_once __DIR__ . '/Accounting/accounting.php';
                $accounting = new AccountingSystem($pdo);
                // Try to guess payment mode from request if available, default to cash
                $payment_mode = isset($_POST['payment_mode']) ? strtolower($_POST['payment_mode']) : 'cash';
                // Estimate cost of goods sold as 70% of net sale if not tracked precisely
                $estimated_cogs = round($discounted_total * 0.7, 2);
                $accounting->recordPharmacySale($bill_id, $discounted_total, $gst_total, $estimated_cogs, $payment_mode);
            } catch (Exception $e) {
                error_log('Accounting entry failed for pharmacy bill ' . $bill_id . ': ' . $e->getMessage());
            }
            
            // Log to system log
            log_action('Reception', 'Pharmacy Bill Generated', 'Invoice: ' . $invoice_number . ', Patient: ' . $visit['full_name'] . ', Amount: ' . $discounted_total);
            $success = true;
            $auto_print = true;
            $_SESSION['pharmacy_bill_list'] = [];
            unset($_SESSION['pharmacy_discount_type'], $_SESSION['pharmacy_discount_value'], $_SESSION['pharmacy_item_discounts']);
            // Redirect to printable bill
            header('Location: pharmacy_billing.php?visit_id=' . $visit_id . '&invoice_number=' . urlencode($invoice_number) . '&print=1&bill_created=true');
            exit;
        } else {
            $error = 'Failed to generate bill.';
        }
    } else {
        $error = 'No medicines in bill.';
    }
}
// Handle 'New Bill' action
if (isset($_POST['new_bill'])) {
    $_SESSION['pharmacy_bill_list'] = [];
    unset($_SESSION['pharmacy_discount_type'], $_SESSION['pharmacy_discount_value'], $_SESSION['pharmacy_item_discounts']);
    header('Location: pharmacy_billing.php?visit_id=' . $visit_id);
    exit;
}
// Fetch bill and items if generated or if a bill exists for this visit
if ($success && $bill_id) {
    $bill = $pdo->prepare("SELECT * FROM pharmacy_bills WHERE id = ?");
    $bill->execute([$bill_id]);
    $bill = $bill->fetch();
    $bill_items = $pdo->prepare("SELECT pbi.*, ps.medicine_name, ps.batch_no, ps.expiry_date, ps.sale_price, ps.hsn_code FROM pharmacy_bill_items pbi JOIN pharmacy_stock ps ON pbi.medicine_id = ps.id WHERE pbi.bill_id = ?");
    $bill_items->execute([$bill_id]);
    $bill_items = $bill_items->fetchAll();
} else if (isset($_GET['invoice_number'])) {
    $bill = $pdo->prepare("SELECT * FROM pharmacy_bills WHERE invoice_number = ?");
    $bill->execute([$_GET['invoice_number']]);
    $bill = $bill->fetch();
    if ($bill) {
        $bill_items = $pdo->prepare("SELECT pbi.*, ps.medicine_name, ps.batch_no, ps.expiry_date, ps.sale_price, ps.hsn_code FROM pharmacy_bill_items pbi JOIN pharmacy_stock ps ON pbi.medicine_id = ps.id WHERE pbi.bill_id = ?");
        $bill_items->execute([$bill['id']]);
        $bill_items = $bill_items->fetchAll();
        $success = true;
    }
} else if ($visit_id) {
    $bill = $pdo->prepare("SELECT * FROM pharmacy_bills WHERE visit_id = ?");
    $bill->execute([$visit_id]);
    $bill = $bill->fetch();
    if ($bill) {
        $bill_items = $pdo->prepare("SELECT pbi.*, ps.medicine_name, ps.batch_no, ps.expiry_date, ps.sale_price, ps.hsn_code FROM pharmacy_bill_items pbi JOIN pharmacy_stock ps ON pbi.medicine_id = ps.id WHERE pbi.bill_id = ?");
        $bill_items->execute([$bill['id']]);
        $bill_items = $bill_items->fetchAll();
        $success = true;
    }
}
// Fetch all previous bills for this visit
$all_bills = $pdo->prepare("SELECT * FROM pharmacy_bills WHERE visit_id = ? ORDER BY id DESC");
$all_bills->execute([$visit_id]);
$all_bills = $all_bills->fetchAll();

// If invoice_number is set, fetch and display that bill
$show_bill = false;
if (isset($_GET['invoice_number'])) {
    $bill = $pdo->prepare("SELECT * FROM pharmacy_bills WHERE invoice_number = ?");
    $bill->execute([$_GET['invoice_number']]);
    $bill = $bill->fetch();
    if ($bill) {
        $bill_items = $pdo->prepare("SELECT pbi.*, ps.medicine_name, ps.batch_no, ps.expiry_date, ps.sale_price, ps.hsn_code FROM pharmacy_bill_items pbi JOIN pharmacy_stock ps ON pbi.medicine_id = ps.id WHERE pbi.bill_id = ?");
        $bill_items->execute([$bill['id']]);
        $bill_items = $bill_items->fetchAll();
        $show_bill = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Billing - Ishtah Clinic</title>
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            line-height: 1.3;
            color: #000;
            background: white;
        }
        
        /* Print-specific styles */
        @media print {
            @page {
                margin: 0.3in 0.5in;
                size: A4;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            /* Remove browser headers and footers */
            @page {
                margin-header: 0;
                margin-footer: 0;
            }
        }
        
        /* Professional bill layout */
        .bill-container {
            width: 100%;
            max-width: 8.5in;
            margin: 0 auto;
            padding: 0.5in;
        }
        
        /* Header section */
        .bill-header {
            text-align: center;
            margin-bottom: 0.5in;
            border-bottom: 2px solid #333;
            padding-bottom: 0.3in;
        }
        
        .clinic-name {
            font-size: 20pt;
            font-weight: bold;
            margin-bottom: 0.1in;
        }
        
        .clinic-info {
            font-size: 10pt;
            line-height: 1.4;
        }
        
        .bill-title {
            font-size: 16pt;
            font-weight: bold;
            margin: 0.2in 0;
        }
        
        /* Patient and bill info */
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.4in;
            font-size: 11pt;
        }
        
        .patient-info {
            flex: 1;
        }
        
        .bill-info {
            flex: 1;
            text-align: right;
        }
        
        .info-row {
            margin-bottom: 0.1in;
        }
        
        .info-label {
            font-weight: bold;
            margin-right: 0.2in;
        }
        
        /* Bill table */
        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.4in;
            font-size: 9pt;
        }
        
        .bill-table th,
        .bill-table td {
            border: 1px solid #000;
            padding: 0.1in 0.15in;
            text-align: left;
        }
        
        .bill-table th {
            background: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }
        
        .bill-table .amount {
            text-align: right;
        }
        
        /* Summary section */
        .summary-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 0.4in;
        }
        
        .summary-table {
            border-collapse: collapse;
            font-size: 11pt;
        }
        
        .summary-table td {
            padding: 0.1in 0.3in;
            border: 1px solid #000;
        }
        
        .summary-table .label {
            font-weight: bold;
            background: #f0f0f0;
        }
        
        .summary-table .amount {
            text-align: right;
            font-weight: bold;
        }
        
        /* Footer */
        .bill-footer {
            margin-top: 0.5in;
            text-align: center;
            font-size: 10pt;
            border-top: 1px solid #333;
            padding-top: 0.2in;
        }
        
        /* Control buttons */
        .controls {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 0 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 12pt;
        }
        
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-outline-secondary { background: white; color: #6c757d; border: 1px solid #6c757d; }
        .btn-outline-info { background: white; color: #17a2b8; border: 1px solid #17a2b8; }
        
        .btn:hover { opacity: 0.8; }
        
        /* Form elements */
        .form-control {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 12pt;
        }
        
        .form-select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 12pt;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .table th,
        .table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-danger { color: #dc3545; }
        .text-muted { color: #6c757d; }
        .mb-1 { margin-bottom: 5px; }
        .mb-2 { margin-bottom: 10px; }
        .mb-3 { margin-bottom: 15px; }
        .mb-4 { margin-bottom: 20px; }
        .mt-4 { margin-top: 20px; }
        .ms-2 { margin-left: 10px; }
        .d-inline { display: inline; }
        .d-inline-block { display: inline-block; }
        .w-auto { width: auto; }
        .align-middle { vertical-align: middle; }
        .small { font-size: 0.875em; }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .modal-title {
            font-size: 16pt;
            font-weight: bold;
        }
        
        .btn-close {
            background: none;
            border: none;
            font-size: 20pt;
            cursor: pointer;
        }
        
        .modal-body {
            margin-bottom: 15px;
        }
        
        .modal-footer {
            text-align: right;
            padding-top: 10px;
            border-top: 1px solid #ddd;
        }
        
        /* Card styles */
        .card {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .card-body {
            padding: 15px;
        }
        
        /* Row and column layout */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .col-md-2, .col-md-3, .col-md-6 {
            padding: 0 10px;
            margin-bottom: 10px;
        }
        
        .col-md-2 { flex: 0 0 16.666667%; }
        .col-md-3 { flex: 0 0 25%; }
        .col-md-6 { flex: 0 0 50%; }
        
        .g-2 { gap: 10px; }
        .align-items-end { align-items: flex-end; }
        .align-self-end { align-self: flex-end; }
    </style>
</head>
<body>
    <?php if ($show_bill && $bill && $bill_items): ?>
    <!-- Professional Bill Layout -->
    <div class="bill-container">
        <!-- Header -->
        <div class="bill-header">
            <div class="clinic-name">ISHTAH CLINIC</div>
            <div class="clinic-info">
                [Your Clinic Address Line 1]<br>
                [Your Clinic Address Line 2]<br>
                Phone: [Your Phone] | Email: [Your Email]
            </div>
            <div class="bill-title">PHARMACY BILL (GST INVOICE)</div>
        </div>
        
        <!-- Patient and Bill Info -->
        <div class="info-section">
            <div class="patient-info">
                <div class="info-row">
                    <span class="info-label">Patient Name:</span>
                    <span><?= htmlspecialchars($visit['full_name']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Gender:</span>
                    <span><?= htmlspecialchars($visit['gender']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date of Birth:</span>
                    <span><?= htmlspecialchars($visit['dob']) ?></span>
                </div>
            </div>
            <div class="bill-info">
                <div class="info-row">
                    <span class="info-label">Invoice #:</span>
                    <span><?= htmlspecialchars($bill['invoice_number']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date:</span>
                    <span><?= date('d/m/Y', strtotime($bill['created_at'] ?? 'now')) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Visit ID:</span>
                    <span><?= $visit_id ?></span>
                </div>
            </div>
        </div>
        
        <!-- Bill Items Table -->
        <table class="bill-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Medicine</th>
                    <th style="width: 8%;">HSN Code</th>
                    <th style="width: 10%;">Batch</th>
                    <th style="width: 10%;">Expiry</th>
                    <th style="width: 8%;">Unit Price</th>
                    <th style="width: 5%;">Qty</th>
                    <th style="width: 5%;">GST %</th>
                    <th style="width: 10%;">Base Price</th>
                    <th style="width: 10%;">GST Amt</th>
                    <th style="width: 9%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_item_discount = 0;
                foreach ($bill_items as $item): 
                    $base_price = $item['price'] / (1 + $item['gst_percent']/100);
                    $gst_amt = $item['price'] - $base_price;
                    $orig_price = $item['sale_price'] * $item['quantity'];
                    $item_discount = max(0, $orig_price - $item['price']);
                    $total_item_discount += $item_discount;
                ?>
                <tr>
                    <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($item['batch_no']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td class="amount"><?= number_format($item['sale_price'], 2) ?></td>
                    <td class="amount"><?= $item['quantity'] ?></td>
                    <td class="amount"><?= $item['gst_percent'] ?>%</td>
                    <td class="amount"><?= number_format($base_price, 2) ?></td>
                    <td class="amount"><?= number_format($gst_amt, 2) ?></td>
                    <td class="amount"><?= number_format($item['price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Summary -->
        <div class="summary-section">
            <table class="summary-table">
                <tr>
                    <td class="label">Subtotal (Base Price):</td>
                    <td class="amount">₹<?= number_format($bill['total_amount'] - $bill['gst_amount'], 2) ?></td>
                </tr>
                <tr>
                    <td class="label">Total Item Discount:</td>
                    <td class="amount">₹<?= number_format($total_item_discount, 2) ?></td>
                </tr>
                <tr>
                    <td class="label">Total Discount:</td>
                    <td class="amount">
                        <?php 
                        $total_discount_amount = $total_item_discount;
                        $bill_discount_amount = 0;
                        if ($bill['discount_type'] === 'percent' && $bill['discount_value'] > 0) {
                            $bill_discount_amount = ($bill['total_amount'] - $total_item_discount) * $bill['discount_value'] / 100;
                        } elseif ($bill['discount_type'] === 'rupees' && $bill['discount_value'] > 0) {
                            $bill_discount_amount = $bill['discount_value'];
                        }
                        $total_discount_amount += $bill_discount_amount;
                        $effective_discount_percent = ($bill['total_amount'] > 0) ? ($total_discount_amount / $bill['total_amount'] * 100) : 0;
                        echo '₹' . number_format($total_discount_amount, 2) . ' (' . number_format($effective_discount_percent, 2) . '%)';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">Total GST:</td>
                    <td class="amount">₹<?= number_format($bill['gst_amount'], 2) ?></td>
                </tr>
                <tr>
                    <td class="label">Grand Total:</td>
                    <td class="amount">₹<?= number_format($bill['discounted_total'] ?? $bill['total_amount'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="bill-footer">
            <p>Thank you for choosing our services</p>
            <p>For any queries, please contact us</p>
        </div>
    </div>
    
    <!-- Controls -->
    <div class="controls no-print">
        <button onclick="window.print()" class="btn btn-primary">Print Bill</button>
        <form method="post" class="d-inline">
            <button type="submit" name="new_bill" class="btn btn-warning">New Bill</button>
        </form>
        <a href="index.php<?= isset($_GET['bill_created']) && $_GET['bill_created'] === 'true' ? '?bill_created=true&visit_id=' . $visit_id : '' ?>" class="btn btn-secondary">Back to Home</a>
    </div>
    
    <?php else: ?>
    <!-- Billing Interface -->
    <div class="bill-container">
        <h2 class="mb-4 no-print">Pharmacy Billing</h2>
        
        <?php if (!empty($all_bills)): ?>
        <div class="mb-3 no-print">
            <strong>Previous Bills for this Visit:</strong><br>
            <?php foreach ($all_bills as $b): ?>
                <a href="pharmacy_billing.php?visit_id=<?= $visit_id ?>&invoice_number=<?= urlencode($b['invoice_number']) ?>" class="btn btn-sm btn-outline-info mb-1" target="_blank">
                    <?= htmlspecialchars($b['invoice_number']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($item_discount_cleared) && $item_discount_cleared): ?>
            <div class="alert alert-warning no-print">All item-specific discounts have been removed because a total bill discount is now being applied.</div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger no-print"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Patient Info Card -->
        <div class="card mb-3 no-print">
            <div class="card-body">
                <strong>Patient:</strong> <?= htmlspecialchars($visit['full_name']) ?> <br>
                <strong>Gender:</strong> <?= htmlspecialchars($visit['gender']) ?> | 
                <strong>DOB:</strong> <?= htmlspecialchars($visit['dob']) ?> <br>
                <strong>Visit Date:</strong> <?= htmlspecialchars($visit['visit_date']) ?>
            </div>
        </div>
        
        <!-- Search Form -->
        <form method="get" class="mb-3 no-print">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <input type="text" class="form-control" name="search" placeholder="Search medicine by name" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </div>
        </form>
        
        <!-- Medicines Selection -->
        <?php if ($search !== ''): ?>
        <div class="table-responsive mb-3 no-print">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>HSN Code</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th>Unit Price</th>
                        <th>GST %</th>
                        <th>Available</th>
                        <th>Add</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medicines as $med): ?>
                    <tr>
                        <td><?= htmlspecialchars($med['medicine_name']) ?></td>
                        <td><?= htmlspecialchars($med['hsn_code'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($med['batch_no']) ?></td>
                        <td><?= htmlspecialchars($med['expiry_date']) ?></td>
                        <td><?= number_format($med['sale_price'], 2) ?></td>
                        <td><?= $med['gst_percent'] ?>%</td>
                        <td>
                            <?php if ($med['quantity'] > 0): ?>
                                <?= $med['quantity'] ?>
                            <?php else: ?>
                                <span class="text-danger">Out of Stock</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($med['quantity'] > 0): ?>
                                <button type="button" class="btn btn-success btn-sm add-medicine-btn" 
                                    data-med-id="<?= $med['id'] ?>" 
                                    data-med-name="<?= htmlspecialchars($med['medicine_name'], ENT_QUOTES) ?>"
                                    data-max-qty="<?= $med['quantity'] ?>">
                                    Add
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm" disabled>Out of Stock</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($medicines)): ?>
                    <tr><td colspan="8" class="text-center">No medicines found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php elseif ($search === ''): ?>
        <div class="alert alert-info no-print">
            Use the search box above to find and add medicines to the bill.
        </div>
        <?php endif; ?>
        
        <!-- Quantity Modal -->
        <div class="modal no-print" id="quantityModal">
            <div class="modal-content">
                <form method="post" id="quantityModalForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Enter Quantity</h5>
                        <button type="button" class="btn-close" onclick="closeModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                        <input type="hidden" name="add_medicine" id="modal_medicine_id">
                        <div class="mb-3">
                            <label for="modal_quantity" class="form-label">Quantity for <span id="modal_medicine_name"></span></label>
                            <input type="number" name="add_quantity" class="form-control" id="modal_quantity" min="1" value="1">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add to Bill</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (!$show_bill): ?>
        <!-- Bill List -->
        <h5 class="no-print">Bill List</h5>
        <div id="bill-list-section" class="no-print">
        <div class="table-responsive mb-3">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>HSN Code</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th>Unit Price</th>
                        <th>GST %</th>
                        <th>Qty</th>
                        <th>Item Discount</th>
                        <th>Remove</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Live summary variables
                    $live_subtotal = 0;
                    $live_total_item_discount = 0;
                    $live_items = [];
                    foreach ($bill_list as $item) {
                        $price = $item['unit_price'] * $item['quantity'];
                        $item_discount_type = isset($item_discounts[$item['medicine_id']]['type']) ? $item_discounts[$item['medicine_id']]['type'] : 'rupees';
                        $item_discount_value = isset($item_discounts[$item['medicine_id']]['value']) ? $item_discounts[$item['medicine_id']]['value'] : 0;
                        $item_discount = 0;
                        if ($item_discount_type === 'percent') {
                            $item_discount = $price * $item_discount_value / 100;
                        } else {
                            $item_discount = $item_discount_value;
                        }
                        $discounted_price = max(0, $price - $item_discount);
                        $live_subtotal += $price;
                        $live_total_item_discount += $item_discount;
                        $live_items[] = [
                            'price' => $discounted_price,
                            'gst_percent' => $item['gst_percent'],
                            'medicine_id' => $item['medicine_id'],
                            'quantity' => $item['quantity'],
                            'item_discount' => $item_discount
                        ];
                    }
                    // Total discount
                    $live_discount_amount = 0;
                    if ($discount_type === 'percent' && $discount_value > 0) {
                        $live_discount_amount = ($live_subtotal - $live_total_item_discount) * $discount_value / 100;
                    } elseif ($discount_type === 'rupees' && $discount_value > 0) {
                        $live_discount_amount = $discount_value;
                    }
                    $live_discounted_total = max(0, $live_subtotal - $live_total_item_discount - $live_discount_amount);
                    // GST
                    $live_gst_total = 0;
                    foreach ($live_items as &$item) {
                        $proportion = ($live_subtotal - $live_total_item_discount) > 0 ? $item['price'] / ($live_subtotal - $live_total_item_discount) : 0;
                        $item_total_discount = $live_discount_amount * $proportion;
                        $net_price = max(0, $item['price'] - $item_total_discount);
                        $item['gst'] = $net_price * $item['gst_percent'] / (100 + $item['gst_percent']);
                        $live_gst_total += $item['gst'];
                    }
                    unset($item);
                    ?>
                    <?php foreach ($bill_list as $item): ?>
                    <?php
                        $item_discount_type = isset($item_discounts[$item['medicine_id']]['type']) ? $item_discounts[$item['medicine_id']]['type'] : 'rupees';
                        $item_discount_value = isset($item_discounts[$item['medicine_id']]['value']) ? $item_discounts[$item['medicine_id']]['value'] : 0;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                        <td><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($item['batch_no']) ?></td>
                        <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                        <td><?= number_format($item['unit_price'], 2) ?></td>
                        <td><?= $item['gst_percent'] ?>%</td>
                        <td>
                            <form method="post" class="d-flex align-items-center auto-submit-form" style="gap:4px;">
                                <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                                <input type="number" name="new_quantity" class="form-control form-control-sm auto-submit-input" min="1" max="<?= $item['available'] ?>" value="<?= $item['quantity'] ?>" style="width:70px;display:inline-block;">
                                <input type="hidden" name="update_quantity" value="<?= $item['medicine_id'] ?>">
                            </form>
                        </td>
                        <td style="white-space: nowrap;">
                            <form method="post" class="d-inline auto-submit-form" style="display: flex; gap: 5px; align-items: center;">
                                <input type="hidden" name="item_id" value="<?= $item['medicine_id'] ?>">
                                <select name="item_discount_type" class="form-select form-select-sm auto-submit-input" style="width: 60px; padding: 4px;" <?= ($discount_type === 'percent' || $discount_type === 'rupees') && $discount_value > 0 ? 'disabled title=\'Disabled when total bill discount is applied\'' : '' ?>>
                                    <option value="rupees" <?= $item_discount_type==='rupees'?'selected':'' ?>>₹</option>
                                    <option value="percent" <?= $item_discount_type==='percent'?'selected':'' ?>>%</option>
                                </select>
                                <input type="number" name="item_discount_value" class="form-control form-control-sm auto-submit-input" style="width: 80px; padding: 4px;" min="0" step="0.01" value="<?= htmlspecialchars($item_discount_value) ?>" <?= ($discount_type === 'percent' || $discount_type === 'rupees') && $discount_value > 0 ? 'disabled title=\'Disabled when total bill discount is applied\'' : '' ?>>
                                <input type="hidden" name="set_item_discount" value="1">
                            </form>
                            <?php if (($discount_type === 'percent' || $discount_type === 'rupees') && $discount_value > 0): ?>
                                <div class="text-muted small">Disabled when total bill discount is applied</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                                <button type="submit" name="remove_medicine" value="<?= $item['medicine_id'] ?>" class="btn btn-danger btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bill_list)): ?>
                    <tr><td colspan="9" class="text-center">No medicines in bill.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Live Bill Summary -->
        <div class="table-responsive mb-3">
            <table class="table table-bordered w-auto ms-auto">
                <tr><th>Subtotal (Base Price)</th><td>₹<?= number_format(array_sum(array_map(function($item){ return $item['price'] / (1 + $item['gst_percent']/100); }, $live_items)),2) ?></td></tr>
                <tr><th>Total GST</th><td>₹<?= number_format(array_sum(array_map(function($item){ return $item['price'] - ($item['price'] / (1 + $item['gst_percent']/100)); }, $live_items)),2) ?></td></tr>
                <tr><th>Grand Total</th><td>₹<?= number_format($live_discounted_total,2) ?></td></tr>
            </table>
        </div>
        
        <!-- Total Discount and Bill Generation -->
        <form method="post">
            <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label for="discount_type" class="form-label">Total Discount Type</label>
                    <select class="form-select" name="discount_type" id="discount_type" onchange="toggleDiscountValueField()">
                        <option value="" <?= $discount_type===''?'selected':'' ?>>None</option>
                        <option value="percent" <?= $discount_type==='percent'?'selected':'' ?>>Percent (%)</option>
                        <option value="rupees" <?= $discount_type==='rupees'?'selected':'' ?>>Rupees (₹)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="discount_value" class="form-label">Total Discount Value</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="discount_value" id="discount_value" value="<?= htmlspecialchars($discount_value) ?>">
                </div>
                <div class="col-md-3 align-self-end">
                    <button type="submit" class="btn btn-outline-secondary" name="apply_total_discount">Apply Total Discount</button>
                </div>
            </div>
            <button type="submit" name="final_submit" class="btn btn-success" <?= empty($bill_list) ? 'disabled' : '' ?>>Generate Bill</button>
            <a href="index.php" class="btn btn-secondary ms-2">Back to Home</a>
        </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <script>
    function toggleDiscountValueField() {
        var type = document.getElementById('discount_type').value;
        var valueField = document.getElementById('discount_value');
        if (type === '') {
            valueField.value = '';
            valueField.setAttribute('disabled', 'disabled');
        } else {
            valueField.removeAttribute('disabled');
        }
    }
    
    function closeModal() {
        document.getElementById('quantityModal').style.display = 'none';
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        toggleDiscountValueField();
        
        // Modal functionality
        var quantityModal = document.getElementById('quantityModal');
        var modalMedicineId = document.getElementById('modal_medicine_id');
        var modalMedicineName = document.getElementById('modal_medicine_name');
        var modalQuantity = document.getElementById('modal_quantity');
        var quantityModalForm = document.getElementById('quantityModalForm');
        
        document.querySelectorAll('.add-medicine-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var medId = this.getAttribute('data-med-id');
                var medName = this.getAttribute('data-med-name');
                var maxQty = this.getAttribute('data-max-qty');
                modalMedicineId.value = medId;
                modalMedicineName.textContent = medName;
                modalQuantity.value = 1;
                modalQuantity.max = maxQty;
                quantityModal.style.display = 'block';
            });
        });
        
        quantityModalForm.addEventListener('submit', function(e) {
            if (parseInt(modalQuantity.value) < 1 || parseInt(modalQuantity.value) > parseInt(modalQuantity.max)) {
                alert('Please enter a valid quantity (1 to ' + modalQuantity.max + ')');
                e.preventDefault();
            }
        });
        
        // Auto-submit for bill list forms
        function attachAutoSubmitHandlers() {
            document.querySelectorAll('#bill-list-section .auto-submit-form').forEach(function(form) {
                form.querySelectorAll('.auto-submit-input').forEach(function(input) {
                    input.addEventListener('change', function(e) {
                        e.preventDefault();
                        var formData = new FormData(form);
                        fetch('pharmacy_billing_ajax.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(html => {
                            document.getElementById('bill-list-section').innerHTML = html;
                            attachAutoSubmitHandlers();
                        });
                    });
                });
            });
        }
        attachAutoSubmitHandlers();
    });
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        var modal = document.getElementById('quantityModal');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
    </script>
    
    <?php if ($show_bill && $bill && $bill_items && $auto_print): ?>
    <?php if (isset($_GET['print']) && $_GET['print'] == 1): ?>
    <script>window.onload = function() { window.print(); };</script>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html> 