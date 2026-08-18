<?php
require_once '../includes/auth.php';
require_once '../includes/settings.php';
requireAdmin();

/* Auto-create table if missing */
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    role VARCHAR(50) DEFAULT 'admin',
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    last_login TIMESTAMP WITH TIME ZONE)");

$myEmail = strtolower($_SESSION['user_email'] ?? '');

/* ---------------- POST ACTIONS ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = $_POST['admin_id'] ?? '';

    if ($_POST['action'] === 'add_admin') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $role  = in_array($_POST['role'] ?? '', ['admin','superadmin']) ? $_POST['role'] : 'admin';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { header("Location: admin_users.php?err=invalid"); exit; }
        if (strlen($pass) < 6) { header("Location: admin_users.php?err=short"); exit; }
        $chk = $pdo->prepare("SELECT 1 FROM admin_users WHERE LOWER(email) = LOWER(?)");
        $chk->execute([$email]);
        if ($chk->fetchColumn()) { header("Location: admin_users.php?err=exists"); exit; }
        $pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role, status) VALUES (?,?,?,?, 'active')")
            ->execute([$name, $email, password_hash($pass, PASSWORD_BCRYPT), $role]);
        header("Location: admin_users.php?saved=added"); exit;
    }

    if ($_POST['action'] === 'update_admin') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', ['admin','superadmin']) ? $_POST['role'] : 'admin';
        $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
        $pass  = $_POST['password'] ?? '';
        $targetEmail = trim(strtolower($_POST['email'] ?? ''));
        // Guards for self-edit
        if ($targetEmail === $myEmail) { $status = 'active'; $role = in_array($_SESSION['user_role'],['admin','superadmin']) ? $_SESSION['user_role'] : 'admin'; }
        if ($pass !== '' && strlen($pass) < 6) { header("Location: admin_users.php?err=short"); exit; }
        if ($pass !== '') {
            $pdo->prepare("UPDATE admin_users SET name=?, email=?, role=?, status=?, password_hash=? WHERE id=?")
                ->execute([$name, $email, $role, $status, password_hash($pass, PASSWORD_BCRYPT), $id]);
        } else {
            $pdo->prepare("UPDATE admin_users SET name=?, email=?, role=?, status=? WHERE id=?")
                ->execute([$name, $email, $role, $status, $id]);
        }
        header("Location: admin_users.php?saved=updated"); exit;
    }

    if ($_POST['action'] === 'toggle_admin') {
        $t = $pdo->prepare("SELECT * FROM admin_users WHERE id = ?"); $t->execute([$id]); $row = $t->fetch();
        if (!$row) { header("Location: admin_users.php"); exit; }
        if (strtolower($row['email']) === $myEmail) { header("Location: admin_users.php?err=self"); exit; }
        $active = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE status='active'")->fetchColumn();
        if ($row['status'] === 'active' && $active <= 1) { header("Location: admin_users.php?err=last"); exit; }
        $pdo->prepare("UPDATE admin_users SET status = ? WHERE id = ?")
            ->execute([$row['status'] === 'active' ? 'suspended' : 'active', $id]);
        header("Location: admin_users.php?saved=toggled"); exit;
    }

    if ($_POST['action'] === 'delete_admin') {
        $t = $pdo->prepare("SELECT * FROM admin_users WHERE id = ?"); $t->execute([$id]); $row = $t->fetch();
        if (!$row) { header("Location: admin_users.php"); exit; }
        if (strtolower($row['email']) === $myEmail) { header("Location: admin_users.php?err=self"); exit; }
        $active = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE status='active'")->fetchColumn();
        if ($row['status'] === 'active' && $active <= 1) { header("Location: admin_users.php?err=last"); exit; }
        $pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$id]);
        header("Location: admin_users.php?saved=deleted"); exit;
    }
}

/* ---------------- DATA ---------------- */
$admins = $pdo->query("SELECT * FROM admin_users ORDER BY created_at ASC")->fetchAll();
$activeCount = count(array_filter($admins, fn($a) => $a['status'] === 'active'));
$suspCount   = count($admins) - $activeCount;

