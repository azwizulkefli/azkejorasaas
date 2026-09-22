<?php
require_once '../includes/auth.php';
require_once '../includes/settings.php';
requireAdmin();
ensure_settings_table($pdo);

$trialHours = max(1, (int)get_setting($pdo, 'general', 'trial_default_hours', 1));
$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$back = http_build_query(array_filter(['q' => $q, 'page' => $page]));

/* ---------------- POST ACTIONS ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = trim($_POST['user_id'] ?? '');
    $subId  = trim($_POST['sub_id']  ?? '');

    /* ---- EXPIRE NOW (new) : update subscriptions table ---- */
    if ($_POST['action'] === 'expire_now' && $subId !== '') {
        $pdo->prepare("UPDATE subscriptions SET
                status         = 'expired',
                trial_ends_at  = CASE WHEN status = 'active_trial' THEN NOW() ELSE trial_ends_at END,
                period_ends_at = CASE WHEN status = 'active_trial' THEN period_ends_at ELSE NOW() END
                WHERE id = ?")->execute([$subId]);
        header("Location: admin.php?expired=1&" . $back); exit;

    } elseif ($_POST['action'] === 'extend_trial' && $subId !== '') {
        $st = $pdo->prepare("UPDATE subscriptions SET status='active_trial', trial_ends_at = NOW() + (? * INTERVAL '1 hour') WHERE id = ?");
        $st->execute([$trialHours, $subId]);
        if ($st->rowCount() === 0 && $userId !== '') {
            $ins = $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, trial_ends_at) VALUES (?,'active_trial',0, NOW() + (? * INTERVAL '1 hour')) RETURNING id");
            $ins->execute([$userId, $trialHours]);
            $newSub = $ins->fetchColumn();
            $pdo->prepare("UPDATE users SET subscription_id = ? WHERE id = ?")->execute([$newSub, $userId]);
        }

    } elseif ($_POST['action'] === 'activate' && $subId !== '') {
        $pdo->prepare("UPDATE subscriptions SET status='active', period_ends_at = NOW() + INTERVAL '90 days' WHERE id = ?")
            ->execute([$subId]);

    } elseif ($_POST['action'] === 'suspend' && $subId !== '') {
        $pdo->prepare("UPDATE subscriptions SET status='suspended' WHERE id = ?")->execute([$subId]);

    } elseif ($_POST['action'] === 'save_profile' && $userId !== '') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ? AND role = 'customer'")
                ->execute([$name, $email, $userId]);

    } elseif ($_POST['action'] === 'save_settings') {
        foreach (($_POST['setting'] ?? []) as $module => $pairs) {
            if (!is_array($pairs)) continue;
            foreach ($pairs as $key => $value) set_setting($pdo, $module, $key, trim((string)$value));
        }
        header("Location: admin.php?saved=1&" . $back); exit;

    } elseif ($_POST['action'] === 'delete_user' && $userId !== '') {
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $chk->execute([$userId]);
            if ($chk->fetchColumn() === 'customer') {
                /* 1) Null-out subscription FK pointers (RESTRICT-safe) */
                $pdo->prepare("UPDATE users SET subscription_id = NULL WHERE id = ? OR subscription_id IN (SELECT id FROM subscriptions WHERE user_id = ?)")->execute([$userId, $userId]);
                $pdo->prepare("UPDATE companies SET subscription_id = NULL WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("UPDATE subscriber_users SET subscription_id = NULL WHERE owner_id = ? OR user_id = ?")->execute([$userId, $userId]);
                /* 2) Team-member rows (owner or member) */
                $pdo->prepare("DELETE FROM subscriber_users WHERE owner_id = ? OR user_id = ?")->execute([$userId, $userId]);
                /* 3) Child tables in FK-safe order */
                foreach (['einvoice_records', 'einvoice_uploads', 'einvoice_consolidated', 'subscriptions_billing', 'transactions', 'companies', 'subscriptions'] as $tbl) {
                    if ($pdo->query("SELECT to_regclass('public." . $tbl . "')")->fetchColumn()) {
                        $pdo->prepare("DELETE FROM " . $tbl . " WHERE user_id = ?")->execute([$userId]);
                    }
                }
                /* 4) The account itself */
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            }
            $pdo->commit();
            header("Location: admin.php?deleted=1&" . $back); exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
        }
    }
    header("Location: admin.php?" . $back); exit;
}

