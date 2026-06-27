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

// --- Handle Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_budget_id'])) {
    $delete_id = intval($_POST['delete_budget_id']);
    if ($delete_id > 0) {
        $stmtDelete = $conn->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
        $stmtDelete->bind_param("ii", $delete_id, $user_id);
        $stmtDelete->execute();
    }
    header("Location: budgets.php?month=" . ($_GET['month'] ?? date("Y-m")) . "&deleted=1");
    exit;
}

// --- Month Filter ---
$month = $_GET['month'] ?? date("Y-m");
if (!preg_match("/^\d{4}-\d{2}$/", $month)) {
    $month = date("Y-m");
}
$startDate = $month . "-01";
$endDate = date("Y-m-t", strtotime($startDate));

// Fetch budgets & spend
$sql = "
SELECT b.id, b.limit_amount, c.name AS category_name,
COALESCE((SELECT SUM(amount) 
          FROM transactions 
          WHERE user_id = b.user_id 
          AND category_id = b.category_id
          AND type='expense'
          AND is_deleted = 0
          AND transaction_date BETWEEN ? AND ?
),0) AS spent
FROM budgets b
JOIN categories c ON c.id = b.category_id
WHERE b.user_id = ?
ORDER BY c.name;
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssi", $startDate, $endDate, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$pageTitle = "Budgets";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>



    <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">Monthly Budgets</h1>
            <p class="text-muted" style="margin: 0; font-size: 0.875rem;">Manage your spending limits and track your progress.</p>
        </div>
        <div class="card" style="padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem;">
            <form style="display: flex; gap: 0.5rem; align-items: center;">
                <input type="month" name="month" value="<?= $month ?>" class="form-control" style="width: 160px; padding: 0.5rem 0.75rem;">
                <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem;">Filter</button>
            </form>
            <div style="width: 1px; height: 24px; background: var(--border); margin: 0 0.5rem;"></div>
            <a href="set_budget.php" class="btn btn-secondary" style="padding: 0.5rem 1rem;">Set Budgets</a>
        </div>
    </header>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 2rem; color: var(--danger); padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.875rem; font-weight: 500;">Budget limit successfully deleted.</div>
        </div>
    <?php endif; ?>

    <?php if ($result->num_rows == 0): ?>
        <div class="card" style="text-align: center; padding: 4rem 2rem;">
            <div style="font-size: 3rem; margin-bottom: 1rem;"></div>
            <h3 style="margin-bottom: 0.5rem;">No budgets set</h3>
            <p class="text-muted" style="margin-bottom: 2rem;">Start managing your finances by setting spending limits for your categories.</p>
            <a href="set_budget.php" class="btn btn-primary">Set Your First Budget</a>
        </div>
    <?php else: ?>
        <div class="stat-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
            <?php while ($row = $result->fetch_assoc()):
                $limit = floatval($row['limit_amount']);
                $spent = floatval($row['spent']);
                $percent = ($limit > 0) ? round(($spent / $limit) * 100, 1) : 0;
                $isOver = $spent > $limit;
            ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                        <h3 style="font-size: 1rem; margin: 0;"><?= htmlspecialchars($row['category_name']) ?></h3>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span class="badge <?= $isOver ? 'badge-danger' : 'badge-success' ?>">
                                <?= $isOver ? 'Over Budget' : 'On Track' ?>
                            </span>
                            <button type="button" onclick="openDeleteModal(<?= $row['id'] ?>)" title="Delete Budget" style="background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 0.25rem; transition: color 0.2s;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-dim)'">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem;">
                            <span class="text-muted">Progress</span>
                            <span style="font-weight: 700; color: <?= $isOver ? 'var(--danger)' : 'var(--primary)' ?>;"><?= $percent ?>%</span>
                        </div>
                        <div style="height: 8px; background: var(--secondary); border-radius: 999px; overflow: hidden;">
                            <div style="height: 100%; width: <?= min($percent, 100) ?>%; background: <?= $isOver ? 'var(--danger)' : 'var(--primary)' ?>; border-radius: 999px; transition: width 0.5s ease;"></div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 0.125rem;">Spent</div>
                            <div style="font-weight: 700; font-size: 1.125rem;">RM <?= number_format($spent, 2) ?></div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 0.125rem;">Limit</div>
                            <div style="font-weight: 600; color: var(--text-muted);">RM <?= number_format($limit, 2) ?></div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>

<!-- DELETE MODAL -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <h3 style="margin-bottom: 1rem; color: var(--danger);">Delete Budget Limit</h3>
        <p class="text-muted" style="margin-bottom: 2rem;">Are you sure you want to delete this budget limit? This action cannot be undone.</p>
        
        <form method="POST">
            <input type="hidden" name="delete_budget_id" id="deleteBudgetId">
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1; background: var(--danger); border-color: var(--danger);">Yes, Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeleteModal(id) {
    document.getElementById('deleteBudgetId').value = id;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
</script>

<?php require_once '../../includes/footer.php'; ?>
