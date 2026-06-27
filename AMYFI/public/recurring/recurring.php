<?php
session_start();

// User must be logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../../config/db.php';

$user_id = $_SESSION["user_id"];

/* ==========================
   AUTO-ADVANCE RECURRING INCOME
   ========================== */
$today = date('Y-m-d');

$incomeStmt = $conn->prepare("
    SELECT id, next_run_date, frequency
    FROM recurring_transactions
    WHERE user_id = ?
      AND type = 'income'
      AND is_active = 1
      AND next_run_date < ?
");
$incomeStmt->bind_param("is", $user_id, $today);
$incomeStmt->execute();
$incomeRes = $incomeStmt->get_result();

while ($income = $incomeRes->fetch_assoc()) {

    $nextDate = $income['next_run_date'];

    while ($nextDate < $today) {
        switch ($income['frequency']) {
            case 'weekly':
                $nextDate = date('Y-m-d', strtotime("+1 week", strtotime($nextDate)));
                break;
            case 'monthly':
                $nextDate = date('Y-m-d', strtotime("+1 month", strtotime($nextDate)));
                break;
            case 'yearly':
                $nextDate = date('Y-m-d', strtotime("+1 year", strtotime($nextDate)));
                break;
        }
    }

    $upd = $conn->prepare("
        UPDATE recurring_transactions
        SET next_run_date = ?
        WHERE id = ?
    ");
    $upd->bind_param("si", $nextDate, $income['id']);
    $upd->execute();
}

/* ==========================
   1. Handle Toggle Status (AJAX)
   ========================== */
if (isset($_POST['toggle_id'])) {
    $toggle_id = intval($_POST['toggle_id']);
    $newStatus = intval($_POST['new_status']);

    $stmt = $conn->prepare("UPDATE recurring_transactions SET is_active = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("iii", $newStatus, $toggle_id, $user_id);
    $stmt->execute();
    exit;
}

/* ==========================
   2. Handle Edit / Delete (normal POST)
   ========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {

    // EDIT RECURRING
    if ($_POST['action'] === 'edit') {
        $rec_id        = intval($_POST['recurring_id']);
        $type          = $_POST['type'] ?? 'expense';
        $saving          = $_POST['saving'] ?? 'saving';
        $amount        = floatval($_POST['amount'] ?? 0);
        $category_id   = intval($_POST['category_id'] ?? 0);
        $frequency     = $_POST['frequency'] ?? 'monthly';
        $next_run_date = $_POST['next_run_date'] ?? '';
        $end_date      = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        // simple validation (you can expand if needed)
        if ($amount > 0 && $category_id > 0 && in_array($type, ['income','expense','saving']) && in_array($frequency,['weekly','monthly','yearly']) && $next_run_date) {

            $stmt = $conn->prepare("
                UPDATE recurring_transactions
                SET type = ?, amount = ?, category_id = ?, frequency = ?, next_run_date = ?, end_date = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param(
                "sdisssii",
                $type,
                $amount,
                $category_id,
                $frequency,
                $next_run_date,
                $end_date,
                $rec_id,
                $user_id
            );
            $stmt->execute();
        }
        header("Location: recurring.php?updated=1");
        exit;
    }

    // DELETE RECURRING
    if ($_POST['action'] === 'delete') {
        $rec_id = intval($_POST['recurring_id'] ?? 0);

        if ($rec_id > 0) {
            $stmt = $conn->prepare("DELETE FROM recurring_transactions WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $rec_id, $user_id);
            $stmt->execute();
        }

        header("Location: recurring.php?deleted=1");
        exit;
    }
}

/* ==========================
   3. Fetch categories for edit modal
   ========================== */
$catStmt = $conn->prepare("
    SELECT id, name, type
    FROM categories
    WHERE (user_id = ? OR user_id IS NULL)
      AND is_active = 1
    ORDER BY type ASC, name ASC
");
$catStmt->bind_param("i", $user_id);
$catStmt->execute();
$categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ==========================
   4. Query recurring list
   ========================== */
$sql = "
    SELECT 
        rt.id, 
        rt.type, 
        rt.amount, 
        rt.frequency, 
        rt.next_run_date, 
        rt.is_active,
        rt.category_id,
        rt.end_date,
        c.name AS category_name
    FROM recurring_transactions rt
    LEFT JOIN categories c ON c.id = rt.category_id
    WHERE rt.user_id = ?
    ORDER BY rt.next_run_date ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$pageTitle = "Recurring Payments";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>



    <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="margin-bottom: 0.5rem; font-size: 1.75rem;">Recurring Payments</h1>
            <p class="text-muted" style="margin: 0; font-size: 0.875rem;">Automate your regular income and expenses for better planning.</p>
        </div>
        <a href="add_recurring.php" class="btn btn-primary btn-sm">+ Add Recurring Payment</a>
    </header>

    <div class="card">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Amount (RM)</th>
                        <th>Frequency</th>
                        <th>Next Due</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($res->num_rows === 0): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;" class="text-dim">No recurring payments configured.</td>
                        </tr>
                    <?php else: ?>
                        <?php while($row = $res->fetch_assoc()):
                            $dueDate = strtotime($row['next_run_date']);
                            $todayTS = strtotime(date("Y-m-d"));
                            $isOverdue = ($row['type'] === 'expense' && $dueDate < $todayTS);
                            $isSoon = ($row['type'] === 'expense' && ($dueDate - $todayTS) <= 3*86400);
                        ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $row['type'] === 'income' ? 'badge-success' : ($row['type'] === 'saving' ? 'badge-primary' : 'badge-danger') ?>">
                                        <?= ucfirst($row['type']) ?>
                                    </span>
                                </td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($row['category_name'] ?? '–'); ?></td>
                                <td style="font-weight: 700;">RM <?= number_format($row['amount'], 2) ?></td>
                                <td><?= ucfirst($row['frequency']) ?></td>
                                <td style="white-space: nowrap; color: <?= $isOverdue ? 'var(--danger)' : ($isSoon ? 'var(--warning)' : 'inherit') ?>; font-weight: <?= $isOverdue || $isSoon ? '700' : 'normal' ?>;">
                                    <?= date("d M Y", $dueDate) ?>
                                    <?php if ($isOverdue): ?> <span style="font-size: 0.75rem;">(Overdue)</span><?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div class="toggle-track" style="cursor: pointer; width: 36px; height: 18px; position: relative; background: <?= $row['is_active'] ? 'var(--primary)' : 'var(--secondary)' ?>; border-radius: 999px; transition: var(--transition);">
                                            <input type="checkbox" class="toggleActive" 
                                                   data-id="<?= $row['id'] ?>" 
                                                   <?= $row['is_active'] ? 'checked' : '' ?>
                                                   style="position: absolute; inset: 0; opacity: 0; cursor: pointer; z-index: 2;">
                                            <div class="toggle-knob" style="position: absolute; top: 2px; left: <?= $row['is_active'] ? '20px' : '2px' ?>; width: 14px; height: 14px; background: white; border-radius: 50%; transition: var(--transition);"></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                                        <button class="btn btn-secondary btn-sm"
                                            onclick="openEditRecurringModal(this)"
                                            data-id="<?= $row['id'] ?>"
                                            data-type="<?= htmlspecialchars($row['type']) ?>"
                                            data-amount="<?= $row['amount'] ?>"
                                            data-frequency="<?= htmlspecialchars($row['frequency']) ?>"
                                            data-next="<?= $row['next_run_date'] ?>"
                                            data-category="<?= $row['category_id'] ?>"
                                            data-end="<?= $row['end_date'] ?? '' ?>"
                                        >Edit</button>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteRecurringModal(<?= $row['id'] ?>)">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- EDIT MODAL -->
<div id="editRecurringModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <h3 style="margin-bottom: 1.5rem;">Edit Recurring Payment</h3>
        
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="recurring_id" id="edit_recurring_id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" id="edit_type" class="form-control" onchange="filterCategoriesByType()">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                        <option value="saving">Saving</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" id="edit_category_id" class="form-control">
                        <option value="">-- Select Category --</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" data-type="<?= $cat['type'] ?>">
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Amount (RM)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="edit_amount" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Frequency</label>
                    <select name="frequency" id="edit_frequency" class="form-control">
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Next Due Date</label>
                    <input type="date" name="next_run_date" id="edit_next_run_date" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">End Date (Optional)</label>
                    <input type="date" name="end_date" id="edit_end_date" class="form-control">
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                <button type="button" onclick="closeEditRecurringModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div id="deleteRecurringModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 1rem;"></div>
        <h3 style="margin-bottom: 1rem;">Delete Recurring Payment?</h3>
        <p class="text-muted" style="font-size: 0.875rem; margin-bottom: 2.5rem;">
            This recurring payment will be removed permanently. Past transactions will not be affected.
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="recurring_id" id="delete_recurring_id">

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">Delete Payment</button>
                <button type="button" onclick="closeDeleteRecurringModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>
</div>

<script>
// Toggle switch update without reload (Improved AJAX and UI logic)
document.querySelectorAll('.toggleActive').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        let isChecked = this.checked;
        let newStatus = isChecked ? 1 : 0;
        let id        = this.dataset.id;
        
        // UI Interaction Points
        const track = this.closest('.toggle-track');
        const knob  = track.querySelector('.toggle-knob');

        // Instant UI feedback
        track.style.background = isChecked ? 'var(--primary)' : 'var(--secondary)';
        knob.style.left        = isChecked ? '20px' : '2px';

        fetch("", {
            method: "POST",
            headers: {"Content-Type":"application/x-www-form-urlencoded"},
            body: `toggle_id=${id}&new_status=${newStatus}`
        }).then(response => {
            if (!response.ok) throw new Error('Network response failed');
        }).catch(err => {
            console.error("Failed to update status:", err);
            // Revert UI on failure
            this.checked = !isChecked;
            track.style.background = !isChecked ? 'var(--primary)' : 'var(--secondary)';
            knob.style.left        = !isChecked ? '20px' : '2px';
            alert("Connection error. Could not update status.");
        });
    });
});

