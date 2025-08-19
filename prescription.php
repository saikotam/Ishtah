<?php
// prescription.php - Print patient details for letterhead
require_once 'includes/db.php';

$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
if (!$visit_id) {
    die('Invalid visit ID.');
}
// Fetch visit, patient, doctor details
$stmt = $pdo->prepare("SELECT v.*, p.full_name, p.gender, p.dob, p.contact_number, d.name AS doctor_name, d.specialty FROM visits v JOIN patients p ON v.patient_id = p.id JOIN doctors d ON v.doctor_id = d.id WHERE v.id = ?");
$stmt->execute([$visit_id]);
$visit = $stmt->fetch();
if (!$visit) {
    die('Visit not found.');
}

// Calculate age from date of birth
$dob = new DateTime($visit['dob']);
$today = new DateTime();
$age_diff = $today->diff($dob);
$age = $age_diff->y . ' yrs ' . $age_diff->m . ' mnths';

// Format visit date in Indian format (dd/mm/yyyy) with time (HH:MM)
$visit_date = new DateTime($visit['visit_date']);
$formatted_visit_date = $visit_date->format('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription - Ishtah Clinic</title>
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            font-size: 14pt;
            line-height: 1.4;
            color: #000;
            background: white;
        }
        
        /* Print-specific styles */
        @media print {
            @page {
                margin: 0.3in 0.5in; /* Reduced margins for more space */
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
            
            /* Prevent page breaks in prescription content */
            .prescription-row {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            .dividing-line {
                page-break-before: avoid;
                break-before: avoid;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                background: #333 !important;
            }
            
            .dividing-line-alt {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                border-top: 2px solid #333 !important;
            }
        }
        
        /* Letterhead area - positioned for standard letterhead */
        .letterhead-content {
            width: 100%;
            max-width: 8.5in;
            margin: 0 auto;
            padding: 1.5in 0.5in 0.5in 0.5in; /* Increased top padding for more white space */
            min-height: 11in;
            position: relative;
        }
        
        /* Row styles */
        .prescription-row {
            display: flex;
            margin-bottom: 0.15in;
            min-height: 0.8in;
        }
        
        /* First row - Doctor info on right half */
        .doctor-section {
            width: 50%;
            padding-left: 0.5in;
            margin-left: auto;
        }
        
        .doctor-name {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 0.1in;
        }
        
        .doctor-qualifications {
            font-size: 13pt;
            color: #333;
        }
        
        /* Second row - Patient details in two sub-rows */
        .patient-section {
            width: 100%;
        }
        
        .patient-sub-row {
            display: flex;
            margin-bottom: 0.1in;
            flex-wrap: nowrap;
        }
        
        .patient-field {
            display: flex;
            align-items: baseline;
            margin-right: 0.8in;
            min-width: 2in;
            white-space: nowrap;
        }
        
        .field-label {
            font-weight: bold;
            margin-right: 0.2in;
            min-width: 0.8in;
        }
        
        .field-value {
            padding-left: 0.1in;
            min-width: 1.2in;
            flex: 1;
            white-space: nowrap;
        }
        
        /* Dividing line between patient details and prescription */
        .dividing-line {
            width: 100%;
            height: 2px;
            background: #333;
            margin: 0.2in 0;
            border: none;
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
        }
        
        /* Alternative approach using border for better print compatibility */
        .dividing-line-alt {
            width: 100%;
            height: 0;
            border-top: 2px solid #333;
            margin: 0.2in 0;
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
        }
        
        /* Third row - Prescription content */
        .prescription-section {
            width: 100%;
            min-height: 2.5in;
        }
        
        .prescription-content {
            padding: 0.3in;
            min-height: 2in;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .rx-symbol {
            font-weight: bold;
            margin-bottom: 0.2in;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Control buttons */
        .print-controls {
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
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <!-- Print Controls -->
    <div class="print-controls no-print">
        <button class="btn btn-primary" onclick="window.print()">Print Prescription</button>
        <a href="index.php" class="btn btn-secondary">Back to Home</a>
    </div>
    
    <div class="letterhead-content">
        <!-- First Row - Doctor Info (Left Half) -->
        <div class="prescription-row">
            <div class="doctor-section">
                <div class="doctor-name">Dr. <?= htmlspecialchars($visit['doctor_name']) ?></div>
                <div class="doctor-qualifications"><?= htmlspecialchars($visit['specialty']) ?></div>
            </div>
        </div>
        
        <!-- Second Row - Patient Details (Two Sub-rows) -->
        <div class="prescription-row">
            <div class="patient-section">
                <!-- First sub-row -->
                <div class="patient-sub-row">
                    <div class="patient-field">
                        <div class="field-label">Name:</div>
                        <div class="field-value"><?= htmlspecialchars($visit['full_name']) ?></div>
                    </div>
                    <div class="patient-field">
                        <div class="field-label">Age:</div>
                        <div class="field-value"><?= $age ?></div>
                    </div>
                    <div class="patient-field">
                        <div class="field-label">Gender:</div>
                        <div class="field-value"><?= htmlspecialchars($visit['gender']) ?></div>
                    </div>
                </div>
                <!-- Second sub-row -->
                <div class="patient-sub-row">
                    <div class="patient-field">
                        <div class="field-label">Date:</div>
                        <div class="field-value"><?= htmlspecialchars($formatted_visit_date) ?></div>
                    </div>
                    <div class="patient-field">
                        <div class="field-label">Contact:</div>
                        <div class="field-value"><?= htmlspecialchars($visit['contact_number']) ?></div>
                    </div>
                    <div class="patient-field">
                        <div class="field-label">ID:</div>
                        <div class="field-value"><?= htmlspecialchars($visit['patient_id']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dividing Line -->
        <hr style="width: 100%; height: 2px; background: #333; border: none; margin: 0.1in 0; -webkit-print-color-adjust: exact; color-adjust: exact;">
        
        <!-- Third Row - Prescription Content -->
        <div class="prescription-row">
            <div class="prescription-section">
                <div class="prescription-content">
                    <div class="rx-symbol">Rx:</div>
                    <!-- Prescription content will go here -->
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                    <p style="margin-bottom: 0.2in;">&nbsp;</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 