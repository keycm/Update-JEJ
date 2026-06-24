<?php
// db.php - Database connection setup
$host = 'localhost';
$dbname = 'u574500774_JEJcorp';
$db_user = 'u574500774_JEJcotporation'; // Change if your MySQL user is different
$db_pass = 'JEJtoppriority2026';     // Change if your MySQL has a password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>

