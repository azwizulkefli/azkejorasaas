<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// Subscription plans configuration
$plans = [
    'starter' => ['name' => 'Starter', 'price' => 50],
    'growth' => ['name' => 'Growth', 'price' => 100],
    'scale' => ['name' => 'Scale', 'price' => 200]
];

// Get selected plan from session
$planKey = $_GET['plan'] ?? $_SESSION['selected_plan'] ?? '';
if (!isset($plans[$planKey])) {
    header("Location: s_subscribe.php?err=invalid_plan");
    exit;
}

$selectedPlan = $plans[$planKey];
$amount = $selectedPlan['price'];

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_payment') {
    try {
        $pdo->beginTransaction();
        
        $paymentMethod = $_POST['payment_method'] ?? 'manual';
        $paymentGateway = $_POST['payment_gateway'] ?? 'manual_transfer';
        $transactionId = $_POST['transaction_id'] ?? uniqid('TXN_');
        $bankName = $_POST['bank_name'] ?? '';
        $refNo = $_POST['ref_no'] ?? '';
        
        // Generate receipt and invoice numbers
        $receiptNo = 'RCP-' . date('Ymd') . '-' . strtoupper(substr($transactionId, -8));
        $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(substr($transactionId, -8));
        
        // Calculate billing period (90 days / 3 months)
        $now = new DateTime();
        $periodEnd = clone $now;
        $periodEnd->modify('+90 days');
        
        // 1. Create/Update subscription record
        $subData = [
            'user_id' => $uid,
            'plan' => $selectedPlan['name'],
            'status' => 'active',
            'price' => $amount,
            'period_ends_at' => $periodEnd->format('Y-m-d H:i:s'),
            'receipt_no' => $receiptNo,
            'payment_date' => $now->format('Y-m-d H:i:s'),
            'payment_type' => $paymentMethod,
            'ref_no' => $refNo,
            'bank' => $bankName,
            'amount' => $amount
        ];
        
        // Check if user has existing subscription
        $checkSub = $pdo->prepare("SELECT id FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $checkSub->execute([$uid]);
        $existingSub = $checkSub->fetch();
        
        if ($existingSub) {
            // Update existing subscription
            $stmt = $pdo->prepare("
                UPDATE subscriptions SET 
                    plan = ?, status = ?, price = ?, period_ends_at = ?,
                    receipt_no = ?, payment_date = ?, payment_type = ?,
                    ref_no = ?, bank = ?, amount = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $subData['plan'], $subData['status'], $subData['price'], $subData['period_ends_at'],
                $subData['receipt_no'], $subData['payment_date'], $subData['payment_type'],
                $subData['ref_no'], $subData['bank'], $subData['amount'],
                $existingSub['id']
            ]);
            $subscriptionId = $existingSub['id'];
        } else {
            // Create new subscription
            $stmt = $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan, status, price, period_ends_at, 
                    receipt_no, payment_date, payment_type, ref_no, bank, amount, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                RETURNING id
            ");
            $stmt->execute([
                $subData['user_id'], $subData['plan'], $subData['status'], $subData['price'], 
                $subData['period_ends_at'], $subData['receipt_no'], $subData['payment_date'], 
                $subData['payment_type'], $subData['ref_no'], $subData['bank'], $subData['amount']
            ]);
            $subscriptionId = $stmt->fetchColumn();
        }
        
        // 2. Create billing record
        $billingStmt = $pdo->prepare("
            INSERT INTO subscriptions_billing (
                subscription_id, user_id, billing_type, plan, amount, currency,
                payment_status, payment_method, payment_gateway, transaction_id,
                receipt_no, invoice_no, billing_period_start, billing_period_end,
                payment_date, paid_date, created_at, updated_at
            ) VALUES (
                ?, ?, 'subscription', ?, ?, ?,
                'paid', ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, NOW(), NOW()
            )
        ");
        
        $billingStmt->execute([
            $subscriptionId, $uid, $selectedPlan['name'], $amount, 'MYR',
            $paymentMethod, $paymentGateway, $transactionId,
            $receiptNo, $invoiceNo, $now->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')
        ]);
        
        $pdo->commit();
        
        // Clear session
        unset($_SESSION['selected_plan']);
        unset($_SESSION['plan_price']);
        
        // Redirect to success page
        header("Location: s_payment_success.php?receipt=" . urlencode($receiptNo));
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Subscription payment error: " . $e->getMessage());
        header("Location: s_subscribe.php?err=payment_failed");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}

.header{background:#fff;border-bottom:1px solid var(--line);padding:16px 24px}
.header-content{max-width:800px;margin:0 auto;display:flex;justify-content:space-between;align-items:center}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo-icon{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.back-link{color:var(--muted);font-size:14px;font-weight:600}

.main{max-width:800px;margin:0 auto;padding:48px 24px}
.page-title{font-size:28px;font-weight:800;margin-bottom:32px;text-align:center}

.payment-card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:32px;box-shadow:var(--card);margin-bottom:24px}
.payment-card h3{font-size:18px;font-weight:700;margin-bottom:20px}

.order-summary{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:24px}
.summary-row{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)}
.summary-row:last-child{border-bottom:none;font-weight:800;font-size:18px}
.summary-row .label{color:var(--muted)}
.summary-row .value{font-weight:700}

.form-group{margin-bottom:20px}
.form-group label{display:block;margin-bottom:8px;font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.form-group input,.form-group select{width:100%;border:1px solid var(--line);border-radius:10px;padding:12px 16px;font-size:14px;outline:none}
.form-group input:focus,.form-group select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(84,87,229,.1)}

.payment-methods{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.payment-method{border:2px solid var(--line);border-radius:12px;padding:16px;text-align:center;cursor:pointer;transition:.15s}
.payment-method:hover{border-color:var(--brand)}
.payment-method input{display:none}
.payment-method.active{border-color:var(--brand);background:#eef2ff}
.payment-method .icon{font-size:24px;margin-bottom:8px}
.payment-method .name{font-size:13px;font-weight:700}

.btn{display:inline-block;background:var(--grad);color:#fff;padding:14px 32px;border-radius:12px;font-size:14px;font-weight:700;text-align:center;transition:.15s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn-block{display:block;width:100%}

.secure-notice{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:20px;font-size:12px;color:var(--muted)}
.secure-notice svg{width:16px;height:16px}

@media(max-width:760px){
  .payment-methods{grid-template-columns:1fr}
  .main{padding:24px 16px}
}
</style>
</head>
<body>

<header class="header">
  <div class="header-content">
    <a href="s_subscribe.php" class="back-link">← Back to Plans</a>
    <div class="logo">
      <span class="logo-icon">⚡</span>
      AZ Kejora SaaS
    </div>
    <div style="width:100px"></div>
  </div>
</header>

<main class="main">
  <h1 class="page-title">Complete Your Payment</h1>
  
  <div class="payment-card">
    <h3>Order Summary</h3>
    <div class="order-summary">
      <div class="summary-row">
        <span class="label">Plan</span>
        <span class="value"><?= htmlspecialchars($selectedPlan['name']) ?></span>
      </div>
      <div class="summary-row">
        <span class="label">Billing Period</span>
        <span class="value">3 months (90 days)</span>
      </div>
      <div class="summary-row">
        <span class="label">Subtotal</span>
        <span class="value">RM<?= number_format($amount, 2) ?></span>
      </div>
      <div class="summary-row">
        <span class="label">Tax (0%)</span>
        <span class="value">RM0.00</span>
      </div>
      <div class="summary-row">
        <span class="label">Total</span>
        <span class="value" style="color:var(--brand);font-size:24px">RM<?= number_format($amount, 2) ?></span>
      </div>
    </div>
    
    <form method="POST">
      <input type="hidden" name="action" value="process_payment">
      <input type="hidden" name="transaction_id" value="<?= uniqid('TXN_') ?>">
      
      <h3 style="margin:24px 0 16px">Payment Method</h3>
      
      <div class="payment-methods">
        <label class="payment-method active">
          <input type="radio" name="payment_method" value="manual_transfer" checked>
          <div class="icon"></div>
          <div class="name">Bank Transfer</div>
        </label>
        <label class="payment-method">
          <input type="radio" name="payment_method" value="credit_card">
          <div class="icon">💳</div>
          <div class="name">Credit Card</div>
        </label>
        <label class="payment-method">
          <input type="radio" name="payment_method" value="ewallet">
          <div class="icon">📱</div>
          <div class="name">E-Wallet</div>
        </label>
      </div>
      
      <div class="form-group">
        <label>Payment Gateway</label>
        <select name="payment_gateway" required>
          <option value="manual_transfer">Manual Bank Transfer</option>
          <option value="toyyibpay">ToyyibPay</option>
          <option value="billplz">Billplz</option>
          <option value="stripe">Stripe</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Bank Name</label>
        <select name="bank_name" required>
          <option value="">Select Bank</option>
          <option value="Maybank">Maybank</option>
          <option value="CIMB">CIMB Bank</option>
          <option value="Public Bank">Public Bank</option>
          <option value="RHB Bank">RHB Bank</option>
          <option value="Hong Leong Bank">Hong Leong Bank</option>
          <option value="Ambank">Ambank</option>
          <option value="Other">Other</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Transaction Reference Number</label>
        <input type="text" name="ref_no" placeholder="Enter your transaction reference number" required>
        <small style="color:var(--faint);font-size:11px;margin-top:4px;display:block">Example: TRX123456789 or receipt number</small>
      </div>
      
      <button type="submit" class="btn btn-block">
        Confirm Payment - RM<?= number_format($amount, 2) ?>
      </button>
      
      <div class="secure-notice">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        <span>Secure payment processing · 256-bit SSL encryption</span>
      </div>
    </form>
  </div>
</main>

<script>
document.querySelectorAll('.payment-method input').forEach(radio => {
  radio.addEventListener('change', function() {
    document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
    this.closest('.payment-method').classList.add('active');
  });
});
</script>

</body>
</html>
