<?php
// includes/accounting.php - Core Accounting Functions and Classes

class AccountingSystem {
    private $pdo;
    private $maxRetries = 3;
    private $retryDelay = 1; // seconds
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Safe execution with automatic retry and duplicate prevention
     */
    private function safeExecute($referenceType, $referenceId, $callable, $maxRetries = null) {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        
        // Check if already synced to prevent duplicates
        if ($this->isAlreadySynced($referenceType, $referenceId)) {
            $this->log("Entry already synced: {$referenceType} ID {$referenceId}", 'DEBUG');
            return $this->getExistingJournalEntryId($referenceType, $referenceId);
        }
        
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $result = $callable();
                $this->log("Successfully synced {$referenceType} ID {$referenceId} on attempt {$attempt}", 'INFO');
                return $result;
                
            } catch (Exception $e) {
                $lastException = $e;
                $this->log("Sync attempt {$attempt} failed for {$referenceType} ID {$referenceId}: " . $e->getMessage(), 'WARNING');
                
                if ($attempt < $maxRetries) {
                    sleep($this->retryDelay * $attempt); // Progressive delay
                }
            }
        }
        
        // All retries failed - log error but don't throw exception to avoid breaking business logic
        $this->log("All sync attempts failed for {$referenceType} ID {$referenceId}: " . $lastException->getMessage(), 'ERROR');
        error_log("ACCOUNTING SYNC FAILED: {$referenceType} ID {$referenceId} - " . $lastException->getMessage());
        
        return null; // Return null to indicate sync failure without breaking the calling code
    }
    
    /**
     * Check if record is already synced
     */
    private function isAlreadySynced($referenceType, $referenceId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM journal_entries 
                WHERE reference_type = ? AND reference_id = ? AND status = 'POSTED'
            ");
            $stmt->execute([$referenceType, $referenceId]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            $this->log("Error checking sync status: " . $e->getMessage(), 'WARNING');
            return false; // Assume not synced if we can't check
        }
    }
    
    /**
     * Get existing journal entry ID
     */
    private function getExistingJournalEntryId($referenceType, $referenceId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id FROM journal_entries 
                WHERE reference_type = ? AND reference_id = ? AND status = 'POSTED'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$referenceType, $referenceId]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            $this->log("Error getting existing journal entry: " . $e->getMessage(), 'WARNING');
            return null;
        }
    }
    
    /**
     * Enhanced logging
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [ACCOUNTING] [{$level}] {$message}";
        error_log($logMessage);
        
        // Also log to database if possible (but don't fail if we can't)
        try {
            if ($level === 'ERROR' || $level === 'CRITICAL') {
                // Could add database logging here if needed
            }
        } catch (Exception $e) {
            // Ignore logging errors to prevent infinite loops
        }
    }
    
    /**
     * Create a journal entry with multiple lines
     */
    public function createJournalEntry($entry_date, $reference_type, $reference_id, $description, $lines, $created_by = 'System') {
        try {
            $this->pdo->beginTransaction();
            
            // Generate entry number
            $entry_number = $this->generateEntryNumber();
            
            // Calculate totals
            $total_debit = array_sum(array_column($lines, 'debit_amount'));
            $total_credit = array_sum(array_column($lines, 'credit_amount'));
            
            // Validate that debits equal credits
            if (abs($total_debit - $total_credit) > 0.01) {
                throw new Exception("Journal entry is not balanced. Debits: $total_debit, Credits: $total_credit");
            }
            
            // Insert journal entry header
            $stmt = $this->pdo->prepare("
                INSERT INTO journal_entries (entry_number, entry_date, reference_type, reference_id, description, total_debit, total_credit, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$entry_number, $entry_date, $reference_type, $reference_id, $description, $total_debit, $total_credit, $created_by]);
            $journal_entry_id = $this->pdo->lastInsertId();
            
            // Insert journal entry lines
            foreach ($lines as $line) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO journal_entry_lines (journal_entry_id, account_id, description, debit_amount, credit_amount)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $journal_entry_id,
                    $line['account_id'],
                    $line['description'] ?? '',
                    $line['debit_amount'] ?? 0,
                    $line['credit_amount'] ?? 0
                ]);
            }
            
            // Post the entry and update balances
            $this->postJournalEntry($journal_entry_id);
            
            $this->pdo->commit();
            return $journal_entry_id;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Post journal entry and update account balances
     */
    public function postJournalEntry($journal_entry_id) {
        // Update journal entry status
        $stmt = $this->pdo->prepare("UPDATE journal_entries SET status = 'POSTED' WHERE id = ?");
        $stmt->execute([$journal_entry_id]);
        
        // Get journal entry details
        $stmt = $this->pdo->prepare("SELECT entry_date FROM journal_entries WHERE id = ?");
        $stmt->execute([$journal_entry_id]);
        $entry = $stmt->fetch();
        
        // Get all lines for this entry
        $stmt = $this->pdo->prepare("
            SELECT account_id, SUM(debit_amount) as total_debit, SUM(credit_amount) as total_credit
            FROM journal_entry_lines 
            WHERE journal_entry_id = ?
            GROUP BY account_id
        ");
        $stmt->execute([$journal_entry_id]);
        $lines = $stmt->fetchAll();
        
        // Update account balances
        foreach ($lines as $line) {
            $this->updateAccountBalance($line['account_id'], $entry['entry_date'], $line['total_debit'], $line['total_credit']);
        }
    }
    
    /**
     * Update account balance for a specific date
     */
    private function updateAccountBalance($account_id, $balance_date, $debit_amount, $credit_amount) {
        // Get current balance
        $stmt = $this->pdo->prepare("
            SELECT debit_balance, credit_balance FROM account_balances 
            WHERE account_id = ? AND balance_date = ?
        ");
        $stmt->execute([$account_id, $balance_date]);
        $current_balance = $stmt->fetch();
        
        if ($current_balance) {
            // Update existing balance
            $new_debit = $current_balance['debit_balance'] + $debit_amount;
            $new_credit = $current_balance['credit_balance'] + $credit_amount;
            $net_balance = $new_debit - $new_credit;
            
            $stmt = $this->pdo->prepare("
                UPDATE account_balances 
                SET debit_balance = ?, credit_balance = ?, net_balance = ?, updated_at = NOW()
                WHERE account_id = ? AND balance_date = ?
            ");
            $stmt->execute([$new_debit, $new_credit, $net_balance, $account_id, $balance_date]);
        } else {
            // Create new balance record
            $net_balance = $debit_amount - $credit_amount;
            $stmt = $this->pdo->prepare("
                INSERT INTO account_balances (account_id, balance_date, debit_balance, credit_balance, net_balance)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$account_id, $balance_date, $debit_amount, $credit_amount, $net_balance]);
        }
    }
    
    /**
     * Generate unique entry number
     */
    private function generateEntryNumber() {
        $year = date('Y');
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) + 1 as next_number 
            FROM journal_entries 
            WHERE YEAR(created_at) = ?
        ");
        $stmt->execute([$year]);
        $result = $stmt->fetch();
        return "JE{$year}" . str_pad($result['next_number'], 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Get account balance as of a specific date
     */
    public function getAccountBalance($account_id, $as_of_date) {
        $stmt = $this->pdo->prepare("
            SELECT 
                c.account_name,
                c.normal_balance,
                COALESCE(SUM(jel.debit_amount), 0) as total_debits,
                COALESCE(SUM(jel.credit_amount), 0) as total_credits
            FROM chart_of_accounts c
            LEFT JOIN journal_entry_lines jel ON c.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE c.id = ? 
            AND (je.entry_date IS NULL OR je.entry_date <= ?)
            AND (je.status IS NULL OR je.status = 'POSTED')
            GROUP BY c.id, c.account_name, c.normal_balance
        ");
        $stmt->execute([$account_id, $as_of_date]);
        $result = $stmt->fetch();
        
        if (!$result) return 0;
        
        $net_balance = $result['total_debits'] - $result['total_credits'];
        return ($result['normal_balance'] === 'DEBIT') ? $net_balance : -$net_balance;
    }
    
    /**
     * Get trial balance as of a specific date
     */
    public function getTrialBalance($as_of_date) {
        $stmt = $this->pdo->prepare("
            SELECT 
                c.id,
                c.account_code,
                c.account_name,
                c.account_type,
                c.normal_balance,
                COALESCE(SUM(jel.debit_amount), 0) as total_debits,
                COALESCE(SUM(jel.credit_amount), 0) as total_credits
            FROM chart_of_accounts c
            LEFT JOIN journal_entry_lines jel ON c.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE c.is_active = 1
            AND (je.entry_date IS NULL OR je.entry_date <= ?)
            AND (je.status IS NULL OR je.status = 'POSTED')
            GROUP BY c.id, c.account_code, c.account_name, c.account_type, c.normal_balance
            HAVING (total_debits + total_credits) > 0
            ORDER BY c.account_code
        ");
        $stmt->execute([$as_of_date]);
        $accounts = $stmt->fetchAll();
        
        $trial_balance = [];
        foreach ($accounts as $account) {
            $net_balance = $account['total_debits'] - $account['total_credits'];
            $balance = ($account['normal_balance'] === 'DEBIT') ? $net_balance : -$net_balance;
            
            if (abs($balance) > 0.01) { // Only include accounts with non-zero balances
                $trial_balance[] = [
                    'account_code' => $account['account_code'],
                    'account_name' => $account['account_name'],
                    'account_type' => $account['account_type'],
                    'debit_balance' => ($balance > 0) ? $balance : 0,
                    'credit_balance' => ($balance < 0) ? abs($balance) : 0,
                    'balance' => $balance
                ];
            }
        }
        
        return $trial_balance;
    }
    
    /**
     * Record consultation revenue with bulletproof sync
     */
    public function recordConsultationRevenue($patient_id, $doctor_id, $amount, $payment_mode, $date = null, $consultation_id = null) {
        // Use consultation_id if provided, otherwise use patient_id as reference
        $referenceId = $consultation_id ?? $patient_id;
        
        return $this->safeExecute('CONSULTATION', $referenceId, function() use ($patient_id, $doctor_id, $amount, $payment_mode, $date, $referenceId) {
            $date = $date ?? date('Y-m-d');
            
            // Determine cash/bank account based on payment mode
            $cash_account_id = ($payment_mode === 'cash') ? 
                $this->getAccountIdByCode('1000') : // Cash in Hand
                $this->getAccountIdByCode('1010');  // Cash at Bank
                
            $revenue_account_id = $this->getAccountIdByCode('4000'); // Consultation Revenue
            
            $lines = [
                [
                    'account_id' => $cash_account_id,
                    'description' => "Consultation fee - Patient ID: $patient_id, Doctor ID: $doctor_id",
                    'debit_amount' => $amount,
                    'credit_amount' => 0
                ],
                [
                    'account_id' => $revenue_account_id,
                    'description' => "Consultation fee - Patient ID: $patient_id, Doctor ID: $doctor_id",
                    'debit_amount' => 0,
                    'credit_amount' => $amount
                ]
            ];
            
            return $this->createJournalEntry(
                $date,
                'CONSULTATION',
                $referenceId,
                "Consultation fee collected from Patient ID: $patient_id (Doctor: $doctor_id)",
                $lines
            );
        });
    }
    
    /**
     * Record pharmacy sale with bulletproof sync
     */
    public function recordPharmacySale($bill_id, $total_amount, $gst_amount, $cost_amount, $payment_mode, $date = null) {
        return $this->safeExecute('PHARMACY', $bill_id, function() use ($bill_id, $total_amount, $gst_amount, $cost_amount, $payment_mode, $date) {
            $date = $date ?? date('Y-m-d');
            
            // Determine cash/bank account
            $cash_account_id = ($payment_mode === 'cash') ? 
                $this->getAccountIdByCode('1000') : 
                $this->getAccountIdByCode('1010');
                
            $revenue_account_id = $this->getAccountIdByCode('4010'); // Pharmacy Sales
            $cogs_account_id = $this->getAccountIdByCode('5000');    // Cost of Goods Sold
            $inventory_account_id = $this->getAccountIdByCode('1100'); // Inventory
            $gst_account_id = $this->getAccountIdByCode('2010');     // GST Payable
            
            $lines = [
                [
                    'account_id' => $cash_account_id,
                    'description' => "Pharmacy sale - Bill ID: $bill_id",
                    'debit_amount' => $total_amount,
                    'credit_amount' => 0
                ],
                [
                    'account_id' => $revenue_account_id,
                    'description' => "Pharmacy sale - Bill ID: $bill_id",
                    'debit_amount' => 0,
                    'credit_amount' => $total_amount - $gst_amount
                ],
                [
                    'account_id' => $gst_account_id,
                    'description' => "GST on pharmacy sale - Bill ID: $bill_id",
                    'debit_amount' => 0,
                    'credit_amount' => $gst_amount
                ],
                [
                    'account_id' => $cogs_account_id,
                    'description' => "Cost of goods sold - Bill ID: $bill_id",
                    'debit_amount' => $cost_amount,
                    'credit_amount' => 0
                ],
                [
                    'account_id' => $inventory_account_id,
                    'description' => "Inventory reduction - Bill ID: $bill_id",
                    'debit_amount' => 0,
                    'credit_amount' => $cost_amount
                ]
            ];
            
            return $this->createJournalEntry(
                $date,
                'PHARMACY',
                $bill_id,
                "Pharmacy sale - Bill ID: $bill_id",
                $lines
            );
        });
    }

    /**
     * Record laboratory revenue with bulletproof sync
     */
    public function recordLabRevenue($bill_id, $amount, $payment_mode, $date = null) {
        return $this->safeExecute('LAB', $bill_id, function() use ($bill_id, $amount, $payment_mode, $date) {
            $date = $date ?? date('Y-m-d');
            
            $cash_account_id = ($payment_mode === 'cash') ? 
                $this->getAccountIdByCode('1000') : 
                $this->getAccountIdByCode('1010');
            $revenue_account_id = $this->getAccountIdByCode('4020'); // Laboratory Revenue
            
            $lines = [
                [
                    'account_id' => $cash_account_id,
                    'description' => "Lab bill - Bill ID: $bill_id",
                    'debit_amount' => $amount,
                    'credit_amount' => 0
                ],
                [
                    'account_id' => $revenue_account_id,
                    'description' => "Lab bill - Bill ID: $bill_id",
                    'debit_amount' => 0,
                    'credit_amount' => $amount
                ]
            ];
            
            return $this->createJournalEntry(
                $date,
                'LAB',
                $bill_id,
                "Lab bill - Bill ID: $bill_id",
                $lines
            );
        });
    }

    /**
     * Record ultrasound revenue with bulletproof sync
     */
    public function recordUltrasoundRevenue($bill_id, $amount, $payment_mode, $date = null) {
        return $this->safeExecute('ULTRASOUND', $bill_id, function() use ($bill_id, $amount, $payment_mode, $date) {
            $date = $date ?? date('Y-m-d');
            
            $cash_account_id = ($payment_mode === 'cash') ? 
                $this->getAccountIdByCode('1000') : 
                $this->getAccountIdByCode('1010');
            $revenue_account_id = $this->getAccountIdByCode('4030'); // Ultrasound Revenue
            
            $lines = [
                [
                    'account_id' => $cash_account_id,
                    'description' => "Ultrasound bill - Bill ID: $bill_id",
                    'debit_amount' => $amount,
                    'credit_amount' => 0
                ],
                [
                    'account_id' => $revenue_account_id,
                    'description' => "Ultrasound bill - Bill ID: $bill_id",
                    'debit_amount' => 0,
                    'credit_amount' => $amount
                ]
            ];
            
            return $this->createJournalEntry(
                $date,
                'ULTRASOUND',
                $bill_id,
                "Ultrasound bill - Bill ID: $bill_id",
                $lines
            );
        });
    }
    
    /**
     * Get account ID by account code
     */
    private function getAccountIdByCode($code) {
        $stmt = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
        $stmt->execute([$code]);
        $result = $stmt->fetch();
        return $result ? $result['id'] : null;
    }
    
    /**
     * Get accounts by type for reporting
     */
    public function getAccountsByType($account_type, $as_of_date = null) {
        $as_of_date = $as_of_date ?? date('Y-m-d');
        
        $stmt = $this->pdo->prepare("
            SELECT 
                c.id,
                c.account_code,
                c.account_name,
                c.account_type,
                c.account_subtype,
                c.normal_balance,
                COALESCE(SUM(jel.debit_amount), 0) as total_debits,
                COALESCE(SUM(jel.credit_amount), 0) as total_credits
            FROM chart_of_accounts c
            LEFT JOIN journal_entry_lines jel ON c.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE c.account_type = ? 
            AND c.is_active = 1
            AND (je.entry_date IS NULL OR je.entry_date <= ?)
            AND (je.status IS NULL OR je.status = 'POSTED')
            GROUP BY c.id, c.account_code, c.account_name, c.account_type, c.account_subtype, c.normal_balance
            ORDER BY c.account_code
        ");
        $stmt->execute([$account_type, $as_of_date]);
        $accounts = $stmt->fetchAll();
        
        $result = [];
        foreach ($accounts as $account) {
            $net_balance = $account['total_debits'] - $account['total_credits'];
            $balance = ($account['normal_balance'] === 'DEBIT') ? $net_balance : -$net_balance;
            
            $result[] = [
                'id' => $account['id'],
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'account_type' => $account['account_type'],
                'account_subtype' => $account['account_subtype'],
                'balance' => $balance,
                'total_debits' => $account['total_debits'],
                'total_credits' => $account['total_credits']
            ];
        }
        
        return $result;
    }
}

// Helper functions for financial calculations
function formatCurrency($amount) {
    return '₹ ' . number_format($amount, 2);
}

function getFinancialYear($date = null) {
    $date = $date ?? date('Y-m-d');
    $year = date('Y', strtotime($date));
    $month = date('n', strtotime($date));
    
    if ($month >= 4) {
        return $year . '-' . ($year + 1);
    } else {
        return ($year - 1) . '-' . $year;
    }
}

function getFinancialYearDates($financial_year = null) {
    if (!$financial_year) {
        $financial_year = getFinancialYear();
    }
    
    $years = explode('-', $financial_year);
    $start_year = $years[0];
    $end_year = $years[1];
    
    return [
        'start' => $start_year . '-04-01',
        'end' => $end_year . '-03-31'
    ];
}
?>