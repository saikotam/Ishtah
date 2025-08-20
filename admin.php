<?php
// admin.php - Admin dashboard with links to all pages
$pages = [
    'General' => [
        ['Home', 'index.php'],
        ['View All Patients', 'patients.php'],
        ['Register Patient', 'register_patient.php'],
        ['Visit List', 'visit_list.php'],
        ['Doctor Management', 'doctor_management.php'],
        ['Prescription (example)', 'prescription.php?visit_id=1'],
        ['Print Consultation Invoice (example)', 'print_consultation_invoice.php?invoice_id=1'],
    ],
    'Pharmacy' => [
        ['Medicines Search', 'pharmacy_stock_summary.php'],
        ['Pharmacy Billing (example)', 'pharmacy_billing.php?visit_id=1'],
        ['Pharmacy Invoice Summary', 'pharmacy_invoice_summary.php'],
        ['Stock Log', 'pharmacy_stock_log.php'],
        ['Pharmacy Stock Analysis', 'pharmacy_stock_analysis.php'],
        ['Stock Summary', 'pharmacy_stock_summary.php'],
        ['Stock Entry', 'pharmacy_stock_entry.php'],
    ],
    'Lab' => [
        ['Lab Test Rates', 'lab_test_rates.php'],
        ['Lab Billing (example)', 'lab_billing.php?visit_id=1'],
    ],
    'Ultrasound' => [
        ['Ultrasound Scan Rates (Display)', 'ultrasound_rates_display.php'],
        ['Ultrasound Scan Rates (Admin)', 'ultrasound_scan_rates.php'],
        ['Ultrasound Billing (example)', 'ultrasound_billing.php?visit_id=1'],
        ['Doctor Incentives', 'doctor_incentives.php'],
    ],
    'Accounting & Finance' => [
        ['Accounting Dashboard', 'Accounting/accounting_dashboard.php'],
        ['Profit & Loss Statement', 'Accounting/profit_loss_statement.php'],
        ['Balance Sheet', 'Accounting/balance_sheet.php'],
        ['Trial Balance', 'Accounting/trial_balance.php'],
        ['Cash Flow Statement', 'Accounting/cash_flow_statement.php'],
        ['Bank Statement Upload', 'Accounting/bank_statement_upload.php'],
        ['Chart of Accounts', 'Accounting/chart_of_accounts.php'],
        ['Journal Entries', 'Accounting/journal_entries.php'],
    ],
    'Reports & System' => [
        ['Summary Report', 'summary.php'],
        ['System Log', 'system_log.php'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ishtah Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
    <h2 class="mb-4">Admin Dashboard</h2>
    <?php foreach ($pages as $section => $links): ?>
        <div class="card mb-4">
            <div class="card-header bg-light"><strong><?= htmlspecialchars($section) ?></strong></div>
            <div class="card-body">
                <ul class="list-group">
                    <?php foreach ($links as $link): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <a href="<?= $link[1] ?>" target="_blank"><?= htmlspecialchars($link[0]) ?></a>
                            <?php if (strpos($link[1], 'prescription.php') !== false || strpos($link[1], 'print_consultation_invoice.php') !== false || strpos($link[1], 'pharmacy_billing.php') !== false || strpos($link[1], 'lab_billing.php') !== false || strpos($link[1], 'ultrasound_billing.php') !== false): ?>
                                <span class="badge bg-secondary">Example link (requires valid visit/invoice)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endforeach; ?>
    <a href="index.php" class="btn btn-secondary">Back to Home</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 