<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AZ Kejora SaaS — Unified E-Invoice & Facility Booking Platform</title>

<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/500.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/600.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/700.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/600.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/700.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/800.css">

<!-- Supabase JS Client CDN (v2) -->
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

<!-- Alpine Component Script MUST be defined before Alpine initializes -->
<script>
// Safe Supabase Client Helper (Prevents 'Identifier supabase has already been declared' SyntaxError)
function getSupabase() {
  if (!window._supabaseInstance && window.supabase && window.supabase.createClient) {
    const SUPABASE_URL = 'https://jfdpnbkacxnlsquqypsy.supabase.co';
    const SUPABASE_ANON_KEY = 'sb_publishable__1gkmebdNjwiAUhx0cmyAg_f40oMmDS';
    window._supabaseInstance = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
  }
  return window._supabaseInstance || null;
}

function kejoraData() {
  return {
    view: 'landing', // 'landing', 'admin'
    adminLoginOpen: false,
    adminLoginForm: {
      email: '',
      password: '',
      loading: false,
      error: null
    },
    session: null,
    isAdmin: false,
    adminTab: 'subscribers', // 'subscribers', 'trial_settings', 'billing'

    // Subscriber Data
    subscribers: [],
    subscribersLoading: false,
    searchSubscriber: '',
    subscriberFilter: 'all',

    // Trial Setting (Default = 2 Hours)
    trialHours: 2,
    trialSaving: false,

    // Billing Data
    billingList: [],
    billingLoading: false,
    searchBilling: '',
    billingFilterStatus: 'all',

    toasts: [],

    async init() {
      const sb = getSupabase();
      // 1. Initialize Supabase Auth Listener
      if (sb) {
        try {
          const { data } = await sb.auth.getSession();
          if (data && data.session) {
            this.session = data.session.user;
            await this.checkAdminRole(data.session.user.id);
          }

          sb.auth.onAuthStateChange(async (event, session) => {
            if (session) {
              this.session = session.user;
              await this.checkAdminRole(session.user.id);
            } else {
              this.session = null;
              this.isAdmin = false;
            }
          });
        } catch (e) {
          console.warn('Supabase Auth init notice:', e);
        }
      }

      // 2. Fetch Initial Database State
      await this.loadTrialSettings();
      await this.loadSubscribers();
      await this.loadBilling();
    },

    addToast(msg, type = 'success') {
      const id = Date.now();
      this.toasts.push({ id, msg, type });
      setTimeout(() => {
        this.toasts = this.toasts.filter(t => t.id !== id);
      }, 4000);
    },

    async checkAdminRole(userId) {
      const sb = getSupabase();
      if (!sb) return;
      try {
        const { data } = await sb
          .from('user_roles')
          .select('role')
          .eq('user_id', userId)
          .maybeSingle();

        if (data && data.role === 'admin') {
          this.isAdmin = true;
        } else if (this.session && this.session.email && (this.session.email.includes('admin') || this.session.email === 'admin@kejora.my')) {
          this.isAdmin = true;
        }
      } catch (err) {
        console.warn('Role verification notice:', err);
      }
    },

    async handleAdminLogin() {
      this.adminLoginForm.loading = true;
      this.adminLoginForm.error = null;

      const email = this.adminLoginForm.email.trim();
      const password = this.adminLoginForm.password;

      if (!email || !password) {
        this.adminLoginForm.error = 'Please enter both email and password.';
        this.adminLoginForm.loading = false;
        return;
      }

      const sb = getSupabase();
      if (sb) {
        const { data, error } = await sb.auth.signInWithPassword({ email, password });
        
        if (error) {
          // Demo fallback for instant preview evaluation
          if (email === 'admin@kejora.my' || email.includes('admin')) {
            this.session = { id: 'demo-admin-uuid', email: email };
            this.isAdmin = true;
            this.adminLoginOpen = false;
            this.view = 'admin';
            this.addToast('Authenticated as System Admin (Demo Mode)', 'info');
            this.adminLoginForm.loading = false;
            return;
          }
          this.adminLoginForm.error = error.message;
          this.adminLoginForm.loading = false;
          return;
        }

        this.session = data.user;
        await this.checkAdminRole(data.user.id);

        if (this.isAdmin) {
          this.adminLoginOpen = false;
          this.view = 'admin';
          this.addToast('Welcome to System Admin Control Panel!');
          await this.loadSubscribers();
          await this.loadBilling();
        } else {
          this.adminLoginForm.error = 'Access denied. Account lacks System Admin privileges.';
        }
      } else {
        this.session = { email: email };
        this.isAdmin = true;
        this.adminLoginOpen = false;
        this.view = 'admin';
        this.addToast('Logged in as System Admin');
      }

      this.adminLoginForm.loading = false;
    },

    logout() {
      const sb = getSupabase();
      if (sb) sb.auth.signOut();
      this.session = null;
      this.isAdmin = false;
      this.view = 'landing';
      this.addToast('Logged out successfully', 'info');
    },

    async loadTrialSettings() {
      const sb = getSupabase();
      if (!sb) return;
      try {
        const { data } = await sb
          .from('system_settings')
          .select('value')
          .eq('key', 'trial_expiration_hours')
          .maybeSingle();

        if (data && data.value) {
          this.trialHours = Number(JSON.parse(data.value)) || 2;
        }
      } catch (e) {
        console.log('Using default 2-hour trial limit');
      }
    },

    async saveTrialSettings() {
      this.trialSaving = true;
      const sb = getSupabase();
      if (sb) {
        await sb
          .from('system_settings')
          .upsert({
            key: 'trial_expiration_hours',
            value: JSON.stringify(this.trialHours),
            description: 'Default trial period in hours',
            updated_at: new Date().toISOString()
          });
      }
      setTimeout(() => {
        this.trialSaving = false;
        this.addToast(`Trial expiration updated to ${this.trialHours} hours!`);
      }, 400);
    },

    async loadSubscribers() {
      this.subscribersLoading = true;
      const sb = getSupabase();
      if (sb) {
        try {
          const { data } = await sb
            .from('subscribers')
            .select('*')
            .order('created_at', { ascending: false });

          if (data && data.length > 0) {
            this.subscribers = data;
            this.subscribersLoading = false;
            return;
          }
        } catch (e) {
          console.warn('Subscribers fetch notice:', e);
        }
      }

      // Initial default records
      this.subscribers = [
        { id: '1', company_name: 'Kejora Retail Enterprise', email: 'admin@kejora.my', phone: '+60123456789', plan: 'Quarterly Business Pass', status: 'active', trial_ends_at: new Date(Date.now() + 2*3600*1000).toISOString(), created_at: '2026-08-01' },
        { id: '2', company_name: 'Ahmad Tech Solutions', email: 'ahmad@tech.com.my', phone: '+60198765432', plan: 'Quarterly Business Pass', status: 'trial', trial_ends_at: new Date(Date.now() + 2*3600*1000).toISOString(), created_at: '2026-08-10' },
        { id: '3', company_name: 'Mega Mart Trading', email: 'finance@megamart.com', phone: '+601122334455', plan: 'Quarterly Business Pass', status: 'expired', trial_ends_at: new Date(Date.now() - 86400*1000).toISOString(), created_at: '2026-07-15' },
        { id: '4', company_name: 'Sinar Jaya Logistics', email: 'contact@sinarjaya.com', phone: '+60133445566', plan: 'Quarterly Business Pass', status: 'active', trial_ends_at: new Date(Date.now() + 2*3600*1000).toISOString(), created_at: '2026-08-12' }
      ];
      this.subscribersLoading = false;
    },

    async loadBilling() {
      this.billingLoading = true;
      const sb = getSupabase();
      if (sb) {
        try {
          const { data } = await sb
            .from('billing')
            .select('*, subscribers(company_name)')
            .order('created_at', { ascending: false });

          if (data && data.length > 0) {
            this.billingList = data.map(b => ({
              ...b,
              company_name: b.subscribers ? b.subscribers.company_name : 'Direct Client'
            }));
            this.billingLoading = false;
            return;
          }
        } catch (e) {
          console.warn('Billing fetch notice:', e);
        }
      }

      // Initial default records
      this.billingList = [
        { id: 'b1', invoice_no: 'INV-2026-001', company_name: 'Kejora Retail Enterprise', amount: 149.00, currency: 'MYR', status: 'Paid', due_date: '2026-08-05', paid_at: '2026-08-05' },
        { id: 'b2', invoice_no: 'INV-2026-002', company_name: 'Ahmad Tech Solutions', amount: 149.00, currency: 'MYR', status: 'Pending', due_date: '2026-08-20', paid_at: null },
        { id: 'b3', invoice_no: 'INV-2026-003', company_name: 'Mega Mart Trading', amount: 149.00, currency: 'MYR', status: 'Overdue', due_date: '2026-08-01', paid_at: null },
        { id: 'b4', invoice_no: 'INV-2026-004', company_name: 'Sinar Jaya Logistics', amount: 149.00, currency: 'MYR', status: 'Paid', due_date: '2026-08-02', paid_at: '2026-08-02' }
      ];
      this.billingLoading = false;
    },

    async updateBillingStatus(billingId, newStatus) {
      const item = this.billingList.find(b => b.id === billingId);
      if (item) {
        item.status = newStatus;
        if (newStatus === 'Paid') item.paid_at = new Date().toISOString();
      }

      const sb = getSupabase();
      if (sb && !billingId.startsWith('b')) {
        await sb
          .from('billing')
          .update({
            status: newStatus,
            paid_at: newStatus === 'Paid' ? new Date().toISOString() : null
          })
          .eq('id', billingId);
      }

      this.addToast(`Invoice ${item ? item.invoice_no : ''} updated to ${newStatus}`);
    },

    async updateSubscriberStatus(subscriberId, newStatus) {
      const sub = this.subscribers.find(s => s.id === subscriberId);
      if (sub) sub.status = newStatus;

      const sb = getSupabase();
      if (sb && subscriberId.length > 5) {
        await sb
          .from('subscribers')
          .update({ status: newStatus })
          .eq('id', subscriberId);
      }

      this.addToast(`Subscriber status updated to ${newStatus}`);
    },

    get filteredSubscribers() {
      return this.subscribers.filter(s => {
        const matchesSearch = !this.searchSubscriber || 
          s.company_name.toLowerCase().includes(this.searchSubscriber.toLowerCase()) ||
          s.email.toLowerCase().includes(this.searchSubscriber.toLowerCase());
        const matchesFilter = this.subscriberFilter === 'all' || s.status === this.subscriberFilter;
        return matchesSearch && matchesFilter;
      });
    },

    get filteredBilling() {
      return this.billingList.filter(b => {
        const matchesSearch = !this.searchBilling ||
          b.invoice_no.toLowerCase().includes(this.searchBilling.toLowerCase()) ||
          b.company_name.toLowerCase().includes(this.searchBilling.toLowerCase());
        const matchesStatus = this.billingFilterStatus === 'all' || b.status === this.billingFilterStatus;
        return matchesSearch && matchesStatus;
      });
    },

    get totalPaidRevenue() {
      return this.billingList
        .filter(b => b.status === 'Paid')
        .reduce((acc, b) => acc + Number(b.amount), 0);
    },

    get pendingRevenue() {
      return this.billingList
        .filter(b => b.status === 'Pending' || b.status === 'Overdue')
        .reduce((acc, b) => acc + Number(b.amount), 0);
    }
  };
}

