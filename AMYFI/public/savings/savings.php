<?php
session_start();
require_once '../../config/db.php';

// Protect page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = "";

/* =========================
   HANDLE POST ACTIONS
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST['action'] ?? '';

    // UPDATE GOAL
    if ($action === 'update_goal') {

        $goal_id       = intval($_POST['goal_id']);
        $name          = trim($_POST['name']);
        $target_amount = floatval($_POST['target_amount']);
        $current_amount= floatval($_POST['current_amount']);
        $deadline      = $_POST['deadline'] ?: null;
        $status        = $_POST['status'];

        if ($goal_id <= 0) $errors[] = "Invalid goal.";
        if ($name === "")  $errors[] = "Goal name cannot be empty.";
        if ($target_amount <= 0) $errors[] = "Target must be more than RM 0.";
        if (!in_array($status, ['active','completed','cancelled'])) {
            $errors[] = "Invalid status.";
        }

        if (empty($errors)) {
            // Fetch old name first for sync
            $oldStmt = $conn->prepare("SELECT name FROM savings_goals WHERE id = ? AND user_id = ?");
            $oldStmt->bind_param("ii", $goal_id, $user_id);
            $oldStmt->execute();
            $oldGoal = $oldStmt->get_result()->fetch_assoc();
            $oldName = $oldGoal['name'] ?? '';

            $stmt = $conn->prepare("
                UPDATE savings_goals
                SET name = ?, target_amount = ?, current_amount = ?, deadline = ?, status = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param(
                "sddssii",
                $name,
                $target_amount,
                $current_amount,
                $deadline,
                $status,
                $goal_id,
                $user_id
            );

            if ($stmt->execute()) {
                // 1. SYNC linked category name
                $syncCat = $conn->prepare("
                    UPDATE categories
                    SET name = ?
                    WHERE saving_goal_id = ? AND user_id = ?
                ");
                $syncCat->bind_param("sii", $name, $goal_id, $user_id);
                $syncCat->execute();

                // 2. SYNC transactions title (where title matches old goal name)
                // First find the category id linked to this goal
                $catResult = $conn->query("SELECT id FROM categories WHERE saving_goal_id = $goal_id AND user_id = $user_id");
                if ($catRow = $catResult->fetch_assoc()) {
                    $cat_id = $catRow['id'];
                    
                    // No title sync needed.
                    // Pages already use categories.name dynamically.
                }

                $success = "Saving goal updated successfully";
            } else {
                $errors[] = "Failed to update goal.";
            }
        }
    }

    // DELETE GOAL
    if ($action === 'delete_goal') {

        $goal_id = intval($_POST['goal_id']);

        if ($goal_id > 0) {

            // Delete linked saving category first
            $deleteCat = $conn->prepare("
                DELETE FROM categories
                WHERE saving_goal_id = ? AND user_id = ?
            ");
            $deleteCat->bind_param("ii", $goal_id, $user_id);
            $deleteCat->execute();

            // Delete saving goal
            $stmt = $conn->prepare("
                DELETE FROM savings_goals
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $goal_id, $user_id);

            if ($stmt->execute()) {
                $success = "Saving goal deleted successfully.";
            } else {
                $errors[] = "Failed to delete goal.";
            }
        }
    }

    // MARK COMPLETED
    if ($action === 'mark_completed') {
        $goal_id = intval($_POST['goal_id']);

        if ($goal_id > 0) {
            $stmt = $conn->prepare("
                UPDATE savings_goals
                SET status = 'completed'
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $goal_id, $user_id);
            if ($stmt->execute()) {
                $success = "Goal marked as completed";
            }
        }
    }
}

/* =========================
   FETCH SAVINGS GOALS
   with actual saved amount from transactions
   ========================= */
