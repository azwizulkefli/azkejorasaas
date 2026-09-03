<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup_password') {
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($new !== $confirm)                         { header("Location: main.php?err=pwd_mismatch"); exit; }
    if (strlen($new) < 6)                          { header("Location: main.php?err=pwd_short");    exit; }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? 
                           WHERE id = ? 
                           AND (password_hash IS NULL OR password_hash LIKE ?)");
    $stmt->execute([$hash, $uid, '$2y$10$GoogleOAuth%']);
    header("Location: main.php?welcome=1&pwd_set=1"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if ($name !== '') {
            $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?")
                ->execute([$name, $phone, $uid]);
        }
        
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === 0) {
            $uploadDir = __DIR__ . '/../storage/avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($ext, $allowed)) {
                $filename = $uid . '.' . $ext;
                $path = 'storage/avatars/' . $filename;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $filename)) {
                    $pdo->prepare("UPDATE users SET avatar_path = ? WHERE id = ?")
                        ->execute([$path, $uid]);
                }
            }
        }
        
        header("Location: main.php?updated=profile"); exit;
    }
    
    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if ($new !== $confirm) {
            header("Location: main.php?err=pwd_mismatch"); exit;
        }
        
        if (strlen($new) < 6) {
            header("Location: main.php?err=pwd_short"); exit;
        }
        
        $ok = password_verify($current, $me['password_hash'])
           || crypt($current, $me['password_hash']) === $me['password_hash'];
        
        if (!$ok) {
            header("Location: main.php?err=pwd_wrong"); exit;
        }
        
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$hash, $uid]);
        
        header("Location: main.php?updated=password"); exit;
    }
}

// Subscription + derived metrics
$sub = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$sub->execute([$uid]); $s = $sub->fetch();

$now = new DateTime();
$trialLeft = $periodLeft = 0; $trialPct = $periodPct = 0;
if ($s) {
    if ($s['status'] === 'active_trial' && $s['trial_ends_at']) {
        $end = new DateTime($s['trial_ends_at']);
        $trialLeft = max(0, $end->getTimestamp() - $now->getTimestamp());
        $hours = get_setting($pdo,'general','trial_default_hours',1);
        $trialPct = $hours ? min(100, round((1 - $trialLeft/($hours*3600))*100)) : 0;
    }
    if ($s['period_ends_at']) {
        $end = new DateTime($s['period_ends_at']);
        $periodLeft = max(0, $end->getTimestamp() - $now->getTimestamp());
        $periodPct = min(100, round((1 - $periodLeft/(90*86400))*100));
    }
}

// ---------------- E-INVOICE COUNTS (Updated to einvoice_records) ----------------
$einSt = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ?");
$einSt->execute([$uid]);
$einCount = (int)$einSt->fetchColumn();

$einGross = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM einvoice_records WHERE user_id = ?");
$einGross->execute([$uid]);
$einGrossV = $einGross->fetchColumn();

// Booking counts
$bkCount = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
$bkCount->execute([$uid]);
$bkCountV = $bkCount->fetchColumn();

// E-Invoice by time periods (week, month, year)
$einWeek = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ? AND created_at >= NOW() - INTERVAL '7 days'");
$einWeek->execute([$uid]);
$einWeekCount = (int)$einWeek->fetchColumn();

$einMonth = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ? AND created_at >= NOW() - INTERVAL '30 days'");
$einMonth->execute([$uid]);
$einMonthCount = (int)$einMonth->fetchColumn();

$einYear = $pdo->prepare("SELECT COUNT(*) FROM einvoice_records WHERE user_id = ? AND created_at >= NOW() - INTERVAL '1 year'");
$einYear->execute([$uid]);
$einYearCount = (int)$einYear->fetchColumn();

// E-Invoice by status (using lhdn_status)
$einByStatus = $pdo->prepare("SELECT lhdn_status, COUNT(*) as count FROM einvoice_records WHERE user_id = ? GROUP BY lhdn_status");
$einByStatus->execute([$uid]);
$statusMap = [];
while ($row = $einByStatus->fetch()) {
    $status = strtolower($row['lhdn_status'] ?? 'pending');
    $statusMap[$status] = ($statusMap[$status] ?? 0) + (int)$row['count'];
}

// Activity log (last 10 activities from multiple sources)
$activities = [];

