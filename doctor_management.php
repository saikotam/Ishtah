<?php
// doctor_management.php - Doctor Management Interface
require_once 'includes/db.php';
require_once 'includes/log.php';

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_doctor'])) {
        // Simple server-side processing for doctor addition
        try {
            $stmt = $pdo->prepare("INSERT INTO doctors (name, specialty, fees) VALUES (?, ?, ?)");
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['specialty']),
                floatval($_POST['fees'])
            ]);
            $message = 'Doctor added successfully!';
            log_action('Admin', 'Doctor Added', 'Added doctor: ' . $_POST['name'] . ' with fees: ' . $_POST['fees']);
        } catch (Exception $e) {
            $error = 'Error adding doctor: ' . $e->getMessage();
        }
    } elseif (isset($_POST['update_doctor'])) {
        // Simple server-side processing for doctor update
        try {
            $stmt = $pdo->prepare("UPDATE doctors SET name = ?, specialty = ?, fees = ? WHERE id = ?");
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['specialty']),
                floatval($_POST['fees']),
                intval($_POST['doctor_id'])
            ]);
            $message = 'Doctor updated successfully!';
            log_action('Admin', 'Doctor Updated', 'Updated doctor: ' . $_POST['name'] . ' with fees: ' . $_POST['fees']);
        } catch (Exception $e) {
            $error = 'Error updating doctor: ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_doctor'])) {
        // Validate doctor ID for deletion
        if (empty($_POST['doctor_id']) || !is_numeric($_POST['doctor_id'])) {
            $error = 'Invalid doctor ID for deletion.';
        } else {
            try {
                // Check if doctor has any visits
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE doctor_id = ?");
                $stmt->execute([$_POST['doctor_id']]);
                $visit_count = $stmt->fetchColumn();
                
                if ($visit_count > 0) {
                    $error = 'Cannot delete doctor. They have ' . $visit_count . ' visits associated with them.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM doctors WHERE id = ?");
                    $stmt->execute([$_POST['doctor_id']]);
                    $message = 'Doctor deleted successfully!';
                    log_action('Admin', 'Doctor Deleted', 'Deleted doctor ID: ' . $_POST['doctor_id']);
                }
            } catch (Exception $e) {
                $error = 'Error deleting doctor: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all doctors
$doctors = $pdo->query("SELECT * FROM doctors ORDER BY specialty, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Management - Ishtah Clinic</title>
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
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">Ishtah Clinic</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin.php">Admin</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <h2 class="mb-4">Doctor Management</h2>
            
            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Add New Doctor -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Add New Doctor</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="add_doctor" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="name" class="form-label">Doctor Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" 
                                       minlength="2" maxlength="100" pattern="[a-zA-Z\s]+"
                                       required value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
                                <div class="invalid-feedback">Please enter a valid doctor name (letters and spaces only, 2-100 characters)</div>
                            </div>
                            <div class="col-md-4">
                                <label for="specialty" class="form-label">Specialty <span class="text-danger">*</span></label>
                                <input type="text" name="specialty" id="specialty" class="form-control" 
                                       minlength="2" maxlength="100"
                                       required value="<?= isset($_POST['specialty']) ? htmlspecialchars($_POST['specialty']) : '' ?>">
                                <div class="invalid-feedback">Please enter a valid specialty (2-100 characters)</div>
                            </div>
                            <div class="col-md-4">
                                <label for="fees" class="form-label">Consultation Fees (₹) <span class="text-danger">*</span></label>
                                <input type="number" name="fees" id="fees" class="form-control" 
                                       step="0.01" min="0" max="10000"
                                       required value="<?= isset($_POST['fees']) ? htmlspecialchars($_POST['fees']) : '300' ?>">
                                <div class="invalid-feedback">Please enter a valid consultation fee (positive number, max ₹10,000)</div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Add Doctor</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Doctors List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Manage Doctors</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($doctors)): ?>
                        <p class="text-muted">No doctors found. Add a doctor using the form above.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Specialty</th>
                                        <th>Fees (₹)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($doctor['name']) ?></td>
                                            <td><?= htmlspecialchars($doctor['specialty']) ?></td>
                                            <td>₹<?= number_format($doctor['fees'], 2) ?></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" onclick="editDoctor(<?= htmlspecialchars(json_encode($doctor)) ?>)">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm" onclick="deleteDoctor(<?= $doctor['id'] ?>, '<?= htmlspecialchars($doctor['name']) ?>')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Doctor Modal -->
<div class="modal fade" id="editDoctorModal" tabindex="-1" aria-labelledby="editDoctorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editDoctorModalLabel">Edit Doctor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="update_doctor" value="1">
                    <input type="hidden" name="doctor_id" id="edit_doctor_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Doctor Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" 
                               minlength="2" maxlength="100" pattern="[a-zA-Z\s]+" required>
                        <div class="invalid-feedback">Please enter a valid doctor name (letters and spaces only, 2-100 characters)</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_specialty" class="form-label">Specialty <span class="text-danger">*</span></label>
                        <input type="text" name="specialty" id="edit_specialty" class="form-control" 
                               minlength="2" maxlength="100" required>
                        <div class="invalid-feedback">Please enter a valid specialty (2-100 characters)</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_fees" class="form-label">Consultation Fees (₹) <span class="text-danger">*</span></label>
                        <input type="number" name="fees" id="edit_fees" class="form-control" 
                               step="0.01" min="0" max="10000" required>
                        <div class="invalid-feedback">Please enter a valid consultation fee (positive number, max ₹10,000)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Doctor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Doctor Modal -->
<div class="modal fade" id="deleteDoctorModal" tabindex="-1" aria-labelledby="deleteDoctorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteDoctorModalLabel">Delete Doctor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="delete_doctor_name"></strong>?</p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <form method="post">
                <div class="modal-footer">
                    <input type="hidden" name="delete_doctor" value="1">
                    <input type="hidden" name="doctor_id" id="delete_doctor_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Doctor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editDoctor(doctor) {
    document.getElementById('edit_doctor_id').value = doctor.id;
    document.getElementById('edit_name').value = doctor.name;
    document.getElementById('edit_specialty').value = doctor.specialty;
    document.getElementById('edit_fees').value = doctor.fees;
    
    const modal = new bootstrap.Modal(document.getElementById('editDoctorModal'));
    modal.show();
}

function deleteDoctor(doctorId, doctorName) {
    document.getElementById('delete_doctor_id').value = doctorId;
    document.getElementById('delete_doctor_name').textContent = doctorName;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteDoctorModal'));
    modal.show();
}
</script>

<!-- Simple Client-Side Validation Script -->
<script src="js/simple-validation.js"></script>
</body>
</html> 