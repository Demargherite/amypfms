<?php
// Database connection - MySQLi version

$servername = "localhost";
$username = "root";
$password = "";
$database = "amyfi";

try {
    // Create connection
    $conn = new mysqli($servername, $username, $password, $database);

    // Check connection
    if ($conn->connect_error) {
        die("Database Connection Failed: " . $conn->connect_error);
    }
    
    // Set charset and collation to avoid Hostinger 500 errors on DATE_FORMAT
    $conn->set_charset("utf8mb4");
    $conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
} catch (Exception $e) {
    die("Database Connection Failed: " . $e->getMessage() . "<br><br><b>Hint:</b> Make sure your config/db.php uses the correct database credentials for Hostinger. (Often 'localhost' is still used for the host, but username/database/password will differ).");
}
?>
