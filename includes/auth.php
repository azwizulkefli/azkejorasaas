<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

function login(string $email, string $password): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND activated_at IS NOT NULL");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return false;
    $ok = password_verify($password, $user['password_hash'])
       || crypt($password, $user['password_hash']) === $user['password_hash'];
    if ($ok) {
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];
        return true;
    }
    return false;
}

function registerCustomer(array $data): array {
    /* Returns ['ok'=>bool, 'error'=>string|null, 'token'=>string|null] */
    global $pdo;
    $name  = trim($data['name']  ?? '');
    $email = trim(strtolower($data['email'] ?? ''));
    $phone = trim($data['phone'] ?? '');
    $pass  = $data['password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '')
        return ['ok'=>false, 'error'=>'Please fill name, valid email and phone.'];
        
    if (strlen($pass) < 6)
        return ['ok'=>false, 'error'=>'Password must be at least 6 characters.'];

    $exists = $pdo->prepare("SELECT id, activated_at FROM users WHERE LOWER(email) = LOWER(?)");
    $exists->execute([$email]);
    $row = $exists->fetch();
    if ($row) {
        return $row['activated_at']
            ? ['ok'=>false, 'error'=>'This email is already registered — please sign in.']
            : ['ok'=>false, 'error'=>'Activation pending for this email. Check your inbox.'];
    }

    $token = bin2hex(random_bytes(32));
    $hash  = password_hash($pass, PASSWORD_BCRYPT);

    $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role, activation_token)
                    VALUES (?, ?, ?, ?, 'customer', ?)")
        ->execute([$name, $email, $phone, $hash, $token]);

    return ['ok'=>true, 'error'=>null, 'token'=>$token, 'email'=>$email, 'name'=>$name];
}

function activateByToken(string $token): array {
    global $pdo;
    ensure_settings_table($pdo);
    $trialH = max(1, (int)get_setting($pdo, 'general', 'trial_default_hours', 1));

    $u = $pdo->prepare("SELECT id FROM users WHERE activation_token = ? AND activated_at IS NULL");
    $u->execute([$token]);
    $user = $u->fetch();
    if (!$user) return ['ok'=>false, 'error'=>'Invalid or expired activation link.'];

    $pdo->prepare("UPDATE users SET activation_token = NULL, activated_at = NOW() WHERE id = ?")
        ->execute([$user['id']]);

    // Auto-provision free trial subscription
    $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, trial_ends_at)
                    VALUES (?, 'active_trial', 0, NOW() + (? * INTERVAL '1 hour'))")
        ->execute([$user['id'], $trialH]);

    // Log the user in
    $full = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $full->execute([$user['id']]);
    $row = $full->fetch();
    $_SESSION['user_id']    = $row['id'];
    $_SESSION['user_name']  = $row['name'];
    $_SESSION['user_email'] = $row['email'];
    $_SESSION['user_role']  = $row['role'];

    return ['ok'=>true, 'error'=>null, 'trial_hours'=>$trialH];
}

function currentUserId(): ?string { return $_SESSION['user_id'] ?? null; }
function currentUser(): ?array {
    if (!currentUserId()) return null;
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([currentUserId()]);
    return $st->fetch() ?: null;
}
function requireCustomer() {
    if (!currentUserId() || ($_SESSION['user_role'] ?? '') !== 'customer') {
        header('Location: /public/index.php?err=auth'); exit;
    }
}
function requireAdmin() {
    if (!currentUserId() || ($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: /public/index.php?err=auth'); exit;
    }
}
function logout() { session_unset(); session_destroy(); header('Location: /public/index.php'); exit; }
