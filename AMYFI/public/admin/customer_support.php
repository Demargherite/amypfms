<?php
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require '../../PHPMailer/src/PHPMailer.php';
require '../../PHPMailer/src/SMTP.php';
require '../../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ===============================
   DELETE SUPPORT REQUEST
 ================================= */
if (isset($_GET['delete'])) {

    $id = (int) $_GET['delete'];

    $stmt = $conn->prepare("
        DELETE FROM customer_support
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: customer_support.php");
    exit;
}

/* ===============================
   MARK RESOLVED
 ================================= */
if (isset($_GET['resolve'])) {

    $id = (int) $_GET['resolve'];

    // Get support details to send email
    $stmt = $conn->prepare("SELECT email, subject, name FROM customer_support WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $support = $stmt->get_result()->fetch_assoc();

    if ($support) {
        $userEmail = $support['email'];
        $userSubject = $support['subject'];
        $userName = $support['name'];

        // Update status
        $stmt = $conn->prepare("
            UPDATE customer_support
            SET status = 'resolved'
            WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // Send Email Notification
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'amyqmy12@gmail.com';
            $mail->Password = 'ovjjhtrtrqfoessg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom('amyqmy12@gmail.com', 'AMYFI Support');
            $mail->addAddress($userEmail);

            $mail->isHTML(true);
            $mail->Subject = "Issue Resolved: " . $userSubject;
            $mail->Body = "
                <div style='font-family: \"Outfit\", sans-serif; max-width: 600px; margin: 0 auto; padding: 30px; background: #0f172a; color: #f8fafc; border-radius: 20px; border: 1px solid #334155;'>
                    <div style='text-align: center; margin-bottom: 30px;'>
                        <div style='display: inline-block; padding: 15px; background: #6366f1; border-radius: 12px; color: white; font-weight: 800; font-size: 20px;'>AF</div>
                        <h2 style='color: #fff; margin-top: 20px;'>Issue Resolved</h2>
                    </div>
                    <p>Hello <strong>$userName</strong>,</p>
                    <p>We are pleased to inform you that your support request regarding <strong>\"$userSubject\"</strong> has been reviewed and resolved by our team.</p>
                    <div style='margin: 30px 0; padding: 20px; background: rgba(16, 185, 129, 0.1); border-left: 4px solid #10b981; border-radius: 8px;'>
                        <p style='margin: 0; color: #10b981; font-weight: 600;'>Status: RESOLVED ✅</p>
                    </div>
                    <p>Thank you for reaching out to AMYFI Support. If you have any further questions, feel free to submit another request or contact us directly.</p>
                    <div style='margin-top: 40px; padding-top: 20px; border-top: 1px solid #334155; font-size: 12px; color: #94a3b8; text-align: center;'>
                        &copy; " . date('Y') . " AMYFI Finance Management System. All rights reserved.
                    </div>
                </div>
            ";

            $mail->send();
        } catch (Exception $e) {
            // Log error or handle silently for now to avoid breaking the flow
        }
    }

    header("Location: customer_support.php");
    exit;
}

/* ===============================
   SEARCH
 ================================= */
$search = trim($_GET['search'] ?? '');

$sql = "
SELECT *
FROM customer_support
";

if ($search !== '') {
    $sql .= " WHERE name LIKE ? 
              OR email LIKE ?
              OR subject LIKE ?
              OR message LIKE ? ";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);

if ($search !== '') {
    $like = "%$search%";
    $stmt->bind_param("ssss", $like, $like, $like, $like);
}

$stmt->execute();
$result = $stmt->get_result();

$adminName = $_SESSION['admin_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support Center - AMYFI</title>
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
            --warning: #f59e0b;
            --success: #10b981;
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
            padding: 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--border);
        }

        td {
            padding: 1.15rem 1rem;
            font-size: 0.95rem;
            color: var(--text-main);
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
            vertical-align: top;
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

        .badge-resolved {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-pending {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .action-links {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: 1px solid transparent;
            width: fit-content;
        }

        .btn-resolve {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .btn-resolve:hover {
            background: var(--success);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
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

        .message-box {
            background: rgba(15, 23, 42, 0.6);
            padding: 0.85rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-size: 0.9rem;
            color: #cbd5e1;
            line-height: 1.5;
            max-width: 320px;
        }

        /* MODAL STYLES */
        .modal{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.65);
            z-index:9999;
            justify-content:center;
            align-items:center;
        }

        .modal-content{
            background:#0f172a;
            padding:35px;
            border-radius:20px;
            width:95%;
            max-width:420px;
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
            <h2>Support Center</h2>
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
                <input type="text" name="search" class="search-input" placeholder="Search support requests..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn-search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Search Requests
                </button>
            </form>
        </div>

        <div class="card table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 15%;">User</th>
                        <th style="width: 15%;">Subject</th>
                        <th style="width: 35%;">Message</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 10%;">Date</th>
                        <th style="width: 10%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-weight: 500;">
                                    #<?= $row['id'] ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-main); margin-bottom: 0.25rem;">
                                        <?= htmlspecialchars($row['name']) ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?= htmlspecialchars($row['email']) ?>
                                    </div>
                                </td>
                                <td style="font-weight: 500;">
                                    <?= htmlspecialchars($row['subject']) ?>
                                </td>
                                <td>
                                    <div class="message-box">
                                        <?= nl2br(htmlspecialchars($row['message'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'resolved'): ?>
                                        <span class="badge badge-resolved">Resolved</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.85rem;">
                                    <?= date('d M Y', strtotime($row['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <?php if($row['status'] !== 'resolved'): ?>
                                            <a class="btn-action btn-resolve" href="?resolve=<?= $row['id'] ?>">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                                Resolve
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
                            <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                No support requests found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- DELETE SUPPORT MODAL -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="text-align:center;">
            <h3 style="margin-bottom:1rem; font-size:28px;">Delete Request?</h3>
            <p style="color:#94a3b8; font-size:15px; line-height:1.6; margin-bottom:2rem;">
                This action cannot be undone. Are you sure you want to delete this support request?
            </p>
            <div style="display:flex; gap:1rem;">
                <a id="confirmDeleteBtn" href="#" class="btn-action btn-delete" style="flex:1; justify-content:center; padding:14px;">
                    Delete
                </a>
                <button onclick="closeDeleteModal()" style="flex:1; border:none; border-radius:10px; background:#1e293b; color:white; font-weight:600; cursor:pointer;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <script>
    function openDeleteModal(id){
        document.getElementById("deleteModal").style.display="flex";
        document.getElementById("confirmDeleteBtn").href = "customer_support.php?delete=" + id;
    }

    function closeDeleteModal(){
        document.getElementById("deleteModal").style.display="none";
    }
    </script>
</body>
</html>