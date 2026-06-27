<?php
if (!isset($activePage)) {
    $activePage = '';
}

$userName = $_SESSION['user_name'] ?? 'AMYFI User';
?>
<nav class="amyfi-sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">A</div>
        <div class="sidebar-title">
            <span>AMYFI</span>
            <span>Personal Finance</span>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar">
            <?= strtoupper(substr($userName, 0, 1)) ?>
        </div>
        <div class="sidebar-user-meta">
            <span class="sidebar-user-name"><?= htmlspecialchars($userName) ?></span>
            <span class="sidebar-user-tag">Welcome back ✨</span>
        </div>
    </div>

    <div class="sidebar-section-label">Main</div>
    <ul class="sidebar-nav">
        <li class="sidebar-nav-item <?= $activePage === 'dashboard' ? 'is-active' : '' ?>">
            <a href="/public/dashboard/dashboard.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">📊</span>
                <span>Dashboard</span>
            </a>
        </li>

        <li class="sidebar-nav-item <?= $activePage === 'transactions' ? 'is-active' : '' ?>">
            <a href="/public/transactions/transactions.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">💸</span>
                <span>Transactions</span>
            </a>
        </li>

        <li class="sidebar-nav-item <?= $activePage === 'budgets' ? 'is-active' : '' ?>">
            <a href="/public/budgets/budgets.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">📁</span>
                <span>Budgets</span>
            </a>
        </li>

        <li class="sidebar-nav-item <?= $activePage === 'savings' ? 'is-active' : '' ?>">
            <a href="/public/savings/savings.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">💰</span>
                <span>Savings</span>
            </a>
        </li>

        <li class="sidebar-nav-item <?= $activePage === 'reports' ? 'is-active' : '' ?>">
            <a href="/public/reports/reports.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">📈</span>
                <span>Reports</span>
            </a>
        </li>

        <li class="sidebar-nav-item <?= $activePage === 'advice' ? 'is-active' : '' ?>">
            <a href="/public/ai/advice.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">🤖</span>
                <span>AI Advice</span>
            </a>
        </li>
    </ul>

    <div class="sidebar-section-label">Profile</div>
    <ul class="sidebar-nav">
        <li class="sidebar-nav-item <?= $activePage === 'profile' ? 'is-active' : '' ?>">
            <a href="/public/profile/profile.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">👤</span>
                <span>Profile</span>
            </a>
        </li>

        <li class="sidebar-nav-item <?= $activePage === 'settings' ? 'is-active' : '' ?>">
            <a href="/public/profile/notification_settings.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">⚙️</span>
                <span>Settings</span>
            </a>
        </li>

        <li class="sidebar-nav-item">
            <a href="/public/auth/logout.php" class="sidebar-nav-link">
                <span class="sidebar-nav-icon">🚪</span>
                <span>Logout</span>
            </a>
        </li>
    </ul>

    <?php if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <div class="sidebar-section-label">Admin</div>
        <ul class="sidebar-nav">
            <li class="sidebar-nav-item <?= $activePage === 'admin_dashboard' ? 'is-active' : '' ?>">
                <a href="/public/admin/admin_dashboard.php" class="sidebar-nav-link">
                    <span class="sidebar-nav-icon">🛡️</span>
                    <span>Admin Dashboard</span>
                </a>
            </li>
            <li class="sidebar-nav-item <?= $activePage === 'manage_users' ? 'is-active' : '' ?>">
                <a href="/public/admin/manage_users.php" class="sidebar-nav-link">
                    <span class="sidebar-nav-icon">👥</span>
                    <span>Manage Users</span>
                </a>
            </li>
            <li class="sidebar-nav-item <?= $activePage === 'categories_admin' ? 'is-active' : '' ?>">
                <a href="/public/admin/categories_admin.php" class="sidebar-nav-link">
                    <span class="sidebar-nav-icon">🗂️</span>
                    <span>Categories Admin</span>
                </a>
            </li>
        </ul>
    <?php endif; ?>

    <div class="sidebar-footer">
        © <?= date('Y') ?> AMYFI<br>
        <span class="text-muted">Smart Personal Finance</span>
    </div>
</nav>