// Recent einvoice items (using sale_no and customer_name)
$einRecent = $pdo->prepare("SELECT sale_no, customer_name, created_at FROM einvoice_records WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$einRecent->execute([$uid]);
while ($row = $einRecent->fetch()) {
    $activities[] = [
        'type' => '🧾 E-Invoice',
        'desc' => 'Processed ' . ($row['sale_no'] ?: 'Invoice') . ' for ' . ($row['customer_name'] ?: 'Customer'),
        'time' => $row['created_at']
    ];
}

// Recent bookings
$bkRecent = $pdo->prepare("SELECT 'booking' as type, status, created_at FROM bookings WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
$bkRecent->execute([$uid]);
while ($row = $bkRecent->fetch()) {
    $activities[] = [
        'type' => '📅 Booking',
        'desc' => 'Booking ' . $row['status'],
        'time' => $row['created_at']
    ];
}

// Sort by time and take top 10
usort($activities, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
$activities = array_slice($activities, 0, 10);

$fmtDate = fn($v) => $v ? (new DateTime($v))->format('M d, Y') : '—';
$fmtLeft = function($sec, $unit) {
    if ($sec <= 0) return 'Expired';
    $d = floor($sec/86400); $h = floor(($sec%86400)/3600);
    return $unit==='hours' ? "{$h}h left" : "{$d}d {$h}h left";
};

$statusBadge = fn($st) => [
    'active'=>'active','active_trial'=>'active_trial','past_due'=>'past_due',
    'canceled'=>'canceled','suspended'=>'suspended'
][$st] ?? 'none';

$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — AZ Kejora SaaS</title>
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
.btn-company{background:#e0e5ff;color:#4644cf;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.btn-company:hover{background:#c6ceff}

.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}

/* ---------- TRIAL BANNER ---------- */
.trial-banner{margin:28px 0;position:relative;overflow:hidden;border-radius:20px;background:linear-gradient(135deg,var(--brand),#7c3aed);padding:28px;color:#fff;box-shadow:0 20px 40px -20px rgba(84,87,229,.5)}
.trial-banner .glow{position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.12);filter:blur(40px)}
.trial-banner p{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#e0e5ff}
.trial-banner h2{margin-top:6px;font-size:28px;font-weight:800}
.trial-banner .sub{color:#e0e5ff;margin-top:4px;font-size:14px}
.bar{margin-top:16px;height:8px;background:rgba(255,255,255,.2);border-radius:999px;overflow:hidden}
.bar i{display:block;height:100%;background:#fff;border-radius:999px}
.cta-row{margin-top:18px;display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s}
.btn.white{background:#fff;color:var(--brand)}.btn.ghost{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25)}
.btn.primary{background:var(--grad);color:#fff;box-shadow:0 10px 24px -8px rgba(84,87,229,.5)}
.past-due{margin:24px 0;background:#fff1f2;border:1px solid #fecdd3;border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;color:#9f1239;font-weight:600}

/* ---------- STATS ---------- */
.stats4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:28px 0}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:24px;font-weight:800}
.stat small{display:block;margin-top:4px;font-size:12px;color:var(--muted)}
.stat .grad{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.stat .emerald{color:#059669}.stat .rose{color:#e11d48}
.badge{display:inline-block;margin-top:6px;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.active{background:#d1fae5;color:#059669}.badge.active_trial{background:#e0e5ff;color:#4644cf}
.badge.past_due{background:#fef3c7;color:#d97706}.badge.canceled{background:#f1f5f9;color:#64748b}
.badge.suspended{background:#ffe4e6;color:#e11d48}.badge.none{background:#f1f5f9;color:#94a3b8}

/* ---------- E-INVOICE SUMMARIES ---------- */
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

/* ---------- ACTIVITY LOG ---------- */
.activity-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card);margin-bottom:24px}
.activity-card h3{font-size:16px;font-weight:700;margin-bottom:16px}
.activity-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9}
.activity-item:last-child{border-bottom:none}
.activity-icon{width:36px;height:36px;border-radius:10px;background:#f8fafc;display:grid;place-items:center;font-size:16px;flex-shrink:0}
.activity-content{flex:1;min-width:0}
.activity-content b{font-size:13px;color:#1e293b;display:block}
.activity-content small{font-size:11px;color:var(--faint)}

/* ---------- SERVICE CARDS ---------- */
.cards2{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.svc{background:#fff;border:1px solid var(--line);border-radius:20px;padding:28px;box-shadow:var(--card);position:relative;overflow:hidden}
.svc .blob{position:absolute;right:-30px;top:-30px;width:140px;height:140px;border-radius:50%;filter:blur(40px);opacity:.6}
.svc h3{margin-top:14px;font-size:20px;font-weight:800;position:relative}
.svc p{margin-top:6px;font-size:14px;color:var(--muted);line-height:1.6;position:relative}
.ic-tile{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:20px;position:relative}
.svc .btn{margin-top:18px;position:relative}
.footer{max-width:1200px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}

/* ---------- MODAL ---------- */
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px}
.modal.open{display:grid}
.modal-card{width:100%;max-width:520px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4)}
.modal-card h3{font-size:18px;font-weight:800;margin-bottom:4px}
.modal-card .msub{font-size:13px;color:var(--muted);margin-bottom:16px}
.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input,.field select{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none}
.field input:focus,.field select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.avatar-upload{display:flex;align-items:center;gap:16px;margin-top:14px}
.avatar-preview{width:80px;height:80px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:24px;overflow:hidden}
.avatar-preview img{width:100%;height:100%;object-fit:cover}
.mrow{display:flex;gap:10px;margin-top:20px}
.mrow .btn{flex:1;text-align:center}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 18px;font-size:13px;font-weight:700}
.btn-save:hover{opacity:.9}
.cancel{background:#f1f5f9;color:#475569;border-radius:10px;padding:11px 18px;font-size:13px;font-weight:700}
.tabs{display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid var(--line)}
.tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;cursor:pointer}
.tab.active{color:var(--brand);border-bottom-color:var(--brand)}

/* ---------- RESPONSIVE ---------- */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  .stats4{grid-template-columns:repeat(2,1fr)}
  .cards2{grid-template-columns:1fr}
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
    <a href="main.php" class="menu-item active">🏠 Home</a>
    <a href="e-invoice.php" class="menu-item">🧾 E-Invoice</a>
    <div class="menu-section">Subscription</div>
    <a href="s_payment.php" class="menu-item">🧾 Payment</a>
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
      <button class="avatar" onclick="openProfile()">
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

    <?php if (isset($_GET['welcome'])): ?>
      <div class="banner success">🎉 Welcome back! Your dashboard is ready.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'profile'): ?>
      <div class="banner success">✓ Profile updated successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'password'): ?>
      <div class="banner success">✓ Password changed successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'pwd_mismatch'): ?>
      <div class="banner error">✗ New passwords do not match.</div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'pwd_short'): ?>
      <div class="banner error">✗ Password must be at least 6 characters.</div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'pwd_wrong'): ?>
      <div class="banner error">✗ Current password is incorrect.</div>
    <?php endif; ?>

    <h1>Welcome back, <span style="background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent"><?= htmlspecialchars(explode(' ',$me['name'])[0]) ?></span> 👋</h1>
    <p class="sub">Here's what's happening across your services today.</p>

    <?php if ($s && $s['status'] === 'active_trial'): ?>
    <div class="trial-banner">
      <span class="glow"></span>
      <p>Active trial · no card required</p>
      <h2><?= $fmtLeft($trialLeft, 'hours') ?></h2>
      <p class="sub">Trial ends <?= $fmtDate($s['trial_ends_at']) ?> — subscribe to keep your data & tools.</p>
      <div class="bar"><i style="width:<?= $trialPct ?>%"></i></div>
      <div class="cta-row">
        <a href="#plans" class="btn white">Choose a 3-month plan</a>
        <a href="#billing" class="btn ghost">View billing portal</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($s && $s['status'] === 'past_due'): ?>
    <div class="past-due">
      ⚠ Your last payment failed — subscription is past due. Update your method to restore access.
      <a class="btn primary" style="margin-left:auto" href="#pay">Pay now</a>
    </div>
    <?php endif; ?>

    <!-- ========== STATS CARDS ========== -->
    <div class="stats4">
      <div class="stat">
        <p>Plan status</p>
        <span class="badge <?= $statusBadge($s['status'] ?? 'none') ?>"><?= htmlspecialchars($s['status'] ?? 'No plan') ?></span>
        <small><?= $s['plan'] ? htmlspecialchars($s['plan']) . ' · RM ' . $s['price'] . ' / 90d' : 'No active plan' ?></small>
      </div>
      <div class="stat">
        <p><?= $s['status'] === 'active_trial' ? 'Trial ends' : 'Renews on' ?></p>
        <b><?= $fmtDate($s['status'] === 'active_trial' ? $s['trial_ends_at'] : $s['period_ends_at']) ?></b>
        <small><?= $s['status']==='active' ? $fmtLeft($periodLeft,'days') : '' ?></small>
      </div>
      <div class="stat">
        <p>Invoices processed</p>
        <b><?= $einCount ?></b>
        <small>RM <?= number_format((float)$einGrossV, 0) ?> gross value</small>
      </div>
      <div class="stat">
        <p>My bookings</p>
        <b><?= $bkCountV ?></b>
        <small>upcoming & past</small>
      </div>
    </div>

    <!-- ========== E-INVOICE SUMMARIES ========== -->
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
      <div class="status-card">
        <b class="status-new"><?= $statusMap['new'] ?? 0 ?></b>
        <p>New</p>
      </div>
      <div class="status-card">
        <b class="status-submitted"><?= $statusMap['submitted'] ?? 0 ?></b>
        <p>Submitted</p>
      </div>
      <div class="status-card">
        <b class="status-valid"><?= $statusMap['valid'] ?? $statusMap['validated'] ?? 0 ?></b>
        <p>Valid</p>
      </div>
      <div class="status-card">
        <b class="status-invalid"><?= $statusMap['invalid'] ?? 0 ?></b>
        <p>Invalid</p>
      </div>
      <div class="status-card">
        <b class="status-progress"><?= $statusMap['in_progress'] ?? $statusMap['pending'] ?? 0 ?></b>
        <p>In Progress</p>
      </div>
      <div class="status-card">
        <b class="status-fail"><?= $statusMap['fail'] ?? $statusMap['failed'] ?? 0 ?></b>
        <p>Failed</p>
      </div>
    </div>

    <!-- ========== ACTIVITY LOG ========== -->
    <div class="activity-card">
      <h3>📜 Recent Activity</h3>
      <?php if (empty($activities)): ?>
        <p style="text-align:center;padding:20px;color:var(--faint);font-size:13px">No recent activity yet.</p>
      <?php else: ?>
        <?php foreach ($activities as $act): ?>
          <div class="activity-item">
            <div class="activity-icon"><?= explode(' ', $act['type'])[0] ?></div>
            <div class="activity-content">
              <b><?= htmlspecialchars($act['type']) ?></b>
              <small><?= htmlspecialchars($act['desc']) ?> · <?= date('M d, H:i', strtotime($act['time'])) ?></small>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ========== SERVICE CARDS ========== -->
    <div class="cards2">
      <div class="svc">
        <span class="blob" style="background:#fef3c7"></span>
        <span class="ic-tile" style="background:linear-gradient(135deg,#f59e0b,#f97316)">🧾</span>
        <h3>E-Invoice for SME</h3>
        <p>Upload CSV / PDF / JSON, auto-extract line items, compute SST and export compliance reports.</p>
        <a href="e-invoice.php" class="btn primary">Open tool →</a>
      </div>
      <div class="svc">
        <span class="blob" style="background:#fae8ff"></span>
        <span class="ic-tile" style="background:linear-gradient(135deg,#d946ef,#ec4899)">📅</span>
        <h3>Retail Facility Booking</h3>
        <p>Manage your facilities & rates, or book courts, rooms and halls on the public portal.</p>
        <div class="cta-row">
          <a href="#" class="btn primary">Merchant console</a>
          <a href="#" class="btn ghost" style="background:#f1f5f9;color:#475569;border-color:var(--line)">Public portal</a>
        </div>
      </div>
    </div>

  </main>

  <footer class="footer">© 2026 AZ Kejora SaaS · Supabase PostgreSQL · <?= htmlspecialchars($me['email']) ?></footer>
</div>

<!-- Profile Modal -->
<div class="modal" id="profileModal">
  <div class="modal-card">
    <div class="tabs">
      <div class="tab active" data-tab="info">Profile Info</div>
      <div class="tab" data-tab="password">Change Password</div>
    </div>
    
    <form method="POST" enctype="multipart/form-data" class="action-form" id="profileForm">
      <input type="hidden" name="action" value="update_profile">
      <h3>Update Profile</h3>
      <p class="msub">Change your name, phone and profile picture.</p>
      
      <div class="avatar-upload">
        <div class="avatar-preview" id="avatarPreview">
          <?php if ($avatarSrc): ?>
            <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Avatar" id="avatarImg">
          <?php else: ?>
            <?= strtoupper(substr($me['name'],0,1)) ?>
          <?php endif; ?>
        </div>
        <div>
          <label class="btn ghost" style="cursor:pointer;padding:8px 14px;font-size:12px">
            Upload Picture
            <input type="file" name="avatar" accept="image/*" style="display:none" id="avatarInput">
          </label>
          <p style="font-size:11px;color:var(--faint);margin-top:4px">JPG, PNG or GIF · Max 2MB</p>
        </div>
      </div>
      
      <div class="field"><label>Full name</label><input type="text" name="name" value="<?= htmlspecialchars($me['name']) ?>" required></div>
      <div class="field"><label>Phone</label><input type="tel" name="phone" value="<?= htmlspecialchars($me['phone'] ?? '') ?>" placeholder="+60 12-345 6789"></div>
      
      <div class="mrow">
        <button type="submit" class="btn-save">Save changes</button>
        <button type="button" class="cancel" onclick="closeProfile()">Cancel</button>
      </div>
    </form>
    
    <form method="POST" class="action-form" id="passwordForm" style="display:none">
      <input type="hidden" name="action" value="change_password">
      <h3>Change Password</h3>
      <p class="msub">Update your account password.</p>
      
      <div class="field"><label>Current password</label><input type="password" name="current_password" required></div>
      <div class="field"><label>New password</label><input type="password" name="new_password" minlength="6" required></div>
      <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="6" required></div>
      
      <div class="mrow">
        <button type="submit" class="btn-save">Update password</button>
        <button type="button" class="cancel" onclick="closeProfile()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

const modal = document.getElementById('profileModal');
function openProfile(){ modal.classList.add('open'); }
function closeProfile(){ modal.classList.remove('open'); }
modal.addEventListener('click', e => { if (e.target === modal) closeProfile(); });

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const tabName = tab.dataset.tab;
    document.getElementById('profileForm').style.display = tabName === 'info' ? 'block' : 'none';
    document.getElementById('passwordForm').style.display = tabName === 'password' ? 'block' : 'none';
  });
});

document.getElementById('avatarInput').addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const preview = document.getElementById('avatarPreview');
      preview.innerHTML = '<img src="' + e.target.result + '" alt="Avatar" id="avatarImg">';
    };
    reader.readAsDataURL(file);
  }
});

