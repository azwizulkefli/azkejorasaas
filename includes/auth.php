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

    /* 2) Fallback: users table (customers + team members + legacy admin) */
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if ($user['role'] !== 'admin' && empty($user['activated_at'])) return false;

    $ok = password_verify($password, $user['password_hash'])
       || crypt($password, $user['password_hash']) === $user['password_hash'];
    if ($ok) {
        $_SESSION['user_id']           = $user['id'];
        $_SESSION['user_name']         = $user['name'];
        $_SESSION['user_email']        = $user['email'];
        $_SESSION['user_role']         = $user['role'];
        $_SESSION['subscription_id']   = $user['subscription_id'] ?? null;
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

    try {
        $pdo->beginTransaction();

        /* 1) Insert into users table and get the new user ID */
        $stmtUser = $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role, activation_token)
                                   VALUES (?, ?, ?, ?, 'customer', ?) RETURNING id");
        $stmtUser->execute([$name, $email, $phone, $hash, $token]);
        $newUserId = $stmtUser->fetchColumn();

        /* 2) Insert into subscriber_users table (The registering user is the first Admin/Owner of their workspace) */
        $stmtSubUser = $pdo->prepare("INSERT INTO subscriber_users (owner_id, user_id, email, name, phone, role, status)
                                      VALUES (?, ?, ?, ?, ?, 'admin', 'active')");
        $stmtSubUser->execute([$newUserId, $newUserId, $email, $name, $phone]);

        $pdo->commit();

        return ['ok'=>true, 'error'=>null, 'token'=>$token, 'email'=>$email, 'name'=>$name];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok'=>false, 'error'=>'Registration failed. Please try again.'];
    }
}

/**
 * Helper: Syncs subscription_id across users, subscriber_users, and companies
 */
function syncSubscriptionId($pdo, string $userId, string $subscriptionId): void {
    // 1. Update main users table
    $pdo->prepare("UPDATE users SET subscription_id = ? WHERE id = ?")
        ->execute([$subscriptionId, $userId]);

    // 2. Update ALL subscriber_users under this owner (so team members inherit the sub)
    $pdo->prepare("UPDATE subscriber_users SET subscription_id = ? WHERE owner_id = ?")
        ->execute([$subscriptionId, $userId]);

    // 3. Update companies table (if the user has already created their company profile)
    $pdo->prepare("UPDATE companies SET subscription_id = ? WHERE user_id = ?")
        ->execute([$subscriptionId, $userId]);
}

function activateByToken(string $token): array {
    global $pdo;
    ensure_settings_table($pdo);
    $trialH = max(1, (int)get_setting($pdo, 'general', 'trial_default_hours', 1));

    $u = $pdo->prepare("SELECT id FROM users WHERE activation_token = ? AND activated_at IS NULL");
    $u->execute([$token]);
    $user = $u->fetch();
    if (!$user) return ['ok'=>false, 'error'=>'Invalid or expired activation link.'];

    try {
        $pdo->beginTransaction();

        /* 1) Activate user account */
        $pdo->prepare("UPDATE users SET activation_token = NULL, activated_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        /* 2) Auto-provision free trial subscription and fetch the new subscription_id */
        $stmtSub = $pdo->prepare("INSERT INTO subscriptions (user_id, status, price, trial_ends_at)
                                  VALUES (?, 'active_trial', 0, NOW() + (? * INTERVAL '1 hour')) RETURNING id");
        $stmtSub->execute([$user['id'], $trialH]);
        $newSubId = $stmtSub->fetchColumn();

        /* 3) SYNC SUBSCRIPTION ID TO ALL 3 TABLES */
        syncSubscriptionId($pdo, $user['id'], $newSubId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok'=>false, 'error'=>'Activation failed: ' . $e->getMessage()];
    }

    /* Log the user in */
    $full = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $full->execute([$user['id']]);
    $row = $full->fetch();
    
    $_SESSION['user_id']           = $row['id'];
    $_SESSION['user_name']         = $row['name'];
    $_SESSION['user_email']        = $row['email'];
    $_SESSION['user_role']         = $row['role'];
    $_SESSION['subscription_id']   = $row['subscription_id'] ?? null;

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

function logout() { 
    session_unset(); 
    session_destroy(); 
    header('Location: /public/index.php'); 
    exit; 
}
