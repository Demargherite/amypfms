<?php
/* =====================================================
   admin/users.php
===================================================== */
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

/* =====================================================
   HANDLE ACTIONS
===================================================== */
if (isset($_GET['action'], $_GET['id'])) {

    $userId  = (int) $_GET['id'];
    $action  = $_GET['action'];

    if ($userId > 0) {

        if ($action === 'activate') {
            $stmt = $conn->prepare("
                UPDATE users
                SET status = 'active'
                WHERE id = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
        }

        if ($action === 'deactivate') {
            $stmt = $conn->prepare("
                UPDATE users
                SET status = 'inactive'
                WHERE id = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
        }

        if ($action === 'delete') {

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            try {

                $conn->begin_transaction();

                /* transactions */
                $stmt = $conn->prepare("
                    DELETE FROM transactions
                    WHERE user_id = ?
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();

                /* recurring */
                $stmt = $conn->prepare("
                    DELETE FROM recurring_transactions
                    WHERE user_id = ?
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();

                /* budgets linked to categories */
                $stmt = $conn->prepare("
                    DELETE FROM budgets
                    WHERE user_id = ?
                       OR category_id IN (
                            SELECT id FROM categories WHERE user_id = ?
                       )
                ");
                $stmt->bind_param("ii", $userId, $userId);
                $stmt->execute();

                /* category keywords */
                $stmt = $conn->prepare("
                    DELETE FROM category_keywords
                    WHERE category_id IN (
                        SELECT id FROM categories WHERE user_id = ?
                    )
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();

                /* other user tables */
                $tables = [
                    'children',
                    'notification_settings',
                    'savings_goals',
                    'suspicious_activities',
                    'system_logs',
                    'user_settings'
                ];

                foreach ($tables as $table) {
                    $sql = "DELETE FROM {$table} WHERE user_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                }

                /* categories */
                $stmt = $conn->prepare("
                    DELETE FROM categories
                    WHERE user_id = ?
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();

                /* user */
                $stmt = $conn->prepare("
                    DELETE FROM users
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();

                $conn->commit();

            } catch (Exception $e) {

                $conn->rollback();
                die("DELETE ERROR: " . $e->getMessage());
            }
        }
    }

    header("Location: users.php");
    exit;
}

/* =====================================================
   SEARCH
===================================================== */
$search = trim($_GET['search'] ?? '');

$sql = "
SELECT 
    u.id,
    u.name,
    u.email,
    u.created_at,
    u.status,
    (
        SELECT COUNT(*)
        FROM transactions t
        WHERE t.user_id = u.id
    ) AS total_transactions
FROM users u
";

if ($search !== '') {
    $sql .= " WHERE u.name LIKE ? OR u.email LIKE ? ";
}

$sql .= " ORDER BY u.id DESC ";

$stmt = $conn->prepare($sql);

if ($search !== '') {
    $like = "%$search%";
    $stmt->bind_param("ss", $like, $like);
}

$stmt->execute();
$users = $stmt->get_result();

$adminName = $_SESSION['admin_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users - AMYFI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --bg-card-alt: #111827;
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --accent: #0ea5e9;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #334155;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --radius-lg: 20px;
            --radius-md: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            margin: 0;
            font-family: 'Outfit', sans-serif;
            background: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            padding: 1.5rem 3rem;
            background: rgba(17, 24, 39, 0.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-title h2 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #fff, var(--text-muted));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-title p {
            margin: 0.25rem 0 0 0;
            color: var(--text-muted);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .header-actions .btn-back {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.5rem;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .header-actions .btn-back:hover {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transform: translateY(-2px);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 2rem;
            width: 100%;
            box-sizing: border-box;
            flex: 1;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .search-input {
            flex: 1;
            max-width: 400px;
            padding: 0.85rem 1.25rem;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(15, 23, 42, 0.8);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        .btn-search {
            padding: 0.85rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: none;
            border-radius: var(--radius-md);
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-search:hover {
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
            transform: translateY(-2px);
        }

        .table-container {
            overflow-x: auto;
            padding: 1rem;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
        }

        th {
            padding: 1rem 1.25rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--border);
        }

        td {
            padding: 1.15rem 1.25rem;
            font-size: 0.95rem;
            color: var(--text-main);
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
            vertical-align: middle;
        }

        tbody tr {
            transition: var(--transition);
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
            border-radius: var(--radius-md);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-inactive {
            background: rgba(148, 163, 184, 0.15);
            color: var(--text-muted);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .action-links {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .btn-activate {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .btn-activate:hover {
            background: var(--success);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
        }

        .btn-deactivate {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.2);
        }

        .btn-deactivate:hover {
            background: var(--warning);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
        }

        .btn-delete:hover {
            background: var(--danger);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);
        }

        /* MODAL STYLES */
        .modal{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.65);
            z-index:9999;
            justify-content:center;
            align-items:center;
        }

        .modal-content{
            background:#0f172a;
            padding:35px;
            border-radius:20px;
            width:95%;
            border:1px solid #334155;
            animation:popup .25s ease;
        }

        @keyframes popup{
            from{
                opacity:0;
                transform:scale(.9);
            }
            to{
                opacity:1;
                transform:scale(1);
            }
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="header-title">
            <h2>User Management</h2>
            <p>Admin: <?= htmlspecialchars($adminName) ?></p>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-back">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Dashboard
            </a>
        </div>
    </header>

    <main class="container">
        
        <div class="card">
            <form method="GET" class="search-form">
                <input type="text" name="search" class="search-input" placeholder="Search by name or email address..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn-search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Search Users
                </button>
            </form>
        </div>

        <div class="card table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Joined</th>
                        <th>Status</th>
                        <th style="text-align: center;">Transactions</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users->num_rows > 0): ?>
                        <?php while($row = $users->fetch_assoc()): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-weight: 500;">#<?= $row['id'] ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($row['name']) ?></td>
                                <td style="color: var(--text-muted);"><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;"><?= $row['total_transactions'] ?></td>
                                <td>
                                    <div class="action-links">
                                        <?php if($row['status'] === 'active'): ?>
                                            <a class="btn-action btn-deactivate"
                                               href="javascript:void(0)"
                                               onclick="openStatusModal('deactivate', <?= $row['id'] ?>)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                                Deactivate
                                            </a>
                                        <?php else: ?>
                                            <a class="btn-action btn-activate"
                                               href="javascript:void(0)"
                                               onclick="openStatusModal('activate', <?= $row['id'] ?>)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                                Activate
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a class="btn-action btn-delete" href="javascript:void(0)" onclick="openDeleteModal(<?= $row['id'] ?>)">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                            Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                No users found in the database.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- DELETE USER MODAL -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width:420px; text-align:center;">
            <h3 style="margin-bottom:1rem; font-size:28px;">Delete User?</h3>
            <p style="color:#94a3b8; font-size:15px; margin-bottom:2rem; line-height:1.6;">
                This action cannot be undone. Are you sure you want to delete this user?
            </p>
            <div style="display:flex; gap:1rem;">
                <a id="confirmDeleteBtn" href="#" class="btn-action btn-delete" style="flex:1; justify-content:center; padding:14px;">
                    Delete User
                </a>
                <button onclick="closeDeleteModal()" style="flex:1; border:none; border-radius:10px; background:#1e293b; color:white; font-weight:600; cursor:pointer;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- STATUS MODAL -->
    <div id="statusModal" class="modal">
        <div class="modal-content" style="max-width:420px; text-align:center;">

            <h3 id="statusTitle"
                style="margin-bottom:1rem; font-size:28px;">
            </h3>

            <p id="statusText"
               style="color:#94a3b8; font-size:15px; margin-bottom:2rem; line-height:1.6;">
            </p>

            <div style="display:flex; gap:1rem;">

                <a id="confirmStatusBtn"
                   href="#"
                   class="btn-action"
                   style="flex:1; justify-content:center; padding:14px;">
                    Confirm
                </a>

                <button onclick="closeStatusModal()"
                    style="
                        flex:1;
                        border:none;
                        border-radius:10px;
                        background:#1e293b;
                        color:white;
                        font-weight:600;
                        cursor:pointer;
                    ">
                    Cancel
                </button>

            </div>
        </div>
    </div>

    <script>
    function openDeleteModal(id){
        document.getElementById("deleteModal").style.display="flex";
        document.getElementById("confirmDeleteBtn").href = "?action=delete&id=" + id;
    }

    function closeDeleteModal(){
        document.getElementById("deleteModal").style.display="none";
    }

    function openStatusModal(action, id){

        const modal = document.getElementById("statusModal");
        const title = document.getElementById("statusTitle");
        const text  = document.getElementById("statusText");
        const btn   = document.getElementById("confirmStatusBtn");

        modal.style.display = "flex";

        if(action === "deactivate"){

            title.innerHTML = "Deactivate User?";
            text.innerHTML  = "This user will no longer be able to access the system.";
            btn.innerHTML   = "Deactivate";
            btn.className   = "btn-action btn-deactivate";
        }
        else{

            title.innerHTML = "Activate User?";
            text.innerHTML  = "This user will regain access to the system.";
            btn.innerHTML   = "Activate";
            btn.className   = "btn-action btn-activate";
        }

        btn.href = "?action=" + action + "&id=" + id;
    }

    function closeStatusModal(){
        document.getElementById("statusModal").style.display = "none";
    }
    </script>
</body>
</html>