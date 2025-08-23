<?php
// patient_management.php - Unified Patient Management Interface
require_once 'includes/db.php';
require_once 'includes/patient.php';
require_once 'includes/log.php';

$message = '';
$patients = [];
$selected_patient = null;
$search_query = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register_patient'])) {
        try {
            $patient_id = register_patient($pdo, $_POST);
            $message = 'Patient registered successfully! Patient ID: ' . $patient_id;
            log_action('Reception', 'Patient Registered', 'Patient: ' . $_POST['full_name']);
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['search_patients'])) {
        $search_query = trim($_POST['search_query']);
        if (!empty($search_query)) {
            try {
                $patients = search_patients($pdo, $search_query);
                log_action('Reception', 'Patient Search', 'Searched for: ' . $search_query);
            } catch (Exception $e) {
                $message = 'Search error: ' . $e->getMessage();
            }
        }
    }
}

// Get all patients for display
if (empty($patients) && empty($search_query)) {
    try {
        $stmt = $pdo->query("SELECT * FROM patients ORDER BY created_at DESC LIMIT 20");
        $patients = $stmt->fetchAll();
    } catch (Exception $e) {
        $message = 'Error loading patients: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Management - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Ishtah Clinic</a>
    <div class="navbar-nav">
        <a class="nav-link" href="index.php">Dashboard</a>
        <a class="nav-link active" href="patient_management.php">Patient Management</a>
        <a class="nav-link" href="patients.php">All Patients</a>
    </div>
  </div>
</nav>

<div class="container">
    <?php if ($message): ?>
        <div class="alert <?= strpos($message, 'Error') !== false ? 'alert-danger' : 'alert-success' ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Patient Registration Form -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3>Register New Patient</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="gender" class="form-label">Gender *</label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="dob" class="form-label">Date of Birth *</label>
                            <input type="date" class="form-control" id="dob" name="dob" required>
                        </div>
                        <div class="mb-3">
                            <label for="contact_number" class="form-label">Contact Number *</label>
                            <input type="text" class="form-control" id="contact_number" name="contact_number" required>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Address *</label>
                            <textarea class="form-control" id="address" name="address" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="lead_source" class="form-label">How did you hear about us?</label>
                            <select class="form-select" id="lead_source" name="lead_source">
                                <option value="">Select Source</option>
                                <option value="Referral">Referral</option>
                                <option value="Online">Online</option>
                                <option value="Advertisement">Advertisement</option>
                                <option value="Walk-in">Walk-in</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <button type="submit" name="register_patient" class="btn btn-primary">Register Patient</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Patient Search and List -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3>Search Patients</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="mb-3">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search_query" 
                                   placeholder="Search by name or contact number" 
                                   value="<?= htmlspecialchars($search_query) ?>">
                            <button type="submit" name="search_patients" class="btn btn-outline-secondary">Search</button>
                        </div>
                    </form>

                    <?php if (!empty($patients)): ?>
                        <h5><?= empty($search_query) ? 'Recent Patients' : 'Search Results' ?></h5>
                        <div class="list-group">
                            <?php foreach ($patients as $patient): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?= htmlspecialchars($patient['full_name']) ?></h6>
                                        <small>ID: <?= $patient['id'] ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <strong>Contact:</strong> <?= htmlspecialchars($patient['contact_number']) ?><br>
                                        <strong>Gender:</strong> <?= htmlspecialchars($patient['gender']) ?><br>
                                        <strong>DOB:</strong> <?= htmlspecialchars($patient['dob']) ?>
                                    </p>
                                    <small><?= htmlspecialchars($patient['address']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!empty($search_query)): ?>
                        <div class="alert alert-info">No patients found matching your search.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>