<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../../config/db.php';

$userId = $_SESSION['user_id'];

$stmtSeen = $conn->prepare("
    UPDATE customer_support
    SET user_seen = 1
    WHERE user_id = ?
    AND status = 'resolved'
");

$stmtSeen->bind_param("i", $userId);
$stmtSeen->execute();


// Get support history for this user
$stmt = $conn->prepare("
    SELECT id, subject, message, status, created_at
    FROM customer_support
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$pageTitle = "My Support Requests";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div class="support-page-container">
    <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="margin-bottom: 0.25rem; font-size: 2rem;">Support History</h1>
            <p class="text-muted" style="margin: 0; font-size: 0.875rem; font-weight: 500;">
                Track the status of your support requests and inquiries.
            </p>
        </div>
        <div class="card" style="padding: 0.75rem 1.25rem;">
            <button onclick="openSupportModal()" class="btn btn-primary" style="padding: 0.65rem 1.25rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 0.25rem;">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                New Request
            </button>
        </div>
    </header>

    <div class="card table-container">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="width: 15%;">Date</th>
                    <th style="width: 20%;">Subject</th>
                    <th style="width: 45%;">Message</th>
                    <th style="width: 20%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td style="color: var(--text-dim); font-size: 0.85rem;">
                                <?= date('d M Y', strtotime($row['created_at'])) ?>
                            </td>
                            <td style="font-weight: 600; color: var(--text-main);">
                                <?= htmlspecialchars($row['subject']) ?>
                            </td>
                            <td>
                                <div style="max-width: 400px; color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($row['message']) ?>">
                                    <?= htmlspecialchars($row['message']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if($row['status'] == 'resolved'): ?>
                                    <span class="badge badge-success">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                                            <polyline points="20 6 9 17 4 12"></polyline>
                                        </svg>
                                        Resolved
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <line x1="12" y1="8" x2="12" y2="12"></line>
                                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                        </svg>
                                        Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 4rem 2rem;">
                            <div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.2;">📩</div>
                            <h3 style="color: var(--text-muted); margin-bottom: 0.5rem;">No support requests yet</h3>
                            <p style="color: var(--text-dim); font-size: 0.875rem;">Need help? Our team is here to assist you with any issues.</p>
                            <button onclick="openSupportModal()" class="btn btn-outline" style="margin-top: 1.5rem;">Contact Support</button>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .support-page-container {
        animation: fadeIn 0.4s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.4rem 0.85rem;
        border-radius: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        font-size: 0.7rem;
    }

    .badge-success {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .badge-warning {
        background: rgba(245, 158, 11, 0.1);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.2);
    }
    
    .table-container tr:hover td {
        background: rgba(255, 255, 255, 0.03);
        cursor: default;
    }
</style>

<?php require_once '../../includes/footer.php'; ?>
