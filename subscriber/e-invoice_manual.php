<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/myinvois.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

/* ---------------- LOAD COMPANY DATA FOR MODAL ---------------- */
$co = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?");
$co->execute([$uid]);
$company = $co->fetch();
if (!$company) {
    $pdo->prepare("INSERT INTO companies (user_id) VALUES (?)")->execute([$uid]);
    $co->execute([$uid]); $company = $co->fetch();
}

/* ---------------- POST ACTIONS (MODAL) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_einvoice') {
        $pdo->prepare("UPDATE companies SET 
            msic_code = ?, classification_code = ?, taxpayer_tin = ?, taxpayer_brn = ?,
            sandbox_clientid = ?, sandbox_secret1 = ?, sandbox_secret2 = ?,
            prod_clientid = ?, prod_secret1 = ?, prod_secret2 = ?, updated_at = NOW()
            WHERE user_id = ?")
            ->execute([
                trim($_POST['msic_code'] ?? ''),
                trim($_POST['classification_code'] ?? ''),
                trim($_POST['taxpayer_tin'] ?? ''),
                trim($_POST['taxpayer_brn'] ?? ''),
                trim($_POST['sandbox_clientid'] ?? ''),
                trim($_POST['sandbox_secret1'] ?? ''),
                trim($_POST['sandbox_secret2'] ?? ''),
                trim($_POST['prod_clientid'] ?? ''),
                trim($_POST['prod_secret1'] ?? ''),
                trim($_POST['prod_secret2'] ?? ''),
                $uid
            ]);
        header("Location: e-invoice.php?updated=einvoice"); exit;
    }

    if ($_POST['action'] === 'update_ei_env') {
        $env = (($_POST['ei_env'] ?? 'sandbox') === 'prod') ? 'prod' : 'sandbox';
        $pdo->prepare("UPDATE users SET ei_env = ? WHERE id = ?")->execute([$env, $uid]);
        header("Location: e-invoice.php?updated=env"); exit;
    }

    if ($_POST['action'] === 'get_token') {
        $res = myinvois_request_token($pdo, $uid);
        header("Location: e-invoice.php?" . ($res['ok'] ? "token=ok" : "token=err&msg=" . urlencode($res['error'])));
        exit;
    }
}

// Refresh user for env/token state
$me        = currentUser();
$envIsProd = ($me['ei_env'] ?? 'sandbox') === 'prod';
$envLabel  = $envIsProd ? 'Production' : 'Sandbox (UAT)';
$envUrl    = myinvois_base_url($me);
$maskedTok = !empty($me['ei_token']) ? substr($me['ei_token'], 0, 10) . '••••••••••••' : null;

/* ---------------- E-INVOICE STATISTICS ---------------- */
// Updated to use einvoice_records table
$einSt = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ?");
$einSt->execute([$uid]);
$einCount = (int)$einSt->fetchColumn();

$einGross = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM einvoice_records WHERE user_id = ?");
$einGross->execute([$uid]);
$einGrossV = $einGross->fetchColumn();

$einWeek = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ? AND created_at >= NOW() - INTERVAL '7 days'");
$einWeek->execute([$uid]);
$einWeekCount = (int)$einWeek->fetchColumn();

$einMonth = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ? AND created_at >= NOW() - INTERVAL '30 days'");
$einMonth->execute([$uid]);
$einMonthCount = (int)$einMonth->fetchColumn();

$einYear = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ? AND created_at >= NOW() - INTERVAL '1 year'");
$einYear->execute([$uid]);
$einYearCount = (int)$einYear->fetchColumn();

// Updated to group by submission_status
$einByStatus = $pdo->prepare("SELECT submission_status, COUNT(*) as count FROM einvoice_records WHERE user_id = ? GROUP BY submission_status");
$einByStatus->execute([$uid]);
$statusMap = [];
while ($row = $einByStatus->fetch()) {
    $statusMap[$row['submission_status']] = (int)$row['count'];
}

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Invoice — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}

/* ---------- LOADING OVERLAY ---------- */
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner-wrap{text-align:center}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted)}

/* ---------- SIDEBAR ---------- */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}
.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.menu-section{margin-top:16px;padding:0 8px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.15s}
.menu-item:hover{background:#f8fafc;color:var(--ink)}
.menu-item.active{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}

/* ---------- MAIN LAYOUT ---------- */
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
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}

