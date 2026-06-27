<?php
session_start();
require_once '../../config/db.php';

// Must login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* =========================
   CLEAN INPUT VALIDATION
========================= */
$name_raw   = trim($_POST["name"] ?? '');
$target_raw = trim($_POST["target_amount"] ?? '');
$deadline   = trim($_POST["deadline"] ?? '');

/* Goal Name */
if ($name_raw === '') {
    $errors[] = "Please enter your savings goal name.";
} elseif (strlen($name_raw) < 2) {
    $errors[] = "Goal name must be at least 2 characters.";
} elseif (strlen($name_raw) > 100) {
    $errors[] = "Goal name cannot exceed 100 characters.";
}

/* Target */
if ($target_raw === '') {
    $errors[] = "Please enter your target amount.";
} elseif (!is_numeric($target_raw)) {
    $errors[] = "Target amount must be a valid number.";
} elseif (floatval($target_raw) <= 0) {
    $errors[] = "Target amount must be more than RM 0.";
}

/* Deadline */
if ($deadline === '') {
    $errors[] = "Please choose a deadline date.";
}

// Map variables for insertion
$name = $name_raw;
$target = floatval($target_raw);

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO savings_goals 
            (user_id, name, target_amount, current_amount, deadline)
            VALUES (?, ?, ?, 0, ?)
        ");
        
        $stmt->bind_param("isds", $user_id, $name, $target, $deadline);

        if ($stmt->execute()) {
            $goal_id = $stmt->insert_id;

            // AUTO CREATE CATEGORY (TYPE = SAVING)
            $cat = $conn->prepare("
                INSERT INTO categories (user_id, name, type, saving_goal_id)
                VALUES (?, ?, 'saving', ?)
            ");
            $cat->bind_param("isi", $user_id, $name, $goal_id);
            $cat->execute();

            $success = "🎉 Goal created successfully!";
            header("refresh:1.5; url=savings.php");
        } else {
            $errors[] = "Failed to save. Try again.";
        }
    }
}

$pageTitle = "Add Savings Goal";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="max-width: 500px; margin: 2rem auto;">
        <div class="card" style="padding: 3rem;">
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">New Savings Goal</h1>
                <p class="text-muted">Dream big, save smart. Set a target and track your progress to financial freedom.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 1.5rem; color: var(--danger);">
                    <?php foreach ($errors as $e) echo "<div style='font-size: 0.875rem; font-weight: 500;'>• $e</div>"; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); margin-bottom: 1.5rem; color: var(--success);">
                    <div style="font-size: 0.875rem; font-weight: 500;">✅ <?= $success ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="form-group">
                    <label class="form-label">Goal Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., New Laptop, Vacation, Emergency Fund">
                </div>

                <div class="form-group">
                    <label class="form-label">Target Amount (RM)</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-dim); font-weight: 600;">RM</span>
                        <input type="number" step="0.01" name="target_amount" class="form-control" style="padding-left: 3.5rem;" placeholder="0.00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Deadline Date</label>
                    <input type="date" name="deadline" class="form-control">
                    <p class="text-muted" style="font-size: 0.75rem; margin-top: 0.50rem;">When do you want to achieve this goal?</p>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2.5rem;">
                    <a href="savings.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="flex: 2;">Save Goal</button>
                </div>
            </form>
        </div>
    </div>
    </div>
<?php require_once '../../includes/footer.php'; ?>
