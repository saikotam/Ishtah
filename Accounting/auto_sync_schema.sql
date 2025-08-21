-- Auto Synchronization System Schema
-- This schema adds tables and triggers for automatic accounting synchronization

-- Table to track failed synchronization attempts
CREATE TABLE IF NOT EXISTS sync_failures (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND') NOT NULL,
    reference_id INT NOT NULL,
    operation_type ENUM('CREATE', 'UPDATE', 'DELETE') NOT NULL,
    failure_reason TEXT,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    UNIQUE KEY unique_sync_failure (reference_type, reference_id, operation_type)
);

-- Table to track synchronization status for each operational record
CREATE TABLE IF NOT EXISTS sync_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND') NOT NULL,
    reference_id INT NOT NULL,
    is_synced BOOLEAN DEFAULT FALSE,
    journal_entry_id INT NULL,
    last_sync_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sync_attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_sync_status (reference_type, reference_id),
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
);

-- Create triggers for automatic synchronization
-- Note: Triggers will be created programmatically to handle the accounting sync

-- Index for performance
CREATE INDEX idx_sync_failures_retry ON sync_failures (next_retry_at, resolved_at);
CREATE INDEX idx_sync_status_unsynced ON sync_status (is_synced, reference_type);