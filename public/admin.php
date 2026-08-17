<?php
require_once '../includes/auth.php';
requireAdmin();

// Fetch subscribers
$stmt = $pdo->query("
    SELECT u.id, u.name, u.email, s.plan, s.status, s.price, s.trial_ends_at, s.period_ends_at, s.created_at
    FROM users u
    LEFT JOIN subscriptions s ON u.id = s.user_id
    WHERE u.role = 'customer'
    ORDER BY u.created_at DESC
");
$subscribers = $stmt->fetchAll();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = $_POST['user_id'] ?? '';
    
    if ($_POST['action'] === 'extend_trial') {
        $hours = (int)($_POST['hours'] ?? 2); // Default 2 hours
        $stmt = $pdo->prepare("UPDATE subscriptions SET status = 'active_trial', trial_ends_at = NOW() + INTERVAL '{$hours} hours' WHERE user_id = ?");
        $stmt->execute([$userId]);
    } 
    elseif ($_POST['action'] === 'activate') {
        $stmt = $pdo->prepare("UPDATE subscriptions SET status = 'active', period_ends_at = NOW() + INTERVAL '90 days' WHERE user_id = ?");
        $stmt->execute([$userId]);
    } 
    elseif ($_POST['action'] === 'suspend') {
        $stmt = $pdo->prepare("UPDATE subscriptions SET status = 'suspended' WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    header("Location: admin.php");
    exit;
}

$stats = $pdo->query("
    SELECT 
        COUNT(*) FILTER (WHERE status = 'active') as active_subs,
        COUNT(*) FILTER (WHERE status = 'active_trial') as trials,
        COUNT(*) FILTER (WHERE status = 'past_due') as past_due
    FROM subscriptions
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Console — AZ Kejora SaaS</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.1.1/400.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.1.1/700.css">
<script>
tailwind.config = { theme: { extend: { fontFamily: { display:['Sora','sans-serif'], sans:['Inter','sans-serif'] }, colors: { ink:'#131327', brand:{500:'#6366f1', 600:'#5457e5', 700:'#4644cf'} } } } }
</script>
<style>body{font-family:'Inter',sans-serif} .btn-primary{@apply bg-brand-600 text-white px-4 py-2 rounded-lg font-semibold hover:bg-brand-700 transition;} .btn-danger{@apply bg-rose-50 text-rose-600 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-rose-100;} .btn-ghost{@apply bg-slate-100 text-slate-600 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-slate-200;}</style>
</head>
<body class="bg-slate-50 text-ink">
<nav class="bg-white border-b border-slate-200 px-6 py-4 flex justify-between items-center">
    <div class="flex items-center gap-3">
        <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-violet-500 text-white shadow-lg">⚡</span>
        <span class="font-display text-lg font-bold tracking-tight">AZ Kejora <span class="text-brand-600">Admin</span></span>
    </div>
    <div class="flex items-center gap-4">
        <span class="text-sm text-slate-500">Logged in as <b><?= htmlspecialchars($_SESSION['user_name']) ?></b></span>
        <a href="login.php?logout=1" class="btn-danger">Sign out</a>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-6 py-8">
    <h1 class="font-display text-3xl font-extrabold mb-6">Subscriber Management</h1>
    
    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Active Subscriptions</p>
            <p class="text-3xl font-extrabold text-emerald-600 mt-2"><?= $stats['active_subs'] ?></p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Active Trials</p>
            <p class="text-3xl font-extrabold text-brand-600 mt-2"><?= $stats['trials'] ?></p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Past Due</p>
            <p class="text-3xl font-extrabold text-rose-500 mt-2"><?= $stats['past_due'] ?></p>
        </div>
    </div>

    <!-- Subscribers Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h2 class="font-display font-bold text-lg">All Subscribers</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-500 uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Plan / Status</th>
                        <th class="px-6 py-4">Expiry / Trial Ends</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($subscribers as $sub): ?>
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4">
                            <div class="font-semibold text-slate-800"><?= htmlspecialchars($sub['name']) ?></div>
                            <div class="text-xs text-slate-400"><?= htmlspecialchars($sub['email']) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-semibold"><?= $sub['plan'] ?: 'No Plan' ?> <span class="text-slate-400 font-normal">(RM <?= $sub['price'] ?>)</span></div>
                            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase 
                                <?= $sub['status'] == 'active' ? 'bg-emerald-50 text-emerald-600' : 
                                   ($sub['status'] == 'active_trial' ? 'bg-brand-50 text-brand-700' : 
                                   ($sub['status'] == 'past_due' ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-500')) ?>">
                                <?= $sub['status'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs text-slate-500">
                            <?php if ($sub['status'] == 'active_trial' && $sub['trial_ends_at']): ?>
                                <span class="font-mono"><?= date('M d, H:i', strtotime($sub['trial_ends_at'])) ?></span>
                            <?php elseif ($sub['period_ends_at']): ?>
                                <span class="font-mono"><?= date('M d, Y', strtotime($sub['period_ends_at'])) ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <?php if ($sub['status'] == 'active_trial' || $sub['status'] == 'suspended' || $sub['status'] == 'past_due'): ?>
                                <form method="POST" class="flex gap-1">
                                    <input type="hidden" name="user_id" value="<?= $sub['id'] ?>">
                                    <input type="hidden" name="action" value="extend_trial">
                                    <input type="hidden" name="hours" value="2">
                                    <button type="submit" class="btn-ghost" title="Reset/Extend Trial (2h)">⏱️ +2h Trial</button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($sub['status'] !== 'active'): ?>
                                <form method="POST">
                                    <input type="hidden" name="user_id" value="<?= $sub['id'] ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="btn-primary !py-1.5 !px-3 !text-xs">Activate (90d)</button>
                                </form>
                                <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="user_id" value="<?= $sub['id'] ?>">
                                    <input type="hidden" name="action" value="suspend">
                                    <button type="submit" class="btn-danger">Suspend</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
