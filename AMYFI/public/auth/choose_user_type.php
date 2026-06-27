<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$errors = [];

/* =========================
   DEFAULT CATEGORIES
========================= */
function create_default_categories($conn, $user_id, $user_type) {
    $default_categories = [
        "student" => ["Food", "Transport", "Books", "Topup"],
        "worker_single" => ["Rent", "Utilities", "Groceries", "Transport",],
        "worker_married" => ["Groceries", "Utilities", "Housing", "Family","Rent"],
        "worker_married_children" => ["Groceries", "School Fees", "Daycare", "Kids Medical","Rent"],
        "freelancer" => ["Software", "Tools", "Business Fund", "Savings","Rent"],
        "other" => ["Food", "Transport", "Topup","Groceries", "Utilities", "Housing", "Family","School Fees", "Daycare", "Kids Medical","Software", "Tools", "Business Fund","Rent"]
    ];

    if (isset($default_categories[$user_type])) {
        foreach ($default_categories[$user_type] as $cat) {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO categories (user_id, name, type)
                VALUES (?, ?, 'expense')
            ");
            $stmt->bind_param("is", $user_id, $cat);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* =========================
   HANDLE SUBMIT
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $user_type = $_POST['user_type'] ?? '';

    if ($user_type === '') {
        $errors[] = "You need to choose your user type";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE users SET user_type = ? WHERE id = ?");
        $stmt->bind_param("si", $user_type, $user_id);
        $stmt->execute();
        $stmt->close();

        create_default_categories($conn, $user_id, $user_type);

        if ($user_type === "worker_married_children") {
            header("Location: add_children.php");
        } else {
            header("Location: ../onboarding/onboarding.php");
        }
        exit;
    }
}

$pageTitle = "Choose Your Profile Type";
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Profile Type - AMYFI</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Google Font: Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(30, 41, 59, 0.4);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-glow: rgba(99, 102, 241, 0.2);
        }

        body {
            margin: 0;
            padding: 4rem 1rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(34, 211, 238, 0.08) 0px, transparent 50%),
                url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.03'/%3E%3C/svg%3E");
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            overflow-y: auto;
        }

        .wizard-container {
            width: 100%;
            max-width: 900px;
            margin: auto;
            position: relative;
        }

        /* Branded Header */
        .brand-section {
            text-align: center;
            margin-bottom: 3.5rem;
            animation: fadeInDown 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-weight: 800;
            font-size: 1.5rem;
            box-shadow: 0 0 30px var(--glass-glow);
            transform: rotate(-5deg);
        }

        /* Glass Card */
        .auth-container {
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            border-radius: 3rem;
            padding: 4rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: center;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Progress Bar */
        .progress-bar-wrapper {
            max-width: 400px;
            margin: 0 auto 3rem;
        }

        .step-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-dim);
        }

        .step-info .active {
            color: var(--primary);
        }

        .progress-bar {
            height: 6px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            position: relative;
            overflow: hidden;
        }

        .progress-fill {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 33.33%; /* Step 1 of 3 */
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 10px;
            box-shadow: 0 0 15px var(--glass-glow);
        }

        /* Grid & Cards */
        .user-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3.5rem;
        }

        .user-type-card {
            cursor: pointer;
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid var(--glass-border);
            border-radius: 2rem;
            padding: 2.5rem 1.5rem;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .user-type-card:hover {
            transform: translateY(-10px);
            background: rgba(15, 23, 42, 0.5);
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6);
        }

        .user-type-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .icon-box {
            width: 64px;
            height: 64px;
            background: rgba(99, 102, 241, 0.08);
            border-radius: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            transition: all 0.4s ease;
            border: 1px solid rgba(99, 102, 241, 0.1);
        }

        .user-type-card svg {
            width: 28px;
            height: 28px;
            stroke-width: 2;
        }

        .user-type-card span {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-dim);
            transition: var(--transition);
        }

        .user-type-card p {
            font-size: 0.8125rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin: 0;
        }

        /* Selected State */
        .user-type-card:has(input:checked) {
            background: rgba(99, 102, 241, 0.08);
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary), 0 15px 30px -10px rgba(99, 102, 241, 0.2);
        }

        .user-type-card:has(input:checked) .icon-box {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-glow);
            transform: scale(1.1) rotate(5deg);
        }

        .user-type-card:has(input:checked) span {
            color: var(--text-main);
        }

        .btn-continue {
            min-width: 300px;
            padding: 1.25rem 3rem;
            font-size: 1.125rem;
            font-weight: 800;
            border-radius: 1.25rem;
            box-shadow: 0 10px 25px -5px var(--primary-glow);
        }

        @media (max-width: 640px) {
            .auth-container { padding: 3rem 1.5rem; border-radius: 2rem; }
            .user-type-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="wizard-container">
    <div class="brand-section">
        <div class="brand-logo">AF</div>
        <h1 style="font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.02em;">Choose Your Path</h1>
        <p style="color: var(--text-dim); font-size: 1.125rem;">Personalize your AMYFI experience in just 3 steps.</p>
    </div>

    <div class="auth-container">
        <div class="progress-bar-wrapper">
            <div class="step-info">
                <span>Account Setup</span>
                <span class="active">Step 1 of 3</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-card" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 2.5rem; color: var(--danger); padding: 1rem 1.5rem; border-radius: 1.25rem;">
                <div id="serverErrors">
                    <?php foreach ($errors as $e) echo "<div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center; justify-content: center;'><svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg> $e</div>"; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return validateUserType()" novalidate>
            <div class="user-type-grid">
                <label class="user-type-card">
                    <input type="radio" name="user_type" value="student">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                    </div>
                    <div>
                        <span>Student</span>
                        <p>Track allowances, books, and daily university expenses.</p>
                    </div>
                </label>

                <label class="user-type-card">
                    <input type="radio" name="user_type" value="worker_single">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <span>Worker (Single)</span>
                        <p>Optimize your salary for personal growth and savings.</p>
                    </div>
                </label>

                <label class="user-type-card">
                    <input type="radio" name="user_type" value="worker_married">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <span>Worker (Married)</span>
                        <p>Coordinate finances for double-income households.</p>
                    </div>
                </label>

                <label class="user-type-card">
                    <input type="radio" name="user_type" value="worker_married_children">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div>
                        <span>Married + Kids</span>
                        <p>Complete family management including education costs.</p>
                    </div>
                </label>

                <label class="user-type-card">
                    <input type="radio" name="user_type" value="freelancer">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    </div>
                    <div>
                        <span>Freelancer</span>
                        <p>Manage variable income and business-related costs.</p>
                    </div>
                </label>

                <label class="user-type-card">
                    <input type="radio" name="user_type" value="other">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                    </div>
                    <div>
                        <span>Other</span>
                        <p>Dynamic setup for any other unique situation.</p>
                    </div>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-continue">Continue Journey →</button>
        </form>
    </div>
</div>

<script>
function validateUserType() {
    let selected = document.querySelector('input[name="user_type"]:checked');
    let errors = [];
    if (!selected) {
        errors.push("Please select your profile type to proceed.");
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
        errorCard.style.cssText = 'background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 2.5rem; color: var(--danger); padding: 1.5rem; border-radius: 1.25rem;';
        
        errorCard.innerHTML = `<div id="serverErrors"></div>`;
        document.querySelector('form').before(errorCard);
        box = document.getElementById('serverErrors');
    }
    box.innerHTML = errors.map(e => `
        <div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center; justify-content: center;'>
            <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'>
                <circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/>
            </svg> 
            ${e}
        </div>
    `).join('');
}
</script>
</body>
</html>
<?php $conn->close(); ?>