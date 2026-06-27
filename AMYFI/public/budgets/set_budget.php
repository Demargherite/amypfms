<?php
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

// Protect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = "";
$errors = [];

// Month filter (default current)
$month = $_GET['month'] ?? date("Y-m");
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}
$startDate = $month . "-01";
$endDate = date("Y-m-t", strtotime($startDate));
$monthLabel = date("F Y", strtotime($startDate));

/* =========================
   SAVE / DELETE BUDGETS
========================= */

// Handle Single Suggestion Update
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['apply_suggested'])) {

    $targetCatId  = intval($_POST['cat_id']);
    $targetAmount = floatval($_POST['amount']);
    $targetMonth  = $_POST['month'] ?? date("Y-m");
    $targetStart  = $targetMonth . "-01";

    if ($targetCatId > 0 && $targetAmount > 0) {
        $stmt = $conn->prepare("
            INSERT INTO budgets (user_id, category_id, limit_amount)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)
        ");
        $stmt->bind_param("iid", $user_id, $targetCatId, $targetAmount);

        if ($stmt->execute()) {
            header("Location: budgets.php?success=1");
            exit;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['budget'])) {

    // Prepared statements
    $saveStmt = $conn->prepare("
        INSERT INTO budgets (user_id, category_id, limit_amount)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)
    ");

    $deleteStmt = $conn->prepare("
        DELETE FROM budgets
        WHERE user_id = ?
          AND category_id = ?
    ");

    foreach ($_POST['budget'] as $catId => $amount) {

        $catId  = intval($catId);
        $amount = floatval($amount);

        if ($catId <= 0) continue;

        // If slider = 0 → DELETE budget
        if ($amount <= 0) {

            $deleteStmt->bind_param("ii", $user_id, $catId);
            $deleteStmt->execute();

        } else {

            // Save / Update budget
            $saveStmt->bind_param("iid", $user_id, $catId, $amount);
            $saveStmt->execute();
        }
    }

    header("Location: budgets.php?success=1");
    exit;
}

// Success message
if (isset($_GET['success'])) {
    $success = "Budgets saved successfully 💖";
}

/* =========================
   FETCH CATEGORIES + BUDGET
========================= */
$sql = "
SELECT c.id, c.name,
       COALESCE(b.limit_amount,0) AS limit_amount,
       COALESCE(SUM(t.amount),0) AS spent
FROM categories c
LEFT JOIN budgets b
     ON b.category_id = c.id
    AND b.user_id = ?
LEFT JOIN transactions t
     ON t.category_id = c.id
    AND t.user_id = ?
    AND t.type='expense'
    AND t.is_deleted = 0
    AND t.transaction_date BETWEEN ? AND ?
WHERE c.user_id = ?
  AND c.type='expense'
  AND c.is_active = 1
GROUP BY c.id, c.name
ORDER BY c.name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iissi", $user_id, $user_id, $startDate, $endDate, $user_id);
$stmt->execute();
$resCats = $stmt->get_result();

$pageTitle = "Set Budget";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="max-width: 900px; margin: 2rem auto;">
        <div class="card" style="padding: 3rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3rem; flex-wrap: wrap; gap: 1.5rem;">
                <div>
                    <h1 style="margin-bottom: 0.5rem;">Set Monthly Budgets</h1>
                    <p class="text-muted">Adjust your spending limits across all months. To check spending for a specific month against these limits, filter in the <a href="budgets.php">Budgets overview</a>.</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); margin-bottom: 2rem; color: var(--success);">
                    <div style="font-size: 0.875rem; font-weight: 500;"><?= $success ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <?php if ($resCats->num_rows === 0): ?>
                    <div class="alert-card" style="text-align: center; padding: 3rem;">
                        <p class="text-muted">No expense categories found. You need to create categories before setting budgets.</p>
                        <a href="../categories/add_category.php" class="btn btn-primary" style="margin-top: 1rem;">Add Category</a>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php while($row = $resCats->fetch_assoc()):
                            $catId = $row['id'];
                            $spent = (float)$row['spent'];
                            $limit = (float)$row['limit_amount'];
                        ?>
                        <div class="card" style="padding: 1.5rem; background: rgba(var(--primary-rgb), 0.02); border: 1px solid var(--border);">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; align-items: center;">
                                <div>
                                    <div style="font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem;"><?= htmlspecialchars($row['name']) ?></div>
                                    <div style="font-size: 0.875rem; color: var(--text-dim);">Actual Spent: RM <?= number_format($spent, 2) ?></div>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    <label class="form-label" style="font-size: 0.75rem; margin: 0;">Set Monthly Limit (RM)</label>
                                    <input type="number" 
                                           step="0.01" 
                                           min="0" 
                                           name="budget[<?= $catId ?>]" 
                                           value="<?= $limit > 0 ? $limit : '' ?>" 
                                           class="form-control" 
                                           placeholder="Enter budget (e.g. 500)"
                                           style="padding: 0.75rem 1rem;">
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 3rem;">
                        <a href="budgets.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Back</a>
                        <button type="submit" class="btn btn-primary" style="flex: 2;">Confirm All Budgets</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    </div>
