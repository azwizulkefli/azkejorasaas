<?php
/** DIAGNOSTIC + AUTO-REPAIR — visit once, read the table, then DELETE this file. */
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/../config/database.php';
$rows = [];
function step($label, $ok, $detail = '') { global $rows; $rows[] = ['label'=>$label,'ok'=>$ok,'detail'=>$detail]; }

step('PHP version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
step('DB connection (pooler + SSL)', true, 'PDO connected to Supabase');

// 1. users table
try { $n = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(); step('users table readable', true, $n.' row(s)'); }
catch (Throwable $e) { step('users table readable', false, $e->getMessage()); }

// 2. admin row BEFORE repair
$admin = null;
try { $admin = $pdo->query("SELECT email, role, password_hash FROM users WHERE LOWER(email)='admin@azkejora.io'")->fetch(); } catch (Throwable $e) { step('admin query', false, $e->getMessage()); }
step('Admin row exists (before repair)', (bool)$admin, $admin ? 'role='.$admin['role'].' · hash "'.substr($admin['password_hash'],0,4).'…" · len '.strlen($admin['password_hash']) : 'MISSING → setup.php never wrote it (it likely ran while config was still broken)');

// 3. does the stored hash verify?
if ($admin) {
    $v = password_verify('password', $admin['password_hash']);
    step('Stored hash verifies "password"', $v, $v ? 'yes' : 'NO ← this is exactly why login returned err=1');
}

// 4. AUTO-REPAIR: write a fresh PHP-generated bcrypt hash
$hash = password_hash('password', PASSWORD_BCRYPT);
try {
    if ($admin) $pdo->prepare("UPDATE users SET password_hash=?, role='admin', name='Admin User' WHERE LOWER(email)='admin@azkejora.io'")->execute([$hash]);
    else        $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute(['Admin User','admin@azkejora.io',$hash,'admin']);
    step('Auto-repair: fresh bcrypt hash written', true, substr($hash, 0, 7).'…');
} catch (Throwable $e) { step('Auto-repair: fresh bcrypt hash written', false, $e->getMessage()); }

// 5. LIVE simulation of the exact function login.php uses
require_once __DIR__ . '/../includes/auth.php';
$live = login('admin@azkejora.io', 'password');
step('LIVE login() via includes/auth.php', $live, $live ? 'SUCCESS — you can sign in now' : 'STILL FAILING — look at the first red row above');
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Doctor</title>
<style>body{font-family:system-ui;background:#F6F7FB;padding:40px;color:#131327}
.card{max-width:760px;margin:0 auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 12px 32px -16px rgba(19,19,39,.2)}
table{width:100%;border-collapse:collapse;font-size:13px}td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.ok{color:#059669;font-weight:800}.bad{color:#e11d48;font-weight:800}
small{color:#64748b}a{color:#5457e5;font-weight:700}</style></head>
<body><div class="card"><h2>🩺 AZ Kejora Login Doctor</h2>
<table><tr><th style="text-align:left">Check</th><th>Result</th><th style="text-align:left">Detail</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= htmlspecialchars($r['label']) ?></td>
<td class="<?= $r['ok'] ? 'ok' : 'bad' ?>"><?= $r['ok'] ? 'PASS' : 'FAIL' ?></td>
<td><small><?= htmlspecialchars($r['detail']) ?></small></td></tr>
<?php endforeach; ?>
</table>
<p style="margin-top:16px;font-size:13px">
<?php if ($live): ?>✅ Backend login works. Now <b>hard-refresh</b> the landing page (Ctrl+Shift+R) and sign in with <code>admin@azkejora.io</code> / <code>password</code>.<?php else: ?>❌ Still failing — the first red row above tells you why.<?php endif; ?>
<br><b style="color:#e11d48">DELETE doctor.php after use.</b> · <a href="index.php">→ Go to login</a></p>
</div></body></html>
