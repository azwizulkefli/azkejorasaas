<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

$res = registerCustomer($_POST);
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if (!$res['ok']) {
    header('Location: index.php?signup=1&err=' . urlencode($res['error'])); exit;
}

$link = $base . '/public/activate.php?token=' . $res['token'];
sendActivationEmail($res['email'], $res['name'], $link);

// Redirect to "check your email" screen
header('Location: index.php?check=1&email=' . urlencode($res['email'])
     . '&fallback=' . urlencode($link));
exit;
