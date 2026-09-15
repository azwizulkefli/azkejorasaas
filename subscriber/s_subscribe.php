<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// Subscription plans configuration
$plans = [
    'starter' => [
        'name' => 'Starter',
        'price' => 50,
        'description' => 'Perfect for sole proprietors & micro businesses',
        'features' => [
            'Up to 200 e-invoices / month',
            'Manual entry + CSV upload',
            'Live status tracking',
            'LHDN invoice downloads',
            'Email support'
        ],
        'popular' => false
    ],
    'growth' => [
        'name' => 'Growth',
        'price' => 100,
        'description' => 'Ideal for growing SMEs with regular invoicing',
        'features' => [
            'Up to 1,000 e-invoices / month',
            'Bulk batch uploads',
            'Credit note automation',
            'Excel & PDF report exports',
            'Priority support'
        ],
        'popular' => true
    ],
    'scale' => [
        'name' => 'Scale',
        'price' => 200,
        'description' => 'For multi-outlet retailers & large operations',
        'features' => [
            'Unlimited e-invoices',
            'API + webhook integration',
            'Custom user roles',
            'Dedicated account manager',
            'Advanced audit log'
        ],
        'popular' => false
    ]
];

// Handle plan selection and payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'select_plan') {
        $planKey = $_POST['plan'] ?? '';
        if (!isset($plans[$planKey])) {
            header("Location: s_subscribe.php?err=invalid_plan");
            exit;
        }
        
        $selectedPlan = $plans[$planKey];
        $_SESSION['selected_plan'] = $planKey;
        $_SESSION['plan_price'] = $selectedPlan['price'];
        
        // Redirect to payment page
        header("Location: s_payment.php?plan=" . urlencode($planKey));
        exit;
    }
}

// Get current subscription status
$sub = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$sub->execute([$uid]);
$currentSub = $sub->fetch();

$fmtDate = fn($v) => $v ? (new DateTime($v))->format('M d, Y') : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Choose Your Plan — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}

