<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/myinvois.php';
requireCustomer();
ensure_settings_table($pdo);

$uid = currentUserId();
$me  = currentUser();

/* ================= DB SCHEMA UPGRADE ================= */
$alterations = [
    "ADD COLUMN IF NOT EXISTS submission_type VARCHAR(50) DEFAULT 'individual'",
    "ADD COLUMN IF NOT EXISTS customer_tin VARCHAR(100)",
    "ADD COLUMN IF NOT EXISTS lhdn_status VARCHAR(50)",
    "ADD COLUMN IF NOT EXISTS lhdn_uuid VARCHAR(255)",
    "ADD COLUMN IF NOT EXISTS lhdn_submission_id VARCHAR(255)",
    "ADD COLUMN IF NOT EXISTS lhdn_long_id VARCHAR(255)",
    "ADD COLUMN IF NOT EXISTS lhdn_response TEXT",
    "ADD COLUMN IF NOT EXISTS lhdn_jsonsend TEXT",
    "ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255)"
];
foreach ($alterations as $alt) {
    try { $pdo->exec("ALTER TABLE einvoice_records $alt"); } catch (Exception $e) {}
}

/* ================= HELPER FUNCTIONS ================= */
function buildLineItems($record) {
    $amount = number_format((float)$record['total_amount'], 2, '.', '');
    $desc = ($record['submission_type'] ?? '') === 'consolidated' ? 'Consolidated daily sales' : ($record['sale_title'] ?? 'Sale Transaction');
    $desc = str_replace(['"', "\n", "\r"], ['\\"', ' ', ' '], $desc);
    
    // ✅ NEW LOGIC: If General TIN is used, Classification Code is 004, otherwise 022
    $classCode = ($record['customer_tin'] === 'EI00000000010') ? '004' : '022';
    
    return '{' .
        '"ID": [{"_": "1"}],' .
        '"InvoicedQuantity": [{"_": 1, "unitCode": "C62"}],' .
        '"LineExtensionAmount": [{"_": ' . $amount . ', "currencyID": "MYR"}],' .
        '"AllowanceCharge": [{"ChargeIndicator": [{"_": false}], "AllowanceChargeReason": [{"_": "Sample Description"}], "MultiplierFactorNumeric": [{"_": 0.15}], "Amount": [{"_": 0, "currencyID": "MYR"}]}],' .
        '"TaxTotal": [{"TaxAmount": [{"_": 0, "currencyID": "MYR"}], "TaxSubtotal": [{"TaxableAmount": [{"_": ' . $amount . ', "currencyID": "MYR"}], "TaxAmount": [{"_": 0, "currencyID": "MYR"}], "Percent": [{"_": 6}], "TaxCategory": [{"ID": [{"_": "E"}], "TaxExemptionReason": [{"_": "Exempt New Means of Transport"}], "TaxScheme": [{"ID": [{"_": "OTH", "schemeID": "UN/ECE 5153", "schemeAgencyID": "6"}]}]}]}]}],' .
        '"Item": [{"CommodityClassification": [{"ItemClassificationCode": [{"_": "9800.00.0010", "listID": "PTC"}]}, {"ItemClassificationCode": [{"_": "' . $classCode . '", "listID": "CLASS"}]}], "Description": [{"_": "' . $desc . '"}], "OriginCountry": [{"IdentificationCode": [{"_": "MYS"}]}]}],' .
        '"Price": [{"PriceAmount": [{"_": ' . $amount . ', "currencyID": "MYR"}]}],' .
        '"ItemPriceExtension": [{"Amount": [{"_": ' . $amount . ', "currencyID": "MYR"}]}]' .
    '}';
}

