<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/log.php';
session_start();

// Check if visit_id and form_id are provided
$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$visit_id || !$form_id) {
    die('Visit ID and Form ID are required.');
}

// Fetch visit and patient details
$stmt = $pdo->prepare("SELECT v.*, p.full_name, p.gender, p.dob, p.address, p.contact_number FROM visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = ?");
$stmt->execute([$visit_id]);
$visit = $stmt->fetch();

if (!$visit) {
    die('Visit not found.');
}

// Fetch form data
$stmt = $pdo->prepare("SELECT * FROM form_f_records WHERE id = ?");
$stmt->execute([$form_id]);
$form_data = $stmt->fetch();

if (!$form_data) {
    die('Form F not found.');
}

// Generate PDF
generateFormFPDF($form_data, $visit);

function generateFormFPDF($form_data, $visit) {
    // Create a simple but professional PDF using HTML and CSS
    $current_date = date('d/m/Y');
    $current_time = date('H:i');
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Form F - Patient Consent Form</title>
        <style>
            @page {
                margin: 1in;
                size: A4;
            }
            body { 
                font-family: "Times New Roman", serif; 
                font-size: 12pt; 
                line-height: 1.4; 
                margin: 0; 
                padding: 20px;
                color: #000;
            }
            .header { 
                text-align: center; 
                margin-bottom: 30px; 
                border-bottom: 3px solid #000; 
                padding-bottom: 15px; 
            }
            .form-title { 
                font-size: 20pt; 
                font-weight: bold; 
                margin-bottom: 8px; 
                text-transform: uppercase;
            }
            .form-number { 
                font-size: 14pt; 
                margin-bottom: 8px; 
                font-weight: bold;
            }
            .form-date { 
                font-size: 12pt; 
                margin-bottom: 5px;
            }
            .section { 
                margin-bottom: 25px; 
                page-break-inside: avoid;
            }
            .section-title { 
                font-weight: bold; 
                font-size: 14pt; 
                margin-bottom: 12px; 
                border-bottom: 2px solid #333; 
                padding-bottom: 5px;
                text-transform: uppercase;
            }
            .info-grid { 
                display: table; 
                width: 100%; 
                margin-bottom: 10px;
            }
            .info-row { 
                display: table-row; 
            }
            .info-cell { 
                display: table-cell; 
                width: 50%; 
                padding: 8px 15px 8px 0; 
                vertical-align: top;
            }
            .info-cell:first-child { 
                padding-left: 0; 
            }
            .info-label { 
                font-weight: bold; 
                display: inline-block; 
                min-width: 120px;
            }
            .signature-area { 
                border: 2px solid #000; 
                height: 80px; 
                margin: 15px 0; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                background: #f9f9f9;
                font-style: italic;
                color: #666;
            }
            .consent-list { 
                margin: 20px 0; 
            }
            .consent-list ol { 
                margin-left: 25px; 
                margin-top: 10px;
            }
            .consent-list li { 
                margin-bottom: 8px; 
                line-height: 1.5;
            }
            .footer { 
                margin-top: 40px; 
                text-align: center; 
                font-size: 10pt; 
                border-top: 1px solid #ccc;
                padding-top: 15px;
            }
            .signature-section {
                margin-top: 30px;
            }
            .signature-grid {
                display: table;
                width: 100%;
            }
            .signature-cell {
                display: table-cell;
                width: 50%;
                padding: 0 20px;
                vertical-align: top;
            }
            .signature-cell:first-child {
                padding-left: 0;
            }
            .signature-cell:last-child {
                padding-right: 0;
            }
            .date-line {
                margin-top: 10px;
                border-bottom: 1px solid #000;
                height: 20px;
            }
            .clinic-info {
                text-align: center;
                margin-bottom: 20px;
                font-size: 14pt;
                font-weight: bold;
            }
            @media print {
                body { margin: 0.5in; }
                .no-print { display: none; }
                .signature-area { 
                    border: 2px solid #000 !important;
                    background: white !important;
                }
            }
        </style>
    </head>
    <body>
        <div class="clinic-info">
            ISHTAH CLINIC<br>
            <span style="font-size: 12pt; font-weight: normal;">Ultrasound & Diagnostic Center</span>
        </div>
        
        <div class="header">
            <div class="form-title">FORM F - PATIENT CONSENT FORM</div>
            <div class="form-number">Form Number: ' . htmlspecialchars($form_data['form_number']) . '</div>
            <div class="form-date">Date: ' . $current_date . ' | Time: ' . $current_time . '</div>
        </div>
        
        <div class="section">
            <div class="section-title">Patient Information</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-cell">
                        <span class="info-label">Patient Name:</span> ' . htmlspecialchars($form_data['patient_name']) . '
                    </div>
                    <div class="info-cell">
                        <span class="info-label">Age:</span> ' . $form_data['patient_age'] . ' years
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-cell">
                        <span class="info-label">Gender:</span> ' . htmlspecialchars($form_data['patient_gender']) . '
                    </div>
                    <div class="info-cell">
                        <span class="info-label">Phone:</span> ' . htmlspecialchars($form_data['patient_phone']) . '
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-cell">
                        <span class="info-label">Emergency Contact:</span> ' . htmlspecialchars($form_data['emergency_contact']) . '
                    </div>
                    <div class="info-cell">
                        <span class="info-label">Emergency Phone:</span> ' . htmlspecialchars($form_data['emergency_phone']) . '
                    </div>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <span class="info-label">Address:</span> ' . htmlspecialchars($form_data['patient_address']) . '
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Scan Information</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-cell">
                        <span class="info-label">Scan Type:</span> ' . htmlspecialchars($form_data['scan_type']) . '
                    </div>
                    <div class="info-cell">
                        <span class="info-label">Referring Doctor:</span> ' . htmlspecialchars($form_data['referring_doctor']) . '
                    </div>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <span class="info-label">Scan Details:</span><br>
                <div style="margin-left: 120px; margin-top: 5px;">
                    ' . nl2br(htmlspecialchars($form_data['scan_details'])) . '
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Consent Statement</div>
            <p style="margin-bottom: 15px;">I, <strong>' . htmlspecialchars($form_data['patient_name']) . '</strong>, hereby give my informed consent for the ultrasound examination as described above. I understand that:</p>
            <div class="consent-list">
                <ol>
                    <li>This is a diagnostic procedure that uses sound waves to create images of internal organs.</li>
                    <li>The procedure is generally safe and non-invasive.</li>
                    <li>I may be asked to change position or hold my breath during the examination.</li>
                    <li>The results will be interpreted by a qualified medical professional.</li>
                    <li>I have the right to ask questions and withdraw my consent at any time.</li>
                    <li>I have been informed about the procedure and any potential risks involved.</li>
                    <li>I understand that the results will be shared with my referring doctor.</li>
                </ol>
            </div>
        </div>
        
        <div class="section signature-section">
            <div class="section-title">Signatures</div>
            <div class="signature-grid">
                <div class="signature-cell">
                    <strong>Patient Signature:</strong><br>
                    <div class="signature-area">Patient signature here</div>
                    <strong>Date:</strong>
                    <div class="date-line"></div>
                </div>
                <div class="signature-cell">
                    <strong>Witness Signature:</strong><br>
                    <div class="signature-area">Witness signature here</div>
                    <strong>Date:</strong>
                    <div class="date-line"></div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Additional Notes</div>
            <div style="margin-left: 20px;">
                ' . nl2br(htmlspecialchars($form_data['notes'])) . '
            </div>
        </div>
        
        <div class="footer">
            <p><strong>This form is a legal document. Please ensure all information is accurate and complete.</strong></p>
            <p>Generated on: ' . $current_date . ' at ' . $current_time . '</p>
            <p>Form F Number: ' . htmlspecialchars($form_data['form_number']) . '</p>
        </div>
    </body>
    </html>';

    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Form_F_' . $form_data['form_number'] . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // For now, we'll use a simple approach
    // In production, you should use a proper PDF library like TCPDF, mPDF, or wkhtmltopdf
    echo generatePDFFromHTML($html);
}

