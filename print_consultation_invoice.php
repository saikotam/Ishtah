<?php
require_once 'includes/db.php';
require_once 'includes/patient.php';

$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
if (!$invoice_id) {
    die('Invalid invoice ID.');
}
$invoice = get_consultation_invoice($pdo, $invoice_id);
if (!$invoice) {
    die('Invoice not found.');
}
$patient = get_patient($pdo, $invoice['patient_id']);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consultation Invoice #<?= $invoice['id'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print { .no-print { display: none; } }
        .invoice-box { max-width: 500px; margin: 40px auto; border: 1px solid #eee; padding: 30px; border-radius: 8px; background: #fff; }
    </style>
</head>
<body>
<div class="invoice-box">
    <h3 class="mb-4">Consultation Invoice</h3>
    <p><strong>Invoice #:</strong> <?= $invoice['id'] ?><br>
       <strong>Date:</strong> <?= date('Y-m-d H:i', strtotime($invoice['created_at'])) ?><br>
       <strong>Patient:</strong> <?= htmlspecialchars($patient['full_name']) ?><br>
       <strong>Amount:</strong> ₹<?= $invoice['amount'] ?><br>
       <strong>Payment Mode:</strong> <?= htmlspecialchars($invoice['mode']) ?><br>
    </p>
    <hr>
    <p>Thank you for your payment.</p>
    <button class="btn btn-primary no-print" onclick="window.print()">Print</button>
    <a href="./" class="btn btn-secondary no-print">Back</a>
</div>
</body>
</html> 