-- Comprehensive Auto-Synchronization System Schema
-- This creates a bulletproof system that ensures no accounting entry is ever missed

-- ============================================================================
-- AUDIT AND TRACKING TABLES
-- ============================================================================

-- Comprehensive audit log for all operations
CREATE TABLE IF NOT EXISTS sync_audit_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    operation_id VARCHAR(36) NOT NULL, -- UUID for tracking related operations
    reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND') NOT NULL,
    reference_id INT NOT NULL,
    operation_type ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    sync_status ENUM('PENDING', 'SUCCESS', 'FAILED', 'RETRY', 'ABANDONED') NOT NULL DEFAULT 'PENDING',
    attempt_number INT DEFAULT 1,
    journal_entry_id INT NULL,
    error_message TEXT NULL,
    stack_trace TEXT NULL,
    sync_data JSON NULL, -- Store the data being synced for retry purposes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    next_retry_at TIMESTAMP NULL,
    INDEX idx_sync_audit_status (sync_status, next_retry_at),
    INDEX idx_sync_audit_ref (reference_type, reference_id),
    INDEX idx_sync_audit_operation (operation_id)
);

-- Real-time sync queue for immediate processing
CREATE TABLE IF NOT EXISTS sync_queue (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    operation_id VARCHAR(36) NOT NULL UNIQUE,
    reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND') NOT NULL,
    reference_id INT NOT NULL,
    operation_type ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    priority TINYINT DEFAULT 5, -- 1=highest, 10=lowest
    sync_data JSON NOT NULL,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_at TIMESTAMP NULL,
    locked_by VARCHAR(100) NULL,
    processed_at TIMESTAMP NULL,
    INDEX idx_sync_queue_scheduled (scheduled_at, processed_at),
    INDEX idx_sync_queue_priority (priority, scheduled_at),
    INDEX idx_sync_queue_ref (reference_type, reference_id)
);

-- Master sync status tracking
CREATE TABLE IF NOT EXISTS sync_master_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND') NOT NULL,
    reference_id INT NOT NULL,
    is_synced BOOLEAN DEFAULT FALSE,
    journal_entry_id INT NULL,
    first_sync_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_sync_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    successful_sync_at TIMESTAMP NULL,
    sync_attempts INT DEFAULT 0,
    sync_failures INT DEFAULT 0,
    last_error TEXT NULL,
    checksum VARCHAR(64) NULL, -- MD5 of sync data to detect changes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_master_sync (reference_type, reference_id),
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
    INDEX idx_master_sync_unsynced (is_synced, last_sync_attempt),
    INDEX idx_master_sync_failed (sync_failures, last_sync_attempt)
);

-- Periodic reconciliation log
CREATE TABLE IF NOT EXISTS sync_reconciliation_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reconciliation_type ENUM('HOURLY', 'DAILY', 'WEEKLY', 'MANUAL') NOT NULL,
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP NULL,
    records_checked INT DEFAULT 0,
    missing_entries_found INT DEFAULT 0,
    sync_corrections_made INT DEFAULT 0,
    errors_encountered INT DEFAULT 0,
    status ENUM('RUNNING', 'COMPLETED', 'FAILED') DEFAULT 'RUNNING',
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- OPERATIONAL DATA CHANGE TRACKING
-- ============================================================================

-- Track all changes to operational tables for sync purposes
CREATE TABLE IF NOT EXISTS operational_change_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    table_name VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    operation_type ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_data JSON NULL,
    new_data JSON NULL,
    changed_columns JSON NULL,
    sync_required BOOLEAN DEFAULT TRUE,
    sync_processed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_change_log_sync (sync_required, sync_processed),
    INDEX idx_change_log_table (table_name, record_id),
    INDEX idx_change_log_created (created_at)
);

-- ============================================================================
-- SYSTEM MONITORING AND ALERTS
-- ============================================================================

-- System health monitoring
CREATE TABLE IF NOT EXISTS sync_system_health (
    id INT PRIMARY KEY AUTO_INCREMENT,
    check_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    queue_size INT DEFAULT 0,
    failed_syncs_count INT DEFAULT 0,
    avg_processing_time_ms INT DEFAULT 0,
    last_successful_sync TIMESTAMP NULL,
    system_status ENUM('HEALTHY', 'WARNING', 'CRITICAL') DEFAULT 'HEALTHY',
    alerts_sent INT DEFAULT 0,
    details JSON NULL
);

