<?php
require_once __DIR__ . '/../includes/auth.php';   // starts session + loads config & settings
// Google sign-in entry point: ?google=1 → start OAuth
if (isset($_GET['google'])) {
    require_once __DIR__ . '/../includes/google.php';
    header('Location: ' . google_auth_url()); exit;
}

ensure_settings_table($pdo);
$trialHours = max(1, (int)get_setting($pdo, 'general', 'trial_default_hours', 1));
$isLoggedIn = isset($_SESSION['user_id']);
$userName   = $isLoggedIn ? $_SESSION['user_name'] : '';
$userRole   = $isLoggedIn ? $_SESSION['user_role'] : '';
$loginError = isset($_GET['err']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AZ Kejora SaaS — LHDN e-Invoice Compliance, Automated</title>
<style>
/* ================= TOKENS / BASE ================= */
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--brand-dark:#4644cf;--violet:#8b5cf6;--fuchsia:#d946ef;--text:#334155;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--emerald:#10b981;--rose:#e11d48;--grad:linear-gradient(90deg,var(--brand),var(--violet));--shadow:0 8px 30px -12px rgba(19,19,39,.15);--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}button{font:inherit;cursor:pointer;border:none;background:none}
.wrap{max-width:1200px;margin:0 auto;padding:0 20px}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes pop{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
@keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
/* ================= NAV ================= */
.nav{position:fixed;inset:0 0 auto 0;z-index:40}
.nav-inner{margin-top:16px;display:flex;align-items:center;justify-content:space-between;gap:16px;background:rgba(255,255,255,.8);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.6);border-radius:16px;padding:12px 20px;box-shadow:var(--shadow)}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;letter-spacing:-.02em}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center;box-shadow:0 8px 20px -6px rgba(84,87,229,.5)}
.brand em{font-style:normal;background:linear-gradient(90deg,var(--brand),var(--violet),var(--fuchsia));-webkit-background-clip:text;background-clip:text;color:transparent}
.links{display:flex;gap:28px;font-size:14px;font-weight:600;color:var(--muted)}.links a:hover{color:var(--brand-dark)}
.nav-cta{display:flex;align-items:center;gap:8px}.hi{font-size:13px;font-weight:600;color:var(--muted);margin-right:6px}
@media(max-width:860px){.links{display:none}}
/* ================= BUTTONS ================= */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:10px 18px;font-size:14px;font-weight:600;transition:.2s;white-space:nowrap}
.btn.primary{background:var(--grad);color:#fff;box-shadow:0 10px 24px -8px rgba(84,87,229,.5)}.btn.primary:hover{transform:translateY(-2px)}
.btn.ghost{background:#fff;border:1px solid var(--line);color:var(--text)}.btn.ghost:hover{border-color:#a3aeff;color:var(--brand-dark)}
.btn.white{background:#fff;color:var(--brand-dark);font-weight:700}
.btn.full{width:100%;margin-top:24px;padding:14px}
/* ================= HERO ================= */
.hero{position:relative;padding:160px 0 96px;overflow:hidden;background-image:linear-gradient(rgba(99,102,241,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(99,102,241,.06) 1px,transparent 1px);background-size:44px 44px}
.glow{position:absolute;top:-128px;left:50%;transform:translateX(-50%);width:820px;height:480px;border-radius:50%;background:linear-gradient(90deg,rgba(198,206,255,.6),rgba(221,214,254,.6),rgba(245,208,255,.6));filter:blur(48px);pointer-events:none}
.hero-grid{position:relative;display:grid;grid-template-columns:1.05fr .95fr;gap:64px;align-items:center}
@media(max-width:960px){.hero-grid{grid-template-columns:1fr}}
.eyebrow{display:inline-flex;align-items:center;gap:8px;border:1px solid #c6ceff;background:#eef1ff;color:var(--brand-dark);border-radius:999px;padding:6px 14px;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
.dot{width:6px;height:6px;border-radius:50%;background:var(--emerald);animation:pulse 1.6s infinite}
h1.big{margin-top:24px;font-size:clamp(38px,5vw,58px);line-height:1.08;letter-spacing:-.02em;font-weight:800}
.grad-text{background:linear-gradient(90deg,var(--brand),var(--violet),var(--fuchsia));-webkit-background-clip:text;background-clip:text;color:transparent}
.lead{margin-top:24px;max-width:560px;font-size:18px;line-height:1.7;color:var(--muted)}
.cta-row{margin-top:32px;display:flex;flex-wrap:wrap;gap:12px}
.checks{margin-top:24px;display:flex;flex-wrap:wrap;gap:20px;font-size:14px;color:var(--muted)}
.checks span::before{content:'✓';color:var(--emerald);font-weight:800;margin-right:6px}
/* hero mock */
.mock{position:relative}
.mock-card{background:#fff;border:1px solid var(--line);border-radius:24px;box-shadow:var(--card);padding:20px;animation:fadeUp .6s ease both}
.mock-top span{width:10px;height:10px;border-radius:50%;background:#e2e8f0;display:inline-block;margin-right:6px}
.mock-top i{font-style:normal;font-size:11px;color:var(--faint);margin-left:8px}
.mock-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;text-align:center}
.mock-kpi{background:#f8fafc;border-radius:12px;padding:14px}.mock-kpi b{font-size:18px;font-weight:800}.mock-kpi p{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);margin-top:4px}
.mock-kpi.g b{color:var(--emerald)}.mock-kpi.b b{color:var(--brand)}
.mock-bars{display:flex;align-items:flex-end;gap:8px;height:120px;margin-top:16px}
.mock-bars i{flex:1;background:linear-gradient(180deg,#8b5cf6,#5457e5);border-radius:6px 6px 0 0;opacity:.85}
.chip{position:absolute;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:10px 14px;display:flex;gap:10px;align-items:center;animation:floaty 6s ease-in-out infinite}
.chip .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:15px}
.chip b{display:block;font-size:12px}.chip small{color:var(--muted);font-size:11px}
/* stats */
.stats{margin-top:80px;display:grid;grid-template-columns:repeat(4,1fr);gap:24px;background:rgba(255,255,255,.7);border:1px solid var(--line);border-radius:16px;padding:32px;backdrop-filter:blur(8px)}
.stats b{font-size:30px;font-weight:800;display:block}.stats p{margin-top:4px;font-size:14px;color:var(--muted)}
@media(max-width:860px){.stats{grid-template-columns:repeat(2,1fr)}}
/* ================= SECTIONS ================= */
section{padding:96px 0}.sec-head{text-align:center;max-width:640px;margin:0 auto}
h2.sec{margin-top:20px;font-size:clamp(28px,3.4vw,40px);font-weight:800;letter-spacing:-.02em}
.cards4{margin-top:56px;display:grid;grid-template-columns:repeat(4,1fr);gap:24px}
@media(max-width:1020px){.cards4{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.cards4{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid rgba(226,232,240,.7);border-radius:16px;box-shadow:var(--card);padding:28px;transition:.2s}
.card:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
.ic-tile{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:20px;box-shadow:0 10px 20px -8px rgba(19,19,39,.3)}
.card h3{margin-top:20px;font-size:18px;font-weight:700}.card p{margin-top:8px;font-size:14px;line-height:1.6;color:var(--muted)}
/* services */
.svc{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
@media(max-width:960px){.svc{grid-template-columns:1fr}}
.svc + .svc{margin-top:96px}
.svc ul{margin-top:28px;list-style:none;display:grid;gap:16px;color:var(--text);font-size:15px}
.svc li{display:flex;gap:12px}
.tick{flex:0 0 24px;width:24px;height:24px;border-radius:6px;background:#d1fae5;color:var(--emerald);display:grid;place-items:center;font-size:12px;font-weight:800;margin-top:2px}
.drop{border:2px dashed #a3aeff;background:rgba(238,241,255,.5);border-radius:12px;padding:28px;text-align:center;color:var(--brand-dark);font-weight:700;font-size:14px}
.drop small{display:block;color:var(--muted);font-weight:500;margin-top:4px}
.mini-stats{margin-top:20px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;text-align:center}
.mini-stats div{background:#f8fafc;border-radius:12px;padding:16px}.mini-stats b{font-size:20px;font-weight:800}
.mini-stats p{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);margin-top:4px}
.status-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:16px}
.status-pill{border-radius:10px;padding:10px 8px;text-align:center;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.status-pill.valid{background:#d1fae5;color:#059669}
.status-pill.invalid{background:#fee2e2;color:#dc2626}
.status-pill.progress{background:#fef3c7;color:#d97706}
.status-pill.error{background:#fce7f3;color:#db2777}
.status-pill b{display:block;font-size:18px;margin-bottom:2px}
.total-row{margin-top:20px;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border-radius:12px;padding:16px 20px}
/* how it works */
.steps{margin-top:56px;display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
@media(max-width:860px){.steps{grid-template-columns:1fr}}
.step{background:#fff;border:1px solid var(--line);border-radius:20px;padding:32px;text-align:center;box-shadow:var(--card);position:relative}
.step-num{width:48px;height:48px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:20px;font-weight:800;margin:0 auto 20px;box-shadow:0 10px 24px -8px rgba(84,87,229,.5)}
.step h3{font-size:20px;font-weight:800;margin-bottom:10px}.step p{font-size:14px;line-height:1.7;color:var(--muted)}
/* pricing */
.cards3{margin-top:56px;display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
@media(max-width:960px){.cards3{grid-template-columns:1fr}}
.plan{position:relative;padding:32px}
.plan.pop{box-shadow:0 0 0 2px var(--brand),var(--card)}
.flag{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--grad);color:#fff;border-radius:999px;padding:4px 16px;font-size:11px;font-weight:800}
.price{margin-top:20px;display:flex;align-items:flex-end;gap:8px}.price b{font-size:44px;font-weight:800;letter-spacing:-.02em}.price span{padding-bottom:6px;color:var(--muted);font-size:14px}
.plan ul{margin:24px 0 28px;list-style:none;display:grid;gap:12px;font-size:14px;color:var(--text)}
.plan li::before{content:'✓';color:var(--emerald);font-weight:800;margin-right:10px}
.plan .btn{width:100%}
/* faq */
.faq-list{margin-top:48px}
details.faq{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--card);overflow:hidden;margin-top:12px}
details.faq summary{list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 24px;font-weight:600;cursor:pointer}
details.faq summary::-webkit-details-marker{display:none}
details.faq summary::after{content:'⌄';color:var(--faint);font-size:18px;transition:.2s}
details[open].faq summary::after{transform:rotate(180deg)}
details.faq p{padding:0 24px 20px;font-size:14px;line-height:1.7;color:var(--muted)}
/* cta + footer */
.cta{position:relative;overflow:hidden;border-radius:24px;background:linear-gradient(90deg,var(--brand-dark),var(--brand),#7c3aed);padding:64px 32px;text-align:center;color:#fff;box-shadow:0 24px 60px -24px rgba(84,87,229,.6)}
.cta h2{font-size:clamp(26px,3.4vw,38px);font-weight:800}.cta p{margin:16px auto 0;max-width:560px;color:#e0e5ff}.cta .btn{margin-top:32px}
footer{border-top:1px solid var(--line);background:#fff;padding:40px 0}
.foot{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between}.foot small{color:var(--faint)}
.foot nav{display:flex;gap:20px;font-size:13px;font-weight:600;color:var(--muted)}
/* ================= MODAL ================= */
.modal{position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px}
.modal.open{display:grid}
.modal-card{width:100%;max-width:420px;background:#fff;border-radius:24px;padding:32px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);animation:pop .25s ease-out}
.modal-card .logo{margin:0 auto;width:48px;height:48px;font-size:20px}
.modal-card h3{margin-top:16px;font-size:20px;font-weight:800;text-align:center}
.modal-card .sub{margin-top:4px;font-size:14px;color:var(--muted);text-align:center}
.field{margin-top:16px}
.field label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.field input{width:100%;border:1px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;outline:none;transition:.2s}
.field input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(99,102,241,.1)}
.err{margin-top:16px;background:#fff1f2;color:var(--rose);border-radius:10px;padding:12px;font-size:13px;font-weight:600;text-align:center}
.hint{margin-top:16px;font-size:12px;color:var(--faint);text-align:center}
.hint code{background:#f1f5f9;border-radius:4px;padding:2px 6px}
</style>
</head>
<body>

<!-- NAV -->
<header class="nav"><div class="wrap nav-inner">
  <a class="brand" href="#"><span class="logo">⚡</span>AZ Kejora<em>SaaS</em></a>
  <nav class="links"><a href="#features">Features</a><a href="#how-it-works">How It Works</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a></nav>
  <div class="nav-cta">
    <?php if($isLoggedIn): ?>
      <span class="hi">Hi, <?= htmlspecialchars(explode(' ',$userName)[0]) ?></span>
      <?php if($userRole==='admin'): ?><a class="btn ghost" href="admin.php">Admin Console</a><?php endif; ?>
      <a class="btn ghost" href="login.php?logout=1">Sign out</a>
      <a class="btn primary" href="<?= $userRole==='admin' ? 'admin.php' : 'subscriber/main.php' ?>">Open Dashboard</a>
    <?php else: ?>
      <button class="btn ghost" onclick="openAuth()">Sign in</button>
      <button class="btn primary" onclick="openSignup()">Start Free Trial</button>
    <?php endif; ?>
  </div>
</div></header>

<!-- HERO -->
<section class="hero"><div class="glow"></div>
  <div class="wrap hero-grid">
    <div>
      <span class="eyebrow"><span class="dot"></span>LHDN e-Invoice Ready · MyTax Compliant</span>
      <h1 class="big">Automate your LHDN e-Invoice compliance in <span class="grad-text">minutes, not days.</span></h1>
      <p class="lead">Stop juggling spreadsheets and worrying about validation errors. AZ Kejora SaaS handles every submission, tracks every status, and delivers official LHDN e-invoices — all from one elegant dashboard.</p>
      <div class="cta-row">
        <?php if($isLoggedIn): ?><a class="btn primary" href="<?= $userRole==='admin' ? 'admin.php' : 'subscriber/main.php' ?>" style="padding:14px 28px;font-size:16px">Open Dashboard →</a>
        <?php else: ?><button class="btn primary" style="padding:14px 28px;font-size:16px" onclick="openSignup()">Start Free Trial →</button><?php endif; ?>
        <a class="btn ghost" style="padding:14px 24px;font-size:16px" href="#pricing">View Monthly Plans</a>
      </div>
      <div class="checks"><span>No credit card required</span><span>LHDN validated</span><span>Cancel anytime</span></div>
    </div>
    <div class="mock">
      <div class="mock-card">
        <div class="mock-top"><span></span><span></span><span></span><i>app.azkejora.io / dashboard</i></div>
        <div class="mock-grid">
          <div class="mock-kpi"><b>1,248</b><p>Submitted</p></div>
          <div class="mock-kpi g"><b>98.4%</b><p>Validated</p></div>
          <div class="mock-kpi b"><b>RM 342k</b><p>Billed</p></div>
        </div>
        <div class="status-row">
          <div class="status-pill valid"><b>1,228</b>Valid</div>
          <div class="status-pill invalid"><b>12</b>Invalid</div>
          <div class="status-pill progress"><b>6</b>In Progress</div>
          <div class="status-pill error"><b>2</b>Error</div>
        </div>
        <div class="mock-bars"><i style="height:35%"></i><i style="height:52%"></i><i style="height:44%"></i><i style="height:66%"></i><i style="height:58%"></i><i style="height:78%"></i><i style="height:70%"></i><i style="height:92%"></i></div>
      </div>
      <div class="chip" style="left:-20px;top:28px"><span class="ic" style="background:#d1fae5;color:#059669">✓</span><div><b>Invoice validated by LHDN</b><small>TIN OK · TIN verified · Batch #A204</small></div></div>
      <div class="chip" style="right:-14px;bottom:36px;animation-delay:1.4s"><span class="ic" style="background:#e0e5ff;color:var(--brand)">📄</span><div><b>Credit note issued</b><small>Linked to INV-2026-0842 · Auto-submitted</small></div></div>
    </div>
  </div>
</section>

<!-- THE PROBLEM -->
<section style="background:#fff"><div class="wrap">
  <div class="sec-head">
    <span class="eyebrow">The Compliance Challenge</span>
    <h2 class="sec">LHDN e-invoicing shouldn't keep you up at night.</h2>
    <p style="margin-top:20px;font-size:17px;line-height:1.7;color:var(--muted)">
      Manual data entry takes hours. Validation errors slip through. Deadlines get missed. And the fear of penalties? It's always there. Malaysian businesses need a smarter way to stay compliant — one that removes the guesswork and gives you absolute confidence in every submission.
    </p>
  </div>
  <div class="cards4" style="margin-top:40px">
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#dc2626,#e11d48)">⏱</span><h3>Hours Lost to Manual Entry</h3><p>Typing each invoice by hand is slow, boring, and error-prone. Your team's time is worth more.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#f59e0b,#f97316)">⚠</span><h3>Hidden Validation Errors</h3><p>One wrong TIN or missing field can invalidate a submission — and you won't know until it's too late.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#64748b,#475569)">📊</span><h3>No Central Visibility</h3><p>Scattered spreadsheets mean no single view of your compliance status, pending submissions, or audit trail.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#8b5cf6,#6366f1)">🔐</span><h3>Penalty Risk</h3><p>Missed deadlines or repeated errors attract LHDN fines. The cost of non-compliance is real — and avoidable.</p></div>
  </div>
</div></section>

<!-- KEY FEATURES -->
<section id="features"><div class="wrap">
  <div class="sec-head">
    <span class="eyebrow">Built for Malaysian SMEs</span>
    <h2 class="sec">Everything you need for effortless LHDN compliance.</h2>
  </div>
  <div class="cards4" style="grid-template-columns:repeat(3,1fr)">
    <div class="card"><span class="ic-tile" style="background:var(--grad)">📤</span><h3>Flexible Submission</h3><p>Manual entry for one-off invoices or bulk batch uploads for hundreds at once. Work the way that suits your business best.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#10b981,#14b8a6)">📊</span><h3>Comprehensive Dashboard</h3><p>One centralized hub with real-time reports, submission trends, and actionable statistics — always at your fingertips.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#f59e0b,#f97316)">🔔</span><h3>Live Status Tracking</h3><p>Crystal-clear visibility on every submission: Valid, Invalid, In Progress, or Error. Nothing slips through the cracks.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#5457e5,#7c3aed)">🏛</span><h3>Official LHDN Integration</h3><p>View and download the official LHDN e-invoice for every validated submission. Perfect audit trails, guaranteed.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#d946ef,#ec4899)">🔄</span><h3>Streamlined Credit Notes</h3><p>Generate and submit credit note e-invoices directly linked to previously validated invoices — no complexity, no hassle.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#06b6d4,#0891b2)">📥</span><h3>One-Click Exporting</h3><p>Export audit-ready reports to Excel or PDF instantly. Perfect for management reviews, tax audits, and record-keeping.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#84cc16,#65a30d)">💰</span><h3>Affordable Monthly Plans</h3><p>Subscription-based pricing designed for SMEs. Start small, scale as you grow, cancel anytime. No long-term contracts.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#0ea5e9,#0284c7)">🇲🇾</span><h3>Malaysian-First Design</h3><p>Built specifically for Malaysian tax structures, SST, TIN formats, and LHDN workflows — not a generic global tool.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#ef4444,#dc2626)">🛡</span><h3>Bank-Grade Security</h3><p>Encrypted data storage, secure session management, and role-based access keep your financial data safe at all times.</p></div>
  </div>
</div></section>

<!-- HOW IT WORKS -->
<section id="how-it-works" style="background:#fff"><div class="wrap">
  <div class="sec-head">
    <span class="eyebrow">Simple 3-Step Workflow</span>
    <h2 class="sec">From invoice to LHDN validation in minutes.</h2>
    <p style="margin-top:16px;color:var(--muted);font-size:16px">No complex onboarding. No steep learning curves. Just three simple steps to full compliance.</p>
  </div>
  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <h3>Submit</h3>
      <p>Enter a single invoice manually or upload a batch file containing hundreds. Our smart engine handles CSV, PDF, and JSON formats with automatic field extraction.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h3>Validate</h3>
      <p>Watch submissions flow through in real-time. Instant status updates — Valid, Invalid, In Progress, or Error — with detailed diagnostics for anything that needs fixing.</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h3>Comply</h3>
      <p>Download official LHDN e-invoices, issue linked credit notes, and export comprehensive PDF/Excel reports — all with a single click. Audit-ready, always.</p>
    </div>
  </div>
</div></section>

<!-- PRICING -->
<section id="pricing"><div class="wrap">
  <div class="sec-head">
    <span class="eyebrow">Simple Monthly Pricing</span>
    <h2 class="sec">Affordable plans that scale with your business.</h2>
    <p style="margin-top:16px;color:var(--muted)">No long-term contracts · No hidden fees · Cancel anytime · Prices in MYR (RM)</p>
  </div>
  <div class="cards3">
    <div class="card plan"><h3>Starter</h3><p style="color:var(--muted);font-size:14px;margin-top:4px">Perfect for sole proprietors & micro businesses</p>
      <div class="price"><b>RM49</b><span>/ month</span></div>
      <ul><li>Up to 200 e-invoices / month</li><li>Manual entry + CSV upload</li><li>Live status tracking</li><li>LHDN invoice downloads</li><li>Email support</li></ul>
      <button class="btn ghost" onclick="openSignup()">Start Free Trial</button></div>
    <div class="card plan pop"><span class="flag">MOST POPULAR</span><h3>Growth</h3><p style="color:var(--muted);font-size:14px;margin-top:4px">Ideal for growing SMEs with regular invoicing</p>
      <div class="price"><b>RM129</b><span>/ month</span></div>
      <ul><li>Up to 1,000 e-invoices / month</li><li>Bulk batch uploads</li><li>Credit note automation</li><li>Excel & PDF report exports</li><li>Priority support</li></ul>
      <button class="btn primary" onclick="openSignup()">Start Free Trial</button></div>
    <div class="card plan"><h3>Scale</h3><p style="color:var(--muted);font-size:14px;margin-top:4px">For multi-outlet retailers & large operations</p>
      <div class="price"><b>RM299</b><span>/ month</span></div>
      <ul><li>Unlimited e-invoices</li><li>API + webhook integration</li><li>Custom user roles</li><li>Dedicated account manager</li><li>Advanced audit log</li></ul>
      <button class="btn ghost" onclick="openSignup()">Start Free Trial</button></div>
  </div>
</div></section>

<!-- FAQ -->
<section id="faq" style="background:#fff"><div class="wrap" style="max-width:760px">
  <div class="sec-head"><span class="eyebrow">FAQ</span><h2 class="sec">Questions, answered.</h2></div>
  <div class="faq-list">
    <details class="faq" open><summary>Is AZ Kejora fully compliant with LHDN e-Invoice requirements?</summary><p>Yes. Our platform is built specifically for the Malaysian e-invoicing mandate. We support all required TIN validation, SST calculations, and official LHDN submission protocols. Every validated invoice generates an official LHDN e-invoice document for your records.</p></details>
    <details class="faq"><summary>What file formats do you support for bulk uploads?</summary><p>We support CSV, JSON, and structured PDF formats for batch submissions. Our extraction engine automatically parses line items, tax totals, and customer TINs — so you can upload hundreds of invoices in one go.</p></details>
    <details class="faq"><summary>How does live status tracking work?</summary><p>Every submission flows through four clear statuses: Valid, Invalid, In Progress, or Error. You get real-time updates on your dashboard with detailed diagnostics, so you can fix issues fast and resubmit without delay.</p></details>
    <details class="faq"><summary>Can I issue credit notes through the platform?</summary><p>Absolutely. You can generate credit note e-invoices directly linked to any previously validated submission. The platform handles the LHDN submission automatically, with full audit trail integrity.</p></details>
    <details class="faq"><summary>What if I need to change plans or cancel?</summary><p>You can upgrade, downgrade, or cancel anytime from your dashboard. Upgrades apply immediately; downgrades and cancellations take effect at the end of your current billing cycle. No penalty, no hidden fees.</p></details>
    <details class="faq"><summary>Do you offer a free trial?</summary><p>Yes. Every new account starts with a <?= $trialHours ?>-hour free trial with full access to all features. No credit card required. If you love it, pick a monthly plan. If not, walk away — no questions asked.</p></details>
  </div>
</div></section>

<!-- FINAL CTA -->
<section><div class="wrap">
  <div class="cta">
    <h2>Ready to make LHDN compliance effortless?</h2>
    <p>Join hundreds of Malaysian businesses who've automated their e-invoicing. Start your <?= $trialHours ?>-hour free trial today — no credit card, no commitment.</p>
    <button class="btn white" onclick="openSignup()">Start My Free Trial →</button>
  </div>
</div></section>

<footer><div class="wrap foot">
  <span class="brand" style="font-size:15px"><span class="logo" style="width:28px;height:28px;border-radius:8px;font-size:13px">⚡</span>AZ Kejora SaaS</span>
  <small>© 2026 AZ Kejora SaaS · LHDN e-Invoice Compliant · Copyright Protected</small>
  <nav><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a></nav>
</div></footer>

<!-- LOGIN MODAL -->
<div class="modal" id="authModal" onclick="if(event.target===this)closeAuth()">
  <div class="modal-card">
    <form action="login.php" method="POST">
      <span class="logo">⚡</span>
      <h3>Sign in to AZ Kejora</h3>
      <p class="sub">Access your compliance dashboard</p>
<?php if($loginError): ?>
  <div class="err"><?= ($_GET['err'] ?? '') === '2'
      ? 'Account not activated yet — open the activation link we emailed you.'
      : 'Invalid email or password.' ?></div>
<?php endif; ?>

      <!-- GOOGLE SIGN-IN BUTTON -->
      <div style="margin-bottom:20px">
        <a href="?google=1" class="btn ghost full" style="gap:10px;font-weight:700">
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>
            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/>
            <path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/>
            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>
          </svg>
          Continue with Google
        </a>
        <div style="display:flex;align-items:center;gap:12px;margin:16px 0;color:var(--faint);font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase">
          <span style="flex:1;height:1px;background:var(--line)"></span>OR<span style="flex:1;height:1px;background:var(--line)"></span>
        </div>
      </div>

      <div class="field"><label>Email address</label><input type="email" name="email"  required></div>
      <div class="field"><label>Password</label><input type="password" name="password"  required></div>
      <button type="submit" class="btn primary full">Sign in securely</button>
    </form>
    <button class="btn ghost full" style="margin-top:12px" onclick="closeAuth()">Cancel</button>
  </div>
</div>
<?php if($loginError): ?><script>openAuth();</script><?php endif; ?>
<script>
function openAuth(){document.getElementById('authModal').classList.add('open')}
function closeAuth(){document.getElementById('authModal').classList.remove('open')}
<?php if($loginError): ?>openAuth();<?php endif; ?>
</script>

<!-- ============ SIGNUP MODAL ============ -->
<div class="modal" id="signupModal" onclick="if(event.target===this)closeSignup()">
  <div class="modal-card">
    <form action="signup.php" method="POST">
      <span class="logo">⚡</span>
      <h3>Start your free trial</h3>
      <p class="sub">Full access to LHDN e-Invoice compliance tools — no credit card required</p>
      <?php if (isset($_GET['signup']) && isset($_GET['err'])): ?>
        <div class="err"><?= htmlspecialchars($_GET['err']) ?></div>
      <?php endif; ?>

      <div style="margin-bottom:20px">
        <a href="?google=1" class="btn ghost full" style="gap:10px;font-weight:700">
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>
            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/>
            <path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/>
            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>
          </svg>
          Continue with Google
        </a>
        <div style="display:flex;align-items:center;gap:12px;margin:16px 0;color:var(--faint);font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase">
          <span style="flex:1;height:1px;background:var(--line)"></span>OR<span style="flex:1;height:1px;background:var(--line)"></span>
        </div>
      </div>

      <div class="field"><label>Full name</label><input type="text" name="name" placeholder="Aina Rahman" required></div>
      <div class="field"><label>Email address</label><input type="email" name="email" placeholder="aina@company.com" required></div>
      <div class="field"><label>Phone (Malaysia)</label><input type="tel" name="phone" placeholder="+60 12-345 6789" pattern="[\+\d\s\-]{8,20}" required></div>
      <div class="field"><label>Password</label><input type="password" name="password" placeholder="At least 6 characters" minlength="6" required></div>
      <button type="submit" class="btn primary full">Start Free Trial →</button>
      <p class="hint">Already have an account? <a href="#" onclick="closeSignup();openAuth();return false" style="color:var(--brand);font-weight:700">Sign in</a></p>
    </form>
    <button class="btn ghost full" style="margin-top:12px" onclick="closeSignup()">Cancel</button>
  </div>
</div>

<!-- ============ CHECK-EMAIL MODAL ============ -->
<?php if (isset($_GET['check'])): ?>
<div class="modal open" id="checkModal">
  <div class="modal-card" style="text-align:center">
    <span class="logo" style="background:linear-gradient(135deg,#10b981,#14b8a6)">✉</span>
    <h3>Check your inbox</h3>
    <p class="sub">We sent an activation link to<br><b><?= htmlspecialchars($_GET['email']) ?></b></p>
    <div style="margin:20px 0;padding:16px;background:#f8fafc;border-radius:12px;font-size:12px;color:#475569;text-align:left;word-break:break-all">
      <div style="font-weight:700;margin-bottom:8px;color:#059669">✓ Activation email sent</div>
      Click the link in the email to activate your account and start your <?= $trialHours ?>-hour free trial.
      <?php if (!empty($_GET['fallback'])): ?>
        <details style="margin-top:12px"><summary style="cursor:pointer;color:var(--brand);font-weight:600">Show activation link (fallback)</summary>
        <a href="<?= htmlspecialchars($_GET['fallback']) ?>" style="color:var(--brand);text-decoration:underline;word-break:break-all"><?= htmlspecialchars($_GET['fallback']) ?></a>
        </details>
      <?php endif; ?>
    </div>
    <button class="btn primary full" onclick="document.getElementById('checkModal').classList.remove('open')">Got it</button>
  </div>
</div>
<?php endif; ?>

<script>
function openSignup(){document.getElementById('signupModal').classList.add('open')}
function closeSignup(){document.getElementById('signupModal').classList.remove('open')}
<?php if (isset($_GET['signup']) && isset($_GET['err'])): ?>openSignup();<?php endif; ?>
</script>
</body>
</html>
