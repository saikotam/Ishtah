<?php
/**
 * Enhanced Sync Integration
 * This file provides bulletproof integration functions that replace the existing
 * manual accounting calls with automatic, error-proof synchronization
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/accounting.php';

class EnhancedSyncIntegration {
    private $pdo;
    private $accounting;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->accounting = new AccountingSystem($pdo);
    }
    
    /**
     * Enhanced consultation revenue recording with multiple failsafes
     */
    public function recordConsultationRevenue($invoiceId, $patientId, $amount, $mode, $visitId = null) {
        $operationId = uniqid('consultation_', true);
        
        try {
            // Primary sync attempt - immediate processing
            $journalEntryId = $this->accounting->recordConsultationRevenue(
                $patientId, 
                $this->getDoctorIdForPatient($patientId, $visitId), 
                $amount, 
                strtolower($mode)
            );
            
            // Mark as successfully synced
            $this->markSyncSuccess('CONSULTATION', $invoiceId, $journalEntryId, $operationId);
            
            $this->log("Consultation revenue recorded successfully (Invoice: {$invoiceId}, Journal: {$journalEntryId})", 'INFO');
            return $journalEntryId;
            
        } catch (Exception $e) {
            // Primary sync failed - queue for retry with comprehensive data
            $this->queueForRetrySync('CONSULTATION', $invoiceId, 'INSERT', [
                'id' => $invoiceId,
                'patient_id' => $patientId,
                'visit_id' => $visitId,
                'amount' => $amount,
                'mode' => $mode,
                'created_at' => date('Y-m-d H:i:s'),
                'operation_id' => $operationId
            ], $e->getMessage());
            
            // Don't throw exception - let the operation continue
            $this->log("Consultation revenue queued for retry (Invoice: {$invoiceId}): " . $e->getMessage(), 'WARNING');
            return null;
        }
    }
    
    /**
     * Enhanced pharmacy sale recording
     */
    public function recordPharmacySale($billId, $totalAmount, $gstAmount, $costAmount, $paymentMode = 'cash') {
        $operationId = uniqid('pharmacy_', true);
        
        try {
            // Check if already synced to prevent duplicates
            if ($this->isAlreadySynced('PHARMACY', $billId)) {
                $this->log("Pharmacy sale already synced (Bill: {$billId})", 'DEBUG');
                return $this->getExistingJournalEntryId('PHARMACY', $billId);
            }
            
            $journalEntryId = $this->accounting->recordPharmacySale(
                $billId, $totalAmount, $gstAmount, $costAmount, $paymentMode
            );
            
            $this->markSyncSuccess('PHARMACY', $billId, $journalEntryId, $operationId);
            $this->log("Pharmacy sale recorded successfully (Bill: {$billId}, Journal: {$journalEntryId})", 'INFO');
            return $journalEntryId;
            
        } catch (Exception $e) {
            $this->queueForRetrySync('PHARMACY', $billId, 'INSERT', [
                'id' => $billId,
                'total_amount' => $totalAmount,
                'gst_amount' => $gstAmount,
                'estimated_cogs' => $costAmount,
                'payment_mode' => $paymentMode,
                'created_at' => date('Y-m-d H:i:s'),
                'operation_id' => $operationId
            ], $e->getMessage());
            
            $this->log("Pharmacy sale queued for retry (Bill: {$billId}): " . $e->getMessage(), 'WARNING');
            return null;
        }
    }
    
    /**
     * Enhanced lab revenue recording
     */
    public function recordLabRevenue($billId, $amount, $paymentMode = 'cash') {
        $operationId = uniqid('lab_', true);
        
        try {
            if ($this->isAlreadySynced('LAB', $billId)) {
                $this->log("Lab revenue already synced (Bill: {$billId})", 'DEBUG');
                return $this->getExistingJournalEntryId('LAB', $billId);
            }
            
            $journalEntryId = $this->accounting->recordLabRevenue($billId, $amount, $paymentMode);
            
            $this->markSyncSuccess('LAB', $billId, $journalEntryId, $operationId);
            $this->log("Lab revenue recorded successfully (Bill: {$billId}, Journal: {$journalEntryId})", 'INFO');
            return $journalEntryId;
            
        } catch (Exception $e) {
            $this->queueForRetrySync('LAB', $billId, 'INSERT', [
                'id' => $billId,
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'created_at' => date('Y-m-d H:i:s'),
                'operation_id' => $operationId
            ], $e->getMessage());
            
            $this->log("Lab revenue queued for retry (Bill: {$billId}): " . $e->getMessage(), 'WARNING');
            return null;
        }
    }
    
    /**
     * Enhanced ultrasound revenue recording
     */
    public function recordUltrasoundRevenue($billId, $amount, $paymentMode = 'cash') {
        $operationId = uniqid('ultrasound_', true);
        
        try {
            if ($this->isAlreadySynced('ULTRASOUND', $billId)) {
                $this->log("Ultrasound revenue already synced (Bill: {$billId})", 'DEBUG');
                return $this->getExistingJournalEntryId('ULTRASOUND', $billId);
            }
            
            $journalEntryId = $this->accounting->recordUltrasoundRevenue($billId, $amount, $paymentMode);
            
            $this->markSyncSuccess('ULTRASOUND', $billId, $journalEntryId, $operationId);
            $this->log("Ultrasound revenue recorded successfully (Bill: {$billId}, Journal: {$journalEntryId})", 'INFO');
            return $journalEntryId;
            
        } catch (Exception $e) {
            $this->queueForRetrySync('ULTRASOUND', $billId, 'INSERT', [
                'id' => $billId,
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'created_at' => date('Y-m-d H:i:s'),
                'operation_id' => $operationId
            ], $e->getMessage());
            
            $this->log("Ultrasound revenue queued for retry (Bill: {$billId}): " . $e->getMessage(), 'WARNING');
            return null;
        }
    }
    
    /**
     * Check if record is already synced
     */
    private function isAlreadySynced($referenceType, $referenceId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM journal_entries 
            WHERE reference_type = ? AND reference_id = ? AND status = 'POSTED'
        ");
        $stmt->execute([$referenceType, $referenceId]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Get existing journal entry ID
     */
    private function getExistingJournalEntryId($referenceType, $referenceId) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM journal_entries 
            WHERE reference_type = ? AND reference_id = ? AND status = 'POSTED'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$referenceType, $referenceId]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Queue item for retry sync
     */
    private function queueForRetrySync($referenceType, $referenceId, $operationType, $syncData, $errorMessage) {
        try {
            $stmt = $this->pdo->prepare("CALL AddToSyncQueue(?, ?, ?, ?, 1)");
            $stmt->execute([$referenceType, $referenceId, $operationType, json_encode($syncData)]);
            
            // Also create an alert for immediate attention
            $this->createAlert('SYNC_FAILURES', 'MEDIUM', 
                "Sync failed for {$referenceType} ID {$referenceId}: {$errorMessage}", [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'error' => $errorMessage,
                    'sync_data' => $syncData
                ]);
                
        } catch (Exception $e) {
            // Last resort - log to error log
            error_log("CRITICAL: Failed to queue sync for {$referenceType} {$referenceId}: " . $e->getMessage());
        }
    }
    
    /**
     * Mark sync as successful
     */
    private function markSyncSuccess($referenceType, $referenceId, $journalEntryId, $operationId) {
        try {
            // Update master sync status
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_master_status 
                (reference_type, reference_id, is_synced, journal_entry_id, successful_sync_at, sync_attempts)
                VALUES (?, ?, TRUE, ?, CURRENT_TIMESTAMP, 1)
                ON DUPLICATE KEY UPDATE
                is_synced = TRUE,
                journal_entry_id = ?,
                successful_sync_at = CURRENT_TIMESTAMP,
                last_error = NULL
            ");
            $stmt->execute([$referenceType, $referenceId, $journalEntryId, $journalEntryId]);
            
            // Log success in audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_audit_log 
                (operation_id, reference_type, reference_id, operation_type, sync_status, journal_entry_id)
                VALUES (?, ?, ?, 'INSERT', 'SUCCESS', ?)
            ");
            $stmt->execute([$operationId, $referenceType, $referenceId, $journalEntryId]);
            
        } catch (Exception $e) {
            // Don't fail the main operation if we can't update sync status
            $this->log("Warning: Could not update sync status: " . $e->getMessage(), 'WARNING');
        }
    }
    
    /**
     * Get doctor ID for patient
     */
    private function getDoctorIdForPatient($patientId, $visitId = null) {
        if ($visitId) {
            $stmt = $this->pdo->prepare("SELECT doctor_id FROM visits WHERE id = ?");
            $stmt->execute([$visitId]);
            $result = $stmt->fetchColumn();
            if ($result) return $result;
        }
        
        // Fallback: get most recent visit for patient
        $stmt = $this->pdo->prepare("
            SELECT doctor_id FROM visits 
            WHERE patient_id = ? 
            ORDER BY visit_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetchColumn() ?: 0;
    }
    
    /**
     * Create system alert
     */
    private function createAlert($alertType, $severity, $message, $details = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO sync_alerts (alert_type, severity, message, details)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $alertType,
                $severity,
                $message,
                $details ? json_encode($details) : null
            ]);
        } catch (Exception $e) {
            // Don't fail if we can't create alert
            error_log("Could not create alert: " . $e->getMessage());
        }
    }
    
    /**
     * Comprehensive sync verification
     * This method performs deep verification to ensure no entries are missed
     */
    public function verifySyncIntegrity($referenceType = null, $startDate = null, $endDate = null) {
        $this->log("Starting sync integrity verification", 'INFO');
        
        $results = [
            'total_checked' => 0,
            'missing_syncs' => 0,
            'orphaned_entries' => 0,
            'corrections_made' => 0,
            'errors' => []
        ];
        
        try {
            // Check consultation invoices
            if (!$referenceType || $referenceType === 'CONSULTATION') {
                $consultationResults = $this->verifyConsultationSync($startDate, $endDate);
                $results = $this->mergeResults($results, $consultationResults);
            }
            
            // Check pharmacy bills
            if (!$referenceType || $referenceType === 'PHARMACY') {
                $pharmacyResults = $this->verifyPharmacySync($startDate, $endDate);
                $results = $this->mergeResults($results, $pharmacyResults);
            }
            
            // Check lab bills
            if (!$referenceType || $referenceType === 'LAB') {
                $labResults = $this->verifyLabSync($startDate, $endDate);
                $results = $this->mergeResults($results, $labResults);
            }
            
            // Check ultrasound bills
            if (!$referenceType || $referenceType === 'ULTRASOUND') {
                $ultrasoundResults = $this->verifyUltrasoundSync($startDate, $endDate);
                $results = $this->mergeResults($results, $ultrasoundResults);
            }
            
            // Check for orphaned journal entries
            $orphanedResults = $this->findOrphanedJournalEntries();
            $results = $this->mergeResults($results, $orphanedResults);
            
            $this->log("Sync integrity verification completed: " . json_encode($results), 'INFO');
            
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            $this->log("Sync integrity verification failed: " . $e->getMessage(), 'ERROR');
        }
        
        return $results;
    }
    
    /**
     * Verify consultation sync integrity
     */
    private function verifyConsultationSync($startDate, $endDate) {
        $results = ['total_checked' => 0, 'missing_syncs' => 0, 'corrections_made' => 0];
        
        $whereClause = "ci.amount > 0";
        $params = [];
        
        if ($startDate) {
            $whereClause .= " AND ci.created_at >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $whereClause .= " AND ci.created_at <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT ci.id, ci.patient_id, ci.visit_id, ci.amount, ci.mode, ci.created_at
            FROM consultation_invoices ci
            LEFT JOIN journal_entries je ON je.reference_type = 'CONSULTATION' AND je.reference_id = ci.id AND je.status = 'POSTED'
            WHERE {$whereClause} AND je.id IS NULL
            ORDER BY ci.created_at DESC
        ");
        $stmt->execute($params);
        
        while ($row = $stmt->fetch()) {
            $results['total_checked']++;
            $results['missing_syncs']++;
            
            try {
                // Queue for sync
                $this->queueForRetrySync('CONSULTATION', $row['id'], 'INSERT', $row, 'Missing from verification');
                $results['corrections_made']++;
            } catch (Exception $e) {
                // Continue processing other records
            }
        }
        
        return $results;
    }
    
    /**
     * Verify pharmacy sync integrity
     */
    private function verifyPharmacySync($startDate, $endDate) {
        $results = ['total_checked' => 0, 'missing_syncs' => 0, 'corrections_made' => 0];
        
        $whereClause = "pb.invoice_number IS NOT NULL AND pb.discounted_total > 0";
        $params = [];
        
        if ($startDate) {
            $whereClause .= " AND pb.created_at >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $whereClause .= " AND pb.created_at <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT pb.*
            FROM pharmacy_bills pb
            LEFT JOIN journal_entries je ON je.reference_type = 'PHARMACY' AND je.reference_id = pb.id AND je.status = 'POSTED'
            WHERE {$whereClause} AND je.id IS NULL
            ORDER BY pb.created_at DESC
        ");
        $stmt->execute($params);
        
        while ($row = $stmt->fetch()) {
            $results['total_checked']++;
            $results['missing_syncs']++;
            
            try {
                $this->queueForRetrySync('PHARMACY', $row['id'], 'INSERT', $row, 'Missing from verification');
                $results['corrections_made']++;
            } catch (Exception $e) {
                // Continue processing
            }
        }
        
        return $results;
    }
    
    /**
     * Verify lab sync integrity
     */
    private function verifyLabSync($startDate, $endDate) {
        $results = ['total_checked' => 0, 'missing_syncs' => 0, 'corrections_made' => 0];
        
        $whereClause = "lb.invoice_number IS NOT NULL AND lb.discounted_amount > 0";
        $params = [];
        
        if ($startDate) {
            $whereClause .= " AND lb.created_at >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $whereClause .= " AND lb.created_at <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT lb.*
            FROM lab_bills lb
            LEFT JOIN journal_entries je ON je.reference_type = 'LAB' AND je.reference_id = lb.id AND je.status = 'POSTED'
            WHERE {$whereClause} AND je.id IS NULL
            ORDER BY lb.created_at DESC
        ");
        $stmt->execute($params);
        
        while ($row = $stmt->fetch()) {
            $results['total_checked']++;
            $results['missing_syncs']++;
            
            try {
                $this->queueForRetrySync('LAB', $row['id'], 'INSERT', $row, 'Missing from verification');
                $results['corrections_made']++;
            } catch (Exception $e) {
                // Continue processing
            }
        }
        
        return $results;
    }
    
    /**
     * Verify ultrasound sync integrity
     */
    private function verifyUltrasoundSync($startDate, $endDate) {
        $results = ['total_checked' => 0, 'missing_syncs' => 0, 'corrections_made' => 0];
        
        $whereClause = "ub.invoice_number IS NOT NULL AND ub.discounted_total > 0";
        $params = [];
        
        if ($startDate) {
            $whereClause .= " AND ub.created_at >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $whereClause .= " AND ub.created_at <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT ub.*
            FROM ultrasound_bills ub
            LEFT JOIN journal_entries je ON je.reference_type = 'ULTRASOUND' AND je.reference_id = ub.id AND je.status = 'POSTED'
            WHERE {$whereClause} AND je.id IS NULL
            ORDER BY ub.created_at DESC
        ");
        $stmt->execute($params);
        
        while ($row = $stmt->fetch()) {
            $results['total_checked']++;
            $results['missing_syncs']++;
            
            try {
                $this->queueForRetrySync('ULTRASOUND', $row['id'], 'INSERT', $row, 'Missing from verification');
                $results['corrections_made']++;
            } catch (Exception $e) {
                // Continue processing
            }
        }
        
        return $results;
    }
    
    /**
     * Find orphaned journal entries
     */
    private function findOrphanedJournalEntries() {
        $results = ['total_checked' => 0, 'orphaned_entries' => 0, 'corrections_made' => 0];
        
        $stmt = $this->pdo->query("
            SELECT je.id, je.reference_type, je.reference_id
            FROM journal_entries je
            LEFT JOIN consultation_invoices ci ON je.reference_type = 'CONSULTATION' AND je.reference_id = ci.id
            LEFT JOIN pharmacy_bills pb ON je.reference_type = 'PHARMACY' AND je.reference_id = pb.id
            LEFT JOIN lab_bills lb ON je.reference_type = 'LAB' AND je.reference_id = lb.id
            LEFT JOIN ultrasound_bills ub ON je.reference_type = 'ULTRASOUND' AND je.reference_id = ub.id
            WHERE je.reference_type IN ('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND')
            AND ci.id IS NULL AND pb.id IS NULL AND lb.id IS NULL AND ub.id IS NULL
        ");
        
        while ($row = $stmt->fetch()) {
            $results['total_checked']++;
            $results['orphaned_entries']++;
            
            // Log orphaned entry for manual review
            $this->createAlert('DATA_INCONSISTENCY', 'MEDIUM', 
                "Orphaned journal entry found: {$row['reference_type']} ID {$row['reference_id']}", [
                    'journal_entry_id' => $row['id'],
                    'reference_type' => $row['reference_type'],
                    'reference_id' => $row['reference_id']
                ]);
        }
        
        return $results;
    }
    
    /**
     * Merge verification results
     */
    private function mergeResults($results1, $results2) {
        return [
            'total_checked' => $results1['total_checked'] + $results2['total_checked'],
            'missing_syncs' => ($results1['missing_syncs'] ?? 0) + ($results2['missing_syncs'] ?? 0),
            'orphaned_entries' => ($results1['orphaned_entries'] ?? 0) + ($results2['orphaned_entries'] ?? 0),
            'corrections_made' => $results1['corrections_made'] + $results2['corrections_made'],
            'errors' => array_merge($results1['errors'] ?? [], $results2['errors'] ?? [])
        ];
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [ENHANCED_SYNC] [{$level}] {$message}";
        error_log($logMessage);
    }
}

// Global function for easy access
function getEnhancedSyncIntegration($pdo = null) {
    global $pdo as $globalPdo;
    return new EnhancedSyncIntegration($pdo ?: $globalPdo);
}
?>