const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function(e) {
    if (this.onsubmit && !this.onsubmit(e)) return;
    overlay.classList.add('active');
  });
});
document.querySelectorAll('a[href]').forEach(link => {
  link.addEventListener('click', function() {
    if (!this.href.includes('#') && !this.target) {
      overlay.classList.add('active');
    }
  });
});
</script>

<?php
// Helper function check (ensure this exists in your auth.php or settings.php)
if (!function_exists('needsPasswordSetup')) {
    function needsPasswordSetup() {
        global $me;
        return empty($me['password_hash']) || strpos($me['password_hash'], '$2y$10$GoogleOAuth') === 0;
    }
}
$showSetup = needsPasswordSetup() || (isset($_GET['setup_pwd']) && $_GET['setup_pwd'] == 1);
?>
<div class="modal <?= $showSetup ? 'open' : '' ?>" id="setupPwdModal" style="z-index:80">
  <div class="modal-card">
    <form method="POST" class="action-form">
      <input type="hidden" name="action" value="setup_password">
      <span class="logo" style="margin:0 auto;width:48px;height:48px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:20px">🔐</span>
      <h3 style="text-align:center;margin-top:16px">Set your password</h3>
      <p class="msub" style="text-align:center">You signed up with Google. Please set a password so you can also sign in with email if needed.</p>
      <?php if (isset($_GET['err'])): ?>
        <div style="margin-top:12px;background:#fff1f2;color:#e11d48;border-radius:10px;padding:10px;font-size:12px;text-align:center">
          <?= $_GET['err'] === 'pwd_mismatch' ? 'Passwords do not match.' : 'Password must be at least 6 characters.' ?>
        </div>
      <?php endif; ?>
      <div class="field"><label>New password</label><input type="password" name="new_password" minlength="6" required autofocus></div>
      <div class="field"><label>Confirm password</label><input type="password" name="confirm_password" minlength="6" required></div>
      <div class="mrow"><button class="btn-save" type="submit">Save password</button></div>
    </form>
  </div>
</div>
    
</body>
</html>
