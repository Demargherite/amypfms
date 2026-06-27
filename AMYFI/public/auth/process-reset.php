<?php
session_start();
require_once '../../config/db.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

$token    = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm'] ?? '';

if (!$token || !$password || !$confirm) {
    die("All fields are required.");
}

if ($password !== $confirm) {
    die("Passwords do not match.");
}

if (strlen($password) < 8) {
    die("Password must be at least 8 characters.");
}

$stmt = $conn->prepare("
    SELECT email FROM password_resets 
    WHERE token = ? AND expires_at > NOW()
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
    die("Invalid or expired token.");
}

$email = $res->fetch_assoc()['email'];
$hash  = password_hash($password, PASSWORD_DEFAULT);

/* UPDATE PASSWORD */
$update = $conn->prepare("
    UPDATE users SET password_hash = ? WHERE email = ?
");
$update->bind_param("ss", $hash, $email);
$update->execute();

/* DELETE TOKEN */
$delete = $conn->prepare("
    DELETE FROM password_resets WHERE email = ?
");
$delete->bind_param("s", $email);
$delete->execute();

$_SESSION['success_msg'] = "Password updated successfully. You can now login.";
header("Location: login.php");
exit;
