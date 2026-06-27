<?php
require_once __DIR__ . '/../config/constants.php';
if (empty($_GET['token']) || $_GET['token'] !== CRON_TOKEN) {
    http_response_code(403);
    exit('Forbidden – invalid token');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$today = date('Y-m-d');
$in3days = date('Y-m-d', strtotime('+3 days'));

/***********************************************
 * GET ALL USERS
 ***********************************************/
$users = $conn->query("SELECT id, name, email FROM users WHERE status = 'active'");

while ($user = $users->fetch_assoc()) {

    $user_id = $user['id'];
    $email   = $user['email'];
    $name    = $user['name'];

    $upcoming = [];
    $overdue  = [];

    /***********************************************
     * GET UPCOMING (EXACTLY 3 DAYS BEFORE)
     ***********************************************/
    // Example: Due on 24th. Today is 21st.
    // 21st + 3 days = 24th. So if next_run_date = 24th, trigger today.
    $stmt = $conn->prepare("
        SELECT c.name AS category_name, rt.amount, rt.next_run_date, rt.id
        FROM recurring_transactions rt
        JOIN categories c ON c.id = rt.category_id
        WHERE rt.user_id = ?
          AND rt.type = 'expense'
          AND rt.is_active = 1
          AND DATE(rt.next_run_date) = ?
          AND (rt.last_notified IS NULL OR rt.last_notified != ?)
    ");

    $stmt->bind_param("iss", $user_id, $in3days, $today);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $upcoming[] = $row;
    }

    /***********************************************
     * GET OVERDUE (1 DAY AFTER DUE DATE)
     ***********************************************/
    // Example: Due on 24th. Today is 25th.
    // We only trigger exactly 1 day after they missed it so we don't spam every day.
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $conn->prepare("
        SELECT c.name AS category_name, rt.amount, rt.next_run_date, rt.id
        FROM recurring_transactions rt
        JOIN categories c ON c.id = rt.category_id
        WHERE rt.user_id = ?
          AND rt.type = 'expense'
          AND rt.is_active = 1
          AND DATE(rt.next_run_date) = ?
          AND (rt.last_notified IS NULL OR rt.last_notified != ?)
    ");

    $stmt->bind_param("iss", $user_id, $yesterday, $today);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $overdue[] = $row;
    }

    /***********************************************
     * SKIP IF NO DATA
     ***********************************************/
    if (empty($upcoming) && empty($overdue)) {
        continue;
    }

    /***********************************************
     * BUILD EMAIL CONTENT
     ***********************************************/
    $message = "
    <div style='font-family: sans-serif; max-width:600px; padding:20px'>
        <h2 style='color:#6366f1;'>Bill Reminder</h2>
        <p>Hi <strong>$name</strong>,</p>
        <p>Here is your bill summary:</p>
    ";

    if (!empty($overdue)) {
        $message .= "<h3 style='color:red;'>Overdue Bills</h3><ul>";
        foreach ($overdue as $bill) {
            $message .= "<li>{$bill['category_name']} - RM {$bill['amount']} (Due: {$bill['next_run_date']})</li>";
        }
        $message .= "</ul>";
    }

    if (!empty($upcoming)) {
        $message .= "<h3 style='color:#f59e0b;'>Due in 3 Days</h3><ul>";
        foreach ($upcoming as $bill) {
            $message .= "<li>{$bill['category_name']} - RM {$bill['amount']} (Due: {$bill['next_run_date']})</li>";
        }
        $message .= "</ul>";
    }

    $message .= "
        <p>Please make payment to avoid penalties.</p>
        <p style='color:#94a3b8;font-size:12px;'>- AMYFI System</p>
    </div>
    ";

    /***********************************************
     * SEND EMAIL (1 EMAIL ONLY)
     ***********************************************/
    sendEmail($email, "AMYFI Bill Reminder", $message, $name);

    /***********************************************
     * UPDATE last_notified 
     ***********************************************/
    $notified_ids = [];
    foreach ($upcoming as $u) $notified_ids[] = $u['id'];
    foreach ($overdue as $o) $notified_ids[] = $o['id'];

    if (!empty($notified_ids)) {
        $placeholders = str_repeat('?,', count($notified_ids) - 1) . '?';
        $types = "s" . str_repeat('i', count($notified_ids));
        
        $params = array_merge([$today], $notified_ids);
        
        $stmt = $conn->prepare("
            UPDATE recurring_transactions
            SET last_notified = ?
            WHERE id IN ($placeholders)
        ");
        
        $stmt->execute($params);
    }
}

echo "Reminder script executed successfully.";
