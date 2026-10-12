
<?php
$host = "sql102.infinityfree.com";
$user = "if0_41479604";
$pass = "3UxbUUbeWXSN3Yz";
$db   = "if0_41479604_azkejorasaas";
$port = 3306;

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Database connected successfully!";
} catch (PDOException $e) {
    error_log($e->getMessage());

    // TEMPORARY DEBUGGING ONLY — remove after testing.
    echo "Database error: " . htmlspecialchars($e->getMessage());
}
?>
