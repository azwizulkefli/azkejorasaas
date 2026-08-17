<?php

declare(strict_types=1);

/**
 * Enterprise SME Landing Page - E-Invoice Automation & Retail Facility Booking Platform
 * HTML5 + Tailwind CSS + PHP Session Integration
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    session_start();
}

$isLoggedIn = !empty($_SESSION['logged_in']) && !empty($_SESSION['user']);
$user       = $isLoggedIn ? $_SESSION['user'] : null;
$sub        = $isLoggedIn ? ($_SESSION['subscription'] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NexusSME - Unified E-Invoice & Retail Facility Booking Platform</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 antialiased selection:bg-cyan-500 selection:text-slate-950">

    <!-- NAVIGATION BAR -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-slate-950/80 backdrop-blur-md border-b border-slate-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="/" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-emerald-400 flex items-center justify-center shadow-lg shadow-cyan-500/20 group-hover:scale-105 transition-transform">
                    <svg class="w-6 h-6 text-slate-950" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="flex flex-col">
                    <span class="text-lg font-bold tracking-tight text-white flex items-center gap-1">
                        Nexus<span class="text-cyan-400">SME</span>
                    </span>
                    <span class="text-[10px] text-slate-400 font-medium tracking-wider uppercase">E-Invoice & Booking</span>
                </div>
            </a>

            <!-- Nav Links -->
            <div class="hidden md:flex items-center space-x-8 text-sm font-medium text-slate-300">
                <a href="#features" class="hover:text-cyan-400 transition-colors">Features</a>
                <a href="#einvoice" class="hover:text-cyan-400 transition-colors">E-Invoice Module</a>
                <a href="#booking" class="hover:text-cyan-400 transition-colors">Facility Booking</a>
                <a href="#pricing" class="hover:text-cyan-400 transition-colors">Pricing Plan</a>
            </div>

            <!-- Auth Call to Action -->
            <div class="flex items-center space-x-4">
                <?php if ($isLoggedIn && $user): ?>
                    <div class="flex items-center space-x-3 bg-slate-900 border border-slate-800 rounded-full py-1.5 px-3">
                        <img src="<?= htmlspecialchars($user['avatar_url'] ?: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=100&q=80') ?>" 
                             alt="Avatar" class="w-7 h-7 rounded-full object-cover border border-cyan-400">
                        <span class="text-xs font-semibold text-slate-200 hidden sm:inline"><?= htmlspecialchars($user['name']) ?></span>
                        <a href="/auth.php?action=logout" class="text-xs font-semibold text-red-400 hover:text-red-300 ml-2">Sign Out</a>
                    </div>
                <?php else: ?>
                    <a href="/auth.php?action=login" 
                       class="inline-flex items-center space-x-2.5 px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-100 font-semibold text-sm border border-slate-700/80 shadow-sm transition-all hover:border-slate-600 hover:shadow-cyan-500/10">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                        </svg>
                        <span>Sign in with Google</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="relative pt-36 pb-24 overflow-hidden border-b border-slate-800/60">
        <!-- Ambient Glow Backgrounds -->
        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[350px] bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/3 right-10 w-96 h-96 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            <!-- Badge -->
            <div class="inline-flex items-center space-x-2 px-4 py-2 rounded-full bg-slate-900/90 border border-slate-800 text-xs font-semibold text-emerald-400 mb-8 shadow-inner">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                <span>Automated SME Solution for Modern Business</span>
            </div>

            <!-- Main Headline -->
            <h1 class="text-4xl sm:text-6xl font-extrabold tracking-tight text-white max-w-4xl mx-auto leading-[1.15]">
                Seamless <span class="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-emerald-400">E-Invoicing</span> & Retail <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-teal-300">Facility Booking</span>
            </h1>

            <p class="mt-6 text-lg sm:text-xl text-slate-300 max-w-2xl mx-auto leading-relaxed">
                Streamline compliance with automated tax invoicing and empower your retail operations with real-time slot scheduling — all in one unified cloud platform.
            </p>

            <!-- Hero Action Buttons -->
            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/auth.php?action=login" 
                   class="w-full sm:w-auto inline-flex items-center justify-center space-x-3 px-8 py-4 rounded-xl bg-gradient-to-r from-cyan-500 to-emerald-400 text-slate-950 font-bold text-base hover:from-cyan-400 hover:to-emerald-300 transition-all shadow-lg shadow-cyan-500/25">
                    <span>Start 30-Day Free Trial</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                    </svg>
                </a>

                <a href="#features" 
                   class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 font-semibold text-base border border-slate-800 transition-colors">
                    Explore Platform Features
                </a>
            </div>

            <!-- Stats Bar -->
            <div class="mt-16 grid grid-cols-2 md:grid-cols-4 gap-6 max-w-4xl mx-auto pt-10 border-t border-slate-800/80">
                <div class="text-center">
                    <p class="text-3xl font-extrabold text-cyan-400">100%</p>
                    <p class="text-xs font-medium text-slate-400 mt-1">Tax Compliant Output</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-extrabold text-emerald-400">&lt; 30s</p>
                    <p class="text-xs font-medium text-slate-400 mt-1">Web Invoice Generation</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-extrabold text-cyan-400">24/7</p>
                    <p class="text-xs font-medium text-slate-400 mt-1">Self-Service Slot Booking</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-extrabold text-emerald-400">30 Days</p>
                    <p class="text-xs font-medium text-slate-400 mt-1">Unrestricted Free Trial</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURE SHOWCASE SECTION -->
    <section id="features" class="py-24 bg-slate-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-xs font-bold uppercase tracking-widest text-cyan-400 mb-2">Dual Core Capabilities</h2>
                <p class="text-3xl sm:text-4xl font-extrabold text-slate-100 tracking-tight">Designed Exclusively for Growing Enterprises</p>
                <p class="text-slate-400 text-sm sm:text-base mt-3">Replace fragmented tools with an integrated workspace that handles both financial reporting and physical space utilization.</p>
            </div>

            <!-- MODULE A: E-INVOICE AUTOMATION -->
            <div id="einvoice" class="mb-20 bg-slate-900/60 border border-slate-800/80 rounded-3xl p-8 sm:p-12 relative overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    <div class="space-y-6">
                        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-lg bg-cyan-500/10 text-cyan-400 text-xs font-semibold border border-cyan-500/20">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span>Module A • Tax Compliance</span>
                        </div>
                        
                        <h3 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">Smart E-Invoice Automation</h3>
                        <p class="text-slate-300 text-sm leading-relaxed">
                            Generate, validate, and submit standardized e-invoices directly from web forms or bulk file uploads. Stay fully compliant with automated tax calculations and instant audit trail reporting.
                        </p>

                        <div class="space-y-4 pt-2">
                            <div class="flex items-start space-x-3">
                                <div class="w-6 h-6 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-100">Smart Tax & Exemption Calculation</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Automated tax breakdown, multi-item line totals, and standard rate calculations.</p>
                                </div>
                            </div>

                            <div class="flex items-start space-x-3">
                                <div class="w-6 h-6 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-100">Web Upload & Batch Processing</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Direct entry portal with instant XML/JSON validation and PDF invoice generation.</p>
                                </div>
                            </div>

                            <div class="flex items-start space-x-3">
                                <div class="w-6 h-6 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-100">Real-Time Financial Reporting</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Comprehensive sales tax dashboards, CSV exports, and tax authority submission logs.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Visual Demo Card -->
                    <div class="bg-slate-950 border border-slate-800 rounded-2xl p-6 shadow-2xl space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-red-500/80"></span>
                                <span class="w-3 h-3 rounded-full bg-yellow-500/80"></span>
                                <span class="w-3 h-3 rounded-full bg-green-500/80"></span>
                            </div>
                            <span class="text-xs font-mono text-slate-400">e-invoice_preview.json</span>
                        </div>
                        <div class="font-mono text-xs space-y-2 text-slate-300">
                            <div><span class="text-purple-400">"invoice_no"</span>: <span class="text-emerald-300">"INV-2026-0891"</span>,</div>
                            <div><span class="text-purple-400">"tax_id"</span>: <span class="text-cyan-300">"MY-TAX-998231"</span>,</div>
                            <div><span class="text-purple-400">"subtotal"</span>: <span class="text-yellow-300">1250.00</span>,</div>
                            <div><span class="text-purple-400">"tax_amount"</span>: <span class="text-yellow-300">100.00</span>,</div>
                            <div><span class="text-purple-400">"total_payable"</span>: <span class="text-emerald-400 font-bold">1350.00</span>,</div>
                            <div><span class="text-purple-400">"status"</span>: <span class="text-emerald-400">"VALIDATED_TAX_COMPLIANT"</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODULE B: FACILITY BOOKING SYSTEM -->
            <div id="booking" class="bg-slate-900/60 border border-slate-800/80 rounded-3xl p-8 sm:p-12 relative overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    
                    <!-- Visual Demo Card -->
                    <div class="order-2 lg:order-1 bg-slate-950 border border-slate-800 rounded-2xl p-6 shadow-2xl space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                            <span class="text-sm font-semibold text-slate-200">Retail Booking Terminal</span>
                            <span class="text-xs font-mono text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded">Slot Available</span>
                        </div>

                        <div class="space-y-3">
                            <div class="p-3 bg-slate-900 rounded-xl border border-slate-800 flex justify-between items-center">
                                <div>
                                    <p class="text-xs font-bold text-slate-100">Main Event Hall A</p>
                                    <p class="text-[11px] text-slate-400">Capacity: 150 PAX • High-Speed WiFi</p>
                                </div>
                                <span class="text-xs font-bold text-cyan-400">$80/hr</span>
                            </div>

                            <div class="p-3 bg-slate-900 rounded-xl border border-slate-800 flex justify-between items-center">
                                <div>
                                    <p class="text-xs font-bold text-slate-100">Retail Pop-Up Stall 04</p>
                                    <p class="text-[11px] text-slate-400">High Foot-Traffic Area</p>
                                </div>
                                <span class="text-xs font-bold text-cyan-400">$45/hr</span>
                            </div>

                            <div class="pt-2 flex gap-2">
                                <span class="px-3 py-1 bg-cyan-500/20 text-cyan-300 text-xs rounded-lg font-mono">10:00 AM</span>
                                <span class="px-3 py-1 bg-cyan-500/20 text-cyan-300 text-xs rounded-lg font-mono">02:00 PM</span>
                                <span class="px-3 py-1 bg-slate-800 text-slate-500 text-xs rounded-lg font-mono line-through">04:00 PM</span>
                            </div>
                        </div>
                    </div>

                    <div class="order-1 lg:order-2 space-y-6">
                        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 text-xs font-semibold border border-emerald-500/20">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <span>Module B • Facility Operations</span>
                        </div>

                        <h3 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">Retail Facility Booking Platform</h3>
                        <p class="text-slate-300 text-sm leading-relaxed">
                            Publish physical retail spaces, conference rooms, pop-up stalls, and equipment. Customers and staff can reserve available slots in real time with instant calendar conflict resolution.
                        </p>

                        <div class="space-y-4 pt-2">
                            <div class="flex items-start space-x-3">
                                <div class="w-6 h-6 rounded-full bg-cyan-500/10 text-cyan-400 flex items-center justify-center shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-100">Retail Facility Catalog</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Manage space listings, capacity limits, hourly rates, and facility amenities.</p>
                                </div>
                            </div>

                            <div class="flex items-start space-x-3">
                                <div class="w-6 h-6 rounded-full bg-cyan-500/10 text-cyan-400 flex items-center justify-center shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-100">Real-Time Time-Slot Scheduler</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Public booking portal with automatic double-booking prevention.</p>
                                </div>
                            </div>

                            <div class="flex items-start space-x-3">
                                <div class="w-6 h-6 rounded-full bg-cyan-500/10 text-cyan-400 flex items-center justify-center shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-100">Automated Confirmations & Invoicing</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Generate compliant tax e-invoices instantly upon booking confirmation.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </section>

    <!-- PRICING SECTION -->
    <section id="pricing" class="py-24 bg-slate-900/40 border-t border-slate-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-xs font-bold uppercase tracking-widest text-emerald-400 mb-2">Transparent Subscription</h2>
                <p class="text-3xl sm:text-4xl font-extrabold text-slate-100 tracking-tight">Simple, Predictable Enterprise Pricing</p>
                <p class="text-slate-400 text-sm sm:text-base mt-3">Start with a full 30-day trial without commitment. Upgrade anytime to our quarterly plan.</p>
            </div>

            <div class="max-w-lg mx-auto">
                <div class="bg-gradient-to-b from-slate-900 to-slate-950 border-2 border-cyan-500/50 rounded-3xl p-8 shadow-2xl relative overflow-hidden">
                    <!-- Featured Tag -->
                    <div class="absolute top-0 right-0 bg-gradient-to-l from-cyan-500 to-emerald-400 text-slate-950 font-extrabold text-[11px] uppercase tracking-wider px-4 py-1.5 rounded-bl-xl shadow-md">
                        30-Day Free Trial Included
                    </div>

                    <div class="space-y-4">
                        <h3 class="text-xl font-bold text-white">Quarterly Business Pass</h3>
                        <p class="text-slate-400 text-xs">Full access to E-Invoice Automation & Facility Booking</p>

                        <div class="flex items-baseline space-x-2 pt-2">
                            <span class="text-4xl font-extrabold text-white tracking-tight">$149</span>
                            <span class="text-slate-400 text-sm font-medium">/ 3 Months</span>
                        </div>
                        <p class="text-[11px] text-emerald-400 font-semibold">Equivalent to $49.66/month billed quarterly</p>
                    </div>

                    <div class="my-8 border-t border-slate-800"></div>

                    <!-- Checklist -->
                    <ul class="space-y-3.5 text-sm text-slate-300 mb-8">
                        <li class="flex items-center space-x-3">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span><strong>Unlimited</strong> E-Invoice Generation & Tax Audits</span>
                        </li>
                        <li class="flex items-center space-x-3">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span><strong>Unlimited</strong> Facility & Slot Listings</span>
                        </li>
                        <li class="flex items-center space-x-3">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span>Google OAuth 2.0 Single Sign-On (SSO)</span>
                        </li>
                        <li class="flex items-center space-x-3">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span>CSV/PDF Export & Tax Reporting</span>
                        </li>
                        <li class="flex items-center space-x-3">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span>Direct Supabase PostgreSQL Backup</span>
                        </li>
                    </ul>

                    <a href="/auth.php?action=login" 
                       class="w-full inline-flex items-center justify-center space-x-2 py-4 rounded-xl bg-gradient-to-r from-cyan-500 to-emerald-400 text-slate-950 font-bold text-sm hover:from-cyan-400 hover:to-emerald-300 transition-all shadow-lg shadow-cyan-500/20">
                        <span>Activate 30-Day Trial Now</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="bg-slate-950 border-t border-slate-800/80 py-12 text-slate-400 text-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center space-x-3">
                <div class="w-7 h-7 rounded-lg bg-gradient-to-tr from-cyan-500 to-emerald-400 flex items-center justify-center text-slate-950 font-bold text-xs">
                    N
                </div>
                <span class="text-sm font-semibold text-slate-200">NexusSME Platform</span>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-6 text-slate-400">
                <a href="#einvoice" class="hover:text-slate-200 transition-colors">E-Invoice Module</a>
                <a href="#booking" class="hover:text-slate-200 transition-colors">Facility Booking</a>
                <a href="/auth.php?action=login" class="hover:text-cyan-400 transition-colors">Customer Login</a>
                <a href="/auth.php?action=login" class="hover:text-cyan-400 transition-colors">Admin Access</a>
                <a href="#" class="hover:text-slate-200 transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-slate-200 transition-colors">Privacy Policy</a>
            </div>

            <p class="text-slate-500">© 2026 NexusSME. Built with PHP 8.2 & Supabase PostgreSQL.</p>
        </div>
    </footer>

</body>
</html>
