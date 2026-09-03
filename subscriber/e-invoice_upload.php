<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/excel_reader.php';
require_once __DIR__ . '/../includes/lhdn_submit.php';
require_once __DIR__ . '/../includes/myinvois.php'; // ✅ Added for automatic token refresh
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

/* ================= DB SCHEMA UPGRADE ================= */
$alterations = [
    "ADD COLUMN IF NOT EXISTS submission_type VARCHAR(50) DEFAULT 'consolidated'",
    "ADD COLUMN IF NOT EXISTS customer_tin VARCHAR(100)",
    "ADD COLUMN IF NOT EXISTS lhdn_status VARCHAR(50)",
    "ADD COLUMN IF NOT EXISTS lhdn_uuid VARCHAR(255)",
    "ADD COLUMN IF NOT EXISTS lhdn_submission_id VARCHAR(255)",
    "ADD COLUMN IF NOT EXISTS lhdn_long_id VARCHAR(255)",
    "ADD COLUMN IF NOT EXISTS lhdn_response TEXT"
];
foreach ($alterations as $alt) {
    try { $pdo->exec("ALTER TABLE einvoice_records $alt"); } catch (Exception $e) {}
}

/* ================= HELPERS ================= */
function logInternal($pdo, $submissionId, $step, $status, $message, $payload = null) {
    $stmt = $pdo->prepare("INSERT INTO einvoice_logs (submission_id, step, status, message, payload, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$submissionId, $step, $status, $message, $payload ? (is_array($payload) ? json_encode($payload) : $payload) : null]);
}

function lookupCustomerTIN($ic, $email, $baseUrl, $token) {
    $cleanIC = preg_replace('/[-\s]/', '', $ic);
    if (strlen($cleanIC) >= 12) return $cleanIC;
    return 'EI00000000010';
}

function findErrorInArray($arr) {
    if (!is_array($arr)) return null;
    foreach (['error', 'message', 'errorMessages', 'validationErrors', 'curl_error'] as $k) {
        if (isset($arr[$k]) && $arr[$k]) return is_string($arr[$k]) ? $arr[$k] : json_encode($arr[$k]);
    }
    foreach (['submission', 'details', 'raw'] as $k) {
        if (isset($arr[$k]) && is_array($arr[$k])) {
            $found = findErrorInArray($arr[$k]);
            if ($found) return $found;
        }
    }
    return null;
}

function extractLhdnError($rec) {
    if (in_array($rec['lhdn_status'] ?? '', ['Invalid', 'Error']) && !empty($rec['lhdn_response'])) {
        $r = json_decode($rec['lhdn_response'], true);
        $found = findErrorInArray($r);
        if ($found) return $found;
        return substr($rec['lhdn_response'], 0, 200);
    }
    return null;
}

function buildLHDNPayloads($record, $company, $jsonSendTemplate, $jsonConvertTemplate) {
    $map = [
        '*|ei_invoiceno|*'           => $record['sale_no'] ?? '',
        '*|ei_invoicedate|*'         => date('Y-m-d', strtotime($record['sale_datetime'])),
        '*|ei_invoicetype|*'         => $record['document_type'] ?? '03',
        '*|ei_invoicecurrency|*'     => 'MYR',
        '*|ei_msiccode|*'            => $company['msic_code'] ?? '',
        '*|ei_msicname|*'            => $company['business_type'] ?? '',
        '*|ei_suppliertin|*'         => $company['taxpayer_tin'] ?? '',
        '*|ei_suppliername|*'        => $company['name'] ?? '',
        '*|ei_supplieradd1|*'        => $company['address'] ?? '',
        '*|ei_supplierpostcode|*'    => $company['postcode'] ?? '',
        '*|ei_suppliertown|*'        => $company['town'] ?? '',
        '*|ei_supplierphone|*'       => $company['phone'] ?? '',
        '*|ei_supplieremail|*'       => $company['email'] ?? '',
        '*|ei_customertin|*'         => $record['customer_tin'] ?? 'EI00000000010',
        '*|ei_customername|*'        => $record['customer_name'] ?? 'Consolidated Sales',
        '*|ei_customeradd1|*'        => $record['customer_address'] ?? 'Multiple Customers',
        '*|ei_customerpostcode|*'    => $record['customer_postcode'] ?? '00000',
        '*|ei_customertown|*'        => $record['customer_town'] ?? 'N/A',
        '*|ei_customerphone|*'       => $record['customer_phone'] ?? '0000000000',
        '*|ei_customeremail|*'       => $record['customer_email'] ?? 'na@na.com',
        '*|ei_customeric|*'          => $record['customer_ic'] ?? '000000000000',
        '*|ei_invoicetotalamount|*'  => number_format((float)$record['total_amount'], 2, '.', ''),
        '*|ei_cninvoice_referenceno|*' => $record['reference_no'] ?? 'NA',
        '*|ei_cninvoice_uuid|*'      => $record['reference_uuid'] ?? 'NA'
    ];
    $jsonStr = str_replace(array_keys($map), array_values($map), $jsonSendTemplate);
    $base64Doc = base64_encode($jsonStr);
    $sha256 = hash('sha256', $jsonStr);
    $convertMap = [
        '*|ei_convertbase64|*'  => $base64Doc,
        '*|ei_convertsha256|*'  => $sha256,
        '*|ei_invoiceno|*'      => $record['sale_no']
    ];
    $convertStr = str_replace(array_keys($convertMap), array_values($convertMap), $jsonConvertTemplate);
    return ['send' => $jsonStr, 'convert' => $convertStr];
}

function submitCustomPayloadToLHDN($url, $invoiceData, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = is_array($invoiceData) ? json_encode($invoiceData) : $invoiceData;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'response' => $response, 'curl_error' => $err];
}

function getStatusFromLHDN($url, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'response' => $response];
}

function renderProgressDots($step, $status) {
    $labels = ['Build', 'Submit', 'Validate', 'Result'];
    $errState = in_array($status, ['Invalid', 'Error']);
    $out = '<div style="display:flex; align-items:center; gap:6px;">';
    for ($i = 1; $i <= 4; $i++) {
        $cls = '#e2e8f0';
        if ($errState && $i === 4) $cls = '#e11d48';
        elseif ($i <= $step) $cls = '#10b981';
        $out .= '<div style="display:flex; align-items:center; gap:4px;">';
        $out .= '<div style="width:10px; height:10px; border-radius:50%; background:'.$cls.'"></div>';
        $out .= '<span style="font-size:10px; font-weight:700; color:#94a3b8;">'.$labels[$i-1].'</span>';
        $out .= '</div>';
        if ($i < 4) $out .= '<div style="width:12px; height:1px; background:#e2e8f0;"></div>';
    }
    $out .= '</div>';
    return $out;
}

