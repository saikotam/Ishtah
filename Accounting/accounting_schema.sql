-- Accounting System Database Schema
-- This schema creates the foundation for double-entry bookkeeping and financial reporting

-- Chart of Accounts - Master list of all accounts
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_code VARCHAR(20) UNIQUE NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE') NOT NULL,
    account_subtype VARCHAR(50),
    parent_account_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    normal_balance ENUM('DEBIT', 'CREDIT') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_account_id) REFERENCES chart_of_accounts(id)
);

-- Journal Entries - Main transaction records
CREATE TABLE IF NOT EXISTS journal_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entry_number VARCHAR(20) UNIQUE NOT NULL,
    entry_date DATE NOT NULL,
    reference_type ENUM('CONSULTATION', 'PHARMACY', 'LAB', 'ULTRASOUND', 'MANUAL', 'ADJUSTMENT') NOT NULL,
    reference_id INT,
    description TEXT NOT NULL,
    total_debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('DRAFT', 'POSTED', 'REVERSED') DEFAULT 'DRAFT',
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Journal Entry Lines - Individual debit/credit entries
CREATE TABLE IF NOT EXISTS journal_entry_lines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    journal_entry_id INT NOT NULL,
    account_id INT NOT NULL,
    description VARCHAR(255),
    debit_amount DECIMAL(12,2) DEFAULT 0.00,
    credit_amount DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
);

-- Account Balances - Running balances for each account
CREATE TABLE IF NOT EXISTS account_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_id INT NOT NULL,
    balance_date DATE NOT NULL,
    debit_balance DECIMAL(12,2) DEFAULT 0.00,
    credit_balance DECIMAL(12,2) DEFAULT 0.00,
    net_balance DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_account_date (account_id, balance_date),
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
);

-- Financial Periods for reporting
CREATE TABLE IF NOT EXISTS financial_periods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    period_name VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_closed BOOLEAN DEFAULT FALSE,
    fiscal_year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default Chart of Accounts
INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, account_subtype, normal_balance) VALUES
-- ASSETS
('1000', 'Cash in Hand', 'ASSET', 'Current Asset', 'DEBIT'),
('1010', 'Cash at Bank', 'ASSET', 'Current Asset', 'DEBIT'),
('1020', 'Accounts Receivable', 'ASSET', 'Current Asset', 'DEBIT'),
('1100', 'Inventory - Pharmacy', 'ASSET', 'Current Asset', 'DEBIT'),
('1200', 'Medical Equipment', 'ASSET', 'Fixed Asset', 'DEBIT'),
('1210', 'Furniture & Fixtures', 'ASSET', 'Fixed Asset', 'DEBIT'),
('1220', 'Computer Equipment', 'ASSET', 'Fixed Asset', 'DEBIT'),
('1300', 'Accumulated Depreciation - Equipment', 'ASSET', 'Fixed Asset', 'CREDIT'),
('1310', 'Accumulated Depreciation - Furniture', 'ASSET', 'Fixed Asset', 'CREDIT'),

-- LIABILITIES
('2000', 'Accounts Payable', 'LIABILITY', 'Current Liability', 'CREDIT'),
('2010', 'GST Payable', 'LIABILITY', 'Current Liability', 'CREDIT'),
('2020', 'TDS Payable', 'LIABILITY', 'Current Liability', 'CREDIT'),
('2100', 'Bank Loan', 'LIABILITY', 'Long-term Liability', 'CREDIT'),
('2200', 'Accrued Expenses', 'LIABILITY', 'Current Liability', 'CREDIT'),

-- EQUITY
('3000', 'Owner\'s Capital', 'EQUITY', 'Capital', 'CREDIT'),
('3100', 'Retained Earnings', 'EQUITY', 'Retained Earnings', 'CREDIT'),
('3200', 'Current Year Earnings', 'EQUITY', 'Current Earnings', 'CREDIT'),

-- REVENUE
('4000', 'Consultation Revenue', 'REVENUE', 'Service Revenue', 'CREDIT'),
('4010', 'Pharmacy Sales', 'REVENUE', 'Product Revenue', 'CREDIT'),
('4020', 'Laboratory Revenue', 'REVENUE', 'Service Revenue', 'CREDIT'),
('4030', 'Ultrasound Revenue', 'REVENUE', 'Service Revenue', 'CREDIT'),
('4100', 'Other Income', 'REVENUE', 'Other Revenue', 'CREDIT'),

-- EXPENSES
('5000', 'Cost of Goods Sold - Pharmacy', 'EXPENSE', 'Direct Cost', 'DEBIT'),
('5100', 'Doctor Fees', 'EXPENSE', 'Professional Fees', 'DEBIT'),
('5200', 'Staff Salaries', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5300', 'Rent Expense', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5400', 'Utilities Expense', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5500', 'Medical Supplies', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5600', 'Office Supplies', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5700', 'Insurance Expense', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5800', 'Depreciation Expense', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('5900', 'Bank Charges', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('6000', 'Professional Fees', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('6100', 'Marketing Expense', 'EXPENSE', 'Operating Expense', 'DEBIT'),
('6200', 'Maintenance Expense', 'EXPENSE', 'Operating Expense', 'DEBIT');

-- Insert current financial period
INSERT IGNORE INTO financial_periods (period_name, start_date, end_date, fiscal_year) VALUES
('FY 2024-25', '2024-04-01', '2025-03-31', 2024);