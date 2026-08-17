<?php
require_once __DIR__ . '/../includes/auth.php';
$token = $_GET['token'] ?? '';
if ($token === '') { header('Location: index.php?err=notoken'); exit; }

$res = activateByToken($token);
if (!$res['ok']) {
    header('Location: index.php?err=' . urlencode($res['error'])); exit;
}
header('Location: subscriber/main.php?welcome=1');
exit;
