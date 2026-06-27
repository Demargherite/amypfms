<?php
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

// =========================
// AUTH CHECK
// =========================
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$errors = [];
$warnings = [];

// =========================
// GET USER TYPE
// =========================
$stmt = $conn->prepare("SELECT user_type FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_type = $stmt->get_result()->fetch_assoc()['user_type'] ?? '';
$stmt->close();

// =========================
// FORM SUBMIT
// =========================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $income      = trim($_POST["income"] ?? '');
    $saving_goal = trim($_POST["savings_goal"] ?? '');

    // -------------------------
    // BASIC VALIDATION
    // -------------------------
    if ($income === '' || !is_numeric($income) || $income <= 0) {
        $errors[] = "Net salary must be a valid number.";
    }

    if ($saving_goal === '' || !is_numeric($saving_goal) || $saving_goal < 0) {
        $errors[] = "Saving goal must be a valid number.";
    }

    $income = (float)$income;
    $saving_goal = (float)$saving_goal;

    // =========================
    // INCOME-BASED SAVING LIMIT
    // =========================
    $max_saving_percentage = 0;

    if ($income >= 500 && $income <= 1000) {
        $max_saving_percentage = 0.15;
    } elseif ($income >= 2000 && $income <= 2500) {
        $max_saving_percentage = 0.10;
    } elseif ($income >= 3000 && $income <= 6000) {
        $max_saving_percentage = 0.20;
    } elseif ($income >= 7000 && $income <= 9000) {
        $max_saving_percentage = 0.25;
    } elseif ($income >= 10000) {
        $max_saving_percentage = 0.40;
    }

    $max_allowed_saving = $income * $max_saving_percentage;

    // BLOCK if exceed limit
    if ($max_saving_percentage > 0 && $saving_goal > $max_allowed_saving) {
        $errors[] = "Based on your income, the maximum recommended saving is "
                  . ($max_saving_percentage * 100) . "% (RM "
                  . number_format($max_allowed_saving, 2) . ").";
    }

    // SOFT WARNING if close to max
    if (
        $max_saving_percentage > 0 &&
        $saving_goal >= ($max_allowed_saving * 0.9) &&
        $saving_goal <= $max_allowed_saving
    ) {
        $warnings[] = "You are saving close to the maximum recommended limit. Ensure daily expenses are manageable.";
    }

    // -------------------------
    // SMART RULE 1
    // -------------------------
    if ($saving_goal > $income) {
        $errors[] = "Monthly saving goal cannot exceed net salary.";
    }

    // -------------------------
    // SMART RULE 2 (legacy soft warning)
    // -------------------------
    if (
    $max_saving_percentage > 0 &&
    $saving_goal >= ($max_allowed_saving * 0.8) &&
    $saving_goal < $max_allowed_saving
) {
    $warnings[] =
        "You are saving close to the recommended limit (" .
        ($max_saving_percentage * 100) . "%). Please ensure your daily expenses remain manageable.";
}
    // -------------------------
    // FIXED EXPENSE ESTIMATION
    // -------------------------
    $estimated_fixed_expenses = $income * 0.4;

    // -------------------------
    // SMART RULE 3
    // -------------------------
    if (($income - $saving_goal - $estimated_fixed_expenses) < 0) {
        $errors[] = "Your income is insufficient after savings and fixed expenses.";
    }

    // =========================
    // IF VALID → SAVE DATA
    // =========================
    if (empty($errors)) {

        // SAVE USER SETTINGS
        $stmt = $conn->prepare("
            INSERT INTO user_settings (user_id, monthly_income, savings_goal)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                monthly_income = VALUES(monthly_income),
                savings_goal = VALUES(savings_goal)
        ");
        $stmt->bind_param("idd", $user_id, $income, $saving_goal);
        $stmt->execute();
        $stmt->close();

        // AUTO BUDGET RULES
        $budget_rules = [
            'Food'          => 0.30,
            'Transport'     => 0.15,
            'Utilities'     => 0.10,
            'Entertainment' => 0.10
        ];

        $budget_month = date('Y-m-01');

        foreach ($budget_rules as $category_name => $percentage) {

            $stmt = $conn->prepare("
                SELECT id FROM categories
                WHERE name = ? AND user_id = ? AND type = 'expense'
            ");
            $stmt->bind_param("si", $category_name, $user_id);
            $stmt->execute();
            $category = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($category) {
                $limit = $income * $percentage;

                $stmt = $conn->prepare("
                    INSERT INTO budgets (user_id, category_id, month, limit_amount)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)
                ");
                $stmt->bind_param("iisd", $user_id, $category['id'], $budget_month, $limit);
                $stmt->execute();
                $stmt->close();
            }
        }

        header("Location: ../dashboard/dashboard.php");
        exit;
    }
}

$pageTitle = "Onboarding";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Profile - AMYFI</title>
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
            max-width: 600px;
            margin: auto;
            position: relative;
        }

        /* Branded Header */
        .brand-section {
            text-align: center;
            margin-bottom: 3rem;
            animation: fadeInDown 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-logo {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-weight: 800;
            font-size: 1.25rem;
            box-shadow: 0 0 30px var(--glass-glow);
            transform: rotate(-5deg);
        }

        /* Glass Card */
        .auth-container {
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            border-radius: 2.5rem;
            padding: 3.5rem;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.5),
                var(--shadow-glow);
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Progress Bar */
        .progress-bar {
            height: 6px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
        }

        .progress-fill {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 100%; /* Final step */
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 10px;
            box-shadow: 0 0 10px var(--primary-glow);
        }

        .step-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-dim);
        }

        .step-label span.active {
            color: var(--primary);
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 2rem;
        }

        .field-label {
            display: block;
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.75rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1.25rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            pointer-events: none;
            transition: var(--transition);
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.25rem 1rem 3.5rem;
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

        .form-control:focus + .input-icon {
            color: var(--primary);
        }

        .input-suffix {
            position: absolute;
            right: 1.25rem;
            color: var(--text-dim);
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Suggestion Box */
        .suggestion-box {
            background: rgba(99, 102, 241, 0.05);
            border: 1px solid rgba(99, 102, 241, 0.1);
            border-left: 4px solid var(--primary);
            padding: 1.50rem;
            border-radius: 1rem;
            margin: 2.5rem 0;
            display: flex;
            gap: 1rem;
        }

        .suggestion-box svg {
            color: var(--primary);
            flex-shrink: 0;
            margin-top: 0.2rem;
        }

        .btn-finish {
            width: 100%;
            padding: 1.25rem;
            font-size: 1.125rem;
            font-weight: 800;
            border-radius: 1.25rem;
            transform: translateZ(0);
            box-shadow: 0 10px 20px -5px var(--primary-glow);
        }

        @media (max-width: 640px) {
            .auth-container { padding: 2.5rem 1.5rem; }
            body { padding: 2rem 1rem; }
        }
    </style>
</head>
<body>

<div class="wizard-container">
    <div class="brand-section">
        <div class="brand-logo">AF</div>
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.01em;">Setup Your Goals</h1>
        <p style="color: var(--text-dim); font-size: 1rem;">Let's finalize your profile to unlock custom insights.</p>
    </div>

    <div class="auth-container">
        <div class="step-label">
            <span>Setup Journey</span>
            <span class="active">Step 3 of 3</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-card" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 2rem; color: var(--danger); padding: 1rem 1.5rem; border-radius: 1rem;">
                <?php foreach ($errors as $e) echo "<div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center;'><svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg> $e</div>"; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
            <div class="alert-card" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); margin-bottom: 2rem; color: var(--warning); padding: 1rem 1.5rem; border-radius: 1rem;">
                <?php foreach ($warnings as $w) echo "<div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center;'><svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/><line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/></svg> $w</div>"; ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="form-group">
                <label class="field-label">
                    <?= ($user_type === 'student') ? 'Monthly Allowance' : 'Net Monthly Salary' ?>
                </label>
                <div class="input-wrapper">
                    <div class="input-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><path d="M7 15h.01M11 15h2"/></svg>
                    </div>
                    <input type="number" step="0.01" name="income" class="form-control" placeholder="0.00" autofocus>
                    <span class="input-suffix">RM</span>
                </div>
            </div>

            <div class="form-group">
                <label class="field-label">Monthly Savings Goal</label>
                <div class="input-wrapper">
                    <div class="input-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M16 8l-8 8M12 7v10"/></svg>
                    </div>
                    <input type="number" step="0.01" name="savings_goal" class="form-control" placeholder="0.00">
                    <span class="input-suffix">RM</span>
                </div>
            </div>

            <div class="suggestion-box">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <p style="margin: 0; font-size: 0.9375rem; color: var(--text-main); line-height: 1.6;">
                    <strong>Smart Suggestion:</strong> We'll automatically build balanced budgets for your categories based on these targets.
                </p>
            </div>

            <button type="submit" class="btn btn-primary btn-finish">Complete Setup & Dashboard →</button>
        </form>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>