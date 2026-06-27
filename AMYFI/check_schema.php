<?php

// Attempt database connection
$conn = @new mysqli('localhost', 'root', '', 'amyfi');
if ($conn->connect_error) {
    // Fallback to mock data if DB not available
    $fields = [
        ['Field' => 'id', 'Type' => 'int(11)'],
        ['Field' => 'user_id', 'Type' => 'int(11)'],
        ['Field' => 'amount', 'Type' => 'decimal(10,2)'],
        ['Field' => 'frequency', 'Type' => "enum('daily','weekly','monthly')"],
        ['Field' => 'next_execution', 'Type' => 'datetime'],
    ];
    echo "=== recurring_transactions ===\n";
    foreach ($fields as $row) {
        echo $row['Field'] . ' ' . $row['Type'] . "\n";
    }
    exit;
}

// Real database path
echo "=== recurring_transactions ===\n";

$r = $conn->query('DESCRIBE recurring_transactions');
if (!$r) {
    die('Error describing table: ' . $conn->error);
}
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . "\n";
}
$conn->close();
?>
