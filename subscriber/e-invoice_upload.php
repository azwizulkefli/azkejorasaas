<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/excel_reader.php';
require_once __DIR__ . '/../includes/lhdn_submit.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

/* ================= HELPER: Internal Logging ================= */
function logInternal($pdo, $submissionId, $step, $status, $message, $payload = null) {
    $stmt = $pdo->prepare("INSERT INTO einvoice_logs (submission_id, step, status, message, payload, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$submissionId, $step, $status, $message, $payload ? (is_array($payload) ? json_encode($payload) : $payload) : null]);
}

/* ================= HELPER: Build LHDN JSON Payloads ================= */
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

/* ================= HELPER: cURL API Calls (Custom Payload) ================= */
// ✅ FIX: Renamed to avoid collision with submitToLHDN in lhdn_submit.php
function submitCustomPayloadToLHDN($url, $invoiceData, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    // Handles both array and string payloads
    $payload = is_array($invoiceData) ? json_encode($invoiceData) : $invoiceData;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json', 
        'Authorization: Bearer ' . $token
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'response' => $response];
}

// ✅ FIX: Removed unused $pdo parameter
function getStatusFromLHDN($url, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'response' => $response];
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

                        $insertRec = $pdo->prepare("INSERT INTO einvoice_records (upload_id, user_id, document_type, sale_no, customer_name, customer_address, customer_postcode, customer_phone, customer_email, customer_ic, customer_type, sale_title, sale_amount, sale_tax, total_amount, sale_datetime, validation_status, validation_errors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                        $validCount = 0; $invalidCount = 0;
                        foreach ($result['rows'] as $row) {
                            $row = normalizeInvoiceRecord($row);
                            $validation = validateInvoiceRecord($row);
                            $status = $validation['valid'] ? 'valid' : 'invalid';
                            $errors = $validation['valid'] ? null : json_encode($validation['errors']);

                            $insertRec->execute([
                                $uploadId, $uid, $row['document_type'] ?? '01', $row['sale_no'] ?? '', $row['customer_name'] ?? '', $row['customer_address'] ?? '', 
                                $row['customer_postcode'] ?? '', $row['customer_phone'] ?? '', $row['customer_email'] ?? '', $row['customer_ic'] ?? '', 
                                $row['customer_type'] ?? 'general', $row['sale_title'] ?? '', (float)($row['sale_amount'] ?? 0), (float)($row['sale_tax'] ?? 0), 
                                (float)($row['total_amount'] ?? 0), $row['sale_datetime'] ?? date('Y-m-d H:i:s'), $status, $errors
                            ]);
                            $validation['valid'] ? $validCount++ : $invalidCount++;
                        }
                        $pdo->prepare("UPDATE einvoice_uploads SET valid_records = ?, invalid_records = ?, status = 'completed' WHERE id = ?")->execute([$validCount, $invalidCount, $uploadId]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        header("Location: e-invoice_upload.php?err=" . urlencode('Upload failed: ' . $e->getMessage())); exit;
                    }
                    header("Location: e-invoice_upload.php?upload=" . $uploadId); exit;
                } else { 
                    header("Location: e-invoice_upload.php?err=" . urlencode($result['error'] ?? 'No data rows.')); exit; 
                }
            }
        } else { 
            header("Location: e-invoice_upload.php?err=Invalid file format."); exit; 
        }
    }
}

