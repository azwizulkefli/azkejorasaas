<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

/* Auto-create table if missing */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscriber_users (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        owner_id UUID REFERENCES users(id) ON DELETE CASCADE,
        email VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50),
        role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin','user')),
        position VARCHAR(100),
        password_hash VARCHAR(255),
        status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
        created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        UNIQUE(owner_id, email))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sub_users_owner ON subscriber_users(owner_id)");
} catch (Throwable $e) { /* already exists */ }

/* ---------------- POST ACTIONS ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_user') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
        $pos   = trim($_POST['position'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: users.php?err=invalid"); exit;
        }

        /* duplicate email check for this owner */
        $dup = $pdo->prepare("SELECT 1 FROM subscriber_users WHERE owner_id = ? AND LOWER(email) = LOWER(?)");
        $dup->execute([$uid, $email]);
        if ($dup->fetchColumn()) {
            header("Location: users.php?err=duplicate"); exit;
        }

        $hash = $pass !== '' ? password_hash($pass, PASSWORD_BCRYPT) : null;

        $pdo->prepare("INSERT INTO subscriber_users (owner_id, name, email, phone, role, position, password_hash, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'active')")
            ->execute([$uid, $name, $email, $phone, $role, $pos, $hash]);

        header("Location: users.php?saved=added"); exit;
    }

    if ($_POST['action'] === 'update_user') {
        $id    = $_POST['user_id'] ?? '';
        $name  = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
        $pos   = trim($_POST['position'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: users.php?err=invalid"); exit;
        }

        /* duplicate check (excluding self) */
        $dup = $pdo->prepare("SELECT 1 FROM subscriber_users WHERE owner_id = ? AND LOWER(email) = LOWER(?) AND id <> ?");
        $dup->execute([$uid, $email, $id]);
        if ($dup->fetchColumn()) {
            header("Location: users.php?err=duplicate"); exit;
        }

        if ($pass !== '') {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE subscriber_users SET name=?, email=?, phone=?, role=?, position=?, password_hash=?, updated_at=NOW()
                           WHERE id = ? AND owner_id = ?")
                ->execute([$name, $email, $phone, $role, $pos, $hash, $id, $uid]);
        } else {
            $pdo->prepare("UPDATE subscriber_users SET name=?, email=?, phone=?, role=?, position=?, updated_at=NOW()
                           WHERE id = ? AND owner_id = ?")
                ->execute([$name, $email, $phone, $role, $pos, $id, $uid]);
        }
        header("Location: users.php?saved=updated"); exit;
    }

    if ($_POST['action'] === 'toggle_user') {
        $id = $_POST['user_id'] ?? '';
        $pdo->prepare("UPDATE subscriber_users SET status = CASE WHEN status='active' THEN 'suspended' ELSE 'active' END, updated_at=NOW()
                       WHERE id = ? AND owner_id = ?")
            ->execute([$id, $uid]);
        header("Location: users.php?saved=toggled"); exit;
    }

    if ($_POST['action'] === 'delete_user') {
        $id = $_POST['user_id'] ?? '';
        $pdo->prepare("DELETE FROM subscriber_users WHERE id = ? AND owner_id = ?")->execute([$id, $uid]);
        header("Location: users.php?saved=deleted"); exit;
    }
}

/* ---------------- DATA ---------------- */
$q = trim($_GET['q'] ?? '');
$like = '%' . mb_strtolower($q) . '%';
$usersQuery = $pdo->prepare("SELECT * FROM subscriber_users
    WHERE owner_id = ? AND (LOWER(name) LIKE ? OR LOWER(email) LIKE ? OR LOWER(position) LIKE ?)
    ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, created_at DESC");
$usersQuery->execute([$uid, $like, $like, $like]);
$users = $usersQuery->fetchAll();

$adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$userCount  = count(array_filter($users, fn($u) => $u['role'] === 'user'));
$activeCount = count(array_filter($users, fn($u) => $u['status'] === 'active'));

$errMap = [
    'invalid'   => '✗ Please provide a valid name and email address.',
    'duplicate' => '✗ A user with this email already exists in your team.',
];
$savedMap = [
    'added'    => '✔ New team member added.',
    'updated'  => '✔ User details updated.',
    'toggled'  => '✔ User status changed.',
    'deleted'  => '✔ User removed from team.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Users — AZ Kejora SaaS</title>
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
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}

.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}

.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}

.head-row{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:4px}

/* ---------- STATS ---------- */
.stats4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:28px 0}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:24px;font-weight:800}
.stat small{display:block;margin-top:4px;font-size:12px;color:var(--muted)}
.stat .emerald{color:#059669}.stat .rose{color:#e11d48}.stat .violet{color:var(--brand)}

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
table{width:100%;border-collapse:collapse;font-size:14px;min-width:820px}
th{padding:14px 24px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 24px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:middle}
tbody tr:hover{background:#f8fafc}
.name-cell b{color:#1e293b}.name-cell .email{font-size:12px;color:var(--faint)}
.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.admin{background:#e0e5ff;color:#4644cf}
.badge.user{background:#f1f5f9;color:#475569}
.badge.active{background:#d1fae5;color:#059669}
.badge.suspended{background:#ffe4e6;color:#e11d48}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;align-items:center}
.ibtn{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:14px;background:#f8fafc;border:1px solid var(--line);transition:.15s}
.ibtn:hover{background:#e2e8f0}
.ibtn.del:hover{background:#ffe4e6;border-color:#fecdd3}
.abtn{border-radius:8px;padding:7px 12px;font-size:11px;font-weight:700;transition:.15s}
.abtn.go{background:var(--grad);color:#fff}.abtn.go:hover{opacity:.9}
.abtn.stop{background:#fff1f2;color:#e11d48}.abtn.stop:hover{background:#ffe4e6}
.btn-add{background:var(--grad);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;box-shadow:0 8px 20px -8px rgba(84,87,229,.5)}
.btn-add:hover{opacity:.95}

/* ---------- MODAL ---------- */
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px}
.modal.open{display:grid}
.modal-card{width:100%;max-width:520px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);max-height:90vh;overflow-y:auto}
.modal-card h3{font-size:18px;font-weight:800;margin-bottom:4px}
.modal-card .msub{font-size:13px;color:var(--muted);margin-bottom:16px}
.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input,.field select{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none}
.field input:focus,.field select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mrow{display:flex;gap:10px;margin-top:20px}
.mrow .btn-save{flex:1;text-align:center}
.mrow .cancel{flex:1;background:#f1f5f9;color:#475569;border-radius:10px;font-size:13px;font-weight:700;text-align:center;padding:11px 18px}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 18px;font-size:13px;font-weight:700}
.btn-save:hover{opacity:.9}

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
  .grid2{grid-template-columns:1fr}
  th,td{padding:10px 12px}
  .toolbar{flex-direction:column;align-items:stretch}
  .search{width:100%}
  .search-in{flex:1;width:auto}
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
    <a href="s_payment.php" class="menu-item">💳 Payment</a>
    <a href="s_report.php" class="menu-item">📄 Report</a>
    <div class="menu-section">Setup</div>
    <a href="company.php" class="menu-item">🏢 Company</a>
    <a href="users.php" class="menu-item active">👥 Users</a>
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
      <span class="avatar"><?= strtoupper(substr($me['name'], 0, 1)) ?></span>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <div class="head-row">
      <div>
        <h1>Team Users</h1>
        <p class="sub">Invite and manage people who can access your workspace.</p>
      </div>
      <button class="btn-add" onclick="openAdd()">＋ Add user</button>
    </div>

    <?php if (isset($_GET['saved']) && isset($savedMap[$_GET['saved']])): ?>
      <div class="banner success"><?= $savedMap[$_GET['saved']] ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && isset($errMap[$_GET['err']])): ?>
      <div class="banner error"><?= $errMap[$_GET['err']] ?></div>
    <?php endif; ?>

    <div class="stats4">
      <div class="stat"><p>Total users</p><b class="violet"><?= count($users) ?></b><small>in your workspace</small></div>
      <div class="stat"><p>Admins</p><b class="violet"><?= $adminCount ?></b><small>full access</small></div>
      <div class="stat"><p>Users</p><b><?= $userCount ?></b><small>standard access</small></div>
      <div class="stat"><p>Active</p><b class="emerald"><?= $activeCount ?></b><small><?= count($users) - $activeCount ?> suspended</small></div>
    </div>

    <div class="table-card">
      <form method="GET" class="toolbar">
        <h3>All team members <span><?= count($users) ?> record(s)<?= $q ? ' · filtered by "'.htmlspecialchars($q).'"' : '' ?></span></h3>
        <div class="search">
          <input class="search-in" type="text" name="q" placeholder="Search name, email or position…" value="<?= htmlspecialchars($q) ?>">
          <button class="search-btn">Search</button>
          <?php if ($q): ?><a class="clear-btn" href="users.php">Clear</a><?php endif; ?>
        </div>
      </form>
      <div class="table-wrap"><table>
        <thead><tr>
          <th>Team member</th>
          <th>Position</th>
          <th>Role</th>
          <th>Status</th>
          <th>Added</th>
          <th style="text-align:right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr data-id="<?= $u['id'] ?>"
              data-name="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>"
              data-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
              data-phone="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES) ?>"
              data-role="<?= htmlspecialchars($u['role']) ?>"
              data-position="<?= htmlspecialchars($u['position'] ?? '', ENT_QUOTES) ?>">
            <td class="name-cell">
              <b><?= htmlspecialchars($u['name']) ?></b>
              <div class="email"><?= htmlspecialchars($u['email']) ?></div>
            </td>
            <td><?= $u['position'] ? htmlspecialchars($u['position']) : '<span style="color:var(--faint)">—</span>' ?></td>
            <td><span class="badge <?= $u['role'] ?>"><?= htmlspecialchars($u['role']) ?></span></td>
            <td><span class="badge <?= $u['status'] ?>"><?= htmlspecialchars($u['status']) ?></span></td>
            <td class="mono"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            <td>
              <div class="actions">
                <button class="ibtn" data-edit title="Edit user">✏️</button>
                <form method="POST" class="action-form">
                  <input type="hidden" name="action" value="toggle_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="abtn <?= $u['status'] === 'active' ? 'stop' : 'go' ?>"
                          title="<?= $u['status'] === 'active' ? 'Suspend user' : 'Reactivate user' ?>">
                      <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
                <form method="POST" class="action-form" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES) ?> from your team? This cannot be undone.')">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="ibtn del" title="Delete user">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
          <tr><td colspan="6" style="text-align:center;padding:40px">No team members yet — click <b>＋ Add user</b> to invite someone.</td></tr>
        <?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </main>
</div>

<!-- ADD USER MODAL -->
<div class="modal" id="addModal">
  <form method="POST" class="modal-card action-form">
    <input type="hidden" name="action" value="add_user">
    <h3>Add team member</h3>
    <p class="msub">Invite someone to access your workspace.</p>

    <div class="field"><label>Full name</label><input name="name" placeholder="Aina Rahman" required></div>
    <div class="field"><label>Email address</label><input type="email" name="email" placeholder="aina@company.com" required></div>
    <div class="grid2">
      <div class="field"><label>Phone</label><input name="phone" placeholder="+60 12-345 6789"></div>
      <div class="field"><label>Position</label><input name="position" placeholder="Finance Manager"></div>
    </div>
    <div class="field"><label>Role</label>
      <select name="role" required>
        <option value="user">User — standard access</option>
        <option value="admin">Admin — full workspace access</option>
      </select>
    </div>
    <div class="field"><label>Password (optional)</label><input type="password" name="password" minlength="6" placeholder="Leave blank to set later"></div>

    <div class="mrow">
      <button type="submit" class="btn-save">Add user</button>
      <button type="button" class="cancel" onclick="closeAdd()">Cancel</button>
    </div>
  </form>
</div>

<!-- EDIT USER MODAL -->
<div class="modal" id="editModal">
  <form method="POST" class="modal-card action-form">
    <input type="hidden" name="action" value="update_user">
    <input type="hidden" name="user_id" id="edit_id">
    <h3>Edit team member</h3>
    <p class="msub">Update contact, role or reset password.</p>

    <div class="field"><label>Full name</label><input name="name" id="edit_name" required></div>
    <div class="field"><label>Email address</label><input type="email" name="email" id="edit_email" required></div>
    <div class="grid2">
      <div class="field"><label>Phone</label><input name="phone" id="edit_phone"></div>
      <div class="field"><label>Position</label><input name="position" id="edit_position"></div>
    </div>
    <div class="field"><label>Role</label>
      <select name="role" id="edit_role" required>
        <option value="user">User — standard access</option>
        <option value="admin">Admin — full workspace access</option>
      </select>
    </div>
    <div class="field"><label>New password (leave blank to keep current)</label><input type="password" name="password" minlength="6" placeholder="••••••••"></div>

    <div class="mrow">
      <button type="submit" class="btn-save">Save changes</button>
      <button type="button" class="cancel" onclick="closeEdit()">Cancel</button>
    </div>
  </form>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

const addM  = document.getElementById('addModal');
const editM = document.getElementById('editModal');
function openAdd()  { addM.classList.add('open'); }
function closeAdd() { addM.classList.remove('open'); }
function closeEdit(){ editM.classList.remove('open'); }
addM.addEventListener('click', e => { if (e.target === addM) closeAdd(); });
editM.addEventListener('click', e => { if (e.target === editM) closeEdit(); });

document.querySelectorAll('button[data-edit]').forEach(b => b.addEventListener('click', () => {
  const tr = b.closest('tr');
  document.getElementById('edit_id').value       = tr.dataset.id;
  document.getElementById('edit_name').value     = tr.dataset.name;
  document.getElementById('edit_email').value    = tr.dataset.email;
  document.getElementById('edit_phone').value    = tr.dataset.phone;
  document.getElementById('edit_position').value = tr.dataset.position;
  document.getElementById('edit_role').value     = tr.dataset.role;
  editM.classList.add('open');
}));

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
