<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo0); // Note: adjusted to match your auth.php variable if needed, or keep $pdo
$uid = currentUserId();
$me  = currentUser();

// Subscription plans configuration
$plans = [
    'starter' => ['name' => 'Starter', 'price' => 50],
    'growth'  => ['name' => 'Growth', 'price' => 100],
    'scale'   => ['name' => 'Scale', 'price' => 200]
];

// Get selected plan from session or GET
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
        
        $paymentMethod  = $_POST['payment_method'] ?? 'manual_transfer';
        $paymentGateway = $paymentMethod === 'duitnow' ? 'duitnow_qr' : ($paymentMethod === 'tng' ? 'tng_ewallet' : 'manual_transfer');
        $transactionId  = $_POST['transaction_id'] ?? uniqid('TXN_');
        $bankName       = trim($_POST['bank_name'] ?? '');
        $refNo          = trim($_POST['ref_no'] ?? '');
        
        // Handle Payment Proof Upload
        $paymentProofPath = null;
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../storage/payment_proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (in_array($ext, $allowed) && $_FILES['payment_proof']['size'] <= 5 * 1024 * 1024) {
                $filename = uniqid('proof_') . '.' . $ext;
                $path = 'storage/payment_proofs/' . $filename;
                if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $uploadDir . $filename)) {
                    $paymentProofPath = $path;
                }
            }
        }

        // Validate manual transfer requirements
        if ($paymentMethod === 'manual_transfer' && empty($paymentProofPath)) {
            throw new Exception("Payment proof upload is required for manual bank transfers.");
        }
        if ($paymentMethod === 'manual_transfer' && empty($bankName)) {
            throw new Exception("Please select your bank name.");
 a       }
        
        // Generate receipt and invoice numbers
        $receiptNo = 'RCP-' . date('Ymd') . '-' . strtoupper(substr($transactionId, -8));
        $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(substr($transactionId, -8));
        
        // Calculate billing period (90 days / 3 months)
        $now = new DateTime();
        $periodEnd = clone $now;
        $periodEnd->modify('+90 days');
        
        // 1. Create/Update subscription record
        // FIXED: Latest schema ONLY has: id, user_id, plan, status, price, trial_ends_at, period_ends_at, created_at
        $checkSub = $pdo->prepare("SELECT id FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $checkSub->execute([$uid]);
        $existingSub = $checkSub->fetch();
        
        if ($existingSub) {
            $stmt = $pdo->prepare("
                UPDATE subscriptions SET 
                    plan = ?, 
                    status = 'pending_verification', 
                    price = ?, 
                    period_ends_at = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $selectedPlan['name'], 
                $amount, 
                $periodEnd->format('Y-m-d H:i:s'),
                $existingSub['id']
            ]);
            $subscriptionId = $existingSub['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan, status, price, period_ends_at, created_at)
                VALUES (?, ?, 'pending_verification', ?, ?, NOW())
                RETURNING id
            ");
            $stmt->execute([
                $uid, 
                $selectedPlan['name'], 
                $amount, 
                $periodEnd->format('Y-m-d H:i:s')
            ]);
            $subscriptionId = $stmt->fetchColumn();
        }
        
        // 2. Create billing record with payment details in metadata
        $metadata = json_encode([
            'bank_name' => $bankName,
            'ref_no' => $refNo,
            'payment_proof_path' => $paymentProofPath
        ]);
        
        $notes = "Payment via " . ucfirst(str_replace('_', ' ', $paymentMethod));
        if ($paymentMethod === 'manual_transfer') {
            $notes .= " (Bank: $bankName, Ref: $refNo)";
        }
        
        $billingStmt = $pdo->prepare("
            INSERT INTO subscriptions_billing (
                subscription_id, user_id, billing_type, plan, amount, currency,
                payment_status, payment_method, payment_gateway, transaction_id,
                receipt_no, invoice_no, billing_period_start, billing_period_end,
                payment_date, due_date, notes, metadata, created_at, updated_at
            ) VALUES (
                ?, ?, 'subscription', ?, ?, ?,
                'pending_verification', ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, NOW(), NOW()
            )
        ");
        
        $billingStmt->execute([
            $subscriptionId, $uid, $selectedPlan['name'], $amount, 'MYR',
            $paymentMethod, $paymentGateway, $transactionId,
            $receiptNo, $invoiceNo, $now->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s'),
            $notes, $metadata
        ]);
        
        $pdo->commit();
        
        // Clear session
        unset($_SESSION['selected_plan']);
        unset($_SESSION['plan_price']);
        
        // Redirect to success page
        header("Location: s_subscribepayment_success.php?receipt=" . urlencode($receiptNo));
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Subscription payment error: " . $e->getMessage());
        header("Location: s_subscribe.php?err=payment_failed&msg=" . urlencode($e->getMessage()));
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
.back-link{color:var(--muted);font-size:14px;font-weight:600;transition:.15s}
.back-link:hover{color:var(--brand)}

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
.form-group input,.form-group select{width:100%;border:1px solid var(--line);border-radius:10px;padding:12px 16px;font-size:14px;outline:none;background:#fff}
.form-group input:focus,.form-group select:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(84,87,229,.1)}

.payment-methods{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.payment-method{border:2px solid var(--line);border-radius:12px;padding:16px;text-align:center;cursor:pointer;transition:.15s;background:#fff}
.payment-method:hover{border-color:var(--brand);background:#f8fafc}
.payment-method input{display:none}
.payment-method.active{border-color:var(--brand);background:#eef2ff}
.payment-method .icon{font-size:24px;margin-bottom:8px}
.payment-method .name{font-size:13px;font-weight:700;color:var(--ink)}

/* QR & Bank Info Styles */
.qr-box{text-align:center;padding:24px;background:#f8fafc;border-radius:12px;border:2px dashed var(--line);margin-bottom:20px}
.qr-box img{max-width:200px;border-radius:8px;margin-bottom:16px;background:#fff;padding:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05)}
.qr-instruction{font-size:14px;color:var(--muted);margin-bottom:16px;line-height:1.5}
.bank-info{background:#fff;padding:16px;border-radius:8px;border:1px solid var(--line);text-align:left;font-size:14px}
.bank-info p{margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.bank-info p:last-child{margin-bottom:0}
.bank-info strong{color:var(--ink)}

.payment-details{animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}

.btn{display:inline-block;background:var(--grad);color:#fff;padding:14px 32px;border-radius:12px;font-size:14px;font-weight:700;text-align:center;transition:.15s;border:none;cursor:pointer}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn-block{display:block;width:100%}

.secure-notice{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:20px;font-size:12px;color:var(--muted)}
.secure-notice svg{width:16px;height:16px}

@media(max-width:760px){
  .payment-methods{grid-template-columns:1fr}
  .main{padding:24px 16px}
  .bank-info p{flex-direction:column;align-items:flex-start;gap:4px}
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
      </div “>
    </div>
    
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="process_payment">
      <input type="hidden" name="transaction_id" value="<?= uniqid('TXN_') ?>">
      
      <h3 style="margin:24px 0 16px">Select Payment Method</h3>
      
      <div class="payment-methods">
        <label class="payment-method active" data-method="manual_transfer">
          <input type="radio" name="payment_method" value="manual_transfer" checked>
          <div class="icon">🏦</div>
          <div class="name">Bank Transfer</div>
        </label>
        <label class="payment-method" data-method="duitnow">
          <input type="radio" name="payment_method" value="duitnow">
          <div class="icon">📱</div>
          <div class="name">DuitNow QR</div>
        </label>
        <label class="payment-method" data-method="tng">
          <input type="radio" name="payment_method" value="tng">
          <div class="icon">💳</div>
          <div class="name">Touch 'n Go</div>
        </label>
      </div>
      
      <!-- Manual Transfer Details -->
      <div id="manual-details" class="payment-details">
        <div class="form-group">
          <label>Your Bank Name</label>
          <select name="bank_name" id="bank_name">
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
          <input type="text" name="ref_no" id="ref_no" placeholder="e.g., TRX123456789">
        </div>
        <div class="form-group">
          <label>Upload Payment Proof (Slip) <span style="color:#e11d48">*</span></label>
          <input type="file" name="payment_proof" id="payment-proof" accept="image/*,.pdf" required>
          <small style="color:var(--faint);font-size:11px;margin-top:4px;display:block">Supported: JPG, PNG, PDF (Max 5MB)</small>
        </div>
      </div>

      <!-- DuitNow QR Details -->
      <div id="duitnow-details" class="payment-details" style="display:none;">
        <div class="qr-box">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=DuitNow%20Maybank%20107424075785%20RM<?= number_format($amount, 2, '.', '') ?>" alt="DuitNow QR Code">
          <p class="qr-instruction">Scan this QR code using your bank app to pay <strong>RM<?= number_format($amount, 2) ?></strong></p>
          <div class="bank-info">
            <p><span>Bank:</span> <strong>Maybank</strong></p>
            <p><span>Account No:</span> <strong>1074 2407 5785</strong></p>
            <p><span>Amount:</span> <strong style="color:var(--brand)">RM<?= number_format($amount, 2) ?></strong></p>
          </div>
          <p style="margin-top:16px;font-size:12px;color:var(--faint)">* Your subscription will be marked as "Pending Verification" until our team confirms the payment.</p>
       20px>
      </div>

      <!-- Touch 'n Go Details -->
      <div id="tng-details" class="payment-details" style="display:none;">
        <div class="qr-box">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=TNG%20eWallet%20Payment%20RM<?= number_format($amount, 2, '.', '') ?>" alt="TNG eWallet QR Code">
          <p class="qr-instruction">Scan this QR code using your <strong>Touch 'n Go eWallet</strong> app to pay <strong>RM<?= number_format($amount, 2) ?></strong></p>
          <div class="bank-info">
            <p><span>Payment Method:</span> <strong>Touch 'n Go eWallet</strong></p>
            <p><span>Amount:</span> <strong style="color:var(--brand)">RM<?= number_format($amount, 2) ?></strong></p>
          </div>
          <p style="margin-top:16px;font-size:12px;color:var(--faint)">* Your subscription will be marked as "Pending Verification" until our team confirms the payment.</p>
        </div>
      </div>
      
      <button type="submit" class="btn btn-block" style="margin-top:24px">
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
// Toggle payment method details
document.querySelectorAll('.payment-method input').forEach(radio => {
  radio.addEventListener('change', function() {
    // Update active state
    document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
    this.closest('.payment-method').classList.add('active');
    
    const method = this.value;
    
    // Hide all details
    document.querySelectorAll('.payment-details').forEach(d => d.style.display = 'none');
    
    // Show relevant details and toggle required attributes
    if (method === 'manual_transfer') {
      document.getElementById('manual-details').style.display = 'block';
      document.getElementById('payment-proof').required = true;
      document.getElementById('bank_name').required = true;
    } else if (method === 'duitnow') {
      document.getElementById('duitnow-details').style.display = 'block';
      document.getElementById('payment-proof').required = false;
      document.getElementById('bank_name').required = false;
    } else if (method === 'tng') {
      document.getElementById('tng-details').style.display = 'block';
      document.getElementById('payment-proof').required = false;
      document.getElementById('bank_name').required = false;
    }
  });
});
</script>

</body>
</html>
