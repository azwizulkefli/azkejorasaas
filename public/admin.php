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
    $userId = $_POST['user_id'] ?? '';

    if ($_POST['action'] === 'extend_trial') {
        $st = $pdo->prepare("UPDATE subscriptions SET status='active_trial', trial_ends_at = NOW() + (? * INTERVAL '1 hour') WHERE user_id = ?");
        $st->execute([$trialHours, $userId]);
        if ($st->rowCount() === 0)
            $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, trial_ends_at) VALUES (?,'active_trial',0, NOW() + (? * INTERVAL '1 hour'))")
                ->execute([$userId, $trialHours]);

    } elseif ($_POST['action'] === 'activate') {
        $st = $pdo->prepare("UPDATE subscriptions SET status='active', period_ends_at = NOW() + INTERVAL '90 days' WHERE user_id = ?");
        $st->execute([$userId]);
        if ($st->rowCount() === 0)
            $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, period_ends_at) VALUES (?,'active',0, NOW() + INTERVAL '90 days')")
                ->execute([$userId]);

    } elseif ($_POST['action'] === 'suspend') {
        $pdo->prepare("UPDATE subscriptions SET status='suspended' WHERE user_id = ?")->execute([$userId]);

    } elseif ($_POST['action'] === 'save_profile') {
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

    } elseif ($_POST['action'] === 'delete_user') {
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $chk->execute([$userId]);
            if ($chk->fetchColumn() === 'customer') {
                foreach (['bookings', 'transactions', 'einvoice_items', 'subscriptions'] as $tbl) {
                    if ($pdo->query("SELECT to_regclass('public." . $tbl . "')")->fetchColumn()) {
                        $pdo->prepare("DELETE FROM " . $tbl . " WHERE user_id = ?")->execute([$userId]);
                    }
                }
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

/* ---------------- DATA ---------------- */
$like = '%' . mb_strtolower($q) . '%';
$cnt  = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role='customer' AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)");
$cnt->execute([$like, $like]);
$total   = (int)$cnt->fetchColumn();
$perPage = 10;
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$subscribers = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.created_at, s.plan, s.status, s.price, s.trial_ends_at, s.period_ends_at,
           agg.first_payment, agg.total_sale
    FROM users u
    LEFT JOIN subscriptions s ON s.user_id = u.id
    LEFT JOIN LATERAL (
        SELECT MIN(x.created_at) FILTER (WHERE x.status='succeeded' AND x.amount > 0) AS first_payment,
               COALESCE(SUM(x.amount) FILTER (WHERE x.status='succeeded' AND x.amount > 0), 0) AS total_sale
        FROM transactions x WHERE x.user_id = u.id
    ) agg ON true
    WHERE u.role = 'customer' AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)
    ORDER BY u.created_at DESC, u.email
    LIMIT $perPage OFFSET $offset");
$subscribers->execute([$like, $like]);
$rows = $subscribers->fetchAll();

