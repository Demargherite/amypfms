<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

$errors = [];
$warnings = [];

/***********************************************
GET USER INCOME
***********************************************/
$stmt = $conn->prepare("
SELECT monthly_income
FROM user_settings
WHERE user_id = ?
");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

$income = (float)($data['monthly_income'] ?? 0);

$saving_goal = (float)($_POST['saving_goal'] ?? 0);


/***********************************************
INCOME-BASED SAVING LIMIT
***********************************************/
$max_saving_percentage = 0;

if ($income >= 500 && $income <= 1000) {
    $max_saving_percentage = 0.15;
}
elseif ($income >= 2000 && $income <= 2500) {
    $max_saving_percentage = 0.10;
}
elseif ($income >= 3000 && $income <= 6000) {
    $max_saving_percentage = 0.20;
}
elseif ($income >= 7000 && $income <= 9000) {
    $max_saving_percentage = 0.25;
}
elseif ($income >= 10000) {
    $max_saving_percentage = 0.40;
}

$max_allowed_saving = $income * $max_saving_percentage;


/***********************************************
VALIDATION RULES
***********************************************/

/* BLOCK if exceed limit */
if ($max_saving_percentage > 0 && $saving_goal > $max_allowed_saving) {

    $errors[] =
    "Based on your income, the maximum recommended saving is "
    . ($max_saving_percentage * 100) . "% (RM "
    . number_format($max_allowed_saving,2) . ").";
}


/* SMART RULE 1 */
if ($saving_goal > $income) {
    $errors[] = "Monthly saving goal cannot exceed your monthly income.";
}


/* FIXED EXPENSE ESTIMATION */
$estimated_fixed_expenses = $income * 0.4;


/* SMART RULE 2 */
if (($income - $saving_goal - $estimated_fixed_expenses) < 0) {
    $errors[] =
    "After savings and estimated expenses, your income may be insufficient.";
}


/***********************************************
IF ERROR → REDIRECT BACK WITH MESSAGE
***********************************************/
if (!empty($errors)) {

    $_SESSION['saving_goal_error'] = implode("<br>", $errors);

    // buka semula modal saving goal
    $_SESSION['open_saving_modal'] = true;

    header("Location: ../dashboard/dashboard.php");
    exit;
}


/***********************************************
UPDATE SAVING GOAL
***********************************************/
$stmt = $conn->prepare("
UPDATE user_settings
SET savings_goal = ?
WHERE user_id = ?
");

$stmt->bind_param("di",$saving_goal,$user_id);
$stmt->execute();


/***********************************************
SUCCESS MESSAGE
***********************************************/
$_SESSION['saving_goal_success'] = "Saving goal updated successfully.";

header("Location: ../dashboard/dashboard.php");
exit;