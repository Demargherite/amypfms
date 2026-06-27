<?php
session_start();
require_once '../../config/db.php';

// Ensure user logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* =========================
   EXTRA VALIDATION
========================= */
$name_raw = trim($_POST['name'] ?? '');
$type_raw = trim($_POST['type'] ?? '');

if ($name_raw === '') {
    $errors[] = "Please enter category name.";
} elseif (strlen($name_raw) < 2) {
    $errors[] = "Category name must be at least 2 characters.";
} elseif (strlen($name_raw) > 50) {
    $errors[] = "Category name cannot exceed 50 characters.";
}

if ($type_raw === '') {
    $errors[] = "Please choose category type.";
}

$name = trim(preg_replace('/\s+/', ' ', $name_raw));
$type = $type_raw;

if (empty($errors)) {
    if (!in_array($type, ['income', 'expense', 'saving'])) {
        $errors[] = "Invalid category type.";
    }
}

    // Prevent duplicate per user + type (case-insensitive)
    if (empty($errors)) {

        $check = $conn->prepare("
            SELECT id FROM categories
            WHERE user_id = ?
              AND type = ?
              AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");
        $check->bind_param("iss", $user_id, $type, $name);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Category already exists.";
        }
    }

    if (empty($errors)) {

        // ==========================
        // IF SAVING CATEGORY
        // ==========================
        if ($type === 'saving') {

            // 1. Create saving goal
            $goalStmt = $conn->prepare("
                INSERT INTO savings_goals 
                (user_id, name, target_amount, current_amount, deadline, status)
                VALUES (?, ?, 0, 0, NULL, 'active')
            ");
            $goalStmt->bind_param("is", $user_id, $name);

            if ($goalStmt->execute()) {

                // 2. Get saving goal ID
                $saving_goal_id = $conn->insert_id;

                // 3. Create category linked to goal
                $stmt = $conn->prepare("
                    INSERT INTO categories 
                    (user_id, name, type, is_active, saving_goal_id)
                    VALUES (?, ?, ?, 1, ?)
                ");
                $stmt->bind_param("issi", $user_id, $name, $type, $saving_goal_id);

                if ($stmt->execute()) {
                    $success = "Saving category + goal created!";
                    header("refresh:1.3; url=categories.php");
                } else {
                    $errors[] = "Error saving category.";
                }

            } else {
                $errors[] = "Error creating saving goal.";
            }

        } else {

            // ==========================
            // NORMAL CATEGORY
            // ==========================
            $stmt = $conn->prepare("
                INSERT INTO categories (user_id, name, type, is_active)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->bind_param("iss", $user_id, $name, $type);

            if ($stmt->execute()) {
                $success = "Category added successfully!";
                header("refresh:1.3; url=categories.php");
            } else {
                $errors[] = "Error saving category.";
            }
        }
    }
}

$pageTitle = "Add Category Income and Expense";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="max-width: 500px; margin: 2rem auto;">
        <div class="card" style="padding: 3rem;">
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">Add Category</h1>
                <p class="text-muted">Organize your finances by creating custom categories for income and expenses.</p>
            </div>

            <?php if ($success): ?>
                <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); margin-bottom: 1.5rem; color: var(--success);">
                    <div style="font-size: 0.875rem; font-weight: 500;">✅ <?= $success ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 1.5rem; color: var(--danger);">
                    <?php foreach ($errors as $e) echo "<div style='font-size: 0.875rem; font-weight: 500;'>• $e</div>"; ?>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="form-group">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="name" class="form-control" 
                           placeholder="e.g., Groceries, Salary, Freelance">
                </div>

                <div class="form-group">
                    <label class="form-label">Category Type</label>
                    <select name="type" class="form-control">
                        <option value="expense">Expense (Money Out)</option>
                        <option value="income">Income (Money In)</option>
                        <option value="saving">Saving (Goal Accumulation)</option>
                    </select>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2.5rem;">
                    <a href="categories.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="flex: 2;">Save Category</button>
                </div>
            </form>
        </div>
    </div>
    </div>

