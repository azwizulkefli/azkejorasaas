<?php
// Supabase PostgreSQL Connection
$host = 'db.jfdpnbkacxnlsquqypsy.supabase.co';
$port = '5432';
$db   = 'postgres';
$user = 'postgres';
$pass = 'predicatenotdefined2026'; // Replace this!

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
