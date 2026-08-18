<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

function needsPasswordSetup(): bool {
    if (!currentUserId()) return false;
    global $pdo;
    $st = $pdo->prepare("SELECT password_hash, reg_type FROM users WHERE id = ?");
    $st->execute([currentUserId()]);
    $u = $st->fetch();
    if (!$u || $u['reg_type'] !== 'google') return false;
    return empty($u['password_hash'])
        || str_starts_with((string)$u['password_hash'], '$2y$10$GoogleOAuth');
}

function login(string $email, string $password): bool {
    global $pdo;

    /* 1) Dedicated admin store (admin_users) */
    try {
        $a = $pdo->prepare("SELECT * FROM admin_users WHERE LOWER(email) = LOWER(?)");
        $a->execute([$email]);
        $admin = $a->fetch();
        if ($admin && ($admin['status'] ?? 'active') === 'active' && !empty($admin['password_hash'])) {
            $ok = password_verify($password, $admin['password_hash'])
               || crypt($password, $admin['password_hash']) === $admin['password_hash'];
            if ($ok) {
                $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
                $_SESSION['user_id']    = $admin['id'];
                $_SESSION['user_name']  = $admin['name'];
                $_SESSION['user_email'] = $admin['email'];
                $_SESSION['user_role']  = $admin['role'] ?: 'admin';
                return true;
            }
            return false; // admin account exists → wrong password, stop here
        }
    } catch (Throwable $e) { /* admin_users missing → fall back */ }

    /* 2) Fallback: users table (customers + legacy admin) */
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if ($user['role'] !== 'admin' && empty($user['activated_at'])) return false;

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

function requireAdmin() {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true)) {
        header('Location: index.php?err=auth'); exit;
    }
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

function logout() { session_unset(); session_destroy(); header('Location: /public/index.php'); exit; }
