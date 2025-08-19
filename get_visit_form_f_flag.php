<?php
// get_visit_form_f_flag.php - returns whether a visit has any ultrasound scans that require Form F
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';

header('Content-Type: application/json');

if (!isset($_GET['visit_id']) || empty($_GET['visit_id'])) {
	echo json_encode(['success' => false, 'message' => 'Visit ID is required']);
	exit;
}

$visit_id = intval($_GET['visit_id']);

try {
	$stmt = $pdo->prepare(
		"SELECT COUNT(*) FROM ultrasound_bill_items ubi
		 JOIN ultrasound_bills ub ON ub.id = ubi.bill_id
		 JOIN ultrasound_scans us ON us.id = ubi.scan_id
		 WHERE ub.visit_id = ? AND us.is_form_f_needed = 1"
	);
	$stmt->execute([$visit_id]);
	$count = (int)$stmt->fetchColumn();

	echo json_encode([
		'success' => true,
		'requires_form_f' => $count > 0
	]);
} catch (Exception $e) {
	echo json_encode([
		'success' => false,
		'message' => 'Error checking Form F flag: ' . $e->getMessage()
	]);
}
?>


