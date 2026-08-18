<?php
require_once '../includes/auth.php';
require_once '../includes/settings.php';
requireAdmin();
ensure_settings_table($pdo);

/* Auto-create platform table if missing */
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_company (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL DEFAULT 'AZ Kejora SaaS',
    registration_no VARCHAR(100), address TEXT, postcode VARCHAR(20),
    state VARCHAR(100), town VARCHAR(100), email VARCHAR(255), phone VARCHAR(50),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW())");

$subUserId = trim($_GET['user_id'] ?? '');
$subUser = null; $company = null; $mode = 'platform';

if ($subUserId !== '') {
    $st = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'customer'");
    $st->execute([$subUserId]);
    $subUser = $st->fetch();
    if ($subUser) {
        $mode = 'subscriber';
        $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?");
        $c->execute([$subUserId]);
        $company = $c->fetch();
        if (!$company) {
            $pdo->prepare("INSERT INTO companies (user_id) VALUES (?)")->execute([$subUserId]);
            $c->execute([$subUserId]); $company = $c->fetch();
        }
    }
}
if ($mode === 'platform') {
    $company = $pdo->query("SELECT * FROM admin_company ORDER BY updated_at DESC LIMIT 1")->fetch();
    if (!$company) {
        $pdo->exec("INSERT INTO admin_company (name) VALUES ('AZ Kejora SaaS')");
        $company = $pdo->query("SELECT * FROM admin_company LIMIT 1")->fetch();
    }
}

