<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);

$uid = currentUserId();
$me  = currentUser();

// ============================================================
// 1. DATE FILTERING
// ============================================================
$dateFilter = $_GET['date_range'] ?? 'last_6_months';
$dateCondition = '';
$dateParams = [];

switch ($dateFilter) {
    case 'last_30_days':
        $dateCondition = "AND sale_datetime >= NOW() - INTERVAL '30 days'";
        break;
    case 'last_3_months':
        $dateCondition = "AND sale_datetime >= NOW() - INTERVAL '3 months'";
        break;
    case 'last_6_months':
    default:
        $dateCondition = "AND sale_datetime >= NOW() - INTERVAL '6 months'";
        break;
    case 'this_year':
        $dateCondition = "AND EXTRACT(YEAR FROM sale_datetime) = EXTRACT(YEAR FROM CURRENT_DATE)";
        break;
}

// ============================================================
// 2. FETCH SUMMARY STATISTICS
// ============================================================
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_records,
        COALESCE(SUM(total_amount), 0) as total_amount,
        SUM(CASE WHEN lhdn_status = 'Valid' THEN 1 ELSE 0 END) as valid_count,
        SUM(CASE WHEN lhdn_status = 'Invalid' THEN 1 ELSE 0 END) as invalid_count,
        SUM(CASE WHEN lhdn_status IN ('Pending', 'In Progress') OR lhdn_status IS NULL THEN 1 ELSE 0 END) as pending_count
    FROM einvoice_records
    WHERE user_id = ? $dateCondition
");
$stmtStats->execute([$uid]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 3. FETCH CHART DATA (Monthly Trend)
// ============================================================
$stmtTrend = $pdo->prepare("
    SELECT 
        TO_CHAR(sale_datetime, 'Mon YY') as month_label,
        TO_CHAR(sale_datetime, 'YYYY-MM') as month_sort,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as amount
    FROM einvoice_records
    WHERE user_id = ? $dateCondition
    GROUP BY TO_CHAR(sale_datetime, 'YYYY-MM'), TO_CHAR(sale_datetime, 'Mon YY')
    ORDER BY month_sort ASC
");
$stmtTrend->execute([$uid]);
$trendData = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4. FETCH LHDN STATUS BREAKDOWN
// ============================================================
$stmtStatus = $pdo->prepare("
    SELECT 
        COALESCE(lhdn_status, 'Pending') as status,
        COUNT(*) as count
    FROM einvoice_records
    WHERE user_id = ? $dateCondition
    GROUP BY COALESCE(lhdn_status, 'Pending')
    ORDER BY count DESC
");
$stmtStatus->execute([$uid]);
$statusData = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 5. FETCH SUBMISSION TYPE BREAKDOWN
// ============================================================
$stmtType = $pdo->prepare("
    SELECT 
        submission_type,
        COUNT(*) as count
    FROM einvoice_records
    WHERE user_id = ? $dateCondition
    GROUP BY submission_type
");
$stmtType->execute([$uid]);
$typeData = $stmtType->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 6. FETCH RECENT RECORDS FOR TABLE
// ============================================================
$stmtRecent = $pdo->prepare("
    SELECT 
        id, sale_no, customer_name, customer_type, total_amount, 
        sale_datetime, lhdn_status, validation_status, submission_type
    FROM einvoice_records
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmtRecent->execute([$uid]);
$recentRecords = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 7. SMART INSIGHTS GENERATION (AI-like Logic)
// ============================================================
$insights = [];
$total = $stats['total_records'];
if ($total > 0) {
    $validRate = round(($stats['valid_count'] / $total) * 100, 1);
    $invalidRate = round(($stats['invalid_count'] / $total) * 100, 1);
    
    $insights[] = "📊 <strong>Validation Success Rate:</strong> Your e-Invoices have a <strong>{$validRate}%</strong> valid submission rate.";
    
    if ($invalidRate > 5) {
        $insights[] = "⚠️ <strong>Attention Needed:</strong> {$invalidRate}% of submissions are invalid. Review the 'Invalid' records in the table below to fix validation errors.";
    } else {
        $insights[] = "✅ <strong>Healthy Profile:</strong> Your invalid submission rate is low ({$invalidRate}%). Keep up the good data entry practices!";
    }

    if ($stats['pending_count'] > ($total * 0.2)) {
        $insights[] = "⏳ <strong>Processing Delay:</strong> Over 20% of your invoices are still pending LHDN validation. This is normal during peak hours, but monitor if it exceeds 24 hours.";
    }
} else {
    $insights[] = "💡 <strong>Getting Started:</strong> No e-Invoice records found for this period. Start by uploading or generating your first e-Invoice.";
}

// ============================================================
// 8. FORMATTERS
// ============================================================
$fmtDate = fn($v) => $v ? (new DateTime($v))->format('d M Y') : '—';
$fmtDateTime = fn($v) => $v ? (new DateTime($v))->format('d M Y, h:i A') : '—';
$fmtMoney = fn($v) => 'RM ' . number_format((float)$v, 2);

$statusBadge = fn($st) => match(strtolower($st)) {
    'valid'     => ['bg:#d1fae5', 'color:#059669', 'Valid'],
    'invalid'   => ['bg:#ffe4e6', 'color:#e11d48', 'Invalid'],
    'pending'   => ['bg:#fef3c7', 'color:#d97706', 'Pending'],
    'in progress' => ['bg:#e0e5ff', 'color:#4644cf', 'In Progress'],
    default     => ['bg:#f1f5f9', 'color:#94a3b8', ucfirst($st ?: 'Unknown')]
};

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Invoice Reports — AZ Kejora SaaS</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;overflow:hidden;cursor:pointer}
.avatar img{width:100%;height:100%;object-fit:cover}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}

.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}

/* ---------- STATS ---------- */
.stats4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:28px 0}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:24px;font-weight:800}
.stat small{display:block;margin-top:4px;font-size:12px;color:var(--muted)}
.stat .grad{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}

