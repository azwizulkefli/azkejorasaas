<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();

$uid = currentUserId();
$me  = currentUser();

// ---------------- RESUBMIT ACTION HANDLER ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resubmit') {
    header('Content-Type: application/json');
    $recordId = $_POST['id'] ?? '';
    
    // Verify record belongs to user
    $chk = $pdo->prepare("SELECT * FROM einvoice_records WHERE id = ? AND user_id = ?");
    $chk->execute([$recordId, $uid]);
    $record = $chk->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Record not found or unauthorized.']);
        exit;
    }

    // =====================================================================
    // TODO: Replace this block with your actual LHDN API call function.
    // Example: $apiResult = myinvois_check_document_status($pdo, $uid, $record['reference_uuid'] ?? $record['id']);
    // 
    // $newStatus = $apiResult['status'] ?? 'pending';
    // $responseJson = json_encode($apiResult);
    // 
    // $updateStmt = $pdo->prepare("UPDATE einvoice_records SET lhdn_status = ?, lhdn_response = ? WHERE id = ?");
    // $updateStmt->execute([$newStatus, $responseJson, $recordId]);
    // =====================================================================

    // For demonstration, we simulate a successful check initiation
    echo json_encode(['success' => true, 'message' => 'Status check initiated. The record will be updated shortly.']);
    exit;
}

// ---------------- PAGINATION & SEARCH PARAMS ----------------
$perPageOptions = [10, 20, 50, 100, 200];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// ---------------- EXPORT LOGIC ----------------
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="einvoice_records_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($fp, ['No', 'Sale No', 'Sale Date', 'Customer Name', 'Customer Email', 'Customer Phone', 'Customer TIN', 'Customer IC', 'Category', 'Sale Total', 'Submitted Date', 'LHDN Status']);
    
    // Build export query with same filters
    $exportWhere = ["user_id = ?"];
    $exportParams = [$uid];
    if ($search !== '') {
        $exportWhere[] = "(sale_no ILIKE ? OR customer_name ILIKE ?)";
        $exportParams[] = "%$search%";
        $exportParams[] = "%$search%";
    }
    if ($dateFrom !== '') {
        $exportWhere[] = "created_at >= ?";
        $exportParams[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $exportWhere[] = "created_at <= ?";
        $exportParams[] = $dateTo . ' 23:59:59';
    }
    
    $exportStmt = $pdo->prepare("SELECT * FROM einvoice_records WHERE " . implode(' AND ', $exportWhere) . " ORDER BY created_at DESC");
    $exportStmt->execute($exportParams);
    
    $no = 1;
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        $catMap = ['01' => 'Invoice', '02' => 'Credit Note', '03' => 'Debit Note', '04' => 'Refund Note', '11' => 'Self-Billed'];
        $category = $catMap[$row['document_type']] ?? ucfirst($row['document_type'] ?? 'Unknown');
        $saleDate = $row['sale_datetime'] ? date('Y-m-d H:i', strtotime($row['sale_datetime'])) : date('Y-m-d H:i', strtotime($row['created_at']));
        
        fputcsv($fp, [
            $no++,
            $row['sale_no'],
            $saleDate,
            $row['customer_name'],
            $row['customer_email'],
            $row['customer_phone'],
            $row['customer_tin'],
            $row['customer_ic'],
            $category,
            number_format($row['total_amount'], 2),
            date('Y-m-d H:i', strtotime($row['created_at'])),
            strtoupper($row['lhdn_status'] ?? 'PENDING')
        ]);
    }
    fclose($fp);
    exit;
}