/* ================= HANDLE ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $stmtCompany = $pdo->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
    $stmtCompany->execute([$uid]);
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        header("Location: e-invoice_upload.php?err=" . urlencode('Company profile not found.')); exit;
    }

    $stmtSandboxUrl = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'sandbox_url'");
    $stmtSandboxUrl->execute();
    $sandboxUrl = $stmtSandboxUrl->fetchColumn() ?: 'https://preprod-api.myinvois.hasil.gov.my';
    
    $stmtProdUrl = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'prod_url'");
    $stmtProdUrl->execute();
    $prodUrl = $stmtProdUrl->fetchColumn() ?: 'https://api.myinvois.hasil.gov.my';

    $sandboxToken = $company['sandbox_token'] ?? null;
    $prodToken = $company['prod_token'] ?? null;
    
    $isSandbox = !empty($sandboxToken);
    $tokenValue = $isSandbox ? $sandboxToken : $prodToken;
    $tokenExpiry = $isSandbox ? ($company['sandbox_token_expiry'] ?? null) : ($company['prod_token_expiry'] ?? null);
    $envLabel = $isSandbox ? 'Sandbox' : 'Production';
    
    $apiBaseUrl = $isSandbox ? $sandboxUrl : $prodUrl;

    if (empty($tokenValue) || empty($tokenExpiry) || strtotime($tokenExpiry) <= time()) {
        header("Location: e-invoice_upload.php?err=" . urlencode("LHDN {$envLabel} API Token expired or missing. Please refresh in Company Settings.")); exit;
    }

    $stmtSend = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'json_send'");
    $stmtSend->execute(); $jsonSendTemplate = $stmtSend->fetchColumn();
    
    $stmtConvert = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'json_convert'");
    $stmtConvert->execute(); $jsonConvertTemplate = $stmtConvert->fetchColumn();

    if (!$jsonSendTemplate || !$jsonConvertTemplate) {
        header("Location: e-invoice_upload.php?err=" . urlencode('JSON templates not configured in settings.')); exit;
    }

    // ===== PROCESS CONSOLIDATED SUBMISSION =====
    if ($_POST['action'] === 'process_consolidated') {
        set_time_limit(0);
        ignore_user_abort(true);

        $stmt = $pdo->prepare("SELECT DATE(sale_datetime) as sale_date FROM einvoice_records WHERE user_id = ? AND validation_status = 'valid' AND submission_status = 'pending' GROUP BY DATE(sale_datetime) ORDER BY sale_date ASC");
        $stmt->execute([$uid]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($dates)) {
            header("Location: e-invoice_upload.php?err=" . urlencode('No valid pending records to process.')); exit;
        }

        $summary = ['submitted' => 0, 'in_progress' => 0, 'valid' => 0, 'invalid' => 0, 'error' => 0];

        foreach ($dates as $saleDate) {
            $stmtRec = $pdo->prepare("SELECT * FROM einvoice_records WHERE user_id = ? AND DATE(sale_datetime) = ? AND validation_status = 'valid' AND submission_status = 'pending'");
            $stmtRec->execute([$uid, $saleDate]);
            $records = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
            if (empty($records)) continue;

            $totalAmount = array_sum(array_column($records, 'sale_amount'));
            $totalTax = array_sum(array_column($records, 'sale_tax'));
            $grandTotal = array_sum(array_column($records, 'total_amount'));

            $pdo->beginTransaction();
            try {
                $stmtConsol = $pdo->prepare("INSERT INTO einvoice_consolidated (user_id, sale_date, total_records, total_amount, total_tax, grand_total, submission_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'processing', NOW()) RETURNING id");
                $stmtConsol->execute([$uid, $saleDate, count($records), $totalAmount, $totalTax, $grandTotal]);
                $consolidatedId = $stmtConsol->fetchColumn();

                $recordIds = array_column($records, 'id');
                $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
                $pdo->prepare("UPDATE einvoice_records SET consolidated_id = ?, submission_status = 'queued' WHERE id IN ($placeholders)")->execute(array_merge([$consolidatedId], $recordIds));

                $consolidatedData = [
                    'sale_no'         => 'CONSOL-' . str_replace('-', '', $saleDate) . '-' . substr($uid, 0, 8),
                    'customer_name'   => 'Consolidated Sales',
                    'customer_address'=> 'Multiple Customers',
                    'customer_email'  => $me['email'] ?? 'na@na.com',
                    'customer_tin'    => 'EI00000000010',
                    'customer_ic'     => '000000000000',
                    'total_amount'    => $grandTotal,
                    'sale_datetime'   => $saleDate . ' 23:59:59',
                    'document_type'   => '03'
                ];

                $payloads = buildLHDNPayloads($consolidatedData, $company, $jsonSendTemplate, $jsonConvertTemplate);

                $pdo->prepare("UPDATE einvoice_consolidated SET ei_json = ?, ei_convert = ? WHERE id = ?")->execute([$payloads['send'], $payloads['convert'], $consolidatedId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $summary['error']++;
                continue;
            }

            // ✅ FIX: Use the renamed custom payload function
            $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);
            $submitResponse = json_decode($submitResult['response'], true);
            
            $submissionUid = $submitResponse['submissionUid'] ?? null;
            $uuid = null;
            if (!empty($submitResponse['acceptedDocuments']) && is_array($submitResponse['acceptedDocuments'])) {
                $uuid = $submitResponse['acceptedDocuments'][0]['uuid'] ?? null;
            }

            $lhdnStatus = ($submitResult['code'] >= 200 && $submitResult['code'] < 300) ? 'Submitted' : 'Error';
            
            $pdo->prepare("UPDATE einvoice_consolidated SET ei_submission_id = ?, ei_uuid = ?, lhdn_status = ?, lhdn_response = ? WHERE id = ?")
                ->execute([$submissionUid, $uuid, $lhdnStatus, json_encode($submitResponse), $consolidatedId]);
            
            if ($lhdnStatus === 'Submitted') $summary['submitted']++;
            else $summary['error']++;

            sleep(5);

            if ($submissionUid && $uuid) {
                $statusUrl = $apiBaseUrl . "/api/v1.0/documentsubmissions/{$submissionUid}";
                $detailsUrl = $apiBaseUrl . "/api/v1.0/documents/{$uuid}/details";
                
                // ✅ FIX: Updated to match the new function signature (removed $pdo)
                $statusResult = getStatusFromLHDN($statusUrl, $tokenValue);
                $detailsResult = getStatusFromLHDN($detailsUrl, $tokenValue);
                
                $statusResponse = json_decode($statusResult['response'], true);
                $detailsResponse = json_decode($detailsResult['response'], true);
                
                $docStatus = $detailsResponse['status'] ?? $statusResponse['status'] ?? 'Unknown';
                
                if (stripos($docStatus, 'valid') !== false) $summary['valid']++;
                elseif (stripos($docStatus, 'invalid') !== false) $summary['invalid']++;
                else $summary['in_progress']++;

                $pdo->prepare("UPDATE einvoice_consolidated SET lhdn_response_2 = ?, lhdn_status = ? WHERE id = ?")
                    ->execute([json_encode(['submission' => $statusResponse, 'details' => $detailsResponse]), $docStatus, $consolidatedId]);
            }

            sleep(5);
        }

        $_SESSION['einvoice_summary'] = $summary;
        header("Location: e-invoice_summary.php");
        exit;
    }

    // ===== DELETE UPLOAD =====
    if ($_POST['action'] === 'delete_upload') {
        $uploadId = $_POST['upload_id'] ?? null;
        if ($uploadId) {
            $stmt = $pdo->prepare("SELECT file_path FROM einvoice_uploads WHERE id = ? AND user_id = ?");
            $stmt->execute([$uploadId, $uid]);
            $upload = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($upload) {
                $pdo->beginTransaction();
                try {
                    $stmtRec = $pdo->prepare("SELECT id FROM einvoice_records WHERE upload_id = ?");
                    $stmtRec->execute([$uploadId]);
                    $recordIds = $stmtRec->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($recordIds)) {
                        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
                        $pdo->prepare("DELETE FROM einvoice_logs WHERE submission_id IN (SELECT id FROM einvoice_submissions WHERE record_id IN ($placeholders))")->execute($recordIds);
                        $pdo->prepare("DELETE FROM einvoice_submissions WHERE record_id IN ($placeholders)")->execute($recordIds);
                        $pdo->prepare("DELETE FROM einvoice_queue WHERE record_id IN ($placeholders)")->execute($recordIds);
                    }
                    
                    if (!empty($upload['file_path'])) {
                        $fullPath = __DIR__ . '/../storage/uploads/' . $upload['file_path'];
                        if (file_exists($fullPath)) unlink($fullPath);
                    }
                    
                    $pdo->prepare("DELETE FROM einvoice_uploads WHERE id = ?")->execute([$uploadId]);
                    $pdo->commit();
                    header("Location: e-invoice_upload.php?msg=" . urlencode('Upload, file, and all associated records/logs deleted successfully.'));
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    header("Location: e-invoice_upload.php?err=" . urlencode('Delete failed: ' . $e->getMessage())); exit;
                }
            }
        }
    }
}

/* ================= LOAD DATA ================= */
$uploads = $pdo->prepare("SELECT * FROM einvoice_uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$uploads->execute([$uid]); 
$uploadsList = $uploads->fetchAll(PDO::FETCH_ASSOC);

$currentUpload = null; $records = [];
$recTotal = 0; $recPage = 1; $recPerPage = 100; $recPages = 1; $recOffset = 0;
$agg = ['valid' => 0, 'invalid' => 0, 'amount' => 0, 'submittable' => 0];

if (isset($_GET['upload'])) {
    $stmt = $pdo->prepare("SELECT * FROM einvoice_uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['upload'], $uid]); 
    $currentUpload = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentUpload) {
        $a = $pdo->prepare("SELECT COUNT(*) FILTER (WHERE validation_status='valid') AS valid, COUNT(*) FILTER (WHERE validation_status='invalid') AS invalid, COALESCE(SUM(total_amount),0) AS amount, COUNT(*) FILTER (WHERE validation_status='valid' AND submission_status IN ('pending', 'queued')) AS submittable FROM einvoice_records WHERE upload_id = ?");
        $a->execute([$currentUpload['id']]); 
        $agg = $a->fetch(PDO::FETCH_ASSOC);

        $recTotal   = (int)$currentUpload['total_records'];
        $recPage    = max(1, (int)($_GET['rpage'] ?? 1));
        $recPages   = max(1, (int)ceil($recTotal / $recPerPage));
        $recPage    = min($recPage, $recPages);
        $recOffset  = ($recPage - 1) * $recPerPage;

        $r = $pdo->prepare("SELECT * FROM einvoice_records WHERE upload_id = ? ORDER BY sale_no LIMIT $recPerPage OFFSET $recOffset");
        $r->execute([$currentUpload['id']]); 
        $records = $r->fetchAll(PDO::FETCH_ASSOC);
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
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}.banner.success{background:#d1fae5;color:#059669}.banner.error{background:#ffe4e6;color:#e11d48}.banner.info{background:#e0e5ff;color:#4644cf}.banner.warn{background:#fef3c7;color:#d97706}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}.card h2{font-size:20px;font-weight:800;margin-bottom:4px}.card .msub{font-size:13px;color:var(--muted);margin-bottom:20px}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px}.step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;text-align:center;position:relative}.step-num{width:40px;height:40px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px;margin:0 auto 12px}.step h3{font-size:16px;font-weight:700;margin-bottom:8px}.step p{font-size:13px;color:var(--muted);line-height:1.5}
.upload-zone{border:2px dashed var(--line);border-radius:16px;padding:48px;text-align:center;transition:.2s;cursor:pointer}.upload-zone:hover{border-color:var(--brand);background:#f8fafc}.upload-zone.dragover{border-color:var(--brand);background:#e0e5ff}.upload-icon{width:64px;height:64px;border-radius:16px;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:28px;margin:0 auto 16px}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none}.btn.primary{background:var(--grad);color:#fff;box-shadow:0 10px 24px -8px rgba(84,87,229,.5)}.btn.ghost{background:#f1f5f9;color:#475569}.btn.ghost:hover{background:#e2e8f0}.btn.success{background:#d1fae5;color:#059669}.btn.warn{background:#fef3c7;color:#d97706}
table{width:100%;border-collapse:collapse;font-size:14px}th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}td{padding:12px 16px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:top}tbody tr:hover{background:#f8fafc}.err-text{color:#e11d48;font-size:11px;font-weight:600;line-height:1.5}
.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.badge.valid{background:#d1fae5;color:#059669}.badge.invalid{background:#ffe4e6;color:#e11d48}.badge.pending{background:#fef3c7;color:#d97706}.badge.submitted{background:#d1fae5;color:#059669}.badge.queued{background:#e0e5ff;color:#4644cf}.badge.failed{background:#ffe4e6;color:#e11d48}.badge.consolidated{background:#e0e5ff;color:#4644cf}.badge.processing{background:#dbeafe;color:#3b82f6}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center}.summary-card b{display:block;font-size:28px;font-weight:800;color:var(--brand)}.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
.action-bar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}.checkbox-label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer}
.submit-box{margin-top:20px;background:#f5f6ff;border:1px solid #c6ceff;border-radius:16px;padding:20px}.submit-box h3{font-size:16px;font-weight:700;margin-bottom:6px}.submit-box p{font-size:13px;color:var(--muted);margin-bottom:16px}.submit-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.pager-rec{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0 0;font-size:13px;color:var(--muted);flex-wrap:wrap}.pager-rec nav{display:flex;gap:8px}.pbtn{border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;background:#f1f5f9;color:#475569;text-decoration:none}.pbtn:hover{background:#e2e8f0}.pbtn.off{opacity:.4;pointer-events:none}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.sidebar-overlay.open{display:block}.main-wrapper{margin-left:0}.menu-toggle{display:block}.steps{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.main{padding:20px 12px}h1{font-size:22px}.summary-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay"><div><div class="spinner"></div><p class="spinner-text">Processing…</p></div></div>
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
      <div class="step"><div class="step-num">1</div><h3>Download Template</h3><p>Download our Excel template with all required fields pre-formatted.</p><a href="download_template.php" class="btn ghost" style="margin-top:12px">📥 Download</a></div>
      <div class="step"><div class="step-num">2</div><h3>Fill Your Data</h3><p>Add your invoice records. Ensure all mandatory fields are filled correctly.</p></div>
      <div class="step"><div class="step-num">3</div><h3>Upload & Submit</h3><p>Upload the file, review validation, then process submission to LHDN MyInvois.</p></div>
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
                    <a href="?upload=<?= $up['id'] ?>" class="btn ghost" style="padding:6px 12px;font-size:11px">View</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this upload, its file, and all associated logs/records?');">
                      <input type="hidden" name="action" value="delete_upload">
                      <input type="hidden" name="upload_id" value="<?= htmlspecialchars($up['id']) ?>">
                      <button type="submit" class="btn ghost" style="padding:6px 12px;font-size:11px;color:#e11d48">🗑️ Delete</button>
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
        <div class="summary-grid">
          <div class="summary-card"><b><?= $recTotal ?></b><p>Total Records</p></div>
          <div class="summary-card"><b style="color:#059669"><?= $agg['valid'] ?></b><p>Verified</p></div>
          <div class="summary-card"><b style="color:#e11d48"><?= $agg['invalid'] ?></b><p>Failed</p></div>
          <div class="summary-card"><b><?= number_format((float)$agg['amount'], 2) ?></b><p>Total Amount (RM)</p></div>
        </div>

        <?php if ((int)$agg['invalid'] > 0): ?>
          <div class="banner error">✗ <?= (int)$agg['invalid'] ?> record(s) failed validation.</div>
          <div style="margin-top:14px"><a href="e-invoice_upload.php" class="btn primary">🔄 Re-upload corrected file</a></div>
        <?php else: ?>
          <div class="banner success">✓ All records verified — ready for LHDN submission.</div>
        <?php endif; ?>

        <?php if ((int)$agg['submittable'] > 0): ?>
          <div class="submit-box">
            <h3>🚀 Process Submission</h3>
            <p><b><?= (int)$agg['submittable'] ?></b> valid record(s) ready. System will consolidate by sale date, generate JSON payloads, submit to LHDN, and poll for status (5s intervals).</p>
            <div class="submit-row">
              <form method="POST" onsubmit="return confirm('This will process all valid pending records, consolidate them by date, and submit to LHDN. This may take a few moments. Continue?');">
                <input type="hidden" name="action" value="process_consolidated">
                <button type="submit" class="btn success">🚀 Process Consolidated Submission</button>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <div style="overflow-x:auto; margin-top:24px">
          <table style="min-width:1150px">
            <thead><tr><th>Sale No</th><th>Customer</th><th>Amount</th><th>Tax</th><th>Total</th><th>Date</th><th>Status</th><th>Errors</th></tr></thead>
            <tbody>
              <?php foreach ($records as $rec): ?>
                <tr>
                  <td><b><?= htmlspecialchars($rec['sale_no']) ?></b></td>
                  <td><?= htmlspecialchars($rec['customer_name']) ?></td>
                  <td>RM <?= number_format($rec['sale_amount'], 2) ?></td>
                  <td>RM <?= number_format($rec['sale_tax'], 2) ?></td>
                  <td><b>RM <?= number_format($rec['total_amount'], 2) ?></b></td>
                  <td><?= $rec['sale_datetime'] ? date('M d, H:i', strtotime($rec['sale_datetime'])) : '—' ?></td>
                  <td><span class="badge <?= $rec['validation_status'] ?>"><?= $rec['validation_status'] ?></span></td>
                  <td><?php if ($rec['validation_status'] === 'invalid' && $rec['validation_errors']): ?><span class="err-text">• <?= htmlspecialchars(implode(' • ', json_decode($rec['validation_errors'], true) ?: ['Failed'])) ?></span><?php else: ?>—<?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <div style="margin-top:24px; display:flex; gap:12px; flex-wrap: wrap;">
            <a href="e-invoice_upload.php" class="btn ghost">← Upload another file</a>
            <form method="POST" onsubmit="return confirm('Delete this upload, its file, and all associated logs/records?');">
                <input type="hidden" name="action" value="delete_upload">
                <input type="hidden" name="upload_id" value="<?= htmlspecialchars($currentUpload['id']) ?>">
                <button type="submit" class="btn ghost" style="color:#e11d48; border:1px solid #fda4af">🗑️ Delete this Upload</button>
            </form>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<script>
const dropZone=document.getElementById('dropZone'),fileInput=document.getElementById('fileInput'),fileInfo=document.getElementById('fileInfo');
fileInput?.addEventListener('change',function(e){const f=e.target.files[0];if(f){document.getElementById('fileName').textContent=f.name;document.getElementById('fileSize').textContent=(f.size/1024).toFixed(2)+' KB';fileInfo.style.display='block';dropZone.style.display='none';}});
['dragenter','dragover'].forEach(evt=>{dropZone?.addEventListener(evt,e=>{e.preventDefault();dropZone.classList.add('dragover');});});
['dragleave','drop'].forEach(evt=>{dropZone?.addEventListener(evt,e=>{e.preventDefault();dropZone.classList.remove('dragover');});});
dropZone?.addEventListener('drop',e=>{const files=e.dataTransfer.files;if(files.length>0){fileInput.files=files;fileInput.dispatchEvent(new Event('change'));}});
const overlay=document.getElementById('loadingOverlay');document.querySelectorAll('form').forEach(form=>{form.addEventListener('submit',function(){overlay.classList.add('active');});});
</script>
</body>
</html>
