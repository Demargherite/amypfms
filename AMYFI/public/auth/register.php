<?php
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name  = trim($_POST["name"] ?? '');
    $email = trim($_POST["email"] ?? '');
    $password = $_POST["password"] ?? '';
    $confirm_password = $_POST["confirm_password"] ?? '';
    $terms = isset($_POST["terms"]);

    /* =========================
       BACKEND VALIDATION
    ========================= */
    if ($name === '') {
        $errors[] = "Name is required.";
    } elseif (strlen($name) < 3 || strlen($name) > 100) {
        $errors[] = "Name must be between 3 and 100 characters.";
    }

   if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
} elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
    $errors[] = "Only gmail.com email addresses are allowed.";
}

    if (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    ) {
        $errors[] = "Password must include 8 characters,1 character uppercase, lowercase and number.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    if (!$terms) {
        $errors[] = "You must agree to the terms.";
    }

    /* =========================
       EMAIL UNIQUE CHECK
    ========================= */
    if (empty($errors)) {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $errors[] = "Email already registered.";
        }
        $check->close();
    }

    /* =========================
       INSERT USER
    ========================= */
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("sss", $name, $email, $hash);

        if ($stmt->execute()) {
            $_SESSION["user_id"] = $stmt->insert_id;
            $_SESSION["user_name"] = $name;
            header("Location: choose_user_type.php");
exit;
        } else {
            $errors[] = "Something went wrong. Please try again.";
        }
        $stmt->close();
    }
}

$pageTitle = "Register";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - AMYFI</title>
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
            min-height: 640px;
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

        .auth-form-section {
            flex: 1.3;
            padding: 3.5rem 4.5rem;
            background: rgba(15, 23, 42, 0.2);
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow-y: auto;
        }

        .form-header {
            margin-bottom: 2.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .field-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            color: var(--text-dim);
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1.25rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 1rem;
            color: var(--text-main);
            font-size: 0.9375rem;
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

        .terms-label {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            font-size: 0.875rem;
            color: var(--text-dim);
            user-select: none;
            transition: all 0.3s ease;
        }

        .terms-label:hover {
            color: var(--text-main);
        }

        .btn-auth {
            width: 100%;
            padding: 1.125rem;
            font-size: 1.125rem;
            font-weight: 800;
            border-radius: 1rem;
            margin-top: 1rem;
            box-shadow: 0 10px 20px -5px var(--primary-glow);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @media (max-width: 1024px) {
            .auth-container { max-width: 900px; }
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
            <h2 style="color: white; font-size: 2.75rem; font-weight: 800; margin-bottom: 1.5rem; letter-spacing: -0.03em; line-height: 1.1;">Secure Your Family's Legacy.</h2>
            <p style="color: var(--text-dim); font-size: 1.125rem; line-height: 1.7; max-width: 340px;">Begin your journey to smarter money management and lasting financial freedom.</p>
        </div>
    </div>

    <div class="auth-form-section">
        <div class="form-header">
            <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.02em;">Create Account</h1>
            <p style="color: var(--text-dim); font-size: 1rem;">Join AMYFI today and take control of your wealth.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-card" style="margin-bottom: 1.5rem; background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: var(--danger); padding: 1.125rem; border-radius: 1.125rem;">
                <div id="serverErrors">
                    <?php foreach ($errors as $e) echo "<div style='font-size: 0.875rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.4rem;'><svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg> $e</div>"; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return validateRegister()" novalidate>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="field-label">Full Name</label>
                    <input type="text" name="name" id="name" class="form-control" placeholder="John Doe" autofocus>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label class="field-label">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="john@example.com">
                </div>
            </div>

            <div class="form-group">
                <label class="field-label">Password</label>
                <div style="position: relative;">
                    <input type="password" name="password" id="password" class="form-control" placeholder="••••••••">
                    <span style="position: absolute; right: 1.25rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-dim); display: flex;" onclick="togglePassword('password')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                </div>
                <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 0.6rem; font-weight: 500;">8+ characters, including upper, lower & numbers.</div>
            </div>

            <div class="form-group">
                <label class="field-label">Confirm Password</label>
                <div style="position: relative;">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="••••••••">
                    <span style="position: absolute; right: 1.25rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-dim); display: flex;" onclick="togglePassword('confirm_password')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                </div>
            </div>

            <div class="form-group" style="margin: 2rem 0;">
                <label class="terms-label">
                    <input type="checkbox" name="terms" id="terms" style="accent-color: var(--primary); width: 1.25rem; height: 1.25rem; cursor: pointer;">
                    <span>I agree to the <a href="#" style="color: var(--primary); font-weight: 700; text-decoration: none;">Terms of Service</a></span>
                </label>
            </div>

            <button class="btn btn-primary btn-auth" type="submit">Create Free Account →</button>

            <p style="text-align: center; margin-top: 2.5rem; font-size: 0.9375rem; color: var(--text-dim);">
                Already registered? <a href="login.php" style="color: var(--primary); font-weight: 800; text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.3s ease;" onmouseover="this.style.borderBottomColor='var(--primary)'" onmouseout="this.style.borderBottomColor='transparent'">Log in here</a>
            </p>
        </form>
    </div>
</div>

<script>
function togglePassword(id) {
    const f = document.getElementById(id);
    f.type = f.type === "password" ? "text" : "password";
}

function validateRegister() {
    let errors = [];
    const name  = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const pass  = document.getElementById('password').value;
    const conf  = document.getElementById('confirm_password').value;
    const terms = document.getElementById('terms').checked;

    if (name.length < 3 || name.length > 100)
        errors.push("Name must be between 3 and 100 characters.");

    if (!/^[a-zA-Z0-9._%+-]+@gmail\.com$/.test(email))
    errors.push("Only gmail.com email addresses are allowed.");

    if (
        pass.length < 8 ||
        !/[A-Z]/.test(pass) ||
        !/[a-z]/.test(pass) ||
        !/[0-9]/.test(pass)
    )
        errors.push("Password must include uppercase, lowercase and number.");

    if (pass !== conf)
        errors.push("Passwords do not match.");

    if (!terms)
        errors.push("You must agree to the Terms & Conditions.");

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
        errorCard.style.cssText = 'margin-bottom: 1.5rem; color: var(--danger); border-color: rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05);';
        
        let container = document.querySelector('.auth-form-section form');
        if (container) {
            errorCard.innerHTML = `<div id="serverErrors"></div>`;
            container.before(errorCard);
            box = document.getElementById('serverErrors');
        }
    }
    box.innerHTML = errors.map(e => `
        <div style='font-size: 0.875rem; font-weight: 500; display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.25rem;'>
            <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                <circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/>
            </svg> 
            ${e}
        </div>
    `).join('');
}
</script>
</body>
</html>