<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google.php';
require_once __DIR__ . '/../includes/settings.php';
ensure_settings_table($pdo);

// Verify CSRF state
$state = $_GET['state'] ?? '';
if (!$state || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    header('Location: index.php?err=oauth_state'); exit;
}
unset($_SESSION['oauth_state']);

if (isset($_GET['error']) || empty($_GET['code'])) {
    header('Location: index.php?signup=1&err=' . urlencode('Google sign-in was cancelled.')); exit;
}

// Exchange code for tokens
$tokens = google_exchange_code($_GET['code']);
if (!$tokens) {
    header('Location: index.php?signup=1&err=' . urlencode('Failed to contact Google. Try again.')); exit;
}

// Fetch user profile
$g = google_userinfo($tokens['access_token']);
if (!$g || empty($g['email'])) {
    header('Location: index.php?signup=1&err=' . urlencode('Could not read your Google account.')); exit;
}

$email = strtolower(trim($g['email']));
$name  = trim($g['name']  ?? explode('@', $email)[0]);
$gId   = (string)($g['id'] ?? '');
$pic   = $g['picture'] ?? null;

$trialHours = max(1, (int)get_setting($pdo, 'general', 'trial_default_hours', 1));

try {
    $pdo->beginTransaction();

    // Look up by google_id first, then by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR LOWER(email) = LOWER(?)");
    $stmt->execute([$gId, $email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Brand new Google user → create + auto-activate
        $dummyHash = '$2y$10$GoogleOAuthUserNoPasswordSet000000000000000000000000000'; // placeholder
        
        // 👇 Added "RETURNING id" to fetch the generated UUID
        $stmt = $pdo->prepare("INSERT INTO users
            (name, email, password_hash, role, reg_type, google_id, google_picture, activated_at)
            VALUES (?, ?, ?, 'customer', 'google', ?, ?, NOW())
            RETURNING id");
        $stmt->execute([$name, $email, $dummyHash, $gId, $pic]);
        
        // 👇 Fetches the UUID directly from the INSERT result
        $userId = $stmt->fetchColumn(); 

        // Provision trial subscription
        $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, trial_ends_at)
                        VALUES (?, 'active_trial', 0, NOW() + (? * INTERVAL '1 hour'))")
            ->execute([$userId, $trialHours]);

        // Fetch full user record for session
        $full = $pdo->prepare("SELECT * FROM users WHERE id = ?"); 
        $full->execute([$userId]);
        $user = $full->fetch();

    } else {
        // Existing user → link Google ID if missing, ensure activated, log in
        if (!$user['google_id'] && $user['reg_type'] === 'manual') {
            // Don't override manual accounts; just link Google for convenience
            $pdo->prepare("UPDATE users SET google_id = ?, google_picture = COALESCE(google_picture, ?) WHERE id = ?")
                ->execute([$gId, $pic, $user['id']]);
        }
        if (empty($user['activated_at'])) {
            $pdo->prepare("UPDATE users SET activated_at = NOW() WHERE id = ?")->execute([$user['id']]);
        }
        // Ensure a subscription row exists
        $has = $pdo->prepare("SELECT 1 FROM subscriptions WHERE user_id = ?");
        $has->execute([$user['id']]);
        if (!$has->fetchColumn()) {
            $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, trial_ends_at)
                            VALUES (?, 'active_trial', 0, NOW() + (? * INTERVAL '1 hour'))")
                ->execute([$user['id'], $trialHours]);
        }
    }

    $pdo->commit();

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];

    // Google users without a password must set one on first dashboard visit
    $needsPwd = empty($user['password_hash']) ? 1 : 0;
    header('Location: /subscriber/main.php?welcome=1&setup_pwd=' . $needsPwd);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: index.php?signup=1&err=' . urlencode('Google sign-in failed: ' . $e->getMessage()));
    exit;
}
