<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php'; // ✅ Provides $pdo and DB constants safely
requireCustomer();

$uid = currentUserId();
$summary = $_SESSION['einvoice_summary'] ?? ['submitted' => 0, 'in_progress' => 0, 'valid' => 0, 'invalid' => 0, 'error' => 0];
unset($_SESSION['einvoice_summary']); // Clear after reading

// $pdo is now safely available from the included files
$stmt = $pdo->prepare("SELECT * FROM einvoice_consolidated WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$uid]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submission Summary — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink)}a{text-decoration:none}
.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}h1{font-size:28px;font-weight:800;letter-spacing:-.02em}.sub{color:var(--muted);font-size:14px;margin-top:4px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}.card h2{font-size:20px;font-weight:800;margin-bottom:4px}
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px}.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center}.summary-card b{display:block;font-size:28px;font-weight:800}.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
table{width:100%;border-collapse:collapse;font-size:14px}th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}td{padding:12px 16px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:top}tbody tr:hover{background:#f8fafc}
.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.badge.submitted{background:#d1fae5;color:#059669}.badge.processing{background:#dbeafe;color:#3b82f6}.badge.valid{background:#d1fae5;color:#059669}.badge.invalid{background:#ffe4e6;color:#e11d48}.badge.error{background:#ffe4e6;color:#e11d48}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none;border:none;cursor:pointer}.btn.primary{background:var(--grad);color:#fff}
@media(max-width:900px){.summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.main{padding:20px 12px}.summary-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="main">
  <h1>Submission Summary 📊</h1>
  <p class="sub">Overview of your latest consolidated e-invoice processing run.</p>
  <br>

  <div class="summary-grid">
    <div class="summary-card"><b style="color:#3b82f6"><?= $summary['submitted'] ?></b><p>Total Submitted</p></div>
    <div class="summary-card"><b style="color:#f59e0b"><?= $summary['in_progress'] ?></b><p>In Progress</p></div>
    <div class="summary-card"><b style="color:#059669"><?= $summary['valid'] ?></b><p>Total Valid</p></div>
    <div class="summary-card"><b style="color:#e11d48"><?= $summary['invalid'] ?></b><p>Total Invalid</p></div>
    <div class="summary-card"><b style="color:#64748b"><?= $summary['error'] ?></b><p>Total Error</p></div>
  </div>

  <div class="card">
    <h2>Recent Consolidated Submissions</h2>
    <table>
      <thead><tr><th>Sale Date</th><th>Records</th><th>Grand Total</th><th>LHDN Status</th><th>Submission ID</th><th>Timestamp</th></tr></thead>
      <tbody>
        <?php foreach ($submissions as $sub): ?>
          <tr>
            <td><b><?= htmlspecialchars($sub['sale_date']) ?></b></td>
            <td><?= $sub['total_records'] ?></td>
            <td>RM <?= number_format($sub['grand_total'], 2) ?></td>
            <td><span class="badge <?= strtolower($sub['lhdn_status'] ?: 'processing') ?>"><?= htmlspecialchars($sub['lhdn_status'] ?: 'Processing') ?></span></td>
            <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($sub['ei_submission_id'] ?? '—') ?></td>
            <td><?= date('M d, H:i', strtotime($sub['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($submissions)): ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--faint)">No submissions found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <a href="e-invoice_upload.php" class="btn primary">← Back to Upload</a>
</div>
</body>
</html>
