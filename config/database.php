<?php
// Supabase PostgreSQL Connection
$host = 'aws-0-ap-northeast-1.pooler.supabase.com';
$port = '6543';
$db   = 'postgres';
$user = 'postgres.jfdpnbkacxnlsquqypsy';
$pass = 'predicatenotdefined2026'; // Replace this!

// IMPORTANT: Supabase requires SSL for external connections
$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require;";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => true,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
