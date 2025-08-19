<?php
// get_visit_documents.php - Fetch existing documents for a visit
require_once 'includes/db.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['visit_id']) || empty($_GET['visit_id'])) {
        throw new Exception('Visit ID is required');
    }
    
    $visit_id = intval($_GET['visit_id']);
    
    // Fetch documents for the visit
    $stmt = $pdo->prepare("SELECT id, filename, original_name, file_type, file_size, uploaded_at FROM visit_documents WHERE visit_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$visit_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'documents' => $documents
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 