-- Alert configuration and log
CREATE TABLE IF NOT EXISTS sync_alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alert_type ENUM('QUEUE_BACKUP', 'SYNC_FAILURES', 'SYSTEM_DOWN', 'DATA_INCONSISTENCY') NOT NULL,
    severity ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL,
    message TEXT NOT NULL,
    details JSON NULL,
    triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    notification_sent BOOLEAN DEFAULT FALSE,
    INDEX idx_alerts_unresolved (resolved_at, severity)
);

-- ============================================================================
-- CONFIGURATION AND SETTINGS
-- ============================================================================

-- Sync system configuration
CREATE TABLE IF NOT EXISTS sync_configuration (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    data_type ENUM('STRING', 'INTEGER', 'BOOLEAN', 'JSON') DEFAULT 'STRING',
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) NULL
);

-- Insert default configuration
INSERT IGNORE INTO sync_configuration (config_key, config_value, data_type, description) VALUES
('max_retry_attempts', '5', 'INTEGER', 'Maximum retry attempts for failed syncs'),
('retry_delay_seconds', '60', 'INTEGER', 'Initial delay between retries in seconds'),
('retry_backoff_multiplier', '2', 'INTEGER', 'Exponential backoff multiplier'),
('max_retry_delay_seconds', '3600', 'INTEGER', 'Maximum delay between retries'),
('queue_batch_size', '100', 'INTEGER', 'Number of items to process in each batch'),
('reconciliation_frequency_hours', '1', 'INTEGER', 'How often to run reconciliation'),
('alert_threshold_queue_size', '1000', 'INTEGER', 'Queue size that triggers alerts'),
('alert_threshold_failed_syncs', '10', 'INTEGER', 'Failed sync count that triggers alerts'),
('enable_real_time_sync', 'true', 'BOOLEAN', 'Enable real-time synchronization'),
('enable_background_reconciliation', 'true', 'BOOLEAN', 'Enable background reconciliation'),
('sync_timeout_seconds', '300', 'INTEGER', 'Timeout for sync operations'),
('enable_detailed_logging', 'true', 'BOOLEAN', 'Enable detailed sync logging');

-- ============================================================================
-- STORED PROCEDURES FOR SYNC OPERATIONS
-- ============================================================================

DELIMITER //