/* ---------- HEAD ROW ---------- */
.head-row{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:4px}
.btn-config{background:#e0e5ff;color:#4644cf;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 10px -4px rgba(84,87,229,.25)}
.btn-config:hover{background:#c6ceff}

/* ---------- SUBMISSION CARDS ---------- */
.submit-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:28px 0}
.submit-card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:28px;box-shadow:var(--card);position:relative;overflow:hidden;text-decoration:none;display:block;transition:.2s;color:inherit}
.submit-card:hover{transform:translateY(-4px);box-shadow:0 20px 40px -16px rgba(84,87,229,.3);border-color:#c6ceff}
.submit-card .blob{position:absolute;right:-30px;top:-30px;width:140px;height:140px;border-radius:50%;filter:blur(40px);opacity:.6}
.ic-tile{width:56px;height:56px;border-radius:14px;display:grid;place-items:center;color:#fff;font-size:24px;position:relative}
.submit-card h3{margin-top:16px;font-size:20px;font-weight:800;position:relative}
.submit-card p{margin-top:8px;font-size:14px;color:var(--muted);line-height:1.6;position:relative}
.submit-card .arrow{margin-top:18px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:var(--brand);position:relative}

/* ---------- STATS ---------- */
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center;box-shadow:var(--card)}
.summary-card b{display:block;font-size:28px;font-weight:800;color:var(--brand)}
.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}