/* ---------------- POST ACTIONS ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_admin_company') {
        $pdo->prepare("UPDATE admin_company SET name=?, registration_no=?, address=?, postcode=?, state=?, town=?, email=?, phone=?, updated_at=NOW() WHERE id=?")
            ->execute([
                trim($_POST['name'] ?? ''), trim($_POST['registration_no'] ?? ''), trim($_POST['address'] ?? ''),
                trim($_POST['postcode'] ?? ''), trim($_POST['state'] ?? ''), trim($_POST['town'] ?? ''),
                trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? ''), $company['id']
            ]);
        header("Location: admin_company.php?updated=1"); exit;
    }

    if ($_POST['action'] === 'save_sub_company') {
        $pdo->prepare("UPDATE companies SET name=?, registration_no=?, address=?, business_type=?, postcode=?, state=?, town=?, updated_at=NOW() WHERE user_id=?")
            ->execute([
                trim($_POST['name'] ?? ''), trim($_POST['registration_no'] ?? ''), trim($_POST['address'] ?? ''),
                trim($_POST['business_type'] ?? ''), trim($_POST['postcode'] ?? ''), trim($_POST['state'] ?? ''),
                trim($_POST['town'] ?? ''), $subUserId
            ]);
        header("Location: admin_company.php?user_id=" . urlencode($subUserId) . "&updated=1"); exit;
    }

    if ($_POST['action'] === 'save_sub_einvoice') {
        $pdo->prepare("UPDATE companies SET msic_code=?, classification_code=?, taxpayer_tin=?, taxpayer_brn=?,
            sandbox_clientid=?, sandbox_secret1=?, sandbox_secret2=?, prod_clientid=?, prod_secret1=?, prod_secret2=?, updated_at=NOW()
            WHERE user_id=?")
            ->execute([
                trim($_POST['msic_code'] ?? ''), trim($_POST['classification_code'] ?? ''), trim($_POST['taxpayer_tin'] ?? ''),
                trim($_POST['taxpayer_brn'] ?? ''), trim($_POST['sandbox_clientid'] ?? ''), trim($_POST['sandbox_secret1'] ?? ''),
                trim($_POST['sandbox_secret2'] ?? ''), trim($_POST['prod_clientid'] ?? ''), trim($_POST['prod_secret1'] ?? ''),
                trim($_POST['prod_secret2'] ?? ''), $subUserId
            ]);
        header("Location: admin_company.php?user_id=" . urlencode($subUserId) . "&updated=1"); exit;
    }
}

$states = ['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Perak','Perlis','Pulau Pinang','Sabah','Sarawak','Selangor','Terengganu','WP Kuala Lumpur','WP Putrajaya','WP Labuan'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Company — AZ Kejora Admin</title>
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
.main{max-width:900px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}
.card h2{font-size:20px;font-weight:800;margin-bottom:4px}
.card .msub{font-size:13px;color:var(--muted);margin-bottom:20px}
.field{margin-top:14px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.field textarea{resize:vertical;min-height:80px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:700;margin-top:20px}
.btn-save:hover{opacity:.9}
.hint{font-size:11px;color:var(--faint);margin-top:4px}
.backlink{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--brand);margin-bottom:12px}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
}
@media(max-width:700px){
  .grid2,.grid3{grid-template-columns:1fr}
  .main{padding:20px 12px}
  h1{font-size:22px}
}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay"><div><div class="spinner"></div><p class="spinner-text">Processing…</p></div></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand"><span class="logo">⚡</span>AZ Kejora <em>Admin</em></span></div>
  <nav class="sidebar-nav">
    <a href="admin.php" class="menu-item">📊 Dashboard</a>
    <a href="admin_company.php" class="menu-item active">🏢 Company</a>
    <a href="admin_users.php" class="menu-item">👥 Admins</a>
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
    <?php if ($mode === 'subscriber' && $subUser): ?>
      <a class="backlink" href="admin.php">← Back to subscribers</a>
      <h1>Subscriber Company — <?= htmlspecialchars($subUser['name']) ?></h1>
      <p class="sub">Editing company & e-Invoice data for <b><?= htmlspecialchars($subUser['email']) ?></b>.</p>
    <?php else: ?>
      <h1>Platform Company</h1>
      <p class="sub">AZ Kejora's own business details, shown on invoices & reports.</p>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?><div class="banner success">✓ Company details saved successfully.</div><?php endif; ?>

    <?php if ($mode === 'platform'): ?>
      <!-- ============ PLATFORM ADMIN COMPANY ============ -->
      <form method="POST" class="card">
        <input type="hidden" name="action" value="save_admin_company">
        <h2>🏢 Admin Company Information</h2>
        <p class="msub">Business identity for the platform operator.</p>
        <div class="field"><label>Company name</label><input type="text" name="name" value="<?= htmlspecialchars($company['name'] ?? '') ?>" required></div>
        <div class="grid2">
          <div class="field"><label>Registration no</label><input type="text" name="registration_no" value="<?= htmlspecialchars($company['registration_no'] ?? '') ?>"></div>
          <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($company['email'] ?? '') ?>"></div>
        </div>
        <div class="field"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>"></div>
        <div class="field"><label>Address</label><textarea name="address"><?= htmlspecialchars($company['address'] ?? '') ?></textarea></div>
        <div class="grid3">
          <div class="field"><label>Postcode</label><input type="text" name="postcode" value="<?= htmlspecialchars($company['postcode'] ?? '') ?>"></div>
          <div class="field"><label>Town / City</label><input type="text" name="town" value="<?= htmlspecialchars($company['town'] ?? '') ?>"></div>
          <div class="field"><label>State</label>
            <select name="state"><option value="">Select...</option>
              <?php foreach ($states as $s): ?><option value="<?= $s ?>" <?= ($company['state'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn-save">Save platform company</button>
      </form>

    <?php else: ?>
      <!-- ============ SUBSCRIBER COMPANY ============ -->
      <form method="POST" class="card">
        <input type="hidden" name="action" value="save_sub_company">
        <h2>🏢 Company Information</h2>
        <p class="msub">Basic details about the subscriber's business.</p>
        <div class="field"><label>Company name</label><input type="text" name="name" value="<?= htmlspecialchars($company['name'] ?? '') ?>"></div>
        <div class="grid2">
          <div class="field"><label>Registration no</label><input type="text" name="registration_no" value="<?= htmlspecialchars($company['registration_no'] ?? '') ?>"></div>
          <div class="field"><label>Business type</label>
            <select name="business_type"><option value="">Select...</option>
              <?php foreach (['Sole Proprietorship','Partnership','Private Limited','Public Limited','LLP'] as $bt): ?>
                <option value="<?= $bt ?>" <?= ($company['business_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field"><label>Address</label><textarea name="address"><?= htmlspecialchars($company['address'] ?? '') ?></textarea></div>
        <div class="grid3">
          <div class="field"><label>Postcode</label><input type="text" name="postcode" value="<?= htmlspecialchars($company['postcode'] ?? '') ?>"></div>
          <div class="field"><label>Town / City</label><input type="text" name="town" value="<?= htmlspecialchars($company['town'] ?? '') ?>"></div>
          <div class="field"><label>State</label>
            <select name="state"><option value="">Select...</option>
              <?php foreach ($states as $s): ?><option value="<?= $s ?>" <?= ($company['state'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn-save">Save company profile</button>
      </form>

      <form method="POST" class="card">
        <input type="hidden" name="action" value="save_sub_einvoice">
        <h2>🧾 E-Invoice Configuration</h2>
        <p class="msub">LHDN e-Invoice API credentials and tax identifiers.</p>
        <div class="grid2">
          <div class="field"><label>MSIC code</label><input type="text" name="msic_code" value="<?= htmlspecialchars($company['msic_code'] ?? '') ?>"></div>
          <div class="field"><label>Classification code</label><input type="text" name="classification_code" value="<?= htmlspecialchars($company['classification_code'] ?? '') ?>"></div>
        </div>
        <div class="grid2">
          <div class="field"><label>Taxpayer TIN</label><input type="text" name="taxpayer_tin" value="<?= htmlspecialchars($company['taxpayer_tin'] ?? '') ?>"></div>
          <div class="field"><label>Taxpayer BRN</label><input type="text" name="taxpayer_brn" value="<?= htmlspecialchars($company['taxpayer_brn'] ?? '') ?>"></div>
        </div>
        <h3 style="margin-top:24px;font-size:16px;font-weight:700">🧪 Sandbox Credentials</h3>
        <div class="field"><label>Client ID</label><input type="text" name="sandbox_clientid" value="<?= htmlspecialchars($company['sandbox_clientid'] ?? '') ?>"></div>
        <div class="grid2">
          <div class="field"><label>Client Secret 1</label><input type="password" name="sandbox_secret1" value="<?= htmlspecialchars($company['sandbox_secret1'] ?? '') ?>"></div>
          <div class="field"><label>Client Secret 2</label><input type="password" name="sandbox_secret2" value="<?= htmlspecialchars($company['sandbox_secret2'] ?? '') ?>"></div>
        </div>
        <h3 style="margin-top:24px;font-size:16px;font-weight:700">🚀 Production Credentials</h3>
        <div class="field"><label>Client ID</label><input type="text" name="prod_clientid" value="<?= htmlspecialchars($company['prod_clientid'] ?? '') ?>"></div>
        <div class="grid2">
          <div class="field"><label>Client Secret 1</label><input type="password" name="prod_secret1" value="<?= htmlspecialchars($company['prod_secret1'] ?? '') ?>"></div>
          <div class="field"><label>Client Secret 2</label><input type="password" name="prod_secret2" value="<?= htmlspecialchars($company['prod_secret2'] ?? '') ?>"></div>
        </div>
        <button type="submit" class="btn-save">Save e-Invoice config</button>
      </form>
    <?php endif; ?>
  </main>
</div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('open');}
const overlay=document.getElementById('loadingOverlay');
document.querySelectorAll('form').forEach(f=>f.addEventListener('submit',function(e){if(this.onsubmit&&!this.onsubmit(e))return;overlay.classList.add('active');}));
document.querySelectorAll('a[href]').forEach(l=>l.addEventListener('click',function(){if(!this.href.includes('#')&&!this.target)overlay.classList.add('active');}));
</script>
</body>
</html>
