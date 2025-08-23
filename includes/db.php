<?php
// includes/db.php - Database connection
// Temporarily using SQLite for immediate functionality
$sqlite_path = __DIR__ . '/../database/clinic.sqlite';
$sqlite_dir = dirname($sqlite_path);

// Create database directory if it doesn't exist
if (!is_dir($sqlite_dir)) {
    mkdir($sqlite_dir, 0755, true);
}

$dsn = "sqlite:$sqlite_path";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, null, null, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create patients table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        gender TEXT NOT NULL CHECK (gender IN ('Male', 'Female', 'Other')),
        dob TEXT NOT NULL,
        contact_number TEXT NOT NULL,
        address TEXT NOT NULL,
        lead_source TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create visits table if it doesn't exist (needed for patient management)
    $pdo->exec("CREATE TABLE IF NOT EXISTS visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER,
        visit_date DATE NOT NULL DEFAULT (DATE('now')),
        reason TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(id)
    )");
    
    // Fix any existing visits that might have NULL visit_date
    $pdo->exec("UPDATE visits SET visit_date = DATE(created_at) WHERE visit_date IS NULL OR visit_date = ''");
    
    // Create doctors table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS doctors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        specialty TEXT,
        fees DECIMAL(10,2) DEFAULT 300.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create system_log table if it doesn't exist (for logging actions)
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_type TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Insert default doctors if table is empty
    $doctor_count = $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
    if ($doctor_count == 0) {
        $pdo->exec("INSERT INTO doctors (name, specialty, fees) VALUES 
            ('Dr. Smith', 'General Medicine', 300.00),
            ('Dr. Johnson', 'Cardiology', 500.00),
            ('Dr. Williams', 'Pediatrics', 400.00)");
    }
    
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
} 