$stmt = $conn->prepare("
    SELECT 
        sg.id,
        sg.name,
        sg.target_amount,
        sg.current_amount,
        sg.deadline,
        sg.status,
        COALESCE(cat.id, 0) AS category_id,
        COALESCE(SUM(t.amount), 0) AS transaction_saved
    FROM savings_goals sg
    LEFT JOIN categories cat 
        ON cat.saving_goal_id = sg.id 
       AND cat.user_id = sg.user_id
       AND cat.is_active = 1
    LEFT JOIN transactions t
        ON t.category_id = cat.id
       AND t.type = 'saving'
       AND t.is_deleted = 0
    WHERE sg.user_id = ?
    GROUP BY sg.id
    ORDER BY sg.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$pageTitle = "Savings Goals";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>



    <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">Savings Goals</h1>
            <p class="text-muted" style="margin: 0; font-size: 0.875rem;">Track your progress and celebrate your financial milestones.</p>
        </div>
        <a href="add_saving_goal.php" class="btn btn-primary btn-sm">+ New Saving Goal</a>
    </header>

    <?php if ($success): ?>
        <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); margin-bottom: 1.5rem; color: var(--success); font-weight: 500;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 1.5rem; color: var(--danger);">
            <?php foreach ($errors as $e) echo "<div style='font-size: 0.875rem; font-weight: 500;'>• $e</div>"; ?>
        </div>
    <?php endif; ?>

    <div class="stat-grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">
        <?php if ($result->num_rows === 0): ?>
            <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 4rem 2rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;"></div>
                <h3 style="margin-bottom: 0.5rem;">No savings goals yet</h3>
                <p class="text-muted" style="margin-bottom: 2rem;">Start saving for your future by creating your first goal.</p>
                <a href="add_saving_goal.php" class="btn btn-primary">Create Your First Goal</a>
            </div>
        <?php else: ?>
            <?php while($g = $result->fetch_assoc()):
                $target  = floatval($g['target_amount']);
                $display_saved = floatval($g['current_amount']);
                $remaining = max(0, $target - $display_saved);

                // Monthly Suggestion Logic
                $monthly_suggestion = null;
                if (!empty($g['deadline']) && $remaining > 0) {
                    $today = new DateTime();
                    $deadline = new DateTime($g['deadline']);
                    if ($deadline > $today) {
                        $interval = $today->diff($deadline);
                        $months_left = ($interval->y * 12) + $interval->m;
                        if ($interval->d > 0) {
                            $months_left += 1;
                        }
                        if ($months_left > 0) {
                            $monthly_suggestion = $remaining / $months_left;
                        }
                    }
                }

                $percent = ($target > 0) ? round(($display_saved / $target) * 100) : 0;
                $status  = $g['status'];
                $completed = ($status === 'completed' || $percent >= 100);
            ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                        <h3 style="font-size: 1rem; margin: 0;"><?= htmlspecialchars($g['name']) ?></h3>
                        <span class="badge <?= $completed ? 'badge-success' : ($status === 'cancelled' ? 'badge-danger' : 'badge-primary') ?>">
                            <?= $completed ? 'Completed' : ucfirst($status) ?>
                        </span>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem;">
                            <span class="text-muted">Progress</span>
                            <span style="font-weight: 700; color: var(--primary);"><?= $percent ?>%</span>
                        </div>
                        <div style="height: 8px; background: var(--secondary); border-radius: 999px; overflow: hidden;">
                            <div style="height: 100%; width: <?= min($percent, 100) ?>%; background: var(--primary); border-radius: 999px; transition: width 0.5s ease;"></div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.5rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 0.125rem;">Saved</div>
                            <div style="font-weight: 700; font-size: 1.125rem;">RM <?= number_format($display_saved, 2) ?></div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 0.125rem;">Target</div>
                            <div style="font-weight: 600; color: var(--text-muted);">RM <?= number_format($target, 2) ?></div>
                        </div>
                    </div>

                    <div style="padding: 0.75rem; background: rgba(255, 255, 255, 0.03); border-radius: 0.75rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: var(--text-dim);">Remaining:</span>
                            <span style="font-weight: 600; font-size: 0.875rem; color: <?= $remaining > 0 ? 'var(--text-main)' : 'var(--success)' ?>;">
                                <?= $remaining > 0 ? 'RM '.number_format($remaining, 2) : 'Goal Reached!' ?>
                            </span>
                        </div>
                        <?php if (!empty($g['deadline'])): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border);">
                                <span style="font-size: 0.75rem; color: var(--text-dim);">Deadline:</span>
                                <span style="font-size: 0.75rem; font-weight: 500;"><?= date('d M Y', strtotime($g['deadline'])) ?></span>
                            </div>

                            <?php if ($monthly_suggestion !== null): ?>
                                <div style="margin-top: 0.4rem; font-size: 0.75rem; color: var(--primary); font-weight: 500;">
                                     Save RM <?= number_format($monthly_suggestion, 2) ?>/month to reach your goal
                                </div>
                            <?php elseif ($remaining > 0): ?>
                                <div style="margin-top: 0.4rem; font-size: 0.75rem; color: var(--danger); font-weight: 500;">
                                    Deadline too close or passed
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-secondary btn-sm" style="flex: 1;"
                            onclick="openEditModal(this)"
                            data-id="<?= $g['id'] ?>"
                            data-name="<?= htmlspecialchars($g['name'], ENT_QUOTES) ?>"
                            data-target="<?= $g['target_amount'] ?>"
                            data-current="<?= $g['current_amount'] ?>"
                            data-deadline="<?= $g['deadline'] ?>"
                            data-status="<?= $g['status'] ?>"
                        >Edit</button>
                        
                        <?php if ($status !== 'completed' && $status !== 'cancelled'): ?>
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="mark_completed">
                                <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
                                <button class="btn btn-primary btn-sm" style="width: 100%;" type="submit">Complete</button>
                            </form>
                        <?php endif; ?>

                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $g['id'] ?>, '<?= htmlspecialchars($g['name'], ENT_QUOTES) ?>')">Delete</button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</main>

