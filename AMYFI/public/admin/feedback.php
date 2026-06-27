<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

/* =============================
   REPLY FEEDBACK
============================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_feedback'])) {
    $feedbackId = intval($_POST['feedback_id']);
    $reply      = trim($_POST['admin_reply']);

    if ($reply !== '') {
        $stmt = $conn->prepare("
            UPDATE feedbacks
            SET admin_reply=?, replied_at=NOW()
            WHERE id=?
        ");
        $stmt->bind_param("si", $reply, $feedbackId);
        $stmt->execute();
        header("Location: feedback.php?success=1");
        exit;
    }
}

/* =============================
   GET ALL FEEDBACK
============================= */
$result = $conn->query("
    SELECT * FROM feedbacks
    ORDER BY created_at DESC
");

$adminName = $_SESSION['admin_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Feedback - AMYFI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #334155;
            --success: #10b981;
            --radius: 16px;
            --transition: all 0.3s ease;
        }

        body {
            margin: 0;
            font-family: 'Outfit', sans-serif;
            background: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
        }

        .header {
            padding: 1.5rem 3rem;
            background: rgba(17, 24, 39, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-title h2 { margin: 0; font-size: 1.5rem; font-weight: 800; }
        
        .btn-back {
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .btn-back:hover { color: var(--primary); }

        .container {
            max-width: 900px;
            margin: 3rem auto;
            padding: 0 20px;
        }

        .feedback-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: var(--transition);
        }

        .feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .user-info b { font-size: 1.1rem; color: #fff; }
        .rating { color: #facc15; font-size: 0.9rem; margin-top: 4px; }

        .message {
            color: var(--text-muted);
            line-height: 1.6;
            margin: 1rem 0;
            font-size: 0.95rem;
        }

        .admin-reply-box {
            background: rgba(16, 185, 129, 0.08);
            border-left: 3px solid var(--success);
            padding: 1rem;
            border-radius: 12px;
            margin-top: 1rem;
        }

        .reply-form textarea {
            width: 100%;
            padding: 12px;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: #fff;
            font-family: inherit;
            margin-top: 10px;
            box-sizing: border-box;
        }

        .btn-reply {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: var(--transition);
        }

        .btn-reply:hover { background: var(--primary-hover); transform: translateY(-2px); }


    </style>
</head>
<body>

    <header class="header">
        <div class="header-title">
            <h2>Manage Feedback</h2>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-back">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Dashboard
            </a>
        </div>
    </header>

    <main class="container">
        
        <?php if($result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): ?>
                <div class="feedback-card">
                    <div class="feedback-header">
                        <div class="user-info">
                            <b><?= htmlspecialchars($row['name']) ?></b>
                            <div class="rating"><?= str_repeat("⭐", $row['rating']) ?></div>
                        </div>

                    </div>

                    <div class="message">
                        <?= nl2br(htmlspecialchars($row['message'])) ?>
                    </div>

                    <?php if($row['admin_reply']): ?>
                        <div class="admin-reply-box">
                            <b style="color: var(--success); font-size: 0.8rem; text-transform: uppercase;">Admin Response</b>
                            <p style="margin: 5px 0 0 0; color: #fff; font-size: 0.9rem; font-style: italic;">"<?= htmlspecialchars($row['admin_reply']) ?>"</p>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="reply-form">
                            <input type="hidden" name="feedback_id" value="<?= $row['id'] ?>">
                            <textarea name="admin_reply" rows="3" placeholder="Type your public response here..."></textarea>
                            <button type="submit" name="reply_feedback" class="btn-reply">Post Reply</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 50px; color: var(--text-muted);">
                <div style="font-size: 3rem; margin-bottom: 20px;">💬</div>
                <h3>No feedback yet</h3>
                <p>Public testimonials will appear here once users submit them.</p>
            </div>
        <?php endif; ?>

    </main>

</body>
</html>
