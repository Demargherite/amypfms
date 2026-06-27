<?php
require 'c:/xampp/htdocs/AMYFI_FYP/AMYFI/config/db.php';
$res = $conn->query('DESCRIBE categories');
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}
echo "--- SAVINGS GOALS ---\n";
$res = $conn->query('DESCRIBE savings_goals');
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}
?>