/* ---------------- AUTO-EXPIRE ---------------- */
$pdo->exec("UPDATE subscriptions SET status='expired'
    WHERE (status = 'active_trial' AND trial_ends_at IS NOT NULL AND trial_ends_at < NOW())
       OR (status IN ('active','past_due') AND period_ends_at IS NOT NULL AND period_ends_at < NOW())");

/* ---------------- DATA : subscriptions ➜ users (users.subscription_id = subscriptions.id) ----------------
   Excludes team-member accounts (subscriber_users rows where the user is managed by a DIFFERENT owner),
   so every subscription is listed exactly once under its true owner.                            */
$like = '%' . mb_strtolower($q) . '%';
$memberFilter = "NOT EXISTS (SELECT 1 FROM subscriber_users su WHERE su.user_id = u.id AND su.owner_id IS DISTINCT FROM su.user_id)";

$cnt = $pdo->prepare("SELECT COUNT(*)
    FROM subscriptions s
    JOIN users u ON u.subscription_id = s.id
    WHERE u.role = 'customer' AND $memberFilter
      AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)");
$cnt->execute([$like, $like]);
$total   = (int)$cnt->fetchColumn();
$perPage = 10;
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$subscribers = $pdo->prepare("
    SELECT s.id AS sub_id, s.plan, s.status, s.price, s.trial_ends_at, s.period_ends_at, s.created_at AS sub_created_at,
           u.id, u.name, u.email, u.created_at,
           agg.first_payment, agg.total_sale
    FROM subscriptions s
    JOIN users u ON u.subscription_id = s.id
    LEFT JOIN LATERAL (
        SELECT MIN(x.created_at) FILTER (WHERE x.status='succeeded' AND x.amount > 0) AS first_payment,
               COALESCE(SUM(x.amount) FILTER (WHERE x.status='succeeded' AND x.amount > 0), 0) AS total_sale
        FROM transactions x WHERE x.user_id = u.id
    ) agg ON true
    WHERE u.role = 'customer' AND $memberFilter
      AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)
    ORDER BY s.created_at DESC, u.email
    LIMIT $perPage OFFSET $offset");
$subscribers->execute([$like, $like]);
$rows = $subscribers->fetchAll();

$stats = $pdo->query("SELECT
    COUNT(*) FILTER (WHERE status='active') AS active_subs,
    COUNT(*) FILTER (WHERE status='active_trial') AS trials,
    COUNT(*) FILTER (WHERE status IN ('past_due','expired')) AS past_due
    FROM subscriptions")->fetch();

$groups = [];
foreach (all_settings($pdo) as $s) $groups[$s['module']][] = $s;
$modCls  = ['general'=>'mod-general','einvoice'=>'mod-einvoice','booking'=>'mod-booking'];
$modIcon = ['general'=>'⚙️','einvoice'=>'🧾','booking'=>'📅'];

/* ---------------- REMAINING-TIME HELPER ---------------- */
function remainingInfo(string $st, ?string $trialEnds, ?string $periodEnds, DateTime $now): array {
    $end = null;
    if ($st === 'active_trial' && $trialEnds)          $end = new DateTime($trialEnds);
    elseif ($st === 'expired')                          $end = $periodEnds ? new DateTime($periodEnds) : ($trialEnds ? new DateTime($trialEnds) : null);
    elseif ($periodEnds)                                $end = new DateTime($periodEnds);
    if (!$end) return ['end'=>null, 'text'=>'', 'cls'=>''];
    if ($st === 'expired' || $now > $end) return ['end'=>$end, 'text'=>'Expired', 'cls'=>'expired'];
    $mins = (int)floor(($end->getTimestamp() - $now->getTimestamp()) / 60);
    $d = intdiv($mins, 1440); $h = intdiv($mins % 1440, 60); $m = $mins % 60;
    $text = $d > 0 ? "{$d}d {$h}h {$m}m left" : ($h > 0 ? "{$h}h {$m}m left" : "{$m}m left");
    $cls  = $d >= 3 ? 'ok' : ($d >= 1 ? 'warn' : 'danger');
    return ['end'=>$end, 'text'=>$text, 'cls'=>$cls];
}
$now = new DateTime();
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

.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner-wrap{text-align:center}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted)}

