<?php
/**
 * Sync Service Manager
 * Manages the background sync service and provides fail-safe mechanisms
 * to ensure no accounting entry is ever missed
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/sync_processor.php';

class SyncServiceManager {
    private $pdo;
    private $config;
    private $isRunning = false;
    private $services = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfiguration();
        
        // Register shutdown handler
        register_shutdown_function([$this, 'shutdown']);
        
        // Handle signals
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGUSR1, [$this, 'handleReload']);
        }
    }
    
    /**
     * Load configuration
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
    }
    
    /**
     * Start all sync services
     */
    public function start() {
        $this->isRunning = true;
        $this->log("Starting Sync Service Manager", 'INFO');
        
        // Start main sync processor
        if ($this->config['enable_real_time_sync'] ?? true) {
            $this->startSyncProcessor();
        }
        
        // Start reconciliation service
        if ($this->config['enable_background_reconciliation'] ?? true) {
            $this->startReconciliationService();
        }
        
        // Start monitoring service
        $this->startMonitoringService();
        
        // Main service loop
        while ($this->isRunning) {
            $this->monitorServices();
            sleep(30); // Check every 30 seconds
            
            // Handle signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
    
    /**
     * Start the main sync processor
     */
    private function startSyncProcessor() {
        $this->log("Starting sync processor", 'INFO');
        
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception("Could not fork sync processor");
        } elseif ($pid == 0) {
            // Child process - run sync processor
            try {
                $processor = new SyncProcessor($this->pdo);
                $processor->run(); // Run continuously
            } catch (Exception $e) {
                $this->log("Sync processor error: " . $e->getMessage(), 'ERROR');
                exit(1);
            }
            exit(0);
        } else {
            // Parent process - store PID
            $this->services['sync_processor'] = [
                'pid' => $pid,
                'started' => time(),
                'type' => 'sync_processor'
            ];
        }
    }
    
    /**
     * Start reconciliation service
     */
    private function startReconciliationService() {
        $this->log("Starting reconciliation service", 'INFO');
        
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception("Could not fork reconciliation service");
        } elseif ($pid == 0) {
            // Child process - run reconciliation
            $this->runReconciliationLoop();
            exit(0);
        } else {
            $this->services['reconciliation'] = [
                'pid' => $pid,
                'started' => time(),
                'type' => 'reconciliation'
            ];
        }
    }
    
    /**
     * Start monitoring service
     */
    private function startMonitoringService() {
        $this->log("Starting monitoring service", 'INFO');
        
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception("Could not fork monitoring service");
        } elseif ($pid == 0) {
            // Child process - run monitoring
            $this->runMonitoringLoop();
            exit(0);
        } else {
            $this->services['monitoring'] = [
                'pid' => $pid,
                'started' => time(),
                'type' => 'monitoring'
            ];
        }
    }
    
    /**
     * Monitor all services and restart if needed
     */
    private function monitorServices() {
        foreach ($this->services as $name => $service) {
            // Check if process is still running
            if (!$this->isProcessRunning($service['pid'])) {
                $this->log("Service {$name} (PID: {$service['pid']}) has stopped, restarting", 'WARNING');
                
                // Remove dead service
                unset($this->services[$name]);
                
                // Restart based on type
                switch ($service['type']) {
                    case 'sync_processor':
                        if ($this->config['enable_real_time_sync'] ?? true) {
                            $this->startSyncProcessor();
                        }
                        break;
                    case 'reconciliation':
                        if ($this->config['enable_background_reconciliation'] ?? true) {
                            $this->startReconciliationService();
                        }
                        break;
                    case 'monitoring':
                        $this->startMonitoringService();
                        break;
                }
                
                // Create alert for service restart
                $this->createAlert('SYSTEM_DOWN', 'MEDIUM', 
                    "Service {$name} was restarted", [
                        'service' => $name,
                        'pid' => $service['pid'],
                        'runtime' => time() - $service['started']
                    ]);
            }
        }
    }
    
    /**
     * Check if process is running
     */
    private function isProcessRunning($pid) {
        return file_exists("/proc/{$pid}") || posix_kill($pid, 0);
    }
    
    /**
     * Reconciliation loop - ensures no entries are missed
     */
    private function runReconciliationLoop() {
        $this->log("Reconciliation service started", 'INFO');
        
        while (true) {
            try {
                $this->performReconciliation();
                
                // Sleep for configured interval
                $interval = ($this->config['reconciliation_frequency_hours'] ?? 1) * 3600;
                sleep($interval);
                
            } catch (Exception $e) {
                $this->log("Reconciliation error: " . $e->getMessage(), 'ERROR');
                sleep(300); // Wait 5 minutes before retrying
            }
        }
    }
    
    /**
     * Perform comprehensive reconciliation
     */
    private function performReconciliation() {
        $this->log("Starting reconciliation", 'INFO');
        
        $reconciliationId = $this->startReconciliationLog('HOURLY');
        
        try {
            $stats = [
                'records_checked' => 0,
                'missing_entries_found' => 0,
                'sync_corrections_made' => 0,
                'errors_encountered' => 0
            ];
            
            // Check consultation invoices
            $stats = array_merge_recursive($stats, $this->reconcileConsultationInvoices());
            
            // Check pharmacy bills
            $stats = array_merge_recursive($stats, $this->reconcilePharmacyBills());
            
            // Check lab bills
            $stats = array_merge_recursive($stats, $this->reconcileLabBills());
            
            // Check ultrasound bills
            $stats = array_merge_recursive($stats, $this->reconcileUltrasoundBills());
            
            // Verify data integrity
            $this->verifyDataIntegrity();
            
            // Complete reconciliation
            $this->completeReconciliationLog($reconciliationId, $stats, 'COMPLETED');
            
            $this->log("Reconciliation completed: " . json_encode($stats), 'INFO');
            
        } catch (Exception $e) {
            $this->completeReconciliationLog($reconciliationId, $stats ?? [], 'FAILED');
            throw $e;
        }
    }
    
    /**
     * Reconcile consultation invoices
     */
    private function reconcileConsultationInvoices() {
        $stats = ['records_checked' => 0, 'missing_entries_found' => 0, 'sync_corrections_made' => 0];
        
        // Find consultation invoices without corresponding journal entries
        $stmt = $this->pdo->query("
            SELECT ci.id, ci.patient_id, ci.visit_id, ci.amount, ci.mode, ci.created_at
            FROM consultation_invoices ci
            LEFT JOIN journal_entries je ON je.reference_type = 'CONSULTATION' AND je.reference_id = ci.id
            WHERE ci.amount > 0 AND je.id IS NULL
            ORDER BY ci.created_at DESC
            LIMIT 1000
        ");
        
        while ($row = $stmt->fetch()) {
            $stats['records_checked']++;
            $stats['missing_entries_found']++;
            
            try {
                // Add to sync queue for processing
                $syncData = json_encode([
                    'id' => $row['id'],
                    'patient_id' => $row['patient_id'],
                    'visit_id' => $row['visit_id'],
                    'amount' => $row['amount'],
                    'mode' => $row['mode'],
                    'created_at' => $row['created_at']
                ]);
                
                $stmt2 = $this->pdo->prepare("CALL AddToSyncQueue('CONSULTATION', ?, 'INSERT', ?, 1)");
                $stmt2->execute([$row['id'], $syncData]);
                
                $stats['sync_corrections_made']++;
                
            } catch (Exception $e) {
                $this->log("Error queuing consultation {$row['id']} for sync: " . $e->getMessage(), 'ERROR');
                $stats['errors_encountered']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Reconcile pharmacy bills
     */
    private function reconcilePharmacyBills() {
        $stats = ['records_checked' => 0, 'missing_entries_found' => 0, 'sync_corrections_made' => 0];
        
        $stmt = $this->pdo->query("
            SELECT pb.id, pb.visit_id, pb.total_amount, pb.gst_amount, pb.discount_type, 
                   pb.discount_value, pb.discounted_total, pb.invoice_number, pb.created_at
            FROM pharmacy_bills pb
            LEFT JOIN journal_entries je ON je.reference_type = 'PHARMACY' AND je.reference_id = pb.id
            WHERE pb.invoice_number IS NOT NULL AND pb.discounted_total > 0 AND je.id IS NULL
            ORDER BY pb.created_at DESC
            LIMIT 1000
        ");
        
        while ($row = $stmt->fetch()) {
            $stats['records_checked']++;
            $stats['missing_entries_found']++;
            
            try {
                $syncData = json_encode($row);
                
                $stmt2 = $this->pdo->prepare("CALL AddToSyncQueue('PHARMACY', ?, 'INSERT', ?, 1)");
                $stmt2->execute([$row['id'], $syncData]);
                
                $stats['sync_corrections_made']++;
                
            } catch (Exception $e) {
                $this->log("Error queuing pharmacy bill {$row['id']} for sync: " . $e->getMessage(), 'ERROR');
                $stats['errors_encountered']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Reconcile lab bills
     */
    private function reconcileLabBills() {
        $stats = ['records_checked' => 0, 'missing_entries_found' => 0, 'sync_corrections_made' => 0];
        
        $stmt = $this->pdo->query("
            SELECT lb.id, lb.visit_id, lb.amount, lb.paid, lb.discount_type, 
                   lb.discount_value, lb.discounted_amount, lb.invoice_number, lb.created_at
            FROM lab_bills lb
            LEFT JOIN journal_entries je ON je.reference_type = 'LAB' AND je.reference_id = lb.id
            WHERE lb.invoice_number IS NOT NULL AND lb.discounted_amount > 0 AND je.id IS NULL
            ORDER BY lb.created_at DESC
            LIMIT 1000
        ");
        
        while ($row = $stmt->fetch()) {
            $stats['records_checked']++;
            $stats['missing_entries_found']++;
            
            try {
                $syncData = json_encode($row);
                
                $stmt2 = $this->pdo->prepare("CALL AddToSyncQueue('LAB', ?, 'INSERT', ?, 1)");
                $stmt2->execute([$row['id'], $syncData]);
                
                $stats['sync_corrections_made']++;
                
            } catch (Exception $e) {
                $this->log("Error queuing lab bill {$row['id']} for sync: " . $e->getMessage(), 'ERROR');
                $stats['errors_encountered']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Reconcile ultrasound bills
     */
    private function reconcileUltrasoundBills() {
        $stats = ['records_checked' => 0, 'missing_entries_found' => 0, 'sync_corrections_made' => 0];
        
        $stmt = $this->pdo->query("
            SELECT ub.id, ub.visit_id, ub.referring_doctor_id, ub.total_amount, 
                   ub.discount_type, ub.discount_value, ub.discounted_total, 
                   ub.invoice_number, ub.created_at
            FROM ultrasound_bills ub
            LEFT JOIN journal_entries je ON je.reference_type = 'ULTRASOUND' AND je.reference_id = ub.id
            WHERE ub.invoice_number IS NOT NULL AND ub.discounted_total > 0 AND je.id IS NULL
            ORDER BY ub.created_at DESC
            LIMIT 1000
        ");
        
        while ($row = $stmt->fetch()) {
            $stats['records_checked']++;
            $stats['missing_entries_found']++;
            
            try {
                $syncData = json_encode($row);
                
                $stmt2 = $this->pdo->prepare("CALL AddToSyncQueue('ULTRASOUND', ?, 'INSERT', ?, 1)");
                $stmt2->execute([$row['id'], $syncData]);
                
                $stats['sync_corrections_made']++;
                
            } catch (Exception $e) {
                $this->log("Error queuing ultrasound bill {$row['id']} for sync: " . $e->getMessage(), 'ERROR');
                $stats['errors_encountered']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Verify data integrity
     */
    private function verifyDataIntegrity() {
        // Check for orphaned journal entries
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as orphaned_count
            FROM journal_entries je
            LEFT JOIN consultation_invoices ci ON je.reference_type = 'CONSULTATION' AND je.reference_id = ci.id
            LEFT JOIN pharmacy_bills pb ON je.reference_type = 'PHARMACY' AND je.reference_id = pb.id
            LEFT JOIN lab_bills lb ON je.reference_type = 'LAB' AND je.reference_id = lb.id
            LEFT JOIN ultrasound_bills ub ON je.reference_type = 'ULTRASOUND' AND je.reference_id = ub.id
            WHERE je.reference_type IN ('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND')
            AND ci.id IS NULL AND pb.id IS NULL AND lb.id IS NULL AND ub.id IS NULL
        ");
        
        $orphanedCount = $stmt->fetchColumn();
        if ($orphanedCount > 0) {
            $this->createAlert('DATA_INCONSISTENCY', 'MEDIUM', 
                "Found {$orphanedCount} orphaned journal entries", [
                    'orphaned_count' => $orphanedCount
                ]);
        }
        
        // Check for unbalanced journal entries
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as unbalanced_count
            FROM journal_entries 
            WHERE ABS(total_debit - total_credit) > 0.01
        ");
        
        $unbalancedCount = $stmt->fetchColumn();
        if ($unbalancedCount > 0) {
            $this->createAlert('DATA_INCONSISTENCY', 'HIGH', 
                "Found {$unbalancedCount} unbalanced journal entries", [
                    'unbalanced_count' => $unbalancedCount
                ]);
        }
    }
    
    /**
     * Monitoring loop
     */
    private function runMonitoringLoop() {
        $this->log("Monitoring service started", 'INFO');
        
        while (true) {
            try {
                $this->performHealthCheck();
                $this->processAlerts();
                
                sleep(60); // Check every minute
                
            } catch (Exception $e) {
                $this->log("Monitoring error: " . $e->getMessage(), 'ERROR');
                sleep(60);
            }
        }
    }
    
    /**
     * Perform health check
     */
    private function performHealthCheck() {
        $queueSize = $this->getQueueSize();
        $failedSyncs = $this->getFailedSyncsCount();
        $oldestPendingMinutes = $this->getOldestPendingAge();
        
        // Check queue backup
        if ($queueSize >= ($this->config['alert_threshold_queue_size'] ?? 1000)) {
            $this->createAlert('QUEUE_BACKUP', 'HIGH', 
                "Sync queue backup: {$queueSize} items pending", [
                    'queue_size' => $queueSize
                ]);
        }
        
        // Check failed syncs
        if ($failedSyncs >= ($this->config['alert_threshold_failed_syncs'] ?? 10)) {
            $this->createAlert('SYNC_FAILURES', 'CRITICAL', 
                "High number of failed syncs: {$failedSyncs}", [
                    'failed_count' => $failedSyncs
                ]);
        }
        
        // Check for stale queue items
        if ($oldestPendingMinutes > 60) {
            $this->createAlert('QUEUE_BACKUP', 'MEDIUM', 
                "Oldest pending sync is {$oldestPendingMinutes} minutes old", [
                    'oldest_age_minutes' => $oldestPendingMinutes
                ]);
        }
    }
    
    /**
     * Get queue size
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
     * Get oldest pending age in minutes
     */
    private function getOldestPendingAge() {
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(MINUTE, MIN(created_at), CURRENT_TIMESTAMP) 
            FROM sync_queue 
            WHERE processed_at IS NULL
        ");
        return $stmt->fetchColumn() ?: 0;
    }
    
    /**
     * Process and send alerts
     */
    private function processAlerts() {
        // Get unprocessed alerts
        $stmt = $this->pdo->query("
            SELECT id, alert_type, severity, message, details, triggered_at
            FROM sync_alerts 
            WHERE notification_sent = FALSE 
            AND resolved_at IS NULL
            ORDER BY severity DESC, triggered_at ASC
            LIMIT 10
        ");
        
        while ($alert = $stmt->fetch()) {
            try {
                $this->sendAlert($alert);
                
                // Mark as sent
                $stmt2 = $this->pdo->prepare("
                    UPDATE sync_alerts 
                    SET notification_sent = TRUE 
                    WHERE id = ?
                ");
                $stmt2->execute([$alert['id']]);
                
            } catch (Exception $e) {
                $this->log("Error sending alert {$alert['id']}: " . $e->getMessage(), 'ERROR');
            }
        }
    }
    
    /**
     * Send alert (placeholder - implement your notification method)
     */
    private function sendAlert($alert) {
        // This is where you would implement your notification system
        // Examples: email, SMS, Slack, webhook, etc.
        
        $message = "[{$alert['severity']}] {$alert['alert_type']}: {$alert['message']}";
        $this->log("ALERT: " . $message, 'WARNING');
        
        // You could add email sending, webhook calls, etc. here
    }
    
    /**
     * Start reconciliation log
     */
    private function startReconciliationLog($type) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sync_reconciliation_log (reconciliation_type, status)
            VALUES (?, 'RUNNING')
        ");
        $stmt->execute([$type]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Complete reconciliation log
     */
    private function completeReconciliationLog($id, $stats, $status) {
        $stmt = $this->pdo->prepare("
            UPDATE sync_reconciliation_log 
            SET end_time = CURRENT_TIMESTAMP,
                records_checked = ?,
                missing_entries_found = ?,
                sync_corrections_made = ?,
                errors_encountered = ?,
                status = ?,
                details = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $stats['records_checked'],
            $stats['missing_entries_found'],
            $stats['sync_corrections_made'],
            $stats['errors_encountered'],
            $status,
            json_encode($stats),
            $id
        ]);
    }
    
    /**
     * Create alert
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
            $this->log("Error creating alert: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [SERVICE_MANAGER] [{$level}] {$message}";
        error_log($logMessage);
    }
    
    /**
     * Handle signals
     */
    public function handleSignal($signal) {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->log("Received shutdown signal", 'INFO');
                $this->isRunning = false;
                break;
        }
    }
    
    /**
     * Handle reload signal
     */
    public function handleReload($signal) {
        $this->log("Received reload signal, reloading configuration", 'INFO');
        $this->loadConfiguration();
    }
    
    /**
     * Shutdown cleanup
     */
    public function shutdown() {
        $this->log("Shutting down service manager", 'INFO');
        
        // Stop all child processes
        foreach ($this->services as $name => $service) {
            $this->log("Stopping service {$name} (PID: {$service['pid']})", 'INFO');
            posix_kill($service['pid'], SIGTERM);
            
            // Wait for graceful shutdown
            $timeout = 10;
            while ($timeout > 0 && $this->isProcessRunning($service['pid'])) {
                sleep(1);
                $timeout--;
            }
            
            // Force kill if still running
            if ($this->isProcessRunning($service['pid'])) {
                $this->log("Force killing service {$name}", 'WARNING');
                posix_kill($service['pid'], SIGKILL);
            }
        }
        
        $this->log("Service manager shutdown complete", 'INFO');
    }
}

// Command line interface
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    try {
        $manager = new SyncServiceManager($pdo);
        $manager->start();
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>