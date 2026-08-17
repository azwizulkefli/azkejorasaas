<?php
require_once '../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = $_POST['user_id'] ?? '';
    if ($_POST['action'] === 'extend_trial') {
        $hours = (int)($_POST['hours'] ?? 2);
        $stmt = $pdo->prepare("UPDATE subscriptions SET status='active_trial', trial_ends_at = NOW() + INTERVAL '{$hours} hours' WHERE user_id = ?");
        $stmt->execute([$userId]);
    } elseif ($_POST['action'] === 'activate') {
        $stmt = $pdo->prepare("UPDATE subscriptions SET status='active', period_ends_at = NOW() + INTERVAL '90 days' WHERE user_id = ?");
        $stmt->execute([$userId]);
    } elseif ($_POST['action'] === 'suspend') {
        $stmt = $pdo->prepare("UPDATE subscriptions SET status='suspended' WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    header("Location: admin.php"); exit;
}

$subscribers = $pdo->query("
    SELECT u.id, u.name, u.email, s.plan, s.status, s.price, s.trial_ends_at, s.period_ends_at
    FROM users u LEFT JOIN subscriptions s ON u.id = s.user_id
    WHERE u.role = 'customer' ORDER BY u.created_at DESC")->fetchAll();

$stats = $pdo->query("SELECT
    COUNT(*) FILTER (WHERE status='active') AS active_subs,
    COUNT(*) FILTER (WHERE status='active_trial') AS trials,
    COUNT(*) FILTER (WHERE status='past_due') AS past_due
    FROM subscriptions")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Console — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted)}
.main{max-width:1200px;margin:0 auto;padding:32px 24px}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.stats3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:28px 0}
@media(max-width:760px){.stats3{grid-template-columns:1fr}}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:30px;font-weight:800}
.stat .g{color:#059669}.stat .b{color:var(--brand)}.stat .r{color:#e11d48}
.table-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--card)}
.table-head{padding:16px 24px;border-bottom:1px solid #f1f5f9;font-weight:700}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:820px}
th{padding:14px 24px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 24px;border-bottom:1px solid #f1f5f9;color:var(--muted)}
tbody tr:hover{background:#f8fafc}
.name b{color:#1e293b}.email{font-size:12px;color:var(--faint)}
.badge{display:inline-block;margin-top:4px;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.active{background:#d1fae5;color:#059669}.badge.active_trial{background:#e0e5ff;color:#4644cf}
.badge.past_due{background:#fef3c7;color:#d97706}.badge.canceled{background:#f1f5f9;color:#64748b}.badge.suspended{background:#ffe4e6;color:#e11d48}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.abtn{border-radius:8px;padding:7px 12px;font-size:11px;font-weight:700;transition:.15s}
.abtn.trial{background:#f1f5f9;color:#475569}.abtn.trial:hover{background:#e2e8f0}
.abtn.go{background:var(--grad);color:#fff}.abtn.go:hover{opacity:.9}
.abtn.stop{background:#fff1f2;color:#e11d48}.abtn.stop:hover{background:#ffe4e6}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
</style>
</head>
<body>
<nav class="topbar">
  <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>Admin</em></span>
  <div class="top-right"><span>Logged in as <b><?= htmlspecialchars($_SESSION['user_name']) ?></b></span><a class="btn-out" href="login.php?logout=1">Sign out</a></div>
</nav>
<main class="main">
  <h1>Subscriber Management</h1>
  <p class="sub">Manage billing, suspensions and trial expiry (default 2 hours).</p>
  <div class="stats3">
    <div class="stat"><p>Active Subscriptions</p><b class="g"><?= $stats['active_subs'] ?></b></div>
    <div class="stat"><p>Active Trials</p><b class="b"><?= $stats['trials'] ?></b></div>
    <div class="stat"><p>Past Due</p><b class="r"><?= $stats['past_due'] ?></b></div>
  </div>
  <div class="table-card">
    <div class="table-head">All Subscribers</div>
    <div class="table-wrap"><table>
      <thead><tr><th>Customer</th><th>Plan / Status</th><th>Trial / Period Ends</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($subscribers as $s): ?>
        <tr>
          <td class="name"><b><?= htmlspecialchars($s['name']) ?></b><div class="email"><?= htmlspecialchars($s['email']) ?></div></td>
          <td><?= $s['plan'] ?: 'No Plan' ?> <span style="color:var(--faint)">(RM <?= $s['price'] ?>)</span><br>
              <span class="badge <?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span></td>
          <td class="mono"><?php
            if ($s['status']==='active_trial' && $s['trial_ends_at']) echo '⏱ '.date('M d, H:i', strtotime($s['trial_ends_at']));
            elseif ($s['period_ends_at']) echo date('M d, Y', strtotime($s['period_ends_at']));
            else echo '—';
          ?></td>
          <td><div class="actions">
            <?php if (in_array($s['status'], ['active_trial','suspended','past_due'])): ?>
              <form method="POST"><input type="hidden" name="user_id" value="<?= $s['id'] ?>"><input type="hidden" name="action" value="extend_trial"><input type="hidden" name="hours" value="2">
              <button class="abtn trial" title="Reset trial to 2 hours">⏱ +2h Trial</button></form>
            <?php endif; ?>
            <?php if ($s['status'] !== 'active'): ?>
              <form method="POST"><input type="hidden" name="user_id" value="<?= $s['id'] ?>"><input type="hidden" name="action" value="activate">
              <button class="abtn go">Activate (90d)</button></form>
            <?php else: ?>
              <form method="POST"><input type="hidden" name="user_id" value="<?= $s['id'] ?>"><input type="hidden" name="action" value="suspend">
              <button class="abtn stop">Suspend</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</main>
</body>
</html>