/* ==========================
   EDIT MODAL JS
   ========================== */
function openEditRecurringModal(btn) {
    const modal = document.getElementById('editRecurringModal');

    const id        = btn.dataset.id;
    const type      = btn.dataset.type;
    const amount    = btn.dataset.amount;
    const freq      = btn.dataset.frequency;
    const nextDate  = btn.dataset.next;
    const category  = btn.dataset.category;
    const endDate   = btn.dataset.end || '';

    document.getElementById('edit_recurring_id').value  = id;
    document.getElementById('edit_type').value          = type;
    document.getElementById('edit_amount').value        = amount;
    document.getElementById('edit_frequency').value     = freq;
    document.getElementById('edit_next_run_date').value = nextDate;
    document.getElementById('edit_end_date').value      = endDate;

    // Filter categories to match selected type, then set value
    filterCategoriesByType();

    const catSelect = document.getElementById('edit_category_id');
    catSelect.value = category || '';

    modal.style.display = 'flex';
}

/* Filter category dropdown to only show options matching selected type */
function filterCategoriesByType() {
    const selectedType = document.getElementById('edit_type').value;
    const catSelect    = document.getElementById('edit_category_id');
    const currentVal   = catSelect.value;

    Array.from(catSelect.options).forEach(opt => {
        if (opt.value === '') {
            opt.style.display = '';
            return;
        }
        const optType = opt.dataset.type || '';
        // For 'expense' type, show expense categories
        // For 'income' type, show income categories
        // For 'saving' type, show saving categories
        opt.style.display = (optType === selectedType) ? '' : 'none';
    });

    // If current selection is now hidden, reset to placeholder
    const selected = catSelect.options[catSelect.selectedIndex];
    if (selected && selected.style.display === 'none') {
        catSelect.value = '';
    }
}

function closeEditRecurringModal() {
    document.getElementById('editRecurringModal').style.display = 'none';
}

/* ==========================
   DELETE MODAL JS
   ========================== */
function openDeleteRecurringModal(id) {
    document.getElementById('delete_recurring_id').value = id;
    document.getElementById('deleteRecurringModal').style.display = 'flex';
}
function closeDeleteRecurringModal() {
    document.getElementById('deleteRecurringModal').style.display = 'none';
}
</script>
