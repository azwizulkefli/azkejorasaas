<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/myinvois.php';
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

/* ================= HANDLE FORM SUBMISSIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ---------- Update Company Info ---------- */
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

    /* ---------- Update E-Invoice Config ---------- */
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

    /* ---------- Switch Environment ---------- */
    if ($_POST['action'] === 'update_ei_env') {
        $env = (($_POST['ei_env'] ?? 'sandbox') === 'prod') ? 'prod' : 'sandbox';
        $pdo->prepare("UPDATE users SET ei_env = ? WHERE id = ?")->execute([$env, $uid]);
        header("Location: company.php?updated=env"); exit;
    }

    /* ---------- Get Token (FIXED: saves to companies table) ---------- */
    if ($_POST['action'] === 'get_token') {
        // Determine environment from user settings
        $envIsProd = ($me['ei_env'] ?? 'sandbox') === 'prod';
        $env       = $envIsProd ? 'prod' : 'sandbox';
        $envLabel  = $envIsProd ? 'Production' : 'Sandbox';

        // Get credentials FROM companies table (not users table)
        if ($envIsProd) {
            $clientId     = trim($company['prod_clientid'] ?? '');
            $clientSecret = trim($company['prod_secret1'] ?? '');
        } else {
            $clientId     = trim($company['sandbox_clientid'] ?? '');
            $clientSecret = trim($company['sandbox_secret1'] ?? '');
        }

        // Validate credentials exist
        if (empty($clientId) || empty($clientSecret)) {
            header("Location: company.php?token=err&msg=" . urlencode("{$envLabel} credentials not set. Please save your Client ID and Client Secret 1 in E-Invoice Configuration first."));
            exit;
        }

        // Determine LHDN base URL
        if ($envIsProd) {
            $baseUrl = $me['ei_url_prod'] ?? 'https://api.myinvois.hasil.gov.my';
        } else {
            $baseUrl = $me['ei_url_sandbox'] ?? 'https://preprod-api.myinvois.hasil.gov.my';
        }
        $tokenUrl = rtrim($baseUrl, '/') . '/connect/token';

        // Build OAuth 2.0 client_credentials POST body
        $postData = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret
        ]);

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Handle cURL connection errors
        if ($curlError) {
            $errDetails = "Error #{$curlErrno}: {$curlError}";
            // Provide helpful hints based on common errors
            if ($curlErrno == CURLE_COULDNT_RESOLVE_HOST) {
                $errDetails .= " — Cannot resolve LHDN server. Check your internet/DNS.";
            } elseif ($curlErrno == CURLE_SSL_CONNECT_ERROR) {
                $errDetails .= " — SSL/TLS handshake failed. Check server SSL certificate.";
            } elseif ($curlErrno == CURLE_OPERATION_TIMEOUTED) {
                $errDetails .= " — Connection timed out (30s). LHDN server may be slow or unreachable.";
            }
            header("Location: company.php?token=err&msg=" . urlencode("Connection failed to {$envLabel} LHDN API. {$errDetails}"));
            exit;
        }

        // Parse JSON response from LHDN
        $tokenData = json_decode($response, true);
        $jsonError = json_last_error_msg();

        // Check for API errors
        if ($httpCode !== 200 || !isset($tokenData['access_token'])) {
            $errorMsg = "HTTP {$httpCode} from {$envLabel} LHDN API";

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Non-JSON response (could be HTML error page)
                $errorMsg .= " — Invalid JSON response ({$jsonError}). Raw: " . substr($response, 0, 300);
            } else {
                // JSON error response from LHDN
                if (isset($tokenData['error'])) {
                    $errorMsg .= " — Error: " . $tokenData['error'];
                }
                if (isset($tokenData['error_description'])) {
                    $errorMsg .= " — " . $tokenData['error_description'];
                }
                if (!isset($tokenData['error']) && !isset($tokenData['error_description'])) {
                    $errorMsg .= " — Response: " . substr($response, 0, 500);
                }
            }

            header("Location: company.php?token=err&msg=" . urlencode($errorMsg));
            exit;
        }

        // SUCCESS — Calculate expiry time
        // LHDN returns expires_in in seconds (typically 3600 = 1 hour)
        $expiresIn  = (int)($tokenData['expires_in'] ?? 3600);
        // Subtract 60 seconds buffer to avoid edge case of using token right at expiry
        $expiryTs   = time() + $expiresIn - 60;
        $expiryTime = date('Y-m-d H:i:s', $expiryTs);

        // Save token to COMPANIES table (this is the key fix!)
        if ($envIsProd) {
            $stmtToken = $pdo->prepare("UPDATE companies SET 
                prod_token = ?, 
                prod_token_expiry = ?, 
                updated_at = NOW() 
                WHERE user_id = ?");
            $stmtToken->execute([
                $tokenData['access_token'],
                $expiryTime,
                $uid
            ]);
        } else {
            $stmtToken = $pdo->prepare("UPDATE companies SET 
                sandbox_token = ?, 
                sandbox_token_expiry = ?, 
                updated_at = NOW() 
                WHERE user_id = ?");
            $stmtToken->execute([
                $tokenData['access_token'],
                $expiryTime,
                $uid
            ]);
        }

        // Also save to users table for backward compatibility
        // (in case other code reads from users.ei_token)
        $pdo->prepare("UPDATE users SET 
            ei_token = ?, 
            ei_token_at = NOW() 
            WHERE id = ?")
            ->execute([
                $tokenData['access_token'],
                $uid
            ]);

        // Refresh company data so the display below shows the new token
        $co->execute([$uid]);
        $company = $co->fetch();

        header("Location: company.php?token=ok&env=" . $env);
        exit;
    }
}

/* ================= REFRESH DATA AFTER UPDATES ================= */
 $me       = currentUser();
 $envIsProd = ($me['ei_env'] ?? 'sandbox') === 'prod';
 $envLabel  = $envIsProd ? 'Production' : 'Sandbox (UAT)';

// Determine LHDN base URL
if ($envIsProd) {
    $envUrl = $me['ei_url_prod'] ?? 'https://api.myinvois.hasil.gov.my';
} else {
    $envUrl = $me['ei_url_sandbox'] ?? 'https://preprod-api.myinvois.hasil.gov.my';
}

/* ================= TOKEN STATUS FROM COMPANIES TABLE ================= */
// Get token for CURRENT environment from companies table
if ($envIsProd) {
    $currentToken      = $company['prod_token'] ?? null;
    $currentTokenExpiry = $company['prod_token_expiry'] ?? null;
} else {
    $currentToken      = $company['sandbox_token'] ?? null;
    $currentTokenExpiry = $company['sandbox_token_expiry'] ?? null;
}

 $maskedTok = !empty($currentToken) ? substr($currentToken, 0, 10) . '••••••••••••' : null;

// Determine token status
 $tokenStatus     = 'none';
 $tokenStatusMsg  = 'No token generated yet';
 $tokenStatusColor = '#f59e0b'; // amber
 $tokenExpiryTs   = null;

if (!empty($currentToken)) {
    if (!empty($currentTokenExpiry)) {
        $tokenExpiryTs = strtotime($currentTokenExpiry);
        if ($tokenExpiryTs === false) {
            $tokenStatus     = 'unknown';
            $tokenStatusMsg  = 'Cannot parse expiry date: ' . $currentTokenExpiry;
            $tokenStatusColor = '#f59e0b';
        } elseif ($tokenExpiryTs <= time()) {
            $tokenStatus     = 'expired';
            $tokenStatusMsg  = 'Expired on ' . date('M d, Y · H:i', $tokenExpiryTs);
            $tokenStatusColor = '#ef4444'; // red
        } else {
            $tokenStatus     = 'valid';
            $tokenStatusMsg  = 'Valid until ' . date('M d, Y · H:i', $tokenExpiryTs);
            $tokenStatusColor = '#10b981'; // green
            // Check if expiring soon (within 1 hour)
            if ($tokenExpiryTs <= time() + 3600) {
                $tokenStatusMsg .= ' (expiring soon!)';
                $tokenStatusColor = '#f59e0b';
            }
        }
    } else {
        $tokenStatus     = 'unknown';
        $tokenStatusMsg  = 'Token exists but no expiry date';
        $tokenStatusColor = '#f59e0b';
    }
}

// Get other environment token info for display
if ($envIsProd) {
    $otherEnvToken  = $company['sandbox_token'] ?? null;
    $otherEnvExpiry = $company['sandbox_token_expiry'] ?? null;
    $otherEnvLabel  = 'Sandbox';
} else {
    $otherEnvToken  = $company['prod_token'] ?? null;
    $otherEnvExpiry = $company['prod_token_expiry'] ?? null;
    $otherEnvLabel  = 'Production';
}
 $otherEnvMasked = !empty($otherEnvToken) ? substr($otherEnvToken, 0, 10) . '••••••••••••' : null;
 $otherEnvStatus = 'none';
if (!empty($otherEnvToken)) {
    if (!empty($otherEnvExpiry)) {
        $otherExpiryTs = strtotime($otherEnvExpiry);
        if ($otherExpiryTs && $otherExpiryTs <= time()) {
            $otherEnvStatus = 'expired';
        } else {
            $otherEnvStatus = 'valid';
        }
    } else {
        $otherEnvStatus = 'unknown';
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
.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}

.main{max-width:900px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.banner{margin:20px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}
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
.hint{font-size:11px;color:var(--faint);margin-top:4px;word-break:break-all}

/* ---- environment selector ---- */
.env-opt{border:1px solid var(--line);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px;cursor:pointer;transition:.15s;background:#fff}
.env-opt input{width:auto;margin:0 0 4px 0}
.env-opt b{font-size:14px}
.env-opt small{color:var(--faint);font-size:11px;word-break:break-all}
.env-opt.on{border-color:var(--brand);background:#f5f6ff;box-shadow:0 0 0 3px rgba(99,102,241,.12)}

/* ---- token box ---- */
.trow{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f8fafc;border-radius:10px;padding:12px 16px;margin-top:10px;font-size:13px;flex-wrap:wrap}
.trow span{color:var(--muted);font-weight:600}
.trow b{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#1e293b;word-break:break-all}
.badge-env{display:inline-block;border-radius:999px;padding:3px 12px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge-env.sandbox{background:#e0e5ff;color:#4644cf}
.badge-env.prod{background:#d1fae5;color:#059669}

/* ---- token status badge ---- */
.token-status-box{border-radius:12px;padding:16px 18px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.token-status-box.valid{background:#d1fae5;border:1px solid #6ee7b7}
.token-status-box.expired{background:#ffe4e6;border:1px solid #fda4af}
.token-status-box.none,.token-status-box.unknown{background:#fef3c7;border:1px solid #fcd34d}
.token-status-icon{font-size:28px}
.token-status-text{flex:1;min-width:200px}
.token-status-title{font-size:14px;font-weight:700;color:var(--ink)}
.token-status-sub{font-size:12px;color:var(--muted);margin-top:2px}

/* ---------- RESPONSIVE ---------- */
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
  .topbar{padding:12px 14px}
  .top-right{gap:8px;font-size:12px}
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
    <a href="s_payment.php" class="menu-item">🧾 Payment</a>
    <a href="s_report.php" class="menu-item">🧾 Report</a>
    <div class="menu-section">Setup</div>
    <a href="company.php" class="menu-item active">🏢 Company</a>
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
      <span class="avatar"><?= strtoupper(substr($me['name'], 0, 1)) ?></span>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
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
    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'env'): ?>
      <div class="banner success">✓ Submission environment switched to <?= $envLabel ?>.</div>
    <?php endif; ?>
    <?php if (isset($_GET['token']) && $_GET['token'] === 'ok'): ?>
      <div class="banner success">🔑 Access token received from LHDN (<?= $envLabel ?>) and saved to company profile. Token valid for ~1 hour.</div>
    <?php endif; ?>
    <?php if (isset($_GET['token']) && $_GET['token'] === 'err'): ?>
      <div class="banner error">✗ <?= htmlspecialchars($_GET['msg'] ?? 'Token request failed.') ?></div>
    <?php endif; ?>

    <!-- ============ COMPANY PROFILE ============ -->
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

    <!-- ============ SUBMISSION ENVIRONMENT ============ -->
    <form method="POST" class="card action-form">
      <input type="hidden" name="action" value="update_ei_env">
      <h2>🌐 Submission Environment</h2>
      <p class="msub">Choose which LHDN MyInvois gateway receives your e-Invoices.</p>

      <div class="grid2">
        <label class="env-opt <?= !$envIsProd ? 'on' : '' ?>">
          <input type="radio" name="ei_env" value="sandbox" <?= !$envIsProd ? 'checked' : '' ?>>
          <b>🧪 Sandbox (UAT)</b>
          <small><?= htmlspecialchars($me['ei_url_sandbox'] ?? 'https://preprod-api.myinvois.hasil.gov.my') ?></small>
        </label>
        <label class="env-opt <?= $envIsProd ? 'on' : '' ?>">
          <input type="radio" name="ei_env" value="prod" <?= $envIsProd ? 'checked' : '' ?>>
          <b>🚀 Production</b>
          <small><?= htmlspecialchars($me['ei_url_prod'] ?? 'https://api.myinvois.hasil.gov.my') ?></small>
        </label>
      </div>

      <button type="submit" class="btn-save">Save environment</button>
    </form>

    <!-- ============ E-INVOICE CONFIGURATION ============ -->
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
        <div class="field"><label>Client Secret 1</label><input type="password" name="prod_secret1" value="<?= htmlspecialchars($company['prod_secret1'] ?? '') ?>"></div>
        <div class="field"><label>Client Secret 2</label><input type="password" name="prod_secret2" value="<?= htmlspecialchars($company['prod_secret2'] ?? '') ?>"></div>
      </div>

      <button type="submit" class="btn-save">Save e-Invoice config</button>
    </form>

    <!-- ============ ACCESS TOKEN ============ -->
    <form method="POST" class="card action-form">
      <input type="hidden" name="action" value="get_token">
      <h2>🔑 MyInvois Access Token</h2>
      <p class="msub">Requests an OAuth 2.0 <code>client_credentials</code> token from LHDN using your saved <?= htmlspecialchars($envLabel) ?> credentials, then stores it in your company profile.</p>

      <!-- Token Status Display -->
      <div class="token-status-box <?= $tokenStatus ?>">
        <div class="token-status-icon">
          <?php if ($tokenStatus === 'valid'): ?>✅
          <?php elseif ($tokenStatus === 'expired'): ?>❌
          <?php else: ?>⚠️<?php endif; ?>
        </div>
        <div class="token-status-text">
          <div class="token-status-title">
            <?= $envLabel ?> Token — 
            <?php if ($tokenStatus === 'valid'): ?>Valid
            <?php elseif ($tokenStatus === 'expired'): ?>Expired
            <?php elseif ($tokenStatus === 'none'): ?>Not Generated
            <?php else: ?>Unknown<?php endif; ?>
          </div>
          <div class="token-status-sub"><?= htmlspecialchars($tokenStatusMsg) ?></div>
        </div>
      </div>

      <div class="trow">
        <span>Environment</span>
        <b><span class="badge-env <?= $envIsProd ? 'prod' : 'sandbox' ?>"><?= htmlspecialchars($envLabel) ?></span></b>
      </div>
      <div class="trow">
        <span>API URL</span>
        <b><?= htmlspecialchars($envUrl) ?>/connect/token</b>
      </div>
      <div class="trow">
        <span>Client ID</span>
        <b><?= $envIsProd ? htmlspecialchars($company['prod_clientid'] ?? '—') : htmlspecialchars($company['sandbox_clientid'] ?? '—') ?></b>
      </div>
      <div class="trow">
        <span>Client Secret 1</span>
        <b><?= $envIsProd ? ($company['prod_secret1'] ? '•••••••• (set)' : 'Not set') : ($company['sandbox_secret1'] ? '•••••••• (set)' : 'Not set') ?></b>
      </div>
      <div class="trow">
        <span>Access token</span>
        <b><?= $maskedTok ? htmlspecialchars($maskedTok) : 'Not generated yet' ?></b>
      </div>
      <div class="trow">
        <span>Token expiry</span>
        <b><?= !empty($currentTokenExpiry) ? date('M d, Y · H:i:s', strtotime($currentTokenExpiry)) : '—' ?></b>
      </div>
      <div class="trow">
        <span>Raw expiry (debug)</span>
        <b style="font-size:11px"><?= htmlspecialchars($currentTokenExpiry ?? 'NULL') ?></b>
      </div>

      <!-- Other environment token status -->
      <div class="trow" style="margin-top:14px;background:#f1f5f9">
        <span><?= $otherEnvLabel ?> token</span>
        <b>
          <?php if ($otherEnvStatus === 'valid'): ?>✅ Valid
          <?php elseif ($otherEnvStatus === 'expired'): ?>❌ Expired
          <?php elseif ($otherEnvStatus === 'unknown'): ?>⚠️ Unknown
          <?php else: ?>— Not set<?php endif; ?>
          <?= $otherEnvMasked ? '(' . htmlspecialchars($otherEnvMasked) . ')' : '' ?>
        </b>
      </div>

      <button type="submit" class="btn-save">🔑 Get / Refresh token</button>
    </form>

  </main>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

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
// keep environment cards highlighted when radio changes
document.querySelectorAll('.env-opt input').forEach(r => r.addEventListener('change', () => {
  document.querySelectorAll('.env-opt').forEach(o => o.classList.remove('on'));
  r.closest('.env-opt').classList.add('on');
}));
</script>
</body>
</html>
