<?php
require_once '../../config/db.php';
/** @var mysqli $conn */
date_default_timezone_set('Asia/Kuala_Lumpur');

$token = $_GET['token'] ?? '';
$errors = [];

if (!$token) {
    die("Invalid request.");
}

$stmt = $conn->prepare("
    SELECT email FROM password_resets 
    WHERE token = ? AND expires_at > NOW()
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();

$email = '';
$token_valid = false;

if ($res->num_rows === 1) {
    $email = $res->fetch_assoc()['email'];
    $token_valid = true;
} else {
    $errors[] = "Invalid or expired token. Please request a new password reset.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - AMYFI</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 3rem 1rem;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #0f172a;
            color: white;
            font-family: 'Outfit', sans-serif;
        }
        .reset-container {
            width: 100%;
            max-width: 450px;
            padding: 3rem;
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 2.5rem;
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.7);
        }
        .form-group { margin-bottom: 1.5rem; }
        .field-label { display: block; font-size: 0.875rem; margin-bottom: 0.5rem; color: #94a3b8; }
        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1rem;
            color: white;
            font-size: 1rem;
        }
        .btn-primary {
            width: 100%;
            padding: 1rem;
            background: #6366f1;
            border: none;
            border-radius: 1rem;
            color: white;
            font-weight: 800;
            cursor: pointer;
            margin-top: 1rem;
        }
    </style>
</head>
<body>

<div class="reset-container">
    <?php if (!$token_valid): ?>
        <div style="text-align: center;">
            <div style="width: 64px; height: 64px; background: rgba(239, 68, 68, 0.1); border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; color: #ef4444;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <h2 style="font-size: 2rem; font-weight: 800; margin-bottom: 1rem;">Link Expired</h2>
            <p style="color: #94a3b8; margin-bottom: 2rem; line-height: 1.6;">
                <?= htmlspecialchars($errors[0] ?? 'Invalid request.') ?>
            </p>
        </div>
    <?php else: ?>
        <h2 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem;">Reset Password</h2>
        <p style="color: #94a3b8; margin-bottom: 2rem;">Enter your new password for <strong><?= htmlspecialchars($email) ?></strong></p>

        <form method="POST" action="process-reset.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label class="field-label">New Password</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label class="field-label">Confirm New Password</label>
                <input type="password" name="confirm" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-primary">Update Password</button>
        </form>
    <?php endif; ?>
    
    <div style="margin-top: 2rem; text-align: center;">
        <a href="login.php" style="color: #6366f1; text-decoration: none; font-size: 0.875rem; font-weight: 600;">Back to Login</a>
    </div>
</div>

</body>
</html>
