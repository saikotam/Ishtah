<?php
// delete_document.php - Delete a document
require_once 'includes/db.php';
require_once 'includes/log.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['document_id']) || empty($input['document_id'])) {
        throw new Exception('Document ID is required');
    }
    
    $document_id = intval($input['document_id']);
    
    // Get document information before deletion
    $stmt = $pdo->prepare("SELECT filename, visit_id, original_name FROM visit_documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        throw new Exception('Document not found');
    }
    
    // Delete the file from filesystem
    $filepath = 'uploads/scans/' . $document['filename'];
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    
    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM visit_documents WHERE id = ?");
    $stmt->execute([$document_id]);
    
    // Log the action
    log_action('Reception', 'Document Deleted', 'Visit ID: ' . $document['visit_id'] . ', Document: ' . $document['original_name']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Document deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 