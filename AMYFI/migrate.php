<?php
$conn = new mysqli('localhost','root','','amyfi');
$r = $conn->query("ALTER TABLE recurring_transactions MODIFY COLUMN type ENUM('income','expense','saving') NOT NULL DEFAULT 'expense'");
echo $r ? "OK: recurring_transactions.type now includes 'saving'" : "ERROR: ".$conn->error;
echo "\n";
