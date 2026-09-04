<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/myinvois.php';
requireCustomer();

$uid = currentUserId();
$me  = currentUser();

// ---------------- AJAX: GET CONSOLIDATED DETAILS ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_consolidated_details') {
    header('Content-Type: application/json');
    $consId = $_POST['consolidated_id'] ?? '';
    
    $stmt = $pdo->prepare("SELECT id, sale_no, customer_name, total_amount, lhdn_status, created_at FROM einvoice_records WHERE consolidated_id = ? AND user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$consId, $uid]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $details]);
    exit;
}

// ---------------- DELETE RECORD ACTION HANDLER ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_record') {
    header('Content-Type: application/json');
    $recordId = $_POST['id'] ?? '';
    
    $stmt = $pdo->prepare("DELETE FROM einvoice_records WHERE id = ? AND user_id = ?");
    $stmt->execute([$recordId, $uid]);
    
    echo json_encode(['success' => true, 'message' => 'Record deleted successfully.']);
    exit;
}

// ---------------- RESUBMIT / CHECK STATUS ACTION HANDLER ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resubmit') {
    header('Content-Type: application/json');
    $recordId = $_POST['id'] ?? '';
    
    $chk = $pdo->prepare("SELECT * FROM einvoice_records WHERE id = ? AND user_id = ?");
    $chk->execute([$recordId, $uid]);
    $record = $chk->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Record not found or unauthorized.']);
        exit;
    }

    $envIsProd = ($me['ei_env'] ?? 'sandbox') === 'prod';
    $tokenCol  = $envIsProd ? 'prod_token' : 'sandbox_token';
    $expiryCol = $envIsProd ? 'prod_token_expiry' : 'sandbox_token_expiry';
    $clientIdCol = $envIsProd ? 'prod_clientid' : 'sandbox_clientid';

    $coCheck = $pdo->prepare("SELECT $clientIdCol, $tokenCol, $expiryCol FROM companies WHERE user_id = ?");
    $coCheck->execute([$uid]);
    $companyData = $coCheck->fetch(PDO::FETCH_ASSOC);
    
    $clientId = $companyData[$clientIdCol] ?? '';
    $token    = $companyData[$tokenCol] ?? '';
    $expiry   = $companyData[$expiryCol] ?? null;
    
    if (empty($clientId)) {
        echo json_encode(['success' => false, 'message' => 'LHDN API credentials not configured. Please go to E-Invoice → Company Config → Credentials.']);
        exit;
    }

    $needsRefresh = empty($token) || ($expiry && strtotime($expiry) <= time() + 60);
    if ($needsRefresh) {
        $tokenRes = myinvois_request_token($pdo, $uid);
        if (!$tokenRes['ok']) {
            echo json_encode(['success' => false, 'message' => 'Failed to get LHDN token: ' . ($tokenRes['error'] ?? 'Unknown error')]);
            exit;
        }
        $coCheck->execute([$uid]);
        $companyData = $coCheck->fetch(PDO::FETCH_ASSOC);
        $token = $companyData[$tokenCol] ?? '';
    }

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => 'Token is missing after refresh. Please check your credentials.']);
        exit;
    }

    $isConsolidated = strtolower($record['submission_type'] ?? 'individual') === 'consolidated';
    if ($isConsolidated && !empty($record['consolidated_id'])) {
        $consStmt = $pdo->prepare("SELECT ei_uuid, lhdn_status FROM einvoice_consolidated WHERE id = ? AND user_id = ?");
        $consStmt->execute([$record['consolidated_id'], $uid]);
        $consRecord = $consStmt->fetch(PDO::FETCH_ASSOC);
        $docUuid = $consRecord['ei_uuid'] ?? null;
    } else {
        $docUuid = $record['lhdn_uuid'] ?: $record['reference_uuid'];
    }
    
    if (empty($docUuid)) {
        echo json_encode(['success' => false, 'message' => 'LHDN Document UUID is missing. The document may still be processing or failed to submit properly.']);
        exit;
    }

    $envUrl  = myinvois_base_url($me);
    $apiUrl  = rtrim($envUrl, '/') . '/api/v1.0/documents/' . urlencode($docUuid);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { echo json_encode(['success' => false, 'message' => 'Network error: ' . $curlError]); exit; }
    if ($httpCode === 401) { echo json_encode(['success' => false, 'message' => 'Token unauthorized. Please refresh token manually in Company Config.']); exit; }
    if ($httpCode === 404) { echo json_encode(['success' => false, 'message' => 'Document not found in LHDN (HTTP 404). UUID may be incorrect or document was rejected before validation.']); exit; }
    if ($httpCode !== 200) { echo json_encode(['success' => false, 'message' => 'LHDN API error (HTTP ' . $httpCode . '): ' . $response]); exit; }

    $responseData = json_decode($response, true);
    $newStatus = 'pending';
    $lhdnUuid = $record['lhdn_uuid'] ?? null;
    $lhdnSubmissionId = $record['lhdn_submission_id'] ?? null;
    $lhdnLongId = $record['lhdn_long_id'] ?? null;
    $validationErrors = $record['validation_errors'] ?? null;

    if (is_array($responseData)) {
        $rawStatus = strtolower($responseData['status'] ?? $responseData['documentStatus'] ?? $responseData['validationStatus'] ?? 'pending');
        if (in_array($rawStatus, ['valid', 'validated', 'success', 'approved'])) $newStatus = 'valid';
        elseif (in_array($rawStatus, ['invalid', 'rejected', 'fail', 'failed', 'error'])) $newStatus = 'invalid';
        elseif (in_array($rawStatus, ['in_progress', 'pending', 'processing', 'submitted'])) $newStatus = 'in_progress';

        $lhdnUuid = $responseData['uuid'] ?? $responseData['documentUuid'] ?? $lhdnUuid;
        $lhdnSubmissionId = $responseData['submissionId'] ?? $responseData['submissionUid'] ?? $lhdnSubmissionId;
        $lhdnLongId = $responseData['longId'] ?? $responseData['invoiceLongId'] ?? $lhdnLongId;
        
        if (isset($responseData['validationErrors']) || isset($responseData['errors'])) {
            $errors = $responseData['validationErrors'] ?? $responseData['errors'];
            $validationErrors = is_array($errors) ? json_encode($errors) : (string)$errors;
        }
    }

    if ($isConsolidated && !empty($record['consolidated_id'])) {
        $updateStmt = $pdo->prepare("UPDATE einvoice_consolidated SET lhdn_status = ?, lhdn_response = ?, ei_uuid = COALESCE(NULLIF(?, ''), ei_uuid), ei_submission_id = COALESCE(NULLIF(?, ''), ei_submission_id), lhdn_uuid = COALESCE(NULLIF(?, ''), lhdn_uuid), lhdn_long_id = COALESCE(NULLIF(?, ''), lhdn_long_id) WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$newStatus, json_encode($responseData), $lhdnUuid, $lhdnSubmissionId, $lhdnUuid, $lhdnLongId, $record['consolidated_id'], $uid]);
        $pdo->prepare("UPDATE einvoice_records SET lhdn_status = ? WHERE consolidated_id = ? AND user_id = ?")->execute([$newStatus, $record['consolidated_id'], $uid]);
    } else {
        $updateStmt = $pdo->prepare("UPDATE einvoice_records SET lhdn_status = ?, lhdn_response = ?, lhdn_uuid = COALESCE(NULLIF(?, ''), lhdn_uuid), lhdn_submission_id = COALESCE(NULLIF(?, ''), lhdn_submission_id), lhdn_long_id = COALESCE(NULLIF(?, ''), lhdn_long_id), validation_errors = COALESCE(NULLIF(?, ''), validation_errors) WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$newStatus, json_encode($responseData), $lhdnUuid, $lhdnSubmissionId, $lhdnLongId, $validationErrors, $recordId, $uid]);
    }

    echo json_encode(['success' => true, 'message' => 'Status updated to: ' . strtoupper($newStatus), 'new_status' => $newStatus]);
    exit;
}

// ---------------- REST OF THE PAGE LOGIC ----------------
$perPageOptions = [10, 20, 50, 100, 200];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$envIsProd = ($me['ei_env'] ?? 'sandbox') === 'prod';
$shareBaseUrl = $envIsProd ? 'https://myinvois.hasil.gov.my' : 'https://preprod.myinvois.hasil.gov.my';

// Build WHERE clauses for UNION
$whereInd = ["r.user_id = ?", "(r.submission_type = 'individual' OR r.submission_type IS NULL)"];
$pInd = [$uid];
if ($search !== '') { $whereInd[] = "(r.sale_no ILIKE ? OR r.customer_name ILIKE ?)"; $pInd[] = "%$search%"; $pInd[] = "%$search%"; }
if ($dateFrom !== '') { $whereInd[] = "r.created_at >= ?"; $pInd[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $whereInd[] = "r.created_at <= ?"; $pInd[] = $dateTo . ' 23:59:59'; }
if ($statusFilter !== '') { $whereInd[] = "r.lhdn_status ILIKE ?"; $pInd[] = "%$statusFilter%"; }

$whereCon = ["c.user_id = ?"];
$pCon = [$uid];
if ($search !== '') { $whereCon[] = "(c.id::text ILIKE ? OR c.total_records::text ILIKE ?)"; $pCon[] = "%$search%"; $pCon[] = "%$search%"; }
if ($dateFrom !== '') { $whereCon[] = "c.created_at >= ?"; $pCon[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $whereCon[] = "c.created_at <= ?"; $pCon[] = $dateTo . ' 23:59:59'; }
if ($statusFilter !== '') { $whereCon[] = "c.lhdn_status ILIKE ?"; $pCon[] = "%$statusFilter%"; }

// Counts for pagination
$countIndStmt = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records r WHERE " . implode(' AND ', $whereInd));
$countIndStmt->execute($pInd);
$countInd = (int)$countIndStmt->fetchColumn();

$countConStmt = $pdo->prepare("SELECT COUNT(*) FROM einvoice_consolidated c WHERE " . implode(' AND ', $whereCon));
$countConStmt->execute($pCon);
$countCon = (int)$countConStmt->fetchColumn();

$totalRecords = $countInd + $countCon;
$totalPages = ceil($totalRecords / $perPage);

// UNION Query (FIX: Cast c.lhdn_response to text to match r.lhdn_response)
$sqlInd = "SELECT 'individual' AS record_type, r.id, r.sale_no, r.sale_datetime AS sale_date, r.customer_name, r.document_type, r.total_amount, r.created_at, r.lhdn_status, COALESCE(r.submission_type, 'individual') AS submission_type, NULL::uuid AS consolidated_id, NULL::int AS total_records, r.lhdn_uuid, r.lhdn_submission_id, r.lhdn_long_id, r.lhdn_response, r.lhdn_jsonsend FROM einvoice_records r WHERE " . implode(' AND ', $whereInd);
$sqlCon = "SELECT 'consolidated' AS record_type, c.id, 'Consolidated Batch' AS sale_no, c.sale_date::timestamp with time zone AS sale_date, c.total_records || ' Invoices' AS customer_name, 'Consolidated' AS document_type, c.grand_total AS total_amount, c.created_at, c.lhdn_status, 'consolidated' AS submission_type, c.id AS consolidated_id, c.total_records, c.ei_uuid AS lhdn_uuid, c.ei_submission_id AS lhdn_submission_id, c.lhdn_long_id, c.lhdn_response::text AS lhdn_response, c.ei_json AS lhdn_jsonsend FROM einvoice_consolidated c WHERE " . implode(' AND ', $whereCon);

$unionSql = "($sqlInd) UNION ALL ($sqlCon) ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params = array_merge($pInd, $pCon, [$perPage, $offset]);
$stmt = $pdo->prepare($unionSql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;

function getCategory($docType) {
    $map = ['01' => 'Invoice', '02' => 'Credit Note', '03' => 'Debit Note', '04' => 'Refund Note', '11' => 'Self-Billed', 'Consolidated' => 'Consolidated'];
    return $map[$docType] ?? ucfirst($docType ?? 'Unknown');
}
function getStatusClass($status) {
    $s = strtolower($status ?? 'pending');
    if (in_array($s, ['valid', 'validated', 'success'])) return 'valid';
    if (in_array($s, ['invalid', 'error', 'fail', 'failed', 'rejected'])) return 'invalid';
    return 'processing';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Invoice Records — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink)}a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}.loading-overlay.active{display:grid}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted)}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}