$errMap = [
    'invalid'  => '✗ Please provide a valid name and email.',
    'short'    => '✗ Password must be at least 6 characters.',
    'exists'   => '✗ An admin with this email already exists.',
    'self'     => '✗ You cannot suspend or delete your own account.',
    'last'     => '✗ Cannot remove the last active administrator.',
];
$savedMap = [
    'added'    => '✔ Admin account created.',
    'updated'  => '✔ Admin account updated.',
    'toggled'  => '✔ Admin status changed.',
    'deleted'  => '️ Admin account deleted.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admins — AZ Kejora Admin</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted);text-align:center}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}
.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);transition:.15s}
.menu-item:hover{background:#f8fafc;color:var(--ink)}
.menu-item.active{background:var(--grad);color:#fff}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}
.main-wrapper{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;gap:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted)}
.menu-toggle{display:none;background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink);padding:4px}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.main{max-width:1100px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.head-row{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}
.stats3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:28px 0}
.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:var(--card)}
.stat p{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.stat b{display:block;margin-top:8px;font-size:30px;font-weight:800}
.stat .g{color:#059669}.stat .b{color:var(--brand)}.stat .r{color:#e11d48}
.table-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--card)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:820px}
th{padding:14px 24px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:14px 24px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:middle}
tbody tr:hover{background:#f8fafc}
.name b{color:#1e293b}.email{font-size:12px;color:var(--faint)}
.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.active{background:#d1fae5;color:#059669}.badge.suspended{background:#ffe4e6;color:#e11d48}
.badge.role{background:#e0e5ff;color:#4644cf}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.ibtn{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:14px;background:#f8fafc;border:1px solid var(--line);transition:.15s}
.ibtn:hover{background:#e2e8f0}
.ibtn.del:hover{background:#ffe4e6;border-color:#fecdd3}
.abtn{border-radius:8px;padding:7px 12px;font-size:11px;font-weight:700}
.abtn.go{background:var(--grad);color:#fff}.abtn.stop{background:#fff1f2;color:#e11d48}
.btn-add{background:var(--grad);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700}
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px}
.modal.open{display:grid}
.modal-card{width:100%;max-width:440px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4)}
.modal-card h3{font-size:18px;font-weight:800;margin-bottom:4px}
.modal-card .msub{font-size:13px;color:var(--muted);margin-bottom:16px}
.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input,.field select{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none}
.field input:focus,.field select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.mrow{display:flex;gap:10px;margin-top:20px}
.mrow .btn-save{flex:1;text-align:center}.mrow .cancel{flex:1;background:#f1f5f9;color:#475569;border-radius:10px;font-size:13px;font-weight:700}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:700}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}.main-wrapper{margin-left:0}.menu-toggle{display:block}
}
@media(max-width:760px){.stats3{grid-template-columns:1fr}.main{padding:20px 12px}h1{font-size:22px}th,td{padding:10px 12px}}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay"><div><div class="spinner"></div><p class="spinner-text">Processing…</p></div></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand"><span class="logo">⚡</span>AZ Kejora <em>Admin</em></span></div>
  <nav class="sidebar-nav">
    <a href="admin.php" class="menu-item">📊 Dashboard</a>
    <a href="admin_company.php" class="menu-item">🏢 Company</a>
    <a href="admin_users.php" class="menu-item active">👥 Admins</a>
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
    <div class="top-right"><span>Logged in as <b><?= htmlspecialchars($_SESSION['user_name']) ?></b></span><a class="btn-out" href="login.php?logout=1">Sign out</a></div>
  </nav>

  <main class="main">
    <div class="head-row">
      <div><h1>Admin Users</h1><p class="sub">Manage platform administrator logins, roles and access.</p></div>
      <button class="btn-add" onclick="openAdd()">＋ Add admin</button>
    </div>

    <?php if (isset($_GET['saved']) && isset($savedMap[$_GET['saved']])): ?><div class="banner success"><?= $savedMap[$_GET['saved']] ?></div><?php endif; ?>
    <?php if (isset($_GET['err']) && isset($errMap[$_GET['err']])): ?><div class="banner error"><?= $errMap[$_GET['err']] ?></div><?php endif; ?>

    <div class="stats3">
      <div class="stat"><p>Total Admins</p><b class="b"><?= count($admins) ?></b></div>
      <div class="stat"><p>Active</p><b class="g"><?= $activeCount ?></b></div>
      <div class="stat"><p>Suspended</p><b class="r"><?= $suspCount ?></b></div>
    </div>

    <div class="table-card">
      <div class="table-wrap"><table>
        <thead><tr><th>Admin</th><th>Role</th><th>Status</th><th>Created</th><th>Last Login</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): $self = strtolower($a['email']) === $myEmail; ?>
          <tr data-id="<?= $a['id'] ?>" data-name="<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars($a['email'], ENT_QUOTES) ?>" data-role="<?= htmlspecialchars($a['role']) ?>" data-status="<?= htmlspecialchars($a['status']) ?>">
            <td class="name"><b><?= htmlspecialchars($a['name']) ?><?= $self ? ' <span style="color:var(--brand)">(you)</span>' : '' ?></b><div class="email"><?= htmlspecialchars($a['email']) ?></div></td>
            <td><span class="badge role"><?= htmlspecialchars($a['role']) ?></span></td>
            <td><span class="badge <?= $a['status'] === 'active' ? 'active' : 'suspended' ?>"><?= htmlspecialchars($a['status']) ?></span></td>
            <td class="mono"><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
            <td class="mono"><?= $a['last_login'] ? date('M d, H:i', strtotime($a['last_login'])) : '—' ?></td>
            <td><div class="actions">
              <button class="ibtn" data-edit title="Edit / reset password">✏️</button>
              <form method="POST" class="action-form">
                <input type="hidden" name="action" value="toggle_admin"><input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                <button class="abtn <?= $a['status'] === 'active' ? 'stop' : 'go' ?>" <?= $self ? 'disabled title="You cannot suspend yourself"' : '' ?>><?= $a['status'] === 'active' ? 'Suspend' : 'Activate' ?></button>
              </form>
              <form method="POST" class="action-form" onsubmit="return confirm('Delete admin <?= htmlspecialchars(addslashes($a['name']), ENT_QUOTES) ?>? This cannot be undone.')">
                <input type="hidden" name="action" value="delete_admin"><input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                <button class="ibtn del" title="Delete admin" <?= $self ? 'disabled' : '' ?>>🗑️</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$admins): ?><tr><td colspan="6" style="text-align:center;padding:40px">No admin accounts yet — click "＋ Add admin". Your current login still works via the legacy users table.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </main>
</div>

<!-- ADD ADMIN MODAL -->
<div class="modal" id="addModal">
  <form method="POST" class="modal-card action-form">
    <input type="hidden" name="action" value="add_admin">
    <h3>Add administrator</h3>
    <p class="msub">Create a new platform admin login.</p>
    <div class="field"><label>Full name</label><input name="name" required></div>
    <div class="field"><label>Email address</label><input type="email" name="email" required></div>
    <div class="field"><label>Password</label><input type="password" name="password" minlength="6" required></div>
    <div class="field"><label>Role</label>
      <select name="role"><option value="admin">admin</option><option value="superadmin">superadmin</option></select>
    </div>
    <div class="mrow"><button class="btn-save" type="submit">Create admin</button><button type="button" class="cancel" onclick="closeAdd()">Cancel</button></div>
  </form>
</div>

<!-- EDIT ADMIN MODAL -->
<div class="modal" id="editModal">
  <form method="POST" class="modal-card action-form">
    <input type="hidden" name="action" value="update_admin">
    <input type="hidden" name="admin_id" id="edit_id">
    <h3>Edit administrator</h3>
    <p class="msub">Update details, role, status or reset the password.</p>
    <div class="field"><label>Full name</label><input name="name" id="edit_name" required></div>
    <div class="field"><label>Email address</label><input type="email" name="email" id="edit_email" required></div>
    <div class="field"><label>Role</label>
      <select name="role" id="edit_role"><option value="admin">admin</option><option value="superadmin">superadmin</option></select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status" id="edit_status"><option value="active">active</option><option value="suspended">suspended</option></select>
    </div>
    <div class="field"><label>New password (leave blank to keep current)</label><input type="password" name="password" minlength="6" placeholder="••••••••"></div>
    <div class="mrow"><button class="btn-save" type="submit">Save changes</button><button type="button" class="cancel" onclick="closeEdit()">Cancel</button></div>
  </form>
</div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('open');}
const addM=document.getElementById('addModal'), editM=document.getElementById('editModal');
function openAdd(){addM.classList.add('open')} function closeAdd(){addM.classList.remove('open')}
function closeEdit(){editM.classList.remove('open')}
addM.addEventListener('click',e=>{if(e.target===addM)closeAdd()});
editM.addEventListener('click',e=>{if(e.target===editM)closeEdit()});
document.querySelectorAll('button[data-edit]').forEach(b=>b.addEventListener('click',()=>{
  const tr=b.closest('tr');
  document.getElementById('edit_id').value=tr.dataset.id;
  document.getElementById('edit_name').value=tr.dataset.name;
  document.getElementById('edit_email').value=tr.dataset.email;
  document.getElementById('edit_role').value=tr.dataset.role;
  document.getElementById('edit_status').value=tr.dataset.status;
  editM.classList.add('open');
}));
const overlay=document.getElementById('loadingOverlay');
document.querySelectorAll('form').forEach(f=>f.addEventListener('submit',function(e){if(this.onsubmit&&!this.onsubmit(e))return;overlay.classList.add('active');}));
document.querySelectorAll('a[href]').forEach(l=>l.addEventListener('click',function(){if(!this.href.includes('#')&&!this.target)overlay.classList.add('active');}));
</script>
</body>
</html>
