-- Database Triggers for Automatic Synchronization
-- These triggers ensure EVERY operational change is captured and queued for sync

DELIMITER //

-- ============================================================================
-- CONSULTATION INVOICES TRIGGERS
-- ============================================================================

DROP TRIGGER IF EXISTS consultation_invoices_after_insert //
CREATE TRIGGER consultation_invoices_after_insert
    AFTER INSERT ON consultation_invoices
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        -- Log the error but don't fail the original operation
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for consultation_invoices INSERT, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'consultation_invoices', 'operation', 'INSERT', 'record_id', NEW.id));
    END;
    
    SET v_sync_data = JSON_OBJECT(
        'id', NEW.id,
        'patient_id', NEW.patient_id,
        'visit_id', NEW.visit_id,
        'amount', NEW.amount,
        'mode', NEW.mode,
        'paid', NEW.paid,
        'created_at', NEW.created_at
    );
    
    CALL AddToSyncQueue('CONSULTATION', NEW.id, 'INSERT', v_sync_data, 1);
    
    -- Also log to operational change log
    INSERT INTO operational_change_log (table_name, record_id, operation_type, new_data, sync_required)
    VALUES ('consultation_invoices', NEW.id, 'INSERT', v_sync_data, TRUE);
END //

DROP TRIGGER IF EXISTS consultation_invoices_after_update //
CREATE TRIGGER consultation_invoices_after_update
    AFTER UPDATE ON consultation_invoices
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE v_old_data JSON;
    DECLARE v_changed_columns JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for consultation_invoices UPDATE, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'consultation_invoices', 'operation', 'UPDATE', 'record_id', NEW.id));
    END;
    
    -- Only sync if financial fields changed
    IF (OLD.amount != NEW.amount OR OLD.mode != NEW.mode OR OLD.paid != NEW.paid) THEN
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'patient_id', NEW.patient_id,
            'visit_id', NEW.visit_id,
            'amount', NEW.amount,
            'mode', NEW.mode,
            'paid', NEW.paid,
            'created_at', NEW.created_at
        );
        
        SET v_old_data = JSON_OBJECT(
            'id', OLD.id,
            'amount', OLD.amount,
            'mode', OLD.mode,
            'paid', OLD.paid
        );
        
        SET v_changed_columns = JSON_ARRAY();
        IF OLD.amount != NEW.amount THEN SET v_changed_columns = JSON_ARRAY_APPEND(v_changed_columns, '$', 'amount'); END IF;
        IF OLD.mode != NEW.mode THEN SET v_changed_columns = JSON_ARRAY_APPEND(v_changed_columns, '$', 'mode'); END IF;
        IF OLD.paid != NEW.paid THEN SET v_changed_columns = JSON_ARRAY_APPEND(v_changed_columns, '$', 'paid'); END IF;
        
        CALL AddToSyncQueue('CONSULTATION', NEW.id, 'UPDATE', v_sync_data, 2);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, old_data, new_data, changed_columns, sync_required)
        VALUES ('consultation_invoices', NEW.id, 'UPDATE', v_old_data, v_sync_data, v_changed_columns, TRUE);
    END IF;
END //

-- ============================================================================
-- PHARMACY BILLS TRIGGERS
-- ============================================================================

DROP TRIGGER IF EXISTS pharmacy_bills_after_insert //
CREATE TRIGGER pharmacy_bills_after_insert
    AFTER INSERT ON pharmacy_bills
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for pharmacy_bills INSERT, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'pharmacy_bills', 'operation', 'INSERT', 'record_id', NEW.id));
    END;
    
    -- Only sync if invoice_number is set (bill is finalized)
    IF NEW.invoice_number IS NOT NULL THEN
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'visit_id', NEW.visit_id,
            'total_amount', NEW.total_amount,
            'gst_amount', NEW.gst_amount,
            'discount_type', NEW.discount_type,
            'discount_value', NEW.discount_value,
            'discounted_total', NEW.discounted_total,
            'invoice_number', NEW.invoice_number,
            'created_at', NEW.created_at
        );
        
        CALL AddToSyncQueue('PHARMACY', NEW.id, 'INSERT', v_sync_data, 1);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, new_data, sync_required)
        VALUES ('pharmacy_bills', NEW.id, 'INSERT', v_sync_data, TRUE);
    END IF;
