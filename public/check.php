
<?php
echo "<h3>PHP Environment Check</h3>";

echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Server Software: " .
    ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
echo "Operating System: " . PHP_OS_FAMILY . "<br>";

echo "PDO installed: " .
    (class_exists('PDO') ? 'YES' : 'NO') . "<br>";

echo "PDO MySQL installed: " .
    (extension_loaded('pdo_mysql') ? 'YES' : 'NO') . "<br>";

echo "Available PDO drivers: ";
echo implode(', ', PDO::getAvailableDrivers());
?>