function generatePDFFromHTML($html) {
    // This is a simplified approach
    // For production use, implement one of these solutions:
    
    // Option 1: Use wkhtmltopdf (recommended for best quality)
    // $command = "wkhtmltopdf --page-size A4 --margin-top 0.5in --margin-bottom 0.5in --margin-left 0.5in --margin-right 0.5in - -";
    // $descriptors = array(0 => array("pipe", "r"), 1 => array("pipe", "w"));
    // $process = proc_open($command, $descriptors, $pipes);
    // fwrite($pipes[0], $html);
    // fclose($pipes[0]);
    // $pdf = stream_get_contents($pipes[1]);
    // fclose($pipes[1]);
    // proc_close($process);
    // return $pdf;
    
    // Option 2: Use mPDF library
    // require_once 'vendor/autoload.php';
    // $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => 15]);
    // $mpdf->WriteHTML($html);
    // return $mpdf->Output('', \Mpdf\Output\Destination::STRING);
    
    // Option 3: Use TCPDF library
    // require_once 'vendor/autoload.php';
    // $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    // $pdf->SetCreator('Ishtah Clinic');
    // $pdf->SetAuthor('Ishtah Clinic');
    // $pdf->SetTitle('Form F - Patient Consent Form');
    // $pdf->AddPage();
    // $pdf->writeHTML($html, true, false, true, false, '');
    // return $pdf->Output('', 'S');
    
    // For now, return HTML that can be printed as PDF
    return $html;
}
?>
