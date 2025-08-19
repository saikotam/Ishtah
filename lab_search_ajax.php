<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';

// Get search parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;

// Validate visit_id
if (!$visit_id) {
    echo '<div class="alert alert-danger">Invalid visit ID.</div>';
    exit;
}

// Perform search if query is provided
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE test_name LIKE ? ORDER BY test_name LIMIT 20");
    $stmt->execute(['%' . $search . '%']);
    $tests = $stmt->fetchAll();
    
    if (!empty($tests)) {
        echo '<form method="post" class="mb-3">';
        echo '<div class="table-responsive mb-3">';
        echo '<table class="table table-bordered align-middle">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Test</th>';
        echo '<th>Price (₹)</th>';
        echo '<th>Add</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($tests as $test) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($test['test_name']) . '</td>';
            echo '<td>' . number_format($test['price'], 2) . '</td>';
            echo '<td>';
            echo '<button name="add_test" value="' . $test['id'] . '" class="btn btn-sm btn-success">Add</button>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</form>';
    } else {
        echo '<div class="alert alert-info">No lab tests found matching "' . htmlspecialchars($search) . '". Try a different search term.</div>';
    }
} else {
    echo '<div class="alert alert-info">Enter a search term to find lab tests.</div>';
}
?>