/* ---------- SMART INSIGHTS ---------- */
.insights-box{background:linear-gradient(135deg, #f0f4ff 0%, #fdf4ff 100%);border:1px solid #e0e5ff;border-radius:16px;padding:20px 24px;margin-bottom:28px}
.insights-box h3{font-size:14px;font-weight:800;color:var(--brand);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.insights-box ul{list-style:none;display:flex;flex-direction:column;gap:10px}
.insights-box li{font-size:13px;color:var(--ink);line-height:1.5;padding-left:4px}

/* ---------- FILTER BAR ---------- */
.filter-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px}
.filter-bar input,.filter-bar select{border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;background:#fff;outline:none;font-family:inherit}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}

/* ---------- CHARTS GRID ---------- */
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:28px}
.chart-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.chart-card h3{font-size:15px;font-weight:700;margin-bottom:16px;color:var(--ink)}
.chart-container{position:relative;height:300px;width:100%}

/* ---------- TABLE ---------- */
.table-wrap{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);overflow:hidden}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#f8fafc}
.data-table th{padding:14px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);border-bottom:1px solid var(--line)}
.data-table td{padding:16px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.data-table tbody tr:last-child td{border-bottom:none}
.data-table tbody tr:hover{background:#fafbff}
.data-table .mono{font-family:'JetBrains Mono','Courier New',monospace;font-size:12px;color:var(--muted)}
.data-table .amount{font-weight:800;font-size:14px;color:var(--ink)}

.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}

.btn-ghost{background:#fff;border:1px solid var(--line);color:var(--ink);border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px;transition:.15s}
.btn-ghost:hover{background:#f8fafc;border-color:var(--brand)}

.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-state .ic{font-size:48px;margin-bottom:12px;opacity:.4}
.empty-state h3{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:4px}
.empty-state p{font-size:13px;max-width:400px;margin:0 auto}

/* ---------- RESPONSIVE ---------- */
@media(max-width:1024px){
  .charts-grid{grid-template-columns:1fr}
  .stats4{grid-template-columns:repeat(2,1fr)}
}
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
  .stats4{grid-template-columns:1fr}
  .table-wrap{overflow-x:auto}
  .data-table{min-width:820px}
}
</style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner-wrap">
    <div class="spinner"></div>
    <p class="spinner-text">Loading Reports…</p>
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
    <a href="e-invoice_upload.php" class="menu-item">🧾 Upload Individual</a>
    <a href="e-invoice_consolidate.php" class="menu-item">🧾 Upload Consolidated</a>
    <a href="e-invoice_manual.php" class="menu-item">🧾 Manual Entry</a>
    <a href="e-invoice_submitted.php" class="menu-item">🧾 View Submitted</a>
    <div class="menu-section">Subscription</div>
    <a href="s_payment.php" class="menu-item">💳 Payment</a>
    <a href="s_report.php" class="menu-item active">📊 Report</a>
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
      <button class="avatar" onclick="location.href='profile.php'">
        <?php if ($avatarSrc): ?>
          <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Avatar">
        <?php else: ?>
          <?= strtoupper(substr($me['name'],0,1)) ?>
        <?php endif; ?>
      </button>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px">
      <div>
        <h1>E-Invoice Analytics</h1>
        <p class="sub">Comprehensive insights into your LHDN submission performance and validation metrics.</p>
      </div>
      
      <!-- Date Filter -->
      <form method="GET" style="display:flex;gap:8px">
        <select name="date_range" onchange="this.form.submit()" style="border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;background:#fff;outline:none;font-family:inherit;cursor:pointer">
          <option value="last_30_days" <?= $dateFilter === 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
          <option value="last_3_months" <?= $dateFilter === 'last_3_months' ? 'selected' : '' ?>>Last 3 Months</option>
          <option value="last_6_months" <?= $dateFilter === 'last_6_months' ? 'selected' : '' ?>>Last 6 Months</option>
          <option value="this_year" <?= $dateFilter === 'this_year' ? 'selected' : '' ?>>This Year</option>
        </select>
      </form>
    </div>

    <!-- SMART INSIGHTS -->
    <div class="insights-box">
      <h3>🤖 Smart Insights</h3>
      <ul>
        <?php foreach ($insights as $insight): ?>
          <li><?= $insight ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- STATS CARDS -->
    <div class="stats4">
      <div class="stat">
        <p>Total Invoices</p>
        <b class="grad"><?= number_format($stats['total_records']) ?></b>
        <small>records in selected period</small>
      </div>
      <div class="stat">
        <p>Total Value</p>
        <b class="grad"><?= $fmtMoney($stats['total_amount']) ?></b>
        <small>cumulative invoice amount</small>
      </div>
      <div class="stat">
        <p>Valid Submissions</p>
        <b style="color:#059669"><?= number_format($stats['valid_count']) ?></b>
        <small>successfully validated by LHDN</small>
      </div>
      <div class="stat">
        <p>Pending / Invalid</p>
        <b style="color:#d97706"><?= number_format($stats['pending_count'] + $stats['invalid_count']) ?></b>
        <small>requires attention or processing</small>
      </div>
    </div>

    <!-- CHARTS -->
    <div class="charts-grid">
      <div class="chart-card">
        <h3>📈 Submission & Revenue Trend</h3>
        <div class="chart-container">
          <canvas id="trendChart"></canvas>
        </div>
      </div>
      <div class="chart-card">
        <h3>🎯 LHDN Validation Status</h3>
        <div class="chart-container">
          <canvas id="statusChart"></canvas>
        </div>
      </div>
    </div>

    <!-- FILTER BAR FOR TABLE -->
    <div class="filter-bar">
      <input type="text" id="searchInput" placeholder="🔍 Search customer, sale no, or status…">
      <select id="statusFilter">
        <option value="">All LHDN Statuses</option>
        <option value="Valid">Valid</option>
        <option value="Invalid">Invalid</option>
        <option value="Pending">Pending</option>
        <option value="In Progress">In Progress</option>
      </select>
    </div>

    <!-- RECENT RECORDS TABLE -->
    <div class="table-wrap">
      <?php if (empty($recentRecords)): ?>
        <div class="empty-state">
          <div class="ic">📂</div>
          <h3>No e-Invoice records found</h3>
          <p>Your submitted e-Invoices and their LHDN validation statuses will appear here.</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Sale No</th>
              <th>Customer</th>
              <th>Type</th>
              <th>Amount</th>
              <th>Date</th>
              <th>Validation</th>
              <th>LHDN Status</th>
            </tr>
          </thead>
          <tbody id="recordsBody">
            <?php foreach ($recentRecords as $r):
              $sb = $statusBadge($r['lhdn_status']);
            ?>
            <tr data-search="<?= strtolower(($r['sale_no'] ?? '') . ' ' . ($r['customer_name'] ?? '') . ' ' . ($r['lhdn_status'] ?? '')) ?>"
                data-status="<?= htmlspecialchars($r['lhdn_status'] ?? '') ?>">
              <td><span class="mono"><?= htmlspecialchars($r['sale_no'] ?: '—') ?></span></td>
              <td>
                <strong><?= htmlspecialchars($r['customer_name'] ?: 'General Buyer') ?></strong>
                <div style="font-size:11px;color:var(--faint)"><?= htmlspecialchars($r['customer_type'] ?? 'general') ?></div>
              </td>
              <td><span class="pill" style="background:#f1f5f9;color:#475569"><?= ucfirst($r['submission_type'] ?? 'standard') ?></span></td>
              <td><span class="amount"><?= $fmtMoney($r['total_amount']) ?></span></td>
              <td><?= $fmtDate($r['sale_datetime']) ?></td>
              <td>
                <span class="pill" style="background:<?= $r['validation_status'] === 'valid' ? '#d1fae5' : '#fef3c7' ?>;color:<?= $r['validation_status'] === 'valid' ? '#059669' : '#d97706' ?>">
                  <?= ucfirst($r['validation_status'] ?? 'Pending') ?>
                </span>
              </td>
              <td><span class="pill" style="background:<?= $sb[0] ?>;color:<?= $sb[1] ?>"><?= $sb[2] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </main>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

// ===================== CHARTS INITIALIZATION =====================
const trendLabels = <?= json_encode(array_column($trendData, 'month_label')) ?>;
const trendCounts = <?= json_encode(array_column($trendData, 'count')) ?>;
const trendAmounts = <?= json_encode(array_column($trendData, 'amount')) ?>;

const ctxTrend = document.getElementById('trendChart').getContext('2d');
new Chart(ctxTrend, {
  type: 'bar',
  data: {
    labels: trendLabels,
    datasets: [
      {
        label: 'Submission Count',
        data: trendCounts,
        backgroundColor: 'rgba(84, 87, 229, 0.2)',
        borderColor: 'rgba(84, 87, 229, 1)',
        borderWidth: 2,
        borderRadius: 6,
        yAxisID: 'y'
      },
      {
        label: 'Total Amount (RM)',
        data: trendAmounts,
        type: 'line',
        borderColor: 'rgba(139, 92, 246, 1)',
        backgroundColor: 'rgba(139, 92, 246, 0.1)',
        borderWidth: 3,
        tension: 0.4,
        fill: true,
        yAxisID: 'y1'
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } }
    },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f1f5f9' }, title: { display: true, text: 'Count' } },
      y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Amount (RM)' } }
    }
  }
});

