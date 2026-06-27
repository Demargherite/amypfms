<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../../config/db.php';
/** @var mysqli $conn */

require '../../PHPMailer/src/PHPMailer.php';
require '../../PHPMailer/src/SMTP.php';
require '../../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$errors = [];
/* =========================
   FORGOT PASSWORD HANDLER
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'forgot') {

    $email = trim($_POST['forgot_email'] ?? '');

    if ($email === '') {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    } else {

        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ?"
        );
        $check->bind_param("s", $email);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows === 0) {
            $errors[] = "Email not registered.";
        } else {
            $user_id = $res->fetch_assoc()['id'];

            // Generate token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            // DELETE ANY OLD TOKENS
            $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $del->bind_param("s", $email);
            $del->execute();

            $stmt = $conn->prepare("
                INSERT INTO password_resets (email, user_id, token, expires_at)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("siss", $email, $user_id, $token, $expires);
            $stmt->execute();

            // Build reset link dynamically — works on localhost AND Hostinger
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];                         // e.g. amyfi.my or localhost
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);              // e.g. /AMYFI_FYP/AMYFI/public/auth
            $resetLink = "$protocol://$host$scriptDir/reset-password.php?token=$token";

            try {
                $mail = new PHPMailer(true);

                // SMTP settings
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;

$mail->Username = 'amyqmy12@gmail.com';
$mail->Password = 'ovjjhtrtrqfoessg';

$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
$mail->setFrom('amyqmy12@gmail.com', 'AMYFI');

$mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Reset Your Password';

                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                        <h2 style='color: #1e293b;'>Password Reset Request</h2>
                        <p style='color: #475569;'>Click the button below to reset your password:</p>
                        <div style='margin: 30px 0;'>
                            <a href='$resetLink' style='
                                padding: 12px 24px;
                                background: #6366f1;
                                color: white;
                                text-decoration: none;
                                border-radius: 8px;
                                font-weight: bold;
                                display: inline-block;
                            '>Reset Password</a>
                        </div>
                        <p style='color: #94a3b8; font-size: 0.875rem;'>This link will expire in 30 minutes.</p>
                        <p style='color: #94a3b8; font-size: 0.875rem;'>If you didn't request this, please ignore this email.</p>
                    </div>
                ";

                $mail->send();

                $_SESSION['success_msg'] = "Reset link has been sent to your email.";

            } catch (Exception $e) {
                $errors[] = "Email failed: " . $mail->ErrorInfo;
            }
        }
    }
}
/* =========================
   LOGIN ATTEMPT LIMIT
========================= */
if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

/* Clear old attempts (1 minute) */
$_SESSION['login_attempts'] = array_values(
    array_filter($_SESSION['login_attempts'], function ($t) {
        return is_int($t) && $t > (time() - 60);
    })
);


if (count($_SESSION['login_attempts']) >= 5) {
    $errors[] = "Too many failed login attempts. Please try again later.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($errors)) {

    $_SESSION['login_attempts'][] = time();

    $email = trim($_POST["email"] ?? '');
    $password = trim($_POST["password"] ?? '');

    /* =========================
       BACKEND VALIDATION
    ========================= */
    if ($email === '' || $password === '') {
        $errors[] = "Email and password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "SELECT id, name, password_hash, status
             FROM users
             WHERE email = ?
             LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['status'] !== 'active') {
                $errors[] = "Your account has been deactivated by admin.";
            } elseif (password_verify($password, $user["password_hash"])) {
                session_regenerate_id(true);

                $_SESSION["user_id"]   = $user["id"];
                $_SESSION["user_name"] = $user["name"];

                $_SESSION['login_attempts'] = [];

                header("Location: ../dashboard/dashboard.php");
                exit;
            } else {
                $errors[] = "Incorrect password.";

                /* SAVE FAILED LOGIN */
                $log = $conn->prepare("
                    INSERT INTO system_logs (user_id, action)
                    VALUES (?, ?)
                ");
                $action = "Failed login attempt";
                $log->bind_param("is", $user['id'], $action);
                $log->execute();
            }
        } else {
            $errors[] = "Email not registered.";

            /* UNKNOWN EMAIL LOGIN */
            $log = $conn->prepare("
                INSERT INTO system_logs (user_id, action)
                VALUES (NULL, ?)
            ");
            $action = "Failed login - unknown email: $email";
            $log->bind_param("s", $action);
            $log->execute();
        }

        $stmt->close();
    }
}

