<?php
require_once __DIR__ . '/../includes/auth.php';
$token = $_GET['token'] ?? '';
header('Content-Type: text/plain; charset=utf-8');
echo "=== ACTIVATION DEBUG ===\n\n";
echo "Token from URL: $token\n\n";

global $pdo;
$stmt = $pdo->prepare("SELECT id, email, activated_at, activation_token FROM users WHERE activation_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if ($user) {
    echo "✅ Token found in database!\n";
    echo "User ID: {$user['id']}\n";
    echo "Email: {$user['email']}\n";
    echo "Activated At: " . ($user['activated_at'] ?? 'NULL (Not activated yet)') . "\n\n";
    
    if ($user['activated_at']) {
        echo "⚠️ DIAGNOSIS: This account is ALREADY ACTIVATED.\n";
        echo "You clicked the link once, it worked, but the dashboard redirect failed (404).\n";
        echo "Then you clicked it again, which triggered the 'expired' error.\n\n";
        echo "ACTION: Go to /public/index.php and log in normally with your password!\n";
    }
} else {
    echo "❌ Token NOT found in database.\n";
    echo "Checking latest users...\n";
    $latest = $pdo->query("SELECT email, activated_at FROM users ORDER BY created_at DESC LIMIT 3")->fetchAll();
    foreach ($latest as $u) {
        echo "- {$u['email']} | Activated: " . ($u['activated_at'] ? 'YES' : 'NO') . "\n";
    }
}
