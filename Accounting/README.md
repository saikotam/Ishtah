# Clinic Accounting System

A comprehensive double-entry bookkeeping system designed specifically for medical clinics and healthcare practices.

## Features

### Core Accounting
- **Double-Entry Bookkeeping**: All transactions maintain accounting equation balance
- **Chart of Accounts**: Pre-configured accounts for medical practice operations
- **Journal Entries**: Automated and manual transaction recording
- **Account Balances**: Real-time balance tracking and reporting

### Financial Reports
- **Profit & Loss Statement**: Revenue, expenses, and net income analysis
- **Balance Sheet**: Assets, liabilities, and equity positions
- **Trial Balance**: Complete account balance verification
- **Cash Flow Statement**: Operating, investing, and financing activities

### Bank Integration
- **Bank Statement Upload**: Process Excel/CSV files from major Indian banks
- **Auto-categorization**: Smart transaction classification based on descriptions
- **Multiple Bank Support**: SBI, HDFC, ICICI, Axis Bank, and generic formats

### Dashboard & Analytics
- **Financial Dashboard**: Key performance indicators and trends
- **Monthly Revenue Tracking**: Visual charts and analytics
- **Cash Position Monitoring**: Real-time cash flow tracking
- **Financial Health Indicators**: Ratios and performance metrics

## File Structure

```
Accounting/
├── accounting.php              # Core accounting classes and functions
├── accounting_dashboard.php    # Main dashboard with KPIs
├── accounting_schema.sql       # Database schema and initial data
├── balance_sheet.php          # Balance sheet report generator
├── bank_statement_upload.php  # Bank statement processing
├── cash_flow_statement.php    # Cash flow statement generator
├── profit_loss_statement.php  # P&L statement generator
├── setup_accounting.php       # System initialization
├── trial_balance.php          # Trial balance report
└── README.md                  # This documentation
```

## Installation

1. **Database Setup**
   ```bash
   # Navigate to the Accounting folder
   cd Accounting/
   
   # Run the setup script via web browser
   http://your-domain/Accounting/setup_accounting.php
   ```

2. **File Permissions**
   ```bash
   # Create uploads directory for bank statements
   mkdir -p ../uploads/bank_statements/
   chmod 755 ../uploads/bank_statements/
   ```

3. **Dependencies**
   - PHP 7.4+ with PDO MySQL extension
   - MySQL 5.7+ or MariaDB 10.2+
   - Bootstrap 5.1.3 (loaded via CDN)
   - Font Awesome 6.0.0 (loaded via CDN)
   - Chart.js (for dashboard charts)

## Usage

### Accessing the System
- **Main Dashboard**: `/Accounting/accounting_dashboard.php`
- **Admin Panel**: Access via main admin page under "Accounting & Finance"

### Generating Reports
1. Navigate to the desired report from the dashboard
2. Select date range (defaults to current financial year)
3. Generate report in HTML, CSV, or print format
4. Export options available for all reports

### Bank Statement Processing
1. Go to "Bank Statement Upload"
2. Select your bank format (SBI, HDFC, ICICI, Axis, or Generic)
3. Choose the corresponding bank account
4. Upload Excel (.xlsx, .xls) or CSV file
5. Review auto-categorized transactions
6. Process to create journal entries

### Chart of Accounts

#### Assets (1000-1999)
- **1000**: Cash in Hand
- **1010**: Cash at Bank
- **1020**: Accounts Receivable
- **1100**: Inventory - Pharmacy
- **1200**: Medical Equipment
- **1210**: Furniture & Fixtures
- **1220**: Computer Equipment

#### Liabilities (2000-2999)
- **2000**: Accounts Payable
- **2010**: GST Payable
- **2020**: TDS Payable
- **2100**: Bank Loan
- **2200**: Accrued Expenses

#### Equity (3000-3999)
- **3000**: Owner's Capital
- **3100**: Retained Earnings
- **3200**: Current Year Earnings

#### Revenue (4000-4999)
- **4000**: Consultation Revenue
- **4010**: Pharmacy Sales
- **4020**: Laboratory Revenue
- **4030**: Ultrasound Revenue
- **4100**: Other Income

