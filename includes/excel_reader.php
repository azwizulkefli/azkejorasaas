<?php
/**
 * Lightweight Excel/CSV reader for e-invoice uploads
 * Supports CSV and basic XLSX (requires PhpSpreadsheet if available)
 */

function parseInvoiceFile(string $filePath): array {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($ext === 'csv') {
        return parseCSV($filePath);
    } elseif (in_array($ext, ['xlsx', 'xls'])) {
        return parseExcel($filePath);
    }
    
    return ['success' => false, 'error' => 'Unsupported file format. Please upload CSV or Excel.'];
}

function parseCSV(string $filePath): array {
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['success' => false, 'error' => 'Cannot read file'];
    
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return ['success' => false, 'error' => 'File is empty'];
    }
    
    // Normalize headers
    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);
    
    while (($data = fgetcsv($handle)) !== false) {
        if (count($data) === count($headers)) {
            $row = array_combine($headers, $data);
            $rows[] = $row;
        }
    }
    
    fclose($handle);
    return ['success' => true, 'rows' => $rows, 'headers' => $headers];
}

function parseExcel(string $filePath): array {
    // Try PhpSpreadsheet if available
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            
            if (count($rows) < 2) {
                return ['success' => false, 'error' => 'File is empty'];
            }
            
            $headers = array_map(fn($h) => strtolower(trim($h)), $rows[0]);
            $data = [];
            
            for ($i = 1; $i < count($rows); $i++) {
                if (count($rows[$i]) === count($headers)) {
                    $data[] = array_combine($headers, $rows[$i]);
                }
            }
            
            return ['success' => true, 'rows' => $data, 'headers' => $headers];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Excel parse error: ' . $e->getMessage()];
        }
    }
    
    // Fallback: treat as CSV
    return parseCSV($filePath);
}

function validateInvoiceRecord(array $record): array {
    $errors = [];
    
    // Mandatory fields
    $mandatory = [
        'document_type', 'sale_no', 'customer_name', 'customer_email',
        'sale_amount', 'sale_tax', 'total_amount'
    ];
    
    foreach ($mandatory as $field) {
        if (empty($record[$field] ?? '')) {
            $errors[] = "Missing required field: $field";
        }
    }
    
    // Validate email
    if (!empty($record['customer_email']) && !filter_var($record['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Validate amounts
    $amount = (float)($record['sale_amount'] ?? 0);
    $tax = (float)($record['sale_tax'] ?? 0);
    $total = (float)($record['total_amount'] ?? 0);
    
    if ($amount < 0 || $tax < 0 || $total < 0) {
        $errors[] = "Amounts cannot be negative";
    }
    
    // Validate document type
    if (!in_array($record['document_type'] ?? '', ['01', '02', '03', '04'])) {
        $errors[] = "Invalid document_type (must be 01-04)";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
