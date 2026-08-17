<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';

function login($email, $password) {
    global $pdo;
    $email = trim(strtolower($email));
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return false;

    // Accept PHP bcrypt hashes AND Postgres pgcrypto ($2a$) hashes
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
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
        header('Location: index.php?err=auth'); exit;
    }
}

function logout() {
    session_unset(); session_destroy();
    header('Location: index.php'); exit;
}