.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.menu-section{margin-top:16px;padding:0 8px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.15s}
.menu-item:hover{background:#f8fafc;color:var(--ink)}.menu-item.active{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}
.main-wrapper{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;gap:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted);flex-wrap:wrap}
.menu-toggle{display:none;background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink);padding:4px}
.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;overflow:hidden}.avatar img{width:100%;height:100%;object-fit:cover}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}h1{font-size:28px;font-weight:800;letter-spacing:-.02em}.sub{color:var(--muted);font-size:14px;margin-top:4px}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px}.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center;box-shadow:var(--card)}
.summary-card b{display:block;font-size:28px;font-weight:800}.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
.toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:24px 0 16px}
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 14px;flex:1;max-width:400px}.search-box input,.search-box select{border:none;outline:none;font-size:14px;width:100%;background:transparent;color:var(--ink);font-weight:600}
.filter-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.filter-group select{border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13px;font-weight:600;color:var(--ink);background:#fff;cursor:pointer}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none;border:none;cursor:pointer}
.btn.primary{background:var(--grad);color:#fff}.btn.primary:hover{opacity:.9}.btn.ghost{background:#fff;border:1px solid var(--line);color:var(--muted)}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);overflow:hidden}.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:1000px}th{padding:14px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 16px;border-bottom:1px solid #f1f5f9;color:var(--ink);vertical-align:top}tbody tr:hover{background:#f8fafc}tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;border-radius:999px;padding:4px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.valid{background:#d1fae5;color:#059669}.badge.invalid{background:#ffe4e6;color:#e11d48}.badge.processing{background:#dbeafe;color:#3b82f6}
.customer-details{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5;display:grid;gap:3px}.customer-details div{display:flex;align-items:center;gap:6px;word-break:break-all}
.action-btns{display:flex;gap:6px}.action-btn{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;color:var(--muted);transition:.15s;border:1px solid var(--line);background:#fff}
.action-btn:hover{color:var(--brand);border-color:var(--brand);background:#f5f6ff}.action-btn.danger:hover{color:#e11d48;border-color:#e11d48;background:#fff1f2}
.pagination{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-top:1px solid var(--line);font-size:13px;color:var(--muted);flex-wrap:wrap;gap:12px}
.page-links{display:flex;gap:4px}.page-link{width:34px;height:34px;border-radius:8px;display:grid;place-items:center;font-weight:600;color:var(--muted);transition:.15s;border:1px solid transparent}
.page-link:hover{background:#f1f5f9;color:var(--ink)}.page-link.active{background:var(--grad);color:#fff;border-color:transparent}.page-link.disabled{opacity:.4;pointer-events:none}
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px;overflow-y:auto}.modal.open{display:grid}
.modal-card{width:100%;max-width:600px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);max-height:90vh;overflow-y:auto;margin:auto;animation:pop .2s ease-out}
@keyframes pop{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.modal-header h3{font-size:18px;font-weight:800}.modal-close{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;background:#f1f5f9;color:var(--ink);font-size:18px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}.detail-item{background:#f8fafc;border-radius:10px;padding:12px}
.detail-item label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.08em;margin-bottom:4px}.detail-item span{font-size:14px;font-weight:600;color:var(--ink);word-break:break-all}
.json-block{background:#1e293b;color:#e2e8f0;border-radius:10px;padding:16px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow-y:auto}
.footer{max-width:1200px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.sidebar-overlay.open{display:block}.main-wrapper{margin-left:0}.menu-toggle{display:block}.toolbar{flex-direction:column;align-items:stretch}.search-box{max-width:100%}.filter-group{justify-content:space-between;width:100%}.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){.main{padding:20px 12px}h1{font-size:22px}.topbar{padding:12px 14px}.top-right{gap:8px;font-size:12px}.detail-grid{grid-template-columns:1fr}.summary-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay"><div style="text-align:center"><div class="spinner"></div><p class="spinner-text">Communicating with LHDN…</p></div></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span></div>
  <nav class="sidebar-nav">
    <a href="main.php" class="menu-item">🏠 Home</a>
    <a href="e-invoice.php" class="menu-item">🧾 E-Invoice</a>
    <a href="e-invoice_submitted.php" class="menu-item active">📋 View Submitted</a>
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
    <h1>Submitted E-Invoices 📋</h1>
    <p class="sub">View, search, and manage your LHDN e-invoice submission history.</p>

    <form method="GET" class="toolbar" id="filterForm">
      <div class="search-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" name="search" placeholder="Search by Sale No or Customer..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="search-box" style="max-width: 160px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="From Date">
      </div>
      <div class="search-box" style="max-width: 160px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" title="To Date">
      </div>
      <div class="search-box" style="max-width: 180px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
        <select name="status_filter" onchange="document.getElementById('filterForm').submit()">
          <option value="">All Statuses</option>
          <option value="valid" <?= $statusFilter === 'valid' ? 'selected' : '' ?>>Valid</option>
          <option value="invalid" <?= $statusFilter === 'invalid' ? 'selected' : '' ?>>Invalid</option>
          <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Error</option>
        </select>
      </div>
      <div class="filter-group">
        <select name="per_page" onchange="document.getElementById('filterForm').submit()">
          <?php foreach ($perPageOptions as $opt): ?><option value="<?= $opt ?>" <?= $perPage == $opt ? 'selected' : '' ?>><?= $opt ?> per page</option><?php endforeach; ?>
        </select>
        <a href="?export=1&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&status_filter=<?= urlencode($statusFilter) ?>&per_page=<?= $perPage ?>" class="btn primary" onclick="document.getElementById('loadingOverlay').classList.add('active')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Export CSV
        </a>
      </div>
    </form>

    <div class="card">
      <div class="table-responsive">
        <table>
          <thead><tr><th style="width:50px">No</th><th>Sale No</th><th>Sale Date</th><th>Customer Details</th><th>Category</th><th style="text-align:right">Total</th><th>Submitted Date</th><th>LHDN Status</th><th style="width:140px">Action</th></tr></thead>
          <tbody>
            <?php if (empty($records)): ?>
              <tr><td colspan="9" style="text-align:center;padding:48px 16px;color:var(--faint)"><div style="font-size:32px;margin-bottom:8px">📭</div>No e-invoice records found matching your criteria.</td></tr>
            <?php else: ?>
              <?php foreach ($records as $index => $row): 
                $no = $offset + $index + 1;
                $isConsolidated = $row['record_type'] === 'consolidated';
                
                $status = strtolower($row['lhdn_status'] ?? 'pending');
                $isValid = in_array($status, ['valid', 'validated', 'success']);
                $isPending = in_array($status, ['submitted', 'processing', 'in_progress', 'pending', 'new']);
                $isInvalid = in_array($status, ['invalid', 'error', 'fail', 'failed', 'rejected']);
                
                $saleDate = $row['sale_date'] ? date('d M Y', strtotime($row['sale_date'])) : date('d M Y', strtotime($row['created_at']));
                $submitDate = date('d M Y, H:i', strtotime($row['created_at']));

                $shareUrl = '';
                if ($isValid && !empty($row['lhdn_long_id']) && !empty($row['lhdn_submission_id'])) {
                    $shareUrl = $shareBaseUrl . '/' . rawurlencode($row['lhdn_uuid']) . '/share/' . rawurlencode($row['lhdn_long_id']);
                }
              ?>
                <tr>
                  <td style="color:var(--faint);font-weight:600"><?= $no ?></td>
                  <td>
                    <b><?= htmlspecialchars($row['sale_no'] ?? '—') ?></b>
                    <?php if ($isConsolidated && !empty($row['consolidated_id'])): ?>
                      <div style="font-size:10px;color:var(--faint);font-family:ui-monospace,monospace;margin-top:4px;" title="Consolidated ID">
                        📦 <?= htmlspecialchars(substr($row['consolidated_id'], 0, 8)) ?>...
                      </div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($saleDate) ?></td>
                  <td>
                    <?php if ($isConsolidated): ?>
                      <div style="font-weight:700;color:var(--ink)"><?= htmlspecialchars($row['customer_name']) ?></div>
                      <div class="customer-details"><div>📦 Batch Submission</div></div>
                    <?php else: ?>
                      <div style="font-weight:700;color:var(--ink)"><?= htmlspecialchars($row['customer_name'] ?? '—') ?></div>
                      <div class="customer-details">
                        <?php if (!empty($row['customer_email'])): ?><div>✉️ <?= htmlspecialchars($row['customer_email']) ?></div><?php endif; ?>
                        <?php if (!empty($row['customer_phone'])): ?><div>📞 <?= htmlspecialchars($row['customer_phone']) ?></div><?php endif; ?>
                        <?php if (!empty($row['customer_tin'])): ?><div>🆔 TIN: <?= htmlspecialchars($row['customer_tin']) ?></div><?php endif; ?>
                        <?php if (empty($row['customer_email']) && empty($row['customer_phone']) && empty($row['customer_tin'])): ?><div style="color:var(--faint);font-style:italic">No additional details</div><?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td><div style="font-weight:600;color:var(--ink);font-size:13px"><?= getCategory($row['document_type']) ?></div></td>
                  <td style="text-align:right;font-weight:700;font-family:ui-monospace,monospace">RM <?= number_format($row['total_amount'], 2) ?></td>
                  <td style="font-size:13px;color:var(--muted)">
                    <?= htmlspecialchars($submitDate) ?>
                    <div style="font-size:10px;color:var(--faint);margin-top:4px;text-transform:capitalize"><?= htmlspecialchars($row['submission_type'] ?? 'individual') ?></div>
                    <?php if (!$isConsolidated && in_array($status, ['submitted', 'processing', 'in_progress', 'pending']) && !empty($row['lhdn_uuid'])): ?>
                      <div style="font-size:10px;color:var(--faint);font-family:ui-monospace,monospace;margin-top:4px;word-break:break-all" title="LHDN UUID">🆔 <?= htmlspecialchars($row['lhdn_uuid']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge <?= getStatusClass($row['lhdn_status']) ?>"><?= htmlspecialchars(strtoupper($row['lhdn_status'] ?? 'PENDING')) ?></span></td>
                  <td>
                    <div class="action-btns">
                      <?php if ($isConsolidated): ?>
                        <button class="action-btn" title="View Batch Details" onclick="openConsolidatedModal('<?= htmlspecialchars($row['consolidated_id']) ?>')">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        </button>
                      <?php else: ?>
                        <button class="action-btn" title="View JSON Sent" onclick="openJsonModal(<?= htmlspecialchars(json_encode($row['lhdn_jsonsend'] ?? '{}'), ENT_QUOTES) ?>, 'JSON Sent to LHDN')">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M10 12l-2 2 2 2"></path><path d="M14 12l2 2-2 2"></path></svg>
                        </button>
                        <button class="action-btn" title="View JSON Response" onclick="openJsonModal(<?= htmlspecialchars(json_encode($row['lhdn_response'] ?? '{}'), ENT_QUOTES) ?>, 'JSON Response from LHDN')">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><path d="M8 10h8"></path><path d="M8 14h4"></path></svg>
                        </button>
                        <?php if ($isPending): ?>
                          <button class="action-btn" title="Check Status / Resubmit" onclick="resubmitRecord('<?= htmlspecialchars($row['id']) ?>')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                          </button>
                        <?php elseif ($isValid): ?>
                          <?php if (!empty($shareUrl)): ?>
                            <a href="<?= htmlspecialchars($shareUrl) ?>" target="_blank" class="action-btn" title="View LHDN E-Invoice (New Tab)">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                            </a>
                          <?php endif; ?>
                          <button class="action-btn" title="View Details" onclick="openInvoiceModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                          </button>
                        <?php elseif ($isInvalid): ?>
                          <button class="action-btn danger" title="Delete Record (Re-upload Required)" onclick="deleteRecord('<?= htmlspecialchars($row['id']) ?>')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                          </button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <span>Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records</span>
          <div class="page-links">
            <a href="?page=<?= max(1, $page - 1) ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&status_filter=<?= urlencode($statusFilter) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
            <?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); for ($i = $startPage; $i <= $endPage; $i++): ?>
              <a href="?page=<?= $i ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&status_filter=<?= urlencode($statusFilter) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="?page=<?= min($totalPages, $page + 1) ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&status_filter=<?= urlencode($statusFilter) ?>" class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">›</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
  <footer class="footer">© 2026 AZ Kejora SaaS · Supabase PostgreSQL · <?= htmlspecialchars($me['email']) ?></footer>
</div>

<!-- Modals -->
<div class="modal" id="invoiceModal" onclick="if(event.target===this)closeModal('invoiceModal')"><div class="modal-card"><div class="modal-header"><h3>📄 LHDN E-Invoice Details</h3><button class="modal-close" onclick="closeModal('invoiceModal')">✕</button></div><div id="invoiceModalContent"></div><div style="margin-top:20px;display:flex;gap:10px"><button class="btn primary" style="flex:1" onclick="alert('PDF download feature would be triggered here.')">⬇ Download PDF</button><button class="btn ghost" style="flex:1" onclick="closeModal('invoiceModal')">Close</button></div></div></div>

<div class="modal" id="jsonModal" onclick="if(event.target===this)closeModal('jsonModal')"><div class="modal-card"><div class="modal-header"><h3 id="jsonModalTitle">LHDN API Response (JSON)</h3><button class="modal-close" onclick="closeModal('jsonModal')">✕</button></div><pre class="json-block" id="jsonModalContent"></pre><div style="margin-top:16px;text-align:right"><button class="btn ghost" onclick="copyJson()">📋 Copy to Clipboard</button><button class="btn primary" onclick="closeModal('jsonModal')" style="margin-left:8px">Close</button></div></div></div>

<div class="modal" id="consolidatedModal" onclick="if(event.target===this)closeModal('consolidatedModal')">
  <div class="modal-card" style="max-width: 800px;">
    <div class="modal-header">
      <h3>📦 Consolidated Batch Details</h3>
      <button class="modal-close" onclick="closeModal('consolidatedModal')">✕</button>
    </div>
    <div id="consolidatedModalContent" style="text-align:center;padding:20px;">
      <div class="spinner"></div>
      <p style="margin-top:16px;color:var(--muted)">Loading details...</p>
    </div>
  </div>
</div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}

function getStatusClassJs(status) {
    const s = (status || 'pending').toLowerCase();
    if (['valid', 'validated', 'success'].includes(s)) return 'valid';
    if (['invalid', 'error', 'fail', 'failed', 'rejected'].includes(s)) return 'invalid';
    return 'processing';
}

function openInvoiceModal(record){
  const content = document.getElementById('invoiceModalContent');
  const saleDate = record.sale_date ? new Date(record.sale_date).toLocaleDateString() : new Date(record.created_at).toLocaleDateString();
  content.innerHTML = `<div class="detail-grid"><div class="detail-item"><label>Sale No</label><span>${record.sale_no || '—'}</span></div><div class="detail-item"><label>Document Type</label><span>${record.document_type || '—'}</span></div><div class="detail-item"><label>Customer Name</label><span>${record.customer_name || '—'}</span></div><div class="detail-item"><label>Sale Amount</label><span>RM ${parseFloat(record.total_amount || 0).toFixed(2)}</span></div><div class="detail-item"><label>LHDN Status</label><span style="text-transform:uppercase">${record.lhdn_status || 'PENDING'}</span></div><div class="detail-item"><label>Submission Date</label><span>${saleDate}</span></div></div>${record.lhdn_submission_id ? `<div class="detail-item" style="margin-bottom:16px"><label>LHDN Submission ID</label><span style="font-family:monospace;font-size:12px">${record.lhdn_submission_id}</span></div>` : ''}`;
  document.getElementById('invoiceModal').classList.add('open');document.body.style.overflow='hidden';
}

function openJsonModal(jsonString, modalTitle = 'LHDN API Response (JSON)'){
  try {
    let parsed = jsonString;
    if (typeof jsonString === 'string') {
      if (jsonString.trim() === '') parsed = { "message": "No JSON data available for this record." };
      else parsed = JSON.parse(jsonString);
    }
    document.getElementById('jsonModalContent').textContent = JSON.stringify(parsed, null, 2);
  } catch(e) {
    document.getElementById('jsonModalContent').textContent = jsonString || 'No JSON data available';
  }
  const titleEl = document.getElementById('jsonModalTitle');
  if (titleEl) titleEl.textContent = modalTitle;
  document.getElementById('jsonModal').classList.add('open');document.body.style.overflow='hidden';
}

function copyJson(){
  const text = document.getElementById('jsonModalContent').textContent;
  navigator.clipboard.writeText(text).then(() => alert('JSON copied to clipboard!')).catch(() => alert('Failed to copy.'));
}

function openConsolidatedModal(consId) {
  document.getElementById('consolidatedModal').classList.add('open');
  document.body.style.overflow = 'hidden';
  const content = document.getElementById('consolidatedModalContent');
  content.innerHTML = '<div class="spinner"></div><p style="margin-top:16px;color:var(--muted)">Loading details...</p>';
  
  const formData = new FormData();
  formData.append('action', 'get_consolidated_details');
  formData.append('consolidated_id', consId);
  
  fetch(window.location.href, { method: 'POST', body: formData })
  .then(res => res.json())
  .then(data => {
    if (data.success && data.data.length > 0) {
      let html = `<div class="table-responsive" style="max-height:400px;overflow-y:auto"><table style="min-width:600px"><thead><tr><th>Sale No</th><th>Customer</th><th style="text-align:right">Total</th><th>Status</th></tr></thead><tbody>`;
      data.data.forEach(item => {
        const statusClass = getStatusClassJs(item.lhdn_status);
        html += `<tr>
          <td><b>${item.sale_no || '—'}</b></td>
          <td>${item.customer_name || '—'}</td>
          <td style="text-align:right;font-family:monospace">RM ${parseFloat(item.total_amount || 0).toFixed(2)}</td>
          <td><span class="badge ${statusClass}">${(item.lhdn_status || 'PENDING').toUpperCase()}</span></td>
        </tr>`;
      });
      html += `</tbody></table></div>`;
      content.innerHTML = html;
    } else {
      content.innerHTML = '<p style="color:var(--muted)">No individual records found for this batch.</p>';
    }
  })
  .catch(err => {
    content.innerHTML = '<p style="color:#e11d48">Failed to load details.</p>';
  });
}

function resubmitRecord(recordId){
  if(!confirm('Check LHDN status for this record? This will refresh the token if expired and query the LHDN API.')) return;
  document.getElementById('loadingOverlay').classList.add('active');
  const formData = new FormData();formData.append('action', 'resubmit');formData.append('id', recordId);
  fetch(window.location.href, {method: 'POST', body: formData})
  .then(response => response.json()).then(data => {
    document.getElementById('loadingOverlay').classList.remove('active');
    if(data.success){alert(data.message || 'Status updated successfully!');window.location.reload();}
    else{alert('Error: ' + (data.message || 'Failed to update status.'));}
  }).catch(error => {document.getElementById('loadingOverlay').classList.remove('active');console.error('Error:', error);alert('A network error occurred.');});
}

function deleteRecord(recordId) {
  if (!confirm('Are you sure you want to delete this invalid/error record? You will need to correct and re-upload the data.')) return;
  document.getElementById('loadingOverlay').classList.add('active');
  const formData = new FormData();
  formData.append('action', 'delete_record');
  formData.append('id', recordId);
  fetch(window.location.href, {method: 'POST', body: formData})
  .then(response => response.json()).then(data => {
    document.getElementById('loadingOverlay').classList.remove('active');
    if (data.success) {
      alert(data.message || 'Record deleted successfully!');
      window.location.reload();
    } else {
      alert('Error: ' + (data.message || 'Failed to delete record.'));
    }
  }).catch(error => {document.getElementById('loadingOverlay').classList.remove('active');console.error('Error:', error);alert('A network error occurred.');});
}

document.addEventListener('keydown', function(e){if(e.key === 'Escape'){closeModal('invoiceModal');closeModal('jsonModal');closeModal('consolidatedModal');}});
</script>
</body>
</html>