// ---------------- SUMMARY STATISTICS ----------------
$summaryStmt = $pdo->prepare("SELECT 
    COUNT(*) as total_submitted,
    COUNT(CASE WHEN lhdn_status IN ('valid', 'validated', 'success') THEN 1 END) as total_valid,
    COUNT(CASE WHEN lhdn_status IN ('invalid', 'rejected') THEN 1 END) as total_invalid,
    COUNT(CASE WHEN lhdn_status IN ('error', 'fail', 'failed') THEN 1 END) as total_error
    FROM einvoice_records WHERE user_id = ?");
$summaryStmt->execute([$uid]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// ---------------- FETCH DATA ----------------
$whereClauses = ["user_id = ?"];
$params = [$uid];

if ($search !== '') {
    $whereClauses[] = "(sale_no ILIKE ? OR customer_name ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($dateFrom !== '') {
    $whereClauses[] = "created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $whereClauses[] = "created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $whereClauses);

// Count Total
$countSql = "SELECT COUNT(*) FROM einvoice_records WHERE $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Fetch Records
$sql = "SELECT * FROM einvoice_records WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;

// Helper for category
function getCategory($docType) {
    $map = ['01' => 'Invoice', '02' => 'Credit Note', '03' => 'Debit Note', '04' => 'Refund Note', '11' => 'Self-Billed'];
    return $map[$docType] ?? ucfirst($docType ?? 'Unknown');
}

// Helper for status badge class
function getStatusClass($status) {
    $s = strtolower($status ?? 'pending');
    if (in_array($s, ['valid', 'validated', 'success'])) return 'valid';
    if (in_array($s, ['invalid', 'error', 'fail', 'failed', 'rejected'])) return 'invalid';
    if (in_array($s, ['submitted', 'processing', 'in_progress', 'pending', 'new'])) return 'processing';
    return 'processing';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Invoice Records — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}

/* ---------- LOADING OVERLAY ---------- */
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted)}

/* ---------- SIDEBAR & LAYOUT ---------- */
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

.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}

/* ---------- SUMMARY GRID ---------- */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px}
.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center;box-shadow:var(--card)}
.summary-card b{display:block;font-size:28px;font-weight:800}
.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}

