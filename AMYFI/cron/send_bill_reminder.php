<?php
require_once __DIR__ . '/../config/constants.php';

// ── Token security — block unauthorised access ──
if (PHP_SAPI !== 'cli') {
    if (empty($_GET['token']) || $_GET['token'] !== CRON_TOKEN) {
        http_response_code(403);
        exit('Forbidden – invalid token');
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');

/***********************************************
 * GET UPCOMING AND OVERDUE BILLS
 * Filter: type='expense', active, not yet notified today,
 * next_run_date is less than or equal to today + 3 days.
 ***********************************************/
$stmt = $conn->prepare("
    SELECT 
        rt.id AS transaction_id,
        u.id AS user_id,
        u.email,
        u.name,
        rt.amount,
        rt.next_run_date,
        c.name AS category_name
    FROM recurring_transactions rt
    JOIN users u ON u.id = rt.user_id
    JOIN categories c ON c.id = rt.category_id
    WHERE rt.type = 'expense'
      AND rt.is_active = 1
      AND rt.next_run_date <= DATE_ADD(?, INTERVAL 3 DAY)
      AND (rt.last_notified IS NULL OR rt.last_notified != ?)
");

$stmt->bind_param("ss", $today, $today);
$stmt->execute();
$result = $stmt->get_result();

$users_bills = [];

while($row = $result->fetch_assoc()){
    $user_id = $row['user_id'];
    if (!isset($users_bills[$user_id])) {
        $users_bills[$user_id] = [
            'email' => $row['email'],
            'name' => $row['name'],
            'bills' => [],
            'transaction_ids' => []
        ];
    }
    $users_bills[$user_id]['bills'][] = $row;
    $users_bills[$user_id]['transaction_ids'][] = $row['transaction_id'];
}

foreach ($users_bills as $user_id => $data) {
    $to = $data['email'];
    $name = $data['name'];
    $subject = "Daily Bill Reminder - AMYFI";
    
    $message = "
    <div style='font-family: sans-serif; max-width: 600px; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <h2 style='color: #6366f1;'>Daily Bill Reminder</h2>
        <p>Hi <strong>{$name}</strong>,</p>
        <p>This is a summary of your upcoming and overdue bills:</p>
    ";

    foreach ($data['bills'] as $bill) {
        $is_overdue = $bill['next_run_date'] < $today;
        $status_color = $is_overdue ? "#ef4444" : "#f59e0b";
        $status_text = $is_overdue ? "OVERDUE" : "UPCOMING";
        
        $message .= "
        <div style='background: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid {$status_color};'>
            <p style='margin: 5px 0;'><strong>Category:</strong> {$bill['category_name']}</p>
            <p style='margin: 5px 0;'><strong>Amount:</strong> RM {$bill['amount']}</p>
            <p style='margin: 5px 0;'>
                <strong>Due Date:</strong> {$bill['next_run_date']} 
                <span style='color: {$status_color}; font-weight: bold; margin-left: 10px;'>($status_text)</span>
            </p>
        </div>";
    }

    $message .= "
        <p>Please ensure you have sufficient balance to cover these transactions.</p>
        <p style='color: #94a3b8; font-size: 0.8rem;'>- AMYFI Financial System</p>
    </div>
    ";

    if(sendEmail($to, $subject, $message, $name)){
        // Mark all these transactions as notified for today
        $ids = implode(",", array_map('intval', $data['transaction_ids']));
        $conn->query("UPDATE recurring_transactions SET last_notified = '$today' WHERE id IN ($ids)");
        echo "Email sent to {$to} with " . count($data['bills']) . " bill(s).\n";
    } else {
        echo "Failed to send to {$to}\n";
    }
}

if (empty($users_bills)) {
    echo "No bills need reminding today.\n";
}
