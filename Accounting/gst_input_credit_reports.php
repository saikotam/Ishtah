<?php
// gst_input_credit_reports.php - GST Input Credit Reports and Management
require_once '../includes/db.php';

$current_month = date('n');
$current_year = date('Y');
$selected_month = $_GET['month'] ?? $current_month;
$selected_year = $_GET['year'] ?? $current_year;
$filing_period = sprintf('%04d-%02d', $selected_year, $selected_month);

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'update_claim_status') {
            $stmt = $pdo->prepare("
                UPDATE gst_input_credit_register 
                SET claim_status = ?, claim_date = ?, gstr_reference = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['status'],
                $_POST['claim_date'] ?: null,
                $_POST['gstr_reference'] ?: null,
                $_POST['notes'] ?: null,
                $_POST['register_id']
            ]);
            
            $message = 'GST Input Credit status updated successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error updating status: ' . $e->getMessage();
    }
}

// Get GST Input Credit summary for selected period
$stmt = $pdo->prepare("
    SELECT 
        gicr.*,
        pi.invoice_number,
        pi.invoice_date,
        pi.supplier_name,
        pi.supplier_gstin,
        pi.total_amount,
        pi.category
    FROM gst_input_credit_register gicr
    JOIN purchase_invoices pi ON gicr.purchase_invoice_id = pi.id
    WHERE gicr.gst_period_month = ? AND gicr.gst_period_year = ?
    ORDER BY pi.invoice_date DESC
");
$stmt->execute([$selected_month, $selected_year]);
$gst_credits = $stmt->fetchAll();

// Calculate totals
$total_credits = array_sum(array_column($gst_credits, 'total_credit'));
$claimed_credits = array_sum(array_filter(array_column($gst_credits, 'total_credit'), function($credit, $key) use ($gst_credits) {
    return $gst_credits[$key]['claim_status'] === 'CLAIMED';
}, ARRAY_FILTER_USE_BOTH));

// Get monthly summary for current year
$stmt = $pdo->prepare("
    SELECT 
        gst_period_month,
        COUNT(*) as invoice_count,
        SUM(total_credit) as total_credit,
        SUM(CASE WHEN claim_status = 'CLAIMED' THEN total_credit ELSE 0 END) as claimed_credit,
        SUM(CASE WHEN claim_status = 'PENDING' THEN total_credit ELSE 0 END) as pending_credit
    FROM gst_input_credit_register
    WHERE gst_period_year = ?
    GROUP BY gst_period_month
    ORDER BY gst_period_month
");
$stmt->execute([$selected_year]);
$monthly_summary = $stmt->fetchAll();

// Get supplier-wise summary
$stmt = $pdo->prepare("
    SELECT 
        pi.supplier_name,
        pi.supplier_gstin,
        COUNT(*) as invoice_count,
        SUM(gicr.total_credit) as total_credit,
        SUM(CASE WHEN gicr.claim_status = 'CLAIMED' THEN gicr.total_credit ELSE 0 END) as claimed_credit
    FROM gst_input_credit_register gicr
    JOIN purchase_invoices pi ON gicr.purchase_invoice_id = pi.id
    WHERE gicr.gst_period_month = ? AND gicr.gst_period_year = ?
    GROUP BY pi.supplier_name, pi.supplier_gstin
    ORDER BY total_credit DESC
");
$stmt->execute([$selected_month, $selected_year]);
$supplier_summary = $stmt->fetchAll();

// Get category-wise summary
$stmt = $pdo->prepare("
    SELECT 
        pi.category,
        COUNT(*) as invoice_count,
        SUM(gicr.total_credit) as total_credit,
        SUM(CASE WHEN gicr.claim_status = 'CLAIMED' THEN gicr.total_credit ELSE 0 END) as claimed_credit
    FROM gst_input_credit_register gicr
    JOIN purchase_invoices pi ON gicr.purchase_invoice_id = pi.id
    WHERE gicr.gst_period_month = ? AND gicr.gst_period_year = ?
    GROUP BY pi.category
    ORDER BY total_credit DESC
");
$stmt->execute([$selected_month, $selected_year]);
$category_summary = $stmt->fetchAll();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GST Input Credit Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        .stats-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.2s ease;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 15px;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-claimed { background: #d1edff; color: #0c5460; }
        .status-adjusted { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .filter-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .summary-table th {
            background: #495057;
            color: white;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-chart-line"></i> GST Input Credit Reports</h1>
                    <p class="mb-0">Track and manage GST input credit claims</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="accounting_dashboard.php" class="btn btn-light me-2">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                    <a href="purchase_invoice_entry.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> New Invoice
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <?php if (isset($message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Period Filter -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Select Month</label>
                    <select name="month" class="form-select">
                        <?php foreach ($months as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $num == $selected_month ? 'selected' : '' ?>>
                            <?= $name ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Select Year</label>
                    <select name="year" class="form-select">
                        <?php for ($year = 2020; $year <= date('Y') + 1; $year++): ?>
                        <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
                <div class="col-md-3 text-end">
                    <strong>Period: <?= $months[$selected_month] ?> <?= $selected_year ?></strong>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-primary text-white">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div>
                            <h3><?= count($gst_credits) ?></h3>
                            <p class="text-muted mb-0">Total Invoices</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-info text-white">
                            <i class="fas fa-rupee-sign"></i>
                        </div>
                        <div>
                            <h3>₹<?= number_format($total_credits, 2) ?></h3>
                            <p class="text-muted mb-0">Total Credits</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-success text-white">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <h3>₹<?= number_format($claimed_credits, 2) ?></h3>
                            <p class="text-muted mb-0">Claimed Credits</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-warning text-white">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <h3>₹<?= number_format($total_credits - $claimed_credits, 2) ?></h3>
                            <p class="text-muted mb-0">Pending Credits</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed GST Credits Table -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5><i class="fas fa-list"></i> GST Input Credit Details - <?= $months[$selected_month] ?> <?= $selected_year ?></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="gstCreditsTable">
                        <thead class="summary-table">
                            <tr>
                                <th>Invoice Date</th>
                                <th>Invoice No.</th>
                                <th>Supplier</th>
                                <th>GSTIN</th>
                                <th>Category</th>
                                <th>CGST</th>
                                <th>SGST</th>
                                <th>IGST</th>
                                <th>Total Credit</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gst_credits as $credit): ?>
                            <tr>
                                <td><?= date('d-m-Y', strtotime($credit['invoice_date'])) ?></td>
                                <td><?= htmlspecialchars($credit['invoice_number']) ?></td>
                                <td><?= htmlspecialchars($credit['supplier_name']) ?></td>
                                <td><?= htmlspecialchars($credit['supplier_gstin'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($credit['category']) ?></td>
                                <td>₹<?= number_format($credit['cgst_credit'], 2) ?></td>
                                <td>₹<?= number_format($credit['sgst_credit'], 2) ?></td>
                                <td>₹<?= number_format($credit['igst_credit'], 2) ?></td>
                                <td><strong>₹<?= number_format($credit['total_credit'], 2) ?></strong></td>
                                <td>
                                    <span class="badge status-<?= strtolower($credit['claim_status']) ?>">
                                        <?= ucfirst(strtolower($credit['claim_status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="updateStatus(<?= $credit['id'] ?>, '<?= htmlspecialchars($credit['claim_status']) ?>', '<?= htmlspecialchars($credit['claim_date']) ?>', '<?= htmlspecialchars($credit['gstr_reference']) ?>', '<?= htmlspecialchars($credit['notes']) ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Summary -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6><i class="fas fa-calendar"></i> Monthly Summary - <?= $selected_year ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Invoices</th>
                                        <th>Total Credit</th>
                                        <th>Claimed</th>
                                        <th>Pending</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_summary as $month): ?>
                                    <tr>
                                        <td><?= $months[$month['gst_period_month']] ?></td>
                                        <td><?= $month['invoice_count'] ?></td>
                                        <td>₹<?= number_format($month['total_credit'], 2) ?></td>
                                        <td class="text-success">₹<?= number_format($month['claimed_credit'], 2) ?></td>
                                        <td class="text-warning">₹<?= number_format($month['pending_credit'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h6><i class="fas fa-building"></i> Supplier Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Invoices</th>
                                        <th>Total Credit</th>
                                        <th>Claimed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($supplier_summary as $supplier): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($supplier['supplier_name']) ?></td>
                                        <td><?= $supplier['invoice_count'] ?></td>
                                        <td>₹<?= number_format($supplier['total_credit'], 2) ?></td>
                                        <td class="text-success">₹<?= number_format($supplier['claimed_credit'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Summary -->
        <div class="card mt-4">
            <div class="card-header bg-warning text-dark">
                <h6><i class="fas fa-tags"></i> Category-wise Summary</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Invoices</th>
                                <th>Total Credit</th>
                                <th>Claimed Credit</th>
                                <th>Pending Credit</th>
                                <th>Claim %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($category_summary as $category): ?>
                            <?php 
                            $pending_credit = $category['total_credit'] - $category['claimed_credit'];
                            $claim_percentage = $category['total_credit'] > 0 ? ($category['claimed_credit'] / $category['total_credit']) * 100 : 0;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($category['category']) ?></td>
                                <td><?= $category['invoice_count'] ?></td>
                                <td>₹<?= number_format($category['total_credit'], 2) ?></td>
                                <td class="text-success">₹<?= number_format($category['claimed_credit'], 2) ?></td>
                                <td class="text-warning">₹<?= number_format($pending_credit, 2) ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $claim_percentage ?>%">
                                            <?= number_format($claim_percentage, 1) ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Update GST Input Credit Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_claim_status">
                        <input type="hidden" name="register_id" id="registerId">
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="statusSelect" class="form-select">
                                <option value="PENDING">Pending</option>
                                <option value="CLAIMED">Claimed</option>
                                <option value="ADJUSTED">Adjusted</option>
                                <option value="REJECTED">Rejected</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Claim Date</label>
                            <input type="date" name="claim_date" id="claimDate" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">GSTR Reference</label>
                            <input type="text" name="gstr_reference" id="gstrReference" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="notesField" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#gstCreditsTable').DataTable({
                pageLength: 25,
                order: [[0, 'desc']], // Sort by invoice date descending
                columnDefs: [
                    { orderable: false, targets: [10] } // Disable sorting on Actions column
                ]
            });
        });

        function updateStatus(registerId, currentStatus, claimDate, gstrRef, notes) {
            document.getElementById('registerId').value = registerId;
            document.getElementById('statusSelect').value = currentStatus;
            document.getElementById('claimDate').value = claimDate || '';
            document.getElementById('gstrReference').value = gstrRef || '';
            document.getElementById('notesField').value = notes || '';
            
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }
    </script>
</body>
</html>