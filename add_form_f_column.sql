-- Add Form F column to ultrasound_scans table
ALTER TABLE ultrasound_scans ADD COLUMN is_form_f_needed BOOLEAN DEFAULT FALSE AFTER description;