const statusLabels = <?= json_encode(array_column($statusData, 'status')) ?>;
const statusCounts = <?= json_encode(array_column($statusData, 'count')) ?>;
const statusColors = statusLabels.map(s => {
  const lower = s.toLowerCase();
  if (lower === 'valid') return '#059669';
  if (lower === 'invalid') return '#e11d48';
  if (lower === 'pending') return '#d97706';
  if (lower === 'in progress') return '#4644cf';
  return '#94a3b8';
});

const ctxStatus = document.getElementById('statusChart').getContext('2d');
new Chart(ctxStatus, {
  type: 'doughnut',
  data: {
    labels: statusLabels,
    datasets: [{
      data: statusCounts,
      backgroundColor: statusColors,
      borderWidth: 0,
      hoverOffset: 8
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 15 } }
    },
    cutout: '65%'
  }
});

// ===================== TABLE FILTERS =====================
const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');
const rows = document.querySelectorAll('#recordsBody tr');

function applyFilters() {
  const q = searchInput.value.toLowerCase().trim();
  const s = statusFilter.value;
  rows.forEach(tr => {
    const searchMatch = !q || tr.dataset.search.includes(q);
    const statusMatch = !s || tr.dataset.status === s;
    tr.style.display = (searchMatch && statusMatch) ? '' : 'none';
    tr.style.display = (searchMatch && statusMatch) ? 'table-row' : 'none';
  });
}
searchInput.addEventListener('input', applyFilters);
statusFilter.addEventListener('change', applyFilters);

// ===================== LOADING OVERLAY =====================
const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('a[href]').forEach(link => {
  link.addEventListener('click', function() {
    if (!this.href.includes('#') && !this.target) {
      overlay.classList.add('active');
    }
  });
});
</script>

</body>
</html>
