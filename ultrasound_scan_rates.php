<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';

session_start();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_scan'])) {
        // Simple server-side processing for ultrasound scan addition
        try {
            $stmt = $pdo->prepare("INSERT INTO ultrasound_scans (scan_name, price, description, is_form_f_needed) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([
                trim($_POST['scan_name']),
                floatval($_POST['price']),
                trim($_POST['description'] ?? ''),
                isset($_POST['is_form_f_needed']) ? 1 : 0
            ])) {
                $success_message = 'Ultrasound scan added successfully!';
                log_action('Reception', 'Ultrasound Scan Added', 'Scan: ' . $_POST['scan_name'] . ', Price: ' . $_POST['price']);
            } else {
                $error_message = 'Failed to add ultrasound scan.';
            }
        } catch (Exception $e) {
            $error_message = 'Error adding ultrasound scan: ' . $e->getMessage();
        }
    } elseif (isset($_POST['update_scan'])) {
        $scan_id = intval($_POST['scan_id']);
        $scan_name = trim($_POST['scan_name']);
        $price = floatval($_POST['price']);
        $description = trim($_POST['description']);
        
        if (!empty($scan_name) && $price > 0) {
            $stmt = $pdo->prepare("UPDATE ultrasound_scans SET scan_name = ?, price = ?, description = ?, is_form_f_needed = ? WHERE id = ?");
            if ($stmt->execute([$scan_name, $price, $description, isset($_POST['is_form_f_needed']) ? 1 : 0, $scan_id])) {
                $success_message = 'Ultrasound scan updated successfully!';
                log_action('Reception', 'Ultrasound Scan Updated', 'Scan ID: ' . $scan_id . ', Name: ' . $scan_name . ', Price: ' . $price);
            } else {
                $error_message = 'Failed to update ultrasound scan.';
            }
        } else {
            $error_message = 'Please fill in all required fields.';
        }
    } elseif (isset($_POST['toggle_scan_status'])) {
        $scan_id = intval($_POST['scan_id']);
        $new_status = $_POST['new_status'] === '1' ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE ultrasound_scans SET is_active = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $scan_id])) {
            $success_message = 'Scan status updated successfully!';
            log_action('Reception', 'Ultrasound Scan Status Updated', 'Scan ID: ' . $scan_id . ', Status: ' . ($new_status ? 'Active' : 'Inactive'));
        } else {
            $error_message = 'Failed to update scan status.';
        }
    }
}

