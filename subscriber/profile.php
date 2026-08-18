<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if ($name === '') {
            header("Location: profile.php?err=name_required"); exit;
        }
        
        $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?")
            ->execute([$name, $phone, $uid]);
        
        // Handle avatar upload
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
        
        header("Location: profile.php?updated=profile"); exit;
    }
    
    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if ($new !== $confirm) {
            header("Location: profile.php?err=pwd_mismatch"); exit;
        }
        
        if (strlen($new) < 6) {
            header("Location: profile.php?err=pwd_short"); exit;
        }
        
        // Verify current password
        $ok = password_verify($current, $me['password_hash'])
           || crypt($current, $me['password_hash']) === $me['password_hash'];
        
        if (!$ok) {
            header("Location: profile.php?err=pwd_wrong"); exit;
        }
        
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$hash, $uid]);
        
        header("Location: profile.php?updated=password"); exit;
    }
    
    if ($_POST['action'] === 'remove_avatar') {
        $pdo->prepare("UPDATE users SET avatar_path = NULL WHERE id = ?")->execute([$uid]);
        header("Location: profile.php?updated=avatar_removed"); exit;
    }
}

// Refresh user data
$me = currentUser();
$avatarSrc = $me['avatar_path'] ? '/' . $me['avatar_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — AZ Kejora SaaS</title>
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

.main{max-width:900px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}

.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}

/* ---------- CARDS ---------- */
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}
.card h2{font-size:20px;font-weight:800;margin-bottom:4px}
.card .msub{font-size:13px;color:var(--muted);margin-bottom:20px}

.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input,.field select{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none}
.field input:focus,.field select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}

.avatar-upload{display:flex;align-items:center;gap:20px;margin-top:14px;flex-wrap:wrap}
.avatar-preview{width:100px;height:100px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:32px;overflow:hidden;flex-shrink:0}
.avatar-preview img{width:100%;height:100%;object-fit:cover}
.avatar-actions{display:flex;flex-direction:column;gap:8px}

.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:700;margin-top:20px}
.btn-save:hover{opacity:.9}
.btn-ghost{background:#f1f5f9;color:#475569;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.btn-ghost:hover{background:#e2e8f0}
.btn-danger{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.btn-danger:hover{background:#ffe4e6}

.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}

.hint{font-size:11px;color:var(--faint);margin-top:4px}

/* ---------- RESPONSIVE ---------- */
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
  .grid2{grid-template-columns:1fr}
  .avatar-upload{flex-direction:column;align-items:flex-start}
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
    <a href="users.php" class="menu-item">👥 Users</a>
    <a href="profile.php" class="menu-item active">👤 Profile</a>
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
    <h1>My Profile</h1>
    <p class="sub">Manage your personal information and account security.</p>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'profile'): ?>
      <div class="banner success">✓ Profile updated successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'password'): ?>
      <div class="banner success">✓ Password changed successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'avatar-removed'): ?>
      <div class="banner success">✓ Profile picture removed.</div>
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
    <?php if (isset($_GET['err']) && $_GET['err'] === 'name_required'): ?>
      <div class="banner error">✗ Full name is required.</div>
    <?php endif; ?>

    <!-- ============ PROFILE INFORMATION ============ -->
    <form method="POST" enctype="multipart/form-data" class="card action-form">
      <input type="hidden" name="action" value="update_profile">
      <h2>👤 Personal Information</h2>
      <p class="msub">Update your name, contact details and profile picture.</p>

      <div class="avatar-upload">
        <div class="avatar-preview" id="avatarPreview">
          <?php if ($avatarSrc): ?>
            <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Avatar" id="avatarImg">
          <?php else: ?>
            <?= strtoupper(substr($me['name'],0,1)) ?>
          <?php endif; ?>
        </div>
        <div class="avatar-actions">
          <label class="btn-ghost" style="cursor:pointer;text-align:center">
            📷 Upload Picture
            <input type="file" name="avatar" accept="image/*" style="display:none" id="avatarInput">
          </label>
          <?php if ($avatarSrc): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="remove_avatar">
              <button type="submit" class="btn-danger" onclick="return confirm('Remove your profile picture?')">Remove</button>
            </form>
          <?php endif; ?>
          <p class="hint">JPG, PNG or GIF · Max 2MB</p>
        </div>
      </div>

      <div class="field">
        <label>Full name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($me['name']) ?>" required>
      </div>

      <div class="field">
        <label>Email address</label>
        <input type="email" value="<?= htmlspecialchars($me['email']) ?>" disabled>
        <p class="hint">Email cannot be changed for security reasons</p>
      </div>

      <div class="field">
        <label>Phone number</label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($me['phone'] ?? '') ?>" placeholder="+60 12-345 6789">
      </div>

      <div class="field">
        <label>Account type</label>
        <input type="text" value="<?= htmlspecialchars(ucfirst($me['reg_type'] ?? 'manual')) ?>" disabled>
        <p class="hint">Signed up via <?= $me['reg_type'] === 'google' ? 'Google OAuth' : 'Email & Password' ?></p>
      </div>

      <div class="field">
        <label>Member since</label>
        <input type="text" value="<?= date('F d, Y', strtotime($me['created_at'])) ?>" disabled>
      </div>

      <button type="submit" class="btn-save">Save changes</button>
    </form>

    <!-- ============ CHANGE PASSWORD ============ -->
    <form method="POST" class="card action-form">
      <input type="hidden" name="action" value="change_password">
      <h2>🔐 Change Password</h2>
      <p class="msub">Update your account password to keep your account secure.</p>

      <?php if ($me['reg_type'] === 'google' && empty($me['password_hash'])): ?>
        <div class="banner" style="background:#fef3c7;color:#d97706;margin-bottom:20px">
          ⚠ You signed up with Google and haven't set a password yet. Set one below to enable email login.
        </div>
      <?php endif; ?>

      <?php if ($me['reg_type'] !== 'google' || !empty($me['password_hash'])): ?>
        <div class="field">
          <label>Current password</label>
          <input type="password" name="current_password" required>
        </div>
      <?php endif; ?>

      <div class="grid2">
        <div class="field">
          <label>New password</label>
          <input type="password" name="new_password" minlength="6" required>
          <p class="hint">Minimum 6 characters</p>
        </div>
        <div class="field">
          <label>Confirm new password</label>
          <input type="password" name="confirm_password" minlength="6" required>
        </div>
      </div>

      <button type="submit" class="btn-save">Update password</button>
    </form>

    <!-- ============ ACCOUNT INFO ============ -->
    <div class="card">
      <h2>📊 Account Summary</h2>
      <p class="msub">Quick overview of your account status.</p>

      <div class="grid2">
        <div class="field">
          <label>User ID</label>
          <input type="text" value="<?= htmlspecialchars(substr($me['id'], 0, 8) . '...') ?>" disabled>
        </div>
        <div class="field">
          <label>Role</label>
          <input type="text" value="<?= htmlspecialchars(ucfirst($me['role'])) ?>" disabled>
        </div>
      </div>

      <div class="field">
        <label>Last login</label>
        <input type="text" value="<?= $me['last_login'] ? date('M d, Y · H:i', strtotime($me['last_login'])) : 'Not recorded' ?>" disabled>
      </div>
    </div>
  </main>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

// Avatar preview
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

// Loading overlay
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
