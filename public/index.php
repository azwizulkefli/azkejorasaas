<?php
session_start();
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
<title>AZ Kejora SaaS — E-Invoice & Facility Booking Platform</title>
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
.slots{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.slot{border:1px solid var(--line);border-radius:8px;padding:8px 0;text-align:center;font-size:12px;font-weight:600;color:var(--muted)}
.slot.on{background:var(--brand);border-color:var(--brand);color:#fff;box-shadow:0 6px 14px -6px rgba(84,87,229,.6)}
.slot.off{background:#f1f5f9;border-color:#f1f5f9;color:#cbd5e1;text-decoration:line-through}
.total-row{margin-top:20px;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border-radius:12px;padding:16px 20px}
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
  <nav class="links"><a href="#features">Features</a><a href="#services">Services</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a></nav>
  <div class="nav-cta">
    <?php if($isLoggedIn): ?>
      <span class="hi">Hi, <?= htmlspecialchars(explode(' ',$userName)[0]) ?></span>
      <?php if($userRole==='admin'): ?><a class="btn ghost" href="admin.php">Admin Console</a><?php endif; ?>
      <a class="btn ghost" href="login.php?logout=1">Sign out</a>
      <a class="btn primary" href="<?= $userRole==='admin' ? 'admin.php' : '#' ?>">Get started</a>
    <?php else: ?>
      <button class="btn ghost" onclick="openAuth()">Sign in</button>
      <button class="btn primary" onclick="openSignup()">Get started</button>
    <?php endif; ?>
  </div>
</div></header>

<!-- HERO -->
<section class="hero"><div class="glow"></div>
  <div class="wrap hero-grid">
    <div>
      <span class="eyebrow"><span class="dot"></span>LHDN e-Invoice ready · Supabase PG</span>
      <h1 class="big">Run your whole service business on <span class="grad-text">one elegant platform.</span></h1>
      <p class="lead">AZ Kejora SaaS pairs smart e-Invoicing for SMEs with a beautiful facility-booking engine — wrapped in a simple 3-month subscription. Sign in and start free.</p>
      <div class="cta-row">
        <?php if($isLoggedIn): ?><a class="btn primary" href="<?= $userRole==='admin' ? 'admin.php' : '#' ?>" style="padding:14px 28px;font-size:16px">Open dashboard →</a>
        <?php else: ?><button class="btn primary" style="padding:14px 28px;font-size:16px" onclick="openAuth()">Start 30-day free trial →</button><?php endif; ?>
        <a class="btn ghost" style="padding:14px 24px;font-size:16px" href="#pricing">View 3-month plans</a>
      </div>
      <div class="checks"><span>No credit card</span><span>Secure login</span><span>Stripe · FPX · ToyyibPay</span></div>
    </div>
    <div class="mock">
      <div class="mock-card">
        <div class="mock-top"><span></span><span></span><span></span><i>app.azkejora.io</i></div>
        <div class="mock-grid">
          <div class="mock-kpi"><b>RM 48.2k</b><p>Gross</p></div>
          <div class="mock-kpi g"><b>RM 3.4k</b><p>SST</p></div>
          <div class="mock-kpi b"><b>96%</b><p>Compliant</p></div>
        </div>
        <div class="mock-bars"><i style="height:35%"></i><i style="height:52%"></i><i style="height:44%"></i><i style="height:66%"></i><i style="height:58%"></i><i style="height:78%"></i><i style="height:70%"></i><i style="height:92%"></i></div>
      </div>
      <div class="chip" style="left:-20px;top:28px"><span class="ic" style="background:#d1fae5;color:#059669">✓</span><div><b>Invoice validated</b><small>SST 8% · TIN OK · +RM 1,240</small></div></div>
      <div class="chip" style="right:-14px;bottom:36px;animation-delay:1.4s"><span class="ic" style="background:#e0e5ff;color:var(--brand)">📅</span><div><b>Booking confirmed</b><small>Badminton Court · 18:00 · RM 36</small></div></div>
    </div>
  </div>
  <div class="wrap"><div class="stats">
    <div><b>1,240+</b><p>SMEs onboarded</p></div>
    <div><b>RM 4.2M</b><p>Invoices processed</p></div>
    <div><b>99.98%</b><p>Platform uptime</p></div>
    <div><b>4.9★</b><p>Merchant rating</p></div>
  </div></div>
</section>

<!-- FEATURES -->
<section id="features"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">Why AZ Kejora</span><h2 class="sec">Everything a modern SME needs, nothing it doesn't.</h2></div>
  <div class="cards4">
    <div class="card"><span class="ic-tile" style="background:var(--grad)">🔐</span><h3>Secure Sign-On</h3><p>Session-based authentication with bcrypt-hashed credentials stored on Supabase PostgreSQL.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#10b981,#14b8a6)">🔄</span><h3>90-Day Billing</h3><p>Predictable 3-month cycles with webhook-driven renewals — <code>invoice.paid</code> extends your period automatically.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#f59e0b,#f97316)">🧾</span><h3>Smart E-Invoicing</h3><p>Upload CSV, PDF or JSON — extraction, SST totals, compliance scoring and one-click exports.</p></div>
    <div class="card"><span class="ic-tile" style="background:linear-gradient(135deg,#d946ef,#ec4899)">📅</span><h3>Facility Booking</h3><p>Courts, rooms and halls with live slot availability, instant checkout and a full merchant console.</p></div>
  </div>
</div></section>

<!-- SERVICES -->
<section id="services" style="background:#fff"><div class="wrap">
  <div class="svc">
    <div>
      <span class="eyebrow">Service 01 · E-Invoice for SME</span>
      <h2 class="sec" style="text-align:left;margin-top:20px">From messy files to audit-ready reports in seconds.</h2>
      <ul>
        <li><span class="tick">✓</span>Drag-and-drop extraction for <b>CSV, PDF & JSON</b> invoice files.</li>
        <li><span class="tick">✓</span>Automated SST 6% / 8% tax totals and category summaries.</li>
        <li><span class="tick">✓</span>LHDN TIN & compliance status checks with a live score.</li>
        <li><span class="tick">✓</span>Downloadable <b>PDF & Excel</b> export reports.</li>
      </ul>
    </div>
    <div class="card">
      <div class="drop">⬆ Drop invoice files here<small>CSV · PDF · JSON</small></div>
      <div class="mini-stats">
        <div><b>RM 48.2k</b><p>Gross</p></div>
        <div><b style="color:var(--emerald)">RM 3.4k</b><p>SST</p></div>
        <div><b style="color:var(--brand)">96%</b><p>Compliant</p></div>
      </div>
    </div>
  </div>
  <div class="svc">
    <div class="card">
      <div class="slots">
        <span class="slot">10:00</span><span class="slot on">11:00</span><span class="slot">12:00</span><span class="slot off">14:00</span><span class="slot">15:00</span>
        <span class="slot">16:00</span><span class="slot on">18:00</span><span class="slot on">19:00</span><span class="slot">20:00</span><span class="slot">21:00</span>
      </div>
      <div class="total-row"><div><small style="color:var(--muted)">3 slots · Badminton Court</small><br><b style="font-size:18px">RM 54</b></div><span class="btn primary" style="padding:8px 16px;font-size:12px">Instant checkout</span></div>
    </div>
    <div>
      <span class="eyebrow">Service 02 · Retail Facility Booking</span>
      <h2 class="sec" style="text-align:left;margin-top:20px">Fill your courts, rooms and halls — on autopilot.</h2>
      <ul>
        <li><span class="tick">✓</span>Merchants add facilities, hourly rates and availability windows.</li>
        <li><span class="tick">✓</span>Public booking portal with a real-time slot calendar.</li>
        <li><span class="tick">✓</span>Instant payment checkout & approve / decline workflow.</li>
      </ul>
    </div>
  </div>
</div></section>

<!-- PRICING -->
<section id="pricing"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">Pricing matrix</span><h2 class="sec">One simple 3-month subscription.</h2><p style="margin-top:16px;color:var(--muted)">Billed every 90 days · cancel anytime · prices in MYR (RM).</p></div>
  <div class="cards3">
    <div class="card plan"><h3>Starter</h3><p style="color:var(--muted);font-size:14px;margin-top:4px">For solo operators</p>
      <div class="price"><b>RM90</b><span>/ 3 months</span></div>
      <ul><li>1 business entity</li><li>E-Invoice: 200 docs / cycle</li><li>1 bookable facility</li><li>Standard reports (PDF)</li><li>Email support</li></ul>
      <button class="btn ghost" onclick="openAuth()">Subscribe</button></div>
    <div class="card plan pop"><span class="flag">MOST POPULAR</span><h3>Growth</h3><p style="color:var(--muted);font-size:14px;margin-top:4px">For growing SMEs</p>
      <div class="price"><b>RM210</b><span>/ 3 months</span></div>
      <ul><li>3 business entities</li><li>E-Invoice: unlimited docs</li><li>10 facilities + public portal</li><li>SST & compliance exports</li><li>Priority support</li></ul>
      <button class="btn primary" onclick="openAuth()">Subscribe</button></div>
    <div class="card plan"><h3>Scale</h3><p style="color:var(--muted);font-size:14px;margin-top:4px">For multi-site retailers</p>
      <div class="price"><b>RM450</b><span>/ 3 months</span></div>
      <ul><li>Unlimited entities</li><li>Unlimited facilities</li><li>API + webhook access</li><li>Custom roles & audit log</li><li>Dedicated manager</li></ul>
      <button class="btn ghost" onclick="openAuth()">Subscribe</button></div>
  </div>
</div></section>

<!-- FAQ -->
<section id="faq" style="background:#fff"><div class="wrap" style="max-width:760px">
  <div class="sec-head"><span class="eyebrow">FAQ</span><h2 class="sec">Questions, answered.</h2></div>
  <div class="faq-list">
    <details class="faq" open><summary>How does the free trial work?</summary><p>Sign in and your customer record is provisioned instantly (status <code>active_trial</code>, <code>trial_ends_at = now + 2 hours</code> by default, configurable by admin). Full access to E-Invoice and Booking tools — no credit card required.</p></details>
    <details class="faq"><summary>Why a 3-month billing cycle?</summary><p>Quarterly billing keeps pricing predictable for SMEs and cuts admin overhead. On every successful payment, webhooks (<code>invoice.paid</code>, <code>payment_intent.succeeded</code>) advance <code>period_ends_at</code> by 90 days automatically.</p></details>
    <details class="faq"><summary>Which invoice file formats are supported?</summary><p>CSV, PDF and JSON. Our extraction engine parses line items, computes SST 6%/8% totals, validates LHDN TINs and produces downloadable PDF / Excel compliance reports.</p></details>
    <details class="faq"><summary>Which payment methods do you accept?</summary><p>Cards via Stripe (3-D Secure), FPX online banking (Maybank2u, CIMB Clicks, Public Bank and more) and ToyyibPay DuitNow QR — all with local compliance built in.</p></details>
    <details class="faq"><summary>Can I cancel or change plans?</summary><p>Yes. Upgrades apply immediately and prorate into your next cycle; cancellations stop auto-renewal at period end. Admins can also extend trials or suspend accounts manually.</p></details>
  </div>
</div></section>

<!-- CTA + FOOTER -->
<section><div class="wrap">
  <div class="cta"><h2>Start your 30-day free trial today.</h2><p>Sign in — your trial is provisioned instantly on Supabase PostgreSQL. No card required.</p>
  <button class="btn white" onclick="openAuth()">Create my account →</button></div>
</div></section>
<footer><div class="wrap foot">
  <span class="brand" style="font-size:15px"><span class="logo" style="width:28px;height:28px;border-radius:8px;font-size:13px">⚡</span>AZ Kejora SaaS</span>
  <small>© 2026 AZ Kejora SaaS · PHP 8.2 · Supabase PostgreSQL · Stripe Webhooks</small>
  <nav><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a></nav>
</div></footer>

<!-- LOGIN MODAL -->
<div class="modal" id="authModal" onclick="if(event.target===this)closeAuth()">
  <div class="modal-card">
    <form action="login.php" method="POST">
      <span class="logo">⚡</span>
      <h3>Sign in to AZ Kejora</h3>
      <p class="sub">Enter your credentials to continue</p>
      <?php if($loginError): ?><div class="err">Invalid email or password.</div><?php endif; ?>
      <div class="field"><label>Email address</label><input type="email" name="email" placeholder="admin@azkejora.io" required></div>
      <div class="field"><label>Password</label><input type="password" name="password" placeholder="••••••••" required></div>
      <button type="submit" class="btn primary full">Sign in securely</button>
      <p class="hint">Demo Admin: <code>admin@azkejora.io</code> / <code>password</code></p>
    </form>
    <button class="btn ghost full" style="margin-top:12px" onclick="closeAuth()">Cancel</button>
  </div>
</div>
<?php if($loginError): ?><script>openAuth();</script><?php endif; ?>
<script>
function openAuth(){document.getElementById('authModal').classList.add('open')}
function closeAuth(){document.getElementById('authModal').classList.remove('open')}
</script>

<!-- ============ SIGNUP MODAL ============ -->
<div class="modal" id="signupModal" onclick="if(event.target===this)closeSignup()">
  <div class="modal-card">
    <form action="signup.php" method="POST">
      <span class="logo">⚡</span>
      <h3>Create your account</h3>
      <p class="sub">Start your free trial — no card required</p>
      <?php if (isset($_GET['signup']) && isset($_GET['err'])): ?>
        <div class="err"><?= htmlspecialchars($_GET['err']) ?></div>
      <?php endif; ?>
      <div class="field"><label>Full name</label><input type="text" name="name" placeholder="Aina Rahman" required></div>
      <div class="field"><label>Email address</label><input type="email" name="email" placeholder="aina@company.com" required></div>
      <div class="field"><label>Phone (Malaysia)</label><input type="tel" name="phone" placeholder="+60 12-345 6789" pattern="[\+\d\s\-]{8,20}" required></div>
      <button type="submit" class="btn primary full">Send activation link →</button>
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
      Click the link in the email to activate your account and start your <?= get_setting($pdo,'general','trial_default_hours',1) ?? 1 ?>-hour free trial.
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
