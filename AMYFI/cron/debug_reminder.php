<?php
require_once __DIR__ . '/../config/db.php';

date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');
$in3days = date('Y-m-d', strtotime('+3 days'));
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "=== DEBUG BILL REMINDER ===\n";
echo "Today: $today\n";
echo "In 3 days: $in3days\n";
echo "Yesterday: $yesterday\n\n";

// 1. Check if last_notified column exists
echo "--- TABLE SCHEMA ---\n";
$r = $conn->query('DESCRIBE recurring_transactions');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | Null:' . $row['Null'] . ' | Default:' . $row['Default'] . "\n";
    }
} else {
    echo "ERROR: " . $conn->error . "\n";
}

echo "\n--- ALL ACTIVE RECURRING EXPENSES ---\n";
$r = $conn->query("SELECT rt.id, rt.user_id, rt.amount, rt.type, rt.is_active, rt.next_run_date, rt.last_notified, rt.category_id, c.name AS cat_name FROM recurring_transactions rt LEFT JOIN categories c ON c.id = rt.category_id WHERE rt.type = 'expense' AND rt.is_active = 1 ORDER BY rt.next_run_date ASC");

if ($r && $r->num_rows > 0) {
    while ($row = $r->fetch_assoc()) {
        echo "ID:{$row['id']} | User:{$row['user_id']} | Cat:{$row['cat_name']} | Amount:{$row['amount']} | NextRun:{$row['next_run_date']} | LastNotified:{$row['last_notified']}\n";
        
        // Check if this would match the 3-day reminder
        if ($row['next_run_date'] == $in3days) {
            echo "  >> WOULD MATCH 3-day reminder (reminder.php)\n";
        }
        if ($row['next_run_date'] >= $today && $row['next_run_date'] <= $in3days) {
            echo "  >> WOULD MATCH range reminder (send_bill_reminder.php)\n";
        }
        if ($row['next_run_date'] == $yesterday) {
            echo "  >> WOULD MATCH overdue reminder\n";
        }
    }
} else {
    echo "NO active recurring expenses found!\n";
}

echo "\n--- USERS ---\n";
$r = $conn->query("SELECT id, name, email, status FROM users LIMIT 10");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo "ID:{$row['id']} | {$row['name']} | {$row['email']} | Status:{$row['status']}\n";
    }
}
echo "\nDone.\n";
