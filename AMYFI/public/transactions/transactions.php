<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

$stmtStatus = $conn->prepare("SELECT status FROM users WHERE id = ?");
$stmtStatus->bind_param("i", $user_id);
$stmtStatus->execute();
$rowStatus = $stmtStatus->get_result()->fetch_assoc();

if ($rowStatus['status'] !== 'active') {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

/* =========================
   AUTO DELETE OLD SOFT DELETES
   ========================= */
$cleanup = $conn->prepare("
    DELETE FROM transactions
    WHERE user_id = ?
      AND is_deleted = 1
      AND transaction_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$cleanup->bind_param("i", $user_id);
$cleanup->execute();

/* =========================
   HANDLE EDIT / DELETE
   ========================= */
$errors = [];
$successMessage = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // UPDATE
    if ($_POST['action'] === 'update') {

        $id             = intval($_POST['id']);
        $amount         = floatval($_POST['amount']);
        $category_id    = intval($_POST['category_id']);
        $payment_method = $_POST['payment_method'];
        $note           = trim($_POST['note']);
        $date           = $_POST['transaction_date'];

        if ($amount <= 0) {
            $errors[] = "Amount must be greater than 0.";
        }

        if (empty($errors)) {
            // STEP 1: ambil old data
            $stmt = $conn->prepare("SELECT amount, category_id, type FROM transactions WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $id, $user_id);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();

            if ($old) {
                $old_amount = $old['amount'];
                $old_cat = $old['category_id'];
                $type = $old['type'];

                if ($type == 'saving') {
                    // STEP 2: tolak old
                    $stmt = $conn->prepare("
                        UPDATE savings_goals sg
                        JOIN categories c ON c.id = ?
                        SET sg.current_amount = sg.current_amount - ?
                        WHERE sg.name = c.name AND sg.user_id = ?
                    ");
                    $stmt->bind_param("idi", $old_cat, $old_amount, $user_id);
                    $stmt->execute();

                    // STEP 3: tambah new
                    $stmt = $conn->prepare("
                        UPDATE savings_goals sg
                        JOIN categories c ON c.id = ?
                        SET sg.current_amount = sg.current_amount + ?
                        WHERE sg.name = c.name AND sg.user_id = ?
                    ");
                    $stmt->bind_param("idi", $category_id, $amount, $user_id);
                    $stmt->execute();
                }

                $stmt = $conn->prepare("
                    UPDATE transactions SET
                        amount = ?,
                        category_id = ?,
                        payment_method = ?,
                        note = ?,
                        transaction_date = ?
                    WHERE id = ?
                      AND user_id = ?
                ");
                $stmt->bind_param(
                    "disssii",
                    $amount,
                    $category_id,
                    $payment_method,
                    $note,
                    $date,
                    $id,
                    $user_id
                );

                if ($stmt->execute()) {
                    header("Location: transactions.php?updated=1");
                    exit;
                }
            }
        }
    }

    // DELETE
    if ($_POST['action'] === 'delete') {
        $id = intval($_POST['id']);

        // STEP 1: ambil data dulu
        $stmt = $conn->prepare("SELECT amount, category_id, type FROM transactions WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();

        if ($data) {
            $amount = $data['amount'];
            $category_id = $data['category_id'];
            $type = $data['type'];

            // STEP 2: update saving
            if ($type == 'saving') {
                $stmt = $conn->prepare("
                    UPDATE savings_goals sg
                    JOIN categories c ON c.id = ?
                    SET sg.current_amount = sg.current_amount - ?
                    WHERE sg.name = c.name AND sg.user_id = ?
                ");
                $stmt->bind_param("idi", $category_id, $amount, $user_id);
                $stmt->execute();
            }

            // STEP 3: baru delete
            $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $id, $user_id);
            $stmt->execute();
        }

        header("Location: transactions.php?deleted=1");
        exit;
    }
}

if (isset($_GET['updated'])) $successMessage = "Expense updated successfully ";
if (isset($_GET['deleted'])) $successMessage = "Expense deleted ";

/* =========================
   FILTERS
   ========================= */
$selectedMonth    = $_GET['month'] ?? date('Y-m');
$selectedCategory = $_GET['category'] ?? '';
$searchQuery      = $_GET['search'] ?? '';

$startDate = $selectedMonth . "-01";
$endDate   = date("Y-m-t", strtotime($startDate));

/* =========================
   FETCH CATEGORIES
   ========================= */
$catStmt = $conn->prepare("
    SELECT id, name FROM categories
    WHERE (user_id = ? OR user_id IS NULL)
      AND type IN ('expense','saving')
      AND is_active = 1
    ORDER BY name ASC
");
$catStmt->bind_param("i", $user_id);
$catStmt->execute();
$categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================
   PAGINATION
   ========================= */
$limit  = 8;
$page   = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

/* =========================
   FETCH EXPENSE TRANSACTIONS
   ========================= */
$sql = "
    SELECT t.*, c.name AS category_name, c.is_active AS category_active
FROM transactions t
JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = ?
      AND t.type IN ('expense','saving')
      AND t.is_deleted = 0
      AND t.transaction_date BETWEEN ? AND ?
";

if ($selectedCategory) {
    $sql .= " AND t.category_id = " . intval($selectedCategory);
}

if ($searchQuery) {
    $sql .= " AND t.note LIKE ?";
}

$sql .= " ORDER BY t.transaction_date DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);

if ($searchQuery) {
    $like = "%$searchQuery%";
    $stmt->bind_param("isssii", $user_id, $startDate, $endDate, $like, $limit, $offset);
} else {
    $stmt->bind_param("issii", $user_id, $startDate, $endDate, $limit, $offset);
}

$stmt->execute();
$results = $stmt->get_result();

/* =========================
   COUNT FOR PAGINATION
   ========================= */
$countStmt = $conn->prepare("
    SELECT COUNT(*) total FROM transactions
    WHERE user_id = ?
      AND type IN ('expense','saving')
      AND is_deleted = 0
      AND transaction_date BETWEEN ? AND ?
");
$countStmt->bind_param("iss", $user_id, $startDate, $endDate);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

$pageTitle = "Expenses";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>



    <header style="margin-bottom: 2rem;">
        <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Expense Transactions</h1>
        <p class="text-muted" style="font-size: 0.875rem;">Track and manage your spending history for better financial control.</p>
    </header>

    <?php if ($successMessage): ?>
        <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); margin-bottom: 1.5rem; color: var(--success); font-weight: 500;">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <section class="card" style="margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <form method="GET" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" class="form-control" style="width: 160px; padding: 0.5rem 0.75rem;">
                <select name="category" class="form-control" style="width: 180px;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selectedCategory == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
                <a href="transactions.php" class="btn btn-secondary btn-sm">Reset</a>
            </form>
            <a href="add_transaction.php" class="btn btn-primary btn-sm">+ Add New Expense</a>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Amount (RM)</th>
                        <th>Payment</th>
                        <th>Note</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($results->num_rows === 0): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;" class="text-dim">No transactions found for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($row = $results->fetch_assoc()): ?>
                            <tr>
                                <td style="white-space: nowrap;"><?= date('d M Y', strtotime($row['transaction_date'])) ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($row['category_name']) ?></td>
                                <td>
                                    <span class="badge <?= $row['type'] === 'saving' ? 'badge-primary' : 'badge-danger' ?>">
                                        <?= ucfirst($row['type']) ?>
                                    </span>
                                </td>
                                <td style="font-weight: 700;">RM <?= number_format($row['amount'], 2) ?></td>
                                <td>
                                    <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: capitalize;">
                                        <?= str_replace('_', ' ', $row['payment_method']) ?>
                                    </span>
                                </td>
                                <td class="text-dim" style="font-size: 0.75rem; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= htmlspecialchars($row['note']) ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                                        <?php if ($row['category_active']): ?>
                                            <button class="btn btn-secondary btn-sm" onclick="openEditModal(this)"
                                                data-id="<?= $row['id'] ?>"
                                                data-date="<?= $row['transaction_date'] ?>"
                                                data-amount="<?= $row['amount'] ?>"
                                                data-category="<?= $row['category_id'] ?>"
                                                data-method="<?= $row['payment_method'] ?>"
                                                data-note="<?= htmlspecialchars($row['note']) ?>"
                                            >Edit</button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger btn-sm" onclick="openConfirmModal(<?= $row['id'] ?>)">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="margin-top: 1.5rem; display: flex; justify-content: center; gap: 0.5rem;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>&month=<?= $selectedMonth ?>&category=<?= $selectedCategory ?>&search=<?= urlencode($searchQuery) ?>" 
                       class="btn btn-sm <?= $page == $i ? 'btn-primary' : 'btn-secondary' ?>" style="min-width: 2.5rem;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>

<!-- EDIT MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <h3 style="margin-bottom: 1.5rem;">Edit Expense</h3>
        
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit-id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transaction_date" id="edit-date" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Amount (RM)</label>
                    <input type="number" step="0.01" name="amount" id="edit-amount" class="form-control">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" id="edit-category" class="form-control">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" id="edit-method" class="form-control">
                        <option value="Cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="e_wallet">e-wallet</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Note</label>
                <textarea name="note" id="edit-note" class="form-control" style="height: 80px; resize: none;"></textarea>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
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
        <h3 style="margin-bottom: 1rem;">Delete Expense?</h3>
        <p class="text-muted" style="font-size: 0.875rem; margin-bottom: 2rem;">This action cannot be undone. Are you sure you want to delete this record?</p>
        
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id">
            
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">Delete Record</button>
                <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(btn) {
        editModal.style.display = 'flex';
        document.getElementById('edit-id').value = btn.dataset.id;
        document.getElementById('edit-date').value = btn.dataset.date;
        document.getElementById('edit-amount').value = btn.dataset.amount;
        document.getElementById('edit-category').value = btn.dataset.category;
        document.getElementById('edit-method').value = btn.dataset.method;
        document.getElementById('edit-note').value = btn.dataset.note;
    }

    function closeEditModal() {
        editModal.style.display = 'none';
    }

    function openConfirmModal(id) {
        deleteModal.style.display = 'flex';
        document.getElementById('delete-id').value = id;
    }

    function closeDeleteModal() {
        deleteModal.style.display = 'none';
    }
</script>

<?php require_once '../../includes/footer.php'; ?>