<?php
// pharmacy_stock_entry.php - Enter new pharmacy stock
require_once 'includes/db.php';
require_once 'includes/log.php';


$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Simple server-side processing for pharmacy stock entry
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
        log_action('Reception', 'New Stock Entry', 'Medicine: ' . $_POST['medicine_name'] . ', Qty: ' . $_POST['quantity'] . ' ' . $_POST['unit_type']);
    } else {
        $error = 'Failed to save stock. Please try again.';
    }
}

// Fetch current stock
$stock = $pdo->query("SELECT * FROM pharmacy_stock WHERE quantity > 0 ORDER BY medicine_name, batch_no, expiry_date")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Pharmacy Stock - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Enhanced validation styles */
        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        .form-control.is-valid,
        .form-select.is-valid {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        
        .valid-feedback {
            display: none;
            color: #198754;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        
        /* Smooth transitions for validation states */
        .form-control,
        .form-select {
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">Enter New Pharmacy Stock</h2>
    <?php if ($success): ?>
        <div class="alert alert-success">Stock entry saved successfully!</div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger">
            <strong><?= htmlspecialchars($error) ?></strong>
        </div>
    <?php endif; ?>
    <form method="post" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Medicine Name <span class="text-danger">*</span></label>
            <input type="text" name="medicine_name" class="form-control" 
                   minlength="2" maxlength="200" required
                   value="<?= isset($_POST['medicine_name']) ? htmlspecialchars($_POST['medicine_name']) : '' ?>">
            <div class="invalid-feedback">Please enter a valid medicine name (2-200 characters)</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">HSN Code</label>
            <input type="text" name="hsn_code" class="form-control" 
                   pattern="\d{4,8}" maxlength="8"
                   value="<?= isset($_POST['hsn_code']) ? htmlspecialchars($_POST['hsn_code']) : '' ?>">
            <div class="invalid-feedback">HSN code must be 4-8 digits</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Batch No</label>
            <input type="text" name="batch_no" class="form-control" 
                   maxlength="50"
                   value="<?= isset($_POST['batch_no']) ? htmlspecialchars($_POST['batch_no']) : '' ?>">
            <div class="invalid-feedback">Batch number must not exceed 50 characters</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Expiry Date</label>
            <input type="date" name="expiry_date" class="form-control" 
                   min="<?= date('Y-m-d') ?>"
                   value="<?= isset($_POST['expiry_date']) ? htmlspecialchars($_POST['expiry_date']) : '' ?>">
            <div class="invalid-feedback">Expiry date must be in the future</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Box Number</label>
            <input type="text" name="box_number" class="form-control" 
                   maxlength="50"
                   value="<?= isset($_POST['box_number']) ? htmlspecialchars($_POST['box_number']) : '' ?>">
            <div class="invalid-feedback">Box number must not exceed 50 characters</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Supplier Name</label>
            <input type="text" name="supplier_name" class="form-control" 
                   maxlength="100"
                   value="<?= isset($_POST['supplier_name']) ? htmlspecialchars($_POST['supplier_name']) : '' ?>">
            <div class="invalid-feedback">Supplier name must not exceed 100 characters</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Unit Type <span class="text-danger">*</span></label>
            <select name="unit_type" class="form-select" required>
                <option value="">Select Unit Type</option>
                <option value="capsule" <?= (isset($_POST['unit_type']) && $_POST['unit_type'] === 'capsule') ? 'selected' : '' ?>>Capsule</option>
                <option value="tablet" <?= (isset($_POST['unit_type']) && $_POST['unit_type'] === 'tablet') ? 'selected' : '' ?>>Tablet</option>
                <option value="other" <?= (isset($_POST['unit_type']) && $_POST['unit_type'] === 'other') ? 'selected' : '' ?>>Other</option>
            </select>
            <div class="invalid-feedback">Please select a unit type</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Quantity <span class="text-danger">*</span></label>
            <input type="number" name="quantity" class="form-control" 
                   min="1" max="999999" required
                   placeholder="Number of units" 
                   value="<?= isset($_POST['quantity']) ? htmlspecialchars($_POST['quantity']) : '' ?>">
            <div class="invalid-feedback">Please enter a valid quantity (1-999,999)</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Purchase Price (₹)</label>
            <input type="number" name="purchase_price" class="form-control" 
                   min="0" max="100000" step="0.01"
                   value="<?= isset($_POST['purchase_price']) ? htmlspecialchars($_POST['purchase_price']) : '' ?>">
            <div class="invalid-feedback">Purchase price must be between 0 and ₹100,000</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Sale Price (₹)</label>
            <input type="number" name="sale_price" class="form-control" 
                   min="0" max="100000" step="0.01"
                   value="<?= isset($_POST['sale_price']) ? htmlspecialchars($_POST['sale_price']) : '' ?>">
            <div class="invalid-feedback">Sale price must be between 0 and ₹100,000</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">GST %</label>
            <input type="number" name="gst_percent" class="form-control" 
                   min="0" max="100" step="0.01"
                   value="<?= isset($_POST['gst_percent']) ? htmlspecialchars($_POST['gst_percent']) : '12.00' ?>">
            <div class="invalid-feedback">GST percentage must be between 0 and 100</div>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Save Stock</button>
            <a href="index.php" class="btn btn-secondary ms-2">Back to Home</a>
        </div>
    </form>
    <!-- Current Stock Table -->
    <h4 class="mt-5">Current Available Stock</h4>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>Medicine</th>
                    <th>HSN Code</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th>Quantity</th>
                    <th>Unit Type</th>
                    <th>Supplier</th>
                    <th>Box No</th>
                    <th>Purchase Price</th>
                    <th>Sale Price</th>
                    <th>GST %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stock as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($item['hsn_code']) ?></td>
                    <td><?= htmlspecialchars($item['batch_no']) ?></td>
                    <td><?= htmlspecialchars($item['expiry_date']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= htmlspecialchars($item['unit_type']) ?></td>
                    <td><?= htmlspecialchars($item['supplier_name']) ?></td>
                    <td><?= htmlspecialchars($item['box_number']) ?></td>
                    <td>₹<?= number_format($item['purchase_price'],2) ?></td>
                    <td>₹<?= number_format($item['sale_price'],2) ?></td>
                    <td><?= $item['gst_percent'] ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($stock)): ?>
                <tr><td colspan="10" class="text-center">No stock available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Simple Client-Side Validation Script -->
<script src="js/simple-validation.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 