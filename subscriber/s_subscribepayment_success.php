<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
$uid = currentUserId();
$me  = currentUser();

$receiptNo = $_GET['receipt'] ?? '';
if (!$receiptNo) {
    header("Location: main.php");
    exit;
}

// Fetch payment details
$stmt = $pdo->prepare("
    SELECT sb.*, s.plan, s.period_ends_at
    FROM subscriptions_billing sb
    JOIN subscriptions s ON sb.subscription_id = s.id
    WHERE sb.receipt_no = ? AND sb.user_id = ?
");
$stmt->execute([$receiptNo, $uid]);
$payment = $stmt->fetch();

if (!$payment) {
    header("Location: main.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Successful — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet))}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}

.success-container{min-height:100vh;display:grid;place-items:center;padding:24px}
.success-card{background:#fff;border-radius:24px;padding:48px;max-width:560px;width:100%;text-align:center;box-shadow:0 20px 60px -20px rgba(19,19,39,.2)}
.success-icon{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#10b981,#34d399);color:#fff;display:grid;place-items:center;font-size:40px;margin:0 auto 24px}
.success-card h1{font-size:28px;font-weight:800;margin-bottom:12px}
.success-card .subtitle{font-size:15px;color:var(--muted);margin-bottom:32px}

.receipt-box{background:#f8fafc;border:1px solid var(--line);border-radius:16px;padding:24px;margin:24px 0;text-align:left}
.receipt-row{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)}
.receipt-row:last-child{border-bottom:none}
.receipt-row .label{color:var(--muted);font-size:13px}
.receipt-row .value{font-weight:700;font-size:14px}

.btn{display:inline-block;background:var(--grad);color:#fff;padding:14px 32px;border-radius:12px;font-size:14px;font-weight:700;margin:8px;transition:.15s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn.secondary{background:#f1f5f9;color:#475569}
</style>
</head>
<body>

<div class="success-container">
  <div class="success-card">
    <div class="success-icon">✓</div>
    <h1>Payment Successful!</h1>
    <p class="subtitle">Your subscription has been activated successfully.</p>
    
    <div class="receipt-box">
      <div class="receipt-row">
        <span class="label">Receipt Number</span>
        <span class="value"><?= htmlspecialchars($payment['receipt_no']) ?></span>
      </div>
      <div class="receipt-row">
        <span class="label">Invoice Number</span>
        <span class="value"><?= htmlspecialchars($payment['invoice_no']) ?></span>
      </div>
      <div class="receipt-row">
        <span class="label">Plan</span>
        <span class="value"><?= htmlspecialchars($payment['plan']) ?></span>
      </div>
      <div class="receipt-row">
        <span class="label">Amount Paid</span>
        <span class="value" style="color:var(--brand);font-size:18px">RM<?= number_format($payment['amount'], 2) ?></span>
      </div>
      <div class="receipt-row">
        <span class="label">Payment Date</span>
        <span class="value"><?= date('M d, Y H:i', strtotime($payment['payment_date'])) ?></span>
      </div>
      <div class="receipt-row">
        <span class="label">Subscription Valid Until</span>
        <span class="value"><?= date('M d, Y', strtotime($payment['period_ends_at'])) ?></span>
      </div>
    </div>
    
    <div>
      <a href="main.php" class="btn">Go to Dashboard</a>
      <a href="s_report.php" class="btn secondary">View Billing Report</a>
    </div>
  </div>
</div>

</body>
</html>
