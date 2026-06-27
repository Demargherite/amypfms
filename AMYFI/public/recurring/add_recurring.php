<?php
session_start();
require_once '../../config/db.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = "";

// Fetch categories for dropdown
$sql_cat = "SELECT id, name, type FROM categories WHERE user_id = ? AND is_active = 1 ORDER BY name ASC";
$stmt = $conn->prepare($sql_cat);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res_cat = $stmt->get_result();

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    /* =========================
   EXTRA VALIDATION
========================= */
if (empty($_POST['type'])) {
    $errors[] = "Please select transaction type.";
}

if (empty($_POST['category_id'])) {
    $errors[] = "Please choose a category.";
}

if (empty($_POST['amount'])) {
    $errors[] = "Please enter recurring amount.";
} elseif (!is_numeric($_POST['amount'])) {
    $errors[] = "Amount must be a valid number.";
}

if (empty($_POST['frequency'])) {
    $errors[] = "Please choose repeat frequency.";
}

if (empty($_POST['first_run_date'])) {
    $errors[] = "Please choose first due date.";
}

$type           = $_POST['type'] ?? '';
$amount         = floatval($_POST['amount'] ?? 0);
$category_id    = intval($_POST['category_id'] ?? 0);
$frequency      = $_POST['frequency'] ?? '';
$first_run_date = $_POST['first_run_date'] ?? '';
$end_date       = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

if (empty($errors)) {
    if (!in_array($type, ['income','expense','saving'])) {
        $errors[] = "Invalid type.";
    }
    if ($amount <= 0) $errors[] = "Amount must be > 0.";
    if ($category_id <= 0) $errors[] = "Select a valid category.";
    if (!in_array($frequency, ['weekly','monthly','yearly'])) {
        $errors[] = "Invalid repeat frequency.";
    }
    if ($end_date && $end_date < $first_run_date) {
        $errors[] = "End date cannot be earlier than first due date.";
    }
}
// CHECK DUPLICATE RECURRING CATEGORY
$checkDup = $conn->prepare("
    SELECT id FROM recurring_transactions
    WHERE user_id = ?
      AND category_id = ?
      AND type = ?
      AND is_active = 1
");
$checkDup->bind_param("iis", $user_id, $category_id, $type);
$checkDup->execute();

if ($checkDup->get_result()->num_rows > 0) {
    $errors[] = "Recurring payment for this category already exists.";
}

    if (empty($errors)) {
          $today = new DateTime(date('Y-m-d'));
    $first = new DateTime($first_run_date);

    // AUTO SKIP PAST DATES
    while ($first < $today) {
        if ($frequency === 'monthly') {
            $first->modify('+1 month');
        } elseif ($frequency === 'weekly') {
            $first->modify('+1 week');
        } elseif ($frequency === 'yearly') {
            $first->modify('+1 year');
        }
    }

    $next_date = $first->format('Y-m-d');

    $stmt = $conn->prepare("
        INSERT INTO recurring_transactions
        (user_id, category_id, type, amount, frequency, next_run_date, end_date, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
        $stmt->bind_param(
            "iisdsss",
            $user_id,
            $category_id,
            $type,
            $amount,
            $frequency,
            $next_date,
            $end_date
        );

        if ($stmt->execute()) {
            $success = "Recurring transaction added successfully!";
            header("refresh:1.2; url=recurring.php");
        } else {
            $errors[] = "Something went wrong. Try again.";
        }
    }
}

$pageTitle = "Add Recurring";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="max-width: 600px; margin: 2rem auto;">
        <div class="card" style="padding: 3rem;">
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">Add Recurring</h1>
                <p class="text-muted">Automate your finances. Set up recurring income, expenses, or savings goals.</p>
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
                    <label class="form-label">Type</label>
                    <select name="type" class="form-control">
                        <option value="expense">Expense (Payment Out)</option>
                        <option value="income">Income (Money In)</option>
                        <option value="saving">Saving (Goal In)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control">
                        <option value="">-- Select Category --</option>

                        <?php
                        $income_cats  = [];
                        $expense_cats = [];
                        $saving_cats  = [];

                        while ($cat = $res_cat->fetch_assoc()) {
                            if ($cat['type'] === 'income') {
                                $income_cats[] = $cat;
                            } elseif ($cat['type'] === 'saving') {
                                $saving_cats[] = $cat;
                            } else {
                                $expense_cats[] = $cat;
                            }
                        }
                        ?>

                        <?php if (!empty($income_cats)): ?>
                            <optgroup label="── Income ──">
                                <?php foreach ($income_cats as $cat): ?>
                                    <option value="<?= $cat['id'] ?>">
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>

                        <?php if (!empty($expense_cats)): ?>
                            <optgroup label="── Expenses ──">
                                <?php foreach ($expense_cats as $cat): ?>
                                    <option value="<?= $cat['id'] ?>">
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>

                        <?php if (!empty($saving_cats)): ?>
                            <optgroup label="── Savings ──">
                                <?php foreach ($saving_cats as $cat): ?>
                                    <option value="<?= $cat['id'] ?>">
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount (RM)</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-dim); font-weight: 600;">RM</span>
                        <input type="number" step="0.01" name="amount" class="form-control" style="padding-left: 3.5rem;" placeholder="0.00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: block; margin-bottom: 1rem;">Repeat Frequency</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <label class="freq-option" style="flex: 1; border: 1px solid var(--border); border-radius: 1rem; padding: 1rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            <input type="radio" name="frequency" value="weekly" class="freq-input" style="display: none;">
                            <div style="font-weight: 600; font-size: 0.875rem;">Weekly</div>
                        </label>
                        <label class="freq-option" style="flex: 1; border: 1px solid var(--primary); background: rgba(var(--primary-rgb), 0.1); border-radius: 1rem; padding: 1rem; cursor: pointer; text-align: center; transition: all 0.2s; color: var(--primary);">
                            <input type="radio" name="frequency" value="monthly" class="freq-input" checked style="display: none;">
                            <div style="font-weight: 600; font-size: 0.875rem;">Monthly</div>
                        </label>
                        <label class="freq-option" style="flex: 1; border: 1px solid var(--border); border-radius: 1rem; padding: 1rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            <input type="radio" name="frequency" value="yearly" class="freq-input" style="display: none;">
                            <div style="font-weight: 600; font-size: 0.875rem;">Yearly</div>
                        </label>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div class="form-group">
                        <label class="form-label">First Due Date</label>
                        <input type="date" name="first_run_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date (Optional)</label>
                        <input type="date" name="end_date" class="form-control" placeholder="Optional">
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2.5rem;">
                    <a href="recurring.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="flex: 2;">Save Recurring</button>
                </div>
            </form>
        </div>
    </div>
    </div>

<script>
document.querySelectorAll(".freq-option").forEach(btn => {
    btn.addEventListener("click", () => {
        // Reset others
        document.querySelectorAll(".freq-option").forEach(opt => {
            opt.style.borderColor = 'var(--border)';
            opt.style.background = 'transparent';
            opt.style.color = 'var(--text-main)';
        });
        
        // Activate current
        btn.style.borderColor = 'var(--primary)';
        btn.style.background = 'rgba(var(--primary-rgb), 0.1)';
        btn.style.color = 'var(--primary)';
        btn.querySelector("input").checked = true;
    });
});
</script>