/* ---------- TOOLBAR ---------- */
.toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:24px 0 16px}
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 14px;flex:1;max-width:400px}
.search-box input{border:none;outline:none;font-size:14px;width:100%;background:transparent}
.filter-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.filter-group select{border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13px;font-weight:600;color:var(--ink);background:#fff;cursor:pointer}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none;border:none;cursor:pointer}
.btn.primary{background:var(--grad);color:#fff}.btn.primary:hover{opacity:.9}
.btn.ghost{background:#fff;border:1px solid var(--line);color:var(--muted)}.btn.ghost:hover{border-color:var(--brand);color:var(--brand)}

/* ---------- TABLE ---------- */
.card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);overflow:hidden}
.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:1000px}
th{padding:14px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 16px;border-bottom:1px solid #f1f5f9;color:var(--ink);vertical-align:top}
tbody tr:hover{background:#f8fafc}
tbody tr:last-child td{border-bottom:none}

.badge{display:inline-block;border-radius:999px;padding:4px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.valid{background:#d1fae5;color:#059669}
.badge.invalid{background:#ffe4e6;color:#e11d48}
.badge.processing{background:#dbeafe;color:#3b82f6}

.customer-details{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5;display:grid;gap:3px}
.customer-details div{display:flex;align-items:center;gap:6px;word-break:break-all}

.action-btns{display:flex;gap:6px}
.action-btn{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;color:var(--muted);transition:.15s;border:1px solid var(--line);background:#fff}
.action-btn:hover{color:var(--brand);border-color:var(--brand);background:#f5f6ff}
.action-btn.danger:hover{color:#e11d48;border-color:#e11d48;background:#fff1f2}

/* ---------- PAGINATION ---------- */
.pagination{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-top:1px solid var(--line);font-size:13px;color:var(--muted);flex-wrap:wrap;gap:12px}
.page-links{display:flex;gap:4px}
.page-link{width:34px;height:34px;border-radius:8px;display:grid;place-items:center;font-weight:600;color:var(--muted);transition:.15s;border:1px solid transparent}
.page-link:hover{background:#f1f5f9;color:var(--ink)}
.page-link.active{background:var(--grad);color:#fff;border-color:transparent}
.page-link.disabled{opacity:.4;pointer-events:none}

/* ---------- MODAL ---------- */
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px;overflow-y:auto}
.modal.open{display:grid}
.modal-card{width:100%;max-width:600px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);max-height:90vh;overflow-y:auto;margin:auto;animation:pop .2s ease-out}
@keyframes pop{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.modal-header h3{font-size:18px;font-weight:800}
.modal-close{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;background:#f1f5f9;color:var(--ink);font-size:18px}
.modal-close:hover{background:#e2e8f0}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.detail-item{background:#f8fafc;border-radius:10px;padding:12px}
.detail-item label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.08em;margin-bottom:4px}
.detail-item span{font-size:14px;font-weight:600;color:var(--ink);word-break:break-all}
.json-block{background:#1e293b;color:#e2e8f0;border-radius:10px;padding:16px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow-y:auto}

.footer{max-width:1200px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}

/* ---------- RESPONSIVE ---------- */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  .toolbar{flex-direction:column;align-items:stretch}
  .search-box{max-width:100%}
  .filter-group{justify-content:space-between;width:100%}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
  .detail-grid{grid-template-columns:1fr}
  .summary-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- LOADING OVERLAY -->
<div class="loading-overlay" id="loadingOverlay">
  <div style="text-align:center">
    <div class="spinner"></div>
    <p class="spinner-text">Processing…</p>
  </div>
</div>

<!-- ============ SIDEBAR ============ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span>
  </div>
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

<!-- ============ MAIN WRAPPER ============ -->
<div class="main-wrapper">
  <nav class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span>
    </div>
    <div class="top-right">
      <span>Welcome, <b><?= htmlspecialchars(explode(' ', $me['name'])[0]) ?></b></span>
      <span class="avatar">
        <?php if ($avatarSrc): ?>
          <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Avatar">
        <?php else: ?>
          <?= strtoupper(substr($me['name'],0,1)) ?>
        <?php endif; ?>
      </span>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <h1>Submitted E-Invoices 📋</h1>
    <p class="sub">View, search, and manage your LHDN e-invoice submission history.</p>

    <!-- SUMMARY SECTION -->
    <div class="summary-grid">
      <div class="summary-card">
        <b style="color:#3b82f6"><?= number_format($summary['total_submitted']) ?></b>
        <p>Total Submitted</p>
      </div>
      <div class="summary-card">
        <b style="color:#059669"><?= number_format($summary['total_valid']) ?></b>
        <p>Total Valid</p>
      </div>
      <div class="summary-card">
        <b style="color:#e11d48"><?= number_format($summary['total_invalid']) ?></b>
        <p>Total Invalid</p>
      </div>
      <div class="summary-card">
        <b style="color:#64748b"><?= number_format($summary['total_error']) ?></b>
        <p>Total Error</p>
      </div>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" class="toolbar" id="filterForm">
      <div class="search-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" name="search" placeholder="Search by Sale No or Customer Name..." value="<?= htmlspecialchars($search) ?>">
      </div>
      
      <div class="search-box" style="max-width: 160px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="From Date" style="border:none;outline:none;font-size:13px;width:100%;background:transparent;color:var(--ink)">
      </div>
      <div class="search-box" style="max-width: 160px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--faint);flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" title="To Date" style="border:none;outline:none;font-size:13px;width:100%;background:transparent;color:var(--ink)">
      </div>

      <div class="filter-group">
        <select name="per_page" onchange="document.getElementById('filterForm').submit()">
          <?php foreach ($perPageOptions as $opt): ?>
            <option value="<?= $opt ?>" <?= $perPage == $opt ? 'selected' : '' ?>><?= $opt ?> per page</option>
          <?php endforeach; ?>
        </select>
        <a href="?export=1&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&per_page=<?= $perPage ?>" class="btn primary" onclick="document.getElementById('loadingOverlay').classList.add('active')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Export CSV
        </a>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card">
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th style="width:50px">No</th>
              <th>Sale No</th>
              <th>Sale Date</th>
              <th>Customer Details</th>
              <th>Category</th>
              <th style="text-align:right">Sale Total</th>
              <th>Submitted Date</th>
              <th>LHDN Status</th>
              <th style="width:100px">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($records)): ?>
              <tr>
                <td colspan="9" style="text-align:center;padding:48px 16px;color:var(--faint)">
                  <div style="font-size:32px;margin-bottom:8px">📭</div>
                  No e-invoice records found matching your criteria.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($records as $index => $row): ?>
                <?php 
                  $no = $offset + $index + 1;
                  $status = strtolower($row['lhdn_status'] ?? 'pending');
                  $isValid = in_array($status, ['valid', 'validated', 'success']);
                  $isPending = in_array($status, ['submitted', 'processing', 'in_progress', 'pending', 'new']);
                  $hasResponse = !empty($row['lhdn_response']);
                  $saleDate = $row['sale_datetime'] ? date('d M Y', strtotime($row['sale_datetime'])) : date('d M Y', strtotime($row['created_at']));
                  $submitDate = date('d M Y, H:i', strtotime($row['created_at']));
                ?>
                <tr>
                  <td style="color:var(--faint);font-weight:600"><?= $no ?></td>
                  <td><b><?= htmlspecialchars($row['sale_no'] ?? '—') ?></b></td>
                  <td><?= htmlspecialchars($saleDate) ?></td>
                  
                  <td>
                    <div style="font-weight:700;color:var(--ink)"><?= htmlspecialchars($row['customer_name'] ?? '—') ?></div>
                    <div class="customer-details">
                      <?php if (!empty($row['customer_email'])): ?><div>✉️ <?= htmlspecialchars($row['customer_email']) ?></div><?php endif; ?>
                      <?php if (!empty($row['customer_phone'])): ?><div>📞 <?= htmlspecialchars($row['customer_phone']) ?></div><?php endif; ?>
                      <?php if (!empty($row['customer_tin'])): ?><div>🆔 TIN: <?= htmlspecialchars($row['customer_tin']) ?></div><?php endif; ?>
                      <?php if (!empty($row['customer_ic'])): ?><div>🪪 IC: <?= htmlspecialchars($row['customer_ic']) ?></div><?php endif; ?>
                      <?php if (empty($row['customer_email']) && empty($row['customer_phone']) && empty($row['customer_tin']) && empty($row['customer_ic'])): ?>
                        <div style="color:var(--faint);font-style:italic">No additional details</div>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <div style="font-weight:600;color:var(--ink);font-size:13px"><?= getCategory($row['document_type']) ?></div>
                    <?php if (($row['submission_type'] ?? '') === 'consolidated' && !empty($row['consolidated_id'])): ?>
                      <div style="font-size:11px;color:var(--brand);margin-top:6px;font-family:ui-monospace,monospace;background:#eef1ff;padding:3px 8px;border-radius:6px;display:inline-flex;align-items:center;gap:4px" title="Consolidated ID: <?= htmlspecialchars($row['consolidated_id']) ?>">
                        📦 <?= htmlspecialchars(substr($row['consolidated_id'], 0, 8)) ?>...
                      </div>
                    <?php endif; ?>
                  </td>

                  <td style="text-align:right;font-weight:700;font-family:ui-monospace,monospace">RM <?= number_format($row['total_amount'], 2) ?></td>
                  <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($submitDate) ?></td>
                  <td><span class="badge <?= getStatusClass($row['lhdn_status']) ?>"><?= htmlspecialchars(strtoupper($row['lhdn_status'] ?? 'PENDING')) ?></span></td>
                  
                  <td>
                    <div class="action-btns">
                      <?php if ($isPending): ?>
                        <button class="action-btn" title="Resubmit / Check Status" onclick="resubmitRecord('<?= htmlspecialchars($row['id']) ?>')">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        </button>
                      <?php elseif ($isValid): ?>
                        <button class="action-btn" title="View LHDN E-Invoice" onclick="openInvoiceModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        </button>
                      <?php elseif ($hasResponse): ?>
                        <button class="action-btn danger" title="View Error Details" onclick="openJsonModal(<?= htmlspecialchars(json_encode($row['lhdn_response']), ENT_QUOTES) ?>)">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- PAGINATION -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <span>Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records</span>
          <div class="page-links">
            <a href="?page=<?= max(1, $page - 1) ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
            
            <?php 
              $startPage = max(1, $page - 2);
              $endPage = min($totalPages, $page + 2);
              for ($i = $startPage; $i <= $endPage; $i++): 
            ?>
              <a href="?page=<?= $i ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <a href="?page=<?= min($totalPages, $page + 1) ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">›</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <footer class="footer">© 2026 AZ Kejora SaaS · Supabase PostgreSQL · <?= htmlspecialchars($me['email']) ?></footer>
</div>

<!-- ============ INVOICE DETAIL MODAL ============ -->
<div class="modal" id="invoiceModal" onclick="if(event.target===this)closeModal('invoiceModal')">
  <div class="modal-card">
    <div class="modal-header">
      <h3>📄 LHDN E-Invoice Details</h3>
      <button class="modal-close" onclick="closeModal('invoiceModal')">✕</button>
    </div>
    <div id="invoiceModalContent"></div>
    <div style="margin-top:20px;display:flex;gap:10px">
      <button class="btn primary" style="flex:1" onclick="alert('PDF download feature would be triggered here.')">⬇ Download PDF</button>
      <button class="btn ghost" style="flex:1" onclick="closeModal('invoiceModal')">Close</button>
    </div>
  </div>
</div>

<!-- ============ JSON RESPONSE MODAL ============ -->
<div class="modal" id="jsonModal" onclick="if(event.target===this)closeModal('jsonModal')">
  <div class="modal-card">
    <div class="modal-header">
      <h3>🐛 LHDN API Response (JSON)</h3>
      <button class="modal-close" onclick="closeModal('jsonModal')">✕</button>
    </div>
    <pre class="json-block" id="jsonModalContent"></pre>
    <div style="margin-top:16px;text-align:right">
      <button class="btn ghost" onclick="copyJson()">📋 Copy to Clipboard</button>
      <button class="btn primary" onclick="closeModal('jsonModal')" style="margin-left:8px">Close</button>
    </div>
  </div>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}

function openInvoiceModal(record) {
  const content = document.getElementById('invoiceModalContent');
  const saleDate = record.sale_datetime ? new Date(record.sale_datetime).toLocaleDateString() : new Date(record.created_at).toLocaleDateString();
  
  content.innerHTML = `
    <div class="detail-grid">
      <div class="detail-item"><label>Sale No</label><span>${record.sale_no || '—'}</span></div>
      <div class="detail-item"><label>Document Type</label><span>${record.document_type || '—'}</span></div>
      <div class="detail-item"><label>Customer Name</label><span>${record.customer_name || '—'}</span></div>
      <div class="detail-item"><label>Customer TIN</label><span>${record.customer_tin || '—'}</span></div>
      <div class="detail-item"><label>Sale Amount</label><span>RM ${parseFloat(record.sale_amount || 0).toFixed(2)}</span></div>
      <div class="detail-item"><label>Total Amount</label><span style="color:var(--brand);font-weight:800">RM ${parseFloat(record.total_amount || 0).toFixed(2)}</span></div>
      <div class="detail-item"><label>LHDN Status</label><span style="text-transform:uppercase">${record.lhdn_status || 'PENDING'}</span></div>
      <div class="detail-item"><label>Submission Date</label><span>${saleDate}</span></div>
    </div>
    ${record.lhdn_submission_id ? `<div class="detail-item" style="margin-bottom:16px"><label>LHDN Submission ID</label><span style="font-family:monospace;font-size:12px">${record.lhdn_submission_id}</span></div>` : ''}
  `;
  document.getElementById('invoiceModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function openJsonModal(jsonString) {
  try {
    const parsed = JSON.parse(jsonString);
    document.getElementById('jsonModalContent').textContent = JSON.stringify(parsed, null, 2);
  } catch (e) {
    document.getElementById('jsonModalContent').textContent = jsonString;
  }
  document.getElementById('jsonModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function copyJson() {
  const text = document.getElementById('jsonModalContent').textContent;
  navigator.clipboard.writeText(text).then(() => {
    alert('JSON copied to clipboard!');
  });
}

function resubmitRecord(recordId) {
  if (!confirm('Are you sure you want to resubmit/check status for this record with LHDN?')) return;
  
  document.getElementById('loadingOverlay').classList.add('active');
  
  const formData = new FormData();
  formData.append('action', 'resubmit');
  formData.append('id', recordId);
  
  fetch(window.location.href, {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    document.getElementById('loadingOverlay').classList.remove('active');
    if (data.success) {
      alert(data.message || 'Resubmit successful!');
      window.location.reload();
    } else {
      alert(data.message || 'Resubmit failed.');
    }
  })
  .catch(error => {
    document.getElementById('loadingOverlay').classList.remove('active');
    console.error('Error:', error);
    alert('An error occurred while resubmitting.');
  });
}

// Close modals on Escape key
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') {
    closeModal('invoiceModal');
    closeModal('jsonModal');
  }
});
</script>
</body>
</html>