// Fetch all ultrasound scans
$scans = $pdo->query("SELECT * FROM ultrasound_scans ORDER BY scan_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultrasound Scan Rates - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
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
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2>Ultrasound Scan Rates Management</h2>
                <div>
                    <a href="index.php" class="btn btn-secondary">Back to Home</a>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success_message) ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error_message) ?>
            </div>
            <?php endif; ?>
            
            <!-- Add New Scan -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Add New Ultrasound Scan</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="add_scan" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="scan_name" class="form-label">Scan Name <span class="text-danger">*</span></label>
                                <input type="text" name="scan_name" id="scan_name" class="form-control" 
                                       minlength="2" maxlength="200"
                                       required value="<?= isset($_POST['scan_name']) ? htmlspecialchars($_POST['scan_name']) : '' ?>">
                                <div class="invalid-feedback">Please enter a valid scan name (2-200 characters)</div>
                            </div>
                            <div class="col-md-4">
                                <label for="price" class="form-label">Price (₹) <span class="text-danger">*</span></label>
                                <input type="number" name="price" id="price" class="form-control" 
                                       step="0.01" min="0" max="100000"
                                       required value="<?= isset($_POST['price']) ? htmlspecialchars($_POST['price']) : '' ?>">
                                <div class="invalid-feedback">Please enter a valid price (positive number, max ₹100,000)</div>
                            </div>
                            <div class="col-md-4">
                                <label for="description" class="form-label">Description</label>
                                <input type="text" name="description" id="description" class="form-control" 
                                       maxlength="500"
                                       value="<?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?>">
                                <div class="invalid-feedback">Description must not exceed 500 characters</div>
                            </div>
                                        <div class="col-md-4">
                <div class="form-check mt-4">
                    <input type="checkbox" name="is_form_f_needed" id="is_form_f_needed" class="form-check-input" 
                           value="1" <?= isset($_POST['is_form_f_needed']) ? 'checked' : '' ?>>
                    <label for="is_form_f_needed" class="form-check-label">
                        <strong>Form F Required</strong>
                    </label>
                    <div class="form-text">Check this if Form F should be printed with this scan</div>
                </div>
            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Add Scan</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Scans List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Ultrasound Scans</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Scan Name</th>
                                                                <th>Description</th>
                            <th>Price (₹)</th>
                            <th>Form F</th>
                            <th>Status</th>
                            <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scans as $scan): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($scan['scan_name']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($scan['description']) ?></td>
                                    <td>₹<?= number_format($scan['price'], 2) ?></td>
                                    <td>
                                        <?php if (isset($scan['is_form_f_needed']) && $scan['is_form_f_needed']): ?>
                                            <span class="badge bg-warning text-dark">Required</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Not Required</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $scan['is_active'] ? 'success' : 'danger' ?>">
                                            <?= $scan['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editScanModal" 
                                                data-scan-id="<?= $scan['id'] ?>" 
                                                data-scan-name="<?= htmlspecialchars($scan['scan_name']) ?>"
                                                data-price="<?= $scan['price'] ?>"
                                                data-description="<?= htmlspecialchars($scan['description']) ?>"
                                                data-is-form-f-needed="<?= isset($scan['is_form_f_needed']) && $scan['is_form_f_needed'] ? '1' : '0' ?>">
                                            Edit
                                        </button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="scan_id" value="<?= $scan['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $scan['is_active'] ? '0' : '1' ?>">
                                            <button type="submit" name="toggle_scan_status" class="btn btn-sm btn-<?= $scan['is_active'] ? 'warning' : 'success' ?>">
                                                <?= $scan['is_active'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
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
</div>

<!-- Edit Scan Modal -->
<div class="modal fade" id="editScanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Ultrasound Scan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="scan_id" id="modalScanId">
                    <div class="mb-3">
                        <label class="form-label">Scan Name <span class="text-danger">*</span></label>
                        <input type="text" name="scan_name" id="modalScanName" class="form-control" 
                               minlength="2" maxlength="200" required>
                        <div class="invalid-feedback">Please enter a valid scan name (2-200 characters)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Price (₹) <span class="text-danger">*</span></label>
                        <input type="number" name="price" id="modalPrice" class="form-control" 
                               step="0.01" min="0" max="100000" required>
                        <div class="invalid-feedback">Please enter a valid price (positive number, max ₹100,000)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="modalDescription" class="form-control" 
                               maxlength="500">
                        <div class="invalid-feedback">Description must not exceed 500 characters</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_form_f_needed" id="modalIsFormFNeeded" class="form-check-input" value="1">
                            <label for="modalIsFormFNeeded" class="form-check-label">
                                <strong>Form F Required</strong>
                            </label>
                            <div class="form-text">Check this if Form F should be printed with this scan</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_scan" class="btn btn-primary">Update Scan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle edit scan modal
    const editScanModal = document.getElementById('editScanModal');
    if (editScanModal) {
        editScanModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const scanId = button.getAttribute('data-scan-id');
            const scanName = button.getAttribute('data-scan-name');
            const price = button.getAttribute('data-price');
            const description = button.getAttribute('data-description');
            const isFormFNeeded = button.getAttribute('data-is-form-f-needed');
            
            document.getElementById('modalScanId').value = scanId;
            document.getElementById('modalScanName').value = scanName;
            document.getElementById('modalPrice').value = price;
            document.getElementById('modalDescription').value = description;
            document.getElementById('modalIsFormFNeeded').checked = isFormFNeeded === '1';
        });
    }
});
</script>

<!-- Simple Client-Side Validation Script -->
<script src="js/simple-validation.js"></script>
</body>
</html>
