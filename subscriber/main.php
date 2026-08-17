<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

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

$einCount  = (int)$pdo->prepare("SELECT COUNT(*) FROM einvoice_items WHERE user_id = ?")->execute([$uid]) ?: $pdo->query("SELECT COUNT(*) FROM einvoice_items WHERE user_id = '$uid'")->fetchColumn();
$einSt = $pdo->prepare("SELECT COUNT(*) FROM einvoice_items WHERE user_id = ?"); $einSt->execute([$uid]);
$einGross = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM einvoice_items WHERE user_id = ?"); $einGross->execute([$uid]); $einGrossV = $einGross->fetchColumn();

$bkCount = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?"); $bkCount->execute([$uid]); $bkCountV = $bkCount->fetchColumn();

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
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted)}
.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px}
.main{max-width:1200px;margin:0 auto;padding:32px 24px}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:20px 0 0;background:#d1fae5;color:#059669;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
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
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.past-due{margin:24px 0;background:#fff1f2;border:1px solid #fecdd3;border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;color:#9f1239;font-weight:600}
.stats4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:28px 0}
@media(max-width:900px){.stats4{grid-template-columns:repeat(2,1fr)}}
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
.cards2{display:grid;grid-template-columns:1fr 1fr;gap:24px}
@media(max-width:800px){.cards2{grid-template-columns:1fr}}
.svc{background:#fff;border:1px solid var(--line);border-radius:20px;padding:28px;box-shadow:var(--card);position:relative;overflow:hidden}
.svc .blob{position:absolute;right:-30px;top:-30px;width:140px;height:140px;border-radius:50%;filter:blur(40px);opacity:.6}
.svc h3{margin-top:14px;font-size:20px;font-weight:800;position:relative}
.svc p{margin-top:6px;font-size:14px;color:var(--muted);line-height:1.6;position:relative}
.ic-tile{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:20px;position:relative}
.svc .btn{margin-top:18px;position:relative}
.footer{max-width:1200px;margin:24px auto;padding:0 24px 32px;font-size:12px;color:var(--faint);text-align:center}
</style>
</head>
<body>

<nav class="topbar">
  <a href="main.php" class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></a>
  <div class="top-right">
    <span>Welcome, <b><?= htmlspecialchars($me['name']) ?></b></span>
    <span class="avatar"><?= strtoupper(substr($me['name'],0,1)) ?></span>
    <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
  </div>
</nav>

<main class="main">

  <?php if (isset($_GET['welcome'])): ?>
    <div class="banner">🎉 Account activated! Your free trial has started.</div>
  <?php endif; ?>

  <h1>Welcome back, <span style="background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent"><?= htmlspecialchars(explode(' ',$me['name'])[0]) ?></span> 👋</h1>
  <p class="sub">Here's what's happening across your services today.</p>

  <!-- Trial banner -->
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

  <!-- 4 stat cards -->
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

  <!-- Service cards -->
  <div class="cards2">
    <div class="svc">
      <span class="blob" style="background:#fef3c7"></span>
      <span class="ic-tile" style="background:linear-gradient(135deg,#f59e0b,#f97316)">🧾</span>
      <h3>E-Invoice for SME</h3>
      <p>Upload CSV / PDF / JSON, auto-extract line items, compute SST and export compliance reports.</p>
      <a href="#" class="btn primary">Open tool →</a>
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

</body>
</html>
