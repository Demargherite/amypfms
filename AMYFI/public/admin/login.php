<?php
/* =====================================================
   admin/login.php
   FIXED VERSION
   Only redirect after real login session
===================================================== */
session_start();
require_once '../../config/db.php';

/* =====================================================
   FORCE CLEAR IF OPEN LOGIN WITH ?logout=1
===================================================== */
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    session_start();
}

/* =====================================================
   CHECK EXISTING SESSION
===================================================== */
if (
    isset($_SESSION['admin_logged_in']) &&
    $_SESSION['admin_logged_in'] === true &&
    isset($_SESSION['admin_id']) &&
    is_numeric($_SESSION['admin_id'])
) {

    $check = $conn->prepare("
        SELECT id
        FROM admins
        WHERE id = ?
        AND is_active = 1
        LIMIT 1
    ");

    $check->bind_param("i", $_SESSION['admin_id']);
    $check->execute();

    $validAdmin = $check->get_result()->fetch_assoc();

    if ($validAdmin) {
        header("Location: dashboard.php");
        exit;
    } else {
        session_unset();
        session_destroy();
        session_start();
    }
}

$error = '';

/* =====================================================
   LOGIN PROCESS
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '') {
        $error = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password === '') {
        $error = "Password is required.";
    } else {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, password, role, is_active
            FROM admins
            WHERE email = ?
            LIMIT 1
        ");


        $stmt->bind_param("s", $email);
        $stmt->execute();

        $admin = $stmt->get_result()->fetch_assoc();

        if (!$admin) {

            $error = "Invalid login credentials.";

        } elseif ((int)$admin['is_active'] !== 1) {

            $error = "Your admin account is disabled.";

        } else {

            $loginSuccess = false;

            /* hashed password */
            if (password_verify($password, $admin['password'])) {
                $loginSuccess = true;
            }

            /* plain text old password */
            elseif ($password === $admin['password']) {
                $loginSuccess = true;

                $newHash = password_hash($password, PASSWORD_DEFAULT);

                $up = $conn->prepare("
                    UPDATE admins
                    SET password = ?
                    WHERE id = ?
                ");
                $up->bind_param("si", $newHash, $admin['id']);
                $up->execute();
            }

            if ($loginSuccess) {

                session_regenerate_id(true);

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id']        = $admin['id'];
                $_SESSION['admin_name']      = $admin['full_name'];
                $_SESSION['admin_role']      = $admin['role'];

                $update = $conn->prepare("
                    UPDATE admins
                    SET last_login = NOW()
                    WHERE id = ?
                ");
                $update->bind_param("i", $admin['id']);
                $update->execute();

                header("Location: dashboard.php");
                exit;

            } else {
                $error = "Invalid login credentials.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Portal - AMYFI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --bg-card-alt: #111827;
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --accent: #0ea5e9;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #334155;
            --danger: #ef4444;
            --radius-lg: 20px;
            --radius-md: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            margin: 0;
            font-family: 'Outfit', sans-serif;
            background: radial-gradient(circle at top right, #1e1b4b, #0f172a);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            padding: 20px;
        }

        .box {
            width: 100%;
            max-width: 440px;
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 3rem;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
            position: relative;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .box::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.3), rgba(14, 165, 233, 0.1), transparent);
            border-radius: 24px;
            z-index: -1;
        }

        .logo-wrap {
            margin-bottom: 2.5rem;
            text-align: center;
        }

        .logo-wrap h1 {
            margin: 0;
            font-size: 2.25rem;
            font-weight: 800;
            letter-spacing: -1px;
            background: linear-gradient(135deg, #fff 0%, #a5b4fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-wrap p {
            margin: 0.5rem 0 0;
            color: var(--text-muted);
            font-size: 1rem;
            font-weight: 500;
        }

        .error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger);
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 2rem;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: shake 0.4s cubic-bezier(.36,.07,.19,.97) both;
        }

        @keyframes shake {
            10%, 90% { transform: translate3d(-1px, 0, 0); }
            20%, 80% { transform: translate3d(2px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-4px, 0, 0); }
            40%, 60% { transform: translate3d(4px, 0, 0); }
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            transition: var(--transition);
        }

        input {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            font-family: inherit;
            font-size: 1rem;
            box-sizing: border-box;
            transition: var(--transition);
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(15, 23, 42, 0.8);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        input:focus + label {
            color: var(--primary);
        }

        button {
            width: 100%;
            padding: 1.15rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: none;
            border-radius: var(--radius-md);
            color: white;
            font-size: 1rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        .footer-note {
            margin-top: 2.5rem;
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .footer-note a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .footer-note a:hover {
            text-decoration: underline;
        }

    </style>
</head>
<body>

    <div class="box">
        <div class="logo-wrap">
            <h1>AMYFI</h1>
            <p>Admin Control Center</p>
        </div>

        <?php if($error): ?>
            <div class="error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" novalidate>
            <div class="form-group">
                <input type="email" id="email" name="email" placeholder="Email Address" autofocus>
            </div>
            <div class="form-group">
                <input type="password" id="password" name="password" placeholder="Access Password">
            </div>

            <button type="submit">Unlock Portal</button>
        </form>

        <div class="footer-note">
            Unauthorized access is strictly monitored.
        </div>
    </div>

</body>
</html>