#### Expenses (5000-6999)
- **5000**: Cost of Goods Sold - Pharmacy
- **5100**: Doctor Fees
- **5200**: Staff Salaries
- **5300**: Rent Expense
- **5400**: Utilities Expense
- **5500**: Medical Supplies
- **5600**: Office Supplies
- **5700**: Insurance Expense
- **5800**: Depreciation Expense
- **5900**: Bank Charges
- **6000**: Professional Fees
- **6100**: Marketing Expense
- **6200**: Maintenance Expense

## Bank Statement Formats

### Supported Banks
- **State Bank of India (SBI)**
- **HDFC Bank**
- **ICICI Bank**
- **Axis Bank**
- **Generic Format**

### Expected CSV Columns

#### SBI Format
```
Date, Description, Reference, Debit, Credit, Balance
```

#### HDFC Format
```
Date, Description, Reference, Amount, Balance
```

#### ICICI Format
```
Date, Reference, Description, Debit, Credit, Balance
```

#### Axis Format
```
Date, Description, Reference, Debit, Credit
```

#### Generic Format
```
Date, Description, Reference, Amount
```

## Auto-categorization Rules

The system automatically assigns accounts based on transaction descriptions:

### Income Patterns
- `consultation|patient|fee` → Consultation Revenue (4000)
- `pharmacy|medicine|drug` → Pharmacy Sales (4010)
- `lab|test|pathology` → Laboratory Revenue (4020)
- `ultrasound|scan|imaging` → Ultrasound Revenue (4030)

### Expense Patterns
- `salary|wages|staff` → Staff Salaries (5200)
- `rent` → Rent Expense (5300)
- `electricity|power|utility` → Utilities (5400)
- `medical|supplies` → Medical Supplies (5500)
- `bank|charges|fee` → Bank Charges (5900)

## API Functions

### AccountingSystem Class

```php
// Create new instance
$accounting = new AccountingSystem($pdo);

// Create journal entry
$journal_id = $accounting->createJournalEntry(
    $date,           // Transaction date
    $reference_type, // CONSULTATION, PHARMACY, LAB, etc.
    $reference_id,   // Related record ID
    $description,    // Transaction description
    $lines,          // Array of debit/credit lines
    $created_by      // User who created entry
);

// Get account balance
$balance = $accounting->getAccountBalance($account_id, $as_of_date);

// Get trial balance
$trial_balance = $accounting->getTrialBalance($as_of_date);

// Record consultation revenue
$journal_id = $accounting->recordConsultationRevenue(
    $patient_id,
    $doctor_id,
    $amount,
    $payment_mode
);

// Record pharmacy sale
$journal_id = $accounting->recordPharmacySale(
    $bill_id,
    $total_amount,
    $gst_amount,
    $cost_amount,
    $payment_mode
);
```

## Financial Year Configuration

The system follows Indian financial year (April 1 - March 31):

```php
// Get current financial year
$fy = getFinancialYear(); // Returns "2024-25"

// Get FY date range
$dates = getFinancialYearDates(); 
// Returns: ['start' => '2024-04-01', 'end' => '2025-03-31']
```

## Customization

### Adding New Accounts
1. Insert into `chart_of_accounts` table
2. Follow numbering convention
3. Set appropriate account type and normal balance

### Custom Bank Formats
1. Add new parser function in `bank_statement_upload.php`
2. Update bank selection UI
3. Add format documentation

### Report Modifications
1. Modify SQL queries in report generators
2. Update HTML templates as needed
3. Maintain export functionality

## Security Considerations

- All database queries use prepared statements
- File uploads are validated and stored securely
- User input is sanitized and escaped
- Access control should be implemented at application level

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Check `../includes/db.php` configuration
   - Verify MySQL service is running
   - Confirm database credentials

2. **File Upload Failures**
   - Check `uploads/bank_statements/` directory exists
   - Verify directory permissions (755)
   - Ensure PHP upload limits are sufficient

3. **Report Generation Errors**
   - Verify accounting tables are created
   - Check for sufficient sample data
   - Review PHP error logs

### Performance Optimization

- Index frequently queried date columns
- Archive old journal entries periodically
- Use database query caching
- Optimize report queries for large datasets

## Support

For technical support or customization requests, refer to the main clinic management system documentation or contact the development team.

## Version History

- **v1.0**: Initial release with core accounting features
- **v1.1**: Added bank statement upload functionality
- **v1.2**: Enhanced dashboard with charts and analytics