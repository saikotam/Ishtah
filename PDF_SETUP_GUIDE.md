# Form F PDF Generation Setup Guide

## Overview
The Form F system now includes PDF generation capabilities. This guide explains how to set up proper PDF generation for production use.

## Current Implementation
Currently, the system generates HTML that can be printed as PDF. For better quality and proper PDF generation, you should implement one of the following solutions:

## Option 1: wkhtmltopdf (Recommended - Best Quality)

### Installation
1. **Windows:**
   ```bash
   # Download from: https://wkhtmltopdf.org/downloads.html
   # Install the Windows installer
   ```

2. **Linux (Ubuntu/Debian):**
   ```bash
   sudo apt-get install wkhtmltopdf
   ```

3. **macOS:**
   ```bash
   brew install wkhtmltopdf
   ```

### Implementation
Update the `generatePDFFromHTML()` function in `form_f_pdf_generator.php`:

```php
function generatePDFFromHTML($html) {
    $command = "wkhtmltopdf --page-size A4 --margin-top 0.5in --margin-bottom 0.5in --margin-left 0.5in --margin-right 0.5in - -";
    $descriptors = array(0 => array("pipe", "r"), 1 => array("pipe", "w"));
    $process = proc_open($command, $descriptors, $pipes);
    
    if (is_resource($process)) {
        fwrite($pipes[0], $html);
        fclose($pipes[0]);
        $pdf = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return $pdf;
    }
    
    // Fallback to HTML if wkhtmltopdf fails
    return $html;
}
```

## Option 2: mPDF Library

### Installation
```bash
composer require mpdf/mpdf
```

### Implementation
Update the `generatePDFFromHTML()` function:

```php
function generatePDFFromHTML($html) {
    require_once 'vendor/autoload.php';
    
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 15,
        'margin_bottom' => 15
    ]);
    
    $mpdf->WriteHTML($html);
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING);
}
```

## Option 3: TCPDF Library

### Installation
```bash
composer require tecnickcom/tcpdf
```

### Implementation
Update the `generatePDFFromHTML()` function:

```php
function generatePDFFromHTML($html) {
    require_once 'vendor/autoload.php';
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('Ishtah Clinic');
    $pdf->SetAuthor('Ishtah Clinic');
    $pdf->SetTitle('Form F - Patient Consent Form');
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    return $pdf->Output('', 'S');
}
```

## Option 4: Dompdf Library

### Installation
```bash
composer require dompdf/dompdf
```

### Implementation
Update the `generatePDFFromHTML()` function:

```php
function generatePDFFromHTML($html) {
    require_once 'vendor/autoload.php';
    
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}
```

## Features of the Current Implementation

### Form F Template Includes:
- ✅ **Clinic Header**: Ishtah Clinic branding
- ✅ **Form Number**: Auto-generated unique identifier
- ✅ **Patient Information**: Name, age, gender, contact details
- ✅ **Scan Information**: Type, referring doctor, details
- ✅ **Consent Statement**: Legal consent with numbered points
- ✅ **Signature Areas**: Patient and witness signature boxes
- ✅ **Additional Notes**: Custom notes section
- ✅ **Professional Formatting**: Clean, medical document layout

### Auto-filled Fields:
- Patient name, age, gender
- Contact information and address
- Emergency contact details
- Scan type and details
- Referring doctor information
- Current date and time
- Unique form number

## Usage Instructions

### For Staff:
1. Go to Form F Management page
2. Click "Form F Generator" button
3. Fill in the required scan information
4. Click "Generate Form F PDF"
5. Print the PDF for patient signature

### For PDF Generation:
1. Use the "Generate PDF" button in the Form F records table
2. Or access directly: `form_f_pdf_generator.php?visit_id=X&form_id=Y`

## File Structure
```
cgi-bin/
├── form_f_management.php      # Main Form F management
├── form_f_generator.php       # Form creation and PDF generation
├── form_f_pdf_generator.php   # Dedicated PDF generator
└── PDF_SETUP_GUIDE.md        # This guide
```

## Security Considerations
- All user inputs are sanitized
- Form numbers are unique and traceable
- PDFs are generated server-side
- Access is restricted to valid visit IDs

## Troubleshooting

### Common Issues:
1. **PDF not generating**: Check if the selected PDF library is installed
2. **Font issues**: Ensure system fonts are available
3. **Memory limits**: Increase PHP memory limit for large documents
4. **Permission errors**: Ensure write permissions for temporary files

### Performance Tips:
1. Use wkhtmltopdf for best quality and performance
2. Consider caching generated PDFs for frequently accessed forms
3. Implement PDF compression for storage efficiency

## Next Steps
1. Choose and install a PDF library
2. Update the `generatePDFFromHTML()` function
3. Test PDF generation with sample data
4. Deploy to production environment
5. Train staff on the new workflow
