<?php
/* =====================================================
   admin/dashboard.php
===================================================== */
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$adminName = $_SESSION['admin_name'];



/* =====================================================
   QUICK STATS
===================================================== */
$totalUsers = 0;
$activeUsers = 0;

$r = $conn->query("SELECT COUNT(*) total FROM users");
$totalUsers = $r->fetch_assoc()['total'] ?? 0;

$r = $conn->query("SELECT COUNT(*) total FROM users WHERE status='active'");
$activeUsers = $r->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - AMYFI</title>
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

        .header-actions .btn-logout {
            padding: 0.65rem 1.5rem;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .header-actions .btn-logout:hover {
            background: var(--danger);
            color: #fff;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
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

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3.5rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
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
            background: linear-gradient(90deg, var(--primary), var(--accent));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .stat-value {
            font-size: 2.75rem;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            line-height: 1;
            letter-spacing: -1px;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2.5rem;
        }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .link-card {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2.5rem 1.5rem;
            background: var(--bg-card-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            text-decoration: none;
            color: var(--text-main);
            font-weight: 600;
            font-size: 1rem;
            transition: var(--transition);
            text-align: center;
            gap: 1.25rem;
        }

        .link-card:hover {
            background: var(--bg-card);
            border-color: var(--primary);
            transform: scale(1.02);
            color: var(--primary);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }

        .link-card svg {
            width: 38px;
            height: 38px;
            stroke: var(--text-muted);
            stroke-width: 1.5;
            transition: var(--transition);
        }

        .link-card:hover svg {
            stroke: var(--primary);
            transform: translateY(-4px);
        }


    </style>
</head>
<body>

    <header class="header">
        <div class="header-title">
            <h2>Admin Hub</h2>
            <p>Ready for oversight, <?= htmlspecialchars($adminName) ?></p>
        </div>
        <div class="header-actions">
            <a href="logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <main class="container">
        
        <div class="section-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary)"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            Overall Systems Pulse
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Total Users
                </div>
                <h2 class="stat-value"><?= number_format($totalUsers) ?></h2>
            </div>
            

            
            <div class="stat-card">
                <div class="stat-label">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Total User Active
                </div>
                <h2 class="stat-value"><?= number_format($activeUsers) ?></h2>
            </div>
        </div>

        <div class="main-grid">
            
            <!-- Quick Links -->
            <div>
                <div class="section-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent)"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Administrative Panel
                </div>
                
                <div class="quick-links">
                    <a href="users.php" class="link-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Manage Users
                    </a>
                    <a href="reports.php" class="link-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        Analytics & Reports
                    </a>
                    <a href="customer_support.php" class="link-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Support Center
                    </a>
                    <a href="feedback.php" class="link-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Manage Feedback
                    </a>
                    <a href="security.php" class="link-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Security Logs
                    </a>
                </div>
            </div>


        </div>
    </main>

</body>
</html>