<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php'; // ✅ Provides $pdo and DB constants safely
requireCustomer();

$uid = currentUserId();
$me  = currentUser();
$summary = $_SESSION['einvoice_summary'] ?? ['submitted' => 0, 'in_progress' => 0, 'valid' => 0, 'invalid' => 0, 'error' => 0];
unset($_SESSION['einvoice_summary']); // Clear after reading

$stmt = $pdo->prepare("SELECT * FROM einvoice_consolidated WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$uid]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submission Summary — AZ Kejora SaaS</title>
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

/* ---------- SUMMARY & TABLE STYLES ---------- */
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px}
.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center;box-shadow:var(--card)}
.summary-card b{display:block;font-size:28px;font-weight:800}
.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}

.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}
.card h2{font-size:20px;font-weight:800;margin-bottom:16px}

.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:700px}
th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:12px 16px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:top}
tbody tr:hover{background:#f8fafc}

.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.submitted{background:#d1fae5;color:#059669}
.badge.processing{background:#dbeafe;color:#3b82f6}
.badge.valid{background:#d1fae5;color:#059669}
.badge.invalid{background:#ffe4e6;color:#e11d48}
.badge.error{background:#ffe4e6;color:#e11d48}

.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none;border:none;cursor:pointer}
.btn.primary{background:var(--grad);color:#fff}
.btn.primary:hover{opacity:.9}

.footer{max-width:1200px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}

/* ---------- RESPONSIVE ---------- */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
  .summary-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- LOADING OVERLAY -->
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
    <h1>Submission Summary 📊</h1>
    <p class="sub">Overview of your latest consolidated e-invoice processing run.</p>
    <br>

    <div class="summary-grid">
      <div class="summary-card"><b style="color:#3b82f6"><?= $summary['submitted'] ?></b><p>Total Submitted</p></div>
      <div class="summary-card"><b style="color:#f59e0b"><?= $summary['in_progress'] ?></b><p>In Progress</p></div>
      <div class="summary-card"><b style="color:#059669"><?= $summary['valid'] ?></b><p>Total Valid</p></div>
      <div class="summary-card"><b style="color:#e11d48"><?= $summary['invalid'] ?></b><p>Total Invalid</p></div>
      <div class="summary-card"><b style="color:#64748b"><?= $summary['error'] ?></b><p>Total Error</p></div>
    </div>

    <div class="card">
      <h2>Recent Consolidated Submissions</h2>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Sale Date</th>
              <th>Records</th>
              <th>Grand Total</th>
              <th>LHDN Status</th>
              <th>Submission ID</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($submissions as $sub): ?>
              <tr>
                <td><b><?= htmlspecialchars($sub['sale_date']) ?></b></td>
                <td><?= $sub['total_records'] ?></td>
                <td>RM <?= number_format($sub['grand_total'], 2) ?></td>
                <td><span class="badge <?= strtolower($sub['lhdn_status'] ?: 'processing') ?>"><?= htmlspecialchars($sub['lhdn_status'] ?: 'Processing') ?></span></td>
                <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($sub['ei_submission_id'] ?? '—') ?></td>
                <td><?= date('M d, H:i', strtotime($sub['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($submissions)): ?>
              <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--faint)">No submissions found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <a href="e-invoice_upload.php" class="btn primary">← Back to Upload</a>
  </main>

  <footer class="footer">© 2026 AZ Kejora SaaS · Supabase PostgreSQL · <?= htmlspecialchars($me['email']) ?></footer>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}
</script>
</body>
</html>
