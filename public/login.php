<?php
require_once __DIR__ . '/../includes/auth.php';

/* Sign out */
if (isset($_GET['logout'])) { logout(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (login($email, $pass)) {
        /* ✅ Role-based redirect */
        if ($_SESSION['user_role'] === 'admin') {
            header('Location: admin.php');                      // admin → admin console
        } else {
            header('Location: /public/subscriber/main.php?welcome=1');  // subscriber → customer dashboard
        }
        exit;
    }

    /* ❌ Failed login — distinguish "not activated" vs "wrong credentials" */
    $st = $pdo->prepare("SELECT activated_at, role FROM users WHERE LOWER(email) = LOWER(?)");
    $st->execute([$email]);
    $row  = $st->fetch();
    // Only show "not activated" error for non-admins
    $code = ($row && $row['role'] !== 'admin' && empty($row['activated_at'])) ? 2 : 1;
    header("Location: index.php?err=$code");
    exit;
}

header('Location: index.php');
exit;
