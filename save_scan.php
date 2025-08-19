<?php
// save_scan.php - Handle saving scanned documents for visits
require_once 'includes/db.php';
require_once 'includes/log.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    if (!isset($_POST['action']) || $_POST['action'] !== 'save_scan') {
        throw new Exception('Invalid action');
    }
    
    if (!isset($_POST['visit_id']) || empty($_POST['visit_id'])) {
        throw new Exception('Visit ID is required');
    }
    
    $visit_id = intval($_POST['visit_id']);
    
    // Validate visit ID
    if ($visit_id <= 0) {
        throw new Exception('Invalid visit ID');
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['scanned_file']) || $_FILES['scanned_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('No file uploaded');
    }
    
    $file = $_FILES['scanned_file'];
    
    // Basic file validation
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL => 'File upload was incomplete',
            UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $errorMsg = isset($errorMessages[$file['error']]) ? $errorMessages[$file['error']] : 'Unknown upload error';
        throw new Exception('File upload failed: ' . $errorMsg);
    }
    
    // Check file size (10MB max)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('File must be smaller than 10MB');
    }
    
    // Check file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('File must be JPEG, PNG, GIF, or PDF');
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/scans/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'visit_' . $visit_id . '_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save file');
    }
    
    // Save file information to database
    $stmt = $pdo->prepare("INSERT INTO visit_documents (visit_id, filename, original_name, file_type, file_size, uploaded_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $visit_id,
        $filename,
        $file['name'],
        $file['type'],
        $file['size']
    ]);
    
    $document_id = $pdo->lastInsertId();
    
    // Log the action
    log_action('Reception', 'Document Scanned', 'Visit ID: ' . $visit_id . ', Document: ' . $file['name']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Document saved successfully',
        'document_id' => $document_id,
        'filename' => $filename
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 