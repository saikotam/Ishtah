-- GST Input Credit Management Schema
-- This extends the existing accounting system to handle purchase invoices and GST input credits

-- Purchase Invoices - Master table for all purchase invoices
CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) NOT NULL,
    invoice_date DATE NOT NULL,
    supplier_name VARCHAR(200) NOT NULL,
    supplier_gstin VARCHAR(15), -- GST Identification Number
    supplier_address TEXT,
    supplier_state VARCHAR(50),
    
    -- Invoice totals
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_percent DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    spot_discount_percent DECIMAL(5,2) DEFAULT 0.00, -- Additional discount for spot payment
    spot_discount_amount DECIMAL(12,2) DEFAULT 0.00,
    
    -- GST breakdown
    cgst_amount DECIMAL(12,2) DEFAULT 0.00,
    sgst_amount DECIMAL(12,2) DEFAULT 0.00,
    igst_amount DECIMAL(12,2) DEFAULT 0.00,
    total_gst DECIMAL(12,2) DEFAULT 0.00,
    
    -- Final amounts
    total_amount DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) DEFAULT 0.00,
    balance_amount DECIMAL(12,2) DEFAULT 0.00,
    
    -- GST eligibility and tracking
    is_gst_eligible BOOLEAN DEFAULT TRUE,
    gst_input_claimed BOOLEAN DEFAULT FALSE,
    gst_input_claim_date DATE,
    
    -- Payment and status
    payment_method ENUM('CASH', 'BANK_TRANSFER', 'CHEQUE', 'UPI', 'CREDIT') DEFAULT 'CASH',
    payment_status ENUM('PENDING', 'PARTIAL', 'PAID') DEFAULT 'PENDING',
    payment_date DATE,
    
    -- Reference and notes
    purchase_order_number VARCHAR(50),
    invoice_type ENUM('PURCHASE', 'EXPENSE', 'ASSET') DEFAULT 'PURCHASE',
    category VARCHAR(100), -- Medicine, Equipment, Office Supplies, etc.
    notes TEXT,
    
    -- Accounting integration
    journal_entry_id INT,
    
    -- Audit fields
    created_by VARCHAR(50) DEFAULT 'System',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_invoice_date (invoice_date),
    INDEX idx_supplier_name (supplier_name),
    INDEX idx_gst_eligible (is_gst_eligible),
    INDEX idx_payment_status (payment_status),
    
    -- Foreign key to journal entries
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
);

-- Purchase Invoice Items - Line items for each invoice
CREATE TABLE IF NOT EXISTS purchase_invoice_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    purchase_invoice_id INT NOT NULL,
    
    -- Item details
    item_name VARCHAR(200) NOT NULL,
    item_description TEXT,
    hsn_code VARCHAR(20),
    
    -- Quantity and rates
    quantity DECIMAL(10,3) NOT NULL,
    unit VARCHAR(20) DEFAULT 'NOS',
    rate DECIMAL(12,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    
    -- Discount on item level
    discount_percent DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    
    -- GST details
    gst_rate DECIMAL(5,2) DEFAULT 0.00,
    cgst_rate DECIMAL(5,2) DEFAULT 0.00,
    sgst_rate DECIMAL(5,2) DEFAULT 0.00,
    igst_rate DECIMAL(5,2) DEFAULT 0.00,
    cgst_amount DECIMAL(12,2) DEFAULT 0.00,
    sgst_amount DECIMAL(12,2) DEFAULT 0.00,
    igst_amount DECIMAL(12,2) DEFAULT 0.00,
    
    -- Final amount including GST
    total_amount DECIMAL(12,2) NOT NULL,
    
    -- Pharmacy specific fields (if applicable)
    batch_number VARCHAR(50),
    expiry_date DATE,
    manufacture_date DATE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
    INDEX idx_item_name (item_name),
    INDEX idx_hsn_code (hsn_code)
);

-- GST Input Credit Register - Track GST input credits claimed
CREATE TABLE IF NOT EXISTS gst_input_credit_register (
    id INT PRIMARY KEY AUTO_INCREMENT,
    purchase_invoice_id INT NOT NULL,
    
    -- GST period details
    gst_period_month INT NOT NULL, -- 1-12
    gst_period_year INT NOT NULL,
    filing_period VARCHAR(10), -- e.g., "2024-04"
    
    -- Credit amounts
    cgst_credit DECIMAL(12,2) DEFAULT 0.00,
    sgst_credit DECIMAL(12,2) DEFAULT 0.00,
    igst_credit DECIMAL(12,2) DEFAULT 0.00,
    total_credit DECIMAL(12,2) NOT NULL,
    
    -- Status and dates
    claim_status ENUM('PENDING', 'CLAIMED', 'ADJUSTED', 'REJECTED') DEFAULT 'PENDING',
    claim_date DATE,
    gstr_filing_date DATE,
    
    -- Reference
    gstr_reference VARCHAR(50),
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id),
    UNIQUE KEY unique_invoice_period (purchase_invoice_id, gst_period_month, gst_period_year),
    INDEX idx_filing_period (filing_period),
    INDEX idx_claim_status (claim_status)
);

-- Supplier Master - Store supplier details
CREATE TABLE IF NOT EXISTS suppliers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    supplier_name VARCHAR(200) NOT NULL,
    supplier_code VARCHAR(20) UNIQUE,
    
    -- Contact details
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    
    -- Address
    address_line1 VARCHAR(200),
    address_line2 VARCHAR(200),
    city VARCHAR(50),
    state VARCHAR(50),
    pincode VARCHAR(10),
    country VARCHAR(50) DEFAULT 'India',
    
    -- GST and legal
    gstin VARCHAR(15),
    pan VARCHAR(10),
    
    -- Business details
    supplier_type ENUM('VENDOR', 'MANUFACTURER', 'DISTRIBUTOR', 'SERVICE_PROVIDER') DEFAULT 'VENDOR',
    payment_terms VARCHAR(100), -- e.g., "30 days", "COD"
    credit_limit DECIMAL(12,2) DEFAULT 0.00,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_supplier_name (supplier_name),
    INDEX idx_gstin (gstin)
);

-- Add new accounts to chart of accounts for GST input credit tracking
INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, account_subtype, normal_balance) VALUES
-- GST Input Credits (Assets)
('1030', 'GST Input Credit - CGST', 'ASSET', 'Current Asset', 'DEBIT'),
('1031', 'GST Input Credit - SGST', 'ASSET', 'Current Asset', 'DEBIT'),
('1032', 'GST Input Credit - IGST', 'ASSET', 'Current Asset', 'DEBIT'),

-- Additional expense accounts
('5010', 'Purchase - Medicine', 'EXPENSE', 'Direct Cost', 'DEBIT'),
('5020', 'Purchase - Medical Equipment', 'EXPENSE', 'Direct Cost', 'DEBIT'),
('5030', 'Purchase - Office Supplies', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5040', 'Purchase - Other Items', 'EXPENSE', 'Operating Expense', 'DEBIT'),

-- Discount accounts
('4200', 'Purchase Discount Received', 'REVENUE', 'Other Revenue', 'CREDIT'),
('4210', 'Spot Payment Discount', 'REVENUE', 'Other Revenue', 'CREDIT'),

-- Supplier accounts
('2030', 'Trade Creditors', 'LIABILITY', 'Current Liability', 'CREDIT');

-- Add reference type for purchase invoices to journal entries
ALTER TABLE journal_entries 
MODIFY COLUMN reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND', 'PURCHASE_INVOICE', 'MANUAL', 'ADJUSTMENT') NOT NULL;