.status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
.status-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px;box-shadow:var(--card)}
.status-card b{display:block;font-size:20px;font-weight:800;margin-bottom:4px}
.status-card p{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.status-new{color:#5457e5}.status-submitted{color:#059669}.status-valid{color:#10b981}
.status-invalid{color:#e11d48}.status-progress{color:#d97706}.status-fail{color:#9f1239}

/* ---------- MODAL ---------- */
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px;overflow-y:auto}
.modal.open{display:grid}
.modal-card{width:100%;max-width:640px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);max-height:90vh;overflow-y:auto;margin:auto}
.modal-card h3{font-size:18px;font-weight:800;margin-bottom:4px}
.modal-card .msub{font-size:13px;color:var(--muted);margin-bottom:16px}
.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.field textarea{resize:vertical;min-height:80px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.hint{font-size:11px;color:var(--faint);margin-top:4px;word-break:break-all}
.mrow{display:flex;gap:10px;margin-top:20px}
.mrow .btn-save{flex:1;text-align:center}
.mrow .cancel{flex:1;background:#f1f5f9;color:#475569;border-radius:10px;font-size:13px;font-weight:700;text-align:center;padding:11px 18px}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 18px;font-size:13px;font-weight:700}
.btn-save:hover{opacity:.9}

/* env selector */
.env-opt{border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:4px;cursor:pointer;transition:.15s;background:#fff}
.env-opt input{width:auto;margin:0 0 2px 0}
.env-opt b{font-size:13px}
.env-opt small{color:var(--faint);font-size:10px;word-break:break-all}
.env-opt.on{border-color:var(--brand);background:#f5f6ff;box-shadow:0 0 0 3px rgba(99,102,241,.12)}

/* token box */
.trow{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f8fafc;border-radius:10px;padding:10px 14px;margin-top:8px;font-size:12px;flex-wrap:wrap}
.trow span{color:var(--muted);font-weight:600}
.trow b{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;color:#1e293b;word-break:break-all}
.badge-env{display:inline-block;border-radius:999px;padding:2px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge-env.sandbox{background:#e0e5ff;color:#4644cf}
.badge-env.prod{background:#d1fae5;color:#059669}

/* modal tabs */
.tabs{display:flex;gap:6px;margin-bottom:18px;border-bottom:1px solid var(--line)}
.tab{padding:9px 14px;font-size:12px;font-weight:700;color:var(--muted);border-bottom:2px solid transparent;cursor:pointer}
.tab.active{color:var(--brand);border-bottom-color:var(--brand)}
.tab-panel{display:none}.tab-panel.on{display:block}
.section-head{margin-top:22px;margin-bottom:4px;font-size:14px;font-weight:700;color:var(--ink)}

.footer{max-width:1200px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}

/* ---------- RESPONSIVE ---------- */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  .submit-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
  .summary-grid{grid-template-columns:1fr}
  .grid2,.grid3{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner-wrap">
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
    <a href="e-invoice.php" class="menu-item active">🧾 E-Invoice</a>
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
    <div class="head-row">
      <div>
        <h1>E-Invoice Studio 🧾</h1>
        <p class="sub">Upload, extract, analyse and submit — LHDN MyInvois compliance included.</p>
      </div>
      <button class="btn-config" onclick="openConfig()">⚙️ Company &amp; e-Invoice config</button>
    </div>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'einvoice'): ?>
      <div class="banner success">✓ E-Invoice configuration saved.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'env'): ?>
      <div class="banner success">✓ Submission environment switched to <?= $envLabel ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['token']) && $_GET['token'] === 'ok'): ?>
      <div class="banner success">🔑 Access token received from LHDN (<?= $envLabel ?>) and saved securely.</div>
    <?php endif; ?>
    <?php if (isset($_GET['token']) && $_GET['token'] === 'err'): ?>
      <div class="banner error">✗ <?= htmlspecialchars($_GET['msg'] ?? 'Token request failed.') ?></div>
    <?php endif; ?>

    <!-- ========== SUBMISSION OPTIONS ========== -->
    <h2 style="font-size:20px;font-weight:700;margin-top:24px;margin-bottom:4px">🚀 Submit new e-Invoice</h2>
    <p style="font-size:13px;color:var(--muted);margin-bottom:4px">Choose how you want to submit your invoices to LHDN.</p>

    <div class="submit-grid">
      <a href="e-invoice_upload.php" class="submit-card action-link">
        <span class="blob" style="background:#fef3c7"></span>
        <span class="ic-tile" style="background:linear-gradient(135deg,#f59e0b,#f97316)">📤</span>
        <h3>Upload Excel / CSV</h3>
        <p>Bulk upload your invoices from spreadsheets. We'll extract line items, compute SST, validate TINs and submit to LHDN in one batch.</p>
        <span class="arrow">Upload files →</span>
      </a>

      <a href="e-invoice_manual.php" class="submit-card action-link">
        <span class="blob" style="background:#e0e5ff"></span>
        <span class="ic-tile" style="background:linear-gradient(135deg,var(--brand),var(--violet))">✍️</span>
        <h3>Manual Entry</h3>
        <p>Create a single invoice from scratch using our guided form. Perfect for ad-hoc sales, one-off jobs or quick receipts.</p>
        <span class="arrow">Start entry →</span>
      </a>
    </div>

    <!-- ========== STATISTICS ========== -->
    <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">📊 E-Invoice Summary</h2>

    <div class="summary-grid">
      <div class="summary-card">
        <b><?= $einWeekCount ?></b>
        <p>This Week</p>
      </div>
      <div class="summary-card">
        <b><?= $einMonthCount ?></b>
        <p>This Month</p>
      </div>
      <div class="summary-card">
        <b><?= $einYearCount ?></b>
        <p>This Year</p>
      </div>
    </div>

    <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:var(--muted)">By Status</h3>
    <div class="status-grid">
      <div class="status-card"><b class="status-new"><?= $statusMap['new'] ?? 0 ?></b><p>New</p></div>
      <div class="status-card"><b class="status-submitted"><?= $statusMap['submitted'] ?? 0 ?></b><p>Submitted</p></div>
      <div class="status-card"><b class="status-valid"><?= $statusMap['valid'] ?? $statusMap['validated'] ?? 0 ?></b><p>Valid</p></div>
      <div class="status-card"><b class="status-invalid"><?= $statusMap['invalid'] ?? 0 ?></b><p>Invalid</p></div>
      <div class="status-card"><b class="status-progress"><?= $statusMap['in_progress'] ?? $statusMap['pending'] ?? 0 ?></b><p>In Progress</p></div>
      <div class="status-card"><b class="status-fail"><?= $statusMap['fail'] ?? $statusMap['failed'] ?? 0 ?></b><p>Failed</p></div>
    </div>
  </main>

  <footer class="footer">© 2026 AZ Kejora SaaS · Supabase PostgreSQL · <?= htmlspecialchars($me['email']) ?></footer>
</div>

<!-- ============ COMPANY / E-INVOICE CONFIG MODAL ============ -->
<div class="modal" id="configModal">
  <div class="modal-card">
    <h3>⚙️ Company &amp; e-Invoice Configuration</h3>
    <p class="msub">Manage your business identity, LHDN credentials and access token.</p>

    <div class="tabs">
      <div class="tab active" data-tab="company">Company</div>
      <div class="tab" data-tab="credentials">Credentials</div>
      <div class="tab" data-tab="environment">Environment</div>
      <div class="tab" data-tab="token">Token</div>
    </div>

    <!-- TAB: COMPANY -->
    <form method="POST" class="tab-panel on action-form" data-panel="company">
      <input type="hidden" name="action" value="update_einvoice">
      
      <!-- ✅ FIX: Preserve credentials so they don't get wiped when updating identifiers -->
      <input type="hidden" name="sandbox_clientid" value="<?= htmlspecialchars($company['sandbox_clientid'] ?? '') ?>">
      <input type="hidden" name="sandbox_secret1" value="<?= htmlspecialchars($company['sandbox_secret1'] ?? '') ?>">
      <input type="hidden" name="sandbox_secret2" value="<?= htmlspecialchars($company['sandbox_secret2'] ?? '') ?>">
      <input type="hidden" name="prod_clientid" value="<?= htmlspecialchars($company['prod_clientid'] ?? '') ?>">
      <input type="hidden" name="prod_secret1" value="<?= htmlspecialchars($company['prod_secret1'] ?? '') ?>">
      <input type="hidden" name="prod_secret2" value="<?= htmlspecialchars($company['prod_secret2'] ?? '') ?>">

      <p class="section-head">Business identifiers</p>
      <div class="grid2">
        <div class="field"><label>MSIC code</label><input type="text" name="msic_code" value="<?= htmlspecialchars($company['msic_code'] ?? '') ?>" placeholder="62010">
          <p class="hint">Malaysia Standard Industrial Classification</p></div>
        <div class="field"><label>Classification code</label><input type="text" name="classification_code" value="<?= htmlspecialchars($company['classification_code'] ?? '') ?>" placeholder="022">
          <p class="hint">Business activity classification</p></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Taxpayer TIN</label><input type="text" name="taxpayer_tin" value="<?= htmlspecialchars($company['taxpayer_tin'] ?? '') ?>" placeholder="C2012345678">
          <p class="hint">Tax Identification Number from LHDN</p></div>
        <div class="field"><label>Taxpayer BRN</label><input type="text" name="taxpayer_brn" value="<?= htmlspecialchars($company['taxpayer_brn'] ?? '') ?>" placeholder="202001012345">
          <p class="hint">Business Registration Number</p></div>
      </div>
      <div class="mrow">
        <button type="submit" class="btn-save">Save identifiers</button>
        <button type="button" class="cancel" onclick="closeConfig()">Close</button>
      </div>
    </form>

    <!-- TAB: CREDENTIALS -->
    <form method="POST" class="tab-panel action-form" data-panel="credentials">
      <input type="hidden" name="action" value="update_einvoice">
      
      <!-- Preserve identifiers so they don't get wiped when updating credentials -->
      <input type="hidden" name="msic_code" value="<?= htmlspecialchars($company['msic_code'] ?? '') ?>">
      <input type="hidden" name="classification_code" value="<?= htmlspecialchars($company['classification_code'] ?? '') ?>">
      <input type="hidden" name="taxpayer_tin" value="<?= htmlspecialchars($company['taxpayer_tin'] ?? '') ?>">
      <input type="hidden" name="taxpayer_brn" value="<?= htmlspecialchars($company['taxpayer_brn'] ?? '') ?>">

      <p class="section-head">🧪 Sandbox Credentials</p>
      <div class="field"><label>Client ID</label><input type="text" name="sandbox_clientid" value="<?= htmlspecialchars($company['sandbox_clientid'] ?? '') ?>"></div>
      <div class="grid2">
        <div class="field"><label>Client Secret 1</label><input type="password" name="sandbox_secret1" value="<?= htmlspecialchars($company['sandbox_secret1'] ?? '') ?>"></div>
        <div class="field"><label>Client Secret 2</label><input type="password" name="sandbox_secret2" value="<?= htmlspecialchars($company['sandbox_secret2'] ?? '') ?>"></div>
      </div>

      <p class="section-head">🚀 Production Credentials</p>
      <div class="field"><label>Client ID</label><input type="text" name="prod_clientid" value="<?= htmlspecialchars($company['prod_clientid'] ?? '') ?>"></div>
      <div class="grid2">
        <div class="field"><label>Client Secret 1</label><input type="password" name="prod_secret1" value="<?= htmlspecialchars($company['prod_secret1'] ?? '') ?>"></div>
        <div class="field"><label>Client Secret 2</label><input type="password" name="prod_secret2" value="<?= htmlspecialchars($company['prod_secret2'] ?? '') ?>"></div>
      </div>

      <div class="mrow">
        <button type="submit" class="btn-save">Save credentials</button>
        <button type="button" class="cancel" onclick="closeConfig()">Close</button>
      </div>
    </form>

    <!-- TAB: ENVIRONMENT -->
    <form method="POST" class="tab-panel action-form" data-panel="environment">
      <input type="hidden" name="action" value="update_ei_env">
      <p class="section-head">Submission environment</p>
      <p class="hint" style="margin-bottom:12px">Choose which LHDN MyInvois gateway receives your submissions.</p>
      <div class="grid2">
        <label class="env-opt <?= !$envIsProd ? 'on' : '' ?>">
          <input type="radio" name="ei_env" value="sandbox" <?= !$envIsProd ? 'checked' : '' ?>>
          <b>🧪 Sandbox (UAT)</b>
          <small><?= htmlspecialchars($me['ei_url_sandbox'] ?? 'https://preprod-api.myinvois.hasil.gov.my') ?></small>
        </label>
        <label class="env-opt <?= $envIsProd ? 'on' : '' ?>">
          <input type="radio" name="ei_env" value="prod" <?= $envIsProd ? 'checked' : '' ?>>
          <b>🚀 Production</b>
          <small><?= htmlspecialchars($me['ei_url_prod'] ?? 'https://api.myinvois.hasil.gov.my') ?></small>
        </label>
      </div>
      <div class="mrow">
        <button type="submit" class="btn-save">Save environment</button>
        <button type="button" class="cancel" onclick="closeConfig()">Close</button>
      </div>
    </form>

    <!-- TAB: TOKEN -->
    <form method="POST" class="tab-panel action-form" data-panel="token">
      <input type="hidden" name="action" value="get_token">
      <p class="section-head">🔑 MyInvois Access Token</p>
      <p class="hint">Requests an OAuth 2.0 <code>client_credentials</code> token from LHDN using your saved <?= htmlspecialchars($envLabel) ?> credentials.</p>

      <div class="trow"><span>Environment</span><b><span class="badge-env <?= $envIsProd ? 'prod' : 'sandbox' ?>"><?= htmlspecialchars($envLabel) ?></span></b></div>
      <div class="trow"><span>API URL</span><b><?= htmlspecialchars($envUrl) ?>/connect/token</b></div>
      <div class="trow"><span>Access token</span><b><?= $maskedTok ? htmlspecialchars($maskedTok) : 'Not generated yet' ?></b></div>
      <div class="trow"><span>Last token date</span><b><?= !empty($me['ei_token_at']) ? date('M d, Y · H:i', strtotime($me['ei_token_at'])) : '—' ?></b></div>

      <div class="mrow">
        <button type="submit" class="btn-save">🔑 Get / Refresh token</button>
        <button type="button" class="cancel" onclick="closeConfig()">Close</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

/* ---------- CONFIG MODAL ---------- */
const cfgModal = document.getElementById('configModal');
function openConfig(){ cfgModal.classList.add('open'); }
function closeConfig(){ cfgModal.classList.remove('open'); }
cfgModal.addEventListener('click', e => { if (e.target === cfgModal) closeConfig(); });

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const name = tab.dataset.tab;
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('on', p.dataset.panel === name));
  });
});

/* keep env cards highlighted when radio changes */
document.querySelectorAll('.env-opt input').forEach(r => r.addEventListener('change', () => {
  document.querySelectorAll('.env-opt').forEach(o => o.classList.remove('on'));
  r.closest('.env-opt').classList.add('on');
}));

/* Auto-open config modal on return from token/env/update actions */
<?php if (isset($_GET['updated']) || isset($_GET['token'])): ?>
  openConfig();
  <?php if (isset($_GET['token'])): ?>
    document.querySelector('.tab[data-tab="token"]').click();
  <?php elseif (isset($_GET['updated']) && $_GET['updated'] === 'env'): ?>
    document.querySelector('.tab[data-tab="environment"]').click();
  <?php elseif (isset($_GET['updated']) && $_GET['updated'] === 'einvoice'): ?>
    document.querySelector('.tab[data-tab="credentials"]').click();
  <?php endif; ?>
<?php endif; ?>

/* ---------- LOADING OVERLAY ---------- */
const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function(e) {
    if (this.onsubmit && !this.onsubmit(e)) return;
    overlay.classList.add('active');
  });
});
document.querySelectorAll('a.action-link').forEach(link => {
  link.addEventListener('click', function() { overlay.classList.add('active'); });
});
</script>
</body>
</html>
