<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AZ Kejora SaaS — E-Invoice & Facility Booking Platform</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/500.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/600.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/700.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/600.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/700.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/800.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
tailwind.config = {
  theme: { extend: {
    fontFamily: { display:['Sora','ui-sans-serif','system-ui'], sans:['Inter','ui-sans-serif','system-ui'] },
    colors: { ink:'#131327', brand:{50:'#eef1ff',100:'#e0e5ff',200:'#c6ceff',300:'#a3aeff',400:'#7f8aff',500:'#6366f1',600:'#5457e5',700:'#4644cf',800:'#3b38a8',900:'#333285'} },
    boxShadow: { soft:'0 8px 30px -12px rgba(19,19,39,.15)', card:'0 1px 2px rgba(19,19,39,.06), 0 12px 32px -16px rgba(19,19,39,.12)' }
  } }
}
</script>
<style type="text/tailwindcss">
  .btn-primary{@apply inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/25 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-brand-600/40 cursor-pointer whitespace-nowrap;}
  .btn-ghost{@apply inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition-all duration-200 hover:border-brand-300 hover:text-brand-700 cursor-pointer whitespace-nowrap;}
  .btn-soft{@apply inline-flex items-center justify-center gap-2 rounded-xl bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-700 transition-all duration-200 hover:bg-brand-100 cursor-pointer whitespace-nowrap;}
  .btn-danger{@apply inline-flex items-center justify-center gap-2 rounded-xl bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-100 cursor-pointer;}
  .card{@apply bg-white rounded-2xl border border-slate-200/70 shadow-card;}
  .input{@apply w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-ink outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10;}
  .label{@apply mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500;}
  .th{@apply px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400;}
  .td{@apply px-4 py-3.5 text-sm text-slate-600;}
  .navitem{@apply flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-slate-500 transition-all duration-200 hover:bg-slate-100 hover:text-ink cursor-pointer;}
  .navitem-on{@apply bg-gradient-to-r from-brand-600 to-violet-500 text-white shadow-lg shadow-brand-600/25 hover:text-white;}
  .eyebrow{@apply inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-bold uppercase tracking-widest text-brand-700;}
  .grad-text{@apply bg-gradient-to-r from-brand-600 via-violet-500 to-fuchsia-500 bg-clip-text text-transparent;}
  .slot{@apply rounded-lg border px-2 py-2 text-xs font-semibold transition-all duration-150 cursor-pointer select-none;}
  .slot-free{@apply border-slate-200 bg-white text-slate-600 hover:border-brand-400 hover:text-brand-700;}
  .slot-on{@apply border-brand-600 bg-brand-600 text-white shadow-md shadow-brand-600/30 scale-105;}
  .slot-off{@apply border-slate-100 bg-slate-100 text-slate-300 line-through cursor-not-allowed;}
