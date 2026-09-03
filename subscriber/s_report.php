<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// ============================================================
// 1. FETCH ALL PAYMENT RECORDS (each subscription row = a payment)
// ============================================================
$stmt = $pdo->prepare("
    SELECT *
    FROM subscriptions
    WHERE user_id = ?
      AND payment_date IS NOT NULL
    ORDER BY payment_date DESC
");
$stmt->execute([$uid]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 2. SUMMARY STATS
// ============================================================
$totalPaid     = 0.0;
$countPaid     = count($payments);
$lastPaymentAt = $payments[0]['payment_date'] ?? null;

foreach ($payments as $p) {
    $totalPaid += (float)($p['amount'] ?? 0);
}

// Next billing date from the *current* (most recent) active subscription
$active = $pdo->prepare("
    SELECT period_ends_at, status, plan, price
    FROM subscriptions
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$active->execute([$uid]);
$current = $active->fetch();

// ============================================================
// 3. FORMATTERS
// ============================================================
$fmtDate = fn($v) => $v ? (new DateTime($v))->format('d M Y') : '—';
$fmtDateTime = fn($v) => $v ? (new DateTime($v))->format('d M Y, h:i A') : '—';
$fmtMoney = fn($v) => 'RM ' . number_format((float)$v, 2);

$payTypeLabel = function($t) {
    return [
        'stripe'    => '💳 Card (Stripe)',
        'fpx'       => '🏦 FPX Online Banking',
        'toyyibpay' => '📱 ToyyibPay DuitNow',
        'manual'    => '💵 Manual Transfer',
    ][$t] ?? ucfirst((string)$t);
};

$statusBadge = fn($st) => [
    'active'       => ['bg:#d1fae5','color:#059669','Active'],
    'active_trial' => ['bg:#e0e5ff','color:#4644cf','Trial'],
    'past_due'     => ['bg:#fef3c7','color:#d97706','Past Due'],
    'canceled'     => ['bg:#f1f5f9','color:#64748b','Canceled'],
    'suspended'    => ['bg:#ffe4e6','color:#e11d48','Suspended'],
][$st] ?? ['bg:#f1f5f9','color:#94a3b8','—'];

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment History — AZ Kejora SaaS</title>
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
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}

/* ---------- STATS ---------- */
.stats4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:28px 0}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:24px;font-weight:800}
.stat small{display:block;margin-top:4px;font-size:12px;color:var(--muted)}
.stat .grad{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.stat .emerald{color:#059669}.stat .rose{color:#e11d48}

/* ---------- FILTER BAR ---------- */
.filter-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px}
.filter-bar input,.filter-bar select{border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;background:#fff;outline:none;font-family:inherit}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}

/* ---------- PAYMENTS TABLE ---------- */
.table-wrap{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);overflow:hidden}
.pay-table{width:100%;border-collapse:collapse;font-size:13px}
.pay-table thead{background:#f8fafc}
.pay-table th{padding:14px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);border-bottom:1px solid var(--line)}
.pay-table td{padding:16px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.pay-table tbody tr:last-child td{border-bottom:none}
.pay-table tbody tr:hover{background:#fafbff}
.pay-table .mono{font-family:'JetBrains Mono','Courier New',monospace;font-size:12px;color:var(--muted)}
.pay-table .amount{font-weight:800;font-size:15px;color:var(--ink)}
.pay-table .amount.grad{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}

.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge-pay{background:#e0e5ff;color:#4644cf}
.badge-sub{background:#d1fae5;color:#059669}

.btn-view{display:inline-flex;align-items:center;gap:6px;background:#eef1ff;color:var(--brand-dark);border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;transition:.15s}
.btn-view:hover{background:#c6ceff;color:#fff}
.btn-primary{background:var(--grad);color:#fff;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 20px -8px rgba(84,87,229,.4)}
.btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text);border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px}

.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-state .ic{font-size:48px;margin-bottom:12px;opacity:.4}
.empty-state h3{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:4px}
.empty-state p{font-size:13px;max-width:400px;margin:0 auto}

/* ---------- RECEIPT MODAL ---------- */
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px}
.modal.open{display:grid}
.modal-card{width:100%;max-width:760px;background:#fff;border-radius:20px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);max-height:90vh;display:flex;flex-direction:column}
.modal-head{padding:20px 24px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.modal-head h3{font-size:18px;font-weight:800}
.modal-head .actions{display:flex;gap:8px}
.modal-body{overflow:auto;padding:0}
.modal-foot{padding:16px 24px;border-top:1px solid var(--line);background:#f8fafc;font-size:12px;color:var(--faint);text-align:center}

/* ---------- RECEIPT CONTENT (screen view) ---------- */
.receipt{background:#fff;padding:40px 48px;font-size:14px;color:var(--ink)}
.receipt-head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;padding-bottom:24px;border-bottom:2px solid var(--line);margin-bottom:24px}
.receipt-head .company h2{font-size:22px;font-weight:800;background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.receipt-head .company p{font-size:12px;color:var(--muted);line-height:1.6;margin-top:4px}
.receipt-head .tag{text-align:right}
.receipt-head .tag .stamp{display:inline-block;background:var(--grad);color:#fff;padding:6px 14px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px}
.receipt-head .tag b{display:block;font-size:18px;font-weight:800;font-family:'JetBrains Mono',monospace}
.receipt-head .tag small{font-size:11px;color:var(--muted)}

.receipt-section{margin-bottom:24px}
.receipt-section h4{font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);margin-bottom:10px}
.receipt-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.receipt-grid .col p{font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px}
.receipt-grid .col b{display:block;font-size:13px;font-weight:700}

.pay-breakdown{border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-top:8px}
.pay-breakdown .row{display:flex;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px}
.pay-breakdown .row:last-child{border-bottom:none;background:#f8fafc;font-weight:800;font-size:15px;padding:16px}
.pay-breakdown .row span:first-child{color:var(--muted)}
.pay-breakdown .row span:last-child{font-weight:700}
.pay-breakdown .row.total span:last-child{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent;font-size:18px}

.receipt-footer{margin-top:28px;padding-top:20px;border-top:1px dashed var(--line);text-align:center;font-size:11px;color:var(--faint);line-height:1.7}
.receipt-footer strong{color:var(--ink);font-weight:700}

/* ---------- RESPONSIVE ---------- */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  .stats4{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
  .stats4{grid-template-columns:1fr}
  .table-wrap{overflow-x:auto}
  .pay-table{min-width:820px}
  .receipt{padding:24px}
  .receipt-head{flex-direction:column}
  .receipt-head .tag{text-align:left}
  .receipt-grid{grid-template-columns:1fr}
}

/* ---------- PRINT STYLES (receipt PDF) ---------- */
@media print {
  @page { size: A4; margin: 15mm; }
  body * { visibility: hidden !important; }
  body { background: #fff !important; }
  #printArea, #printArea * { visibility: visible !important; }
  #printArea {
    position: absolute !important;
    left: 0; top: 0; width: 100%;
    padding: 0 !important; margin: 0 !important;
    background: #fff !important;
  }
  .modal-card { box-shadow: none !important; border-radius: 0 !important; max-height: none !important; }
  .receipt { padding: 0 !important; }
  .modal-head, .modal-foot { display: none !important; }
  a { color: inherit !important; }
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
    <a href="e-invoice.php" class="menu-item">🧾 E-Invoice</a>
    <div class="menu-section">Subscription</div>
    <a href="s_payment.php" class="menu-item active">🧾 Payment</a>
    <a href="s_report.php" class="menu-item">🧾 Report</a>
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

    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'paid'): ?>
      <div class="banner success">✓ Payment successful — your subscription has been extended.</div>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px">
      <div>
        <h1>Payment History</h1>
        <p class="sub">View all your subscription payments and download receipts.</p>
      </div>
      <a href="main.php" class="btn-ghost">← Back to Dashboard</a>
    </div>

    <!-- STATS CARDS -->
    <div class="stats4">
      <div class="stat">
        <p>Total Paid</p>
        <b class="grad"><?= $fmtMoney($totalPaid) ?></b>
        <small>lifetime subscription value</small>
      </div>
      <div class="stat">
        <p>Transactions</p>
        <b><?= $countPaid ?></b>
        <small>successful payments</small>
      </div>
      <div class="stat">
        <p>Last Payment</p>
        <b style="font-size:17px"><?= $lastPaymentAt ? $fmtDate($lastPaymentAt) : '—' ?></b>
        <small><?= $countPaid > 0 ? 'Most recent charge' : 'No payments yet' ?></small>
      </div>
      <div class="stat">
        <p>Next Billing</p>
        <b style="font-size:17px"><?= ($current && $current['period_ends_at']) ? $fmtDate($current['period_ends_at']) : '—' ?></b>
        <small><?= $current ? htmlspecialchars($current['plan'] ?? 'No active plan') : 'No active plan' ?></small>
      </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
      <input type="text" id="searchInput" placeholder="🔍 Search receipt no, reference, bank…">
      <select id="methodFilter">
        <option value="">All payment methods</option>
        <option value="stripe">Card (Stripe)</option>
        <option value="fpx">FPX Online Banking</option>
        <option value="toyyibpay">ToyyibPay DuitNow</option>
        <option value="manual">Manual Transfer</option>
      </select>
      <select id="yearFilter">
        <option value="">All years</option>
        <?php
        $years = [];
        foreach ($payments as $p) {
            if ($p['payment_date']) {
                $y = (new DateTime($p['payment_date']))->format('Y');
                $years[$y] = true;
            }
        }
        krsort($years);
        foreach (array_keys($years) as $y): ?>
          <option value="<?= $y ?>"><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- PAYMENTS TABLE -->
    <div class="table-wrap">
      <?php if (empty($payments)): ?>
        <div class="empty-state">
          <div class="ic">💳</div>
          <h3>No payment history yet</h3>
          <p>Your payments from Stripe, FPX, and ToyyibPay will appear here after your first successful charge.</p>
        </div>
      <?php else: ?>
        <table class="pay-table">
          <thead>
            <tr>
              <th>Receipt</th>
              <th>Date</th>
              <th>Plan</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Amount</th>
              <th>Status</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody id="paymentsBody">
            <?php foreach ($payments as $p):
              $sb = $statusBadge($p['status'] ?? 'none');
            ?>
            <tr data-search="<?= strtolower(
                ($p['receipt_no'] ?? '') . ' ' .
                ($p['ref_no'] ?? '') . ' ' .
                ($p['bank'] ?? '') . ' ' .
                ($p['payment_type'] ?? '')
            ) ?>"
            data-method="<?= htmlspecialchars($p['payment_type'] ?? '') ?>"
            data-year="<?= $p['payment_date'] ? (new DateTime($p['payment_date']))->format('Y') : '' ?>">
              <td><span class="mono"><?= htmlspecialchars($p['receipt_no'] ?: '—') ?></span></td>
              <td><?= $fmtDate($p['payment_date']) ?></td>
              <td>
                <strong><?= htmlspecialchars($p['plan'] ?: '—') ?></strong>
              </td>
              <td><?= $payTypeLabel($p['payment_type']) ?></td>
              <td>
                <span class="mono"><?= htmlspecialchars($p['ref_no'] ?: '—') ?></span>
                <?php if (!empty($p['bank'])): ?>
                  <div style="font-size:11px;color:var(--faint);margin-top:2px"><?= htmlspecialchars($p['bank']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="amount grad"><?= $fmtMoney($p['amount'] ?? 0) ?></span></td>
              <td><span class="pill" style="background:<?= $sb[0] ?>;color:<?= $sb[1] ?>"><?= $sb[2] ?></span></td>
              <td style="text-align:right">
                <button class="btn-view" onclick='openReceipt(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
                  📄 View Receipt
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- ============ RECEIPT MODAL ============ -->
<div class="modal" id="receiptModal" onclick="if(event.target===this)closeReceipt()">
  <div class="modal-card">
    <div class="modal-head">
      <h3>📄 Payment Receipt</h3>
      <div class="actions">
        <button class="btn-ghost" onclick="printReceipt()" style="padding:8px 14px">
          🖨 Print / Save PDF
        </button>
        <button class="btn-ghost" onclick="closeReceipt()" style="padding:8px 14px">✕</button>
      </div>
    </div>
    <div class="modal-body">
      <div id="printArea" class="receipt">
        <!-- Header -->
        <div class="receipt-head">
          <div class="company">
            <h2>⚡ AZ Kejora SaaS</h2>
            <p>
              E-Invoice & Facility Booking Platform<br>
              Kuala Lumpur, Malaysia<br>
              support@azkejora.io · +60 3-0000 0000
            </p>
          </div>
          <div class="tag">
            <span class="stamp">Official Receipt</span>
            <b id="r-receipt-no">—</b>
            <small id="r-issue-date">—</small>
          </div>
        </div>

        <!-- Bill To + Payment Info -->
        <div class="receipt-section">
          <div class="receipt-grid">
            <div class="col">
              <h4>Billed To</h4>
              <p>Customer Name</p>
              <b id="r-customer"><?= htmlspecialchars($me['name']) ?></b>
              <p style="margin-top:8px">Email</p>
              <b id="r-email"><?= htmlspecialchars($me['email']) ?></b>
              <p style="margin-top:8px">Phone</p>
              <b id="r-phone"><?= htmlspecialchars($me['phone'] ?? '—') ?></b>
            </div>
            <div class="col">
              <h4>Payment Details</h4>
              <p>Payment Date</p>
              <b id="r-pay-date">—</b>
              <p style="margin-top:8px">Payment Method</p>
              <b id="r-method">—</b>
              <p style="margin-top:8px">Bank / Provider</p>
              <b id="r-bank">—</b>
              <p style="margin-top:8px">Reference No.</p>
              <b id="r-ref" style="font-family:'JetBrains Mono',monospace">—</b>
            </div>
          </div>
        </div>

        <!-- Breakdown -->
        <div class="receipt-section">
          <h4>Payment Breakdown</h4>
          <div class="pay-breakdown">
            <div class="row">
              <span>Subscription Plan</span>
              <b id="r-plan">—</b>
            </div>
            <div class="row">
              <span>Billing Cycle</span>
              <b>90 days (3 months)</b>
            </div>
            <div class="row">
              <span>Period Start</span>
              <b id="r-period-start">—</b>
            </div>
            <div class="row">
              <span>Period End</span>
              <b id="r-period-end">—</b>
            </div>
            <div class="row">
              <span>Subtotal</span>
              <span id="r-subtotal">RM 0.00</span>
            </div>
            <div class="row">
              <span>SST (8%)</span>
              <span id="r-sst">RM 0.00</span>
            </div>
            <div class="row total">
              <span>TOTAL PAID</span>
              <span id="r-total">RM 0.00</span>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="receipt-footer">
          <p>
            <strong>Thank you for your payment.</strong><br>
            This receipt was generated automatically by the AZ Kejora SaaS payment gateway.<br>
            For queries, contact <strong>support@azkejora.io</strong>.
          </p>
          <p style="margin-top:8px;font-size:10px">
            Printed on <?= date('d M Y, h:i A') ?> · Receipt generated for <?= htmlspecialchars($me['email']) ?>
          </p>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      💡 Tip: Click "Print / Save PDF" → choose "Save as PDF" as the destination to download this receipt.
    </div>
  </div>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

// ===================== RECEIPT MODAL =====================
const receiptModal = document.getElementById('receiptModal');

function openReceipt(p) {
  // Compute period start (90 days before period_ends_at)
  let periodStart = '—', periodEnd = '—';
  if (p.period_ends_at) {
    const end = new Date(p.period_ends_at);
    const start = new Date(end);
    start.setDate(start.getDate() - 90);
    periodStart = start.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
    periodEnd = end.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
  } else if (p.payment_date) {
    const start = new Date(p.payment_date);
    const end = new Date(start);
    end.setDate(end.getDate() + 90);
    periodStart = start.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
    periodEnd = end.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
  }

  const amount = parseFloat(p.amount || 0);
  const sstRate = 0.08;
  // Amount is assumed to be the total paid (gross). Back-calculate subtotal + SST.
  const subtotal = amount / (1 + sstRate);
  const sst = amount - subtotal;

  const methodLabels = {
    'stripe':    '💳 Credit/Debit Card (Stripe)',
    'fpx':       '🏦 FPX Online Banking',
    'toyyibpay': '📱 ToyyibPay DuitNow QR',
    'manual':    '💵 Manual Bank Transfer'
  };
  const fmtDate = v => v ? new Date(v).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '—';
  const fmtMoney = v => 'RM ' + Number(v).toFixed(2);

  document.getElementById('r-receipt-no').textContent = p.receipt_no || 'N/A';
  document.getElementById('r-issue-date').textContent = fmtDate(p.payment_date);
  document.getElementById('r-pay-date').textContent = fmtDate(p.payment_date);
  document.getElementById('r-method').textContent = methodLabels[p.payment_type] || p.payment_type || '—';
  document.getElementById('r-bank').textContent = p.bank || (p.payment_type === 'stripe' ? 'Stripe' : p.payment_type === 'toyyibpay' ? 'ToyyibPay' : '—');
  document.getElementById('r-ref').textContent = p.ref_no || '—';
  document.getElementById('r-plan').textContent = p.plan || '—';
  document.getElementById('r-period-start').textContent = periodStart;
  document.getElementById('r-period-end').textContent = periodEnd;
  document.getElementById('r-subtotal').textContent = fmtMoney(subtotal);
  document.getElementById('r-sst').textContent = fmtMoney(sst);
  document.getElementById('r-total').textContent = fmtMoney(amount);

  receiptModal.classList.add('open');
}

function closeReceipt() {
  receiptModal.classList.remove('open');
}

function printReceipt() {
  window.print();
}

// ===================== FILTERS =====================
const searchInput = document.getElementById('searchInput');
const methodFilter = document.getElementById('methodFilter');
const yearFilter = document.getElementById('yearFilter');
const rows = document.querySelectorAll('#paymentsBody tr');

function applyFilters() {
  const q = searchInput.value.toLowerCase().trim();
  const m = methodFilter.value;
  const y = yearFilter.value;
  rows.forEach(tr => {
    const searchMatch = !q || tr.dataset.search.includes(q);
    const methodMatch = !m || tr.dataset.method === m;
    const yearMatch = !y || tr.dataset.year === y;
    tr.style.display = (searchMatch && methodMatch && yearMatch) ? '' : 'none';
  });
}
searchInput.addEventListener('input', applyFilters);
methodFilter.addEventListener('change', applyFilters);
yearFilter.addEventListener('change', applyFilters);

// ===================== LOADING OVERLAY =====================
const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('a[href]').forEach(link => {
  link.addEventListener('click', function() {
    if (!this.href.includes('#') && !this.target && !this.classList.contains('btn-view')) {
      overlay.classList.add('active');
    }
  });
});
</script>

</body>
</html>
