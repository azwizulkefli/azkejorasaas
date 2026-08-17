<?php
require_once '../includes/auth.php';

if (isset($_GET['logout'])) {
    logout();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($email, $password)) {
        if ($_SESSION['user_role'] === 'admin') {
            header('Location: admin.php');
        } else {
            header('Location: index.php'); // Route customers to dashboard later
        }
        exit;
    } else {
        header('Location: index.php?err=1');
        exit;
    }
}
header('Location: index.php');
