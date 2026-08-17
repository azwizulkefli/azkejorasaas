<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// Load or create company record
$co = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?");
$co->execute([$uid]);
$company = $co->fetch();

if (!$company) {
    $pdo->prepare("INSERT INTO companies (user_id) VALUES (?)")->execute([$uid]);
    $co->execute([$uid]);
    $company = $co->fetch();
}

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_company') {
        $pdo->prepare("UPDATE companies SET 
            name = ?, registration_no = ?, address = ?, business_type = ?,
            postcode = ?, state = ?, town = ?, updated_at = NOW()
            WHERE user_id = ?")
            ->execute([
                trim($_POST['name'] ?? ''),
                trim($_POST['registration_no'] ?? ''),
                trim($_POST['address'] ?? ''),
                trim($_POST['business_type'] ?? ''),
                trim($_POST['postcode'] ?? ''),
                trim($_POST['state'] ?? ''),
                trim($_POST['town'] ?? ''),
                $uid
            ]);
        header("Location: company.php?updated=company"); exit;
    }
    
    if ($_POST['action'] === 'update_einvoice') {
        $pdo->prepare("UPDATE companies SET 
            msic_code = ?, classification_code = ?, taxpayer_tin = ?, taxpayer_brn = ?,
            sandbox_clientid = ?, sandbox_secret1 = ?, sandbox_secret2 = ?,
            prod_clientid = ?, prod_secret1 = ?, prod_secret2 = ?, updated_at = NOW()
            WHERE user_id = ?")
            ->execute([
                trim($_POST['msic_code'] ?? ''),
                trim($_POST['classification_code'] ?? ''),
                trim($_POST['taxpayer_tin'] ?? ''),
                trim($_POST['taxpayer_brn'] ?? ''),
                trim($_POST['sandbox_clientid'] ?? ''),
                trim($_POST['sandbox_secret1'] ?? ''),
                trim($_POST['sandbox_secret2'] ?? ''),
                trim($_POST['prod_clientid'] ?? ''),
                trim($_POST['prod_secret1'] ?? ''),
                trim($_POST['prod_secret2'] ?? ''),
                $uid
            ]);
        header("Location: company.php?updated=einvoice"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Company Profile — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
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
.btn-back{background:#f1f5f9;color:#475569;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}
.main{max-width:900px;margin:0 auto;padding:32px 24px}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:20px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
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
@media(max-width:700px){.grid2{grid-template-columns:1fr}}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
@media(max-width:700px){.grid3{grid-template-columns:1fr}}
.btn-save{background:var(--grad);color:#fff;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:700;margin-top:20px}
.btn-save:hover{opacity:.9}
.hint{font-size:11px;color:var(--faint);margin-top:4px}
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
  <a href="main.php" class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></a>
  <div class="top-right">
    <a class="btn-back" href="main.php">← Back to dashboard</a>
  </div>
</nav>

<main class="main">
  <h1>Company Profile</h1>
  <p class="sub">Manage your business details and e-Invoice configuration.</p>

  <?php if (isset($_GET['updated']) && $_GET['updated'] === 'company'): ?>
    <div class="banner success">✓ Company profile updated successfully.</div>
  <?php endif; ?>
  <?php if (isset($_GET['updated']) && $_GET['updated'] === 'einvoice'): ?>
    <div class="banner success">✓ E-Invoice configuration saved.</div>
  <?php endif; ?>

  <!-- Company Profile -->
  <form method="POST" class="card action-form">
    <input type="hidden" name="action" value="update_company">
    <h2>🏢 Company Information</h2>
    <p class="msub">Basic details about your business.</p>
    
    <div class="field"><label>Company name</label><input type="text" name="name" value="<?= htmlspecialchars($company['name'] ?? '') ?>" placeholder="ABC Sdn Bhd"></div>
    
    <div class="grid2">
      <div class="field"><label>Registration no</label><input type="text" name="registration_no" value="<?= htmlspecialchars($company['registration_no'] ?? '') ?>" placeholder="202001012345"></div>
      <div class="field"><label>Business type</label>
        <select name="business_type">
          <option value="">Select...</option>
          <option value="Sole Proprietorship" <?= ($company['business_type'] ?? '') === 'Sole Proprietorship' ? 'selected' : '' ?>>Sole Proprietorship</option>
          <option value="Partnership" <?= ($company['business_type'] ?? '') === 'Partnership' ? 'selected' : '' ?>>Partnership</option>
          <option value="Private Limited" <?= ($company['business_type'] ?? '') === 'Private Limited' ? 'selected' : '' ?>>Private Limited (Sdn Bhd)</option>
          <option value="Public Limited" <?= ($company['business_type'] ?? '') === 'Public Limited' ? 'selected' : '' ?>>Public Limited (Bhd)</option>
          <option value="LLP" <?= ($company['business_type'] ?? '') === 'LLP' ? 'selected' : '' ?>>Limited Liability Partnership</option>
        </select>
      </div>
    </div>
    
    <div class="field"><label>Address</label><textarea name="address" placeholder="No. 1, Jalan Example, Taman Test"><?= htmlspecialchars($company['address'] ?? '') ?></textarea></div>
    
    <div class="grid3">
      <div class="field"><label>Postcode</label><input type="text" name="postcode" value="<?= htmlspecialchars($company['postcode'] ?? '') ?>" placeholder="50000"></div>
      <div class="field"><label>Town / City</label><input type="text" name="town" value="<?= htmlspecialchars($company['town'] ?? '') ?>" placeholder="Kuala Lumpur"></div>
      <div class="field"><label>State</label>
        <select name="state">
          <option value="">Select...</option>
          <?php $states = ['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Perak','Perlis','Pulau Pinang','Sabah','Sarawak','Selangor','Terengganu','WP Kuala Lumpur','WP Putrajaya','WP Labuan']; ?>
          <?php foreach ($states as $st): ?>
            <option value="<?= $st ?>" <?= ($company['state'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    
    <button type="submit" class="btn-save">Save company profile</button>
  </form>

  <!-- E-Invoice Configuration -->
  <form method="POST" class="card action-form">
    <input type="hidden" name="action" value="update_einvoice">
    <h2>🧾 E-Invoice Configuration</h2>
    <p class="msub">LHDN e-Invoice API credentials and tax identifiers.</p>
    
    <div class="grid2">
      <div class="field"><label>MSIC code</label><input type="text" name="msic_code" value="<?= htmlspecialchars($company['msic_code'] ?? '') ?>" placeholder="62010">
        <p class="hint">Malaysia Standard Industrial Classification</p>
      </div>
      <div class="field"><label>Classification code</label><input type="text" name="classification_code" value="<?= htmlspecialchars($company['classification_code'] ?? '') ?>" placeholder="022">
        <p class="hint">Business activity classification</p>
      </div>
    </div>
    
    <div class="grid2">
      <div class="field"><label>Taxpayer TIN</label><input type="text" name="taxpayer_tin" value="<?= htmlspecialchars($company['taxpayer_tin'] ?? '') ?>" placeholder="C2012345678">
        <p class="hint">Tax Identification Number from LHDN</p>
      </div>
      <div class="field"><label>Taxpayer BRN</label><input type="text" name="taxpayer_brn" value="<?= htmlspecialchars($company['taxpayer_brn'] ?? '') ?>" placeholder="202001012345">
        <p class="hint">Business Registration Number</p>
      </div>
    </div>
    
    <h3 style="margin-top:24px;font-size:16px;font-weight:700">🧪 Sandbox Credentials</h3>
    <p class="hint" style="margin-bottom:12px">For testing with LHDN sandbox environment</p>
    
    <div class="field"><label>Client ID</label><input type="text" name="sandbox_clientid" value="<?= htmlspecialchars($company['sandbox_clientid'] ?? '') ?>"></div>
    <div class="grid2">
      <div class="field"><label>Client Secret 1</label><input type="password" name="sandbox_secret1" value="<?= htmlspecialchars($company['sandbox_secret1'] ?? '') ?>"></div>
      <div class="field"><label>Client Secret 2</label><input type="password" name="sandbox_secret2" value="<?= htmlspecialchars($company['sandbox_secret2'] ?? '') ?>"></div>
    </div>
    
    <h3 style="margin-top:24px;font-size:16px;font-weight:700">🚀 Production Credentials</h3>
    <p class="hint" style="margin-bottom:12px">For live e-Invoice submissions</p>
    
    <div class="field"><label>Client ID</label><input type="text" name="prod_clientid" value="<?= htmlspecialchars($company['prod_clientid'] ?? '') ?>"></div>
    <div class="grid2">
      <div class="field"><label>Client Secret 1</label><input type="password" name="prod_secret1" value="<?= htmlspecialchars($prod_secret1'] ?? '') ?>"></div>
      <div class="field"><label>Client Secret 2</label><input type="password" name="prod_secret2" value="<?= htmlspecialchars($company['prod_secret2'] ?? '') ?>"></div>
    </div>
    
    <button type="submit" class="btn-save">Save e-Invoice config</button>
  </form>
</main>

<script>
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