function buildLHDNPayloads($primaryRecord, $allRecords, $company, $jsonSendTemplate, $jsonConvertTemplate, $grandTotal = null) {
    $lineItemsJson = buildLineItems($primaryRecord);
    $finalTotal = $grandTotal ?? $primaryRecord['total_amount'];

    // Safeguard: Force InvoiceTypeCode to '01' for individual invoices
    $jsonSendTemplate = preg_replace('/("InvoiceTypeCode"\s*:\s*\[\s*\{\s*"_"\s*:\s*)"15"/i', '$1"01"', $jsonSendTemplate);

    $map = [
        '*|ei_invoiceno|*'           => $primaryRecord['sale_no'] ?? '',
        '*|ei_invoicedate|*'         => date('Y-m-d', strtotime($primaryRecord['sale_datetime'])),
        '*|ei_invoicetype|*'         => '01',
        '*|ei_invoicecurrency|*'     => 'MYR',
        '*|ei_msiccode|*'            => $company['msic_code'] ?? '',
        '*|ei_msicname|*'            => $company['business_type'] ?? '',
        '*|ei_suppliertin|*'         => $company['taxpayer_tin'] ?? '',
        '*|ei_supplierbrn|*'         => $company['taxpayer_brn'] ?? 'NA',
        '*|ei_suppliername|*'        => $company['name'] ?? '',
        '*|ei_supplieradd1|*'        => $company['address'] ?? '',
        '*|ei_supplieradd2|*'        => $company['address'] ?? '',        
        '*|ei_supplierpostcode|*'    => $company['postcode'] ?? '',
        '*|ei_suppliertown|*'        => $company['town'] ?? '',
        '*|ei_supplierphone|*'       => $company['phone'] ?? '',
        '*|ei_supplieremail|*'       => $company['email'] ?? '',
        '*|ei_customertin|*'         => $primaryRecord['customer_tin'] ?? 'EI00000000010',
        '*|ei_customername|*'        => $primaryRecord['customer_name'] ?? 'General Buyer',
        '*|ei_customeradd1|*'        => $primaryRecord['customer_address'] ?? 'N/A',
        '*|ei_customeradd2|*'        => 'N/A',
        '*|ei_customerpostcode|*'    => $primaryRecord['customer_postcode'] ?? '00000',
        '*|ei_customertown|*'        => $primaryRecord['customer_town'] ?? 'N/A',
        '*|ei_customerphone|*'       => $primaryRecord['customer_phone'] ?? '0000000000',
        '*|ei_customeremail|*'       => $primaryRecord['customer_email'] ?? 'na@na.com',
        '*|ei_customeric|*'          => $primaryRecord['customer_ic'] ?? '000000000000',
        '*|ei_invoicetotalamount|*'  => number_format((float)$finalTotal, 2, '.', ''),
        '*|ei_cninvoice_referenceno|*' => $primaryRecord['reference_no'] ?? 'NA',
        '*|ei_cninvoice_uuid|*'      => $primaryRecord['reference_uuid'] ?? 'NA',
        '*|ei_invoicelineitem|*'     => $lineItemsJson,
        '*|ei_shippingrecipienttin|*'=> $primaryRecord['customer_tin'] ?? 'EI00000000010',
        '*|ei_shippingrecipientname|*'=> $primaryRecord['customer_name'] ?? 'General Buyer'
    ];
    
    $jsonStr = str_replace(array_keys($map), array_values($map), $jsonSendTemplate);
    $base64Doc = base64_encode($jsonStr);
    $sha256 = hash('sha256', $jsonStr);
    $convertMap = [
        '*|ei_convertbase64|*'  => $base64Doc,
        '*|ei_convertsha256|*'  => $sha256,
        '*|ei_invoiceno|*'      => $primaryRecord['sale_no']
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

function findErrorInArray($arr) {
    if (!is_array($arr)) return null;
    foreach (['error', 'message', 'errorMessages', 'validationErrors', 'curl_error', 'description', 'errors'] as $k) {
        if (isset($arr[$k]) && $arr[$k]) {
            $err = is_string($arr[$k]) ? $arr[$k] : json_encode($arr[$k]);
            if (stripos($err, 'ERR237') !== false || stripos($err, 'ERR253') !== false) return "LHDN Rejected: General TIN cannot be used for Individual e-Invoices.";
            if (stripos($err, 'ERR406') !== false || stripos($err, 'ERR409') !== false || stripos($err, 'TIN is invalid') !== false) return "LHDN Rejected: Invalid TIN or TIN/IC mismatch.";
            return $err;
        }
    }
    foreach (['submission', 'details', 'raw', 'rejectedDocuments'] as $k) {
        if (isset($arr[$k]) && is_array($arr[$k])) {
            if ($k === 'rejectedDocuments') {
                foreach ($arr[$k] as $item) { if (is_array($item)) { $found = findErrorInArray($item); if ($found) return $found; } }
            } else {
                $found = findErrorInArray($arr[$k]);
                if ($found) return $found;
            }
        }
    }
    return null;
}

/* ================= HANDLE MANUAL SUBMISSION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_manual') {
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $customer_email = trim($_POST['customer_email']);
    $customer_ic = trim($_POST['customer_ic']);
    $sale_no = trim($_POST['sale_no']);
    $sale_title = trim($_POST['sale_title']);
    $sale_date = trim($_POST['sale_date']);
    $sale_amount = (float)($_POST['sale_amount'] ?? 0);
    $sale_tax = (float)($_POST['sale_tax'] ?? 0);
    $total_amount = (float)($_POST['total_amount'] ?? 0);

    if (empty($customer_name) || empty($customer_phone) || empty($customer_email) || empty($customer_ic) || empty($sale_no) || empty($sale_title) || empty($sale_date)) {
        header("Location: e-invoice_manual.php?err=" . urlencode('Please fill in all required fields.')); exit;
    }

    // Handle optional file upload
    $attachment_path = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../storage/uploads/manual/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $filename = $uid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $filename)) {
                $attachment_path = 'manual/' . $filename;
            }
        }
    }

    $submission_type = 'individual';
    $customer_tin = $customer_ic; // Tentative, will be searched/validated during submission

    // 1. Insert Record
    $stmt = $pdo->prepare("INSERT INTO einvoice_records (
        user_id, document_type, sale_no, customer_name, customer_address, customer_postcode, 
        customer_phone, customer_email, customer_ic, customer_type, sale_title, sale_amount, sale_tax, 
        total_amount, sale_datetime, validation_status, submission_type, customer_tin, lhdn_status, attachment_path
    ) VALUES (?, '01', ?, ?, 'N/A', '00000', ?, ?, ?, 'individual', ?, ?, ?, ?, ?, 'valid', ?, ?, 'Pending', ?) RETURNING id");
    
    $stmt->execute([
        $uid, $sale_no, $customer_name, $customer_phone, $customer_email, $customer_ic,
        $sale_title, $sale_amount, $sale_tax, $total_amount, $sale_date, $submission_type, $customer_tin, $attachment_path
    ]);
    $record_id = $stmt->fetchColumn();

    // 2. Fetch Company & Token
    $stmtCompany = $pdo->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
    $stmtCompany->execute([$uid]);
    $company = $stmtCompany->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        header("Location: e-invoice_manual.php?err=" . urlencode('Company profile not found.')); exit;
    }

    $envIsProd = false;
    $prodValid = !empty($company['prod_token']) && !empty($company['prod_token_expiry']) && strtotime($company['prod_token_expiry']) > (time() + 60);
    $sandboxValid = !empty($company['sandbox_token']) && !empty($company['sandbox_token_expiry']) && strtotime($company['sandbox_token_expiry']) > (time() + 60);

    if ($prodValid && !$sandboxValid) $envIsProd = true;
    elseif (!$prodValid && $sandboxValid) $envIsProd = false;
    else $envIsProd = (!empty($company['prod_clientid']) && empty($company['sandbox_clientid']));

    $tokenCol = $envIsProd ? 'prod_token' : 'sandbox_token';
    $expiryCol = $envIsProd ? 'prod_token_expiry' : 'sandbox_token_expiry';
    $apiBaseUrl = $envIsProd ? 'https://api.myinvois.hasil.gov.my' : 'https://preprod-api.myinvois.hasil.gov.my';

    $tokenValue = $company[$tokenCol] ?? null;
    $tokenExpiry = $company[$expiryCol] ?? null;

    if (empty($tokenValue) || empty($tokenExpiry) || strtotime($tokenExpiry) <= (time() + 60)) {
        if (function_exists('myinvois_request_token')) {
            $res = myinvois_request_token($pdo, $uid);
            if ($res['ok']) {
                $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
                $c->execute([$uid]);
                $company = $c->fetch(PDO::FETCH_ASSOC);
                $tokenValue = $company[$tokenCol] ?? null;
            } else {
                header("Location: e-invoice_manual.php?err=" . urlencode('Token refresh failed: ' . ($res['error'] ?? 'Unknown'))); exit;
            }
        }
    }

    if (empty($tokenValue)) {
        header("Location: e-invoice_manual.php?err=" . urlencode('No LHDN access token available.')); exit;
    }

    // 3. Fetch Templates
    $stmtSend = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'json_send'");
    $stmtSend->execute(); $jsonSendTemplate = $stmtSend->fetchColumn();
    $stmtConvert = $pdo->prepare("SELECT value FROM settings WHERE module = 'einvoice' AND key = 'json_convert'");
    $stmtConvert->execute(); $jsonConvertTemplate = $stmtConvert->fetchColumn();

    if (!$jsonSendTemplate || !$jsonConvertTemplate) {
        header("Location: e-invoice_manual.php?err=" . urlencode('JSON templates not configured in settings.')); exit;
    }

    // 4. Build Record Array for Payload
    $indRec = [
        'id' => $record_id,
        'sale_no' => $sale_no,
        'sale_datetime' => $sale_date,
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'customer_email' => $customer_email,
        'customer_ic' => $customer_ic,
        'customer_tin' => $customer_tin,
        'customer_address' => 'N/A',
        'customer_postcode' => '00000',
        'customer_town' => 'N/A',
        'total_amount' => $total_amount,
        'sale_title' => $sale_title,
        'submission_type' => 'individual'
    ];

    // 5. ✅ NEW LOGIC: Search TIN using IC No. If not found, replace with General TIN
    $cleanIc = str_replace("-", "", str_replace(" ", "", $customer_ic));
    $foundTin = null;

    if (strlen($cleanIc) === 12) {
        $url = $apiBaseUrl . '/api/v1.0/taxpayer/search/tin?' . http_build_query(['idType' => 'NRIC', 'idValue' => $cleanIc]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_TIMEOUT => 15, 
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenValue, 'Accept: application/json']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                if (isset($data[0]['tin'])) $foundTin = $data[0]['tin'];
                elseif (isset($data['tin'])) $foundTin = $data['tin'];
                elseif (isset($data['taxPayerTin'])) $foundTin = $data['taxPayerTin'];
            } elseif (is_string($data)) {
                $foundTin = $data;
            }
        }
    }

    // Apply found TIN or fallback to General TIN
    $finalTin = !empty($foundTin) ? $foundTin : 'EI00000000010';
    $indRec['customer_tin'] = $finalTin;
    
    // Update the database record with the resolved TIN
    if ($finalTin !== $customer_tin) {
        $pdo->prepare("UPDATE einvoice_records SET customer_tin = ? WHERE id = ?")->execute([$finalTin, $record_id]);
    }

    // 6. Build & Submit Payload
    $payloads = buildLHDNPayloads($indRec, [$indRec], $company, $jsonSendTemplate, $jsonConvertTemplate);
    $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);
    
    if ($submitResult['code'] == 401) {
        if (function_exists('myinvois_request_token')) {
            $res = myinvois_request_token($pdo, $uid);
            if ($res['ok']) {
                $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
                $c->execute([$uid]);
                $company = $c->fetch(PDO::FETCH_ASSOC);
                $tokenValue = $company[$tokenCol] ?? null;
                if ($tokenValue) {
                    $submitResult = submitCustomPayloadToLHDN($apiBaseUrl . '/api/v1.0/documentsubmissions', $payloads['convert'], $tokenValue);
                }
            }
        }
    }

    $submitResponse = json_decode($submitResult['response'], true);
    $submissionUid = $submitResponse['submissionUid'] ?? null;
    $uuid = $submitResponse['acceptedDocuments'][0]['uuid'] ?? null;
    
    $isRejected = empty($submissionUid) && !empty($submitResponse['rejectedDocuments']);
    $lhdnStatus = ($submitResult['code'] >= 200 && $submitResult['code'] < 300 && !$isRejected) ? 'Submitted' : 'Error';

    $docStatus = $lhdnStatus; 
    $longId = null;
    $combined = $submitResponse;
    
    if ($lhdnStatus === 'Error' && !empty($submitResult['curl_error'])) {
        $combined = ['curl_error' => $submitResult['curl_error'], 'raw' => $submitResult['response']];
    }
    
    if ($lhdnStatus === 'Submitted' && $uuid) {
        sleep(2);
        $statusResult = getStatusFromLHDN($apiBaseUrl . "/api/v1.0/documents/{$uuid}/details", $tokenValue);
        $detailsResponse = json_decode($statusResult['response'], true);
        $docStatus = $detailsResponse['status'] ?? 'In Progress';
        $longId = $detailsResponse['longId'] ?? null;
        $combined = ['submission' => $submitResponse, 'details' => $detailsResponse];
    }
    
    $errMsg = findErrorInArray($combined);
    $finalStatus = $errMsg ? 'Error' : $docStatus;

    // 7. Update Record with LHDN Response
    $pdo->prepare("UPDATE einvoice_records SET lhdn_jsonsend = ?, lhdn_status = ?, lhdn_uuid = ?, lhdn_submission_id = ?, lhdn_long_id = ?, lhdn_response = ? WHERE id = ?")
        ->execute([$payloads['send'], $finalStatus, $uuid, $submissionUid, $longId, json_encode($combined), $record_id]);

    header("Location: e-invoice_submitted.php?msg=" . urlencode('Manual e-Invoice submitted successfully.'));
    exit;
}

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manual E-Invoice — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}

.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner-wrap{text-align:center}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted)}

.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}
.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.menu-section{margin-top:16px;padding:0 8px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.15s}
.menu-item:hover{background:#f8fafc;color:var(--ink)}
.menu-item.active{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}

.main-wrapper{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;gap:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted);flex-wrap:wrap}
.menu-toggle{display:none;background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink);padding:4px}
.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;overflow:hidden}
.avatar img{width:100%;height:100%;object-fit:cover}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}

.main{max-width:800px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:16px 0 24px;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}

.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}
.card h2{font-size:20px;font-weight:800;margin-bottom:4px}
.card .msub{font-size:13px;color:var(--muted);margin-bottom:20px}

.field{margin-top:16px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input, .field select, .field textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:11px 14px;font-size:14px;outline:none;font-family:inherit;background:#fff;transition:.15s}
.field input:focus, .field select:focus, .field textarea:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.field textarea{resize:vertical;min-height:80px}
.field input[readonly]{background:#f8fafc;color:var(--muted);cursor:not-allowed}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}

.section-head{margin-top:24px;margin-bottom:12px;font-size:14px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px}
.section-head::before{content:'';display:block;width:4px;height:16px;background:var(--grad);border-radius:2px}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:10px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none;border:none;cursor:pointer}
.btn.primary{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}
.btn.primary:hover{opacity:.9;transform:translateY(-1px)}
.btn.ghost{background:#f1f5f9;color:#475569}
.btn.ghost:hover{background:#e2e8f0}

.footer{max-width:800px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}

@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
  .grid2, .grid3{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner-wrap">
    <div class="spinner"></div>
    <p class="spinner-text">Submitting to LHDN MyInvois…</p>
  </div>
</div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span></div>
  <nav class="sidebar-nav">
    <a href="main.php" class="menu-item">🏠 Home</a>
    <a href="e-invoice.php" class="menu-item">🧾 E-Invoice</a>
    <a href="e-invoice_upload.php" class="menu-item">🧾 Upload Individual</a>
    <a href="e-invoice_consolidate.php" class="menu-item">🧾 Upload Consolidated</a>
    <a href="e-invoice_manual.php" class="menu-item active">🧾 Manual Entry</a>
    <a href="e-invoice_submitted.php" class="menu-item">📋 View Submitted</a>
    <div class="menu-section">Subscription</div>
    <a href="s_payment.php" class="menu-item">💳 Payment</a>
    <a href="s_report.php" class="menu-item">📄 Report</a>
    <div class="menu-section">Setup</div>
    <a href="company.php" class="menu-item">🏢 Company</a>
    <a href="users.php" class="menu-item">👥 Users</a>
    <a href="profile.php" class="menu-item">👤 Profile</a>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="main-wrapper">
  <nav class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span>
    </div>
    <div class="top-right">
      <span>Welcome, <b><?= htmlspecialchars(explode(' ', $me['name'])[0]) ?></b></span>
      <span class="avatar"><?php if ($avatarSrc): ?><img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Avatar"><?php else: ?><?= strtoupper(substr($me['name'],0,1)) ?><?php endif; ?></span>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <h1>Manual E-Invoice Entry ✍️</h1>
    <p class="sub">Create and submit a single individual e-invoice directly to LHDN.</p>

    <?php if (isset($_GET['err'])): ?>
      <div class="banner error">✗ <?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="card" id="manualForm" onsubmit="document.getElementById('loadingOverlay').classList.add('active')">
      <input type="hidden" name="action" value="submit_manual">

      <div class="section-head">Customer Details</div>
      <div class="grid2">
        <div class="field">
          <label>Customer Name *</label>
          <input type="text" name="customer_name" required placeholder="e.g. Ahmad bin Abdullah">
        </div>
        <div class="field">
          <label>Customer IC / Passport No *</label>
          <input type="text" name="customer_ic" required placeholder="e.g. 900101-10-5369">
        </div>
      </div>
      <div class="grid2">
        <div class="field">
          <label>Customer Email *</label>
          <input type="email" name="customer_email" required placeholder="customer@example.com">
        </div>
        <div class="field">
          <label>Customer Phone *</label>
          <input type="text" name="customer_phone" required placeholder="e.g. 0123456789">
        </div>
      </div>

      <div class="section-head">Sale Details</div>
      <div class="grid2">
        <div class="field">
          <label>Sale / Invoice No *</label>
          <input type="text" name="sale_no" required placeholder="e.g. INV-2026-001">
        </div>
        <div class="field">
          <label>Sale Title / Description *</label>
          <input type="text" name="sale_title" required placeholder="e.g. Consulting Services">
        </div>
      </div>
      <div class="grid3">
        <div class="field">
          <label>Sale Date & Time *</label>
          <input type="datetime-local" name="sale_date" required value="<?= date('Y-m-d\TH:i') ?>">
        </div>
        <div class="field">
          <label>Sale Amount (RM) *</label>
          <input type="number" id="sale_amount" name="sale_amount" step="0.01" min="0" required placeholder="0.00" oninput="calculateTotal()">
        </div>
        <div class="field">
          <label>Tax Amount (RM) *</label>
          <input type="number" id="sale_tax" name="sale_tax" step="0.01" min="0" required placeholder="0.00" oninput="calculateTotal()">
        </div>
      </div>
      <div class="field">
        <label>Final Total (RM)</label>
        <input type="number" id="total_amount" name="total_amount" step="0.01" readonly placeholder="0.00">
      </div>

      <div class="section-head">Optional Attachment</div>
      <div class="field">
        <label>Upload Receipt / Supporting Document</label>
        <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf">
        <p style="font-size:11px;color:var(--faint);margin-top:4px">Supported formats: JPG, PNG, PDF (Max 5MB)</p>
      </div>

      <div style="margin-top:28px;display:flex;gap:12px">
        <button type="submit" class="btn primary" style="flex:1">🚀 Validate & Submit to LHDN</button>
        <a href="e-invoice.php" class="btn ghost">Cancel</a>
      </div>
    </form>
  </main>

  <footer class="footer">© 2026 AZ Kejora SaaS · Supabase PostgreSQL · <?= htmlspecialchars($me['email']) ?></footer>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

function calculateTotal() {
  const amount = parseFloat(document.getElementById('sale_amount').value) || 0;
  const tax = parseFloat(document.getElementById('sale_tax').value) || 0;
  document.getElementById('total_amount').value = (amount + tax).toFixed(2);
}

// Initialize total on load
calculateTotal();
</script>
</body>
</html>