END //

DROP TRIGGER IF EXISTS pharmacy_bills_after_update //
CREATE TRIGGER pharmacy_bills_after_update
    AFTER UPDATE ON pharmacy_bills
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE v_old_data JSON;
    DECLARE v_changed_columns JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for pharmacy_bills UPDATE, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'pharmacy_bills', 'operation', 'UPDATE', 'record_id', NEW.id));
    END;
    
    -- Sync if invoice was just assigned or financial data changed
    IF (OLD.invoice_number IS NULL AND NEW.invoice_number IS NOT NULL) OR
       (NEW.invoice_number IS NOT NULL AND (
           OLD.total_amount != NEW.total_amount OR 
           OLD.gst_amount != NEW.gst_amount OR 
           OLD.discounted_total != NEW.discounted_total OR
           OLD.discount_type != NEW.discount_type OR
           OLD.discount_value != NEW.discount_value
       )) THEN
        
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'visit_id', NEW.visit_id,
            'total_amount', NEW.total_amount,
            'gst_amount', NEW.gst_amount,
            'discount_type', NEW.discount_type,
            'discount_value', NEW.discount_value,
            'discounted_total', NEW.discounted_total,
            'invoice_number', NEW.invoice_number,
            'created_at', NEW.created_at
        );
        
        SET v_old_data = JSON_OBJECT(
            'total_amount', OLD.total_amount,
            'gst_amount', OLD.gst_amount,
            'discounted_total', OLD.discounted_total,
            'invoice_number', OLD.invoice_number
        );
        
        CALL AddToSyncQueue('PHARMACY', NEW.id, 
                           IF(OLD.invoice_number IS NULL, 'INSERT', 'UPDATE'), 
                           v_sync_data, 1);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, old_data, new_data, sync_required)
        VALUES ('pharmacy_bills', NEW.id, 'UPDATE', v_old_data, v_sync_data, TRUE);
    END IF;
END //

-- ============================================================================
-- LAB BILLS TRIGGERS
-- ============================================================================

DROP TRIGGER IF EXISTS lab_bills_after_insert //
CREATE TRIGGER lab_bills_after_insert
    AFTER INSERT ON lab_bills
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for lab_bills INSERT, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'lab_bills', 'operation', 'INSERT', 'record_id', NEW.id));
    END;
    
    IF NEW.invoice_number IS NOT NULL THEN
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'visit_id', NEW.visit_id,
            'amount', NEW.amount,
            'paid', NEW.paid,
            'discount_type', NEW.discount_type,
            'discount_value', NEW.discount_value,
            'discounted_amount', NEW.discounted_amount,
            'invoice_number', NEW.invoice_number,
            'created_at', NEW.created_at
        );
        
        CALL AddToSyncQueue('LAB', NEW.id, 'INSERT', v_sync_data, 1);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, new_data, sync_required)
        VALUES ('lab_bills', NEW.id, 'INSERT', v_sync_data, TRUE);
    END IF;
END //

DROP TRIGGER IF EXISTS lab_bills_after_update //
CREATE TRIGGER lab_bills_after_update
    AFTER UPDATE ON lab_bills
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE v_old_data JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for lab_bills UPDATE, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'lab_bills', 'operation', 'UPDATE', 'record_id', NEW.id));
    END;
    
    IF (OLD.invoice_number IS NULL AND NEW.invoice_number IS NOT NULL) OR
       (NEW.invoice_number IS NOT NULL AND (
           OLD.amount != NEW.amount OR 
           OLD.discounted_amount != NEW.discounted_amount OR
           OLD.paid != NEW.paid OR
           OLD.discount_type != NEW.discount_type OR
           OLD.discount_value != NEW.discount_value
       )) THEN
        
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'visit_id', NEW.visit_id,
            'amount', NEW.amount,
            'paid', NEW.paid,
            'discount_type', NEW.discount_type,
            'discount_value', NEW.discount_value,
            'discounted_amount', NEW.discounted_amount,
            'invoice_number', NEW.invoice_number,
            'created_at', NEW.created_at
        );
        
        SET v_old_data = JSON_OBJECT(
            'amount', OLD.amount,
            'discounted_amount', OLD.discounted_amount,
            'paid', OLD.paid,
            'invoice_number', OLD.invoice_number
        );
        
        CALL AddToSyncQueue('LAB', NEW.id, 
                           IF(OLD.invoice_number IS NULL, 'INSERT', 'UPDATE'), 
                           v_sync_data, 1);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, old_data, new_data, sync_required)
        VALUES ('lab_bills', NEW.id, 'UPDATE', v_old_data, v_sync_data, TRUE);
    END IF;