-- Procedure to add item to sync queue
CREATE PROCEDURE IF NOT EXISTS AddToSyncQueue(
    IN p_reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND'),
    IN p_reference_id INT,
    IN p_operation_type ENUM('INSERT', 'UPDATE', 'DELETE'),
    IN p_sync_data JSON,
    IN p_priority TINYINT DEFAULT 5
)
BEGIN
    DECLARE v_operation_id VARCHAR(36);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    SET v_operation_id = UUID();
    
    -- Add to sync queue
    INSERT INTO sync_queue (operation_id, reference_type, reference_id, operation_type, sync_data, priority)
    VALUES (v_operation_id, p_reference_type, p_reference_id, p_operation_type, p_sync_data, p_priority)
    ON DUPLICATE KEY UPDATE
        sync_data = p_sync_data,
        retry_count = 0,
        scheduled_at = CURRENT_TIMESTAMP,
        processed_at = NULL,
        locked_at = NULL,
        locked_by = NULL;
    
    -- Log the operation
    INSERT INTO sync_audit_log (operation_id, reference_type, reference_id, operation_type, sync_data)
    VALUES (v_operation_id, p_reference_type, p_reference_id, p_operation_type, p_sync_data);
    
    -- Update master status
    INSERT INTO sync_master_status (reference_type, reference_id, sync_attempts, last_sync_attempt)
    VALUES (p_reference_type, p_reference_id, 1, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
        sync_attempts = sync_attempts + 1,
        last_sync_attempt = CURRENT_TIMESTAMP,
        is_synced = FALSE;
    
    COMMIT;
END //

-- Procedure to mark sync as successful
CREATE PROCEDURE IF NOT EXISTS MarkSyncSuccess(
    IN p_operation_id VARCHAR(36),
    IN p_journal_entry_id INT
)
BEGIN
    DECLARE v_reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND');
    DECLARE v_reference_id INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Get reference info
    SELECT reference_type, reference_id INTO v_reference_type, v_reference_id
    FROM sync_queue WHERE operation_id = p_operation_id;
    
    -- Update audit log
    UPDATE sync_audit_log 
    SET sync_status = 'SUCCESS', 
        journal_entry_id = p_journal_entry_id,
        processed_at = CURRENT_TIMESTAMP
    WHERE operation_id = p_operation_id;
    
    -- Mark queue item as processed
    UPDATE sync_queue 
    SET processed_at = CURRENT_TIMESTAMP
    WHERE operation_id = p_operation_id;
    
    -- Update master status
    UPDATE sync_master_status 
    SET is_synced = TRUE,
        journal_entry_id = p_journal_entry_id,
        successful_sync_at = CURRENT_TIMESTAMP,
        last_error = NULL
    WHERE reference_type = v_reference_type AND reference_id = v_reference_id;
    
    COMMIT;
END //

-- Procedure to mark sync as failed
CREATE PROCEDURE IF NOT EXISTS MarkSyncFailed(
    IN p_operation_id VARCHAR(36),
    IN p_error_message TEXT,
    IN p_stack_trace TEXT DEFAULT NULL
)
BEGIN
    DECLARE v_reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND');
    DECLARE v_reference_id INT;
    DECLARE v_retry_count INT;
    DECLARE v_max_retries INT;
    DECLARE v_next_retry TIMESTAMP;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Get current retry info
    SELECT reference_type, reference_id, retry_count, max_retries 
    INTO v_reference_type, v_reference_id, v_retry_count, v_max_retries
    FROM sync_queue WHERE operation_id = p_operation_id;
    
    SET v_retry_count = v_retry_count + 1;
    
    -- Calculate next retry time with exponential backoff
    SET v_next_retry = DATE_ADD(CURRENT_TIMESTAMP, 
        INTERVAL LEAST(POW(2, v_retry_count) * 60, 3600) SECOND);
    
    -- Update audit log
    UPDATE sync_audit_log 
    SET sync_status = IF(v_retry_count >= v_max_retries, 'ABANDONED', 'RETRY'),
        error_message = p_error_message,
        stack_trace = p_stack_trace,
        next_retry_at = IF(v_retry_count < v_max_retries, v_next_retry, NULL),
        processed_at = CURRENT_TIMESTAMP,
        attempt_number = v_retry_count
    WHERE operation_id = p_operation_id;
    
    -- Update queue for retry or mark as abandoned
    IF v_retry_count < v_max_retries THEN
        UPDATE sync_queue 
        SET retry_count = v_retry_count,
            scheduled_at = v_next_retry,
            locked_at = NULL,
            locked_by = NULL
        WHERE operation_id = p_operation_id;
    ELSE
        UPDATE sync_queue 
        SET processed_at = CURRENT_TIMESTAMP,
            retry_count = v_retry_count
        WHERE operation_id = p_operation_id;
    END IF;
    
    -- Update master status
    UPDATE sync_master_status 
    SET sync_failures = sync_failures + 1,
        last_error = p_error_message,
        last_sync_attempt = CURRENT_TIMESTAMP
    WHERE reference_type = v_reference_type AND reference_id = v_reference_id;
    
    COMMIT;
END //

DELIMITER ;

-- ============================================================================
-- VIEWS FOR MONITORING
-- ============================================================================

-- View for sync dashboard
CREATE OR REPLACE VIEW sync_dashboard_view AS
SELECT 
    'PENDING' as status,
    COUNT(*) as count,
    MIN(created_at) as oldest_entry,
    AVG(TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP)) as avg_age_seconds
FROM sync_queue 
WHERE processed_at IS NULL
UNION ALL
SELECT 
    'FAILED' as status,
    COUNT(*) as count,
    MIN(created_at) as oldest_entry,
    AVG(retry_count) as avg_retries
FROM sync_queue 
WHERE retry_count >= max_retries AND processed_at IS NULL
UNION ALL
SELECT 
    'SUCCESS_TODAY' as status,
    COUNT(*) as count,
    MIN(processed_at) as oldest_entry,
    AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_processing_time_seconds
FROM sync_queue 
WHERE processed_at >= CURDATE() AND processed_at IS NOT NULL;

-- View for unsynced records
CREATE OR REPLACE VIEW unsynced_records_view AS
SELECT 
    reference_type,
    reference_id,
    sync_attempts,
    sync_failures,
    last_sync_attempt,
    last_error,
    TIMESTAMPDIFF(MINUTE, last_sync_attempt, CURRENT_TIMESTAMP) as minutes_since_last_attempt
FROM sync_master_status 
WHERE is_synced = FALSE
ORDER BY sync_failures DESC, last_sync_attempt ASC;

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- Additional performance indexes
CREATE INDEX IF NOT EXISTS idx_sync_audit_retry ON sync_audit_log (sync_status, next_retry_at);
CREATE INDEX IF NOT EXISTS idx_sync_queue_processing ON sync_queue (scheduled_at, processed_at, locked_at);
CREATE INDEX IF NOT EXISTS idx_operational_change_sync ON operational_change_log (sync_required, sync_processed, created_at);
CREATE INDEX IF NOT EXISTS idx_master_status_health ON sync_master_status (is_synced, sync_failures, last_sync_attempt);