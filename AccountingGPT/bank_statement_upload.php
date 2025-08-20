<?php
// bank_statement_upload.php - Upload and process bank statements (CSV/XLSX)
require_once '../includes/db.php';

$message = '';
$error = '';
$processed_rows = 0;

// Create table if not exists (simple, generic schema)
try {
	$pdo->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
		id INT AUTO_INCREMENT PRIMARY KEY,
		bank_name VARCHAR(128) NULL,
		account_number VARCHAR(64) NULL,
		value_date DATE NULL,
		transaction_date DATE NULL,
		narration TEXT NULL,
		reference_no VARCHAR(128) NULL,
		debit_amount DECIMAL(12,2) DEFAULT 0,
		credit_amount DECIMAL(12,2) DEFAULT 0,
		balance DECIMAL(14,2) NULL,
		currency VARCHAR(8) DEFAULT 'INR',
		raw_payload LONGTEXT NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
	)");
} catch (Exception $e) {
	$error = 'Failed to ensure transactions table: ' . $e->getMessage();
}

// Helpers
function parse_csv($path) {
	$rows = [];
	if (($handle = fopen($path, 'r')) !== false) {
		$header = null;
		while (($data = fgetcsv($handle)) !== false) {
			if ($header === null) { $header = $data; continue; }
			$rows[] = array_combine($header, $data);
		}
		fclose($handle);
	}
	return $rows;
}

function try_import_spreadsheet($path) {
	// Attempt to use PhpSpreadsheet if installed
	try {
		if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
			return null;
		}
		$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
		$spreadsheet = $reader->load($path);
		$sheet = $spreadsheet->getActiveSheet();
		$header = [];
		$rows = [];
		foreach ($sheet->getRowIterator() as $rowIndex => $row) {
			$cells = [];
			$cellIterator = $row->getCellIterator();
			$cellIterator->setIterateOnlyExistingCells(false);
			foreach ($cellIterator as $cell) { $cells[] = trim((string)$cell->getValue()); }
			if ($rowIndex === 1) { $header = $cells; continue; }
			if (count(array_filter($cells)) === 0) { continue; }
			$rows[] = array_combine($header, $cells);
		}
		return $rows;
	} catch (Throwable $e) {
		return null;
	}
}

