<?php
/* =====================================================
   admin/security.php
   PHASE 6 - SECURITY MONITORING
===================================================== */
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/* =====================================================
   QUICK COUNTS
===================================================== */
$totalFailed = 0;
$totalInactive = 0;

$r = $conn->query("
SELECT COUNT(*) total
FROM system_logs
WHERE action LIKE '%failed login%'
");
if ($r) $totalFailed = $r->fetch_assoc()['total'] ?? 0;

$r = $conn->query("
SELECT COUNT(*) total
FROM users
WHERE status='inactive'
");
if ($r) $totalInactive = $r->fetch_assoc()['total'] ?? 0;


/* =====================================================
   RECENT FAILED LOGINS
===================================================== */
$failedLogs = [];

$q = $conn->query("
SELECT id, user_id, action, created_at
FROM system_logs
WHERE action LIKE '%failed login%'
ORDER BY id DESC
LIMIT 10
");

if ($q) {
    while ($row = $q->fetch_assoc()) {
        $failedLogs[] = $row;
    }
}

/* =====================================================
   LOCKED / INACTIVE USERS
===================================================== */
$userList = [];

$q = $conn->query("
SELECT id, name, email, status, created_at
FROM users
WHERE status = 'inactive'
ORDER BY id DESC
LIMIT 20
");


if ($q) {
    while ($row = $q->fetch_assoc()) {
        $userList[] = $row;
    }
}

/* =====================================================
   SUSPICIOUS ACTIVITIES
===================================================== */

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Monitoring - AMYFI</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            background: rgba(30, 41, 59, 0.7);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--danger));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card.suspicious::before {
            background: linear-gradient(90deg, var(--warning), var(--danger));
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card .icon {
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .stat-card.suspicious .icon {
            color: var(--warning);
        }

        .stat-card h3 {
            margin: 0;
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .stat-card h2 {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.2;
        }

        .table-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .table-section h3 {
            margin: 0 0 1.5rem 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .table-section h3 svg {
            color: var(--primary);
        }

        .table-container {
            overflow-x: auto;
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
            vertical-align: middle;
        }

        tbody tr {
            transition: var(--transition);
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
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

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-muted {
            background: rgba(148, 163, 184, 0.15);
            color: var(--text-muted);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .id-text {
            color: var(--text-muted);
            font-weight: 500;
        }

        .date-text {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

    </style>
</head>
<body>

    <header class="header">
        <div class="header-title">
            <h2>Security Monitoring</h2>
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
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <h3>Failed Login Attempts</h3>
                <h2><?= number_format($totalFailed) ?></h2>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                </div>
                <h3>Inactive Users</h3>
                <h2><?= number_format($totalInactive) ?></h2>
            </div>

        </div>

        <!-- FAILED LOGIN TABLE -->
        <section class="table-section">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Recent Failed Logins
            </h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 10%;">Log ID</th>
                            <th style="width: 20%;">Associated User</th>
                            <th style="width: 40%;">Security Event</th>
                            <th style="width: 30%;">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($failedLogs) > 0): ?>
                            <?php foreach($failedLogs as $row): ?>
                                <tr>
                                    <td class="id-text">#<?= $row['id'] ?></td>
                                    <td>
                                        <span class="badge badge-muted">User ID: <?= $row['user_id'] ?: 'Unknown' ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-danger"><?= htmlspecialchars($row['action']) ?></span>
                                    </td>
                                    <td class="date-text"><?= date('d M Y, H:i:s', strtotime($row['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                    No records of failed login attempts.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- LOCKED USERS -->
        <section class="table-section">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Inactive Account Monitoring
            </h3>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 10%;">ID</th>
                            <th style="width: 30%;">User Profile</th>
                            <th style="width: 20%;">Security Status</th>
                            <th style="width: 20%;">Join Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($userList) > 0): ?>
                            <?php foreach($userList as $row): ?>
                                <tr>
                                    <td class="id-text">#<?= $row['id'] ?></td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($row['name']) ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($row['email']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-muted">Inactive Access</span>
                                    </td>

                                    <td class="date-text"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                    No inactive users found.

                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>



    </main>

</body>
</html>