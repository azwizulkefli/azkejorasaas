<?php
session_start();
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
$userRole = $isLoggedIn ? $_SESSION['user_role'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AZ Kejora SaaS — E-Invoice & Facility Booking Platform</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/700.css">
<script>
tailwind.config = {
theme: { extend: {
fontFamily: { display:['Sora','sans-serif'], sans:['Inter','sans-serif'] },
colors: { ink:'#131327', brand:{50:'#eef1ff',100:'#e0e5ff',500:'#6366f1',600:'#5457e5',700:'#4644cf'} },
boxShadow: { soft:'0 8px 30px -12px rgba(19,19,39,.15)' }
} }
}
</script>
<style type="text/tailwindcss">
.btn-primary{@apply inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/25 transition-all duration-200 hover:-translate-y-0.5 cursor-pointer whitespace-nowrap;}
.btn-ghost{@apply inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition-all duration-200 hover:border-brand-300 cursor-pointer whitespace-nowrap;}
.card{@apply bg-white rounded-2xl border border-slate-200/70 shadow-soft;}
.input{@apply w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-ink outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10;}
.label{@apply mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500;}
.eyebrow{@apply inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-bold uppercase tracking-widest text-brand-700;}
.grad-text{@apply bg-gradient-to-r from-brand-600 via-violet-500 to-fuchsia-500 bg-clip-text text-transparent;}
</style>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js" defer></script>
</head>
<body class="bg-[#F6F7FB] text-ink antialiased" x-data="{authOpen: false}">
<!-- NAV -->
<header class="fixed inset-x-0 top-0 z-40">
<div class="mx-auto max-w-7xl px-4 sm:px-6">
<div class="mt-4 flex items-center justify-between rounded-2xl border border-white/60 bg-white/75 px-5 py-3 shadow-soft backdrop-blur-xl">
<button class="flex items-center gap-2.5">
<span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg">⚡</span>
<span class="font-display text-lg font-bold tracking-tight">AZ Kejora<span class="grad-text"> SaaS</span></span>
</button>
<div class="flex items-center gap-2">
<?php if ($isLoggedIn): ?>
    <span class="text-sm font-semibold text-slate-600 mr-2">Hi, <?= htmlspecialchars(explode(' ', $userName)[0]) ?></span>
    <?php if ($userRole === 'admin'): ?>
        <a href="admin.php" class="btn-ghost">Admin Console</a>
    <?php endif; ?>
    <a href="login.php?logout=1" class="btn-ghost">Sign out</a>
<?php else: ?>
    <button class="btn-ghost" @click="authOpen=true">Sign in</button>
<?php endif; ?>
<button class="btn-primary" @click="<?= $isLoggedIn ? 'alert(\'Dashboard coming soon!\')' : 'authOpen=true' ?>">Get started</button>
</div>
</div>
</div>
</header>

<!-- HERO -->
<section class="relative overflow-hidden pb-24 pt-40">
<div class="pointer-events-none absolute -top-32 left-1/2 h-[480px] w-[820px] -translate-x-1/2 rounded-full bg-gradient-to-r from-brand-200/60 via-violet-200/60 to-fuchsia-200/60 blur-3xl"></div>
<div class="relative mx-auto grid max-w-7xl items-center gap-16 px-4 sm:px-6 lg:grid-cols-2">
<div>
<span class="eyebrow">LHDN e-Invoice ready · Supabase PG</span>
<h1 class="mt-6 font-display text-5xl font-extrabold leading-[1.08] tracking-tight sm:text-6xl">Run your whole service business on <span class="grad-text">one elegant platform.</span></h1>
<p class="mt-6 max-w-xl text-lg leading-relaxed text-slate-600">AZ Kejora SaaS pairs smart e-Invoicing for SMEs with a beautiful facility-booking engine — wrapped in a simple 3-month subscription.</p>
<div class="mt-8 flex flex-wrap items-center gap-3">
<button class="btn-primary px-7 py-3.5 text-base" @click="<?= $isLoggedIn ? 'alert(\'Dashboard coming soon!\')' : 'authOpen=true' ?>">Start 30-day free trial</button>
</div>
</div>
</div>
</section>

<!-- ============ AUTH MODAL (Real Login) ============ -->
<div x-show="authOpen" x-cloak class="fixed inset-0 z-[70] grid place-items-center bg-ink/50 p-4 backdrop-blur-sm" @click.self="authOpen=false">
<div class="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl">
<form action="login.php" method="POST">
<div class="flex flex-col items-center text-center">
<span class="grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg">⚡</span>
<h3 class="mt-4 font-display text-xl font-bold">Sign in to AZ Kejora</h3>
<p class="mt-1 text-sm text-slate-500">Enter your credentials to continue</p>
</div>
<?php if(isset($_GET['err'])): ?>
<div class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-600 text-center font-semibold">Invalid email or password.</div>
<?php endif; ?>
<div class="mt-6 space-y-4">
<div>
<label class="label">Email address</label>
<input type="email" name="email" class="input" placeholder="admin@azkejora.io" required>
</div>
<div>
<label class="label">Password</label>
<input type="password" name="password" class="input" placeholder="••••••••" required>
</div>
</div>
<button type="submit" class="btn-primary mt-6 w-full py-3.5">Sign in securely</button>
<p class="mt-4 text-center text-xs text-slate-400">Demo Admin: <code class="bg-slate-100 px-1.5 py-0.5 rounded">admin@azkejora.io</code> / <code class="bg-slate-100 px-1.5 py-0.5 rounded">password</code></p>
</form>
<button class="btn-ghost mt-4 w-full" @click="authOpen=false">Cancel</button>
</div>
</div>

</body>
</html>