/* Loading Overlay */
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Header */
.header{background:#fff;border-bottom:1px solid var(--line);padding:16px 24px;position:sticky;top:0;z-index:10}
.header-content{max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;align-items:center}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo-icon{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.back-link{color:var(--muted);font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px}
.back-link:hover{color:var(--brand)}

/* Main */
.main{max-width:1200px;margin:0 auto;padding:48px 24px}
.page-header{text-align:center;margin-bottom:48px}
.page-header h1{font-size:36px;font-weight:800;letter-spacing:-.02em;margin-bottom:12px}
.page-header p{font-size:16px;color:var(--muted);max-width:600px;margin:0 auto}
.badge{display:inline-block;background:var(--grad);color:#fff;padding:6px 16px;border-radius:999px;font-size:12px;font-weight:700;margin-bottom:16px}

/* Pricing Cards */
.pricing-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-bottom:48px}
.pricing-card{background:#fff;border:2px solid var(--line);border-radius:20px;padding:32px;position:relative;transition:.2s;box-shadow:var(--card)}
.pricing-card:hover{transform:translateY(-4px);box-shadow:0 20px 40px -20px rgba(19,19,39,.15)}
.pricing-card.popular{border-color:var(--brand);box-shadow:0 0 0 2px var(--brand),0 20px 40px -20px rgba(84,87,229,.3)}
.popular-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--grad);color:#fff;padding:6px 20px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}

.plan-name{font-size:20px;font-weight:800;margin-bottom:8px}
.plan-desc{font-size:13px;color:var(--muted);margin-bottom:24px;line-height:1.5}
.plan-price{display:flex;align-items:baseline;gap:4px;margin-bottom:24px}
.plan-price .currency{font-size:24px;font-weight:700;color:var(--brand)}
.plan-price .amount{font-size:48px;font-weight:800;color:var(--brand);line-height:1}
.plan-price .period{font-size:14px;color:var(--muted);font-weight:600}

.features{list-style:none;margin-bottom:32px}
.features li{display:flex;align-items:flex-start;gap:10px;padding:10px 0;font-size:13px;color:var(--muted);border-bottom:1px solid #f1f5f9}
.features li:last-child{border-bottom:none}
.features li::before{content:"✓";color:#10b981;font-weight:800;flex-shrink:0}

.select-btn{width:100%;background:var(--grad);color:#fff;padding:14px 24px;border-radius:12px;font-size:14px;font-weight:700;transition:.15s}
.select-btn:hover{opacity:.9;transform:translateY(-1px)}
.select-btn:disabled{background:#e2e8f0;color:#94a3b8;cursor:not-allowed;transform:none}

/* Current Plan Notice */
.current-plan{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:16px;padding:24px;margin-bottom:32px;display:flex;align-items:center;gap:16px}
.current-plan-icon{width:48px;height:48px;border-radius:12px;background:#10b981;color:#fff;display:grid;place-items:center;font-size:20px}
.current-plan-content h3{font-size:16px;font-weight:700;margin-bottom:4px}
.current-plan-content p{font-size:13px;color:var(--muted)}
.current-plan .btn{margin-left:auto;background:#10b981;color:#fff;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:700}

/* Error Banner */
.banner{border-radius:12px;padding:16px 20px;margin-bottom:24px;font-size:14px;font-weight:600}
.banner.error{background:#ffe4e6;color:#e11d48;border:1px solid #fecdd3}

/* Info Cards */
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:48px}
.info-card{text-align:center;padding:24px}
.info-card h3{font-size:14px;font-weight:700;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em}
.info-card p{font-size:18px;font-weight:800;color:var(--ink)}

/* Footer */
.footer{text-align:center;padding:32px 24px;color:var(--faint);font-size:12px;border-top:1px solid var(--line);margin-top:48px}

/* Responsive */
@media(max-width:900px){
  .pricing-grid{grid-template-columns:1fr;max-width:480px;margin:0 auto 48px}
  .info-grid{grid-template-columns:1fr}
  .page-header h1{font-size:28px}
}
</style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner"></div>
</div>

<header class="header">
  <div class="header-content">
    <a href="main.php" class="back-link">← Back to Dashboard</a>
    <div class="logo">
      <span class="logo-icon">⚡</span>
      AZ Kejora SaaS
    </div>
    <div style="width:100px"></div>
  </div>
</header>

<main class="main">
  <div class="page-header">
    <span class="badge">SIMPLE PRICING</span>
    <h1>Choose the perfect plan for your business</h1>
    <p>No long-term contracts · No hidden fees · Cancel anytime · Prices in MYR (RM)</p>
  </div>

  <?php if (isset($_GET['err'])): ?>
    <div class="banner error">
      <?php
      $errors = [
        'invalid_plan' => 'Invalid plan selected. Please try again.',
        'payment_failed' => 'Payment failed. Please try a different method or contact support.',
        'already_subscribed' => 'You already have an active subscription.'
      ];
      echo htmlspecialchars($errors[$_GET['err']] ?? 'An error occurred. Please try again.');
      ?>
    </div>
  <?php endif; ?>

  <?php if ($currentSub && in_array($currentSub['status'], ['active', 'active_trial'])): ?>
    <div class="current-plan">
      <div class="current-plan-icon">✓</div>
      <div class="current-plan-content">
        <h3>You're currently on the <?= htmlspecialchars($currentSub['plan']) ?> plan</h3>
        <p>Renews on <?= $fmtDate($currentSub['period_ends_at']) ?> · RM<?= number_format($currentSub['price'], 2) ?>/90 days</p>
      </div>
      <a href="main.php" class="btn">Go to Dashboard</a>
    </div>
  <?php endif; ?>

  <div class="pricing-grid">
    <?php foreach ($plans as $key => $plan): ?>
      <div class="pricing-card <?= $plan['popular'] ? 'popular' : '' ?>">
        <?php if ($plan['popular']): ?>
          <span class="popular-badge">Most Popular</span>
        <?php endif; ?>
        
        <h3 class="plan-name"><?= htmlspecialchars($plan['name']) ?></h3>
        <p class="plan-desc"><?= htmlspecialchars($plan['description']) ?></p>
        
        <div class="plan-price">
          <span class="currency">RM</span>
          <span class="amount"><?= $plan['price'] ?></span>
          <span class="period">/month</span>
        </div>
        
        <ul class="features">
          <?php foreach ($plan['features'] as $feature): ?>
            <li><?= htmlspecialchars($feature) ?></li>
          <?php endforeach; ?>
        </ul>
        
        <form method="POST" style="margin:0">
          <input type="hidden" name="action" value="select_plan">
          <input type="hidden" name="plan" value="<?= htmlspecialchars($key) ?>">
          <button type="submit" class="select-btn" <?= ($currentSub && in_array($currentSub['status'], ['active', 'active_trial'])) ? 'disabled' : '' ?>>
            <?= $currentSub && in_array($currentSub['status'], ['active', 'active_trial']) ? 'Current Plan' : 'Get Started' ?>
          </button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <h3>Secure Payment</h3>
      <p>256-bit SSL encryption</p>
    </div>
    <div class="info-card">
      <h3>Instant Activation</h3>
      <p>Start immediately</p>
    </div>
    <div class="info-card">
      <h3>24/7 Support</h3>
      <p>Always here to help</p>
    </div>
  </div>
</main>

<footer class="footer">
  © 2026 AZ Kejora SaaS · All rights reserved
</footer>

<script>
const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function() {
    overlay.classList.add('active');
  });
});
</script>

</body>
</html>