END //

-- ============================================================================
-- ULTRASOUND BILLS TRIGGERS
-- ============================================================================

DROP TRIGGER IF EXISTS ultrasound_bills_after_insert //
CREATE TRIGGER ultrasound_bills_after_insert
    AFTER INSERT ON ultrasound_bills
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for ultrasound_bills INSERT, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'ultrasound_bills', 'operation', 'INSERT', 'record_id', NEW.id));
    END;
    
    IF NEW.invoice_number IS NOT NULL THEN
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'visit_id', NEW.visit_id,
            'referring_doctor_id', NEW.referring_doctor_id,
            'total_amount', NEW.total_amount,
            'discount_type', NEW.discount_type,
            'discount_value', NEW.discount_value,
            'discounted_total', NEW.discounted_total,
            'invoice_number', NEW.invoice_number,
            'created_at', NEW.created_at
        );
        
        CALL AddToSyncQueue('ULTRASOUND', NEW.id, 'INSERT', v_sync_data, 1);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, new_data, sync_required)
        VALUES ('ultrasound_bills', NEW.id, 'INSERT', v_sync_data, TRUE);
    END IF;
END //

DROP TRIGGER IF EXISTS ultrasound_bills_after_update //
CREATE TRIGGER ultrasound_bills_after_update
    AFTER UPDATE ON ultrasound_bills
    FOR EACH ROW
BEGIN
    DECLARE v_sync_data JSON;
    DECLARE v_old_data JSON;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        INSERT INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Trigger failed for ultrasound_bills UPDATE, ID: ', NEW.id),
                JSON_OBJECT('error', SQLERRM, 'table', 'ultrasound_bills', 'operation', 'UPDATE', 'record_id', NEW.id));
    END;
    
    IF (OLD.invoice_number IS NULL AND NEW.invoice_number IS NOT NULL) OR
       (NEW.invoice_number IS NOT NULL AND (
           OLD.total_amount != NEW.total_amount OR 
           OLD.discounted_total != NEW.discounted_total OR
           OLD.discount_type != NEW.discount_type OR
           OLD.discount_value != NEW.discount_value
       )) THEN
        
        SET v_sync_data = JSON_OBJECT(
            'id', NEW.id,
            'visit_id', NEW.visit_id,
            'referring_doctor_id', NEW.referring_doctor_id,
            'total_amount', NEW.total_amount,
            'discount_type', NEW.discount_type,
            'discount_value', NEW.discount_value,
            'discounted_total', NEW.discounted_total,
            'invoice_number', NEW.invoice_number,
            'created_at', NEW.created_at
        );
        
        SET v_old_data = JSON_OBJECT(
            'total_amount', OLD.total_amount,
            'discounted_total', OLD.discounted_total,
            'invoice_number', OLD.invoice_number
        );
        
        CALL AddToSyncQueue('ULTRASOUND', NEW.id, 
                           IF(OLD.invoice_number IS NULL, 'INSERT', 'UPDATE'), 
                           v_sync_data, 1);
        
        INSERT INTO operational_change_log (table_name, record_id, operation_type, old_data, new_data, sync_required)
        VALUES ('ultrasound_bills', NEW.id, 'UPDATE', v_old_data, v_sync_data, TRUE);
    END IF;
END //

-- ============================================================================
-- SYSTEM HEALTH MONITORING TRIGGER
-- ============================================================================