<!-- EDIT MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <h3 style="margin-bottom: 1.5rem;">Edit Savings Goal</h3>
        
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="update_goal">
            <input type="hidden" name="goal_id" id="editGoalId">

            <div class="form-group">
                <label class="form-label">Goal Name</label>
                <input type="text" class="form-control" name="name" id="editName">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Target Amount (RM)</label>
                    <input type="number" step="0.01" class="form-control" name="target_amount" id="editTarget">
                </div>
                <div class="form-group">
                    <label class="form-label">Current Saved (RM)</label>
                    <input type="number" step="0.01" class="form-control" name="current_amount" id="editCurrent">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Deadline</label>
                    <input type="date" class="form-control" name="deadline" id="editDeadline">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status" id="editStatus">
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 1rem;"></div>
        <h3 style="margin-bottom: 1rem;">Delete Goal?</h3>
        <p id="deleteGoalName" class="text-muted" style="font-size: 0.875rem; margin-bottom: 1rem;"></p>
        <p class="text-danger" style="font-size: 0.75rem; margin-bottom: 2.5rem; font-weight: 500;">
            This will also remove the linked saving category from transactions.
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="delete_goal">
            <input type="hidden" name="goal_id" id="deleteGoalId">

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">Delete Goal</button>
                <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(btn) {
    document.getElementById('editGoalId').value   = btn.dataset.id;
    document.getElementById('editName').value     = btn.dataset.name;
    document.getElementById('editTarget').value   = btn.dataset.target;
    document.getElementById('editCurrent').value  = btn.dataset.current;
    document.getElementById('editDeadline').value = btn.dataset.deadline || '';
    document.getElementById('editStatus').value   = btn.dataset.status;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openDeleteModal(id, name) {
    document.getElementById('deleteGoalId').value        = id;
    document.getElementById('deleteGoalName').textContent = 'Delete goal: ' + name + '?';
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
</script>
<?php require_once '../../includes/footer.php'; ?>