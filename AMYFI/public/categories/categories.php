<?php
session_start();

/* =======================
   AUTH CHECK
======================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../../config/db.php';
$user_id = $_SESSION["user_id"];

/* =======================
   SMART CHILD-BASED CATEGORY AUTO-CREATE
======================= */

// Fetch children
$childStmt = $conn->prepare("
    SELECT age FROM children
    WHERE user_id = ?
");
$childStmt->bind_param("i", $user_id);
$childStmt->execute();
$childRes = $childStmt->get_result();

$autoCategories = [];

while ($child = $childRes->fetch_assoc()) {
    $age = intval($child['age']);

    if ($age >= 0 && $age <= 2) {
        $autoCategories = array_merge($autoCategories, [
            "Diapers",
            "Milk / Formula",
            "Child Medical"
        ]);
    } elseif ($age >= 3 && $age <= 6) {
        $autoCategories = array_merge($autoCategories, [
            "Daycare / Babysitter",
            "Preschool Fees",
            "Child Medical"
        ]);
    } elseif ($age >= 7 && $age <= 12) {
        $autoCategories = array_merge($autoCategories, [
            "School Fees",
            "Stationery & Books",
            "Tuition"
        ]);
    } elseif ($age >= 13 && $age <= 17) {
        $autoCategories = array_merge($autoCategories, [
            "Tuition",
            "Transport",
            "School Activities"
        ]);
    }
}

// Remove duplicates in array
$autoCategories = array_unique($autoCategories);

foreach ($autoCategories as $catName) {

    $normalized = trim(preg_replace('/\s+/', ' ', $catName));

    // Check duplicate (case-insensitive)
    $check = $conn->prepare("
        SELECT id FROM categories
        WHERE user_id = ?
          AND type = 'expense'
          AND LOWER(name) = LOWER(?)
        LIMIT 1
    ");
    $check->bind_param("is", $user_id, $normalized);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();

    if (!$exists) {
        $insert = $conn->prepare("
            INSERT INTO categories (user_id, name, type, is_active)
            VALUES (?, ?, 'expense', 1)
        ");
        $insert->bind_param("is", $user_id, $normalized);
        $insert->execute();
    }
}
/* =======================
   AJAX TOGGLE
======================= */
if (isset($_POST['toggle_id'])) {
    $toggle_id = intval($_POST['toggle_id']);
    $newStatus = intval($_POST['new_status']);

    $stmt = $conn->prepare("
        UPDATE categories
        SET is_active = ?
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("iii", $newStatus, $toggle_id, $user_id);
    $stmt->execute();
    exit;
}

/* =======================
   VARIABLES
======================= */
$success = "";
$errors  = [];
$protectedCategories = ["Deleted Category"];

/* =======================
   FORM HANDLER
======================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_type'])) {

   /* =======================
   EDIT CATEGORY
======================= */
if ($_POST['form_type'] === "edit") {

    $cat_id = intval($_POST['edit_id']);
    $name   = trim(preg_replace('/\s+/', ' ', $_POST['edit_name']));
    $type   = $_POST['edit_type'];

    if ($cat_id <= 0) $errors[] = "Invalid category.";
    if ($name === "") $errors[] = "Category name required.";
    if (!in_array($type, ['income','expense','saving'])) $errors[] = "Invalid type.";

    // prevent duplicates (case-insensitive, exclude current id)
    if (empty($errors)) {
        $dup = $conn->prepare("
            SELECT id FROM categories
            WHERE user_id = ?
              AND type = ?
              AND LOWER(name) = LOWER(?)
              AND id != ?
            LIMIT 1
        ");
        $dup->bind_param("issi", $user_id, $type, $name, $cat_id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = "Category with same name already exists.";
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE categories
            SET name = ?, type = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ssii", $name, $type, $cat_id, $user_id);

        if ($stmt->execute()) {

            // If saving category, sync savings_goals table
            if ($type === 'saving') {

                $goalStmt = $conn->prepare("
                    SELECT saving_goal_id
                    FROM categories
                    WHERE id = ? AND user_id = ?
                ");
                $goalStmt->bind_param("ii", $cat_id, $user_id);
                $goalStmt->execute();
                $goal = $goalStmt->get_result()->fetch_assoc();

                if (!empty($goal['saving_goal_id'])) {

                    $updateGoal = $conn->prepare("
                        UPDATE savings_goals
                        SET name = ?
                        WHERE id = ? AND user_id = ?
                    ");
                    $updateGoal->bind_param(
                        "sii",
                        $name,
                        $goal['saving_goal_id'],
                        $user_id
                    );
                    $updateGoal->execute();
                }
            }

            $success = "Category updated successfully.";

        } else {
            $errors[] = "Failed to update category.";
        }
    }
}

/* =======================
   DELETE CATEGORY
======================= */
if ($_POST['form_type'] === "delete") {

    $cat_id = intval($_POST['delete_id']);

    if ($cat_id <= 0) {
        $errors[] = "Invalid category.";
    } else {

        $stmt = $conn->prepare("
            SELECT id, name, type, saving_goal_id
            FROM categories
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $cat_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $errors[] = "Category not found.";
        } else {

            $cat = $res->fetch_assoc();

            // If saving category, also delete the linked saving goal
            if ($cat['type'] === 'saving' && !empty($cat['saving_goal_id'])) {
                $deleteGoal = $conn->prepare("
                    DELETE FROM savings_goals
                    WHERE id = ? AND user_id = ?
                ");
                $deleteGoal->bind_param("ii", $cat['saving_goal_id'], $user_id);
                $deleteGoal->execute();
            }

            if (in_array($cat['name'], $protectedCategories)) {
                $errors[] = "This category cannot be deleted.";
            } else {

                // Ensure fallback exists
                $fb = $conn->prepare("
                    SELECT id FROM categories
                    WHERE user_id = ?
                      AND name = 'Deleted Category'
                      AND type = 'expense'
                    LIMIT 1
                ");
                $fb->bind_param("i", $user_id);
                $fb->execute();
                $fallback = $fb->get_result()->fetch_assoc();

                if (!$fallback) {
                    $create = $conn->prepare("
                        INSERT INTO categories (user_id, name, type, is_active)
                        VALUES (?, 'Deleted Category', 'expense', 0)
                    ");
                    $create->bind_param("i", $user_id);
                    $create->execute();
                    $fallback_id = $create->insert_id;
                } else {
                    $fallback_id = $fallback['id'];
                }

                // Reassign transactions
                $re1 = $conn->prepare("
                    UPDATE transactions
                    SET category_id = ?
                    WHERE user_id = ? AND category_id = ?
                ");
                $re1->bind_param("iii", $fallback_id, $user_id, $cat_id);
                $re1->execute();

                // Reassign recurring
                $re2 = $conn->prepare("
                    UPDATE recurring_transactions
                    SET category_id = ?
                    WHERE user_id = ? AND category_id = ?
                ");
                $re2->bind_param("iii", $fallback_id, $user_id, $cat_id);
                $re2->execute();

                // Delete budgets
                $delBud = $conn->prepare("
                    DELETE FROM budgets
                    WHERE user_id = ? AND category_id = ?
                ");
                $delBud->bind_param("ii", $user_id, $cat_id);
                $delBud->execute();

                // Delete category
                $del = $conn->prepare("
                    DELETE FROM categories
                    WHERE id = ? AND user_id = ?
                ");
                $del->bind_param("ii", $cat_id, $user_id);

                if ($del->execute()) {
                    $success = "Category deleted permanently.";
                } else {
                    $errors[] = "Failed to delete category.";
                }
            }
        }
    }
}
}

/* =======================
   FETCH CATEGORIES
======================= */
$stmt = $conn->prepare("
    SELECT id, name, type, is_active
    FROM categories
    WHERE user_id = ?
      AND name <> 'Deleted Category'
    ORDER BY name ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$pageTitle = "Manage Categories Income, Expense & Saving";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>



    <header style="margin-bottom: 2rem;">
        <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Manage Categories</h1>
        <p class="text-muted" style="font-size: 0.875rem;">Organize your income and expense categories for better tracking.</p>
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

    <section class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.125rem; margin: 0;">Your Categories</h2>
            <a href="add_category.php" class="btn btn-primary btn-sm">+ Add New Category</a>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Category Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($res->num_rows === 0): ?>
                        <tr>
                            <td colspan="4" style="text-align: center;" class="text-dim">No categories found.</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($cat = $res->fetch_assoc()): ?>
                            <tr data-id="<?= $cat['id'] ?>"
                                data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                                data-type="<?= $cat['type'] ?>">
                                
                                <td style="font-weight: 600;"><?= htmlspecialchars($cat['name']) ?></td>
                                <td>
                                    <span class="badge <?= $cat['type'] === 'income' ? 'badge-success' : ($cat['type'] === 'saving' ? 'badge-warning' : 'badge-danger') ?>">
                                        <?= ucfirst($cat['type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div class="toggle-track" style="cursor: pointer; width: 36px; height: 18px; position: relative; background: <?= $cat['is_active'] ? 'var(--primary)' : 'var(--secondary)' ?>; border-radius: 999px; transition: var(--transition);">
                                            <input type="checkbox" class="toggleCat" 
                                                   data-id="<?= $cat['id'] ?>" 
                                                   <?= $cat['is_active'] ? 'checked' : '' ?>
                                                   style="position: absolute; inset: 0; opacity: 0; cursor: pointer; z-index: 2;">
                                            <div class="toggle-knob" style="position: absolute; top: 2px; left: <?= $cat['is_active'] ? '20px' : '2px' ?>; width: 14px; height: 14px; background: white; border-radius: 50%; transition: var(--transition);"></div>
                                        </div>
                                        <span class="toggle-label" style="font-size: 0.75rem; color: var(--text-muted);"><?= $cat['is_active'] ? 'Active' : 'Inactive' ?></span>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                                        <button class="btn btn-secondary btn-sm btn-edit-cat">Edit</button>
                                        <button class="btn btn-danger btn-sm btn-delete-cat"
                                                data-id="<?= $cat['id'] ?>"
                                                data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                                                <?= in_array($cat['name'], $protectedCategories) ? 'disabled' : '' ?>>
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<!-- EDIT MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <h3 style="margin-bottom: 1.5rem;">Edit Category</h3>
        
        <form method="POST" novalidate>
            <input type="hidden" name="form_type" value="edit">
            <input type="hidden" name="edit_id" id="edit_id">
            
            <div class="form-group">
                <label class="form-label">Category Name</label>
                <input type="text" name="edit_name" id="edit_name" class="form-control">
            </div>

            <div class="form-group">
                <label class="form-label">Type</label>
                <select name="edit_type" id="edit_type" class="form-control">
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                    <option value="saving">Saving</option>
                </select>
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
        <h3 style="margin-bottom: 1rem;">Delete Category?</h3>
        <p id="del_category_name" class="text-muted" style="font-size: 0.875rem; margin-bottom: 2.5rem;"></p>
        
        <form method="POST">
            <input type="hidden" name="form_type" value="delete">
            <input type="hidden" name="delete_id" id="delete_id">
            
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">Delete Permanently</button>
                <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>

<!-- =======================
     JS
======================= -->
<script>
// toggle functionality
document.querySelectorAll('.toggleCat').forEach(t=>{
    t.onchange=()=>{
        const track = t.closest('.toggle-track');
        const knob = track.querySelector('.toggle-knob');
        const label = t.closest('div').querySelector('.toggle-label');
        const isActive = t.checked;

        // Visual feedback
        track.style.background = isActive ? 'var(--primary)' : 'var(--secondary)';
        knob.style.left = isActive ? '20px' : '2px';
        if(label) label.innerText = isActive ? 'Active' : 'Inactive';

        // Async update
        fetch("",{
            method:"POST",
            headers:{ "Content-Type":"application/x-www-form-urlencoded" },
            body:`toggle_id=${t.dataset.id}&new_status=${isActive?1:0}`
        });
    }
});

// edit
const editModal=document.getElementById('editModal');
function closeEditModal(){ editModal.style.display='none'; }

document.querySelectorAll('.btn-edit-cat').forEach(btn=>{
    btn.onclick=()=>{
        const row=btn.closest('tr');
        edit_id.value=row.dataset.id;
        edit_name.value=row.dataset.name;
        edit_type.value=row.dataset.type;
        editModal.style.display='flex';
    };
});

// delete
const deleteModal=document.getElementById('deleteModal');
function closeDeleteModal(){ deleteModal.style.display='none'; }

document.querySelectorAll('.btn-delete-cat').forEach(btn=>{
    btn.onclick=()=>{
        delete_id.value=btn.dataset.id;
        del_category_name.innerText=`Delete "${btn.dataset.name}" permanently?`;
        deleteModal.style.display='flex';
    };
});
</script>