$stats = $pdo->query("SELECT
    COUNT(*) FILTER (WHERE status='active') AS active_subs,
    COUNT(*) FILTER (WHERE status='active_trial') AS trials,
    COUNT(*) FILTER (WHERE status='past_due') AS past_due
    FROM subscriptions")->fetch();

$groups = [];
foreach (all_settings($pdo) as $s) $groups[$s['module']][] = $s;
$modCls = ['general'=>'mod-general','einvoice'=>'mod-einvoice','booking'=>'mod-booking'];
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
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted)}
.main{max-width:1200px;margin:0 auto;padding:32px 24px}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:16px 0 0;background:#d1fae5;color:#059669;border-radius:12px;padding:10px 16px;font-size:13px;font-weight:700}
.stats3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:28px 0}
@media(max-width:760px){.stats3{grid-template-columns:1fr}}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:30px;font-weight:800}
.stat .g{color:#059669}.stat .b{color:var(--brand)}.stat .r{color:#e11d48}
.set-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);margin-bottom:28px;overflow:hidden}
.set-head{padding:16px 24px;border-bottom:1px solid #f1f5f9;font-weight:700;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.set-head small{color:var(--faint);font-weight:500}
.set-body{padding:8px 24px 20px}
.set-row{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px dashed #e2e8f0;flex-wrap:wrap}
.set-row:last-child{border-bottom:none}
.mod-chip{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-radius:999px;padding:4px 12px;min-width:82px;text-align:center;background:#f1f5f9;color:#64748b}
.mod-general{background:#e0e5ff;color:#4644cf}.mod-einvoice{background:#fef3c7;color:#d97706}.mod-booking{background:#fae8ff;color:#c026d3}
.set-label{flex:1;min-width:220px}.set-label b{font-size:14px;display:block}.set-label small{color:var(--faint);font-size:12px}
.set-input{width:110px;border:1px solid var(--line);border-radius:10px;padding:9px 10px;font-size:14px;text-align:center;outline:none;font-weight:700}
.set-input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700}
.btn-save:hover{opacity:.9}
.table-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--card)}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 24px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap}
.toolbar h3{font-weight:700}
.toolbar h3 span{color:var(--faint);font-weight:500;font-size:12px}
.search{display:flex;gap:8px;align-items:center}
.search-in{border:1px solid var(--line);border-radius:10px;padding:9px 14px;font-size:13px;width:250px;outline:none}
.search-in:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.search-btn{background:#f1f5f9;color:#475569;border-radius:10px;padding:9px 16px;font-size:12px;font-weight:700}
.clear-btn{font-size:12px;font-weight:700;color:#e11d48}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:1100px}
th{padding:14px 24px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 24px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:middle}
tbody tr:hover{background:#f8fafc}
.name b{color:#1e293b}.email{font-size:12px;color:var(--faint)}
.badge{display:inline-block;margin-top:4px;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.active{background:#d1fae5;color:#059669}.badge.active_trial{background:#e0e5ff;color:#4644cf}
.badge.past_due{background:#fef3c7;color:#d97706}.badge.canceled{background:#f1f5f9;color:#64748b}
.badge.suspended{background:#ffe4e6;color:#e11d48}.badge.none{background:#f1f5f9;color:#94a3b8}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.sale{font-weight:800;color:#1e293b}
.date-pair{display:flex;flex-direction:column;gap:4px}
.date-pair span{display:flex;align-items:center;gap:6px}
.date-pair small{color:var(--faint);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.remaining{margin-top:4px;font-size:11px;font-weight:700;color:var(--brand)}
.remaining.expired{color:#e11d48}
.actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;align-items:center}
.ibtn{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:14px;background:#f8fafc;border:1px solid var(--line);transition:.15s}
.ibtn:hover{background:#e2e8f0}
.ibtn.del:hover{background:#ffe4e6;border-color:#fecdd3}
.abtn{border-radius:8px;padding:7px 12px;font-size:11px;font-weight:700;transition:.15s}
.abtn.trial{background:#f1f5f9;color:#475569}.abtn.trial:hover{background:#e2e8f0}
.abtn.go{background:var(--grad);color:#fff}.abtn.go:hover{opacity:.9}
.abtn.stop{background:#fff1f2;color:#e11d48}.abtn.stop:hover{background:#ffe4e6}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.pager{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 24px;font-size:13px;color:var(--muted);flex-wrap:wrap}
.pager nav{display:flex;gap:8px}
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
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner-wrap">
    <div class="spinner"></div>
    <p class="spinner-text">Processing…</p>
  </div>
</div>

<nav class="topbar">
  <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>Admin</em></span>
  <div class="top-right"><span>Logged in as <b><?= htmlspecialchars($_SESSION['user_name']) ?></b></span><a class="btn-out" href="login.php?logout=1">Sign out</a></div>
</nav>
<main class="main">
  <h1>Subscriber Management</h1>
  <p class="sub">Default trial period: <b><?= $trialHours ?> hour(s)</b> — configurable in Platform Settings below.</p>
  <?php if (isset($_GET['saved'])): ?><div class="banner">✔ Settings saved successfully.</div><?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?><div class="banner" style="background:#ffe4e6;color:#e11d48">🗑️ Subscriber and all related data deleted.</div><?php endif; ?>

  <div class="stats3">
    <div class="stat"><p>Active Subscriptions</p><b class="g"><?= $stats['active_subs'] ?></b></div>
    <div class="stat"><p>Active Trials</p><b class="b"><?= $stats['trials'] ?></b></div>
    <div class="stat"><p>Past Due</p><b class="r"><?= $stats['past_due'] ?></b></div>
  </div>

  <form method="POST" class="set-card action-form">
    <input type="hidden" name="action" value="save_settings">
    <div class="set-head"><span>Platform Settings</span><small>Module-based configuration store · <code>settings(module, key, value)</code></small><button class="btn-save">Save settings</button></div>
    <div class="set-body">
      <?php foreach ($groups as $module => $items): ?>
        <?php foreach ($items as $s): ?>
        <div class="set-row">
          <span class="mod-chip <?= $modCls[$module] ?? '' ?>"><?= htmlspecialchars($module) ?></span>
          <div class="set-label"><b><?= htmlspecialchars($s['label'] ?: $s['key']) ?></b><small><?= htmlspecialchars($s['hint'] ?? '') ?></small></div>
          <input class="set-input" type="text" name="setting[<?= htmlspecialchars($module) ?>][<?= htmlspecialchars($s['key']) ?>]" value="<?= htmlspecialchars($s['value']) ?>">
        </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </form>

  <div class="table-card">
    <form method="GET" class="toolbar">
      <h3>All Subscribers <span><?= $total ?> record(s)<?= $q ? ' · filtered by "'.htmlspecialchars($q).'"' : '' ?></span></h3>
      <div class="search">
        <input class="search-in" type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($q) ?>">
        <button class="search-btn">Search</button>
        <?php if ($q): ?><a class="clear-btn" href="admin.php">Clear</a><?php endif; ?>
      </div>
    </form>
    <div class="table-wrap"><table>
      <thead><tr><th>Customer</th><th>Plan</th><th>Status</th><th>Start</th><th>Expiry</th><th>Total Sale</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): 
        $st = $s['status'] ?? 'none';
        $now = new DateTime();
        $expiryDate = null;
        $remainingText = '';
        $remainingClass = '';

        if ($st === 'active_trial' && $s['trial_ends_at']) {
            $expiryDate = new DateTime($s['trial_ends_at']);
        } elseif ($s['period_ends_at']) {
            $expiryDate = new DateTime($s['period_ends_at']);
        }

        if ($expiryDate) {
            $diff = $now->diff($expiryDate);
            if ($now > $expiryDate) {
                $remainingText = 'Expired';
                $remainingClass = 'expired';
            } else {
                $remainingText = $diff->days > 0 ? "{$diff->days}d {$diff->h}h left" : "{$diff->h}h {$diff->i}m left";
            }
        }
      ?>
        <tr data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars($s['email'], ENT_QUOTES) ?>">
          <td class="name"><b><?= htmlspecialchars($s['name']) ?></b><div class="email"><?= htmlspecialchars($s['email']) ?></div></td>
          <td><?= $s['plan'] ?: 'No Plan' ?> <span style="color:var(--faint)">(RM <?= $s['price'] ?>)</span></td>
          <td><span class="badge <?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></span></td>
          <td class="date-pair">
            <span><small>Registered:</small> <b class="mono"><?= $s['created_at'] ? date('M d, Y', strtotime($s['created_at'])) : '—' ?></b></span>
            <span><small>Payment:</small> <b class="mono"><?= $s['first_payment'] ? date('M d, Y', strtotime($s['first_payment'])) : '—' ?></b></span>
          </td>
          <td class="date-pair">
            <?php if ($expiryDate): ?>
              <span><b class="mono"><?php
                if ($st === 'active_trial') echo '⏱ ' . $expiryDate->format('M d, H:i');
                else echo $expiryDate->format('M d, Y');
              ?></b></span>
              <?php if ($remainingText): ?>
                <span class="remaining <?= $remainingClass ?>"><?= $remainingText ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="mono">—</span>
            <?php endif; ?>
          </td>
          <td class="sale">RM <?= number_format((float)$s['total_sale'], 0) ?></td>
          <td><div class="actions">
            <button class="ibtn" data-edit title="Edit profile">✏️</button>
            <button class="ibtn" title="Impersonate (coming soon)" onclick="impersonate('<?= htmlspecialchars(addslashes($s['name']), ENT_QUOTES) ?>')">🎭</button>
            <form method="POST" class="action-form" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($s['name']), ENT_QUOTES) ?> (<?= htmlspecialchars($s['email'], ENT_QUOTES) ?>) and ALL related bookings, transactions & invoices?\nThis cannot be undone.')">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="ibtn del" title="Delete user + related data (testing)">🗑️</button>
            </form>
            <?php if (in_array($st, ['active_trial','suspended','past_due','none'])): ?>
              <form method="POST" class="action-form"><input type="hidden" name="action" value="extend_trial"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="abtn trial" title="Reset trial to configured default">⏱ +<?= $trialHours ?>h</button></form>
            <?php endif; ?>
            <?php if ($st !== 'active'): ?>
              <form method="POST" class="action-form"><input type="hidden" name="action" value="activate"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="abtn go">Activate (90d)</button></form>
            <?php else: ?>
              <form method="POST" class="action-form"><input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="abtn stop">Suspend</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;padding:40px">No subscribers match your search.</td></tr><?php endif; ?>
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
function impersonate(name){ alert('🎭 Impersonation for "' + name + '" is planned — module not built yet.'); }

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
</body>
</html>
