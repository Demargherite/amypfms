<?php
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

/* =========================
   AUTH CHECK
========================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id  = $_SESSION['user_id'];
$errors   = [];
$warnings = [];

/* =========================
   FETCH ACTIVE CATEGORIES
========================= */
$catQuery = $conn->prepare("
    SELECT id, name, type
    FROM categories 
    WHERE (user_id = ? OR user_id IS NULL)
      AND is_active = 1
      AND type IN ('expense','saving')
    ORDER BY type ASC, name ASC
");
$catQuery->bind_param("i", $user_id);
$catQuery->execute();
$categories = $catQuery->get_result();

/* =========================
   HANDLE FORM SUBMIT
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* =========================
   CLEAN INPUT + VALIDATION
========================= */
$date           = trim($_POST["transaction_date"] ?? '');
$amount_raw     = trim($_POST["amount"] ?? '');
$category_id    = trim($_POST["category_id"] ?? '');
$payment_method = trim($_POST["payment_method"] ?? '');
$note           = trim($_POST["note"] ?? '');

/* Date */
if ($date === '') {
    $errors[] = "Please select a transaction date.";
} elseif ($date > date('Y-m-d')) {
    $errors[] = "Transaction date cannot be in the future.";
}

/* Amount */
if ($amount_raw === '') {
    $errors[] = "Please enter an amount.";
} elseif (!is_numeric($amount_raw)) {
    $errors[] = "Amount must be a valid number.";
} elseif (floatval($amount_raw) <= 0) {
    $errors[] = "Amount must be greater than RM 0.";
}
$amount = floatval($amount_raw);

/* Category */
if ($category_id === '') {
    $errors[] = "Please choose a category.";
}

/* Payment */
if ($payment_method === '') {
    $errors[] = "Please select a payment method.";
} else {
    $allowedMethods = ['cash', 'e_wallet', 'bank'];
    if (!in_array($payment_method, $allowedMethods)) {
        $errors[] = "Invalid payment method selected.";
    }
}

/* Note */
if (strlen($note) > 255) {
    $errors[] = "Note cannot exceed 255 characters.";
}

// FORCE EXPENSE (After validation)
if (empty($errors)) {
    $typeQuery = $conn->prepare("SELECT type FROM categories WHERE id = ?");
    $typeQuery->bind_param("i", $category_id);
    $typeQuery->execute();
    $catData = $typeQuery->get_result()->fetch_assoc();
    $type = $catData['type'] ?? 'expense';
}

    /* =========================
       BUDGET WARNING (NOT BLOCK)
    ========================= */
    if (empty($errors)) {

        $month = date('Y-m-01', strtotime($date));

        $budgetQ = $conn->prepare("
            SELECT limit_amount
            FROM budgets
            WHERE user_id = ?
              AND category_id = ?
              AND month = ?
        ");
        $budgetQ->bind_param("iis", $user_id, $category_id, $month);
        $budgetQ->execute();
        $budget = $budgetQ->get_result()->fetch_assoc();

        if ($budget) {

            $spentQ = $conn->prepare("
                SELECT SUM(amount) total
                FROM transactions
                WHERE user_id = ?
                  AND category_id = ?
                  AND type IN ('expense','saving')
                  AND transaction_date BETWEEN ? AND LAST_DAY(?)
            ");
            $spentQ->bind_param("iiss", $user_id, $category_id, $month, $month);
            $spentQ->execute();
            $spent = $spentQ->get_result()->fetch_assoc();

            $current = $spent['total'] ?? 0;

            if (($current + $amount) > $budget['limit_amount']) {
                $warnings[] = "This expense exceeds your monthly budget.";
            }
        }
    }

    /* =========================
       INSERT TRANSACTION
    ========================= */
    if (empty($errors)) {

        $stmt = $conn->prepare("
            INSERT INTO transactions
            (user_id, amount, type, category_id, payment_method, note, transaction_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
             "idsisss",
    $user_id,
    $amount,
    $type,
    $category_id,
    $payment_method,
    $note,
    $date
);

        if ($stmt->execute()) {
            
// ===============================
// UPDATE SAVINGS (REPLACE TRIGGER)
// ===============================
if ($type == 'saving') {

    $updateSaving = $conn->prepare("
        UPDATE savings_goals sg
        JOIN categories c ON c.id = ?
        SET sg.current_amount = sg.current_amount + ?
        WHERE sg.name = c.name AND sg.user_id = ?
    ");

    $updateSaving->bind_param("idi", $category_id, $amount, $user_id);
    $updateSaving->execute();
}

            // ===============================
// AUTO-SYNC RECURRING (AFTER PAY)
// ===============================
$recurringQ = $conn->prepare("
    SELECT id, frequency, next_run_date, end_date
    FROM recurring_transactions
    WHERE user_id = ?
      AND category_id = ?
      AND type = ?
      AND is_active = 1
      AND next_run_date >= ? AND next_run_date <= LAST_DAY(?)
    LIMIT 1
");

$startOfMonth = date('Y-m-01', strtotime($date));
$recurringQ->bind_param(
    "iisss",
    $user_id,
    $category_id,
    $type,
    $startOfMonth,
    $startOfMonth
);
$recurringQ->execute();
$recurring = $recurringQ->get_result()->fetch_assoc();

if ($recurring) {

    $interval = match ($recurring['frequency']) {
        'weekly'  => '1 WEEK',
        'monthly' => '1 MONTH',
        'yearly'  => '1 YEAR',
        default   => null
    };

    if ($interval) {

        $nextRun = date(
            'Y-m-d',
            strtotime($recurring['next_run_date'] . " + $interval")
        );

        // STOP if exceed end_date
        if (!$recurring['end_date'] || $nextRun <= $recurring['end_date']) {

            $update = $conn->prepare("
                UPDATE recurring_transactions
                SET next_run_date = ?
                WHERE id = ?
            ");
            $update->bind_param("si", $nextRun, $recurring['id']);
            $update->execute();

        } else {
            // Auto deactivate if finished
            $stop = $conn->prepare("
                UPDATE recurring_transactions
                SET is_active = 0
                WHERE id = ?
            ");
            $stop->bind_param("i", $recurring['id']);
            $stop->execute();
        }
    }
}
            header("Location: ../transactions/transactions.php?success=1");
            exit;
        } else {
            $errors[] = "Failed to save transaction.";
        }
    }
}

$pageTitle = "Add Transaction";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="max-width: 600px; margin: 2rem auto;">
        <div class="card" style="padding: 3rem;">
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">Add Transaction</h1>
                <p class="text-muted">Record your expenses or savings to keep your balance updated.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 1.5rem; color: var(--danger);">
                    <div id="serverErrors">
                        <?php foreach ($errors as $e) echo "<div style='font-size: 0.875rem; font-weight: 500;'>• $e</div>"; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($warnings)): ?>
                <div class="alert-card" style="background: rgba(245, 158, 11, 0.05); border-color: rgba(245, 158, 11, 0.2); margin-bottom: 1.5rem; color: var(--warning);">
                    <?php foreach ($warnings as $w) echo "<div style='font-size: 0.875rem; font-weight: 500;'>⚠️ $w</div>"; ?>
                </div>
            <?php endif; ?>

            <form id="transactionForm" method="POST" novalidate onsubmit="return validateTransaction()">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transaction_date" class="form-control" 
                           value="<?= htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Amount (RM)</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-dim); font-weight: 600;">RM</span>
                        <input type="number" name="amount" step="0.01" class="form-control" style="padding-left: 3.5rem;" 
                               placeholder="0.00" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control">
                        <option value="">-- Select Category --</option>
                        <?php
                        $expense_cats = [];
                        $saving_cats  = [];
                        $categories->data_seek(0);
                        while($c = $categories->fetch_assoc()) {
                            if ($c['type'] === 'saving') $saving_cats[]  = $c;
                            else                         $expense_cats[] = $c;
                        }
                        if (!empty($expense_cats)): ?>
                            <optgroup label="── Expenses ──">
                                <?php foreach ($expense_cats as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($c['id'] == ($_POST['category_id'] ?? '')) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($saving_cats)): ?>
                            <optgroup label="── Savings ──">
                                <?php foreach ($saving_cats as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($c['id'] == ($_POST['category_id'] ?? '')) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="">-- Select Method --</option>
                        <option value="cash" <?= ($_POST['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="e_wallet" <?= ($_POST['payment_method'] ?? '') === 'e_wallet' ? 'selected' : '' ?>>e-Wallet</option>
                        <option value="bank" <?= ($_POST['payment_method'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Note (Optional)</label>
                    <textarea name="note" class="form-control" style="height: 100px; resize: none;" 
                              placeholder="What was this for?"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2.5rem;">
                    <a href="../transactions/transactions.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="flex: 2;">Save Transaction</button>
                </div>
            </form>
        </div>
    </div>
    </div>

<script>
function validateTransaction() {

    let errors = [];

    const date   = document.querySelector('[name="transaction_date"]').value;
    const amount = parseFloat(document.querySelector('[name="amount"]').value || 0);
    const cat    = document.querySelector('[name="category_id"]').value;
    const pay    = document.querySelector('[name="payment_method"]').value;

    // =========================
    // BASIC VALIDATION
    // =========================
    if (!date)
        errors.push("Please select a transaction date.");

    if (isNaN(amount) || amount <= 0)
        errors.push("Please enter an amount.");

    if (!cat)
        errors.push("Please choose a category.");

    if (!['cash','e_wallet','bank'].includes(pay))
        errors.push("Please select a payment method.");

    // =========================
    // DATE VALIDATION (LOCAL SAFE)
    // =========================
    if (date) {

        const today = new Date();
        today.setHours(0,0,0,0);

        const parts = date.split('-'); // YYYY-MM-DD
        const selectedDate = new Date(
            parts[0],
            parts[1] - 1,
            parts[2]
        );

        if (selectedDate > today)
            errors.push("Transaction date cannot be in the future.");
    }

    // =========================
    // SHOW ERRORS
    // =========================
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
        errorCard.style.background = 'rgba(239, 68, 68, 0.05)';
        errorCard.style.borderColor = 'rgba(239, 68, 68, 0.2)';
        errorCard.style.marginBottom = '1.5rem';
        errorCard.style.color = 'var(--danger)';
        errorCard.innerHTML = `<div id="serverErrors"></div>`;
        const form = document.getElementById('transactionForm');
        if (form) form.before(errorCard);
        box = document.getElementById('serverErrors');
    }
    box.innerHTML = errors.map(e => `<div style='font-size: 0.875rem; font-weight: 500;'>• ${e}</div>`).join('');
}
</script>

<?php require_once '../../includes/footer.php'; ?>