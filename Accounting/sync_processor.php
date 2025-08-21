<?php
/**
 * Comprehensive Synchronization Processor
 * This service ensures bulletproof synchronization between operational and accounting data
 * Features:
 * - Automatic queue processing with intelligent retries
 * - Error recovery and self-healing
 * - Real-time monitoring and alerting
 * - Comprehensive logging and audit trails
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/accounting.php';

class SyncProcessor {
    private $pdo;
    private $accounting;
    private $config;
    private $processId;
    private $isRunning = false;
    private $stats = [
        'processed' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'retried' => 0,
        'abandoned' => 0,
        'start_time' => null,
        'last_activity' => null
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->accounting = new AccountingSystem($pdo);
        $this->processId = uniqid('sync_proc_', true);
        $this->loadConfiguration();
        $this->stats['start_time'] = microtime(true);
        
        // Register shutdown handler for cleanup
        register_shutdown_function([$this, 'shutdown']);
        
        // Handle signals gracefully
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
    }
    
    /**
     * Load configuration from database
     */
    private function loadConfiguration() {
        $stmt = $this->pdo->query("SELECT config_key, config_value, data_type FROM sync_configuration");
        $this->config = [];
        
        while ($row = $stmt->fetch()) {
            $value = $row['config_value'];
            
            switch ($row['data_type']) {
                case 'INTEGER':
                    $value = (int)$value;
                    break;
                case 'BOOLEAN':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'JSON':
                    $value = json_decode($value, true);
                    break;
            }
            
            $this->config[$row['config_key']] = $value;
        }
        
        // Set defaults if not configured
        $defaults = [
            'max_retry_attempts' => 5,
            'retry_delay_seconds' => 60,
            'retry_backoff_multiplier' => 2,
            'max_retry_delay_seconds' => 3600,
            'queue_batch_size' => 100,
            'sync_timeout_seconds' => 300,
            'enable_real_time_sync' => true,
            'enable_detailed_logging' => true
        ];
        
        foreach ($defaults as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }
    }
    
    /**
     * Main processing loop
     */
    public function run($maxIterations = null, $maxRuntime = null) {
        $this->isRunning = true;
        $iterations = 0;
        $startTime = time();
        
        $this->log("Sync processor starting (Process ID: {$this->processId})", 'INFO');
        
        try {
            while ($this->isRunning) {
                $this->stats['last_activity'] = microtime(true);
                
                // Check runtime limit
                if ($maxRuntime && (time() - $startTime) >= $maxRuntime) {
                    $this->log("Maximum runtime reached, stopping", 'INFO');
                    break;
                }
                
                // Check iteration limit
                if ($maxIterations && $iterations >= $maxIterations) {
                    $this->log("Maximum iterations reached, stopping", 'INFO');
                    break;
                }
                
                // Process a batch of items
                $processed = $this->processBatch();
                
                if ($processed === 0) {
                    // No items to process, sleep briefly
                    sleep(5);
                } else {
                    $iterations++;
                    $this->updateSystemHealth();
                }
                
                // Handle signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                // Check for configuration changes
                if ($iterations % 10 === 0) {
                    $this->loadConfiguration();
                }
            }
        } catch (Exception $e) {
            $this->log("Fatal error in sync processor: " . $e->getMessage(), 'ERROR');
            $this->createAlert('SYSTEM_DOWN', 'CRITICAL', 'Sync processor crashed: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'process_id' => $this->processId
            ]);
            throw $e;
        }
        
        $this->log("Sync processor stopped gracefully", 'INFO');
    }
    
    /**
     * Process a batch of items from the sync queue
     */
    private function processBatch() {
        $batchSize = $this->config['queue_batch_size'];
        $processed = 0;
        
        try {
            // Lock and fetch items for processing
            $items = $this->lockAndFetchQueueItems($batchSize);
            
            foreach ($items as $item) {
                try {
                    $this->processQueueItem($item);
                    $processed++;
                    $this->stats['processed']++;
                } catch (Exception $e) {
                    $this->log("Error processing queue item {$item['operation_id']}: " . $e->getMessage(), 'ERROR');
                    $this->handleSyncFailure($item['operation_id'], $e->getMessage(), $e->getTraceAsString());
                }
            }
            
        } catch (Exception $e) {
            $this->log("Error processing batch: " . $e->getMessage(), 'ERROR');
        }
        
        return $processed;
    }
    
    /**
     * Lock and fetch items from sync queue for processing
     */
    private function lockAndFetchQueueItems($limit) {
        $lockTimeout = $this->config['sync_timeout_seconds'];
        
        $this->pdo->beginTransaction();
        
        try {
            // Find items ready for processing
            $stmt = $this->pdo->prepare("
                SELECT operation_id, reference_type, reference_id, operation_type, sync_data, retry_count, max_retries
                FROM sync_queue 
                WHERE processed_at IS NULL 
                AND scheduled_at <= CURRENT_TIMESTAMP
                AND (locked_at IS NULL OR locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND))
                ORDER BY priority ASC, scheduled_at ASC
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute([$lockTimeout, $limit]);
            $items = $stmt->fetchAll();
            
            // Lock the selected items
            if (!empty($items)) {
                $operationIds = array_column($items, 'operation_id');
                $placeholders = str_repeat('?,', count($operationIds) - 1) . '?';
                
                $stmt = $this->pdo->prepare("
                    UPDATE sync_queue 
                    SET locked_at = CURRENT_TIMESTAMP, locked_by = ?
                    WHERE operation_id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$this->processId], $operationIds));
            }
            
            $this->pdo->commit();
            return $items;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Process a single queue item
     */
    private function processQueueItem($item) {
        $operationId = $item['operation_id'];
        $referenceType = $item['reference_type'];
        $referenceId = $item['reference_id'];
        $operationType = $item['operation_type'];
        $syncData = json_decode($item['sync_data'], true);
        
        $this->log("Processing {$referenceType} {$operationType} for ID {$referenceId} (Operation: {$operationId})", 'DEBUG');
        
        try {
            $journalEntryId = null;
            
            // Process based on reference type and operation
            switch ($referenceType) {
                case 'CONSULTATION':
                    $journalEntryId = $this->processConsultationSync($syncData, $operationType);
                    break;
                    
                case 'PHARMACY':
                    $journalEntryId = $this->processPharmacySync($syncData, $operationType);
                    break;
                    
                case 'LAB':
                    $journalEntryId = $this->processLabSync($syncData, $operationType);
                    break;
                    
                case 'ULTRASOUND':
                    $journalEntryId = $this->processUltrasoundSync($syncData, $operationType);
                    break;
                    
                default:
                    throw new Exception("Unknown reference type: {$referenceType}");
            }
            
            // Mark as successful
            $this->markSyncSuccess($operationId, $journalEntryId);
            $this->stats['succeeded']++;
            
            $this->log("Successfully processed {$referenceType} {$operationType} for ID {$referenceId}", 'INFO');
            
        } catch (Exception $e) {
            $this->handleSyncFailure($operationId, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Process consultation synchronization
     */
    private function processConsultationSync($syncData, $operationType) {
        if ($operationType === 'DELETE') {
            // Handle deletion - reverse the journal entry
            return $this->reverseJournalEntry('CONSULTATION', $syncData['id']);
        }
        
        // Check if already synced to avoid duplicates
        if ($this->isAlreadySynced('CONSULTATION', $syncData['id'])) {
            $this->log("Consultation {$syncData['id']} already synced, skipping", 'DEBUG');
            return $this->getExistingJournalEntryId('CONSULTATION', $syncData['id']);
        }
        
        // Get doctor info for the consultation
        $doctorId = $this->getDoctorIdForConsultation($syncData);
        
        return $this->accounting->recordConsultationRevenue(
            $syncData['patient_id'],
            $doctorId,
            $syncData['amount'],
            strtolower($syncData['mode']),
            date('Y-m-d', strtotime($syncData['created_at']))
        );
    }
    
    /**
     * Process pharmacy synchronization
     */
    private function processPharmacySync($syncData, $operationType) {
        if ($operationType === 'DELETE') {
            return $this->reverseJournalEntry('PHARMACY', $syncData['id']);
        }
        
        if ($this->isAlreadySynced('PHARMACY', $syncData['id'])) {
            $this->log("Pharmacy bill {$syncData['id']} already synced, skipping", 'DEBUG');
            return $this->getExistingJournalEntryId('PHARMACY', $syncData['id']);
        }
        
        // Estimate COGS if not provided
        $estimatedCogs = $syncData['estimated_cogs'] ?? round($syncData['discounted_total'] * 0.7, 2);
        
        return $this->accounting->recordPharmacySale(
            $syncData['id'],
            $syncData['discounted_total'],
            $syncData['gst_amount'],
            $estimatedCogs,
            'cash', // Default to cash
            date('Y-m-d', strtotime($syncData['created_at']))
        );
    }
    
    /**
     * Process lab synchronization
     */
    private function processLabSync($syncData, $operationType) {
        if ($operationType === 'DELETE') {
            return $this->reverseJournalEntry('LAB', $syncData['id']);
        }
        
        if ($this->isAlreadySynced('LAB', $syncData['id'])) {
            $this->log("Lab bill {$syncData['id']} already synced, skipping", 'DEBUG');
            return $this->getExistingJournalEntryId('LAB', $syncData['id']);
        }
        
        return $this->accounting->recordLabRevenue(
            $syncData['id'],
            $syncData['discounted_amount'],
            'cash', // Default to cash
            date('Y-m-d', strtotime($syncData['created_at']))
        );
    }
    
    /**
     * Process ultrasound synchronization
     */
    private function processUltrasoundSync($syncData, $operationType) {
        if ($operationType === 'DELETE') {
            return $this->reverseJournalEntry('ULTRASOUND', $syncData['id']);
        }
        
        if ($this->isAlreadySynced('ULTRASOUND', $syncData['id'])) {
            $this->log("Ultrasound bill {$syncData['id']} already synced, skipping", 'DEBUG');
            return $this->getExistingJournalEntryId('ULTRASOUND', $syncData['id']);
        }
        
        return $this->accounting->recordUltrasoundRevenue(
            $syncData['id'],
            $syncData['discounted_total'],
            'cash', // Default to cash
            date('Y-m-d', strtotime($syncData['created_at']))
        );
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
     * Get doctor ID for consultation
     */
    private function getDoctorIdForConsultation($syncData) {
        if (isset($syncData['visit_id']) && $syncData['visit_id']) {
            $stmt = $this->pdo->prepare("SELECT doctor_id FROM visits WHERE id = ?");
            $stmt->execute([$syncData['visit_id']]);
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
        $stmt->execute([$syncData['patient_id']]);
        return $stmt->fetchColumn() ?: 0;
    }
    
    /**
     * Reverse a journal entry for deletions
     */
    private function reverseJournalEntry($referenceType, $referenceId) {
        $stmt = $this->pdo->prepare("
            UPDATE journal_entries 
            SET status = 'REVERSED' 
            WHERE reference_type = ? AND reference_id = ? AND status = 'POSTED'
        ");
        $stmt->execute([$referenceType, $referenceId]);
        
        // Create reversing entry
        // This is a simplified implementation - in practice, you might want more sophisticated reversal logic
        return null;
    }
    
    /**
     * Mark sync operation as successful
     */
    private function markSyncSuccess($operationId, $journalEntryId) {
        $stmt = $this->pdo->prepare("CALL MarkSyncSuccess(?, ?)");
        $stmt->execute([$operationId, $journalEntryId]);
    }
    
    /**
     * Handle sync failure with intelligent retry
     */
    private function handleSyncFailure($operationId, $errorMessage, $stackTrace = null) {
        $this->stats['failed']++;
        
        try {
            $stmt = $this->pdo->prepare("CALL MarkSyncFailed(?, ?, ?)");
            $stmt->execute([$operationId, $errorMessage, $stackTrace]);
            
            // Check if this should trigger an alert
            $stmt = $this->pdo->prepare("
                SELECT retry_count, max_retries FROM sync_queue WHERE operation_id = ?
            ");
            $stmt->execute([$operationId]);
            $queueItem = $stmt->fetch();
            
            if ($queueItem && $queueItem['retry_count'] >= $queueItem['max_retries']) {
                $this->stats['abandoned']++;
                $this->createAlert('SYNC_FAILURES', 'HIGH', 
                    "Sync operation abandoned after {$queueItem['max_retries']} retries", [
                        'operation_id' => $operationId,
                        'error' => $errorMessage
                    ]);
            } else {
                $this->stats['retried']++;
            }
            
        } catch (Exception $e) {
            $this->log("Error handling sync failure: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Update system health metrics
     */
    private function updateSystemHealth() {
        try {
            $queueSize = $this->getQueueSize();
            $failedSyncs = $this->getFailedSyncsCount();
            $avgProcessingTime = $this->calculateAverageProcessingTime();
            
            $status = 'HEALTHY';
            if ($failedSyncs >= $this->config['alert_threshold_failed_syncs']) {
                $status = 'CRITICAL';
            } elseif ($queueSize >= $this->config['alert_threshold_queue_size']) {
                $status = 'WARNING';
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_system_health 
                (queue_size, failed_syncs_count, avg_processing_time_ms, last_successful_sync, system_status, details)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
            ");
            $stmt->execute([
                $queueSize,
                $failedSyncs,
                $avgProcessingTime,
                $status,
                json_encode($this->stats)
            ]);
            
        } catch (Exception $e) {
            $this->log("Error updating system health: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Get current queue size
     */
    private function getQueueSize() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM sync_queue WHERE processed_at IS NULL");
        return $stmt->fetchColumn();
    }
    
    /**
     * Get failed syncs count
     */
    private function getFailedSyncsCount() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM sync_queue 
            WHERE retry_count >= max_retries AND processed_at IS NULL
        ");
        return $stmt->fetchColumn();
    }
    
    /**
     * Calculate average processing time
     */
    private function calculateAverageProcessingTime() {
        $stmt = $this->pdo->query("
            SELECT AVG(TIMESTAMPDIFF(MICROSECOND, created_at, processed_at)) / 1000 as avg_ms
            FROM sync_queue 
            WHERE processed_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
            AND processed_at IS NOT NULL
        ");
        $result = $stmt->fetchColumn();
        return $result ? round($result) : 0;
    }
    
    /**
     * Create system alert
     */
    private function createAlert($alertType, $severity, $message, $details = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_alerts (alert_type, severity, message, details)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $alertType,
                $severity,
                $message,
                $details ? json_encode($details) : null
            ]);
        } catch (Exception $e) {
            $this->log("Error creating alert: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Log message with appropriate level
     */
    private function log($message, $level = 'INFO') {
        if (!$this->config['enable_detailed_logging'] && $level === 'DEBUG') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] [{$this->processId}] {$message}";
        
        // Log to error log
        error_log($logMessage);
        
        // Also log to database for monitoring
        if (in_array($level, ['ERROR', 'CRITICAL'])) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO sync_audit_log (operation_id, reference_type, reference_id, operation_type, sync_status, error_message)
                    VALUES (?, 'SYSTEM', 0, 'LOG', 'FAILED', ?)
                ");
                $stmt->execute([uniqid('log_', true), $logMessage]);
            } catch (Exception $e) {
                // Don't fail if we can't log to database
            }
        }
    }
    
    /**
     * Handle system signals
     */
    public function handleSignal($signal) {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->log("Received shutdown signal, stopping gracefully", 'INFO');
                $this->isRunning = false;
                break;
        }
    }
    
    /**
     * Shutdown cleanup
     */
    public function shutdown() {
        if ($this->isRunning) {
            $this->log("Performing shutdown cleanup", 'INFO');
            
            // Release any locked items
            try {
                $stmt = $this->pdo->prepare("
                    UPDATE sync_queue 
                    SET locked_at = NULL, locked_by = NULL 
                    WHERE locked_by = ?
                ");
                $stmt->execute([$this->processId]);
            } catch (Exception $e) {
                $this->log("Error during shutdown cleanup: " . $e->getMessage(), 'ERROR');
            }
        }
        
        $runtime = microtime(true) - $this->stats['start_time'];
        $this->log(sprintf(
            "Sync processor shutdown complete. Runtime: %.2fs, Processed: %d, Succeeded: %d, Failed: %d",
            $runtime,
            $this->stats['processed'],
            $this->stats['succeeded'],
            $this->stats['failed']
        ), 'INFO');
    }
    
    /**
     * Get current statistics
     */
    public function getStats() {
        $this->stats['runtime'] = microtime(true) - $this->stats['start_time'];
        return $this->stats;
    }
}

// Command line interface for running the sync processor
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $options = getopt('h', ['help', 'iterations:', 'runtime:', 'daemon']);
    
    if (isset($options['h']) || isset($options['help'])) {
        echo "Sync Processor Usage:\n";
        echo "  php sync_processor.php [options]\n\n";
        echo "Options:\n";
        echo "  --iterations=N    Run for N iterations then stop\n";
        echo "  --runtime=N       Run for N seconds then stop\n";
        echo "  --daemon          Run continuously as daemon\n";
        echo "  -h, --help        Show this help message\n";
        exit(0);
    }
    
    try {
        $processor = new SyncProcessor($pdo);
        
        $maxIterations = isset($options['iterations']) ? (int)$options['iterations'] : null;
        $maxRuntime = isset($options['runtime']) ? (int)$options['runtime'] : null;
        
        if (isset($options['daemon'])) {
            // Run as daemon (continuous)
            $processor->run();
        } else {
            // Run with limits
            $processor->run($maxIterations, $maxRuntime);
        }
        
        $stats = $processor->getStats();
        echo "Processing complete. Stats: " . json_encode($stats, JSON_PRETTY_PRINT) . "\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>