function normalize_row($row) {
	// Try to map common bank statement headers to our schema
	$map = [
		'value_date' => ['Value Date','ValueDate','Value Dt','Value_Date','Val Date'],
		'transaction_date' => ['Transaction Date','Txn Date','Tran Date','Posting Date','Date'],
		'narration' => ['Narration','Description','Particulars','Details','Transaction Remarks'],
		'reference_no' => ['Ref No','Reference No','Cheque No','UTR','Reference Number','RefNo'],
		'debit_amount' => ['Withdrawal Amt.','Debit','Dr Amount','Debit Amount','Dr'],
		'credit_amount' => ['Deposit Amt.','Credit','Cr Amount','Credit Amount','Cr'],
		'balance' => ['Balance','Closing Balance','Running Balance'],
		'account_number' => ['Account No','A/c No','Account Number'],
		'bank_name' => ['Bank','Bank Name']
	];
	$normalized = [
		'bank_name' => null,
		'account_number' => null,
		'value_date' => null,
		'transaction_date' => null,
		'narration' => null,
		'reference_no' => null,
		'debit_amount' => 0,
		'credit_amount' => 0,
		'balance' => null,
		'currency' => 'INR'
	];
	foreach ($map as $key => $aliases) {
		foreach ($aliases as $alias) {
			if (isset($row[$alias]) && $row[$alias] !== '') { $normalized[$key] = $row[$alias]; break; }
		}
	}
	// Try generic fallbacks
	if ($normalized['debit_amount'] === 0 && isset($row['Debit'])) { $normalized['debit_amount'] = $row['Debit']; }
	if ($normalized['credit_amount'] === 0 && isset($row['Credit'])) { $normalized['credit_amount'] = $row['Credit']; }
	return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['statement_file'])) {
	try {
		if (!isset($_FILES['statement_file']) || $_FILES['statement_file']['error'] !== UPLOAD_ERR_OK) {
			throw new Exception('Upload failed.');
		}
		$tmp = $_FILES['statement_file']['tmp_name'];
		$name = $_FILES['statement_file']['name'];
		$upload_bank_name = isset($_POST['bank_name']) && $_POST['bank_name'] !== '' ? trim($_POST['bank_name']) : null;
		$upload_account_number = isset($_POST['account_number']) && $_POST['account_number'] !== '' ? trim($_POST['account_number']) : null;
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$rows = [];
		if ($ext === 'csv') {
			$rows = parse_csv($tmp);
		} else if (in_array($ext, ['xlsx','xls'])) {
			$rows = try_import_spreadsheet($tmp);
			if ($rows === null) {
				throw new Exception('XLS/XLSX parsing requires PhpSpreadsheet. Please install it or upload CSV.');
			}
		} else {
			throw new Exception('Unsupported file type. Please upload CSV or XLSX.');
		}
		if (!$rows) {
			throw new Exception('No data found in file.');
		}
		$insert = $pdo->prepare("INSERT INTO bank_transactions (bank_name, account_number, value_date, transaction_date, narration, reference_no, debit_amount, credit_amount, balance, currency, raw_payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
		foreach ($rows as $row) {
			$norm = normalize_row($row);
			// Parse dates and amounts defensively
			$val_date = $norm['value_date'] ? date('Y-m-d', strtotime($norm['value_date'])) : null;
			$txn_date = $norm['transaction_date'] ? date('Y-m-d', strtotime($norm['transaction_date'])) : null;
			$debit = is_numeric(str_replace([','], '', $norm['debit_amount'])) ? floatval(str_replace([','], '', $norm['debit_amount'])) : 0;
			$credit = is_numeric(str_replace([','], '', $norm['credit_amount'])) ? floatval(str_replace([','], '', $norm['credit_amount'])) : 0;
			$balance = null;
			if (isset($norm['balance'])) {
				$balStr = str_replace([','], '', (string)$norm['balance']);
				if (is_numeric($balStr)) { $balance = floatval($balStr); }
			}
			$raw = json_encode($row, JSON_UNESCAPED_UNICODE);
			$insert->execute([
				$norm['bank_name'] ?: $upload_bank_name,
				$norm['account_number'] ?: $upload_account_number,
				$val_date,
				$txn_date,
				$norm['narration'],
				$norm['reference_no'],
				$debit,
				$credit,
				$balance,
				$norm['currency'],
				$raw
			]);
			$processed_rows++;
		}
		$message = "Imported $processed_rows transactions.";
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Statement Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="mb-0">Bank Statement Upload</h3>
        <a href="../admin.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Upload CSV/XLSX</strong></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Statement File</label>
                    <input type="file" name="statement_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Bank (optional)</label>
                    <input type="text" name="bank_name" class="form-control" placeholder="HDFC/ICICI/SBI">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Account (optional)</label>
                    <input type="text" name="account_number" class="form-control" placeholder="xxxxxx">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Upload & Process</button>
                </div>
            </form>
            <div class="text-muted small mt-2">Tip: If XLSX parsing fails, export your statement to CSV and re-upload.</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Recent Imports</strong></div>
        <div class="card-body">
            <?php
			$stmt = $pdo->query("SELECT id, transaction_date, narration, debit_amount, credit_amount, balance FROM bank_transactions ORDER BY id DESC LIMIT 50");
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		?>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead><tr><th>ID</th><th>Date</th><th>Narration</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['id']) ?></td>
                            <td><?= htmlspecialchars($r['transaction_date']) ?></td>
                            <td><?= htmlspecialchars($r['narration']) ?></td>
                            <td class="text-end"><?= number_format((float)$r['debit_amount'], 2) ?></td>
                            <td class="text-end"><?= number_format((float)$r['credit_amount'], 2) ?></td>
                            <td class="text-end"><?= $r['balance'] !== null ? number_format((float)$r['balance'], 2) : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No transactions imported yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>