$pageTitle = "Login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AMYFI</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Google Font: Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(30, 41, 59, 0.4);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-glow: rgba(99, 102, 241, 0.2);
            --visual-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
        }

        body {
            margin: 0;
            padding: 3rem 1rem;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(34, 211, 238, 0.08) 0px, transparent 50%),
                url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.03'/%3E%3C/svg%3E");
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            overflow-y: auto;
        }

        .auth-container {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: 600px;
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            border-radius: 3rem;
            margin: auto;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.7);
            animation: containerEntrance 1s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes containerEntrance {
            from { opacity: 0; transform: scale(0.95) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* Hero Section */
        .auth-visual {
            flex: 1;
            background: var(--visual-gradient);
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            position: relative;
            overflow: hidden;
        }

        .auth-visual::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(99, 102, 241, 0.25) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(34, 211, 238, 0.15) 0%, transparent 50%);
            z-index: 1;
        }

        .auth-logo-float {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            color: white;
            font-weight: 800;
            font-size: 1.75rem;
            box-shadow: 0 20px 40px -10px var(--primary-glow);
            z-index: 2;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(-5deg); }
            50% { transform: translateY(-15px) rotate(-2deg); }
        }

        .auth-visual-text {
            position: relative;
            z-index: 2;
            text-align: left;
        }

        /* Form Section */
        .auth-form-section {
            flex: 1.3;
            padding: 4rem;
            background: rgba(15, 23, 42, 0.2);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 3rem;
        }

        .form-group {
            margin-bottom: 2rem;
        }

        .field-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--text-dim);
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 1.25rem;
            color: var(--text-main);
            font-size: 1rem;
            font-weight: 500;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background: rgba(15, 23, 42, 0.6);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--glass-glow);
            outline: none;
        }

        .btn-auth {
            width: 100%;
            padding: 1.25rem;
            font-size: 1.125rem;
            font-weight: 800;
            border-radius: 1.25rem;
            margin-top: 1rem;
            box-shadow: 0 10px 20px -5px var(--primary-glow);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-auth:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px var(--primary-glow);
        }

        @media (max-width: 1024px) {
            .auth-container { max-width: 900px; min-height: 600px; }
            .auth-visual, .auth-form-section { padding: 4rem; }
        }

        @media (max-width: 850px) {
            .auth-container { flex-direction: column; max-width: 500px; height: auto; border-radius: 2.5rem; }
            .auth-visual { display: none; }
            .auth-form-section { padding: 4rem 2rem; }
            body { overflow-y: auto; padding: 2rem 1rem; }
        }
    </style>
</head>
<body>
<?php include '../../includes/header-auth.php'; ?>


<div class="auth-container">
    <div class="auth-visual">
        <div class="auth-logo-float">AF</div>
        <div class="auth-visual-text">
            <h2 style="color: white; font-size: 2.75rem; font-weight: 800; margin-bottom: 1.5rem; letter-spacing: -0.03em; line-height: 1.1;">Smarter Wealth Management.</h2>
            <p style="color: var(--text-dim); font-size: 1.125rem; line-height: 1.7; max-width: 340px;">Join thousands of families optimizing their financial future with AMYFI.</p>
        </div>
    </div>

    <div class="auth-form-section">
        <div class="form-header">
            <h1 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.02em;">Welcome Back</h1>
            <p style="color: var(--text-dim); font-size: 1rem;">Enter your credentials to access your dashboard.</p>
        </div>

        <?php if (!empty($_SESSION['success_msg'])): ?>
            <div class="alert-card" style="margin-bottom: 2rem; background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.2); color: var(--success); padding: 1.25rem; border-radius: 1.25rem;">
                <div style="display: flex; gap: 0.75rem; align-items: center; font-weight: 600;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                </div>
            </div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert-card" style="margin-bottom: 2rem; background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: var(--danger); padding: 1.25rem; border-radius: 1.25rem;">
                <div id="serverErrors">
                    <?php foreach ($errors as $err) echo "<div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.5rem;'><svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg> $err</div>"; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return validateLogin()" novalidate>
            <div class="form-group">
                <label class="field-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="name@example.com" autofocus>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <label class="field-label" style="margin: 0;">Password</label>
                    <a href="javascript:void(0)" onclick="openForgotModal()" style="font-size: 0.8125rem; color: var(--primary); font-weight: 700; text-decoration: none;">Forgot password?</a>
                </div>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••">
                    <span style="position: absolute; right: 1.25rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-dim); display: flex;" onclick="togglePassword('password')">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                </div>
            </div>

            <button class="btn btn-primary btn-auth" type="submit">Sign In </button>

            <p style="text-align: center; margin-top: 3rem; font-size: 0.9375rem; color: var(--text-dim);">
                New here? <a href="register.php" style="color: var(--primary); font-weight: 800; text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.3s ease;" onmouseover="this.style.borderBottomColor='var(--primary)'" onmouseout="this.style.borderBottomColor='transparent'">Create an account</a>
            </p>
        </form>
    </div>
