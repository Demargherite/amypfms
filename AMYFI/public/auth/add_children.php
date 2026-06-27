<?php
session_start();
require_once '../../config/db.php';

/* =========================
   AUTH CHECK
========================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$errors = [];

/* =========================
   ACCESS CONTROL
   Only worker_married_children allowed
========================= */
$checkType = $conn->prepare("SELECT user_type FROM users WHERE id = ?");
$checkType->bind_param("i", $user_id);
$checkType->execute();
$userType = $checkType->get_result()->fetch_assoc()['user_type'] ?? null;
$checkType->close();

if ($userType !== 'worker_married_children') {
    header("Location: ../onboarding/onboarding.php");
    exit;
}

/* =========================
   HANDLE SUBMIT
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $hasValidChild = false;

    if (!empty($_POST['child_name']) && !empty($_POST['child_age'])) {

        foreach ($_POST['child_name'] as $i => $child) {

            $name = trim($child);
            $ageRaw = $_POST['child_age'][$i] ?? '';
            $age = filter_var($ageRaw, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 25]
            ]);

            /* Skip empty row */
            if ($name === "" && ($ageRaw === "" || $age === false)) {
                continue;
            }

            /* Validation */
            if ($name === '' || strlen($name) < 2) {
                $errors[] = "Child name must be at least 2 characters.";
                break;
            }

            if ($age === false) {
                $errors[] = "Child age must be a number between 0 and 25.";
                break;
            }

            $hasValidChild = true;

            $stmt = $conn->prepare(
                "INSERT INTO children (user_id, child_name, age)
                 VALUES (?, ?, ?)"
            );
            $stmt->bind_param("isi", $user_id, $name, $age);
            $stmt->execute();
            $stmt->close();
        }

        if (!$hasValidChild) {
            $errors[] = "Please add at least one child.";
        }

        if (empty($errors)) {
            header("Location: ../onboarding/onboarding.php");
            exit;
        }

    } else {
        $errors[] = "Please add at least one child.";
    }
}

$pageTitle = "Add Children";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Children - AMYFI</title>
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
            max-width: 650px;
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
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
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
            width: 66.66%; /* Step 2 of 3 */
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

        .child-row {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 1.25rem;
            align-items: center;
            margin-bottom: 1.25rem;
            animation: rowIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            background: rgba(15, 23, 42, 0.3);
            padding: 1.25rem;
            border-radius: 1.5rem;
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }

        .child-row:hover {
            border-color: rgba(99, 102, 241, 0.3);
            background: rgba(15, 23, 42, 0.4);
            transform: scale(1.02);
        }

        @keyframes rowIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .remove-btn {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.1);
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .remove-btn:hover {
            background: var(--danger);
            color: white;
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .add-row-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            color: var(--primary);
            font-weight: 700;
            padding: 1.125rem;
            background: rgba(99, 102, 241, 0.05);
            border: 2px dashed rgba(99, 102, 241, 0.15);
            border-radius: 1.5rem;
            cursor: pointer;
            margin: 1.5rem 0 3rem;
            transition: all 0.3s ease;
        }

        .add-row-btn:hover {
            background: rgba(99, 102, 241, 0.1);
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-3px);
        }

        .btn-confirm {
            width: 100%;
            padding: 1.25rem;
            font-size: 1.125rem;
            font-weight: 800;
            border-radius: 1.25rem;
            box-shadow: 0 10px 20px -5px var(--primary-glow);
        }

        @media (max-width: 640px) {
            .auth-container { padding: 2.5rem 1.5rem; }
            .child-row { grid-template-columns: 1fr; }
            .remove-btn { width: 100%; }
        }
    </style>
</head>
<body>

<div class="wizard-container">
    <div class="brand-section">
        <div class="brand-logo">AF</div>
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.01em;">Your Family</h1>
        <p style="color: var(--text-dim); font-size: 1rem;">Let's add your children to personalize your budget.</p>
    </div>

    <div class="auth-container">
        <div class="step-label">
            <span>Profile Evolution</span>
            <span class="active">Step 2 of 3</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-card" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 2rem; color: var(--danger); padding: 1rem 1.5rem; border-radius: 1.25rem;">
                <div id="serverErrors">
                    <?php foreach ($errors as $e) echo "<div style='font-size: 0.9375rem; font-weight: 600; display: flex; gap: 0.75rem; align-items: center; justify-content: center;'><svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg> $e</div>"; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" id="childrenForm" novalidate>
            <div id="childFields">
                <div class="child-row">
                    <div class="form-group" style="margin: 0;">
                        <input type="text" name="child_name[]" class="form-control" placeholder="Child Name" autofocus>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <input type="number" name="child_age[]" class="form-control" placeholder="Age">
                    </div>
                    <div style="width: 44px;"></div>
                </div>
            </div>

            <div class="add-row-btn" onclick="addRow()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add another child
            </div>

            <button type="submit" class="btn btn-primary btn-confirm">Save & Next Step →</button>
        </form>
    </div>
</div>

<script>
function addRow() {
    const container = document.getElementById("childFields");
    const div = document.createElement("div");
    div.classList.add("child-row");
    div.innerHTML = `
        <div class="form-group" style="margin: 0;">
            <input type="text" name="child_name[]" class="form-control" placeholder="Child Name">
        </div>
        <div class="form-group" style="margin: 0;">
            <input type="number" name="child_age[]" class="form-control" placeholder="Age">
        </div>
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    `;
    container.appendChild(div);
}

document.getElementById("childrenForm").addEventListener("submit", function (e) {
    let names = document.querySelectorAll('input[name="child_name[]"]');
    let ages  = document.querySelectorAll('input[name="child_age[]"]');
    let errors = [];
    let hasValid = false;

    for (let i = 0; i < names.length; i++) {
        let name = names[i].value.trim();
        let age  = ages[i].value.trim();

        if (name === "" && age === "") continue;

        if (name.length < 2) {
            errors.push("Child name must be at least 2 characters.");
            break;
        }

        let ageNum = Number(age);
        if (age === "" || isNaN(ageNum) || ageNum < 0 || ageNum > 25) {
            errors.push("Child age must be between 0 and 25.");
            break;
        }
        hasValid = true;
    }

    if (!hasValid) errors.push("Please add at least one child.");

    if (errors.length > 0) {
        showErrors(errors);
        e.preventDefault();
    }
});

function showErrors(errors) {
    let box = document.getElementById('serverErrors');
    if (!box) {
        const errorCard = document.createElement('div');
        errorCard.className = 'alert-card';
        errorCard.style.cssText = 'background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 2rem; color: var(--danger); padding: 1.5rem; border-radius: 1.25rem;';
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