DROP TRIGGER IF EXISTS sync_queue_health_monitor //
CREATE TRIGGER sync_queue_health_monitor
    AFTER INSERT ON sync_queue
    FOR EACH ROW
BEGIN
    DECLARE v_queue_size INT DEFAULT 0;
    DECLARE v_failed_syncs INT DEFAULT 0;
    DECLARE v_alert_threshold_queue INT DEFAULT 1000;
    DECLARE v_alert_threshold_failed INT DEFAULT 10;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END; -- Don't fail original operation
    
    -- Get current queue size and failed syncs
    SELECT COUNT(*) INTO v_queue_size FROM sync_queue WHERE processed_at IS NULL;
    SELECT COUNT(*) INTO v_failed_syncs FROM sync_queue WHERE retry_count >= max_retries AND processed_at IS NULL;
    
    -- Get alert thresholds
    SELECT CAST(config_value AS UNSIGNED) INTO v_alert_threshold_queue 
    FROM sync_configuration WHERE config_key = 'alert_threshold_queue_size';
    
    SELECT CAST(config_value AS UNSIGNED) INTO v_alert_threshold_failed 
    FROM sync_configuration WHERE config_key = 'alert_threshold_failed_syncs';
    
    -- Insert health record
    INSERT INTO sync_system_health (queue_size, failed_syncs_count, system_status)
    VALUES (v_queue_size, v_failed_syncs,
            CASE 
                WHEN v_failed_syncs >= v_alert_threshold_failed THEN 'CRITICAL'
                WHEN v_queue_size >= v_alert_threshold_queue THEN 'WARNING'
                ELSE 'HEALTHY'
            END);
    
    -- Create alerts if thresholds exceeded
    IF v_queue_size >= v_alert_threshold_queue THEN
        INSERT IGNORE INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('QUEUE_BACKUP', 'MEDIUM', 
                CONCAT('Sync queue size (', v_queue_size, ') exceeds threshold (', v_alert_threshold_queue, ')'),
                JSON_OBJECT('queue_size', v_queue_size, 'threshold', v_alert_threshold_queue));
    END IF;
    
    IF v_failed_syncs >= v_alert_threshold_failed THEN
        INSERT IGNORE INTO sync_alerts (alert_type, severity, message, details)
        VALUES ('SYNC_FAILURES', 'HIGH', 
                CONCAT('Failed sync count (', v_failed_syncs, ') exceeds threshold (', v_alert_threshold_failed, ')'),
                JSON_OBJECT('failed_count', v_failed_syncs, 'threshold', v_alert_threshold_failed));
    END IF;
END //

DELIMITER ;

-- ============================================================================
-- EVENTS FOR AUTOMATIC CLEANUP AND MAINTENANCE
-- ============================================================================

-- Enable event scheduler if not already enabled
SET GLOBAL event_scheduler = ON;

-- Clean up old processed queue items (keep for 30 days)
DROP EVENT IF EXISTS cleanup_processed_sync_queue;
CREATE EVENT cleanup_processed_sync_queue
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM sync_queue 
  WHERE processed_at IS NOT NULL 
  AND processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Clean up old audit logs (keep for 90 days)
DROP EVENT IF EXISTS cleanup_sync_audit_log;
CREATE EVENT cleanup_sync_audit_log
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM sync_audit_log 
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Clean up old operational change logs (keep for 30 days)
DROP EVENT IF EXISTS cleanup_operational_change_log;
CREATE EVENT cleanup_operational_change_log
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM operational_change_log 
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
  AND sync_processed = TRUE;

-- Clean up resolved alerts (keep for 7 days)
DROP EVENT IF EXISTS cleanup_resolved_alerts;
CREATE EVENT cleanup_resolved_alerts
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM sync_alerts 
  WHERE resolved_at IS NOT NULL 
  AND resolved_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Archive old system health records (keep for 30 days)
DROP EVENT IF EXISTS cleanup_system_health;
CREATE EVENT cleanup_system_health
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM sync_system_health 
  WHERE check_time < DATE_SUB(NOW(), INTERVAL 30 DAY);