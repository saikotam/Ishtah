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

function generate_ultrasound_invoice_number($pdo) {
    $prefix = 'US-';
    $stmt = $pdo->query("SELECT invoice_number FROM ultrasound_bills WHERE invoice_number IS NOT NULL ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/US-(\\d+)/', $last, $m)) {
        $num = intval($m[1]) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
if (!$visit_id) {
    // Clear any existing ultrasound billing session data if no visit ID is provided
    unset($_SESSION['ultrasound_bill_list']);
    unset($_SESSION['ultrasound_discount_type']);
    unset($_SESSION['ultrasound_discount_value']);
    unset($_SESSION['ultrasound_item_discounts']);
    unset($_SESSION['current_ultrasound_visit_id']);
    unset($_SESSION['ultrasound_referring_doctor_id']);
    unset($_SESSION['form_f_required']);
    unset($_SESSION['form_f_scan_names']);
    die('Invalid visit ID.');
}

// Store current visit ID in session (only if not already set or if it's the same visit)
if (!isset($_SESSION['current_ultrasound_visit_id']) || $_SESSION['current_ultrasound_visit_id'] !== $visit_id) {
    $_SESSION['current_ultrasound_visit_id'] = $visit_id;
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
$scans = [];
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM ultrasound_scans WHERE scan_name LIKE ? AND is_active = 1 ORDER BY scan_name");
    $stmt->execute(['%' . $search . '%']);
    $scans = $stmt->fetchAll();
}

// Initialize session variables if they don't exist
if (!isset($_SESSION['ultrasound_bill_list'])) {
    $_SESSION['ultrasound_bill_list'] = [];
}
if (!isset($_SESSION['ultrasound_discount_type'])) {
    $_SESSION['ultrasound_discount_type'] = '';
}
if (!isset($_SESSION['ultrasound_discount_value'])) {
    $_SESSION['ultrasound_discount_value'] = '';
}
if (!isset($_SESSION['ultrasound_item_discounts'])) {
    $_SESSION['ultrasound_item_discounts'] = [];
}
if (!isset($_SESSION['ultrasound_referring_doctor_id'])) {
    $_SESSION['ultrasound_referring_doctor_id'] = '';
}

$bill_list = &$_SESSION['ultrasound_bill_list'];

// Add scan to bill list
if (isset($_POST['add_scan'])) {
    $scan_id = intval($_POST['add_scan']);
    $qty = 1; // Always 1 for ultrasound scans
    $stmt = $pdo->prepare("SELECT * FROM ultrasound_scans WHERE id = ? AND is_active = 1");
    $stmt->execute([$scan_id]);
    $scan = $stmt->fetch();
    if ($scan) {
        $found = false;
        foreach ($bill_list as $idx => $item) {
            if ($item['scan_id'] == $scan_id) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $bill_list[] = [
                'scan_id' => $scan_id,
                'scan_name' => $scan['scan_name'],
                'unit_price' => $scan['price'],
                'quantity' => 1,
                'is_form_f_needed' => $scan['is_form_f_needed'] ?? false
            ];
        }
        $_SESSION['ultrasound_bill_list'] = array_values($bill_list);
    }
}

// Remove scan from bill list
if (isset($_POST['remove_scan'])) {
    $remove_id = intval($_POST['remove_scan']);
    $bill_list = array_filter($bill_list, function($item) use ($remove_id) {
        return $item['scan_id'] != $remove_id;
    });
    $_SESSION['ultrasound_bill_list'] = array_values($bill_list);
}

// Discount handling
$discount_type = isset($_POST['discount_type']) ? $_POST['discount_type'] : (isset($_SESSION['ultrasound_discount_type']) ? $_SESSION['ultrasound_discount_type'] : '');
$discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : (isset($_SESSION['ultrasound_discount_value']) ? floatval($_SESSION['ultrasound_discount_value']) : 0);
$_SESSION['ultrasound_discount_type'] = $discount_type;
$_SESSION['ultrasound_discount_value'] = $discount_value;

// Item discount handling
$item_discounts = isset($_SESSION['ultrasound_item_discounts']) ? $_SESSION['ultrasound_item_discounts'] : [];
if (isset($_POST['set_item_discount'])) {
    $item_id = intval($_POST['item_id']);
    $item_discount_type = $_POST['item_discount_type'] ?? 'rupees';
    $item_discount_value = floatval($_POST['item_discount_value'] ?? 0);
    $item_discounts[$item_id] = ['type' => $item_discount_type, 'value' => $item_discount_value];
    $_SESSION['ultrasound_item_discounts'] = $item_discounts;
}

// Referring doctor handling
$referring_doctor_id = isset($_POST['referring_doctor_id']) ? $_POST['referring_doctor_id'] : (isset($_SESSION['ultrasound_referring_doctor_id']) ? $_SESSION['ultrasound_referring_doctor_id'] : '');
$_SESSION['ultrasound_referring_doctor_id'] = $referring_doctor_id;

$success = false;
$error = '';
$bill_id = null;
$auto_print = false;

if (isset($_POST['final_submit'])) {
    // Validate that referring doctor is selected
    if (empty($referring_doctor_id)) {
        $error = 'Please select a referring doctor before generating the bill.';
    } else {
        $total = 0;
        $items = [];
        $subtotal = 0;
        $total_item_discount = 0;
    foreach ($bill_list as $item) {
        if ($item['quantity'] > 0) {
            $price = $item['unit_price'] * $item['quantity'];
            $item_discount_type = isset($item_discounts[$item['scan_id']]['type']) ? $item_discounts[$item['scan_id']]['type'] : 'rupees';
            $item_discount_value = isset($item_discounts[$item['scan_id']]['value']) ? $item_discounts[$item['scan_id']]['value'] : 0;
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
                'scan_id' => $item['scan_id'],
                'quantity' => $item['quantity'],
                'price' => $discounted_price,
                'scan_name' => $item['scan_name'],
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
        $invoice_number = generate_ultrasound_invoice_number($pdo);
        
        // Insert bill
        $stmt = $pdo->prepare("INSERT INTO ultrasound_bills (visit_id, referring_doctor_id, total_amount, discount_type, discount_value, discounted_total, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$visit_id, $referring_doctor_id ?: null, $subtotal, $discount_type, $discount_value, $discounted_total, $invoice_number])) {
            $bill_id = $pdo->lastInsertId();
            
            foreach ($items as $item) {
                // Insert bill item
                $stmt = $pdo->prepare("INSERT INTO ultrasound_bill_items (bill_id, scan_id, amount) VALUES (?, ?, ?)");
                $stmt->execute([$bill_id, $item['scan_id'], $item['price']]);
            }
            
            // Create doctor incentive record if referring doctor is selected
            if ($referring_doctor_id) {
                $stmt = $pdo->prepare("SELECT incentive_percentage FROM referring_doctors WHERE id = ?");
                $stmt->execute([$referring_doctor_id]);
                $incentive_percentage = $stmt->fetchColumn() ?: 0;
                
                if ($incentive_percentage > 0) {
                    $incentive_amount = $discounted_total * $incentive_percentage / 100;
                    $stmt = $pdo->prepare("INSERT INTO doctor_incentives (ultrasound_bill_id, referring_doctor_id, incentive_amount, incentive_percentage, bill_amount) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$bill_id, $referring_doctor_id, $incentive_amount, $incentive_percentage, $discounted_total]);
                }
            }
            
            // Create accounting journal entry
            try {
                require_once __DIR__ . '/Accounting/accounting.php';
                $accounting = new AccountingSystem($pdo);
                // Default payment mode to cash for ultrasound billing (already marked paid)
                $payment_mode = 'cash';
                $accounting->recordUltrasoundRevenue($bill_id, $discounted_total, $payment_mode);
            } catch (Exception $e) {
                error_log('Accounting entry failed for ultrasound bill ' . $bill_id . ': ' . $e->getMessage());
            }
            
            // Log to system log
            log_action('Reception', 'Ultrasound Bill Generated', 'Invoice: ' . $invoice_number . ', Patient: ' . $visit['full_name'] . ', Amount: ' . $discounted_total . ($referring_doctor_id ? ', Referred by: ' . $referring_doctor_id : ''));
            
            $success = true;
            $auto_print = true;
            $_SESSION['ultrasound_bill_list'] = [];
            unset($_SESSION['ultrasound_discount_type'], $_SESSION['ultrasound_discount_value'], $_SESSION['ultrasound_item_discounts'], $_SESSION['ultrasound_referring_doctor_id'], $_SESSION['form_f_required'], $_SESSION['form_f_scan_names']);
            
            // Check if Form F is needed for any scans
            $has_form_f_scans = false;
            $form_f_scan_names = [];
            foreach ($bill_list as $item) {
                if (isset($item['is_form_f_needed']) && $item['is_form_f_needed']) {
                    $has_form_f_scans = true;
                    $form_f_scan_names[] = $item['scan_name'];
                }
            }
            
            // Store Form F information in session for display
            $_SESSION['form_f_required'] = $has_form_f_scans;
            $_SESSION['form_f_scan_names'] = $form_f_scan_names;
            
            // Redirect to printable bill
            header('Location: ultrasound_billing.php?visit_id=' . $visit_id . '&invoice_number=' . urlencode($invoice_number) . '&print=1&bill_created=true');
            exit;
        } else {
            $error = 'Failed to generate bill.';
        }
        } else {
            $error = 'No ultrasound scans in bill.';
        }
    }
}

// Handle 'New Bill' action
if (isset($_POST['new_bill'])) {
    $_SESSION['ultrasound_bill_list'] = [];
    $_SESSION['ultrasound_discount_type'] = '';
    $_SESSION['ultrasound_discount_value'] = '';
    $_SESSION['ultrasound_item_discounts'] = [];
    $_SESSION['ultrasound_referring_doctor_id'] = '';
    unset($_SESSION['form_f_required'], $_SESSION['form_f_scan_names']);
    // Keep the current visit ID in session
    log_action('Reception', 'Ultrasound Bill Started', 'Started new ultrasound bill for Patient: ' . $visit['full_name'] . ', Visit ID: ' . $visit_id);
    header('Location: ultrasound_billing.php?visit_id=' . $visit_id);
    exit;
}

// Handle manual session clearing
if (isset($_POST['clear_session'])) {
    $_SESSION['ultrasound_bill_list'] = [];
    $_SESSION['ultrasound_discount_type'] = '';
    $_SESSION['ultrasound_discount_value'] = '';
    $_SESSION['ultrasound_item_discounts'] = [];
    $_SESSION['ultrasound_referring_doctor_id'] = '';
    unset($_SESSION['current_ultrasound_visit_id'], $_SESSION['form_f_required'], $_SESSION['form_f_scan_names']);
    log_action('Reception', 'Ultrasound Session Cleared', 'Manually cleared ultrasound billing session for Visit ID: ' . $visit_id);
    header('Location: ultrasound_billing.php?visit_id=' . $visit_id);
    exit;
}

// Handle "Go Back to Home" - clear session data
if (isset($_POST['go_back_home'])) {
    $_SESSION['ultrasound_bill_list'] = [];
    $_SESSION['ultrasound_discount_type'] = '';
    $_SESSION['ultrasound_discount_value'] = '';
    $_SESSION['ultrasound_item_discounts'] = [];
    $_SESSION['ultrasound_referring_doctor_id'] = '';
    unset($_SESSION['current_ultrasound_visit_id'], $_SESSION['form_f_required'], $_SESSION['form_f_scan_names']);
    log_action('Reception', 'Ultrasound Session Cleared', 'Cleared ultrasound billing session when going back to home for Visit ID: ' . $visit_id);
    header('Location: index.php');
    exit;
}

// Fetch bill and items if generated or if a bill exists for this visit
if ($success && $bill_id) {
    $bill = $pdo->prepare("SELECT * FROM ultrasound_bills WHERE id = ?");
    $bill->execute([$bill_id]);
    $bill = $bill->fetch();
    $bill_items = $pdo->prepare("SELECT ubi.*, us.scan_name, us.price FROM ultrasound_bill_items ubi JOIN ultrasound_scans us ON ubi.scan_id = us.id WHERE ubi.bill_id = ?");
    $bill_items->execute([$bill_id]);
    $bill_items = $bill_items->fetchAll();
} else {
    // Check if there's an existing bill for this visit
    $existing_bill = $pdo->prepare("SELECT * FROM ultrasound_bills WHERE visit_id = ? ORDER BY id DESC LIMIT 1");
    $existing_bill->execute([$visit_id]);
    $bill = $existing_bill->fetch();
    
    if ($bill) {
        $bill_items = $pdo->prepare("SELECT ubi.*, us.scan_name, us.price FROM ultrasound_bill_items ubi JOIN ultrasound_scans us ON ubi.scan_id = us.id WHERE ubi.bill_id = ?");
        $bill_items->execute([$bill['id']]);
        $bill_items = $bill_items->fetchAll();
    } else {
        $bill = null;
        $bill_items = [];
    }
}

// Fetch referring doctors for dropdown
$referring_doctors = $pdo->query("SELECT id, name, specialty, incentive_percentage FROM referring_doctors WHERE is_active = 1 ORDER BY name")->fetchAll();

// Fetch recent bills for this visit
$recent_bills = $pdo->prepare("SELECT invoice_number, discounted_total, created_at FROM ultrasound_bills WHERE visit_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_bills->execute([$visit_id]);
$recent_bills = $recent_bills->fetchAll();

// Calculate totals for display
$subtotal = 0;
$total_discount = 0;
$discounted_total = 0;

foreach ($bill_list as $item) {
    $subtotal += $item['unit_price'] * $item['quantity'];
}

// Apply item discounts
$total_item_discount = 0;
foreach ($bill_list as $item) {
    if (isset($item_discounts[$item['scan_id']])) {
        $discount_info = $item_discounts[$item['scan_id']];
        $item_total = $item['unit_price'] * $item['quantity'];
        if ($discount_info['type'] === 'percent') {
            $total_item_discount += $item_total * $discount_info['value'] / 100;
        } else {
            $total_item_discount += $discount_info['value'];
        }
    }
}

// Apply total discount
if ($discount_type === 'percent' && $discount_value > 0) {
    $total_discount = ($subtotal - $total_item_discount) * $discount_value / 100;
} elseif ($discount_type === 'rupees' && $discount_value > 0) {
    $total_discount = $discount_value;
}

$discounted_total = max(0, $subtotal - $total_item_discount - $total_discount);

// Handle print mode
$print_mode = isset($_GET['print']) && $_GET['print'] == '1';
$invoice_number = isset($_GET['invoice_number']) ? $_GET['invoice_number'] : '';

if ($print_mode && $invoice_number) {
    $stmt = $pdo->prepare("SELECT * FROM ultrasound_bills WHERE invoice_number = ?");
    $stmt->execute([$invoice_number]);
    $bill = $stmt->fetch();
    if ($bill) {
        $stmt = $pdo->prepare("SELECT ubi.*, us.scan_name, us.price FROM ultrasound_bill_items ubi JOIN ultrasound_scans us ON ubi.scan_id = us.id WHERE ubi.bill_id = ?");
        $stmt->execute([$bill['id']]);
        $bill_items = $stmt->fetchAll();
        
        // Fetch referring doctor details if available
        $referring_doctor_name = '';
        if ($bill['referring_doctor_id']) {
            $stmt = $pdo->prepare("SELECT name, specialty FROM referring_doctors WHERE id = ?");
            $stmt->execute([$bill['referring_doctor_id']]);
            $doctor = $stmt->fetch();
            if ($doctor) {
                $referring_doctor_name = $doctor['name'] . ' (' . $doctor['specialty'] . ')';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $print_mode ? 'Print Ultrasound Bill' : 'Ultrasound Billing' ?> - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Reset and base styles */
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
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
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .amount {
            text-align: right;
        }
        
        /* Summary section */
        .summary-section {
            margin-bottom: 0.4in;
        }
        
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
        }
        
        .summary-table td {
            padding: 0.1in 0.2in;
            border-bottom: 1px solid #ddd;
        }
        
        .summary-table .label {
            font-weight: bold;
            text-align: left;
        }
        
        .summary-table .amount {
            text-align: right;
            font-weight: bold;
        }
        
        /* Footer */
        .bill-footer {
            text-align: center;
            margin-top: 0.5in;
            padding-top: 0.3in;
            border-top: 1px solid #ddd;
            font-size: 10pt;
        }
        
        .bill-footer p {
            margin: 0.1in 0;
        }
        
        /* Controls */
        .controls {
            text-align: center;
            margin-top: 20px;
            padding: 20px;
        }
        

        
        .d-inline {
            display: inline;
        }
        
        .print-only { display: none; }
    </style>
</head>
<body>
<?php if ($print_mode && $bill): ?>
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
            <div class="bill-title">ULTRASOUND BILL</div>
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
                    <th style="width: 60%;">Scan Name</th>
                    <th style="width: 40%;">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bill_items as $i => $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['scan_name']) ?></td>
                    <td class="amount"><?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Summary -->
        <div class="summary-section">
            <table class="summary-table">
                <tr>
                    <td class="label">Total Amount:</td>
                    <td class="amount">₹<?= number_format($bill['discounted_total'], 2) ?></td>
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
        <?php if (isset($_GET['bill_created']) && $_GET['bill_created'] === 'true'): ?>
            <a href="index.php?bill_created=true&visit_id=<?= $visit_id ?>" class="btn btn-secondary">Back to Home</a>
        <?php else: ?>
            <a href="ultrasound_billing.php?visit_id=<?= $visit_id ?>" class="btn btn-secondary">Back to Billing</a>
        <?php endif; ?>
    </div>
    
    <script>
        window.onload = function() {
            // Print the bill
            window.print();
        };
    </script>
<?php else: ?>
    <!-- Normal Mode -->
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2>Ultrasound Billing</h2>
                    <div>
                        <a href="index.php" class="btn btn-secondary">Back to Home</a>
                    </div>
                </div>
                
                <!-- Patient Info -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Patient Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Name:</strong> <?= htmlspecialchars($visit['full_name']) ?></p>
                                <p><strong>Gender:</strong> <?= htmlspecialchars($visit['gender']) ?></p>
                                <p><strong>DOB:</strong> <?= htmlspecialchars($visit['dob']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Visit ID:</strong> <?= $visit_id ?></p>
                                <p><strong>Visit Date:</strong> <?= date('d/m/Y', strtotime($visit['visit_date'])) ?></p>
                                <?php if ($referring_doctor_id): ?>
                                    <?php 
                                    $selected_doctor = null;
                                    foreach ($referring_doctors as $doctor) {
                                        if ($doctor['id'] == $referring_doctor_id) {
                                            $selected_doctor = $doctor;
                                            break;
                                        }
                                    }
                                    ?>
                                    <p><strong>Referring Doctor:</strong> <span class="text-success"><?= htmlspecialchars($selected_doctor['name']) ?> (<?= htmlspecialchars($selected_doctor['specialty']) ?>)</span></p>
                                <?php else: ?>
                                    <p><strong>Referring Doctor:</strong> <span class="text-danger">Not selected</span></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Referring Doctor Selection -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Referring Doctor <span class="text-danger">*</span></h5>
                        <form method="post" class="row g-3">
                            <div class="col-md-12">
                                <select name="referring_doctor_id" class="form-select" onchange="this.form.submit()" required>
                                    <option value="">Select Referring Doctor (Required)</option>
                                    <?php foreach ($referring_doctors as $doctor): ?>
                                        <option value="<?= $doctor['id'] ?>" <?= $referring_doctor_id == $doctor['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($doctor['name']) ?> (<?= htmlspecialchars($doctor['specialty']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text text-danger">Referring doctor is required for ultrasound billing</div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Scan Search -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Add Ultrasound Scans</h5>
                        <form method="get" class="row g-3">
                            <input type="hidden" name="visit_id" value="<?= $visit_id ?>">
                            <div class="col-md-8">
                                <input type="text" name="search" class="form-control" placeholder="Search ultrasound scans..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary">Search</button>
                                <a href="ultrasound_billing.php?visit_id=<?= $visit_id ?>" class="btn btn-secondary">Clear</a>
                            </div>
                        </form>
                        
                        <!-- Available Scans Section -->
                        <div class="mt-4">
                            <h6 class="text-primary">
                                <i class="fas fa-ultrasound"></i> 
                                Available Ultrasound Scans
                            </h6>
                        
                        <?php if ($search && !empty($scans)): ?>
                        <div class="mt-3">
                            <h6>Search Results:</h6>
                            <div class="row">
                                <?php foreach ($scans as $scan): ?>
                                <div class="col-md-12 mb-3">
                                    <div class="d-flex align-items-start">
                                        <form method="post" class="me-3">
                                                                                         <button type="submit" name="add_scan" value="<?= $scan['id'] ?>" class="btn btn-outline-success btn-sm">
                                                 <?= htmlspecialchars($scan['scan_name']) ?> - ₹<?= number_format($scan['price'], 2) ?>
                                                 <?php if (isset($scan['is_form_f_needed']) && $scan['is_form_f_needed']): ?>
                                                     <span class="badge bg-warning text-dark ms-1">Form F</span>
                                                 <?php endif; ?>
                                             </button>
                                        </form>
                                        <?php if (!empty($scan['description'])): ?>
                                        <div class="flex-grow-1">
                                            <small class="text-muted"><?= htmlspecialchars($scan['description']) ?></small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php elseif ($search): ?>
                        <div class="mt-3">
                            <p class="text-muted">No scans found matching "<?= htmlspecialchars($search) ?>"</p>
                        </div>
                        <?php endif; ?>
                        
                                                 <!-- Display all available scans by default -->
                         <?php
                         // Fetch all active scans for display
                         $all_scans_stmt = $pdo->prepare("SELECT * FROM ultrasound_scans WHERE is_active = 1 ORDER BY scan_name");
                         $all_scans_stmt->execute();
                         $all_scans = $all_scans_stmt->fetchAll();
                         ?>
                         
                         <?php if (!empty($all_scans)): ?>
                         <div class="mt-4">
                             <div class="row">
                                 <?php foreach ($all_scans as $scan): ?>
                                 <div class="col-md-12 mb-3">
                                     <div class="d-flex align-items-start">
                                         <form method="post" class="me-3">
                                             <button type="submit" name="add_scan" value="<?= $scan['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                 <?= htmlspecialchars($scan['scan_name']) ?> - ₹<?= number_format($scan['price'], 2) ?>
                                                 <?php if (isset($scan['is_form_f_needed']) && $scan['is_form_f_needed']): ?>
                                                     <span class="badge bg-warning text-dark ms-1">Form F</span>
                                                 <?php endif; ?>
                                             </button>
                                         </form>
                                         <?php if (!empty($scan['description'])): ?>
                                         <div class="flex-grow-1">
                                             <small class="text-muted"><?= htmlspecialchars($scan['description']) ?></small>
                                         </div>
                                         <?php endif; ?>
                                     </div>
                                 </div>
                                 <?php endforeach; ?>
                             </div>
                         </div>
                         <?php endif; ?>
                    </div>
                </div>
                
                <!-- Bill Items -->
                <?php if (!empty($bill_list)): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Bill Items</h5>
                        
                        <?php
                        // Check for Form F scans
                        $has_form_f_scans = false;
                        foreach ($bill_list as $item) {
                            if (isset($item['is_form_f_needed']) && $item['is_form_f_needed']) {
                                $has_form_f_scans = true;
                                break;
                            }
                        }
                        ?>
                        
                        <?php if ($has_form_f_scans): ?>
                        <div class="alert alert-warning mb-3">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Form F Collection Required
                            </h6>
                            <p class="mb-0">
                                <strong>Please collect the following documents before proceeding:</strong>
                            </p>
                            <ul class="mb-2">
                                <li>Form F (filled and signed)</li>
                                <li>Patient's Aadhar Card</li>
                                <li>Referring Doctor's Slip</li>
                            </ul>
                            <p class="mb-0 text-danger">
                                <strong>Note:</strong> These documents are mandatory for scans marked with Form F requirement.
                            </p>
                        </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Scan</th>
                                        <th>Price</th>
                                        <th>Qty</th>
                                        <th>Total</th>
                                        <th>Item Discount</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bill_list as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['scan_name']) ?></td>
                                        <td>₹<?= number_format($item['unit_price'], 2) ?></td>
                                        <td><?= $item['quantity'] ?></td>
                                        <td>₹<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
                                        <td>
                                            <?php
                                            $item_discount = 0;
                                            if (isset($item_discounts[$item['scan_id']])) {
                                                $discount_info = $item_discounts[$item['scan_id']];
                                                $item_total = $item['unit_price'] * $item['quantity'];
                                                if ($discount_info['type'] === 'percent') {
                                                    $item_discount = $item_total * $discount_info['value'] / 100;
                                                } else {
                                                    $item_discount = $discount_info['value'];
                                                }
                                            }
                                            ?>
                                            ₹<?= number_format($item_discount, 2) ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#itemDiscountModal" data-item-id="<?= $item['scan_id'] ?>" data-item-name="<?= htmlspecialchars($item['scan_name']) ?>">
                                                Set
                                            </button>
                                        </td>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <button type="submit" name="remove_scan" value="<?= $item['scan_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Remove this scan?')">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Discount and Total -->
                <?php if (!empty($bill_list)): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Discount & Total</h5>
                        <form method="post" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Discount Type</label>
                                <select name="discount_type" class="form-select">
                                    <option value="">No Discount</option>
                                    <option value="rupees" <?= $discount_type === 'rupees' ? 'selected' : '' ?>>Rupees</option>
                                    <option value="percent" <?= $discount_type === 'percent' ? 'selected' : '' ?>>Percentage</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Discount Value</label>
                                <input type="number" name="discount_value" class="form-control" step="0.01" min="0" value="<?= $discount_value ?>">
                            </div>
                            <div class="col-md-6">
                                <button type="submit" class="btn btn-outline-primary">Apply Discount</button>
                            </div>
                        </form>
                        
                        <div class="row mt-3">
                            <div class="col-md-6 offset-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Subtotal:</strong></td>
                                        <td class="text-end">₹<?= number_format($subtotal, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Item Discounts:</strong></td>
                                        <td class="text-end">-₹<?= number_format($total_item_discount, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Total Discount:</strong></td>
                                        <td class="text-end">-₹<?= number_format($total_discount, 2) ?></td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td><strong>Final Total:</strong></td>
                                        <td class="text-end"><strong>₹<?= number_format($discounted_total, 2) ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Referring Doctor:</strong></td>
                                        <td class="text-end">
                                            <?php if ($referring_doctor_id): ?>
                                                <span class="text-success">✓ Selected</span>
                                            <?php else: ?>
                                                <span class="text-danger">✗ Required</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3">
                            <form method="post" class="d-inline" id="generateBillForm">
                                <button type="submit" name="final_submit" class="btn btn-success btn-lg" onclick="return validateBillGeneration()">
                                    Generate Bill
                                </button>
                            </form>
                        </div>
                        
                        <!-- Hidden input to store Form F scan information -->
                        <input type="hidden" id="formFScans" value="<?php 
                            $form_f_scan_names = [];
                            foreach ($bill_list as $item) {
                                if (isset($item['is_form_f_needed']) && $item['is_form_f_needed']) {
                                    $form_f_scan_names[] = $item['scan_name'];
                                }
                            }
                            echo htmlspecialchars(json_encode($form_f_scan_names));
                        ?>">
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Session Management -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Session Management</h5>
                        <form method="post" class="d-inline">
                            <button type="submit" name="new_bill" class="btn btn-outline-primary">New Bill</button>
                        </form>
                        <form method="post" class="d-inline">
                            <button type="submit" name="clear_session" class="btn btn-outline-warning">Clear Session</button>
                        </form>
                        <form method="post" class="d-inline">
                            <button type="submit" name="go_back_home" class="btn btn-outline-secondary">Go Back to Home</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Sidebar -->
            <div class="col-md-4">
                <!-- Error/Success Messages -->
                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success">
                    Bill generated successfully!
                </div>
                <?php endif; ?>
                
                <!-- Quick Actions -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Quick Actions</h5>
                        <div class="d-grid gap-2">
                            <a href="lab_billing.php?visit_id=<?= $visit_id ?>" class="btn btn-outline-success">Lab Billing</a>
                            <a href="pharmacy_billing.php?visit_id=<?= $visit_id ?>" class="btn btn-outline-warning">Pharmacy Billing</a>
                            <a href="prescription.php?visit_id=<?= $visit_id ?>" class="btn btn-outline-info">Print Prescription</a>
                            <?php if ($recent_bills): ?>
                            <a href="ultrasound_billing.php?visit_id=<?= $visit_id ?>&invoice_number=<?= urlencode($recent_bills[0]['invoice_number']) ?>&print=1" 
                               class="btn btn-outline-primary" target="_blank">
                                Print Latest Bill
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Bills -->
                <?php if ($recent_bills): ?>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Recent Ultrasound Bills</h5>
                        <?php foreach ($recent_bills as $recent_bill): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <span><?= htmlspecialchars($recent_bill['invoice_number']) ?></span>
                                <br><small class="text-muted"><?= date('d/m/Y', strtotime($recent_bill['created_at'])) ?></small>
                            </div>
                            <div class="text-end">
                                <span>₹<?= number_format($recent_bill['discounted_total'], 2) ?></span>
                                <br>
                                <a href="ultrasound_billing.php?visit_id=<?= $visit_id ?>&invoice_number=<?= urlencode($recent_bill['invoice_number']) ?>&print=1" 
                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                    Print
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Item Discount Modal -->
<div class="modal fade" id="itemDiscountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Set Item Discount</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="modalItemId">
                    <div class="mb-3">
                        <label class="form-label">Item: <span id="modalItemName"></span></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount Type</label>
                        <select name="item_discount_type" class="form-select">
                            <option value="rupees">Rupees</option>
                            <option value="percent">Percentage</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount Value</label>
                        <input type="number" name="item_discount_value" class="form-control" step="0.01" min="0" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="set_item_discount" class="btn btn-primary">Set Discount</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle item discount modal
    const itemDiscountModal = document.getElementById('itemDiscountModal');
    if (itemDiscountModal) {
        itemDiscountModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const itemId = button.getAttribute('data-item-id');
            const itemName = button.getAttribute('data-item-name');
            
            document.getElementById('modalItemId').value = itemId;
            document.getElementById('modalItemName').textContent = itemName;
        });
    }
});

// Function to validate bill generation
function validateBillGeneration() {
    const referringDoctorSelect = document.querySelector('select[name="referring_doctor_id"]');
    if (!referringDoctorSelect || referringDoctorSelect.value === '') {
        alert('Please select a referring doctor before generating the bill.');
        referringDoctorSelect.focus();
        return false;
    }
    
    // Check if any scans require Form F using the hidden input
    const formFScansInput = document.getElementById('formFScans');
    let formFScans = [];
    if (formFScansInput && formFScansInput.value) {
        try {
            formFScans = JSON.parse(formFScansInput.value);
        } catch (e) {
            console.error('Error parsing Form F scans:', e);
        }
    }
    
    if (formFScans.length > 0) {
        const message = 'The following scans require Form F collection:\n\n' + 
                       formFScans.join('\n') + 
                       '\n\nPlease ensure you have collected:\n' +
                       '• Form F (filled and signed)\n' +
                       '• Patient\'s Aadhar Card\n' +
                       '• Referring Doctor\'s Slip\n\n' +
                       'Click OK to proceed with bill generation.';
        
        if (!confirm(message)) {
            return false;
        }
    }
    
    return confirm('Generate ultrasound bill?');
}
</script>
</body>
</html>