/* ================= AJAX ENDPOINTS (always valid JSON) ================= */
if (isset($_GET['ajax_action'])) {
    while (ob_get_level()) ob_end_clean();
    ini_set('display_errors', '0');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    try {
        /* ✅ NEW: paginated queue loader (keeps page light for 300+ rows) */
        if ($_GET['ajax_action'] === 'get_queue' && !empty($_GET['upload_id'])) {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE upload_id = ? AND user_id = ?");
            $cnt->execute([$_GET['upload_id'], $uid]);
            $total = (int)$cnt->fetchColumn();

            $r = $pdo->prepare("SELECT id, submission_type, sale_no, sale_datetime, customer_name, customer_ic, customer_phone, customer_email, total_amount, validation_status, validation_errors, lhdn_status, lhdn_uuid, lhdn_response FROM einvoice_records WHERE upload_id = ? AND user_id = ? ORDER BY CASE WHEN submission_type = 'individual' THEN 0 ELSE 1 END, sale_no LIMIT $perPage OFFSET $offset");
            $r->execute([$_GET['upload_id'], $uid]);
            $rows = $r->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$rw) { $rw['lhdn_error'] = extractLhdnError($rw); }
            unset($rw);

            echo json_encode(['total' => $total, 'page' => $page, 'per_page' => $perPage, 'rows' => $rows]);
            exit;
        }

        if ($_GET['ajax_action'] === 'get_stats' && !empty($_GET['upload_id'])) {
            $stmt = $pdo->prepare("SELECT 
                COUNT(*) FILTER (WHERE lhdn_status IS NULL OR lhdn_status = 'Pending') as pending,
                COUNT(*) FILTER (WHERE lhdn_status = 'Submitted' OR lhdn_status = 'In Progress') as submitted,
                COUNT(*) FILTER (WHERE lhdn_status = 'Valid') as valid,
                COUNT(*) FILTER (WHERE lhdn_status = 'Invalid') as invalid,
                COUNT(*) FILTER (WHERE lhdn_status = 'Error') as error,
                COUNT(*) as total
            FROM einvoice_records WHERE upload_id = ? AND user_id = ? AND validation_status = 'valid'");
            $stmt->execute([$_GET['upload_id'], $uid]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'pending'     => (int)$s['pending'],
                'submitted'   => (int)$s['submitted'],
                'valid'       => (int)$s['valid'],
                'invalid'     => (int)$s['invalid'],
                'error_count' => (int)$s['error'],
                'total'       => (int)$s['total'],
                'done'        => (int)$s['total'] - (int)$s['pending']
            ]);
            exit;
        }

        if ($_GET['ajax_action'] === 'process_next') {
            $stmtCompany = $pdo->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
            $stmtCompany->execute([$uid]);
            $company = $stmtCompany->fetch(PDO::FETCH_ASSOC);
            if (!$company) { echo json_encode(['done' => true, 'error' => 'Company profile not found.']); exit; }

            // ✅ FIX: Determine environment dynamically from companies table tokens/credentials
            $envIsProd = false;
            $prodValid = !empty($company['prod_token']) && !empty($company['prod_token_expiry']) && strtotime($company['prod_token_expiry']) > (time() + 60);
            $sandboxValid = !empty($company['sandbox_token']) && !empty($company['sandbox_token_expiry']) && strtotime($company['sandbox_token_expiry']) > (time() + 60);

            if ($prodValid && !$sandboxValid) {
                $envIsProd = true;
            } elseif (!$prodValid && $sandboxValid) {
                $envIsProd = false;
            } else {
                // Fallback: if both or neither are valid, prefer Prod if only Prod credentials exist, else Sandbox
                $envIsProd = (!empty($company['prod_clientid']) && empty($company['sandbox_clientid']));
            }

            $tokenCol = $envIsProd ? 'prod_token' : 'sandbox_token';
            $expiryCol = $envIsProd ? 'prod_token_expiry' : 'sandbox_token_expiry';
            $apiBaseUrl = $envIsProd ? 'https://api.myinvois.hasil.gov.my' : 'https://preprod-api.myinvois.hasil.gov.my';

            // ✅ Helper to refresh token automatically
            $refreshToken = function() use ($pdo, $uid, &$company, $tokenCol) {
                if (function_exists('myinvois_request_token')) {
                    $res = myinvois_request_token($pdo, $uid);
                    if ($res['ok']) {
                        $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
                        $c->execute([$uid]);
                        $company = $c->fetch(PDO::FETCH_ASSOC);
                        return $company[$tokenCol] ?? null;
                    }
                    return ['error' => $res['error'] ?? 'Token refresh failed'];
                }
                return ['error' => 'myinvois_request_token function not found.'];
            };

            $tokenValue = $company[$tokenCol] ?? null;
            $tokenExpiry = $company[$expiryCol] ?? null;

            // ✅ Proactive check: if empty or expires within 60 seconds
            if (empty($tokenValue) || empty($tokenExpiry) || strtotime($tokenExpiry) <= (time() + 60)) {
                $refreshResult = $refreshToken();
                if (is_array($refreshResult) && isset($refreshResult['error'])) {
                    echo json_encode(['done' => true, 'error' => 'Token expired and refresh failed: ' . $refreshResult['error']]);
                    exit;
                }
                $tokenValue = $refreshResult;
            }

            if (empty($tokenValue)) { 
                echo json_encode(['done' => true, 'error' => 'No LHDN access token. Generate one in Company & e-Invoice config.']); 
                exit; 
            }

            $stmtSend = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'json_send'");
            $stmtSend->execute(); $jsonSendTemplate = $stmtSend->fetchColumn();
            $stmtConvert = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'json_convert'");
            $stmtConvert->execute(); $jsonConvertTemplate = $stmtConvert->fetchColumn();
            if (!$jsonSendTemplate || !$jsonConvertTemplate) { echo json_encode(['done' => true, 'error' => 'JSON templates not configured in settings.']); exit; }

            /* ---- 1) INDIVIDUAL ---- */
            $stmt = $pdo->prepare("SELECT * FROM einvoice_records WHERE user_id = ? AND validation_status = 'valid' AND (lhdn_status IS NULL OR lhdn_status = 'Pending') AND submission_type = 'individual' ORDER BY id ASC LIMIT 1");
            $stmt->execute([$uid]);
            $indRec = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($indRec) {
                $tin = $indRec['customer_tin'];
                if (empty($tin) || $tin === 'EI00000000010') {
                    $tin = lookupCustomerTIN($indRec['customer_ic'], $indRec['customer_email'], $apiBaseUrl, $tokenValue);
                    $pdo->prepare("UPDATE einvoice_records SET customer_tin = ? WHERE id = ?")->execute([$tin, $indRec['id']]);
                    $indRec['customer_tin'] = $tin;
                }

                $payloads = buildLHDNPayloads($indRec, $company, $jsonSendTemplate, $jsonConvertTemplate);
                $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);
                
                // ✅ Reactive 401 check (Unauthorized) -> Refresh and retry once
                if ($submitResult['code'] == 401) {
                    $tokenValue = $refreshToken();
                    if ($tokenValue && !is_array($tokenValue)) {
                        $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);
                    }
                }

                $submitResponse = json_decode($submitResult['response'], true);
                $submissionUid = $submitResponse['submissionUid'] ?? null;
                $uuid = $submitResponse['acceptedDocuments'][0]['uuid'] ?? null;
                $lhdnStatus = ($submitResult['code'] >= 200 && $submitResult['code'] < 300) ? 'Submitted' : 'Error';

                $docStatus = $lhdnStatus; $longId = null;
                $combined = $submitResponse;
                if ($lhdnStatus === 'Submitted' && $uuid) {
                    sleep(2);
                    $statusResult = getStatusFromLHDN($apiBaseUrl . "/api/v1.0/documents/{$uuid}/details", $tokenValue);
                    $detailsResponse = json_decode($statusResult['response'], true);
                    $docStatus = $detailsResponse['status'] ?? 'In Progress';
                    $longId = $detailsResponse['longId'] ?? null;
                    $combined = ['submission' => $submitResponse, 'details' => $detailsResponse];
                }
                if ($lhdnStatus === 'Error' && !empty($submitResult['curl_error'])) {
                    $combined = ['curl_error' => $submitResult['curl_error'], 'raw' => $submitResult['response']];
                }
                $errMsg = findErrorInArray($combined);

                $pdo->prepare("UPDATE einvoice_records SET lhdn_status = ?, lhdn_uuid = ?, lhdn_submission_id = ?, lhdn_long_id = ?, lhdn_response = ? WHERE id = ?")
                    ->execute([$docStatus, $uuid, $submissionUid, $longId, json_encode($combined), $indRec['id']]);

                echo json_encode(['done' => false, 'type' => 'individual', 'id' => (string)$indRec['id'], 'sale_no' => $indRec['sale_no'], 'status' => $docStatus, 'uuid' => $uuid, 'submission_id' => $submissionUid, 'long_id' => $longId, 'error_msg' => $errMsg]);
                exit;
            }

            /* ---- 2) CONSOLIDATED ---- */
            $stmt = $pdo->prepare("SELECT DATE(sale_datetime) as sale_date FROM einvoice_records WHERE user_id = ? AND validation_status = 'valid' AND (lhdn_status IS NULL OR lhdn_status = 'Pending') AND submission_type = 'consolidated' GROUP BY DATE(sale_datetime) ORDER BY sale_date ASC LIMIT 1");
            $stmt->execute([$uid]);
            $dateRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($dateRow) {
                $saleDate = $dateRow['sale_date'];
                $stmtRec = $pdo->prepare("SELECT * FROM einvoice_records WHERE user_id = ? AND DATE(sale_datetime) = ? AND validation_status = 'valid' AND (lhdn_status IS NULL OR lhdn_status = 'Pending') AND submission_type = 'consolidated'");
                $stmtRec->execute([$uid, $saleDate]);
                $records = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

                $grandTotal = array_sum(array_column($records, 'total_amount'));
                $consolidatedData = [
                    'sale_no' => 'CONSOL-' . str_replace('-', '', $saleDate) . '-' . substr(md5($uid), 0, 8),
                    'customer_name' => 'Consolidated Sales', 'customer_address' => 'Multiple Customers',
                    'customer_email' => $company['email'] ?? ($me['email'] ?? 'na@na.com'),
                    'customer_phone' => $company['phone'] ?? '0000000000',
                    'customer_tin' => 'EI00000000010',
                    'customer_ic' => '000000000000', 'total_amount' => $grandTotal,
                    'sale_datetime' => $saleDate . ' 23:59:59', 'document_type' => '03'
                ];

                $payloads = buildLHDNPayloads($consolidatedData, $company, $jsonSendTemplate, $jsonConvertTemplate);
                $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);

                // ✅ Reactive 401 check (Unauthorized) -> Refresh and retry once
                if ($submitResult['code'] == 401) {
                    $tokenValue = $refreshToken();
                    if ($tokenValue && !is_array($tokenValue)) {
                        $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);
                    }
                }

                $submitResponse = json_decode($submitResult['response'], true);
                $submissionUid = $submitResponse['submissionUid'] ?? null;
                $uuid = $submitResponse['acceptedDocuments'][0]['uuid'] ?? null;
                $lhdnStatus = ($submitResult['code'] >= 200 && $submitResult['code'] < 300) ? 'Submitted' : 'Error';

                $docStatus = $lhdnStatus; $longId = null;
                $combined = $submitResponse;
                if ($lhdnStatus === 'Submitted' && $uuid) {
                    sleep(2);
                    $statusResult = getStatusFromLHDN($apiBaseUrl . "/api/v1.0/documents/{$uuid}/details", $tokenValue);
                    $detailsResponse = json_decode($statusResult['response'], true);
                    $docStatus = $detailsResponse['status'] ?? 'In Progress';
                    $longId = $detailsResponse['longId'] ?? null;
                    $combined = ['submission' => $submitResponse, 'details' => $detailsResponse];
                }
                if ($lhdnStatus === 'Error' && !empty($submitResult['curl_error'])) {
                    $combined = ['curl_error' => $submitResult['curl_error'], 'raw' => $submitResult['response']];
                }
                $errMsg = findErrorInArray($combined);

                $recordIds = array_map('strval', array_column($records, 'id'));
                $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
                $pdo->prepare("UPDATE einvoice_records SET lhdn_status = ?, lhdn_uuid = ?, lhdn_submission_id = ?, lhdn_long_id = ?, lhdn_response = ? WHERE id IN ($placeholders)")
                    ->execute(array_merge([$docStatus, $uuid, $submissionUid, $longId, json_encode($combined)], $recordIds));

                echo json_encode(['done' => false, 'type' => 'consolidated', 'date' => $saleDate, 'ids' => $recordIds, 'status' => $docStatus, 'uuid' => $uuid, 'submission_id' => $submissionUid, 'long_id' => $longId, 'error_msg' => $errMsg]);
                exit;
            }

            /* nothing left → save final summary into session for e-invoice_summary.php */
            $upId = $_GET['upload_id'] ?? '';
            if ($upId !== '') {
                $st2 = $pdo->prepare("SELECT 
                    COUNT(*) FILTER (WHERE lhdn_status = 'Submitted' OR lhdn_status = 'In Progress') as submitted,
                    COUNT(*) FILTER (WHERE lhdn_status = 'Valid') as valid,
                    COUNT(*) FILTER (WHERE lhdn_status = 'Invalid') as invalid,
                    COUNT(*) FILTER (WHERE lhdn_status = 'Error') as error
                FROM einvoice_records WHERE upload_id = ? AND user_id = ? AND validation_status = 'valid'");
                $st2->execute([$upId, $uid]);
                $fs = $st2->fetch(PDO::FETCH_ASSOC);
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $_SESSION['einvoice_summary'] = [
                    'submitted'   => (int)$fs['submitted'],
                    'in_progress' => 0,
                    'valid'       => (int)$fs['valid'],
                    'invalid'     => (int)$fs['invalid'],
                    'error'       => (int)$fs['error']
                ];
                session_write_close();
            }

            echo json_encode(['done' => true]);
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['done' => true, 'error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

/* ================= HANDLE FILE UPLOAD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['invoice_file'])) {
    set_time_limit(600); ini_set('memory_limit', '512M');
    $uploadDir = __DIR__ . '/../storage/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $file = $_FILES['invoice_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $filename = $uid . '_' . time() . '.' . $ext;
            $filePath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $result = parseInvoiceFile($filePath);
                if ($result['success'] && !empty($result['rows'])) {
                    $pdo->beginTransaction();
                    try {
                        $stmtUpload = $pdo->prepare("INSERT INTO einvoice_uploads (user_id, filename, file_path, total_records, status) VALUES (?, ?, ?, ?, 'processing') RETURNING id");
                        $stmtUpload->execute([$uid, $file['name'], $filename, count($result['rows'])]);
                        $uploadId = $stmtUpload->fetchColumn();

                        $insertRec = $pdo->prepare("INSERT INTO einvoice_records (
                            upload_id, user_id, document_type, sale_no, customer_name, customer_address, customer_postcode, 
                            customer_phone, customer_email, customer_ic, customer_type, sale_title, sale_amount, sale_tax, 
                            total_amount, sale_datetime, validation_status, validation_errors, submission_type, customer_tin, lhdn_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)");

                        $validCount = 0; $invalidCount = 0;
                        foreach ($result['rows'] as $row) {
                            $row = normalizeInvoiceRecord($row);
                            $validation = validateInvoiceRecord($row);
                            $status = $validation['valid'] ? 'valid' : 'invalid';
                            $errors = $validation['valid'] ? null : json_encode($validation['errors']);

                            $email = $row['customer_email'] ?? '';
                            $ic = $row['customer_ic'] ?? '';
                            $isEmailValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                            $isICValid = preg_match('/^\d{12}$/', preg_replace('/[-\s]/', '', $ic));

                            if ($isEmailValid && $isICValid) {
                                $submissionType = 'individual';
                                $customerTin = lookupCustomerTIN($ic, $email, '', '');
                            } else {
                                $submissionType = 'consolidated';
                                $customerTin = 'EI00000000010';
                            }

                            $insertRec->execute([
                                $uploadId, $uid, $row['document_type'] ?? '01', $row['sale_no'] ?? '', $row['customer_name'] ?? '', $row['customer_address'] ?? '', 
                                $row['customer_postcode'] ?? '', $row['customer_phone'] ?? '', $email, $ic, 
                                $row['customer_type'] ?? 'general', $row['sale_title'] ?? '', (float)($row['sale_amount'] ?? 0), (float)($row['sale_tax'] ?? 0), 
                                (float)($row['total_amount'] ?? 0), $row['sale_datetime'] ?? date('Y-m-d H:i:s'), $status, $errors, $submissionType, $customerTin
                            ]);
                            $validation['valid'] ? $validCount++ : $invalidCount++;
                        }
                        $pdo->prepare("UPDATE einvoice_uploads SET valid_records = ?, invalid_records = ?, status = 'completed' WHERE id = ?")->execute([$validCount, $invalidCount, $uploadId]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        header("Location: e-invoice_upload.php?err=" . urlencode('Upload failed: ' . $e->getMessage())); exit;
                    }
                    header("Location: e-invoice_upload.php?upload=" . urlencode($uploadId)); exit;
                } else { 
                    header("Location: e-invoice_upload.php?err=" . urlencode($result['error'] ?? 'No data rows.')); exit; 
                }
            }
        } else { 
            header("Location: e-invoice_upload.php?err=Invalid file format."); exit; 
        }
    }
}

/* ================= HANDLE DELETE / RESET ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_upload') {
        $uploadId = $_POST['upload_id'] ?? null;
        if ($uploadId) {
            $stmt = $pdo->prepare("SELECT file_path FROM einvoice_uploads WHERE id = ? AND user_id = ?");
            $stmt->execute([$uploadId, $uid]);
            $upload = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($upload) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM einvoice_records WHERE upload_id = ?")->execute([$uploadId]);
                    if (!empty($upload['file_path'])) {
                        $fullPath = __DIR__ . '/../storage/uploads/' . $upload['file_path'];
                        if (file_exists($fullPath)) unlink($fullPath);
                    }
                    $pdo->prepare("DELETE FROM einvoice_uploads WHERE id = ?")->execute([$uploadId]);
                    $pdo->commit();
                    header("Location: e-invoice_upload.php?msg=" . urlencode('Upload deleted.')); exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    header("Location: e-invoice_upload.php?err=" . urlencode('Delete failed: ' . $e->getMessage())); exit;
                }
            }
        }
    }
    if ($_POST['action'] === 'reset_upload') {
        $uploadId = $_POST['upload_id'] ?? null;
        if ($uploadId) {
            $pdo->prepare("UPDATE einvoice_records SET lhdn_status = NULL, lhdn_uuid = NULL, lhdn_submission_id = NULL, lhdn_long_id = NULL, lhdn_response = NULL WHERE upload_id = ? AND user_id = ?")
                ->execute([$uploadId, $uid]);
            header("Location: e-invoice_upload.php?upload=" . urlencode($uploadId)); exit;
        }
    }
}

/* ================= LOAD DATA (lightweight: aggregates only, no row rendering) ================= */
$uploads = $pdo->prepare("SELECT * FROM einvoice_uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$uploads->execute([$uid]); 
$uploadsList = $uploads->fetchAll(PDO::FETCH_ASSOC);

$currentUpload = null;
$recTotal = 0;
$agg = ['valid' => 0, 'invalid' => 0, 'amount' => 0, 'submittable' => 0];
$lhdnStats = ['pending' => 0, 'submitted' => 0, 'valid' => 0, 'invalid' => 0, 'error' => 0, 'total' => 0, 'done' => 0];

if (isset($_GET['upload'])) {
    $stmt = $pdo->prepare("SELECT * FROM einvoice_uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['upload'], $uid]); 
    $currentUpload = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentUpload) {
        $a = $pdo->prepare("SELECT COUNT(*) FILTER (WHERE validation_status='valid') AS valid, COUNT(*) FILTER (WHERE validation_status='invalid') AS invalid, COALESCE(SUM(total_amount),0) AS amount, COUNT(*) FILTER (WHERE validation_status='valid' AND (lhdn_status IS NULL OR lhdn_status = 'Pending')) AS submittable FROM einvoice_records WHERE upload_id = ?");
        $a->execute([$currentUpload['id']]); 
        $agg = $a->fetch(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT 
            COUNT(*) FILTER (WHERE lhdn_status IS NULL OR lhdn_status = 'Pending') as pending,
            COUNT(*) FILTER (WHERE lhdn_status = 'Submitted' OR lhdn_status = 'In Progress') as submitted,
            COUNT(*) FILTER (WHERE lhdn_status = 'Valid') as valid,
            COUNT(*) FILTER (WHERE lhdn_status = 'Invalid') as invalid,
            COUNT(*) FILTER (WHERE lhdn_status = 'Error') as error,
            COUNT(*) as total
        FROM einvoice_records WHERE upload_id = ? AND validation_status = 'valid'");
        $st->execute([$currentUpload['id']]);
        $lhdnStats = $st->fetch(PDO::FETCH_ASSOC);
        $lhdnStats['done'] = ($lhdnStats['total'] - $lhdnStats['pending']);

        $recTotal = (int)$currentUpload['total_records'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload E-Invoice — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink)}a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}.loading-overlay.active{display:grid}.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}@keyframes spin{to{transform:rotate(360deg)}}.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted);text-align:center}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}.sidebar-nav{display:flex;flex-direction:column;gap:4px}.menu-section{margin-top:16px;padding:0 8px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.15s}.menu-item:hover{background:#f8fafc;color:var(--ink)}.menu-item.active{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}
.main-wrapper{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;gap:12px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}.brand em{font-style:normal;color:var(--brand)}.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted);flex-wrap:wrap}.menu-toggle{display:none;background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink);padding:4px}.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;overflow:hidden}.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}h1{font-size:28px;font-weight:800;letter-spacing:-.02em}.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}.banner.success{background:#d1fae5;color:#059669}.banner.error{background:#ffe4e6;color:#e11d48}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}.card h2{font-size:20px;font-weight:800;margin-bottom:4px}.card .msub{font-size:13px;color:var(--muted);margin-bottom:20px}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px}.step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;text-align:center}.step h3{font-size:16px;font-weight:700;margin-bottom:8px}.step p{font-size:13px;color:var(--muted);line-height:1.5}
.upload-zone{border:2px dashed var(--line);border-radius:16px;padding:48px;text-align:center;transition:.2s;cursor:pointer}.upload-zone:hover{border-color:var(--brand);background:#f8fafc}.upload-zone.dragover{border-color:var(--brand);background:#e0e5ff}.upload-icon{width:64px;height:64px;border-radius:16px;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:28px;margin:0 auto 16px}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none}.btn.primary{background:var(--grad);color:#fff;box-shadow:0 10px 24px -8px rgba(84,87,229,.5)}.btn.ghost{background:#f1f5f9;color:#475569}.btn.ghost:hover{background:#e2e8f0}.btn.success{background:#d1fae5;color:#059669}
table{width:100%;border-collapse:collapse;font-size:14px}th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}td{padding:12px 16px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:top}tbody tr:hover{background:#f8fafc}
.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.badge.valid{background:#d1fae5;color:#059669}.badge.invalid{background:#ffe4e6;color:#e11d48}.badge.pending{background:#fef3c7;color:#d97706}.badge.submitted{background:#dbeafe;color:#3b82f6}.badge.failed{background:#ffe4e6;color:#e11d48}.badge.consolidated{background:#fef3c7;color:#d97706}.badge.individual{background:#e0e5ff;color:#4644cf}
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px}.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center}.summary-card b{display:block;font-size:28px;font-weight:800;color:var(--brand)}.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
.submit-box{margin-top:20px;background:#f5f6ff;border:1px solid #c6ceff;border-radius:16px;padding:20px}.submit-box h3{font-size:16px;font-weight:700;margin-bottom:6px}.submit-box p{font-size:13px;color:var(--muted);margin-bottom:16px}
.live-log{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:14px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;line-height:1.7;max-height:220px;overflow-y:auto;margin-top:16px}
.live-log .ok{color:#34d399}.live-log .err{color:#f87171}.live-log .info{color:#93c5fd}
.subline{font-size:10px;color:var(--faint);line-height:1.5;margin-top:3px;word-break:break-all}
.errline{font-size:10px;color:#e11d48;line-height:1.4;margin-top:4px;max-width:240px;word-break:break-word}
.pager{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0 0;font-size:13px;color:var(--muted);flex-wrap:wrap}
.pager nav{display:flex;gap:8px}
.pbtn{border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;background:#f1f5f9;color:#475569;border:none;cursor:pointer}
.pbtn:hover{background:#e2e8f0}.pbtn.off{opacity:.4;pointer-events:none}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}.pulsing{animation:pulse 1.2s infinite}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.sidebar-overlay.open{display:block}.main-wrapper{margin-left:0}.menu-toggle{display:block}.steps{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.main{padding:20px 12px}h1{font-size:22px}.summary-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay"><div><div class="spinner"></div><p class="spinner-text">Uploading & Validating…</p></div></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span></div>
  <nav class="sidebar-nav">
    <a href="main.php" class="menu-item">🏠 Home</a>
    <a href="e-invoice.php" class="menu-item active">🧾 E-Invoice</a>
    <div class="menu-section">Submission</div>
    <a href="e-invoice_upload.php" class="menu-item">📤 Upload</a>
    <a href="e-invoice_manual.php" class="menu-item">✍️ Manual Entry</a>
    <div class="menu-section">Setup</div>
    <a href="company.php" class="menu-item">🏢 Company</a>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('open');"></div>
<div class="main-wrapper">
  <nav class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('open');">☰</button>
      <a href="e-invoice.php" class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></a>
    </div>
    <div class="top-right">
      <span>Welcome, <b><?= htmlspecialchars(explode(' ', $me['name'])[0]) ?></b></span>
      <span class="avatar"><?= strtoupper(substr($me['name'],0,1)) ?></span>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <h1>Upload E-Invoice 📤</h1>
    <p class="sub">Bulk upload your invoices from Excel or CSV files.</p>

    <?php if (isset($_GET['err'])): ?>
      <div class="banner error">✗ <?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['msg'])): ?>
      <div class="banner success">✓ <?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>

    <div class="steps">
      <div class="step"><h3>1. Download Template</h3><p>Download our Excel template with all required fields pre-formatted.</p><a href="einvoice_datatemplate.csv" class="btn ghost" style="margin-top:12px">📥 Download</a></div>
      <div class="step"><h3>2. Fill Your Data</h3><p>Add your invoice records. Ensure all mandatory fields are filled correctly.</p></div>
      <div class="step"><h3>3. Upload & Submit</h3><p>Upload the file, review validation, then process submission to LHDN MyInvois.</p></div>
    </div>

    <?php if (!$currentUpload): ?>
      <div class="card">
        <h2>Upload Invoice File</h2>
        <p class="msub">Select your completed Excel or CSV file to begin validation.</p>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
          <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <div class="upload-icon">📤</div>
            <h3 style="font-size:18px;font-weight:700;margin-bottom:8px">Drop your file here</h3>
            <p style="color:var(--muted);margin-bottom:16px">or click to browse</p>
            <p style="font-size:12px;color:var(--faint)">Supports CSV, XLSX, XLS · Max 10MB</p>
            <input type="file" id="fileInput" name="invoice_file" accept=".csv,.xlsx,.xls" style="display:none" required>
          </div>
          <div id="fileInfo" style="margin-top:16px;display:none">
            <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#f8fafc;border-radius:10px">
              <span style="font-size:24px">📄</span>
              <div style="flex:1"><p style="font-weight:600" id="fileName"></p><p style="font-size:12px;color:var(--faint)" id="fileSize"></p></div>
              <button type="submit" class="btn primary">Upload & Validate</button>
            </div>
          </div>
        </form>
      </div>

      <?php if (!empty($uploadsList)): ?>
        <div class="card">
          <h2>Recent Uploads</h2>
          <table>
            <thead><tr><th>Filename</th><th>Date</th><th>Total</th><th>Valid</th><th>Invalid</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($uploadsList as $up): ?>
                <tr>
                  <td><b><?= htmlspecialchars($up['filename']) ?></b></td>
                  <td><?= date('M d, H:i', strtotime($up['created_at'])) ?></td>
                  <td><?= $up['total_records'] ?></td>
                  <td style="color:#059669"><?= $up['valid_records'] ?></td>
                  <td style="color:#e11d48"><?= $up['invalid_records'] ?></td>
                  <td><span class="badge <?= $up['status'] ?>"><?= $up['status'] ?></span></td>
                  <td>
                    <a href="?upload=<?= urlencode($up['id']) ?>" class="btn ghost" style="padding:6px 12px;font-size:11px">View</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this upload and all records?');">
                      <input type="hidden" name="action" value="delete_upload">
                      <input type="hidden" name="upload_id" value="<?= htmlspecialchars($up['id']) ?>">
                      <button type="submit" class="btn ghost" style="padding:6px 12px;font-size:11px;color:#e11d48">🗑️</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="card">
        <h2>Validation Results</h2>
        <p class="msub"><?= htmlspecialchars($currentUpload['filename']) ?> · Uploaded <?= date('M d, H:i', strtotime($currentUpload['created_at'])) ?></p>
        <div class="summary-grid" style="grid-template-columns: repeat(4, 1fr);">
          <div class="summary-card"><b><?= $recTotal ?></b><p>Total Records</p></div>
          <div class="summary-card"><b style="color:#059669"><?= $agg['valid'] ?></b><p>Verified</p></div>
          <div class="summary-card"><b style="color:#e11d48"><?= $agg['invalid'] ?></b><p>Failed</p></div>
          <div class="summary-card"><b><?= number_format((float)$agg['amount'], 2) ?></b><p>Total Amount (RM)</p></div>
        </div>
      </div>

      <?php if ((int)$agg['submittable'] > 0 || $lhdnStats['total'] > 0): ?>
        <div id="processor-dashboard">
            <div class="summary-grid">
                <div class="summary-card"><b id="stat-pending" style="color:#64748b"><?= $lhdnStats['pending'] ?></b><p>Pending</p></div>
                <div class="summary-card"><b id="stat-submitted" style="color:#3b82f6"><?= $lhdnStats['submitted'] ?></b><p>Submitted</p></div>
                <div class="summary-card"><b id="stat-valid" style="color:#059669"><?= $lhdnStats['valid'] ?></b><p>Valid</p></div>
                <div class="summary-card"><b id="stat-invalid" style="color:#e11d48"><?= $lhdnStats['invalid'] ?></b><p>Invalid</p></div>
                <div class="summary-card"><b id="stat-error" style="color:#9f1239"><?= $lhdnStats['error'] ?></b><p>Error</p></div>
            </div>

            <div class="card" style="padding:16px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700; color:#64748b; margin-bottom:8px;">
                    <span>Overall LHDN progress</span>
                    <span id="progress-text"><?= $lhdnStats['done'] ?> / <?= $lhdnStats['total'] ?> transmitted</span>
                </div>
                <div style="height:10px; overflow:hidden; border-radius:999px; background:#e2e8f0;">
                    <div id="progress-bar" style="height:100%; border-radius:999px; background:#10b981; transition:all 0.3s; width:<?= $lhdnStats['total'] > 0 ? round(($lhdnStats['done'] / $lhdnStats['total']) * 100) : 0 ?>%"></div>
                </div>
            </div>

            <?php if ($lhdnStats['pending'] > 0): ?>
            <div class="submit-box">
                <h3>🚀 Start Auto-Process</h3>
                <p>System will process Individual invoices first, then Consolidated batches. Live updates below.</p>
                <button id="btnProcess" class="btn success" onclick="startProcessing()">▶ Start Auto-Process</button>
                <form method="POST" style="display:inline; margin-left:8px" onsubmit="return confirm('Reset all LHDN statuses for this upload to Pending?');">
                    <input type="hidden" name="action" value="reset_upload">
                    <input type="hidden" name="upload_id" value="<?= htmlspecialchars($currentUpload['id']) ?>">
                    <button type="submit" class="btn ghost">🔄 Reset</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- ✅ Queue hidden by default; rows lazy-loaded 50/page via AJAX -->
            <div class="card" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                    <h2>Submission Queue <span style="font-size:12px;color:var(--faint);font-weight:600">(<?= $recTotal ?> records — hidden for fast loading)</span></h2>
                    <button class="btn ghost" id="btnToggleQueue" onclick="toggleQueue()">👁 Show Queue</button>
                </div>

                <div id="queueWrap" style="display:none; margin-top:16px;">
                    <div style="overflow-x:auto;">
                        <table style="min-width:1200px">
                            <thead>
                                <tr>
                                    <th>Type</th><th>Sale No / Date</th><th>Customer</th><th>Total</th>
                                    <th>Validation</th><th>Progress</th><th>LHDN Status</th><th>UUID</th>
                                </tr>
                            </thead>
                            <tbody id="queue-body"></tbody>
                        </table>
                    </div>
                    <div class="pager">
                        <span id="queuePagerInfo">—</span>
                        <nav>
                            <button class="pbtn off" id="qPrev" onclick="loadQueue(queuePage - 1)">← Prev</button>
                            <button class="pbtn off" id="qNext" onclick="loadQueue(queuePage + 1)">Next →</button>
                        </nav>
                    </div>
                </div>

                <div class="live-log" id="liveLog"><div class="info">[ready] Waiting to start…</div></div>
            </div>
        </div>
      <?php endif; ?>

      <div style="margin-top:24px; display:flex; gap:12px; flex-wrap: wrap;">
          <a href="e-invoice_upload.php" class="btn ghost">← Upload another file</a>
      </div>
    <?php endif; ?>
  </main>
</div>

<script>
function el(id){ return document.getElementById(id); }

var dropZone = el('dropZone'), fileInput = el('fileInput'), fileInfo = el('fileInfo');
if (fileInput) {
    fileInput.addEventListener('change', function(e){
        var f = e.target.files[0];
        if (f && fileInfo) {
            el('fileName').textContent = f.name;
            el('fileSize').textContent = (f.size/1024).toFixed(2) + ' KB';
            fileInfo.style.display = 'block';
            dropZone.style.display = 'none';
        }
    });
}
if (dropZone) {
    ['dragenter','dragover'].forEach(function(evt){ dropZone.addEventListener(evt, function(e){ e.preventDefault(); dropZone.classList.add('dragover'); }); });
    ['dragleave','drop'].forEach(function(evt){ dropZone.addEventListener(evt, function(e){ e.preventDefault(); dropZone.classList.remove('dragover'); }); });
    dropZone.addEventListener('drop', function(e){
        var files = e.dataTransfer.files;
        if (files.length > 0 && fileInput) { fileInput.files = files; fileInput.dispatchEvent(new Event('change')); }
    });
}
var overlay = el('loadingOverlay');
var uploadForm = el('uploadForm');
if (uploadForm) uploadForm.addEventListener('submit', function(){ overlay.classList.add('active'); });

var uploadId = <?= json_encode($currentUpload['id'] ?? '') ?>;

/* ================= QUEUE STATE (lazy load) ================= */
var queueOpen = false, queuePage = 1, queuePages = 1, queueLoading = false;

function logMsg(msg, cls) {
    var log = el('liveLog');
    if (!log) return;
    var t = new Date();
    var hh = ('0'+t.getHours()).slice(-2), mm = ('0'+t.getMinutes()).slice(-2), ss = ('0'+t.getSeconds()).slice(-2);
    var div = document.createElement('div');
    div.className = cls || 'info';
    div.textContent = '[' + hh + ':' + mm + ':' + ss + '] ' + msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = (s == null) ? '' : String(s);
    return div.innerHTML;
}

function renderProgressDots(step, status) {
    var labels = ['Build', 'Submit', 'Validate', 'Result'];
    var errState = (status === 'Invalid' || status === 'Error');
    var out = '<div style="display:flex; align-items:center; gap:6px;">';
    for (var i = 1; i <= 4; i++) {
        var cls = '#e2e8f0';
        if (errState && i === 4) cls = '#e11d48';
        else if (i <= step) cls = '#10b981';
        out += '<div style="display:flex; align-items:center; gap:4px;">' +
               '<div style="width:10px; height:10px; border-radius:50%; background:' + cls + '"></div>' +
               '<span style="font-size:10px; font-weight:700; color:#94a3b8;">' + labels[i-1] + '</span></div>';
        if (i < 4) out += '<div style="width:12px; height:1px; background:#e2e8f0;"></div>';
    }
    out += '</div>';
    return out;
}

function renderQueueRow(rec) {
    var type = rec.submission_type || 'consolidated';
    var lhdnStatus = rec.lhdn_status || '';
    var badgeClass = 'pending';
    if (lhdnStatus === 'Valid') badgeClass = 'valid';
    else if (lhdnStatus === 'Invalid') badgeClass = 'invalid';
    else if (lhdnStatus === 'Submitted' || lhdnStatus === 'In Progress') badgeClass = 'submitted';
    else if (lhdnStatus === 'Error') badgeClass = 'failed';
    var step = 1;
    if (lhdnStatus === 'Submitted') step = 2;
    if (lhdnStatus === 'In Progress') step = 3;
    if (lhdnStatus === 'Valid' || lhdnStatus === 'Invalid' || lhdnStatus === 'Error') step = 4;

    var valErr = '';
    if (rec.validation_status === 'invalid' && rec.validation_errors) {
        try {
            var errs = JSON.parse(rec.validation_errors);
            if (errs && errs.length) valErr = '<div class="errline">• ' + escapeHtml(errs.join(' • ')) + '</div>';
        } catch(e) {}
    }
    var lhdnErr = rec.lhdn_error ? '<div class="errline">' + escapeHtml(rec.lhdn_error) + '</div>' : '';
    var dateStr = rec.sale_datetime ? String(rec.sale_datetime).substring(0, 16) : '—';

    return '<tr data-id="' + escapeHtml(rec.id) + '">' +
        '<td><span class="badge ' + type + '">' + type.charAt(0).toUpperCase() + type.slice(1) + '</span></td>' +
        '<td><b>' + escapeHtml(rec.sale_no) + '</b><div class="subline">' + dateStr + '</div></td>' +
        '<td>' + escapeHtml(rec.customer_name) +
            '<div class="subline">IC: ' + escapeHtml(rec.customer_ic || '—') + '<br>Tel: ' + escapeHtml(rec.customer_phone || '—') + '<br>Email: ' + escapeHtml(rec.customer_email || '—') + '</div></td>' +
        '<td><b>RM ' + Number(rec.total_amount || 0).toFixed(2) + '</b></td>' +
        '<td><span class="badge ' + escapeHtml(rec.validation_status) + '">' + escapeHtml(rec.validation_status) + '</span>' + valErr + '</td>' +
        '<td class="col-progress">' + renderProgressDots(step, lhdnStatus) + '</td>' +
        '<td class="col-status"><span class="badge ' + badgeClass + '">' + (lhdnStatus || 'Pending') + '</span>' + lhdnErr + '</td>' +
        '<td class="col-uuid" style="font-family:monospace;font-size:11px">' + escapeHtml(rec.lhdn_uuid || '—') + '</td>' +
    '</tr>';
}

function toggleQueue() {
    var wrap = el('queueWrap');
    var btn = el('btnToggleQueue');
    if (!wrap || !btn) return;
    queueOpen = !queueOpen;
    wrap.style.display = queueOpen ? 'block' : 'none';
    btn.innerHTML = queueOpen ? '🙈 Hide Queue' : '👁 Show Queue';
    if (queueOpen) loadQueue(1);
}

function loadQueue(page) {
    if (queueLoading || page < 1) return;
    queueLoading = true;
    fetch('e-invoice_upload.php?ajax_action=get_queue&upload_id=' + encodeURIComponent(uploadId) + '&page=' + page, {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            queueLoading = false;
            if (d.error) { logMsg('Queue error: ' + d.error, 'err'); return; }
            queuePage = d.page;
            queuePages = Math.max(1, Math.ceil(d.total / d.per_page));
            var html = '';
            for (var i = 0; i < d.rows.length; i++) html += renderQueueRow(d.rows[i]);
            if (!d.rows.length) html = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--faint)">No records.</td></tr>';
            el('queue-body').innerHTML = html;
            el('queuePagerInfo').textContent = 'Page ' + queuePage + ' of ' + queuePages + ' · ' + d.total + ' records';
            el('qPrev').classList.toggle('off', queuePage <= 1);
            el('qNext').classList.toggle('off', queuePage >= queuePages);
        })
        .catch(function(e){
            queueLoading = false;
            logMsg('Queue load failed: ' + e.message, 'err');
        });
}

function updateRow(id, data) {
    var row = document.querySelector('tr[data-id="' + id + '"]');
    if (!row) return; // queue hidden / row not on current page → skip silently
    var statusCell = row.querySelector('.col-status');
    var uuidCell = row.querySelector('.col-uuid');
    var progressCell = row.querySelector('.col-progress');

    var badgeClass = 'pending', step = 1;
    if (data.status === 'Submitted' || data.status === 'In Progress') { badgeClass = 'submitted'; step = 2; }
    if (data.status === 'Valid') { badgeClass = 'valid'; step = 4; }
    if (data.status === 'Invalid') { badgeClass = 'invalid'; step = 4; }
    if (data.status === 'Error') { badgeClass = 'failed'; step = 4; }

    if (statusCell) {
        var errHtml = '';
        if (data.error_msg) errHtml = '<div class="errline">' + escapeHtml(data.error_msg) + '</div>';
        statusCell.innerHTML = '<span class="badge ' + badgeClass + '">' + escapeHtml(data.status) + '</span>' + errHtml;
    }
    if (uuidCell) uuidCell.textContent = data.uuid || '—';
    if (progressCell) progressCell.innerHTML = renderProgressDots(step, data.status);
}

function updateStats() {
    if (!uploadId) return;
    fetch('e-invoice_upload.php?ajax_action=get_stats&upload_id=' + encodeURIComponent(uploadId), {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(s){
            if (s.error) { logMsg('Stats error: ' + s.error, 'err'); return; }
            el('stat-pending').textContent   = s.pending;
            el('stat-submitted').textContent = s.submitted;
            el('stat-valid').textContent     = s.valid;
            el('stat-invalid').textContent   = s.invalid;
            el('stat-error').textContent     = s.error_count;
            var done = s.done || 0;
            var total = s.total || 0;
            el('progress-text').textContent = done + ' / ' + total + ' transmitted';
            var pct = total > 0 ? Math.round((done / total) * 100) : 0;
            el('progress-bar').style.width = pct + '%';
        })
        .catch(function(e){ logMsg('Stats refresh failed: ' + e.message, 'err'); });
}

function startProcessing() {
    var btn = el('btnProcess');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="pulsing">⏳</span> Processing Queue…';
    logMsg('Auto-process started. Priority: Individual → Consolidated.', 'info');

    function stop(label) {
        btn.innerHTML = label;
        btn.disabled = false;
    }

    function next() {
        fetch('e-invoice_upload.php?ajax_action=process_next&upload_id=' + encodeURIComponent(uploadId), {cache:'no-store'})
            .then(function(r){ return r.text(); })
            .then(function(text){
                var data;
                try { data = JSON.parse(text); }
                catch (e) {
                    logMsg('Invalid JSON from server: ' + text.substring(0, 300), 'err');
                    stop('⚠️ Server Error');
                    return;
                }

                if (data.error) {
                    logMsg('Server error: ' + data.error, 'err');
                    stop('⚠️ Stopped');
                    updateStats();
                    return;
                }

                if (data.done) {
                    logMsg('✅ All jobs processed. Redirecting to summary…', 'ok');
                    stop('✅ All Processed');
                    updateStats();
                    if (queueOpen) loadQueue(queuePage); // refresh visible page before leaving
                    setTimeout(function(){ window.location.href = 'e-invoice_summary.php'; }, 2000);
                    return;
                }

                if (data.type === 'individual') {
                    var cls = (data.status === 'Error' || data.status === 'Invalid') ? 'err' : 'ok';
                    logMsg('Individual [' + (data.sale_no || '#' + data.id) + '] → ' + data.status +
                           (data.uuid ? ' (uuid: ' + String(data.uuid).substring(0, 12) + '…)' : '') +
                           (data.error_msg ? ' | ' + data.error_msg : ''), cls);
                    updateRow(data.id, data);
                }

                if (data.type === 'consolidated') {
                    var cls2 = (data.status === 'Error' || data.status === 'Invalid') ? 'err' : 'ok';
                    logMsg('Consolidated [' + data.date + '] (' + data.ids.length + ' records) → ' + data.status +
                           (data.uuid ? ' (uuid: ' + String(data.uuid).substring(0, 12) + '…)' : '') +
                           (data.error_msg ? ' | ' + data.error_msg : ''), cls2);
                    for (var i = 0; i < data.ids.length; i++) updateRow(data.ids[i], data);
                }

                updateStats();
                setTimeout(function(){ next(); }, 500);
            })
            .catch(function(e){
                logMsg('Network/fetch error: ' + e.message, 'err');
                stop('❌ Network Error');
            });
    }

    next();
}
</script>
</body>
</html>
