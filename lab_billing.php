<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';
session_start();

// Always initialize subtotal and discounted_total to avoid undefined warnings
$subtotal = 0;
$discounted_total = 0;

function generate_lab_invoice_number($pdo) {
    $prefix = 'LAB-';
    $stmt = $pdo->query("SELECT invoice_number FROM lab_bills WHERE invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/LAB-(\\d+)/', $last, $m)) {
        $num = intval($m[1]) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
if (!$visit_id) {
    // Clear any existing lab billing session data if no visit ID is provided
    unset($_SESSION['lab_bill_list']);
    unset($_SESSION['lab_discount_type']);
    unset($_SESSION['lab_discount_value']);
    unset($_SESSION['lab_item_discounts']);
    unset($_SESSION['current_lab_visit_id']);
    die('Invalid visit ID.');
}

// Store current visit ID in session (only if not already set or if it's the same visit)
if (!isset($_SESSION['current_lab_visit_id']) || $_SESSION['current_lab_visit_id'] !== $visit_id) {
    $_SESSION['current_lab_visit_id'] = $visit_id;
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
$tests = [];
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE test_name LIKE ? ORDER BY test_name");
    $stmt->execute(['%' . $search . '%']);
    $tests = $stmt->fetchAll();
}
// Don't load all tests by default - only show when searched

// Initialize session variables if they don't exist
if (!isset($_SESSION['lab_bill_list'])) {
    $_SESSION['lab_bill_list'] = [];
}
if (!isset($_SESSION['lab_discount_type'])) {
    $_SESSION['lab_discount_type'] = '';
}
if (!isset($_SESSION['lab_discount_value'])) {
    $_SESSION['lab_discount_value'] = '';
}
if (!isset($_SESSION['lab_item_discounts'])) {
    $_SESSION['lab_item_discounts'] = [];
}

$bill_list = &$_SESSION['lab_bill_list'];

// Add test to bill list
if (isset($_POST['add_test'])) {
    $test_id = intval($_POST['add_test']);
    $qty = 1; // Always 1 for lab tests
    $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE id = ?");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch();
    if ($test) {
        $found = false;
        foreach ($bill_list as $idx => $item) {
            if ($item['test_id'] == $test_id) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $bill_list[] = [
                'test_id' => $test_id,
                'test_name' => $test['test_name'],
                'unit_price' => $test['price'],
                'quantity' => 1
            ];
        }
        $_SESSION['lab_bill_list'] = array_values($bill_list);
    }
}
// Remove test from bill list
if (isset($_POST['remove_test'])) {
    $remove_id = intval($_POST['remove_test']);
    $bill_list = array_filter($bill_list, function($item) use ($remove_id) {
        return $item['test_id'] != $remove_id;
    });
    $_SESSION['lab_bill_list'] = array_values($bill_list);
}

// Discount handling
$discount_type = isset($_POST['discount_type']) ? $_POST['discount_type'] : (isset($_SESSION['lab_discount_type']) ? $_SESSION['lab_discount_type'] : '');
$discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : (isset($_SESSION['lab_discount_value']) ? $_SESSION['lab_discount_value'] : '');

// Notification for item discount removal
$item_discount_cleared = false;
if (isset($_POST['discount_type']) && isset($_POST['apply_discount'])) {
    if (($discount_type === 'percent' || $discount_type === 'rupees') && !empty($_SESSION['lab_item_discounts'])) {
        $_SESSION['lab_item_discounts'] = [];
        $item_discount_cleared = true;
    }
    $_SESSION['lab_discount_type'] = $discount_type;
    $_SESSION['lab_discount_value'] = $discount_value;
}

// Handle per-item discounts in bill list (now with type)
if (!isset($_SESSION['lab_item_discounts'])) {
    $_SESSION['lab_item_discounts'] = [];
}
$item_discounts = &$_SESSION['lab_item_discounts'];
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
if (isset($_POST['final_submit'])) {
    $total = 0;
    $items = [];
    $subtotal = 0;
    $total_item_discount = 0;
    foreach ($bill_list as $item) {
        if ($item['quantity'] > 0) {
            $price = $item['unit_price'] * $item['quantity'];
            $item_discount_type = isset($item_discounts[$item['test_id']]['type']) ? $item_discounts[$item['test_id']]['type'] : 'rupees';
            $item_discount_value = isset($item_discounts[$item['test_id']]['value']) ? $item_discounts[$item['test_id']]['value'] : 0;
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
                'test_id' => $item['test_id'],
                'quantity' => $item['quantity'],
                'price' => $discounted_price,
                'test_name' => $item['test_name'],
                'unit_price' => $item['unit_price'],
                'item_discount' => $item_discount
            ];
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
    if (count($items) > 0) {
        // Generate invoice number
        $invoice_number = generate_lab_invoice_number($pdo);
        // Insert bill
        $stmt = $pdo->prepare("INSERT INTO lab_bills (visit_id, amount, paid, discount_type, discount_value, discounted_amount, invoice_number) VALUES (?, ?, 1, ?, ?, ?, ?)");
        if ($stmt->execute([$visit_id, $subtotal, $discount_type, $discount_value, $discounted_total, $invoice_number])) {
            $bill_id = $pdo->lastInsertId();
            foreach ($items as $item) {
                // Insert bill item
                $stmt = $pdo->prepare("INSERT INTO lab_bill_items (bill_id, test_id, amount) VALUES (?, ?, ?)");
                $stmt->execute([$bill_id, $item['test_id'], $item['price']]);
            }
            // Log to system log
            log_action('Reception', 'Lab Bill Generated', 'Invoice: ' . $invoice_number . ', Patient: ' . $visit['full_name'] . ', Amount: ' . $discounted_total);
            $success = true;
            $auto_print = true;
            $_SESSION['lab_bill_list'] = [];
            unset($_SESSION['lab_discount_type'], $_SESSION['lab_discount_value'], $_SESSION['lab_item_discounts']);
            // Redirect to printable bill
            header('Location: lab_billing.php?visit_id=' . $visit_id . '&invoice_number=' . urlencode($invoice_number) . '&print=1');
            exit;
        } else {
            $error = 'Failed to generate bill.';
        }
    } else {
        $error = 'No lab tests in bill.';
    }
}
// Handle 'New Bill' action
if (isset($_POST['new_bill'])) {
    $_SESSION['lab_bill_list'] = [];
    $_SESSION['lab_discount_type'] = '';
    $_SESSION['lab_discount_value'] = '';
    $_SESSION['lab_item_discounts'] = [];
    // Keep the current visit ID in session
    log_action('Reception', 'Lab Bill Started', 'Started new lab bill for Patient: ' . $visit['full_name'] . ', Visit ID: ' . $visit_id);
    header('Location: lab_billing.php?visit_id=' . $visit_id);
    exit;
}

// Handle manual session clearing
if (isset($_POST['clear_session'])) {
    $_SESSION['lab_bill_list'] = [];
    $_SESSION['lab_discount_type'] = '';
    $_SESSION['lab_discount_value'] = '';
    $_SESSION['lab_item_discounts'] = [];
    unset($_SESSION['current_lab_visit_id']);
    log_action('Reception', 'Lab Session Cleared', 'Manually cleared lab billing session for Visit ID: ' . $visit_id);
    header('Location: lab_billing.php?visit_id=' . $visit_id);
    exit;
}

// Handle "Go Back to Home" - clear session data
if (isset($_POST['go_back_home'])) {
    $_SESSION['lab_bill_list'] = [];
    $_SESSION['lab_discount_type'] = '';
    $_SESSION['lab_discount_value'] = '';
    $_SESSION['lab_item_discounts'] = [];
    unset($_SESSION['current_lab_visit_id']);
    log_action('Reception', 'Lab Session Cleared', 'Cleared lab billing session when going back to home for Visit ID: ' . $visit_id);
    header('Location: index.php');
    exit;
}
// Fetch bill and items if generated or if a bill exists for this visit
if ($success && $bill_id) {
    $bill = $pdo->prepare("SELECT * FROM lab_bills WHERE id = ?");
    $bill->execute([$bill_id]);
    $bill = $bill->fetch();
    $bill_items = $pdo->prepare("SELECT lbi.*, lt.test_name, lt.price FROM lab_bill_items lbi JOIN lab_tests lt ON lbi.test_id = lt.id WHERE lbi.bill_id = ?");
    $bill_items->execute([$bill_id]);
    $bill_items = $bill_items->fetchAll();
} else if (isset($_GET['invoice_number'])) {
    $bill = $pdo->prepare("SELECT * FROM lab_bills WHERE invoice_number = ?");
    $bill->execute([$_GET['invoice_number']]);
    $bill = $bill->fetch();
    if ($bill) {
        $bill_items = $pdo->prepare("SELECT lbi.*, lt.test_name, lt.price FROM lab_bill_items lbi JOIN lab_tests lt ON lbi.test_id = lt.id WHERE lbi.bill_id = ?");
        $bill_items->execute([$bill['id']]);
        $bill_items = $bill_items->fetchAll();
        $success = true;
        log_action('Reception', 'Lab Bill Viewed', 'Viewed lab bill Invoice: ' . $bill['invoice_number'] . ', Patient: ' . $visit['full_name'] . ', Amount: ' . $bill['discounted_amount']);
    }
}
// Fetch all previous bills for this visit
$all_bills = $pdo->prepare("SELECT * FROM lab_bills WHERE visit_id = ? ORDER BY id DESC");
$all_bills->execute([$visit_id]);
$all_bills = $all_bills->fetchAll();

// If invoice_number is set, fetch and display that bill
$show_bill = false;
if (isset($_GET['invoice_number'])) {
    $bill = $pdo->prepare("SELECT * FROM lab_bills WHERE invoice_number = ?");
    $bill->execute([$_GET['invoice_number']]);
    $bill = $bill->fetch();
    if ($bill) {
        $bill_items = $pdo->prepare("SELECT lbi.*, lt.test_name, lt.price FROM lab_bill_items lbi JOIN lab_tests lt ON lbi.test_id = lt.id WHERE lbi.bill_id = ?");
        $bill_items->execute([$bill['id']]);
        $bill_items = $bill_items->fetchAll();
        $success = true;
        log_action('Reception', 'Lab Bill Viewed', 'Viewed lab bill Invoice: ' . $bill['invoice_number'] . ', Patient: ' . $visit['full_name'] . ', Amount: ' . $bill['discounted_amount']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Billing - Ishtah Clinic</title>
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
            font-size: 10pt;
        }
        
        .bill-table th,
        .bill-table td {
            border: 1px solid #000;
            padding: 0.15in 0.2in;
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
        .text-muted { color: #6c757d; }
        
        /* Real-time search styles */
        #searchResults {
            margin-bottom: 20px;
        }
        
        #searchResults .table {
            margin-bottom: 0;
        }
        
        #searchResults .alert {
            margin-bottom: 0;
        }
        
        /* Loading animation */
        .searching {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        
        .searching::after {
            content: '';
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php if ($success && $bill && $bill_items): ?>
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
            <div class="bill-title">LABORATORY BILL</div>
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
                    <th style="width: 50%;">Test Name</th>
                    <th style="width: 20%;">Amount (₹)</th>
                    <th style="width: 15%;">Discount</th>
                    <th style="width: 15%;">Net (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_item_discount = 0;
                foreach ($bill_items as $row): 
                    $item_discount = isset($item_discounts[$row['test_id']]['value']) ? $item_discounts[$row['test_id']]['value'] : 0;
                    $total_item_discount += $item_discount;
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['test_name']) ?></td>
                    <td class="amount"><?= number_format($row['price'], 2) ?></td>
                    <td class="amount">
                        <?php if ($item_discount > 0): ?>
                            <?= isset($item_discounts[$row['test_id']]['type']) && $item_discounts[$row['test_id']]['type'] === 'percent' ? $item_discounts[$row['test_id']]['value'].'%' : '₹'.number_format($item_discount, 2) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="amount"><?= number_format($row['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Summary -->
        <div class="summary-section">
            <table class="summary-table">
                <tr>
                    <td class="label">Subtotal:</td>
                    <td class="amount">₹<?= number_format($bill['amount'], 2) ?></td>
                </tr>
                <tr>
                    <td class="label">Total Discount:</td>
                    <td class="amount">
                        <?php 
                        $total_discount_amount = $total_item_discount;
                        $bill_discount_amount = 0;
                        if ($bill['discount_type'] === 'percent' && $bill['discount_value'] > 0) {
                            $bill_discount_amount = ($bill['amount'] - $total_item_discount) * $bill['discount_value'] / 100;
                        } elseif ($bill['discount_type'] === 'rupees' && $bill['discount_value'] > 0) {
                            $bill_discount_amount = $bill['discount_value'];
                        }
                        $total_discount_amount += $bill_discount_amount;
                        $effective_discount_percent = ($bill['amount'] > 0) ? ($total_discount_amount / $bill['amount'] * 100) : 0;
                        echo '₹' . number_format($total_discount_amount, 2) . ' (' . number_format($effective_discount_percent, 2) . '%)';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">Total Amount:</td>
                    <td class="amount">₹<?= number_format($bill['discounted_amount'], 2) ?></td>
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
        <form method="post" class="d-inline">
            <button type="submit" name="go_back_home" class="btn btn-secondary">Back to Home</button>
        </form>
    </div>
    
    <?php else: ?>
    <!-- Billing Interface -->
    <div class="bill-container">
        <h2 class="mb-4 no-print">Lab Billing</h2>
        
        <?php if (!empty($all_bills)): ?>
        <div class="mb-3 no-print">
            <strong>Previous Bills for this Visit:</strong><br>
            <?php foreach ($all_bills as $b): ?>
                <a href="lab_billing.php?visit_id=<?= $visit_id ?>&invoice_number=<?= urlencode($b['invoice_number']) ?>" class="btn btn-sm btn-outline-info mb-1" target="_blank">
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
        
        <!-- Search Form -->
        <form method="get" class="mb-3 no-print">
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="text" name="search" id="searchInput" class="form-control" placeholder="Search Lab Test" value="<?= htmlspecialchars($search) ?>" style="flex: 1;" autocomplete="off">
                <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>
        
        <!-- Search Results Container -->
        <div id="searchResults" class="no-print"></div>
        
        <!-- Original Tests Selection (hidden when real-time search is active) -->
        <div id="originalSearchResults" class="no-print">
            <?php if (!empty($tests)): ?>
            <form method="post" class="no-print">
                <div class="table-responsive mb-3">
                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>Test</th>
                                <th>Price (₹)</th>
                                <th>Add</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tests as $test): ?>
                            <tr>
                                <td><?= htmlspecialchars($test['test_name']) ?></td>
                                <td><?= number_format($test['price'], 2) ?></td>
                                <td>
                                    <button name="add_test" value="<?= $test['id'] ?>" class="btn btn-sm btn-success">Add</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php elseif ($search !== ''): ?>
            <div class="alert alert-info no-print">
                No lab tests found matching "<?= htmlspecialchars($search) ?>". Try a different search term.
            </div>
            <?php else: ?>
            <div class="alert alert-info no-print">
                Use the search box above to find and add lab tests to the bill.
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Bill List -->
        <h5 class="no-print">Bill List</h5>
        <!-- Bill Items -->
        <form method="post" class="no-print mb-4">
            <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Test</th>
                            <th>Price (₹)</th>
                            <th>Item Discount</th>
                            <th>Net (₹)</th>
                            <th>Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bill_list)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">No tests added yet. Use the search above to add tests to the bill.</td>
                        </tr>
                        <?php else: ?>
                        <?php
                        $live_subtotal = 0;
                        $live_total_item_discount = 0;
                        foreach ($bill_list as $item) {
                            $price = $item['unit_price'] * $item['quantity'];
                            $item_discount_type = isset($item_discounts[$item['test_id']]['type']) ? $item_discounts[$item['test_id']]['type'] : 'rupees';
                            $item_discount_value = isset($item_discounts[$item['test_id']]['value']) ? $item_discounts[$item['test_id']]['value'] : 0;
                            $item_discount = 0;
                            if ($item_discount_type === 'percent') {
                                $item_discount = $price * $item_discount_value / 100;
                            } else {
                                $item_discount = $item_discount_value;
                            }
                            $discounted_price = max(0, $price - $item_discount);
                            $live_subtotal += $price;
                            $live_total_item_discount += $item_discount;
                        }
                        $live_discount_amount = 0;
                        if ($discount_type === 'percent' && $discount_value > 0) {
                            $live_discount_amount = ($live_subtotal - $live_total_item_discount) * $discount_value / 100;
                        } elseif ($discount_type === 'rupees' && $discount_value > 0) {
                            $live_discount_amount = $discount_value;
                        }
                        $live_discounted_total = max(0, $live_subtotal - $live_total_item_discount - $live_discount_amount);
                        ?>
                        <?php foreach ($bill_list as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['test_name']) ?></td>
                            <td><?= number_format($item['unit_price'], 2) ?></td>
                                                         <td style="white-space: nowrap;">
                                 <form method="post" class="d-inline" style="display: flex; gap: 5px; align-items: center;">
                                     <input type="hidden" name="item_id" value="<?= $item['test_id'] ?>">
                                     <?php $disable_item_discount = ($discount_type === 'percent' || $discount_type === 'rupees') && $discount_value > 0; ?>
                                     <select name="item_discount_type" class="form-select form-select-sm" style="width: 60px; padding: 4px;" <?= $disable_item_discount ? 'disabled' : '' ?>>
                                         <option value="rupees" <?= (isset($item_discounts[$item['test_id']]['type']) && $item_discounts[$item['test_id']]['type'] === 'rupees') ? 'selected' : '' ?>>₹</option>
                                         <option value="percent" <?= (isset($item_discounts[$item['test_id']]['type']) && $item_discounts[$item['test_id']]['type'] === 'percent') ? 'selected' : '' ?>>%</option>
                                     </select>
                                     <input type="number" step="0.01" min="0" name="item_discount_value" class="form-control form-control-sm" style="width: 80px; padding: 4px;" value="<?= isset($item_discounts[$item['test_id']]['value']) ? $item_discounts[$item['test_id']]['value'] : '' ?>" <?= $disable_item_discount ? 'disabled' : '' ?>>
                                     <button name="set_item_discount" value="1" class="btn btn-sm btn-outline-secondary" style="padding: 4px 8px; font-size: 10pt;" <?= $disable_item_discount ? 'disabled' : '' ?>>Set</button>
                                 </form>
                             </td>
                            <td>
                                <?php
                                $item_discount_type = isset($item_discounts[$item['test_id']]['type']) ? $item_discounts[$item['test_id']]['type'] : 'rupees';
                                $item_discount_value = isset($item_discounts[$item['test_id']]['value']) ? $item_discounts[$item['test_id']]['value'] : 0;
                                $item_discount = 0;
                                if ($item_discount_type === 'percent') {
                                    $item_discount = $item['unit_price'] * $item_discount_value / 100;
                                } else {
                                    $item_discount = $item_discount_value;
                                }
                                $net = max(0, $item['unit_price'] - $item_discount);
                                echo number_format($net, 2);
                                ?>
                            </td>
                            <td>
                                <form method="post" class="d-inline">
                                    <button name="remove_test" value="<?= $item['test_id'] ?>" class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Discount Form -->
            <div style="display: flex; gap: 20px; align-items: end; margin-bottom: 20px;">
                <div>
                    <label for="discount_type" class="form-label">Discount Type</label>
                    <select class="form-select" name="discount_type" id="discount_type" onchange="toggleDiscountValue()">
                        <option value="" <?= $discount_type===''?'selected':'' ?>>None</option>
                        <option value="percent" <?= $discount_type==='percent'?'selected':'' ?>>Percent (%)</option>
                        <option value="rupees" <?= $discount_type==='rupees'?'selected':'' ?>>Rupees (₹)</option>
                    </select>
                </div>
                <div>
                    <label for="discount_value" class="form-label">Discount Value</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="discount_value" id="discount_value" value="<?= htmlspecialchars($discount_value) ?>" <?= $discount_type === '' ? 'disabled' : '' ?>>
                </div>
                <div>
                    <button type="submit" name="apply_discount" class="btn btn-outline-secondary" id="apply_discount_btn" <?= $discount_type === '' ? 'disabled' : '' ?>>Apply Discount</button>
                </div>
            </div>
            
            <!-- Live Summary -->
            <div class="table-responsive mb-3">
                <table class="table table-bordered" style="width: auto; margin-left: auto;">
                    <tr><th>Subtotal</th><td>₹<?= number_format($live_subtotal, 2) ?></td></tr>
                    <tr><th>Total Item Discount</th><td>₹<?= number_format($live_total_item_discount, 2) ?></td></tr>
                    <tr><th>Total Discount</th><td>
                        <?php 
                        $live_total_discount_amount = $live_total_item_discount;
                        $live_bill_discount_amount = 0;
                        if ($discount_type === 'percent' && $discount_value > 0) {
                            $live_bill_discount_amount = ($live_subtotal - $live_total_item_discount) * $discount_value / 100;
                        } elseif ($discount_type === 'rupees' && $discount_value > 0) {
                            $live_bill_discount_amount = $discount_value;
                        }
                        $live_total_discount_amount += $live_bill_discount_amount;
                        $live_effective_discount_percent = ($live_subtotal > 0) ? ($live_total_discount_amount / $live_subtotal * 100) : 0;
                        echo '₹' . number_format($live_total_discount_amount, 2) . ' (' . number_format($live_effective_discount_percent, 2) . '%)';
                        ?>
                    </td></tr>
                    <tr><th>Discounted Total</th><td>₹<?= number_format($live_discounted_total, 2) ?></td></tr>
                </table>
            </div>
        </form>
        
        <!-- Generate Bill Button -->
        <form method="post" class="mt-4 no-print">
            <button name="final_submit" class="btn btn-success" <?= empty($bill_list) ? 'disabled' : '' ?>>Generate Bill</button>
            <button name="go_back_home" class="btn btn-secondary ms-2">Go Back to Home</button>
            <button name="clear_session" class="btn btn-danger ms-2">Clear Bill</button>
        </form>
    </div>
    <?php endif; ?>
</body>
<script>
function toggleDiscountValue() {
    const discountType = document.getElementById('discount_type').value;
    const discountValue = document.getElementById('discount_value');
    const applyDiscountBtn = document.getElementById('apply_discount_btn');
    
    if (discountType === '') {
        discountValue.disabled = true;
        discountValue.value = '';
        applyDiscountBtn.disabled = true;
    } else {
        discountValue.disabled = false;
        applyDiscountBtn.disabled = false;
    }
}

// Real-time search functionality
let searchTimeout;
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');
const originalSearchResults = document.getElementById('originalSearchResults');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        // Clear previous timeout
        clearTimeout(searchTimeout);
        
        // Clear results if query is empty
        if (query === '') {
            searchResults.innerHTML = '';
            if (originalSearchResults) {
                originalSearchResults.style.display = 'block';
            }
            return;
        }
        
        // Hide original search results when typing
        if (originalSearchResults) {
            originalSearchResults.style.display = 'none';
        }
        
        // Set timeout to avoid too many requests
        searchTimeout = setTimeout(function() {
            performSearch(query);
        }, 300); // 300ms delay
    });
}

function performSearch(query) {
    const visitId = <?= $visit_id ?>;
    
    // Show loading indicator
    searchResults.innerHTML = '<div class="searching">Searching for lab tests...</div>';
    
    // Create AJAX request
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'lab_search_ajax.php?search=' + encodeURIComponent(query) + '&visit_id=' + visitId, true);
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                searchResults.innerHTML = xhr.responseText;
            } else {
                searchResults.innerHTML = '<div class="alert alert-danger">Error occurred while searching. Please try again.</div>';
            }
        }
    };
    
    xhr.send();
}

document.addEventListener('DOMContentLoaded', function() {
    toggleDiscountValue();
});
</script>
</html> 