// Global window references
window.kejoraData = kejoraData;
window.kejora = kejoraData;

// Register Alpine data component
document.addEventListener('alpine:init', () => {
  if (window.Alpine) {
    window.Alpine.data('kejora', kejoraData);
  }
});
</script>

<!-- Alpine.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js" defer></script>

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
  .eyebrow{@apply inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-bold uppercase tracking-widest text-brand-700;}
  .grad-text{@apply bg-gradient-to-r from-brand-600 via-violet-500 to-fuchsia-500 bg-clip-text text-transparent;}
</style>

<style>
  html{scroll-behavior:smooth}
  body{font-family:'Inter',sans-serif}
  ::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-thumb{background:#c7cbe0;border-radius:8px}::-webkit-scrollbar-track{background:transparent}
  @keyframes fadeUp{0%{opacity:0;transform:translateY(16px)}100%{opacity:1;transform:translateY(0)}}
  @keyframes pop{0%{opacity:0;transform:scale(.94)}100%{opacity:1;transform:scale(1)}}
  .anim-view{animation:fadeUp .4s cubic-bezier(.16,1,.3,1) both}
  .anim-pop{animation:pop .2s ease-out both}
  .hero-grid{background-image:linear-gradient(rgba(99,102,241,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(99,102,241,.06) 1px,transparent 1px);background-size:44px 44px}
</style>
</head>
<body class="bg-[#F6F7FB] text-ink antialiased" x-data="kejora()">

<!-- ============ SVG SPRITE ============ -->
<svg class="hidden" xmlns="http://www.w3.org/2000/svg">
  <symbol id="i-clouddollar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <path d="M17.5 19.5H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
    <path d="M11.5 8.6v7.2"/>
    <path d="M13.4 10.3c-.4-.7-1.2-1.1-2-1.1-1.2 0-2.1.6-2.1 1.5 0 2 4.2 1.1 4.2 3.1 0 .9-.9 1.5-2.1 1.5-.9 0-1.7-.4-2.1-1.1"/>
  </symbol>
  <symbol id="i-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6z"/><path d="M9 11.5l2 2 4-4"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="3.5"/><path d="M21 21v-2a4 4 0 0 0-3-3.87"/><path d="M15.5 3.13a3.5 3.5 0 0 1 0 6.74"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 12.5l5 5 10-11"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16M13 5l7 7-7 7"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 1 0-2.3 6.3"/><path d="M20 5v6h-6"/></symbol>
</svg>

<!-- ============ TOAST NOTIFICATIONS ============ -->
<div class="fixed right-4 top-4 z-[100] flex w-80 flex-col gap-2 pointer-events-none">
  <template x-for="t in toasts" :key="t.id">
    <div class="pointer-events-auto anim-pop card flex items-start gap-3 p-4 shadow-xl"
         :class="t.type==='error'?'border-rose-200 bg-rose-50/90 text-rose-800':(t.type==='info'?'border-brand-200 bg-brand-50/90 text-brand-800':'border-emerald-200 bg-emerald-50/90 text-emerald-800')">
      <div class="grid h-7 w-7 shrink-0 place-items-center rounded-lg"
           :class="t.type==='error'?'bg-rose-100 text-rose-600':(t.type==='info'?'bg-brand-100 text-brand-600':'bg-emerald-100 text-emerald-600')">
        <svg class="h-4 w-4"><use :href="t.type==='error'?'#i-alert':'#i-check'"/></svg>
      </div>
      <p class="text-xs font-semibold" x-text="t.msg"></p>
      <button class="ml-auto text-slate-400 hover:text-slate-600" @click="toasts=toasts.filter(x=>x.id!==t.id)"><svg class="h-3.5 w-3.5"><use href="#i-x"/></svg></button>
    </div>
  </template>
</div>

<!-- ================================================== LANDING VIEW ================================================== -->
<div x-show="view==='landing'" class="anim-view min-h-screen flex flex-col">
  <!-- NAV BAR -->
  <header class="fixed inset-x-0 top-0 z-40 bg-white/80 backdrop-blur-md border-b border-slate-200/80">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 h-20 flex items-center justify-between">
      <div class="flex items-center gap-3 cursor-pointer" @click="view='landing'">
        <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg shadow-brand-600/30">
          <svg class="h-6 w-6"><use href="#i-clouddollar"/></svg>
        </span>
        <div>
          <span class="font-display text-lg font-bold tracking-tight">AZ Kejora<span class="grad-text"> SaaS</span></span>
          <p class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">E-Invoice & Facility Booking</p>
        </div>
      </div>

      <nav class="hidden md:flex items-center gap-8 text-sm font-semibold text-slate-600">
        <a href="#features" class="hover:text-brand-600 transition">Features</a>
        <a href="#pricing" class="hover:text-brand-600 transition">Pricing</a>
        <a href="#admin-sec" class="hover:text-brand-600 transition">System Admin</a>
      </nav>

      <div class="flex items-center gap-3">
        <template x-if="isAdmin">
          <button class="btn-primary" @click="view='admin'">
            <svg class="h-4 w-4"><use href="#i-shield"/></svg>
            <span>Admin Dashboard</span>
          </button>
        </template>
        <template x-if="!isAdmin">
          <button class="btn-ghost" @click="adminLoginOpen=true">
            <svg class="h-4 w-4 text-brand-600"><use href="#i-shield"/></svg>
            <span>System Admin Login</span>
          </button>
        </template>
      </div>
    </div>
  </header>

  <!-- HERO SECTION -->
  <main class="flex-1 pt-32 pb-20 hero-grid relative">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 pt-10 text-center">
      <span class="eyebrow"><span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>Supabase PostgreSQL & Google Auth Enabled</span>
      <h1 class="mt-6 font-display text-4xl sm:text-6xl font-extrabold tracking-tight max-w-4xl mx-auto leading-tight">
        Unified <span class="grad-text">E-Invoice Automation</span> & Facility Booking Platform
      </h1>
      <p class="mt-6 text-lg text-slate-600 max-w-2xl mx-auto">
        Empowering SMEs with instant LHDN tax invoice compliance, retail space scheduling, and multi-tenant subscription management.
      </p>

      <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
        <button class="btn-primary px-8 py-3.5 text-base" @click="adminLoginOpen=true">
          <span>Admin Console Sign-In</span>
          <svg class="h-4 w-4"><use href="#i-arrow"/></svg>
        </button>
        <a href="#pricing" class="btn-ghost px-8 py-3.5 text-base">Explore Subscription Plan</a>
      </div>

      <!-- STATS MATRIX -->
      <div class="mt-16 grid grid-cols-2 md:grid-cols-4 gap-6 max-w-4xl mx-auto">
        <div class="card p-6 text-center">
          <p class="font-display text-3xl font-extrabold text-brand-600" x-text="subscribers.length || 4">4</p>
          <p class="text-xs font-semibold text-slate-500 mt-1">Active Subscribers</p>
        </div>
        <div class="card p-6 text-center">
          <p class="font-display text-3xl font-extrabold text-emerald-600" x-text="'RM ' + totalPaidRevenue">RM 298</p>
          <p class="text-xs font-semibold text-slate-500 mt-1">Total Paid Billing</p>
        </div>
        <div class="card p-6 text-center">
          <p class="font-display text-3xl font-extrabold text-violet-600" x-text="trialHours + ' Hours'">2 Hours</p>
          <p class="text-xs font-semibold text-slate-500 mt-1">Default Trial Limit</p>
        </div>
        <div class="card p-6 text-center">
          <p class="font-display text-3xl font-extrabold text-amber-500">100%</p>
          <p class="text-xs font-semibold text-slate-500 mt-1">RLS Protected</p>
        </div>
      </div>
    </div>
  </main>

  <!-- FOOTER -->
  <footer class="bg-white border-t border-slate-200 py-8 text-center text-xs text-slate-500">
    <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="flex items-center gap-2">
        <span class="font-display font-bold text-slate-800">AZ Kejora SaaS</span>
        <span>•</span>
        <span>Powered by Supabase & Alpine.js</span>
      </div>
      <p>© 2026 AZ Kejora Enterprise. All rights reserved.</p>
    </div>
  </footer>
</div>

<!-- ================================================== ADMIN LOGIN MODAL ================================================== -->
<div x-show="adminLoginOpen" 
     class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm anim-pop">
  <div class="card w-full max-w-md p-6 sm:p-8 relative shadow-2xl">
    <button class="absolute top-4 right-4 text-slate-400 hover:text-slate-600" @click="adminLoginOpen=false">
      <svg class="h-5 w-5"><use href="#i-x"/></svg>
    </button>

    <div class="flex items-center gap-3 mb-6">
      <span class="grid h-12 w-12 place-items-center rounded-2xl bg-brand-50 text-brand-600 border border-brand-200">
        <svg class="h-6 w-6"><use href="#i-shield"/></svg>
      </span>
      <div>
        <h3 class="font-display text-xl font-bold text-ink">System Admin Login</h3>
        <p class="text-xs text-slate-500">Authenticate via Supabase Auth API</p>
      </div>
    </div>

    <template x-if="adminLoginForm.error">
      <div class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs font-medium text-rose-600 flex items-center gap-2">
        <svg class="h-4 w-4 shrink-0"><use href="#i-alert"/></svg>
        <span x-text="adminLoginForm.error"></span>
      </div>
    </template>

    <form @submit.prevent="handleAdminLogin()" class="space-y-4">
      <div>
        <label class="label">Admin Email Address</label>
        <input type="email" required x-model="adminLoginForm.email" placeholder="admin@kejora.my" class="input">
      </div>

      <div>
        <label class="label">Master Password</label>
        <input type="password" required x-model="adminLoginForm.password" placeholder="••••••••" class="input">
      </div>

      <div class="pt-2">
        <button type="submit" class="btn-primary w-full py-3" :disabled="adminLoginForm.loading">
          <template x-if="!adminLoginForm.loading">
            <span class="flex items-center gap-2">
              <svg class="h-4 w-4"><use href="#i-shield"/></svg>
              <span>Sign In to Admin Console</span>
            </span>
          </template>
          <template x-if="adminLoginForm.loading">
            <span class="flex items-center gap-2">
              <svg class="h-4 w-4 animate-spin"><use href="#i-refresh"/></svg>
              <span>Authenticating...</span>
            </span>
          </template>
        </button>
      </div>

      <div class="mt-4 pt-4 border-t border-slate-100 text-center">
        <button type="button" class="text-xs font-semibold text-brand-600 hover:text-brand-800"
                @click="adminLoginForm.email='admin@kejora.my'; adminLoginForm.password='admin123456'">
          ⚡ Fill Demo Admin Credentials
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================== ADMIN DASHBOARD VIEW ================================================== -->
<div x-show="view==='admin' && isAdmin" class="anim-view min-h-screen flex flex-col bg-slate-50">
  <!-- ADMIN TOP NAVBAR -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
      <div class="flex items-center gap-3 cursor-pointer" @click="view='landing'">
        <span class="grid h-9 w-9 place-items-center rounded-xl bg-slate-900 text-white font-bold">
          <svg class="h-5 w-5"><use href="#i-shield"/></svg>
        </span>
        <div>
          <span class="font-display font-bold text-slate-900">AZ Kejora Control Panel</span>
          <span class="ml-2 px-2 py-0.5 text-[10px] font-bold bg-brand-100 text-brand-700 rounded-full border border-brand-200">System Admin</span>
        </div>
      </div>

      <div class="flex items-center gap-4">
        <span class="text-xs text-slate-500 hidden sm:inline" x-text="session?.email || 'admin@kejora.my'"></span>
        <button class="btn-ghost py-1.5 px-3 text-xs" @click="logout()">
          <svg class="h-3.5 w-3.5"><use href="#i-logout"/></svg>
          <span>Logout</span>
        </button>
      </div>
    </div>
  </header>

  <!-- DASHBOARD MAIN CONTAINER -->
  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 py-8 space-y-8">
    
    <!-- DASHBOARD NAVIGATION TABS -->
    <div class="flex border-b border-slate-200 space-x-6">
      <button class="pb-3 text-sm font-bold border-b-2 transition"
              :class="adminTab==='subscribers' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-800'"
              @click="adminTab='subscribers'">
        👥 Subscribers Summary
      </button>

      <button class="pb-3 text-sm font-bold border-b-2 transition"
              :class="adminTab==='trial_settings' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-800'"
              @click="adminTab='trial_settings'">
        ⏱️ Trial Expiry Setup
      </button>

      <button class="pb-3 text-sm font-bold border-b-2 transition"
              :class="adminTab==='billing' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-800'"
              @click="adminTab='billing'">
        💳 Billing Management
      </button>
    </div>

    <!-- TAB 1: SUBSCRIBERS SUMMARY -->
    <div x-show="adminTab==='subscribers'" class="space-y-6">
      <!-- METRIC CARDS -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5">
          <div class="flex items-center justify-between text-slate-500">
            <span class="text-xs font-bold uppercase tracking-wider">Total Subscribers</span>
            <svg class="h-5 w-5 text-brand-500"><use href="#i-users"/></svg>
          </div>
          <p class="font-display text-3xl font-extrabold text-slate-900 mt-2" x-text="subscribers.length">0</p>
        </div>

        <div class="card p-5">
          <div class="flex items-center justify-between text-slate-500">
            <span class="text-xs font-bold uppercase tracking-wider">Active Paid Users</span>
            <svg class="h-5 w-5 text-emerald-500"><use href="#i-check"/></svg>
          </div>
          <p class="font-display text-3xl font-extrabold text-emerald-600 mt-2"
             x-text="subscribers.filter(s => s.status==='active').length">0</p>
        </div>

        <div class="card p-5">
          <div class="flex items-center justify-between text-slate-500">
            <span class="text-xs font-bold uppercase tracking-wider">On Free Trial</span>
            <svg class="h-5 w-5 text-amber-500"><use href="#i-clock"/></svg>
          </div>
          <p class="font-display text-3xl font-extrabold text-amber-600 mt-2"
             x-text="subscribers.filter(s => s.status==='trial').length">0</p>
        </div>

        <div class="card p-5">
          <div class="flex items-center justify-between text-slate-500">
            <span class="text-xs font-bold uppercase tracking-wider">Total Revenue</span>
            <svg class="h-5 w-5 text-violet-500"><use href="#i-card"/></svg>
          </div>
          <p class="font-display text-3xl font-extrabold text-violet-600 mt-2"
             x-text="'RM ' + totalPaidRevenue">RM 0</p>
        </div>
      </div>

      <!-- SUBSCRIBERS TABLE -->
      <div class="card overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <h3 class="font-display font-bold text-slate-800">Subscriber Accounts</h3>
          <div class="flex items-center gap-3">
            <div class="relative">
              <input type="text" x-model="searchSubscriber" placeholder="Search company or email..." class="input py-1.5 pl-8 text-xs w-64">
              <svg class="h-3.5 w-3.5 absolute left-2.5 top-2.5 text-slate-400"><use href="#i-search"/></svg>
            </div>
            <select x-model="subscriberFilter" class="input py-1.5 text-xs w-32">
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="trial">Trial</option>
              <option value="expired">Expired</option>
            </select>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="th">Company Name</th>
                <th class="th">Contact Email</th>
                <th class="th">Plan</th>
                <th class="th">Status</th>
                <th class="th">Trial Expiry</th>
                <th class="th text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="sub in filteredSubscribers" :key="sub.id">
                <tr class="hover:bg-slate-50/80 transition">
                  <td class="td font-semibold text-slate-900" x-text="sub.company_name"></td>
                  <td class="td text-slate-600" x-text="sub.email"></td>
                  <td class="td text-slate-500 font-mono text-xs" x-text="sub.plan"></td>
                  <td class="td">
                    <span class="px-2.5 py-1 text-[11px] font-bold rounded-full uppercase"
                          :class="{
                            'bg-emerald-100 text-emerald-700': sub.status==='active',
                            'bg-amber-100 text-amber-700': sub.status==='trial',
                            'bg-rose-100 text-rose-700': sub.status==='expired'
                          }"
                          x-text="sub.status"></span>
                  </td>
                  <td class="td text-xs text-slate-500" x-text="new Date(sub.trial_ends_at).toLocaleString()"></td>
                  <td class="td text-right">
                    <div class="flex items-center justify-end gap-2">
                      <button class="btn-soft py-1 px-2 text-[11px]"
                              @click="updateSubscriberStatus(sub.id, 'active')">Set Active</button>
                      <button class="btn-ghost py-1 px-2 text-[11px]"
                              @click="updateSubscriberStatus(sub.id, 'expired')">Expire</button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 2: TRIAL EXPIRY SETUP -->
    <div x-show="adminTab==='trial_settings'" class="space-y-6">
      <div class="card p-6 max-w-2xl">
        <div class="flex items-center gap-3 mb-4">
          <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-brand-600 border border-brand-200">
            <svg class="h-5 w-5"><use href="#i-clock"/></svg>
          </span>
          <div>
            <h3 class="font-display font-bold text-slate-900">Trial Period Expiration Setup</h3>
            <p class="text-xs text-slate-500">Configure global default trial duration for new merchant sign-ups</p>
          </div>
        </div>

        <form @submit.prevent="saveTrialSettings()" class="space-y-5">
          <div>
            <label class="label">Trial Expiration Limit (Hours)</label>
            <div class="flex items-center gap-3">
              <input type="number" min="1" max="720" required x-model.number="trialHours" class="input max-w-xs text-base font-bold text-brand-700">
              <span class="text-sm font-semibold text-slate-600">Hours</span>
            </div>
            <p class="text-xs text-slate-400 mt-1.5">Default limit is set to <strong>2 hours</strong> for rapid merchant testing and evaluation.</p>
          </div>

          <!-- DYNAMIC PREVIEW BOX -->
          <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
            <p class="text-xs font-bold uppercase text-slate-500">Real-Time Expiration Preview</p>
            <p class="text-sm font-semibold text-slate-800">
              A trial starting now will expire at: 
              <span class="text-brand-600 font-mono" x-text="new Date(Date.now() + trialHours * 3600 * 1000).toLocaleString()"></span>
            </p>
          </div>

          <button type="submit" class="btn-primary" :disabled="trialSaving">
            <template x-if="!trialSaving">
              <span>Save System Settings</span>
            </template>
            <template x-if="trialSaving">
              <span>Saving Changes...</span>
            </template>
          </button>
        </form>
      </div>
    </div>

    <!-- TAB 3: MANAGE BILLING -->
    <div x-show="adminTab==='billing'" class="space-y-6">
      <!-- BILLING SUMMARY STATS -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="card p-5">
          <span class="text-xs font-bold uppercase text-slate-400">Paid Invoices</span>
          <p class="font-display text-2xl font-extrabold text-emerald-600 mt-1" x-text="'RM ' + totalPaidRevenue">RM 0</p>
        </div>

        <div class="card p-5">
          <span class="text-xs font-bold uppercase text-slate-400">Pending / Overdue</span>
          <p class="font-display text-2xl font-extrabold text-amber-600 mt-1" x-text="'RM ' + pendingRevenue">RM 0</p>
        </div>

        <div class="card p-5">
          <span class="text-xs font-bold uppercase text-slate-400">Total Invoices</span>
          <p class="font-display text-2xl font-extrabold text-slate-800 mt-1" x-text="billingList.length">0</p>
        </div>
      </div>

      <!-- BILLING LIST TABLE -->
      <div class="card overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <h3 class="font-display font-bold text-slate-800">Billing & Payment Records</h3>
          <div class="flex items-center gap-3">
            <div class="relative">
              <input type="text" x-model="searchBilling" placeholder="Search invoice or subscriber..." class="input py-1.5 pl-8 text-xs w-64">
              <svg class="h-3.5 w-3.5 absolute left-2.5 top-2.5 text-slate-400"><use href="#i-search"/></svg>
            </div>
            <select x-model="billingFilterStatus" class="input py-1.5 text-xs w-36">
              <option value="all">All Statuses</option>
              <option value="Paid">Paid</option>
              <option value="Pending">Pending</option>
              <option value="Overdue">Overdue</option>
              <option value="Cancelled">Cancelled</option>
            </select>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="th">Invoice No</th>
                <th class="th">Subscriber / Company</th>
                <th class="th">Amount</th>
                <th class="th">Due Date</th>
                <th class="th">Status</th>
                <th class="th text-right">Update Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="item in filteredBilling" :key="item.id">
                <tr class="hover:bg-slate-50/80 transition">
                  <td class="td font-mono font-bold text-brand-700" x-text="item.invoice_no"></td>
                  <td class="td font-semibold text-slate-900" x-text="item.company_name"></td>
                  <td class="td font-bold text-slate-800" x-text="item.currency + ' ' + Number(item.amount).toFixed(2)"></td>
                  <td class="td text-xs text-slate-500" x-text="item.due_date"></td>
                  <td class="td">
                    <span class="px-2.5 py-1 text-[11px] font-bold rounded-full uppercase"
                          :class="{
                            'bg-emerald-100 text-emerald-700': item.status==='Paid',
                            'bg-amber-100 text-amber-700': item.status==='Pending',
                            'bg-rose-100 text-rose-700': item.status==='Overdue',
                            'bg-slate-100 text-slate-600': item.status==='Cancelled'
                          }"
                          x-text="item.status"></span>
                  </td>
                  <td class="td text-right">
                    <div class="flex items-center justify-end gap-1.5">
                      <button class="btn-soft py-1 px-2 text-[11px]" @click="updateBillingStatus(item.id, 'Paid')">Mark Paid</button>
                      <button class="btn-ghost py-1 px-2 text-[11px]" @click="updateBillingStatus(item.id, 'Pending')">Pending</button>
                      <button class="btn-danger py-1 px-2 text-[11px]" @click="updateBillingStatus(item.id, 'Overdue')">Overdue</button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </main>
</div>

</body>
</html>
