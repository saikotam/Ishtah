<?php
// sync_accounting.php - Backfill accounting journal entries from operational data
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/accounting.php';

/**
 * Ensure core accounting tables exist; if missing, create them using accounting_schema.sql
 */
function ensureAccountingTablesExist(PDO $pdo): void {
    $requiredTables = [
        'chart_of_accounts',
        'journal_entries',
        'journal_entry_lines',
        'account_balances',
        'financial_periods'
    ];

    $missing = [];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($table) . "'");
        if ($stmt->rowCount() === 0) {
            $missing[] = $table;
        }
    }

    if (!empty($missing)) {
        $schemaFile = __DIR__ . '/accounting_schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception('Accounting schema file not found: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);
        // Split cautiously; ignore comments and empty statements
        $statements = array_filter(array_map('trim', explode(';', $sql)), function ($stmt) {
            if ($stmt === '') return false;
            if (strpos(ltrim($stmt), '--') === 0) return false;
            return true;
        });

        $pdo->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/**
 * Backfill accounting journal entries from operational billing tables.
 * Idempotent: checks for existing journal entries by (reference_type, reference_id).
 *
 * Returns summary counts for each source.
 */
function syncAccountingFromOperationalData(PDO $pdo): array {
    ensureAccountingTablesExist($pdo);

    $accounting = new AccountingSystem($pdo);

    $created = [
        'pharmacy' => 0,
        'lab' => 0,
        'ultrasound' => 0,
        // consultations intentionally skipped due to non-unique reference mapping
    ];

    // Pharmacy bills → PHARMACY
    try {
        $sql = "SELECT id, discounted_total, gst_amount FROM pharmacy_bills WHERE invoice_number IS NOT NULL";
        foreach ($pdo->query($sql) as $row) {
            $billId = (int)$row['id'];
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'PHARMACY' AND reference_id = ?");
            $existsStmt->execute([$billId]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                $total = (float)$row['discounted_total'];
                $gst = (float)$row['gst_amount'];
                $estimatedCogs = round($total * 0.7, 2);
                $accounting->recordPharmacySale($billId, $total, $gst, $estimatedCogs, 'cash');
                $created['pharmacy']++;
            }
        }
    } catch (Throwable $e) {
        error_log('Accounting sync (pharmacy) failed: ' . $e->getMessage());
    }

    // Lab bills → LAB
    try {
        $sql = "SELECT id, discounted_amount FROM lab_bills WHERE invoice_number IS NOT NULL";
        foreach ($pdo->query($sql) as $row) {
            $billId = (int)$row['id'];
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'LAB' AND reference_id = ?");
            $existsStmt->execute([$billId]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                $amount = (float)$row['discounted_amount'];
                $accounting->recordLabRevenue($billId, $amount, 'cash');
                $created['lab']++;
            }
        }
    } catch (Throwable $e) {
        error_log('Accounting sync (lab) failed: ' . $e->getMessage());
    }

    // Ultrasound bills → ULTRASOUND
    try {
        $sql = "SELECT id, discounted_total FROM ultrasound_bills WHERE invoice_number IS NOT NULL";
        foreach ($pdo->query($sql) as $row) {
            $billId = (int)$row['id'];
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'ULTRASOUND' AND reference_id = ?");
            $existsStmt->execute([$billId]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                $amount = (float)$row['discounted_total'];
                $accounting->recordUltrasoundRevenue($billId, $amount, 'cash');
                $created['ultrasound']++;
            }
        }
    } catch (Throwable $e) {
        error_log('Accounting sync (ultrasound) failed: ' . $e->getMessage());
    }

    return $created;
}

?>