.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}
.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.15s}
.menu-item:hover{background:#f8fafc;color:var(--ink)}
.menu-item.active{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}

.main-wrapper{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;gap:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted)}
.menu-toggle{display:none;background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink);padding:4px}

.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:16px 0 0;background:#d1fae5;color:#059669;border-radius:12px;padding:10px 16px;font-size:13px;font-weight:700}

.stats3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:28px 0}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:30px;font-weight:800}
.stat .g{color:#059669}.stat .b{color:var(--brand)}.stat .r{color:#e11d48}

/* ---------- SETTINGS ---------- */
.set-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);margin-bottom:28px;overflow:hidden}
.set-head{padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.set-head .t{font-weight:800;font-size:15px}
.set-head small{color:var(--faint);font-weight:500}
.set-head code{background:#f1f5f9;border-radius:6px;padding:2px 6px;font-size:11px;color:#475569}
.set-body{padding:4px 24px 16px}
.set-module{margin-top:22px}
.set-module-head{display:flex;align-items:center;gap:12px;margin-bottom:2px}
.set-module-head .line{flex:1;height:1px;background:var(--line)}
.set-module-head .cnt{font-size:11px;color:var(--faint);font-weight:600;white-space:nowrap}
.mod-chip{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-radius:999px;padding:4px 12px;background:#f1f5f9;color:#64748b;white-space:nowrap}
.mod-general{background:#e0e5ff;color:#4644cf}.mod-einvoice{background:#fef3c7;color:#d97706}.mod-booking{background:#fae8ff;color:#c026d3}
.set-row{display:grid;grid-template-columns:minmax(200px,5fr) minmax(150px,3fr);gap:6px 24px;align-items:center;padding:14px 0;border-bottom:1px dashed #e2e8f0}
.set-module .set-row:last-child{border-bottom:none}
.set-row.wide{grid-template-columns:1fr}
.set-label b{font-size:14px;display:block;color:#1e293b;font-weight:600}
.set-label small{color:var(--faint);font-size:12px;display:block;margin-top:2px;line-height:1.45}
.set-control{display:flex;justify-content:flex-end}
.set-row.wide .set-control{justify-content:stretch}
.set-input{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13px;outline:none;background:#f8fafc;transition:.15s;color:var(--ink)}
.set-input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1);background:#fff}
.set-input.num{max-width:120px;text-align:center;font-weight:700}
.set-input.url{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
textarea.set-input{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;min-height:92px;resize:vertical;line-height:1.55;white-space:pre}
.set-foot{padding:14px 24px;border-top:1px solid #f1f5f9;background:#f8fafc;display:flex;justify-content:flex-end;align-items:center;gap:12px}
.set-foot small{margin-right:auto;color:var(--faint);font-size:12px}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:10px 22px;font-size:13px;font-weight:700;box-shadow:0 8px 20px -8px rgba(84,87,229,.5)}
.btn-save:hover{opacity:.9}

/* ---------- TABLE ---------- */
.table-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--card)}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 24px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap}
.toolbar h3{font-weight:700}
.toolbar h3 span{color:var(--faint);font-weight:500;font-size:12px}
.search{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.search-in{border:1px solid var(--line);border-radius:10px;padding:9px 14px;font-size:13px;width:250px;outline:none}
.search-in:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.search-btn{background:#f1f5f9;color:#475569;border-radius:10px;padding:9px 16px;font-size:12px;font-weight:700}
.clear-btn{font-size:12px;font-weight:700;color:#e11d48}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:980px}
th{padding:14px 24px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 24px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:top}
tbody tr:hover{background:#f8fafc}
.name b{color:#1e293b}.email{font-size:12px;color:var(--faint)}
.plan-line{font-weight:600;color:#475569}
.badge{display:inline-block;margin-top:6px;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.active{background:#d1fae5;color:#059669}.badge.active_trial{background:#e0e5ff;color:#4644cf}
.badge.past_due{background:#fef3c7;color:#d97706}.badge.canceled{background:#f1f5f9;color:#64748b}
.badge.suspended{background:#ffe4e6;color:#e11d48}.badge.expired{background:#ffe4e6;color:#e11d48}
.badge.none{background:#f1f5f9;color:#94a3b8}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.sale{font-weight:800;color:#1e293b}
.date-pair{display:flex;flex-direction:column;gap:4px}
.date-pair span{display:flex;align-items:center;gap:6px}
.date-pair small{color:var(--faint);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.remaining{margin-top:2px;font-size:11px;font-weight:800;letter-spacing:.02em}
.remaining.ok{color:#059669}.remaining.warn{color:#d97706}.remaining.danger{color:#e11d48}.remaining.expired{color:#e11d48}
.actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;align-items:center}
.ibtn{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:14px;background:#f8fafc;border:1px solid var(--line);transition:.15s;text-decoration:none;color:var(--muted)}
.ibtn:hover{background:#e2e8f0;color:var(--ink)}
.ibtn.del:hover{background:#ffe4e6;border-color:#fecdd3;color:#e11d48}
.ibtn.exp:hover{background:#fef3c7;border-color:#fde68a;color:#d97706}
.abtn{border-radius:8px;padding:7px 12px;font-size:11px;font-weight:700;transition:.15s}
.abtn.trial{background:#f1f5f9;color:#475569}.abtn.trial:hover{background:#e2e8f0}
.abtn.go{background:var(--grad);color:#fff}.abtn.go:hover{opacity:.9}
.abtn.stop{background:#fff1f2;color:#e11d48}.abtn.stop:hover{background:#ffe4e6}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.pager{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 24px;font-size:13px;color:var(--muted);flex-wrap:wrap}
.pager nav{display:flex;gap:8px;flex-wrap:wrap}
.pbtn{border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;background:#f1f5f9;color:#475569}
.pbtn:hover{background:#e2e8f0}.pbtn.off{opacity:.4;pointer-events:none}
.pnum{border-radius:8px;padding:7px 11px;font-size:12px;font-weight:700;background:#fff;border:1px solid var(--line);color:#475569}
.pnum.on{background:var(--grad);color:#fff;border-color:transparent}

.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px}
.modal.open{display:grid}
.modal-card{width:100%;max-width:420px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4)}
.modal-card h3{font-size:18px;font-weight:800;margin-bottom:4px}
.modal-card .msub{font-size:13px;color:var(--muted);margin-bottom:16px}
.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none}
.field input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.mrow{display:flex;gap:10px;margin-top:20px}
.mrow .btn-save{flex:1;text-align:center}.mrow .cancel{flex:1;background:#f1f5f9;color:#475569;border-radius:10px;font-size:13px;font-weight:700}

@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  table{min-width:880px}
  th,td{padding:12px 16px}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
  .stats3{grid-template-columns:1fr;gap:12px}
  .stat{padding:18px}.stat b{font-size:24px}
  .toolbar{flex-direction:column;align-items:stretch}
  .search{width:100%}.search-in{flex:1;width:auto}
  .pager{flex-direction:column;align-items:center}
  th,td{padding:10px 12px}
  .set-head{padding:16px;flex-direction:column;align-items:flex-start;gap:4px}
  .set-body{padding:0 16px 8px}
  .set-module{margin-top:18px}
  .set-row{grid-template-columns:1fr;gap:8px;padding:12px 0}
  .set-control{justify-content:stretch}
  .set-input{font-size:14px;padding:11px 12px}
  .set-input.num{max-width:none;text-align:left}
  textarea.set-input{min-height:110px}
  .set-foot{padding:12px 16px;flex-direction:column-reverse;align-items:stretch;gap:8px}
  .set-foot small{text-align:center;margin:0}
  .set-foot .btn-save{width:100%;padding:13px}
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

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>Admin</em></span>
  </div>
  <nav class="sidebar-nav">
    <a href="admin.php" class="menu-item active">📊 Dashboard</a>
    <a href="admin_company.php" class="menu-item">🏢 Company</a>
    <a href="admin_users.php" class="menu-item">👥 Admins</a>
    <a href="admin_report.php" class="menu-item">👥 Report</a>
    <a href="admin_log.php" class="menu-item">📜 Logs</a>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="main-wrapper">
  <nav class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>Admin</em></span>
    </div>
    <div class="top-right">
      <span>Logged in as <b><?= htmlspecialchars($_SESSION['user_name']) ?></b></span>
      <a class="btn-out" href="login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <h1>Subscriber Management</h1>
    <p class="sub">Default trial period: <b><?= $trialHours ?> hour(s)</b>. List source: <code>subscriptions ⋈ users (users.subscription_id = subscriptions.id)</code>, team-member accounts excluded. Passed trials/periods auto-flip to <b>expired</b>.</p>
    <?php if (isset($_GET['saved'])): ?><div class="banner">✔ Settings saved successfully.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="banner" style="background:#ffe4e6;color:#e11d48">🗑️ Subscriber and all related data deleted.</div><?php endif; ?>
    <?php if (isset($_GET['expired'])): ?><div class="banner" style="background:#fef3c7;color:#b45309">⏹ Subscription marked as expired.</div><?php endif; ?>

    <div class="stats3">
      <div class="stat"><p>Active Subscriptions</p><b class="g"><?= $stats['active_subs'] ?></b></div>
      <div class="stat"><p>Active Trials</p><b class="b"><?= $stats['trials'] ?></b></div>
      <div class="stat"><p>Past Due / Expired</p><b class="r"><?= $stats['past_due'] ?></b></div>
    </div>

    <!-- ============ PLATFORM SETTINGS ============ -->
    <form method="POST" class="set-card action-form">
      <input type="hidden" name="action" value="save_settings">
      <div class="set-head">
        <span class="t">🛠️ Platform Settings</span>
        <small>Module-based configuration store · <code>settings(module, key, value)</code></small>
      </div>
      <div class="set-body">
        <?php foreach ($groups as $module => $items): ?>
          <div class="set-module">
            <div class="set-module-head">
              <span class="mod-chip <?= $modCls[$module] ?? '' ?>"><?= $modIcon[$module] ?? '📦' ?> <?= htmlspecialchars($module) ?></span>
              <span class="line"></span>
              <span class="cnt"><?= count($items) ?> setting(s)</span>
            </div>
            <?php foreach ($items as $s):
                $val    = (string)($s['value'] ?? '');
                $trim   = trim($val);
                $isJson = str_starts_with($trim, '{') || str_starts_with($trim, '[');
                $isLong = mb_strlen($trim) > 60;
                $isUrl  = (bool)preg_match('#^https?://#i', $trim);
                $isNum  = $trim !== '' && is_numeric($trim);
                $wide   = $isJson || $isLong || $isUrl;
                $fname  = 'setting[' . htmlspecialchars($module) . '][' . htmlspecialchars($s['key']) . ']';
            ?>
            <div class="set-row <?= $wide ? 'wide' : '' ?>">
              <div class="set-label">
                <b><?= htmlspecialchars($s['label'] ?: $s['key']) ?></b>
                <?php if (!empty($s['hint'])): ?><small><?= htmlspecialchars($s['hint']) ?></small><?php endif; ?>
              </div>
              <div class="set-control">
                <?php if ($isJson || $isLong): ?>
                  <textarea class="set-input" rows="4" name="<?= $fname ?>" spellcheck="false"><?= htmlspecialchars($val) ?></textarea>
                <?php elseif ($isUrl): ?>
                  <input class="set-input url" type="text" name="<?= $fname ?>" value="<?= htmlspecialchars($val) ?>" spellcheck="false" autocomplete="off">
                <?php else: ?>
                  <input class="set-input <?= $isNum ? 'num' : '' ?>" type="text" name="<?= $fname ?>" value="<?= htmlspecialchars($val) ?>" <?= $isNum ? 'inputmode="numeric"' : '' ?> autocomplete="off">
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$groups): ?>
          <div class="set-row"><div class="set-label"><b>No settings defined yet.</b><small>They will appear here once seeded.</small></div></div>
        <?php endif; ?>
      </div>
      <div class="set-foot">
        <small>Changes apply immediately after saving.</small>
        <button class="btn-save" type="submit">💾 Save settings</button>
      </div>
    </form>

    <!-- ============ SUBSCRIPTION LIST ============ -->
    <div class="table-card">
      <form method="GET" class="toolbar">
        <h3>All Subscriptions <span><?= $total ?> record(s)<?= $q ? ' · filtered by "'.htmlspecialchars($q).'"' : '' ?></span></h3>
        <div class="search">
          <input class="search-in" type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($q) ?>">
          <button class="search-btn">Search</button>
          <?php if ($q): ?><a class="clear-btn" href="admin.php">Clear</a><?php endif; ?>
        </div>
      </form>
      <div class="table-wrap"><table>
        <thead><tr><th>Customer</th><th>Plan / Status</th><th>Start</th><th>Expiry &amp; Remaining</th><th>Total Sale</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s):
          $st  = $s['status'] ?? 'none';
          $rem = remainingInfo($st, $s['trial_ends_at'], $s['period_ends_at'], $now);
          $delMsg = 'Permanently delete ' . $s['name'] . ' (' . $s['email'] . ') and ALL related bookings, transactions & invoices? This cannot be undone.';
        ?>
          <tr data-id="<?= $s['id'] ?>" data-sub="<?= $s['sub_id'] ?>" data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars($s['email'], ENT_QUOTES) ?>">
            <!-- 1 · CUSTOMER -->
            <td class="name"><b><?= htmlspecialchars($s['name']) ?></b><div class="email"><?= htmlspecialchars($s['email']) ?></div></td>

            <!-- 2 · PLAN + STATUS -->
            <td>
              <div class="plan-line"><?= $s['plan'] ? htmlspecialchars($s['plan']) : 'No Plan' ?> <span style="color:var(--faint);font-weight:500">(RM <?= htmlspecialchars((string)$s['price']) ?>)</span></div>
              <span class="badge <?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></span>
            </td>

            <!-- 3 · START -->
            <td class="date-pair">
              <span><small>Registered:</small> <b class="mono"><?= $s['created_at'] ? date('M d, Y', strtotime($s['created_at'])) : '—' ?></b></span>
              <span><small>Payment:</small> <b class="mono"><?= $s['first_payment'] ? date('M d, Y', strtotime($s['first_payment'])) : '—' ?></b></span>
            </td>

            <!-- 4 · EXPIRY + REMAINING -->
            <td class="date-pair">
              <?php if ($rem['end']): ?>
                <span><b class="mono"><?= $st === 'active_trial' ? '⏱ ' . $rem['end']->format('M d, Y H:i') : $rem['end']->format('M d, Y H:i') ?></b></span>
                <?php if ($rem['text']): ?><span class="remaining <?= $rem['cls'] ?>"><?= $rem['text'] ?></span><?php endif; ?>
              <?php else: ?>
                <span class="mono">—</span>
              <?php endif; ?>
            </td>

            <!-- 5 · TOTAL SALE -->
            <td class="sale">RM <?= number_format((float)$s['total_sale'], 0) ?></td>

            <!-- 6 · ACTIONS -->
            <td><div class="actions">
              <button class="ibtn" data-edit title="Edit profile">✏️</button>
              <a href="admin_company.php?user_id=<?= $s['id'] ?>" class="ibtn" title="Update subscriber company">🏢</a>

              <?php if ($st !== 'expired'): ?>
              <form method="POST" class="action-form" data-confirm="Mark this subscription as EXPIRED now? The end date will be stamped to the current time.">
                <input type="hidden" name="action" value="expire_now">
                <input type="hidden" name="sub_id" value="<?= $s['sub_id'] ?>">
                <button class="ibtn exp" title="Mark as expired">⏹</button>
              </form>
              <?php endif; ?>

              <?php if (in_array($st, ['active_trial','suspended','past_due','expired'])): ?>
              <form method="POST" class="action-form">
                <input type="hidden" name="action" value="extend_trial">
                <input type="hidden" name="sub_id" value="<?= $s['sub_id'] ?>">
                <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                <button class="abtn trial" title="Reset trial to configured default">⏱ +<?= $trialHours ?>h</button>
              </form>
              <?php endif; ?>

              <?php if ($st !== 'active'): ?>
              <form method="POST" class="action-form">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="sub_id" value="<?= $s['sub_id'] ?>">
                <button class="abtn go">Activate (90d)</button>
              </form>
              <?php else: ?>
              <form method="POST" class="action-form">
                <input type="hidden" name="action" value="suspend">
                <input type="hidden" name="sub_id" value="<?= $s['sub_id'] ?>">
                <button class="abtn stop">Suspend</button>
              </form>
              <?php endif; ?>

              <form method="POST" class="action-form" data-confirm="<?= htmlspecialchars($delMsg, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                <button class="ibtn del" title="Delete user + related data (testing)">🗑️</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;padding:40px">No subscriptions match your search.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <div class="pager">
        <span>Showing <?= $total ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?></span>
        <nav>
          <a class="pbtn <?= $page <= 1 ? 'off' : '' ?>" href="?page=<?= $page - 1 ?>&q=<?= urlencode($q) ?>">← Prev</a>
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="pnum <?= $i === $page ? 'on' : '' ?>" href="?page=<?= $i ?>&q=<?= urlencode($q) ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a class="pbtn <?= $page >= $pages ? 'off' : '' ?>" href="?page=<?= $page + 1 ?>&q=<?= urlencode($q) ?>">Next →</a>
        </nav>
      </div>
    </div>
  </main>
</div>

<div class="modal" id="editModal">
  <form method="POST" class="modal-card action-form">
    <input type="hidden" name="action" value="save_profile">
    <input type="hidden" name="user_id" id="edit_id">
    <h3>Edit customer profile</h3>
    <p class="msub">Update the customer's identity details.</p>
    <div class="field"><label>Full name</label><input id="edit_name" name="name" required></div>
    <div class="field"><label>Email address</label><input id="edit_email" name="email" type="email" required></div>
    <div class="mrow"><button class="btn-save" type="submit">Save changes</button><button class="cancel" type="button" onclick="closeEdit()">Cancel</button></div>
  </form>
</div>

<script>
const modal = document.getElementById('editModal');
function closeEdit(){ modal.classList.remove('open'); }
modal.addEventListener('click', e => { if (e.target === modal) closeEdit(); });
document.querySelectorAll('button[data-edit]').forEach(b => b.addEventListener('click', () => {
  const tr = b.closest('tr');
  document.getElementById('edit_id').value    = tr.dataset.id;
  document.getElementById('edit_name').value  = tr.dataset.name;
  document.getElementById('edit_email').value = tr.dataset.email;
  modal.classList.add('open');
}));

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}

const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('.action-form').forEach(form => {
  form.addEventListener('submit', function(e) {
    const msg = this.dataset.confirm;
    if (msg && !confirm(msg)) { e.preventDefault(); return; }
    overlay.classList.add('active');
  });
});
document.querySelectorAll('a[href]').forEach(link => {
  link.addEventListener('click', function() {
    if (!this.href.includes('#') && !this.target) overlay.classList.add('active');
  });
});
</script>
</body>
</html>
