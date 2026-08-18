<?php
/**
 * Lightweight Excel/CSV reader for e-invoice uploads
 * Optimized for large files (5,000+ rows)
 */

function parseInvoiceFile(string $filePath): array {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'csv') return parseCSV($filePath);
    if (in_array($ext, ['xlsx', 'xls'])) return parseExcel($filePath);
    return ['success' => false, 'error' => 'Unsupported file format. Please upload CSV or Excel.'];
}

function parseCSV(string $filePath): array {
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['success' => false, 'error' => 'Cannot read file'];

    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); return ['success' => false, 'error' => 'File is empty']; }

    // Strip UTF-8 BOM (Excel exports) from first header
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $headers);
    $colCount = count($headers);

    // Stream row-by-row (memory safe for huge files)
    while (($data = fgetcsv($handle)) !== false) {
        // skip blank lines
        if (count(array_filter($data, fn($v) => $v !== null && $v !== '')) === 0) continue;
        if (count($data) === $colCount) {
            $rows[] = array_combine($headers, $data);
        }
    }
    fclose($handle);
    return ['success' => true, 'rows' => $rows, 'headers' => $headers];
}

function parseExcel(string $filePath): array {
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            if (count($rows) < 2) return ['success' => false, 'error' => 'File is empty'];
            $headers = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);
            $data = [];
            for ($i = 1; $i < count($rows); $i++) {
                if (count($rows[$i]) === count($headers)) $data[] = array_combine($headers, $rows[$i]);
            }
            return ['success' => true, 'rows' => $data, 'headers' => $headers];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Excel parse error: ' . $e->getMessage()];
        }
    }
    return parseCSV($filePath);
}

/** Normalize values BEFORE validation (fixes "1" vs "01" etc.) */
function normalizeInvoiceRecord(array $r): array {
    $r['document_type']  = str_pad(trim((string)($r['document_type'] ?? '01')), 2, '0', STR_PAD_LEFT);
    $ct = strtolower(trim((string)($r['customer_type'] ?? '')));
    $r['customer_type']  = in_array($ct, ['general', 'individual']) ? $ct : 'general';
    $r['sale_no']        = trim((string)($r['sale_no'] ?? ''));
    $r['customer_name']  = trim((string)($r['customer_name'] ?? ''));
    $r['customer_email'] = strtolower(trim((string)($r['customer_email'] ?? '')));
    $r['customer_ic']    = trim((string)($r['customer_ic'] ?? ''));
    $r['sale_title']     = trim((string)($r['sale_title'] ?? ''));
    return $r;
}

function validateInvoiceRecord(array $record): array {
    $errors = [];

    $mandatory = ['document_type','sale_no','customer_name','customer_email','sale_amount','sale_tax','total_amount'];
    foreach ($mandatory as $field) {
        if (!isset($record[$field]) || trim((string)$record[$field]) === '') {
            $errors[] = "Missing required field: $field";
        }
    }

    if (!empty($record['customer_email']) && !filter_var($record['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid customer_email format";
    }

    $amount = (float)($record['sale_amount'] ?? 0);
    $tax    = (float)($record['sale_tax'] ?? 0);
    $total  = (float)($record['total_amount'] ?? 0);

    if ($amount <= 0)            $errors[] = "sale_amount must be greater than 0";
    if ($tax < 0 || $total < 0)  $errors[] = "sale_tax / total_amount cannot be negative";
    if ($total > 0 && abs(($amount + $tax) - $total) > 0.01) {
        $errors[] = "total_amount ≠ sale_amount + sale_tax";
    }

    if (!in_array($record['document_type'] ?? '', ['01','02','03','04'])) {
        $errors[] = "Invalid document_type (must be 01-04)";
    }

    if (!empty($record['sale_datetime']) && strtotime((string)$record['sale_datetime']) === false) {
        $errors[] = "Invalid sale_datetime format";
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}