</style>
<style>
  html{scroll-behavior:smooth}
  body{font-family:'Inter',sans-serif}
  ::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-thumb{background:#c7cbe0;border-radius:8px}::-webkit-scrollbar-track{background:transparent}
  @keyframes fadeUp{0%{opacity:0;transform:translateY(16px)}100%{opacity:1;transform:translateY(0)}}
  @keyframes pop{0%{opacity:0;transform:scale(.94)}100%{opacity:1;transform:scale(1)}}
  @keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
  @keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.35}}
  .anim-view{animation:fadeUp .5s cubic-bezier(.16,1,.3,1) both}
  .anim-pop{animation:pop .25s ease-out both}
  .floaty{animation:floaty 6s ease-in-out infinite}
  .dot-live{animation:pulse-dot 1.6s ease-in-out infinite}
  .hero-grid{background-image:linear-gradient(rgba(99,102,241,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(99,102,241,.06) 1px,transparent 1px);background-size:44px 44px}
  .stripe-off{background-image:repeating-linear-gradient(45deg,#f1f5f9 0 6px,#fff 6px 12px)}
</style>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js" defer></script>
</head>
<body class="bg-[#F6F7FB] text-ink antialiased" x-data="kejora()" x-init="init()">

<!-- ============ SVG SPRITE ============ -->
<svg class="hidden" xmlns="http://www.w3.org/2000/svg">
  <symbol id="i-clouddollar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <path d="M17.5 19.5H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
    <path d="M11.5 8.6v7.2"/>
    <path d="M13.4 10.3c-.4-.7-1.2-1.1-2-1.1-1.2 0-2.1.6-2.1 1.5 0 2 4.2 1.1 4.2 3.1 0 .9-.9 1.5-2.1 1.5-.9 0-1.7-.4-2.1-1.1"/>
  </symbol>
  <symbol id="i-google" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></symbol>
  <symbol id="i-home" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></symbol>
  <symbol id="i-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></symbol>
  <symbol id="i-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M9 13h6M9 17h4"/></symbol>
  <symbol id="i-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16"/><path d="M7 16v-5M12 16V6M17 16v-8"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="3.5"/><path d="M21 21v-2a4 4 0 0 0-3-3.87"/><path d="M15.5 3.13a3.5 3.5 0 0 1 0 6.74"/></symbol>
  <symbol id="i-cal" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 20h16"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12M6 10l6 6 6-6"/><path d="M4 20h16"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 12.5l5 5 10-11"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></symbol>
  <symbol id="i-bell" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16M13 5l7 7-7 7"/></symbol>
  <symbol id="i-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 14h10l1-14M10 11v6M14 11v6"/></symbol>
  <symbol id="i-pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M9 5v14M15 5v14"/></symbol>
  <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5l12 7-12 7z" fill="currentColor"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6z"/><path d="M9 11.5l2 2 4-4"/></symbol>
  <symbol id="i-building" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21h16"/><path d="M6 21V5l6-2v18"/><path d="M12 21V9l6 2v10"/></symbol>
  <symbol id="i-receipt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-2-1.4-2 1.4-2-1.4L10 21l-2-1.4L6 21z"/><path d="M9 8h6M9 12h6"/></symbol>
  <symbol id="i-spark" viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/></symbol>
  <symbol id="i-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 1 0-2.3 6.3"/><path d="M20 5v6h-6"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/></symbol>
  <symbol id="i-history" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></symbol>
  <symbol id="i-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18"/><circle cx="16.5" cy="14.5" r="1.2" fill="currentColor"/></symbol>
  <symbol id="i-star" viewBox="0 0 24 24"><path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z" fill="currentColor"/></symbol>
</svg>

<!-- ============ TOASTS ============ -->
<div class="fixed right-4 top-4 z-[100] flex w-80 flex-col gap-2">
  <template x-for="t in toasts" :key="t.id">
    <div class="anim-pop card flex items-start gap-3 p-4"
         :class="t.type==='error'?'border-rose-200':(t.type==='info'?'border-brand-200':'border-emerald-200')">
      <div class="grid h-8 w-8 shrink-0 place-items-center rounded-lg"
           :class="t.type==='error'?'bg-rose-100 text-rose-600':(t.type==='info'?'bg-brand-100 text-brand-600':'bg-emerald-100 text-emerald-600')">
        <svg class="h-4 w-4"><use :href="t.type==='error'?'#i-alert':'#i-check'"/></svg>
      </div>
      <p class="text-sm font-medium text-slate-700" x-text="t.msg"></p>
      <button class="ml-auto text-slate-400 hover:text-slate-600" @click="toasts=toasts.filter(x=>x.id!==t.id)"><svg class="h-4 w-4"><use href="#i-x"/></svg></button>
    </div>
  </template>
</div>

<!-- ================================================== LANDING ================================================== -->
<div x-show="view==='landing'" class="anim-view">
  <!-- NAV -->
  <header class="fixed inset-x-0 top-0 z-40">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
      <div class="mt-4 flex items-center justify-between rounded-2xl border border-white/60 bg-white/75 px-5 py-3 shadow-soft backdrop-blur-xl">
        <button class="flex items-center gap-2.5" @click="go('landing')">
          <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg shadow-brand-600/30"><svg class="h-5 w-5"><use href="#i-clouddollar"/></svg></span>
          <span class="font-display text-lg font-bold tracking-tight">AZ Kejora<span class="grad-text"> SaaS</span></span>
        </button>
        <nav class="hidden items-center gap-7 text-sm font-semibold text-slate-600 md:flex">
          <a href="#features" class="hover:text-brand-700">Features</a>
          <a href="#services" class="hover:text-brand-700">Services</a>
          <a href="#pricing" class="hover:text-brand-700">Pricing</a>
          <a href="#faq" class="hover:text-brand-700">FAQ</a>
        </nav>
        <div class="flex items-center gap-2">
          <template x-if="!session"><button class="btn-ghost" @click="authOpen=true"><svg class="h-4 w-4"><use href="#i-google"/></svg>Sign in</button></template>
          <template x-if="session"><button class="btn-ghost" @click="go(session.role==='admin'?'admin':'dashboard')">Dashboard<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button></template>
          <button class="btn-primary" @click="session?go('dashboard'):(authOpen=true)">Get started</button>
        </div>
      </div>
    </div>
  </header>

  <!-- HERO -->
  <section class="hero-grid relative overflow-hidden pb-24 pt-40">
    <div class="pointer-events-none absolute -top-32 left-1/2 h-[480px] w-[820px] -translate-x-1/2 rounded-full bg-gradient-to-r from-brand-200/60 via-violet-200/60 to-fuchsia-200/60 blur-3xl"></div>
    <div class="relative mx-auto grid max-w-7xl items-center gap-16 px-4 sm:px-6 lg:grid-cols-2">
      <div>
        <span class="eyebrow"><span class="dot-live h-1.5 w-1.5 rounded-full bg-emerald-500"></span>LHDN e-Invoice ready · Supabase PG</span>
        <h1 class="mt-6 font-display text-5xl font-extrabold leading-[1.08] tracking-tight sm:text-6xl">Run your whole service business on <span class="grad-text">one elegant platform.</span></h1>
        <p class="mt-6 max-w-xl text-lg leading-relaxed text-slate-600">AZ Kejora SaaS pairs smart e-Invoicing for SMEs with a beautiful facility-booking engine — wrapped in a simple 3-month subscription. Sign in with Google and start free for 30 days.</p>
        <div class="mt-8 flex flex-wrap items-center gap-3">
          <button class="btn-primary px-7 py-3.5 text-base" @click="session?go('dashboard'):(authOpen=true)">Start 30-day free trial<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
          <a href="#pricing" class="btn-ghost px-6 py-3.5 text-base">View 3-month plans</a>
        </div>
        <div class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500">
          <span class="flex items-center gap-1.5"><svg class="h-4 w-4 text-emerald-500"><use href="#i-check"/></svg>No credit card</span>
          <span class="flex items-center gap-1.5"><svg class="h-4 w-4 text-emerald-500"><use href="#i-check"/></svg>Google OAuth 2.0</span>
          <span class="flex items-center gap-1.5"><svg class="h-4 w-4 text-emerald-500"><use href="#i-check"/></svg>Stripe · FPX · ToyyibPay</span>
        </div>
      </div>
      <div class="relative">
        <img src="https://image.qwenlm.ai/public_source/de418ccf-8560-403d-9fee-894122daa9d6/1a6e62981-7c6d-4186-a748-a158f2c924c3.png" alt="AZ Kejora SaaS preview" class="w-full rounded-3xl shadow-2xl shadow-brand-900/30">
        <div class="floaty card absolute -left-6 top-8 flex items-center gap-3 px-4 py-3">
          <span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-100 text-emerald-600"><svg class="h-4 w-4"><use href="#i-check"/></svg></span>
          <div><p class="text-xs font-bold">Invoice validated</p><p class="text-[11px] text-slate-500">SST 8% · TIN OK · +RM 1,240</p></div>
        </div>
        <div class="floaty card absolute -right-4 bottom-10 flex items-center gap-3 px-4 py-3" style="animation-delay:1.4s">
          <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-100 text-brand-600"><svg class="h-4 w-4"><use href="#i-cal"/></svg></span>
          <div><p class="text-xs font-bold">Booking confirmed</p><p class="text-[11px] text-slate-500">Badminton Court · 18:00 · RM 36</p></div>
        </div>
      </div>
    </div>
    <div class="relative mx-auto mt-20 max-w-7xl px-4 sm:px-6">
      <div class="grid grid-cols-2 gap-6 rounded-2xl border border-slate-200/70 bg-white/70 p-8 backdrop-blur md:grid-cols-4">
        <div><p class="font-display text-3xl font-extrabold">1,240+</p><p class="mt-1 text-sm text-slate-500">SMEs onboarded</p></div>
        <div><p class="font-display text-3xl font-extrabold">RM 4.2M</p><p class="mt-1 text-sm text-slate-500">Invoices processed</p></div>
        <div><p class="font-display text-3xl font-extrabold">99.98%</p><p class="mt-1 text-sm text-slate-500">Platform uptime</p></div>
        <div><p class="font-display text-3xl font-extrabold">4.9<span class="text-amber-400">★</span></p><p class="mt-1 text-sm text-slate-500">Merchant rating</p></div>
      </div>
    </div>
  </section>

  <!-- FEATURES -->
  <section id="features" class="mx-auto max-w-7xl px-4 py-24 sm:px-6">
    <div class="mx-auto max-w-2xl text-center">
      <span class="eyebrow">Why AZ Kejora</span>
      <h2 class="mt-5 font-display text-4xl font-extrabold tracking-tight">Everything a modern SME needs, nothing it doesn't.</h2>
    </div>
    <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
      <div class="card group p-7 transition hover:-translate-y-1 hover:shadow-soft">
        <span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg shadow-brand-600/25"><svg class="h-6 w-6"><use href="#i-google"/></svg></span>
        <h3 class="mt-5 font-display text-lg font-bold">Google Sign-On</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-500">Zero passwords. Secure OAuth 2.0 with auto-provisioning and an instant 30-day trial on first sign-in.</p>
      </div>
      <div class="card group p-7 transition hover:-translate-y-1 hover:shadow-soft">
        <span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-500 text-white shadow-lg shadow-emerald-500/25"><svg class="h-6 w-6"><use href="#i-refresh"/></svg></span>
        <h3 class="mt-5 font-display text-lg font-bold">90-Day Billing</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-500">Predictable 3-month cycles with webhook-driven renewals — <code class="text-xs">invoice.paid</code> extends your period automatically.</p>
      </div>
      <div class="card group p-7 transition hover:-translate-y-1 hover:shadow-soft">
        <span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 text-white shadow-lg shadow-amber-500/25"><svg class="h-6 w-6"><use href="#i-receipt"/></svg></span>
        <h3 class="mt-5 font-display text-lg font-bold">Smart E-Invoicing</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-500">Upload CSV, PDF or JSON — extraction, SST totals, compliance scoring and one-click PDF / Excel exports.</p>
      </div>
      <div class="card group p-7 transition hover:-translate-y-1 hover:shadow-soft">
        <span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-fuchsia-500 to-pink-500 text-white shadow-lg shadow-fuchsia-500/25"><svg class="h-6 w-6"><use href="#i-cal"/></svg></span>
        <h3 class="mt-5 font-display text-lg font-bold">Facility Booking</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-500">Courts, rooms and halls with live slot availability, instant checkout and a full merchant console.</p>
      </div>
    </div>
  </section>

  <!-- SERVICES -->
  <section id="services" class="bg-white py-24">
    <div class="mx-auto max-w-7xl space-y-24 px-4 sm:px-6">
      <div class="grid items-center gap-14 lg:grid-cols-2">
        <div>
          <span class="eyebrow">Service 01 · E-Invoice for SME</span>
          <h2 class="mt-5 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">From messy files to audit-ready reports in seconds.</h2>
          <ul class="mt-7 space-y-4 text-slate-600">
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>Drag-and-drop extraction for <b>CSV, PDF & JSON</b> invoice files.</li>
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>Automated SST 6% / 8% tax totals and category summaries.</li>
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>LHDN TIN & compliance status checks with a live score.</li>
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>Downloadable <b>PDF & Excel</b> export reports.</li>
          </ul>
          <button class="btn-primary mt-8" @click="session?go('einvoice'):(authOpen=true)">Open E-Invoice tool<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
        </div>
        <div class="card p-6">
          <div class="rounded-xl border-2 border-dashed border-brand-300 bg-brand-50/50 p-6 text-center">
            <svg class="mx-auto h-8 w-8 text-brand-500"><use href="#i-upload"/></svg>
            <p class="mt-2 text-sm font-bold text-brand-700">Drop invoice files here</p>
            <p class="text-xs text-slate-500">CSV · PDF · JSON</p>
          </div>
          <div class="mt-5 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl bg-slate-50 p-4"><p class="font-display text-xl font-extrabold">RM 48.2k</p><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Gross</p></div>
            <div class="rounded-xl bg-slate-50 p-4"><p class="font-display text-xl font-extrabold text-emerald-600">RM 3.4k</p><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">SST</p></div>
            <div class="rounded-xl bg-slate-50 p-4"><p class="font-display text-xl font-extrabold text-brand-600">96%</p><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Compliant</p></div>
          </div>
        </div>
      </div>
      <div class="grid items-center gap-14 lg:grid-cols-2">
        <div class="order-2 lg:order-1 card p-6">
          <div class="grid grid-cols-5 gap-2">
            <template x-for="(s,i) in ['10:00','11:00','12:00','14:00','15:00','16:00','18:00','19:00','20:00','21:00']" :key="i">
              <span class="slot text-center" :class="[1,6,7].includes(i)?'slot-on':([3].includes(i)?'slot-off':'slot-free')" x-text="s"></span>
            </template>
          </div>
          <div class="mt-5 flex items-center justify-between rounded-xl bg-slate-50 px-5 py-4">
            <div><p class="text-xs text-slate-500">3 slots · Badminton Court</p><p class="font-display text-lg font-extrabold">RM 54</p></div>
            <span class="btn-primary px-4 py-2 text-xs">Instant checkout</span>
          </div>
        </div>
        <div class="order-1 lg:order-2">
          <span class="eyebrow">Service 02 · Retail Facility Booking</span>
          <h2 class="mt-5 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Fill your courts, rooms and halls — on autopilot.</h2>
          <ul class="mt-7 space-y-4 text-slate-600">
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-fuchsia-100 text-fuchsia-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>Merchants add facilities, hourly rates and availability windows.</li>
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-fuchsia-100 text-fuchsia-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>Public booking portal with a real-time slot calendar.</li>
            <li class="flex gap-3"><span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-md bg-fuchsia-100 text-fuchsia-600"><svg class="h-3.5 w-3.5"><use href="#i-check"/></svg></span>Instant payment checkout & approve / decline workflow.</li>
          </ul>
          <button class="btn-primary mt-8" @click="session?go('portal'):(authOpen=true)">Browse the portal<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
        </div>
      </div>
    </div>
  </section>

  <!-- PRICING -->
  <section id="pricing" class="mx-auto max-w-7xl px-4 py-24 sm:px-6">
    <div class="mx-auto max-w-2xl text-center">
      <span class="eyebrow">Pricing matrix</span>
      <h2 class="mt-5 font-display text-4xl font-extrabold tracking-tight">One simple 3-month subscription.</h2>
      <p class="mt-4 text-slate-600">Billed every 90 days · cancel anytime · prices in MYR (RM).</p>
    </div>
    <div class="mt-14 grid gap-6 lg:grid-cols-3">
      <template x-for="(p,i) in plans" :key="p.name">
        <div class="card relative p-8 transition hover:-translate-y-1 hover:shadow-soft" :class="p.popular?'ring-2 ring-brand-500':''">
          <span x-show="p.popular" class="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-gradient-to-r from-brand-600 to-violet-500 px-4 py-1 text-xs font-bold text-white shadow-lg">MOST POPULAR</span>
          <h3 class="font-display text-lg font-bold" x-text="p.name"></h3>
          <p class="mt-1 text-sm text-slate-500" x-text="p.tag"></p>
          <div class="mt-5 flex items-end gap-2"><span class="font-display text-5xl font-extrabold" x-text="'RM'+p.price"></span><span class="pb-1.5 text-sm text-slate-500">/ 3 months</span></div>
          <p class="mt-1 text-xs text-slate-400" x-text="'≈ RM '+Math.round(p.price/3)+' / month equivalent'"></p>
          <ul class="mt-6 space-y-3 text-sm text-slate-600">
            <template x-for="f in p.features" :key="f"><li class="flex gap-2.5"><svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"><use href="#i-check"/></svg><span x-text="f"></span></li></template>
          </ul>
          <button class="mt-8 w-full" :class="p.popular?'btn-primary':'btn-ghost'" @click="subscribe(p)">Subscribe</button>
        </div>
      </template>
    </div>
  </section>

  <!-- FAQ -->
  <section id="faq" class="bg-white py-24">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
      <div class="text-center"><span class="eyebrow">FAQ</span><h2 class="mt-5 font-display text-4xl font-extrabold tracking-tight">Questions, answered.</h2></div>
      <div class="mt-12 space-y-3">
        <template x-for="(q,i) in faqs" :key="i">
          <div class="card overflow-hidden">
            <button class="flex w-full items-center justify-between px-6 py-5 text-left font-semibold" @click="faqOpen=faqOpen===i?null:i">
              <span x-text="q.q"></span><svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform" :class="faqOpen===i?'rotate-180':''"><use href="#i-chev"/></svg>
            </button>
            <div x-show="faqOpen===i" class="px-6 pb-5 text-sm leading-relaxed text-slate-600" x-text="q.a"></div>
          </div>
        </template>
      </div>
    </div>
  </section>

  <!-- CTA + FOOTER -->
  <section class="mx-auto max-w-7xl px-4 py-24 sm:px-6">
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-brand-700 via-brand-600 to-violet-600 p-12 text-center shadow-2xl shadow-brand-600/30 sm:p-16">
      <div class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/10 blur-2xl"></div>
      <h2 class="font-display text-4xl font-extrabold text-white">Start your 30-day free trial today.</h2>
      <p class="mx-auto mt-4 max-w-xl text-brand-100">Sign in with Google — your trial is provisioned instantly on Supabase PostgreSQL. No card required.</p>
      <button class="btn-primary mt-8 bg-none bg-white !from-white px-8 py-3.5 text-base !text-brand-700 shadow-xl" @click="session?go('dashboard'):(authOpen=true)">Create my account<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
    </div>
  </section>
  <footer class="border-t border-slate-200 bg-white py-10">
    <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 sm:px-6 md:flex-row">
      <div class="flex items-center gap-2"><span class="grid h-7 w-7 place-items-center rounded-lg bg-gradient-to-br from-brand-600 to-violet-500 text-white"><svg class="h-4 w-4"><use href="#i-clouddollar"/></svg></span><span class="font-display font-bold">AZ Kejora SaaS</span></div>
      <p class="text-xs text-slate-400">© 2025 AZ Kejora SaaS · PHP 8.2 / Laravel · Supabase PostgreSQL · Stripe Webhooks</p>
      <div class="flex gap-5 text-xs font-semibold text-slate-500"><a href="#features" class="hover:text-brand-700">Features</a><a href="#pricing" class="hover:text-brand-700">Pricing</a><a href="#faq" class="hover:text-brand-700">FAQ</a></div>
    </div>
  </footer>
</div>

<!-- ============ AUTH MODAL (Google OAuth) ============ -->
<div x-show="authOpen" x-cloak class="fixed inset-0 z-[70] grid place-items-center bg-ink/50 p-4 backdrop-blur-sm" @click.self="authOpen=false">
  <div class="anim-pop w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl">
    <div class="flex flex-col items-center text-center">
      <svg class="h-8 w-8"><use href="#i-google"/></svg>
      <h3 class="mt-4 font-display text-xl font-bold">Sign in with Google</h3>
      <p class="mt-1 text-sm text-slate-500">to continue to <b class="text-brand-700">AZ Kejora SaaS</b></p>
    </div>
    <div class="mt-6 divide-y divide-slate-100 rounded-2xl border border-slate-200">
      <template x-for="a in accounts" :key="a.email">
        <button class="flex w-full items-center gap-3 px-5 py-4 text-left transition hover:bg-slate-50" @click="signIn(a)">
          <span class="grid h-10 w-10 place-items-center rounded-full text-sm font-bold text-white" :class="a.color" x-text="initials(a.name)"></span>
          <span class="flex-1"><span class="block text-sm font-semibold" x-text="a.name"></span><span class="block text-xs text-slate-500" x-text="a.email"></span></span>
          <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide" :class="a.role==='admin'?'bg-rose-50 text-rose-600':(db.customers.some(c=>c.email===a.email)?'bg-slate-100 text-slate-500':'bg-emerald-50 text-emerald-600')" x-text="a.role==='admin'?'Admin':(db.customers.some(c=>c.email===a.email)?'Existing':'New · 30d trial')"></span>
        </button>
      </template>
    </div>
    <p class="mt-5 text-center text-xs leading-relaxed text-slate-400">First sign-in provisions your customer record and starts a <b>30-day free trial</b> (<code>trial_ends_at = now() + 30d</code>, status <code>active_trial</code>).</p>
    <button class="btn-ghost mt-4 w-full" @click="authOpen=false">Cancel</button>
  </div>
</div>

<!-- ============ PAYMENT MODAL ============ -->
<div x-show="payOpen" x-cloak class="fixed inset-0 z-[80] grid place-items-center bg-ink/60 p-4 backdrop-blur-sm">
  <div class="anim-pop w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl">
    <div class="bg-gradient-to-r from-brand-700 to-violet-600 px-7 py-5 text-white">
      <div class="flex items-center justify-between">
        <h3 class="font-display text-lg font-bold" x-text="payTitle()"></h3>
        <button x-show="payStage==='form'" class="opacity-70 hover:opacity-100" @click="closePay()"><svg class="h-5 w-5"><use href="#i-x"/></svg></button>
      </div>
      <p class="mt-0.5 text-sm text-brand-100" x-text="paySubtitle()"></p>
    </div>
    <div class="p-7">
      <!-- FORM -->
      <div x-show="payStage==='form'">
        <div class="mb-5 flex rounded-xl bg-slate-100 p-1">
          <button class="flex-1 rounded-lg py-2 text-sm font-semibold transition" :class="payMethod==='card'?'bg-white shadow':'text-slate-500'" @click="payMethod='card'">💳 Card (Stripe)</button>
          <button class="flex-1 rounded-lg py-2 text-sm font-semibold transition" :class="payMethod==='fpx'?'bg-white shadow':'text-slate-500'" @click="payMethod='fpx'">🏦 FPX</button>
          <button class="flex-1 rounded-lg py-2 text-sm font-semibold transition" :class="payMethod==='toyyib'?'bg-white shadow':'text-slate-500'" @click="payMethod='toyyib'">📱 ToyyibPay</button>
        </div>
        <div x-show="payMethod==='card'" class="space-y-3">
          <div><label class="label">Card number</label><input class="input" placeholder="4242 4242 4242 4242" x-model="card.num" inputmode="numeric"></div>
          <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2"><label class="label">Name on card</label><input class="input" placeholder="AINA RAHMAN" x-model="card.name"></div>
            <div><label class="label">Exp / CVC</label><input class="input" placeholder="12/27 · 123" x-model="card.exp"></div>
          </div>
        </div>
        <div x-show="payMethod==='fpx'">
          <label class="label">Select your bank</label>
          <select class="input" x-model="fpxBank">
            <option>Maybank2u</option><option>CIMB Clicks</option><option>Public Bank</option><option>RHB Now</option><option>Hong Leong</option><option>Bank Islam</option>
          </select>
          <p class="mt-2 text-xs text-slate-400">You'll be redirected to your bank's FPX page and returned on success.</p>
        </div>
        <div x-show="payMethod==='toyyib'" class="rounded-xl bg-slate-50 p-5 text-center">
          <div class="mx-auto grid h-32 w-32 place-items-center rounded-xl bg-white shadow-inner"><div class="h-24 w-24 rounded-lg" style="background:repeating-conic-gradient(#131327 0 25%, #fff 0 50%) 0 0/12px 12px"></div></div>
          <p class="mt-3 text-xs text-slate-500">Scan the DuitNow QR with any banking app to approve payment.</p>
        </div>
        <div class="mt-6 flex items-center justify-between rounded-xl bg-slate-50 px-5 py-4">
          <span class="text-sm text-slate-500">Total due today</span>
          <span class="font-display text-2xl font-extrabold" x-text="money(payTotal())"></span>
        </div>
        <button class="btn-primary mt-5 w-full py-3.5" @click="processPayment()">Pay <span x-text="money(payTotal())"></span> securely<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
        <p class="mt-3 text-center text-[11px] text-slate-400">🔒 PCI-DSS · 3-D Secure · Webhooks: <code>invoice.paid</code>, <code>payment_intent.succeeded</code></p>
      </div>
      <!-- PROCESSING -->
      <div x-show="payStage==='processing'" class="py-6 text-center">
        <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600"></div>
        <p class="mt-4 text-sm font-semibold text-slate-600">Contacting gateway…</p>
        <div class="mx-auto mt-4 max-w-xs space-y-1.5 text-left font-mono text-[11px] text-slate-500">
          <template x-for="(l,i) in payLog" :key="i"><p class="anim-view" x-text="'✓ '+l"></p></template>
        </div>
      </div>
      <!-- SUCCESS -->
      <div x-show="payStage==='success'" class="py-10 text-center">
        <div class="anim-pop mx-auto grid h-16 w-16 place-items-center rounded-full bg-emerald-100 text-emerald-600"><svg class="h-8 w-8"><use href="#i-check"/></svg></div>
        <h4 class="mt-4 font-display text-xl font-bold">Payment successful</h4>
        <p class="mt-1 text-sm text-slate-500">Subscription state synced via webhook.</p>
      </div>
    </div>
  </div>
</div>

<!-- ============ CUSTOMER HISTORY DRAWER ============ -->
<div x-show="drawerCust" x-cloak class="fixed inset-0 z-[75] bg-ink/40 backdrop-blur-sm" @click.self="drawerCust=null">
  <aside class="anim-view absolute right-0 top-0 h-full w-full max-w-md overflow-y-auto bg-white p-7 shadow-2xl">
    <template x-if="drawerCust">
      <div>
        <div class="flex items-center justify-between">
          <h3 class="font-display text-lg font-bold">Transaction history</h3>
          <button class="text-slate-400 hover:text-slate-600" @click="drawerCust=null"><svg class="h-5 w-5"><use href="#i-x"/></svg></button>
        </div>
        <div class="mt-4 flex items-center gap-3 rounded-2xl bg-slate-50 p-4">
          <span class="grid h-11 w-11 place-items-center rounded-full text-sm font-bold text-white" :class="avCls(drawerCust.email)" x-text="initials(drawerCust.name)"></span>
          <div><p class="font-semibold" x-text="drawerCust.name"></p><p class="text-xs text-slate-500" x-text="drawerCust.email"></p></div>
          <span class="ml-auto rounded-full px-3 py-1 text-[10px] font-bold uppercase" :class="badgeCls(drawerCust.status)" x-text="badgeLabel(drawerCust.status)"></span>
        </div>
        <div class="mt-5 space-y-3">
          <template x-for="t in custTx(drawerCust.id)" :key="t.id">
            <div class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3">
              <div><p class="text-sm font-semibold" x-text="t.description"></p><p class="text-xs text-slate-400" x-text="fmtD(t.date)+' · '+t.method"></p></div>
              <span class="font-display text-sm font-bold" x-text="money(t.amount)"></span>
            </div>
          </template>
          <p x-show="custTx(drawerCust.id).length===0" class="py-8 text-center text-sm text-slate-400">No transactions yet.</p>
        </div>
        <div class="mt-5 flex items-center justify-between rounded-xl bg-brand-50 px-5 py-4"><span class="text-sm font-semibold text-brand-700">Lifetime value</span><span class="font-display text-lg font-extrabold text-brand-700" x-text="money(ltv(drawerCust.id))"></span></div>
      </div>
    </template>
  </aside>
</div>

<!-- ================================================== APP SHELL ================================================== -->
<div x-show="session && view!=='landing'" class="min-h-screen">
  <!-- Mobile topbar -->
  <div class="sticky top-0 z-40 flex items-center justify-between border-b border-slate-200 bg-white/80 px-4 py-3 backdrop-blur lg:hidden">
    <button class="btn-ghost !px-3" @click="sidebarOpen=!sidebarOpen"><svg class="h-5 w-5"><use href="#i-menu"/></svg></button>
    <span class="font-display font-bold">AZ Kejora<span class="grad-text"> SaaS</span></span>
    <span class="grid h-9 w-9 place-items-center rounded-full text-xs font-bold text-white" :class="session?avCls(session.email):''" x-text="session?initials(session.name):''"></span>
  </div>
  <div x-show="sidebarOpen" class="fixed inset-0 z-40 bg-ink/40 lg:hidden" @click="sidebarOpen=false"></div>

  <!-- Sidebar -->
  <aside class="fixed inset-y-0 left-0 z-50 w-72 transform border-r border-slate-200 bg-white transition-transform duration-300 lg:translate-x-0"
         :class="sidebarOpen?'translate-x-0':'-translate-x-full'">
    <div class="flex h-full flex-col p-5">
      <button class="flex items-center gap-2.5 px-2 py-2" @click="go('landing')">
        <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg shadow-brand-600/30"><svg class="h-5 w-5"><use href="#i-clouddollar"/></svg></span>
        <span class="font-display text-lg font-bold">AZ Kejora<span class="grad-text"> SaaS</span></span>
      </button>
      <nav class="mt-6 flex-1 space-y-1 overflow-y-auto">
        <template x-if="session && session.role!=='admin'">
          <div class="space-y-1">
            <p class="px-3 pb-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">Workspace</p>
            <button class="navitem" :class="view==='dashboard'?'navitem-on':''" @click="go('dashboard');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-home"/></svg>Dashboard</button>
            <button class="navitem" :class="view==='billing'?'navitem-on':''" @click="go('billing');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-card"/></svg>Billing portal</button>
            <p class="px-3 pb-1 pt-4 text-[10px] font-bold uppercase tracking-widest text-slate-400">Tools</p>
            <button class="navitem" :class="view==='einvoice'?'navitem-on':''" @click="go('einvoice');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-receipt"/></svg>E-Invoice</button>
            <button class="navitem" :class="view==='merchant'?'navitem-on':''" @click="go('merchant');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-building"/></svg>Facilities</button>
            <button class="navitem" :class="view==='portal'?'navitem-on':''" @click="go('portal');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-cal"/></svg>Booking portal</button>
            <button class="navitem" :class="view==='mybookings'?'navitem-on':''" @click="go('mybookings');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-clock"/></svg>My bookings</button>
          </div>
        </template>
        <template x-if="session && session.role==='admin'">
          <div class="space-y-1">
            <p class="px-3 pb-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">Administration</p>
            <button class="navitem" :class="view==='admin'?'navitem-on':''" @click="go('admin');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-chart"/></svg>Analytics</button>
            <button class="navitem" :class="view==='admin-customers'?'navitem-on':''" @click="go('admin-customers');sidebarOpen=false"><svg class="h-5 w-5"><use href="#i-users"/></svg>Customers</button>
          </div>
        </template>
      </nav>
      <div x-show="session && session.role!=='admin' && me() && me().status==='active_trial'" class="rounded-2xl bg-gradient-to-br from-brand-600 to-violet-600 p-4 text-white shadow-lg shadow-brand-600/25">
        <p class="text-xs font-bold uppercase tracking-wide text-brand-100">Free trial</p>
        <p class="mt-1 font-display text-2xl font-extrabold"><span x-text="trialLeft()"></span> days left</p>
        <div class="mt-2 h-1.5 rounded-full bg-white/25"><div class="h-1.5 rounded-full bg-white" :style="'width:'+trialPct()+'%'"></div></div>
        <button class="mt-3 w-full rounded-lg bg-white py-2 text-xs font-bold text-brand-700 transition hover:bg-brand-50" @click="subscribe(plans[1])">Subscribe now</button>
      </div>
      <div class="mt-4 flex items-center gap-3 rounded-2xl border border-slate-200 p-3">
        <span class="grid h-10 w-10 place-items-center rounded-full text-xs font-bold text-white" :class="session?avCls(session.email):''" x-text="session?initials(session.name):''"></span>
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-bold" x-text="session?session.name:''"></p><p class="truncate text-[11px] text-slate-400" x-text="session?session.email:''"></p></div>
        <button class="text-slate-400 hover:text-rose-500" title="Sign out" @click="signOut()"><svg class="h-5 w-5"><use href="#i-logout"/></svg></button>
      </div>
      <p class="mt-3 px-1 text-center text-[10px] text-slate-400">v2.4.1 · <span class="dot-live text-emerald-500">●</span> Supabase PG connected</p>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="lg:pl-72">
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-10">

      <!-- ========== CUSTOMER DASHBOARD ========== -->
      <div x-show="view==='dashboard'" class="anim-view space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h1 class="font-display text-3xl font-extrabold">Welcome back, <span class="grad-text" x-text="session?session.name.split(' ')[0]:''"></span> 👋</h1>
          <p class="mt-1 text-sm text-slate-500">Here's what's happening across your services today.</p></div>
          <button class="btn-primary" @click="go('einvoice')"><svg class="h-4 w-4"><use href="#i-upload"/></svg>New invoice upload</button>
        </div>
        <div x-show="me() && me().status==='active_trial'" class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-brand-700 to-violet-600 p-6 text-white shadow-xl shadow-brand-600/25">
          <div class="pointer-events-none absolute -right-10 -top-14 h-48 w-48 rounded-full bg-white/10 blur-xl"></div>
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div><p class="text-xs font-bold uppercase tracking-widest text-brand-100">Active trial · no card required</p>
              <p class="mt-1 font-display text-2xl font-extrabold"><span x-text="trialLeft()"></span> days remaining</p>
              <p class="text-sm text-brand-100">Trial ends <span x-text="me()?fmtD(me().trialEnds):''"></span> — subscribe to keep your data & tools.</p></div>
            <div class="w-full max-w-[220px]"><div class="h-2 rounded-full bg-white/25"><div class="h-2 rounded-full bg-white" :style="'width:'+trialPct()+'%'"></div></div>
              <button class="btn-primary mt-3 w-full bg-none bg-white !from-white !text-brand-700" @click="subscribe(plans[1])">Choose a 3-month plan</button></div>
          </div>
        </div>
        <div x-show="me() && me().status==='past_due'" class="flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-700">
          <svg class="h-6 w-6 shrink-0"><use href="#i-alert"/></svg><p class="text-sm font-semibold">Your last payment failed — subscription is past due. Update your method to restore access.</p>
          <button class="btn-danger ml-auto !bg-rose-600 !text-white hover:!bg-rose-700" @click="renewNow()">Pay now</button>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Plan status</p><p class="mt-2 font-display text-xl font-extrabold" x-text="me()?badgeLabel(me().status):''"></p><p class="mt-1 text-xs text-slate-500" x-text="me()?(me().plan||'—')+' · '+money(me().price||0)+' / 90d':''"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400" x-text="me()&&me().status==='active_trial'?'Trial ends':'Renews on'"></p><p class="mt-2 font-display text-xl font-extrabold" x-text="me()?fmtD(me().status==='active_trial'?me().trialEnds:(me().periodEnds||me().trialEnds)):''"></p><p class="mt-1 text-xs text-slate-500" x-text="me()&&me().status==='active'?periodLeft()+' days left in cycle':''"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Invoices processed</p><p class="mt-2 font-display text-xl font-extrabold" x-text="db.einvoice.items.length"></p><p class="mt-1 text-xs text-slate-500" x-text="money(einTotals().gross)+' gross value'"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">My bookings</p><p class="mt-2 font-display text-xl font-extrabold" x-text="myBookings().length"></p><p class="mt-1 text-xs text-slate-500">upcoming & past</p></div>
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
          <div class="card group relative overflow-hidden p-7 transition hover:-translate-y-0.5 hover:shadow-soft">
            <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-amber-100 blur-2xl"></div>
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 text-white shadow-lg"><svg class="h-6 w-6"><use href="#i-receipt"/></svg></span>
            <h3 class="mt-4 font-display text-xl font-bold">E-Invoice for SME</h3>
            <p class="mt-2 text-sm text-slate-500">Upload CSV / PDF / JSON, auto-extract line items, compute SST and export compliance reports.</p>
            <button class="btn-soft mt-5" @click="go('einvoice')">Open tool<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
          </div>
          <div class="card group relative overflow-hidden p-7 transition hover:-translate-y-0.5 hover:shadow-soft">
            <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-fuchsia-100 blur-2xl"></div>
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-fuchsia-500 to-pink-500 text-white shadow-lg"><svg class="h-6 w-6"><use href="#i-cal"/></svg></span>
            <h3 class="mt-4 font-display text-xl font-bold">Retail Facility Booking</h3>
            <p class="mt-2 text-sm text-slate-500">Manage your facilities & rates, or book courts, rooms and halls on the public portal.</p>
            <div class="mt-5 flex gap-2"><button class="btn-soft" @click="go('merchant')">Merchant console</button><button class="btn-ghost" @click="go('portal')">Public portal</button></div>
          </div>
        </div>
      </div>

      <!-- ========== BILLING ========== -->
      <div x-show="view==='billing'" class="anim-view space-y-6">
        <div><h1 class="font-display text-3xl font-extrabold">Billing portal</h1><p class="mt-1 text-sm text-slate-500">Manage your 3-month subscription, invoices and payment methods.</p></div>
        <div class="grid gap-6 lg:grid-cols-3">
          <div class="card p-6 lg:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Current plan</p>
                <p class="mt-1 font-display text-2xl font-extrabold" x-text="me()?(me().plan||'Free trial'):'—'"></p>
                <p class="text-sm text-slate-500" x-text="me()&&me().price?money(me().price)+' per 3-month cycle':'No active subscription yet'"></p></div>
              <span class="rounded-full px-3.5 py-1.5 text-xs font-bold uppercase" :class="me()?badgeCls(me().status):''" x-text="me()?badgeLabel(me().status):''"></span>
            </div>
            <div class="mt-5" x-show="me() && me().status==='active' && me().periodEnds">
              <div class="flex justify-between text-xs font-semibold text-slate-500"><span>Cycle progress</span><span x-text="periodLeft()+' days left'"></span></div>
              <div class="mt-1.5 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-gradient-to-r from-brand-600 to-violet-500" :style="'width:'+(100-periodPct())+'%'"></div></div>
              <p class="mt-2 text-xs text-slate-400">Next renewal <b x-text="me()?fmtD(me().periodEnds):''"></b> · auto-charged via webhook <code>invoice.paid</code></p>
            </div>
            <div class="mt-6 flex flex-wrap gap-2">
              <button class="btn-primary" @click="renewNow()"><svg class="h-4 w-4"><use href="#i-refresh"/></svg>Renew 90 days</button>
              <button class="btn-ghost" x-show="nextPlan()" @click="subscribe(nextPlan(),'upgrade')"><svg class="h-4 w-4"><use href="#i-arrow"/></svg>Upgrade to <span x-text="nextPlan()?nextPlan().name:''"></span></button>
            </div>
          </div>
          <div class="card p-6">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Payment method</p>
            <div class="mt-4 rounded-2xl bg-gradient-to-br from-ink to-slate-700 p-5 text-white shadow-lg">
              <div class="flex justify-between"><span class="font-display text-sm font-bold tracking-widest">VISA</span><svg class="h-5 w-5 opacity-70"><use href="#i-card"/></svg></div>
              <p class="mt-5 font-mono text-sm tracking-widest">•••• •••• •••• <span x-text="me()&&me().card?me().card.last4:'4242'"></span></p>
              <div class="mt-3 flex justify-between text-[11px] text-slate-300"><span x-text="session?session.name.toUpperCase():''"></span><span x-text="me()&&me().card?me().card.exp:'12/27'"></span></div>
            </div>
            <button class="btn-ghost mt-4 w-full" @click="startCheckout({kind:'method'})">Update card</button>
          </div>
        </div>
        <div class="card overflow-hidden">
          <div class="flex items-center justify-between px-6 py-4"><h3 class="font-display font-bold">Past invoices</h3><span class="text-xs text-slate-400" x-text="myInvoices().length+' records'"></span></div>
          <div class="overflow-x-auto"><table class="w-full">
            <thead class="border-y border-slate-100 bg-slate-50/60"><tr><th class="th">Invoice</th><th class="th">Date</th><th class="th">Description</th><th class="th">Method</th><th class="th">Amount</th><th class="th">Status</th><th class="th"></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="inv in myInvoices()" :key="inv.id">
                <tr class="hover:bg-slate-50/60"><td class="td font-mono text-xs font-bold text-brand-700" x-text="inv.invoice"></td><td class="td" x-text="fmtD(inv.date)"></td><td class="td" x-text="inv.description"></td><td class="td" x-text="inv.method"></td><td class="td font-semibold" x-text="money(inv.amount)"></td><td class="td"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase text-emerald-600">Paid</span></td><td class="td"><button class="btn-soft !px-3 !py-1.5 !text-xs" @click="downloadInvoice(inv)"><svg class="h-3.5 w-3.5"><use href="#i-download"/></svg>PDF</button></td></tr>
              </template>
            </tbody>
          </table></div>
          <p x-show="myInvoices().length===0" class="px-6 py-10 text-center text-sm text-slate-400">No invoices yet — subscribe to generate your first one.</p>
        </div>
      </div>

      <!-- ========== E-INVOICE ========== -->
      <div x-show="view==='einvoice'" class="anim-view space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h1 class="font-display text-3xl font-extrabold">E-Invoice studio</h1><p class="mt-1 text-sm text-slate-500">Upload, extract, analyse and export — LHDN compliance included.</p></div>
          <div class="flex flex-wrap gap-2">
            <button class="btn-ghost" @click="loadSample()"><svg class="h-4 w-4"><use href="#i-spark"/></svg>Load sample</button>
            <button class="btn-ghost" :disabled="db.einvoice.items.length===0" @click="exportXLSX()"><svg class="h-4 w-4"><use href="#i-download"/></svg>Excel</button>
            <button class="btn-primary" :disabled="db.einvoice.items.length===0" @click="exportPDF()"><svg class="h-4 w-4"><use href="#i-download"/></svg>PDF report</button>
          </div>
        </div>
        <div class="relative rounded-2xl border-2 border-dashed p-10 text-center transition-all duration-200"
             :class="dragOver?'border-brand-500 bg-brand-50':'border-slate-300 bg-white'"
             @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="onDrop($event)">
          <input type="file" id="filePick" class="hidden" multiple accept=".csv,.json,.pdf" @change="handleFiles($event.target.files); $event.target.value=''">
          <template x-if="!extracting">
            <div>
              <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-100 text-brand-600"><svg class="h-7 w-7"><use href="#i-upload"/></svg></span>
              <p class="mt-3 font-display font-bold">Drop invoice files here</p>
              <p class="mt-1 text-sm text-slate-500">CSV · PDF · JSON — smart extraction runs automatically</p>
              <button class="btn-soft mt-4" @click="document.getElementById('filePick').click()">Browse files</button>
            </div>
          </template>
          <template x-if="extracting">
            <div><div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600"></div><p class="mt-3 text-sm font-semibold text-brand-700">Extracting line items & computing tax…</p></div>
          </template>
        </div>
        <div x-show="db.einvoice.files.length" class="flex flex-wrap gap-2">
          <template x-for="f in db.einvoice.files" :key="f.name">
            <span class="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-600"><svg class="h-3.5 w-3.5 text-brand-500"><use href="#i-file"/></svg><span x-text="f.name"></span><span class="text-slate-400" x-text="f.rows+' rows'"></span></span>
          </template>
          <button class="text-xs font-semibold text-rose-500 hover:underline" @click="clearEinvoice()">Clear all</button>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Gross total</p><p class="mt-2 font-display text-2xl font-extrabold" x-text="money(einTotals().gross)"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Total SST</p><p class="mt-2 font-display text-2xl font-extrabold text-emerald-600" x-text="money(einTotals().tax)"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Line items</p><p class="mt-2 font-display text-2xl font-extrabold" x-text="db.einvoice.items.length"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Compliance score</p><p class="mt-2 font-display text-2xl font-extrabold" :class="einTotals().score>=80?'text-emerald-600':'text-amber-500'" x-text="einTotals().score+'%'"></p></div>
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
          <div class="card p-6 lg:col-span-2"><h3 class="font-display font-bold">Revenue by category</h3><div class="mt-4 h-64"><canvas id="einChart"></canvas></div></div>
          <div class="card p-6">
            <h3 class="font-display font-bold">Compliance checks</h3>
            <div class="mt-4 space-y-3 text-sm">
              <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3"><span class="flex items-center gap-2"><svg class="h-4 w-4 text-emerald-500"><use href="#i-check"/></svg>Valid TIN</span><b x-text="einTotals().tinOk+'/'+db.einvoice.items.length"></b></div>
              <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3"><span class="flex items-center gap-2"><svg class="h-4 w-4 text-emerald-500"><use href="#i-check"/></svg>Validated by LHDN</span><b x-text="einTotals().validated"></b></div>
              <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3"><span class="flex items-center gap-2"><svg class="h-4 w-4 text-amber-500"><use href="#i-clock"/></svg>Pending</span><b x-text="db.einvoice.items.length-einTotals().validated"></b></div>
            </div>
          </div>
        </div>
        <div class="card overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
            <h3 class="font-display font-bold">Extracted invoices</h3>
            <div class="relative"><svg class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"><use href="#i-search"/></svg><input class="input !w-56 !pl-9" placeholder="Search ref / description…" x-model="einSearch"></div>
          </div>
          <div class="overflow-x-auto"><table class="w-full">
            <thead class="border-y border-slate-100 bg-slate-50/60"><tr><th class="th">Ref</th><th class="th">Date</th><th class="th">Description</th><th class="th">Category</th><th class="th">TIN</th><th class="th">Amount</th><th class="th">SST</th><th class="th">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="it in einFiltered()" :key="it.id">
                <tr class="hover:bg-slate-50/60"><td class="td font-mono text-xs font-bold text-brand-700" x-text="it.ref"></td><td class="td" x-text="fmtD(it.date)"></td><td class="td" x-text="it.desc"></td><td class="td"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500" x-text="it.category"></span></td><td class="td font-mono text-xs" x-text="it.tin"></td><td class="td font-semibold" x-text="money(it.amount)"></td><td class="td text-emerald-600" x-text="money(it.tax)"></td><td class="td"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase" :class="it.status==='validated'?'bg-emerald-50 text-emerald-600':'bg-amber-50 text-amber-600'" x-text="it.status"></span></td></tr>
              </template>
            </tbody>
          </table></div>
          <p x-show="db.einvoice.items.length===0" class="px-6 py-10 text-center text-sm text-slate-400">No invoices yet — drop a file above or load the sample dataset.</p>
        </div>
      </div>

      <!-- ========== MERCHANT ========== -->
      <div x-show="view==='merchant'" class="anim-view space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h1 class="font-display text-3xl font-extrabold">Facility console</h1><p class="mt-1 text-sm text-slate-500">Add facilities, set hourly rates and manage incoming bookings.</p></div>
          <button class="btn-primary" @click="showFacForm=!showFacForm"><svg class="h-4 w-4"><use href="#i-plus"/></svg>Add facility</button>
        </div>
        <div x-show="showFacForm" class="card anim-pop grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-6">
          <div class="lg:col-span-2"><label class="label">Facility name</label><input class="input" placeholder="e.g. Court 3 — Arena" x-model="facForm.name"></div>
          <div><label class="label">Type</label><select class="input" x-model="facForm.type"><option value="court">Sports court</option><option value="room">Meeting room</option><option value="hall">Event hall</option></select></div>
          <div><label class="label">Rate (RM/hr)</label><input type="number" class="input" x-model.number="facForm.rate"></div>
          <div><label class="label">Open – Close</label><div class="flex gap-2"><input type="number" class="input" x-model.number="facForm.openHour" min="0" max="23"><input type="number" class="input" x-model.number="facForm.closeHour" min="1" max="24"></div></div>
          <div class="flex items-end"><button class="btn-primary w-full" @click="addFacility()">Save facility</button></div>
        </div>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <template x-for="f in db.facilities" :key="f.id">
            <div class="card overflow-hidden transition hover:-translate-y-0.5 hover:shadow-soft">
              <div class="relative h-40"><img :src="facImage(f.type)" class="h-full w-full object-cover" alt="">
                <span class="absolute left-3 top-3 rounded-full bg-white/90 px-3 py-1 text-[10px] font-bold uppercase tracking-wide text-ink backdrop-blur" x-text="f.type"></span>
                <button class="absolute right-3 top-3 grid h-8 w-8 place-items-center rounded-full bg-white/90 backdrop-blur transition" :class="f.active?'text-emerald-600':'text-slate-400'" :title="f.active?'Active — click to pause':'Paused — click to activate'" @click="toggleFacility(f)">
                  <svg class="h-4 w-4"><use :href="f.active?'#i-pause':'#i-play'"/></svg>
                </button>
              </div>
              <div class="p-5">
                <div class="flex items-center justify-between"><h3 class="font-display font-bold" x-text="f.name"></h3><span class="font-display font-extrabold text-brand-700" x-text="'RM'+f.rate+'/hr'"></span></div>
                <p class="mt-1 text-xs text-slate-500" x-text="fmtHour(f.openHour)+' – '+fmtHour(f.closeHour)+' · '+f.intervalMin+'-min slots'"></p>
                <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                  <span class="text-xs text-slate-500"><b x-text="facBookingsToday(f)"></b> bookings today</span>
                  <button class="btn-danger" @click="deleteFacility(f)"><svg class="h-3.5 w-3.5"><use href="#i-trash"/></svg></button>
                </div>
              </div>
            </div>
          </template>
        </div>
        <div class="card overflow-hidden">
          <div class="px-6 py-4"><h3 class="font-display font-bold">Booking requests</h3></div>
          <div class="overflow-x-auto"><table class="w-full">
            <thead class="border-y border-slate-100 bg-slate-50/60"><tr><th class="th">Facility</th><th class="th">Customer</th><th class="th">Date</th><th class="th">Slot</th><th class="th">Amount</th><th class="th">Status</th><th class="th">Actions</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="b in db.bookings.slice().sort((a,b)=>a.date<b.date?1:-1)" :key="b.id">
                <tr class="hover:bg-slate-50/60">
                  <td class="td font-semibold" x-text="facName(b.facilityId)"></td><td class="td" x-text="b.customerName"></td><td class="td" x-text="fmtD(b.date)"></td><td class="td font-mono text-xs" x-text="b.slot"></td><td class="td font-semibold" x-text="money(b.amount)"></td>
                  <td class="td"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase" :class="badgeCls(b.status)" x-text="b.status"></span></td>
                  <td class="td"><div class="flex gap-1.5" x-show="b.status==='pending'">
                    <button class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-600 hover:bg-emerald-100" @click="setBooking(b,'confirmed')">Approve</button>
                    <button class="btn-danger" @click="setBooking(b,'canceled')">Decline</button>
                  </div></td>
                </tr>
              </template>
            </tbody>
          </table></div>
        </div>
      </div>

      <!-- ========== PORTAL ========== -->
      <div x-show="view==='portal'" class="anim-view space-y-6">
        <div><h1 class="font-display text-3xl font-extrabold">Booking portal</h1><p class="mt-1 text-sm text-slate-500">Pick a facility, choose your slots, check out instantly.</p></div>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <template x-for="f in db.facilities.filter(x=>x.active)" :key="f.id">
            <button class="card overflow-hidden text-left transition hover:-translate-y-1 hover:shadow-soft" :class="selFacility&&selFacility.id===f.id?'ring-2 ring-brand-500':''" @click="selFacility=f; selSlots=[]; $nextTick(()=>document.getElementById('slotPanel')?.scrollIntoView({behavior:'smooth',block:'start'}))">
              <div class="h-36"><img :src="facImage(f.type)" class="h-full w-full object-cover" alt=""></div>
              <div class="p-5"><div class="flex justify-between"><h3 class="font-display font-bold" x-text="f.name"></h3><span class="font-bold text-brand-700" x-text="'RM'+f.rate+'/hr'"></span></div><p class="mt-1 text-xs text-slate-500" x-text="fmtHour(f.openHour)+' – '+fmtHour(f.closeHour)"></p></div>
            </button>
          </template>
        </div>
        <div id="slotPanel" x-show="selFacility" class="card p-6">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <h3 class="font-display text-lg font-bold">Availability — <span x-text="selFacility?selFacility.name:''"></span></h3>
            <div><label class="label !mb-0">Date</label><input type="date" class="input !w-44" :min="todayISO()" x-model="selDate" @change="selSlots=[]"></div>
          </div>
          <div class="mt-5 grid grid-cols-4 gap-2 sm:grid-cols-7 lg:grid-cols-10">
            <template x-for="s in slotsFor(selFacility)" :key="s">
              <button class="slot text-center" :class="slotCls(s)" :disabled="slotCls(s)==='slot-off'" x-text="s" @click="toggleSlot(s)"></button>
            </template>
          </div>
          <div class="mt-4 flex gap-5 text-[11px] font-semibold text-slate-500"><span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-slate-200 bg-white"></span>Available</span><span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-brand-600"></span>Selected</span><span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded stripe-off border border-slate-100"></span>Booked</span></div>
          <div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-slate-50 px-6 py-4">
            <div><p class="text-xs text-slate-500" x-text="selSlots.length+' slot(s) selected · '+fmtD(selDate)"></p><p class="font-display text-2xl font-extrabold" x-text="money(bookingTotal())"></p></div>
            <button class="btn-primary" :disabled="selSlots.length===0" @click="checkoutBooking()">Checkout<svg class="h-4 w-4"><use href="#i-arrow"/></svg></button>
          </div>
        </div>
      </div>

      <!-- ========== MY BOOKINGS ========== -->
      <div x-show="view==='mybookings'" class="anim-view space-y-6">
        <div><h1 class="font-display text-3xl font-extrabold">My bookings</h1><p class="mt-1 text-sm text-slate-500">Every reservation tied to your account.</p></div>
        <div class="grid gap-4">
          <template x-for="b in myBookings()" :key="b.id">
            <div class="card flex flex-wrap items-center gap-4 p-5">
              <span class="grid h-12 w-12 place-items-center rounded-xl bg-brand-100 text-brand-600"><svg class="h-6 w-6"><use href="#i-cal"/></svg></span>
              <div class="min-w-0 flex-1"><p class="font-display font-bold" x-text="facName(b.facilityId)"></p><p class="text-sm text-slate-500" x-text="fmtD(b.date)+' · '+b.slot+' · '+money(b.amount)"></p></div>
              <span class="rounded-full px-3 py-1 text-[10px] font-bold uppercase" :class="badgeCls(b.status)" x-text="b.status"></span>
              <button class="btn-danger" x-show="b.status!=='canceled'" @click="cancelBooking(b)">Cancel</button>
            </div>
          </template>
          <p x-show="myBookings().length===0" class="card p-10 text-center text-sm text-slate-400">No bookings yet — head to the portal and grab a slot.</p>
        </div>
      </div>

      <!-- ========== ADMIN OVERVIEW ========== -->
      <div x-show="view==='admin'" class="anim-view space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h1 class="font-display text-3xl font-extrabold">Admin analytics</h1><p class="mt-1 text-sm text-slate-500">Revenue, subscriptions and platform health at a glance.</p></div>
          <div class="flex gap-2 text-[11px] font-bold"><span class="flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-600"><span class="dot-live h-1.5 w-1.5 rounded-full bg-emerald-500"></span>invoice.paid</span><span class="flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-600"><span class="dot-live h-1.5 w-1.5 rounded-full bg-emerald-500"></span>payment_intent.succeeded</span></div>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">MRR</p><p class="mt-2 font-display text-2xl font-extrabold" x-text="money(kpis().mrr)"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">ARR</p><p class="mt-2 font-display text-2xl font-extrabold text-brand-700" x-text="money(kpis().arr)"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Active subs</p><p class="mt-2 font-display text-2xl font-extrabold" x-text="kpis().active"></p><p class="text-xs text-slate-400" x-text="kpis().trials+' in trial'"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Trial conversion</p><p class="mt-2 font-display text-2xl font-extrabold text-emerald-600" x-text="kpis().conv+'%'"></p></div>
          <div class="card p-5"><p class="text-xs font-bold uppercase tracking-wide text-slate-400">Churn rate</p><p class="mt-2 font-display text-2xl font-extrabold text-rose-500" x-text="kpis().churn+'%'"></p></div>
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
          <div class="card p-6 lg:col-span-2"><h3 class="font-display font-bold">Recurring revenue — trailing 12 months</h3><div class="mt-4 h-72"><canvas id="revChart"></canvas></div></div>
          <div class="card p-6"><h3 class="font-display font-bold">Subscription mix</h3><div class="mt-4 h-72"><canvas id="statusChart"></canvas></div></div>
        </div>
        <div class="card overflow-hidden">
          <div class="px-6 py-4"><h3 class="font-display font-bold">Recent transactions</h3></div>
          <div class="overflow-x-auto"><table class="w-full">
            <thead class="border-y border-slate-100 bg-slate-50/60"><tr><th class="th">Customer</th><th class="th">Date</th><th class="th">Description</th><th class="th">Method</th><th class="th">Amount</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="t in db.transactions.slice().sort((a,b)=>a.date<b.date?1:-1).slice(0,8)" :key="t.id">
                <tr class="hover:bg-slate-50/60"><td class="td font-semibold" x-text="custName(t.customerId)"></td><td class="td" x-text="fmtD(t.date)"></td><td class="td" x-text="t.description"></td><td class="td" x-text="t.method"></td><td class="td font-semibold" x-text="money(t.amount)"></td></tr>
              </template>
            </tbody>
          </table></div>
        </div>
      </div>

      <!-- ========== ADMIN CUSTOMERS ========== -->
      <div x-show="view==='admin-customers'" class="anim-view space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h1 class="font-display text-3xl font-extrabold">Customer management</h1><p class="mt-1 text-sm text-slate-500">Trials, subscriptions, suspensions and histories.</p></div>
          <div class="relative"><svg class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"><use href="#i-search"/></svg><input class="input !w-64 !pl-9" placeholder="Search name or email…" x-model="admSearch"></div>
        </div>
        <div class="card overflow-hidden"><div class="overflow-x-auto"><table class="w-full">
          <thead class="border-y border-slate-100 bg-slate-50/60"><tr><th class="th">Customer</th><th class="th">Plan</th><th class="th">Status</th><th class="th">Trial / period ends</th><th class="th">LTV</th><th class="th text-right">Actions</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <template x-for="c in admFiltered()" :key="c.id">
              <tr class="hover:bg-slate-50/60">
                <td class="td"><div class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-full text-xs font-bold text-white" :class="avCls(c.email)" x-text="initials(c.name)"></span><div><p class="font-semibold" x-text="c.name"></p><p class="text-xs text-slate-400" x-text="c.email"></p></div></div></td>
                <td class="td" x-text="(c.plan||'—')+(c.price?' · '+money(c.price)+'/90d':'')"></td>
                <td class="td"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase" :class="badgeCls(c.status)" x-text="badgeLabel(c.status)"></span></td>
                <td class="td text-xs" x-text="c.status==='active_trial'?fmtD(c.trialEnds):(c.periodEnds?fmtD(c.periodEnds):'—')"></td>
                <td class="td font-semibold" x-text="money(ltv(c.id))"></td>
                <td class="td"><div class="flex justify-end gap-1.5">
                  <button class="rounded-lg bg-brand-50 px-2.5 py-1.5 text-[11px] font-bold text-brand-700 hover:bg-brand-100" title="Extend trial +7d" @click="extendTrial(c,7)">+7d</button>
                  <button class="rounded-lg bg-brand-50 px-2.5 py-1.5 text-[11px] font-bold text-brand-700 hover:bg-brand-100" title="Extend trial +30d" @click="extendTrial(c,30)">+30d</button>
                  <button class="rounded-lg px-2.5 py-1.5 text-[11px] font-bold" :class="c.status==='suspended'?'bg-emerald-50 text-emerald-600 hover:bg-emerald-100':'bg-rose-50 text-rose-600 hover:bg-rose-100'" @click="toggleSuspend(c)" x-text="c.status==='suspended'?'Activate':'Suspend'"></button>
                  <button class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] font-bold text-slate-600 hover:bg-slate-200" @click="drawerCust=c">History</button>
                </div></td>
              </tr>
            </template>
          </tbody>
        </table></div></div>
      </div>

    </div>
  </main>
</div>

<script>
function kejora(){
  const IMG={court:'https://image.qwenlm.ai/public_source/de418ccf-8560-403d-9fee-894122daa9d6/11ee91956-a643-41de-9d77-f08130fb005d.png',room:'https://image.qwenlm.ai/public_source/de418ccf-8560-403d-9fee-894122daa9d6/1d47cdc71-f2c7-451b-b07a-6f4e2e21374b.png',hall:'https://image.qwenlm.ai/public_source/de418ccf-8560-403d-9fee-894122daa9d6/1cc59605c-02c9-4bcf-a29e-255ba7533583.png'};
  const todayISO=()=>new Date().toISOString().slice(0,10);
  const addDaysISO=(iso,d)=>{const x=new Date(iso);x.setDate(x.getDate()+d);return x.toISOString().slice(0,10);};
  const uid=()=>Math.random().toString(36).slice(2,9);
  function seed(){
    const t=todayISO();let inv=100;
    const T=(cid,days,amt,method,desc,kind)=>({id:uid(),customerId:cid,date:addDaysISO(t,-days),amount:amt,method,status:'succeeded',description:desc,kind:kind||'subscription',invoice:kind==='booking'?null:('INV-2025-0'+(++inv))});
    const customers=[
      {id:'c2',name:'Daniel Wong',email:'daniel@luminares.co',plan:'Growth',price:210,status:'active',trialEnds:addDaysISO(t,-170),periodEnds:addDaysISO(t,52),createdAt:addDaysISO(t,-200),card:{last4:'4242',exp:'12/27'}},
      {id:'c3',name:'Nurul Izzah',email:'nurul@kedairakyat.my',plan:'Starter',price:90,status:'active',periodEnds:addDaysISO(t,20),createdAt:addDaysISO(t,-160)},
      {id:'c4',name:'Marcus Teo',email:'marcus@fitzone.asia',plan:'Scale',price:450,status:'active',periodEnds:addDaysISO(t,74),createdAt:addDaysISO(t,-300)},
      {id:'c5',name:'Priya Nair',email:'priya@bloomscafe.com',plan:'Starter',price:90,status:'active',periodEnds:addDaysISO(t,8),createdAt:addDaysISO(t,-120)},
      {id:'c6',name:'Hafiz Rahman',email:'hafiz@servispro.my',plan:'Growth',price:210,status:'past_due',periodEnds:addDaysISO(t,-2),createdAt:addDaysISO(t,-220)},
      {id:'c7',name:'Elena Cruz',email:'elena@studiocrux.co',plan:'Scale',price:450,status:'canceled',periodEnds:addDaysISO(t,-40),createdAt:addDaysISO(t,-350)},
      {id:'c8',name:'Amirul Fikri',email:'amirul@tanjungsports.my',plan:null,price:0,status:'active_trial',trialEnds:addDaysISO(t,21),createdAt:addDaysISO(t,-9)},
      {id:'c9',name:'Grace Lim',email:'grace@petalworks.sg',plan:null,price:0,status:'active_trial',trialEnds:addDaysISO(t,9),createdAt:addDaysISO(t,-21)},
      {id:'c10',name:'Ryan Ong',email:'ryan@quicklane.io',plan:'Starter',price:90,status:'canceled',periodEnds:addDaysISO(t,-70),createdAt:addDaysISO(t,-190)},
      {id:'c11',name:'AZ Kejora Ops',email:'admin@azkejora.io',plan:'Scale',price:450,status:'active',periodEnds:addDaysISO(t,60),createdAt:addDaysISO(t,-400)}
    ];
    const transactions=[
      T('c2',16,210,'Stripe','Growth — 3-month cycle'),T('c2',106,210,'Stripe','Growth — 3-month cycle'),T('c2',196,210,'Stripe','Growth — 3-month cycle'),
      T('c3',40,90,'FPX','Starter — 3-month cycle'),T('c3',130,90,'FPX','Starter — 3-month cycle'),
      T('c4',15,450,'Stripe','Scale — 3-month cycle'),T('c4',105,450,'Stripe','Scale — 3-month cycle'),
      T('c5',82,90,'ToyyibPay','Starter — 3-month cycle'),
      T('c6',95,210,'FPX','Growth — 3-month cycle'),
      T('c7',170,450,'Stripe','Scale — 3-month cycle'),
      T('c10',150,90,'Stripe','Starter — 3-month cycle'),
      T('c11',30,450,'Stripe','Scale — 3-month cycle'),
      {id:uid(),customerId:'c2',date:addDaysISO(t,-5),amount:36,method:'FPX',status:'succeeded',description:'Booking — Badminton Court',kind:'booking',invoice:null},
      {id:uid(),customerId:'c4',date:addDaysISO(t,-12),amount:90,method:'Stripe',status:'succeeded',description:'Booking — Meeting Suite',kind:'booking',invoice:null},
      {id:uid(),customerId:'c3',date:addDaysISO(t,-20),amount:220,method:'FPX',status:'succeeded',description:'Booking — Pavilion Hall',kind:'booking',invoice:null}
    ];
    return {customers,transactions,
      facilities:[
        {id:'f1',name:'Badminton Court 1',type:'court',rate:18,openHour:8,closeHour:22,intervalMin:60,active:true},
        {id:'f2',name:'Glass Meeting Suite',type:'room',rate:45,openHour:9,closeHour:18,intervalMin:60,active:true},
        {id:'f3',name:'The Pavilion Hall',type:'hall',rate:220,openHour:8,closeHour:23,intervalMin:120,active:true}
      ],
      bookings:[
        {id:uid(),facilityId:'f1',customerName:'Daniel Wong',customerEmail:'daniel@luminares.co',date:t,slot:'10:00',status:'confirmed',amount:18},
        {id:uid(),facilityId:'f1',customerName:'Marcus Teo',customerEmail:'marcus@fitzone.asia',date:t,slot:'18:00',status:'confirmed',amount:18},
        {id:uid(),facilityId:'f1',customerName:'Grace Lim',customerEmail:'grace@petalworks.sg',date:addDaysISO(t,1),slot:'09:00',status:'pending',amount:18},
        {id:uid(),facilityId:'f2',customerName:'Priya Nair',customerEmail:'priya@bloomscafe.com',date:t,slot:'14:00',status:'confirmed',amount:45},
        {id:uid(),facilityId:'f3',customerName:'Nurul Izzah',customerEmail:'nurul@kedairakyat.my',date:addDaysISO(t,3),slot:'10:00',status:'confirmed',amount:440}
      ],
      einvoice:{files:[],items:[]},
      revenueSeries:[1840,1960,2050,2210,2140,2380,2520,2610,2760,2905,3040,3185]
    };
  }
  let db;try{db=JSON.parse(localStorage.getItem('azkejora_db_v1'))}catch(e){db=null}
  if(!db||!db.customers){db=seed();localStorage.setItem('azkejora_db_v1',JSON.stringify(db))}
  return {
    IMG,view:'landing',session:JSON.parse(localStorage.getItem('azkejora_session')||'null'),db,
    authOpen:false,payOpen:false,payCtx:null,payMethod:'card',payStage:'form',payLog:[],card:{num:'',name:'',exp:''},fpxBank:'Maybank2u',
    drawerCust:null,sidebarOpen:false,toasts:[],charts:{},
    selFacility:null,selDate:todayISO(),selSlots:[],showFacForm:false,
    facForm:{name:'',type:'court',rate:25,openHour:8,closeHour:22,intervalMin:60},
    dragOver:false,extracting:false,einSearch:'',admSearch:'',faqOpen:0,
    plans:[
      {name:'Starter',price:90,tag:'For solo operators',popular:false,features:['1 business entity','E-Invoice: 200 docs / cycle','1 bookable facility','Standard reports (PDF)','Email support']},
      {name:'Growth',price:210,tag:'For growing SMEs',popular:true,features:['3 business entities','E-Invoice: unlimited docs','10 facilities + public portal','SST & compliance exports','Priority support']},
      {name:'Scale',price:450,tag:'For multi-site retailers',popular:false,features:['Unlimited entities','Unlimited facilities','API + webhook access','Custom roles & audit log','Dedicated manager']}
    ],
    faqs:[
      {q:'How does the 30-day free trial work?',a:'Sign in with Google and we provision your customer record instantly (status active_trial, trial_ends_at = now + 30 days). Full access to E-Invoice and Booking tools — no credit card required. We prompt you to subscribe before expiry.'},
      {q:'Why a 3-month billing cycle?',a:'Quarterly billing keeps pricing predictable for SMEs and cuts admin overhead. On every successful payment our Stripe / FPX / ToyyibPay webhooks (invoice.paid, payment_intent.succeeded) advance current_period_ends_at by 90 days automatically.'},
      {q:'Which invoice file formats are supported?',a:'CSV, PDF and JSON. Our extraction engine parses line items, computes SST 6%/8% totals, validates LHDN TINs and produces downloadable PDF / Excel compliance reports.'},
      {q:'Which payment methods do you accept?',a:'Cards via Stripe (3-D Secure), FPX online banking (Maybank2u, CIMB Clicks, Public Bank and more) and ToyyibPay DuitNow QR — all with local compliance built in.'},
      {q:'Can I cancel or change plans?',a:'Yes. Upgrades apply immediately and prorate into your next cycle; cancellations stop auto-renewal at period end. Admins can also extend trials or suspend accounts manually.'}
    ],
    accounts:[
      {name:'Aina Rahman',email:'aina@gmail.com',color:'bg-gradient-to-br from-brand-500 to-violet-500',role:'customer'},
      {name:'Daniel Wong',email:'daniel@luminares.co',color:'bg-gradient-to-br from-emerald-500 to-teal-500',role:'customer'},
      {name:'AZ Kejora Admin',email:'admin@azkejora.io',color:'bg-gradient-to-br from-rose-500 to-orange-500',role:'admin'}
    ],
    /* ---------- core ---------- */
    init(){
      if(typeof Chart!=='undefined'){Chart.defaults.font.family="'Inter',sans-serif";Chart.defaults.color='#94a3b8';}
      const apply=()=>{const h=location.hash.replace(/^#\/?/,'');const prot=['dashboard','billing','einvoice','merchant','portal','mybookings','admin','admin-customers'];
        if(prot.includes(h)){if(!this.session){this.authOpen=true;this.view='landing';}else{this.view=(h.startsWith('admin')&&this.session.role!=='admin')?(this.session.role==='admin'?'admin':'dashboard'):h;this.renderCharts();}}
        else{this.view='landing';}};
      window.addEventListener('hashchange',apply);apply();
    },
    go(v){if(v!=='landing'&&!this.session){this.authOpen=true;return;}location.hash='/'+v;this.view=v;this.sidebarOpen=false;window.scrollTo({top:0});this.renderCharts();},
    save(){localStorage.setItem('azkejora_db_v1',JSON.stringify(this.db))},
    toast(msg,type){const id=uid();this.toasts.push({id,msg,type:type||'success'});setTimeout(()=>{this.toasts=this.toasts.filter(x=>x.id!==id)},4000)},
    me(){return this.session?this.db.customers.find(c=>c.email===this.session.email):null},
    signIn(a){
      let c=this.db.customers.find(x=>x.email===a.email);
      if(!c){c={id:uid(),name:a.name,email:a.email,plan:null,price:0,status:'active_trial',trialEnds:addDaysISO(todayISO(),30),createdAt:todayISO()};this.db.customers.push(c);this.save();this.toast('Account provisioned — 30-day free trial started 🎉');}
      else this.toast('Welcome back, '+a.name.split(' ')[0]+'!');
      this.session={name:a.name,email:a.email,role:a.role};localStorage.setItem('azkejora_session',JSON.stringify(this.session));
      this.authOpen=false;this.go(a.role==='admin'?'admin':'dashboard');
    },
    signOut(){this.session=null;localStorage.removeItem('azkejora_session');this.go('landing');this.toast('Signed out','info')},
    /* ---------- helpers ---------- */
    todayISO,addDaysISO,
    fmtD(iso){return iso?new Date(iso).toLocaleDateString('en-MY',{day:'2-digit',month:'short',year:'numeric'}):'—'},
    fmtHour(h){return String(h).padStart(2,'0')+':00'},
    money(n){return 'RM '+(n||0).toLocaleString('en-MY',{maximumFractionDigits:0})},
    initials(n){return n.split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase()},
    avCls(e){const cols=['bg-brand-500','bg-emerald-500','bg-amber-500','bg-rose-500','bg-violet-500','bg-teal-500'];let s=0;for(const ch of e)s+=ch.charCodeAt(0);return cols[s%cols.length]},
    badgeCls(s){return{active:'bg-emerald-50 text-emerald-600',confirmed:'bg-emerald-50 text-emerald-600',validated:'bg-emerald-50 text-emerald-600',active_trial:'bg-brand-50 text-brand-700',past_due:'bg-amber-50 text-amber-600',pending:'bg-amber-50 text-amber-600',canceled:'bg-slate-100 text-slate-500',suspended:'bg-rose-50 text-rose-600'}[s]||'bg-slate-100 text-slate-500'},
    badgeLabel(s){return{active:'Active',active_trial:'Active trial',past_due:'Past due',canceled:'Canceled',suspended:'Suspended',confirmed:'Confirmed',pending:'Pending',validated:'Validated'}[s]||s},
    trialLeft(){const m=this.me();if(!m||!m.trialEnds)return 0;return Math.max(0,Math.ceil((new Date(m.trialEnds)-new Date())/86400000))},
    trialPct(){const m=this.me();if(!m)return 0;return Math.min(100,Math.round((30-this.trialLeft())/30*100))},
    periodLeft(){const m=this.me();if(!m||!m.periodEnds)return 0;return Math.max(0,Math.ceil((new Date(m.periodEnds)-new Date())/86400000))},
    periodPct(){return Math.min(100,Math.round((90-this.periodLeft())/90*100))},
    /* ---------- subscription / payments ---------- */
    subscribe(p,mode){if(!this.session){this.authOpen=true;this.toast('Sign in with Google to subscribe','info');return;}this.startCheckout({kind:'subscription',plan:p,mode:mode||'new'})},
    renewNow(){const m=this.me();if(!m)return;this.startCheckout({kind:'subscription',plan:{name:m.plan||'Growth',price:m.price||210},mode:'renew'})},
    nextPlan(){const m=this.me();if(!m)return null;const order=['Starter','Growth','Scale'];const i=order.indexOf(m.plan);return i>=0&&i<2?this.plans[i+1]:null},
    startCheckout(ctx){this.payCtx=ctx;this.payMethod='card';this.payStage='form';this.payLog=[];this.payOpen=true},
    closePay(){this.payOpen=false;this.payCtx=null;this.payStage='form';this.payLog=[]},
    payTitle(){if(!this.payCtx)return'';const k=this.payCtx.kind;return k==='subscription'?(this.payCtx.mode==='upgrade'?'Upgrade subscription':this.payCtx.mode==='renew'?'Renew subscription':'Subscribe — 3-month cycle'):(k==='booking'?'Booking checkout':'Update payment method')},
    paySubtitle(){if(!this.payCtx)return'';if(this.payCtx.kind==='subscription')return this.payCtx.plan.name+' · billed every 90 days';if(this.payCtx.kind==='booking')return this.payCtx.facility.name+' · '+this.fmtD(this.payCtx.date);return 'Stored on your profile via Stripe vault'},
    payTotal(){if(!this.payCtx)return 0;if(this.payCtx.kind==='subscription')return this.payCtx.plan.price;if(this.payCtx.kind==='booking')return this.payCtx.total;return 0},
    processPayment(){
      if(this.payStage!=='form')return;
      if(this.payMethod==='card'&&this.payCtx.kind!=='booking'&&this.payCtx.kind!=='method'){
        if(this.card.num.replace(/\s/g,'').length<12||!this.card.name){this.toast('Please complete card details','error');return;}
      }
      this.payStage='processing';this.payLog=[];
      setTimeout(()=>this.payLog.push('checkout.session.completed — gateway OK'),250);
      setTimeout(()=>this.payLog.push('payment_intent.succeeded · '+(this.payMethod==='card'?'Stripe':this.payMethod==='fpx'?this.fpxBank:'ToyyibPay')),800);
      setTimeout(()=>this.payLog.push('invoice.paid — webhook verified ✓'),1350);
      setTimeout(()=>{this.applyPayment();this.payStage='success';setTimeout(()=>this.closePay(),1000)},1900);
    },
    applyPayment(){
      const m=this.me();const ctx=this.payCtx;const method=this.payMethod==='card'?'Stripe':this.payMethod==='fpx'?this.fpxBank:'ToyyibPay';
      if(ctx.kind==='subscription'&&m){
        m.plan=ctx.plan.name;m.price=ctx.plan.price;m.status='active';
        const base=(ctx.mode==='renew'&&m.periodEnds&&m.periodEnds>todayISO())?m.periodEnds:todayISO();
        m.periodEnds=addDaysISO(base,90);
        this.db.transactions.push({id:uid(),customerId:m.id,date:todayISO(),amount:ctx.plan.price,method,status:'succeeded',description:ctx.plan.name+' — 3-month cycle',kind:'subscription',invoice:'INV-2025-'+Math.floor(100+Math.random()*899)});
        this.save();this.toast('Subscription active — period extended +90 days 🎉');this.go('billing');
      }else if(ctx.kind==='booking'&&this.session){
        ctx.slots.forEach(s=>this.db.bookings.push({id:uid(),facilityId:ctx.facility.id,customerName:this.session.name,customerEmail:this.session.email,date:ctx.date,slot:s,status:'confirmed',amount:ctx.facility.rate}));
        this.db.transactions.push({id:uid(),customerId:m?m.id:'c2',date:todayISO(),amount:ctx.total,method,status:'succeeded',description:'Booking — '+ctx.facility.name,kind:'booking',invoice:null});
        this.selSlots=[];this.save();this.toast('Booking confirmed — see you there! 🏟️');this.go('mybookings');
      }else if(ctx.kind==='method'&&m){
        m.card={last4:(this.card.num.replace(/\s/g,'').slice(-4)||'4242'),exp:this.card.exp||'12/27'};this.save();this.toast('Payment method updated');
      }
    },
    myInvoices(){const m=this.me();if(!m)return[];return this.db.transactions.filter(t=>t.customerId===m.id&&t.kind==='subscription').sort((a,b)=>a.date<b.date?1:-1)},
    downloadInvoice(inv){
      const {jsPDF}=window.jspdf;const d=new jsPDF();
      d.setFont('helvetica','bold');d.setFontSize(20);d.setTextColor(84,87,229);d.text('AZ Kejora SaaS',14,20);
      d.setFontSize(10);d.setTextColor(100);d.text('E-Invoice & Facility Booking Platform',14,27);
      d.setDrawColor(220);d.line(14,32,196,32);
      d.setFontSize(12);d.setTextColor(20);d.text('TAX INVOICE '+inv.invoice,14,42);
      d.setFontSize(10);d.setTextColor(80);
      d.text('Billed to: '+(this.session?this.session.name:''),14,52);
      d.text('Date: '+this.fmtD(inv.date),14,58);d.text('Method: '+inv.method,14,64);d.text('Status: PAID',14,70);
      d.line(14,76,196,76);d.text('Description: '+inv.description,14,84);
      d.setFontSize(14);d.setTextColor(20);d.text('Total: '+this.money(inv.amount),14,96);
      d.setFontSize(8);d.setTextColor(150);d.text('Generated automatically via webhook invoice.paid · Supabase PostgreSQL',14,280);
      d.save(inv.invoice+'.pdf');this.toast('Invoice downloaded');
    },
    /* ---------- e-invoice ---------- */
    onDrop(e){this.dragOver=false;this.handleFiles(e.dataTransfer.files)},
    handleFiles(list){
      const files=Array.from(list||[]);if(!files.length)return;this.extracting=true;
      let done=0;
      files.forEach(f=>{
        const ext=(f.name.split('.').pop()||'').toLowerCase();
        const finish=rows=>{this.db.einvoice.files.push({name:f.name,rows:rows.length});this.db.einvoice.items.push(...rows);done++;if(done===files.length){this.extracting=false;this.save();this.renderCharts();this.toast(rows.length?files.length+' file(s) extracted':'Extraction complete')}};
        if(ext==='csv')f.text().then(txt=>finish(this.rowsFromCSV(txt,f.name)));
        else if(ext==='json')f.text().then(txt=>{try{const j=JSON.parse(txt);finish(this.normalize(Array.isArray(j)?j:(j.items||[]),f.name))}catch(e){finish(this.mockRows(f.name))}});
        else finish(this.mockRows(f.name));
      });
    },
    normalize(arr,fn){return arr.slice(0,60).map((r,i)=>({id:uid(),ref:(r.ref||'INV-'+fn.slice(0,3).toUpperCase()+i),date:r.date||todayISO(),desc:r.desc||r.description||'Imported line item',category:r.category||'Services',tin:r.tin||'C'+(201000000000+i*7),amount:+r.amount||0,tax:+r.tax||Math.round((+r.amount||0)*0.08),status:r.status||(Math.random()>0.25?'validated':'pending')}))},
    rowsFromCSV(txt,fn){
      const lines=txt.trim().split(/\r?\n/);if(lines.length<2)return this.mockRows(fn);
      const parse=l=>{const out=[];let cur='',q=false;for(let i=0;i<l.length;i++){const c=l[i];if(q){if(c==='"'&&l[i+1]==='"'){cur+='"';i++}else if(c==='"')q=false;else cur+=c}else{if(c==='"')q=true;else if(c===',')out.push(cur),cur='';else cur+=c}}out.push(cur);return out};
      const head=parse(lines[0]).map(h=>h.toLowerCase());
      const idx=k=>head.findIndex(h=>h.includes(k));
      const iA=idx('amount'),iD=idx('desc'),iC=idx('categ'),iT=idx('tin'),iX=idx('tax'),iR=idx('ref'),iDt=idx('date');
      const rows=lines.slice(1).map((l,i)=>{const c=parse(l);const amt=parseFloat((c[iA]||'').replace(/[^\d.]/g,''))||0;return{id:uid(),ref:(iR>=0?c[iR]:'CSV-'+i),date:(iDt>=0&&c[iDt])||todayISO(),desc:(iD>=0?c[iD]:'Line item')||'Line item',category:(iC>=0?c[iC]:'Services')||'Services',tin:(iT>=0?c[iT]:'')||'',amount:amt,tax:(iX>=0?parseFloat(c[iX])||0:Math.round(amt*0.08)),status:Math.random()>0.25?'validated':'pending'}});
      return rows.length?rows:this.mockRows(fn);
    },
    mockRows(fn){let h=0;for(const ch of fn)h=(h*31+ch.charCodeAt(0))%997;const cats=['F&B','Retail','Services','Logistics'];const out=[];const n=6+h%5;
      for(let i=0;i<n;i++){const amt=120+((h*(i+3))%880);out.push({id:uid(),ref:'EXT-'+(h%90+10)+'-'+i,date:addDaysISO(todayISO(),-((h+i*3)%28)),desc:['Catering order','Retail sale','Consulting fee','Delivery charge','Equipment rental'][i%5],category:cats[i%4],tin:i%6===5?'':'C20'+(1234567890+i*13),amount:amt,tax:Math.round(amt*(i%2?0.06:0.08)),status:i%5===4?'pending':'validated'})}return out},
    loadSample(){if(this.db.einvoice.items.length){this.toast('Sample already loaded','info');return}
      const rows=this.mockRows('sample_dataset.csv').concat(this.mockRows('q3_sales.pdf'));
      this.db.einvoice.files.push({name:'sample_dataset.csv',rows:rows.length/2},{name:'q3_sales.pdf',rows:rows.length/2});
      this.db.einvoice.items.push(...rows);this.save();this.renderCharts();this.toast('Sample dataset loaded — 2 files extracted')},
    clearEinvoice(){this.db.einvoice={files:[],items:[]};this.save();this.renderCharts();this.toast('E-Invoice workspace cleared','info')},
    einFiltered(){const q=this.einSearch.toLowerCase();return this.db.einvoice.items.filter(i=>!q||i.ref.toLowerCase().includes(q)||i.desc.toLowerCase().includes(q)||i.category.toLowerCase().includes(q))},
    einTotals(){const its=this.db.einvoice.items;const gross=its.reduce((s,i)=>s+i.amount,0);const tax=its.reduce((s,i)=>s+i.tax,0);const tinOk=its.filter(i=>i.tin).length;const validated=its.filter(i=>i.status==='validated').length;return{gross,tax,tinOk,validated,score:its.length?Math.round(validated/its.length*100):0}},
    einCats(){const m={};this.db.einvoice.items.forEach(i=>{m[i.category]=(m[i.category]||0)+i.amount});return Object.entries(m).map(([name,total])=>({name,total}))},
    exportXLSX(){if(!this.db.einvoice.items.length)return this.toast('Nothing to export','error');
      const rows=this.db.einvoice.items.map(i=>({Ref:i.ref,Date:i.date,Description:i.desc,Category:i.category,TIN:i.tin||'MISSING',Amount_RM:i.amount,SST_RM:i.tax,Status:i.status}));
      const ws=XLSX.utils.json_to_sheet(rows);ws['!cols']=[{wch:12},{wch:12},{wch:28},{wch:12},{wch:16},{wch:10},{wch:8},{wch:10}];
      const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'E-Invoice Report');XLSX.writeFile(wb,'AZKejora_EInvoice_Report.xlsx');this.toast('Excel report downloaded')},
    exportPDF(){if(!this.db.einvoice.items.length)return this.toast('Nothing to export','error');
      const {jsPDF}=window.jspdf;const d=new jsPDF();const t=this.einTotals();
      d.setFontSize(18);d.setTextColor(84,87,229);d.text('AZ Kejora SaaS — E-Invoice Report',14,18);
      d.setFontSize(9);d.setTextColor(100);d.text('Generated '+this.fmtD(todayISO())+' · Gross '+this.money(t.gross)+' · SST '+this.money(t.tax)+' · Compliance '+t.score+'%',14,25);
      d.setDrawColor(220);d.line(14,29,196,29);let y=36;d.setFontSize(8);
      this.db.einvoice.items.forEach(i=>{if(y>280){d.addPage();y=20}
        d.setTextColor(30);d.text(i.ref+'  '+this.fmtD(i.date),14,y);d.setTextColor(90);d.text((i.desc||'').slice(0,40),60,y);d.text(i.category,120,y);d.text(this.money(i.amount),150,y);d.text(i.status.toUpperCase(),180,y);y+=6});
      d.save('AZKejora_EInvoice_Report.pdf');this.toast('PDF report downloaded')},
    /* ---------- booking ---------- */
    facImage(t){return this.IMG[t]||this.IMG.court},
    facName(id){const f=this.db.facilities.find(x=>x.id===id);return f?f.name:'—'},
    facBookingsToday(f){return this.db.bookings.filter(b=>b.facilityId===f.id&&b.date===todayISO()&&b.status!=='canceled').length},
    addFacility(){if(!this.facForm.name){this.toast('Give the facility a name','error');return}
      this.db.facilities.push({id:uid(),name:this.facForm.name,type:this.facForm.type,rate:this.facForm.rate||20,openHour:+this.facForm.openHour||8,closeHour:+this.facForm.closeHour||22,intervalMin:+this.facForm.intervalMin||60,active:true});
      this.facForm.name='';this.showFacForm=false;this.save();this.toast('Facility published to the portal 🏟️')},
    toggleFacility(f){f.active=!f.active;this.save();this.toast(f.name+(f.active?' activated':' paused'),'info')},
    deleteFacility(f){this.db.facilities=this.db.facilities.filter(x=>x.id!==f.id);this.db.bookings=this.db.bookings.filter(b=>b.facilityId!==f.id);if(this.selFacility&&this.selFacility.id===f.id)this.selFacility=null;this.save();this.toast('Facility removed','info')},
    slotsFor(f){if(!f)return[];const out=[];for(let m=f.openHour*60;m<f.closeHour*60;m+=f.intervalMin)out.push(String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0'));return out},
    slotCls(s){if(!this.selFacility)return'slot-free';
      const booked=this.db.bookings.some(b=>b.facilityId===this.selFacility.id&&b.date===this.selDate&&b.slot===s&&b.status!=='canceled');
      if(booked)return'slot-off';
      if(this.selDate===todayISO()){const[h,mm]=s.split(':').map(Number);if(h*60+mm<=new Date().getHours()*60+new Date().getMinutes())return'slot-off'}
      return this.selSlots.includes(s)?'slot-on':'slot-free'},
    toggleSlot(s){this.selSlots=this.selSlots.includes(s)?this.selSlots.filter(x=>x!==s):[...this.selSlots,s].sort()},
    bookingTotal(){return this.selSlots.length*(this.selFacility?this.selFacility.rate:0)},
    checkoutBooking(){if(!this.session){this.authOpen=true;this.toast('Sign in with Google to complete your booking','info');return}
      this.startCheckout({kind:'booking',facility:this.selFacility,date:this.selDate,slots:[...this.selSlots],total:this.bookingTotal()})},
    myBookings(){if(!this.session)return[];return this.db.bookings.filter(b=>b.customerEmail===this.session.email).sort((a,b)=>a.date<b.date?1:-1)},
    cancelBooking(b){b.status='canceled';this.save();this.toast('Booking canceled','info')},
    setBooking(b,st){b.status=st;this.save();this.toast(st==='confirmed'?'Booking approved':'Booking declined','info')},
    /* ---------- admin ---------- */
    kpis(){const c=this.db.customers;const act=c.filter(x=>x.status==='active');const mrr=act.reduce((s,x)=>s+(x.price||0)/3,0);
      return{mrr:Math.round(mrr),arr:Math.round(mrr*12),active:act.length,trials:c.filter(x=>x.status==='active_trial').length,conv:Math.round(act.length/c.length*100),churn:Math.round(c.filter(x=>x.status==='canceled').length/c.length*100)}},
    admFiltered(){const q=this.admSearch.toLowerCase();return this.db.customers.filter(c=>!q||c.name.toLowerCase().includes(q)||c.email.toLowerCase().includes(q))},
    custTx(id){return this.db.transactions.filter(t=>t.customerId===id).sort((a,b)=>a.date<b.date?1:-1)},
    ltv(id){return this.custTx(id).reduce((s,t)=>s+t.amount,0)},
    custName(id){const c=this.db.customers.find(x=>x.id===id);return c?c.name:'Guest'},
    extendTrial(c,days){c.trialEnds=addDaysISO(c.trialEnds&&c.trialEnds>todayISO()?c.trialEnds:todayISO(),days);if(c.status!=='active')c.status='active_trial';this.save();this.toast('Trial extended +'+days+' days for '+c.name)},
    toggleSuspend(c){if(c.status==='suspended'){c.status=(c.periodEnds&&c.periodEnds>todayISO())?'active':'past_due';this.toast(c.name+' re-activated')}else{c.status='suspended';this.toast(c.name+' suspended','error')}this.save()},
    monthLabels(){const a=[];const d=new Date();for(let i=11;i>=0;i--)a.push(new Date(d.getFullYear(),d.getMonth()-i,1).toLocaleString('en',{month:'short'}));return a},
    /* ---------- charts ---------- */
    mk(id,cfg){const el=document.getElementById(id);if(!el||typeof Chart==='undefined')return;if(this.charts[id])this.charts[id].destroy();this.charts[id]=new Chart(el,cfg)},
    renderCharts(){this.$nextTick(()=>{
      if(this.view==='admin'){
        this.mk('revChart',{type:'line',data:{labels:this.monthLabels(),datasets:[{label:'Revenue (RM)',data:this.db.revenueSeries,borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.12)',fill:true,tension:.4,borderWidth:2.5,pointRadius:0,pointHoverRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{grid:{color:'#eef1f6'}},x:{grid:{display:false}}}}});
        const dist={active:0,active_trial:0,past_due:0,suspended:0,canceled:0};this.db.customers.forEach(c=>dist[c.status]=(dist[c.status]||0)+1);
        this.mk('statusChart',{type:'doughnut',data:{labels:Object.keys(dist),datasets:[{data:Object.values(dist),backgroundColor:['#10b981','#6366f1','#f59e0b','#f43f5e','#cbd5e1'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{position:'bottom',labels:{usePointStyle:true,boxWidth:7}}}}});
      }
      if(this.view==='einvoice'&&this.db.einvoice.items.length){
        const cats=this.einCats();
        this.mk('einChart',{type:'bar',data:{labels:cats.map(c=>c.name),datasets:[{label:'Gross (RM)',data:cats.map(c=>c.total),backgroundColor:['#6366f1','#8b5cf6','#10b981','#f59e0b','#f43f5e'],borderRadius:10,barThickness:34}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{grid:{color:'#eef1f6'}},x:{grid:{display:false}}}}});
      }
    })}
  };
}
</script>
</body>
</html>