</div>

<!-- FORGOT PASSWORD MODAL -->
<div class="modal" id="forgotModal">
    <div class="modal-content" style="max-width: 480px; padding: 4rem; border-radius: 2.5rem; background: var(--bg-dark); border: 1px solid var(--glass-border); backdrop-filter: blur(40px);">
        <div style="margin-bottom: 3rem; text-align: center;">
            <div style="width: 64px; height: 64px; background: rgba(99, 102, 241, 0.1); border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; color: var(--primary);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h3 style="margin-bottom: 0.75rem; font-size: 1.75rem; font-weight: 800;">Password Recovery</h3>
            <p style="color: var(--text-dim); font-size: 1rem; margin: 0; line-height: 1.6;">Enter your email and we'll send you instructions to reset your password.</p>
        </div>

        <form method="POST" onsubmit="return submitForgot()">
            <input type="hidden" name="action" value="forgot">
            <div class="form-group">
                <label class="field-label">Account Email</label>
                <input type="email" name="forgot_email" id="forgot_email" class="form-control" placeholder="name@example.com">
            </div>
            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1.25rem; margin-top: 3rem;">
                <button type="submit" class="btn btn-primary" style="padding: 1rem; border-radius: 1rem; font-weight: 800;">Recover Access</button>
                <button type="button" onclick="closeForgotModal()" class="btn btn-secondary" style="padding: 1rem; border-radius: 1rem; font-weight: 800;">Go Back</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword(id){
    const field = document.getElementById(id);
    field.type = field.type === "password" ? "text" : "password";
}

function validateLogin() {
    let errors = [];
    const email = document.getElementById('email').value.trim();
    const pass  = document.getElementById('password').value;

    if (!email || !pass) {
        errors.push("Email and password are required.");
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push("Please enter a valid email address.");
    }

    if (errors.length > 0) {
        showErrors(errors);
        return false;
    }
    return true;
}

function showErrors(errors) {
    let box = document.getElementById('serverErrors');
    if (!box) {
        const errorCard = document.createElement('div');
        errorCard.className = 'alert-card';
        errorCard.style.cssText = 'margin-bottom: 2rem; background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: var(--danger); padding: 1.25rem; border-radius: 1.25rem;';
        
        let container = document.querySelector('.auth-form-section form');
        if (container) {
            errorCard.innerHTML = `<div id="serverErrors"></div>`;
            container.before(errorCard);
            box = document.getElementById('serverErrors');
        }
    }
    box.innerHTML = errors.map(e => `
        <div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.5rem;'>
            <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'>
                <circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/>
            </svg> 
            ${e}
        </div>
    `).join('');
}

function openForgotModal(){
    document.getElementById('forgotModal').classList.add('active');
}

function closeForgotModal(){
    document.getElementById('forgotModal').classList.remove('active');
}

function submitForgot(){
    const email = document.getElementById('forgot_email').value.trim();
    let errors = [];
    if(!email){
        errors.push("Email is required.");
    } else if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
        errors.push("Invalid email format.");
    }
    if(errors.length){
        showErrors(errors);
        return false;
    }
    return true;
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeForgotModal();
});
</script>
</body>
</html>