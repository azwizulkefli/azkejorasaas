<?php
// reset_admin.php
// Place this file in a secure, non-public directory. 
// Run it once via CLI or a restricted web request, then delete it immediately.

require_once '../config/database.php'; // Adjust path to your database configuration

$email = 'admin@azkejora.io';
$newPassword = 'Pr3d!ca+e'; // Set your new password here

// Generate a secure bcrypt hash
$hash = password_hash($newPassword, PASSWORD_BCRYPT);

try {
    $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$hash, $email]);
    
    if ($stmt->rowCount() > 0) {
        echo "Success: Password for {$email} has been reset.\n";
    } else {
        echo "Error: No admin account found with the email {$email}.\n";
    }
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
