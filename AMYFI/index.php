<?php
require_once 'config/db.php';
/** @var mysqli $conn */

/* GET APPROVED PUBLIC FEEDBACK */
$feedbacks = $conn->query("
    SELECT * FROM feedbacks
    ORDER BY created_at DESC
    LIMIT 6
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AMYFI - Smart Financial Management</title>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --bg-dark: #0f172a;
    --bg-card: #1e293b;
    --primary: #6366f1;
    --primary-hover: #4f46e5;
    --accent: #0ea5e9;
    --text-main: #f8fafc;
    --text-muted: #94a3b8;
    --border: #334155;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

body {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    background: var(--bg-dark);
    color: var(--text-main);
    line-height: 1.6;
    overflow-x: hidden;
}

/* HERO */
.hero {
    padding: 140px 20px;
    text-align: center;
    background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.15), transparent),
                radial-gradient(circle at bottom left, rgba(14, 165, 233, 0.1), transparent),
                var(--bg-dark);
    position: relative;
}

.hero h1 {
    font-size: clamp(2.5rem, 8vw, 4.5rem);
    font-weight: 800;
    margin: 0;
    letter-spacing: -2px;
    background: linear-gradient(135deg, #fff 0%, var(--text-muted) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    line-height: 1.1;
}

.hero p {
    color: var(--text-muted);
    max-width: 650px;
    margin: 25px auto;
    font-size: 1.125rem;
    font-weight: 400;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: 30px;
    padding: 16px 36px;
    background: linear-gradient(135deg, var(--primary), var(--primary-hover));
    color: white;
    text-decoration: none;
    border-radius: 14px;
    font-weight: 700;
    font-size: 1.05rem;
    transition: var(--transition);
    box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3);
}

.btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 35px rgba(99, 102, 241, 0.4);
}

/* FEATURES */
.features {
    padding: 100px 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.section-header {
    text-align: center;
    margin-bottom: 60px;
}

.section-header h2 {
    font-size: 2.5rem;
    font-weight: 800;
    margin-bottom: 15px;
    letter-spacing: -1px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
}

.card {
    background: rgba(30, 41, 59, 0.5);
    backdrop-filter: blur(10px);
    border: 1px solid var(--border);
    padding: 35px;
    border-radius: 24px;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.card:hover {
    transform: translateY(-10px);
    border-color: var(--primary);
    background: rgba(30, 41, 59, 0.8);
}

.card h3 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.card p {
    color: var(--text-muted);
    margin: 0;
    font-size: 0.95rem;
}

/* FEEDBACK */
.feedback {
    padding: 100px 20px;
    background: rgba(255, 255, 255, 0.02);
}

.feedback-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 25px;
    max-width: 1200px;
    margin: 40px auto 0;
}

.feedback-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    padding: 30px;
    border-radius: 24px;
    transition: var(--transition);
}

.feedback-card:hover {
    border-color: var(--accent);
}

.stars {
    color: #fbbf24;
    font-size: 1.25rem;
    margin-bottom: 15px;
}

.feedback-card p {
    font-style: italic;
    color: var(--text-main);
    margin-bottom: 20px;
    font-size: 1.05rem;
}

.feedback-card strong {
    display: block;
    color: var(--text-muted);
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.admin-reply {
    margin-top: 20px;
    padding: 15px;
    background: rgba(99, 102, 241, 0.08);
    border-left: 3px solid var(--primary);
    border-radius: 12px;
}

.admin-reply b {
    color: var(--primary);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: block;
    margin-bottom: 5px;
}

.admin-reply p {
    margin: 0;
    font-style: normal;
    font-size: 0.9rem;
    color: var(--text-muted);
}

/* FOOTER */
.footer {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
    border-top: 1px solid var(--border);
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .hero { padding: 100px 20px; }
    .section-header h2 { font-size: 2rem; }
}
</style>
</head>

<body>

<!-- HERO -->
<section class="hero">
    <h1>Smart Personal Finance <br>Management System</h1>
    <p>
        AMYFI helps you track expenses, manage budgets, and grow your savings with ease.
        Designed for students and families to take full control of their financial future.
    </p>

    <a href="public/auth/login.php" class="btn">
        <span>Get Started Now</span>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
</section>

<!-- FEATURES -->
<section class="features">
    <div class="section-header">
        <h2>What AMYFI Can Do</h2>
        <p style="color: var(--text-muted);">Empowering your financial journey with intelligent tools.</p>
    </div>

    <div class="grid">

        <div class="card">
            <h3>💰 Track Transactions</h3>
            <p>Easily record income and expenses in one place. Stay on top of every penny with real-time tracking.</p>
        </div>

        <div class="card">
            <h3>📊 Smart Reports</h3>
            <p>Visualize your spending patterns instantly with interactive charts and deep-dive analytics.</p>
        </div>

        <div class="card">
            <h3>🎯 Budget Planning</h3>
            <p>Set custom budgets for different categories and get notified before you overspend.</p>
        </div>

        <div class="card">
            <h3>💡 Smart Advice</h3>
            <p>Get personalized AI-driven insights and tips to improve your saving habits and financial health.</p>
        </div>

        <div class="card">
            <h3>🔁 Recurring Tracking</h3>
            <p>Never miss a bill again. Manage your subscriptions and recurring payments automatically.</p>
        </div>

        <div class="card">
            <h3>🛟 Customer Support</h3>
            <p>Experience peace of mind with our dedicated support center. We're here to help you succeed.</p>
        </div>

    </div>
</section>

<!-- FEEDBACK -->
<section class="feedback">
    <div class="section-header">
        <h2>What Our Users Say</h2>
        <p style="color: var(--text-muted);">Join thousands of users who have transformed their finances.</p>
    </div>

    <div class="feedback-grid">

        <?php if($feedbacks && $feedbacks->num_rows > 0): ?>
            <?php while($f = $feedbacks->fetch_assoc()): ?>

                <div class="feedback-card">



                    <div class="stars">
                        <?= str_repeat("⭐", (int)$f['rating']) ?>
                    </div>
                    <p>"<?= htmlspecialchars($f['message']) ?>"</p>

                    <strong>- <?= htmlspecialchars($f['name']) ?></strong>

                    <?php if(!empty($f['admin_reply'])): ?>
                        <div class="admin-reply">
                            <b>AMYFI Official Reply</b>
                            <p><?= htmlspecialchars($f['admin_reply']) ?></p>
                        </div>
                    <?php endif; ?>


                </div>

            <?php endwhile; ?>
        <?php else: ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--text-muted);">
                <p>Be the first to share your experience with AMYFI!</p>
            </div>
        <?php endif; ?>

    </div>
</section>

<!-- FOOTER -->
<div class="footer">
    <div style="margin-bottom: 20px; font-weight: 800; color: #fff; font-size: 1.2rem;">ΛMYFI</div>
    © <?= date('Y') ?> AMYFI • Smart Finance Management System
</div>

</body>
</html>
