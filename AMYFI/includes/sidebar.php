<?php
// Sidebar – AMYFI
$userName = $_SESSION['user_name'] ?? 'User';
$userInitial = strtoupper($userName[0]);
$currentPage = basename($_SERVER['PHP_SELF']);

$userId = $_SESSION['user_id'] ?? 0;
$profilePic = '';

require_once __DIR__ . '/../config/db.php';
if (!defined('BASE_URL')) require_once __DIR__ . '/../config/constants.php';

$stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $profilePic = $row['profile_pic'];
}

// Define base path for links using relative paths for env-safe routing
$basePath = '..';


$supportSuccess = '';
$supportError   = '';

$feedbackSuccess = '';
$feedbackError   = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_support'])) {

    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    $userId = $_SESSION['user_id'] ?? 0;
    $name   = $_SESSION['user_name'] ?? 'User';

    $email = '';

    $stmt = $conn->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if($row){
        $email = $row['email'];
    }

    if ($subject === '') {
        $supportError = "Subject is required.";
    } elseif (strlen($subject) < 3) {
        $supportError = "Subject must be at least 3 characters.";
    } elseif ($message === '') {
        $supportError = "Support message is required.";
    } elseif (strlen($message) < 10) {
        $supportError = "Support message must be at least 10 characters.";
    } else {
        $stmt = $conn->prepare("
        INSERT INTO customer_support
        (user_id,name,email,subject,message)
        VALUES (?,?,?,?,?)
        ");
 
        $stmt->bind_param(
            "issss",
            $userId,
            $name,
            $email,
            $subject,
            $message
        );
 
        $stmt->execute();
        $supportSuccess = "Support request sent successfully.";
    }

}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_feedback'])) {

    $message = trim($_POST['feedback_message']);
    $rating  = (int)$_POST['rating'];

    $userId = $_SESSION['user_id'] ?? 0;
    $name   = $_SESSION['user_name'] ?? 'User';

    // Message is optional — only rating is required
    $stmt = $conn->prepare("
        INSERT INTO feedbacks (user_id, name, message, rating)
        VALUES (?,?,?,?)
    ");

    $stmt->bind_param("issi",
        $userId,
        $name,
        $message,
        $rating
    );

    $stmt->execute();

    $feedbackSuccess = "Thank you for your feedback!";
}

// NEW: Get count of resolved support for notification badge
$resolvedCount = 0;
if ($userId > 0) {
    $stmtCount = $conn->prepare("SELECT COUNT(*) as total FROM customer_support WHERE user_id = ? AND status = 'resolved' AND user_seen = 0");
    $stmtCount->bind_param("i", $userId);
    $stmtCount->execute();
    $resolvedCount = $stmtCount->get_result()->fetch_assoc()['total'] ?? 0;
}
?>

<style>
.amyfi-sidebar {
    width: 260px;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    padding: 1.5rem 1rem;
    background: var(--bg-dark-alt);
    border-right: 1px solid var(--border);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-y: auto;
    overflow-x: hidden;
}

.amyfi-sidebar::-webkit-scrollbar {
    width: 4px;
}

.amyfi-sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.amyfi-sidebar::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 10px;
}

.sidebar-brand {
    padding: 0 1rem;
    margin-bottom: 2.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.brand-logo {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 8px;
    display: grid;
    place-items: center;
    color: white;
    font-weight: 800;
    font-size: 1rem;
}

.brand-name {
    font-weight: 800;
    font-size: 1.25rem;
    letter-spacing: -0.5px;
    color: var(--text-main);
}

.user-profile {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    margin-bottom: 2rem;
}

.user-avatar {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 10px;
    background: var(--primary);
    display: grid;
    place-items: center;
    font-weight: 700;
    color: white;
    box-shadow: var(--shadow-glow);
}

.nav-section {
    margin-bottom: 1.5rem;
}

.nav-section-title {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 0.75rem;
    padding: 0 1rem;
}

.sidebar-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--text-muted);
    font-size: 0.875rem;
    font-weight: 500;
    transition: var(--transition);
    margin-bottom: 0.25rem;
}

.sidebar-link:hover {
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-main);
}

.sidebar-link.active {
    background: rgba(99, 102, 241, 0.1);
    color: var(--primary);
    font-weight: 600;
    border: 1px solid rgba(99, 102, 241, 0.2);
}

.sidebar-link.active svg {
    stroke: var(--primary);
}

.sidebar-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
}

.sidebar-link svg {
    width: 18px;
    height: 18px;
    stroke: var(--text-dim);
    stroke-width: 2;
    transition: var(--transition);
}

.sidebar-link:hover svg {
    stroke: var(--text-main);
}

.logout-container {
    margin-top: auto;
    border-top: 1px solid var(--border);
    padding-top: 1.5rem;
}

@media (max-width: 1024px) {
    .amyfi-sidebar { width: 80px; padding: 1.5rem 0.5rem; }
    .brand-name, .sidebar-text, .nav-section-title, .user-profile div:last-child { display: none; }
    .user-profile { padding: 0.5rem; justify-content: center; }
    .sidebar-link { justify-content: center; padding: 0.75rem; }
}
</style>

<aside class="amyfi-sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-logo">ΛF</div>
        <span class="brand-name">AMYFI</span>
    </div>

    <!-- User Profile -->
    <div class="user-profile">
        <div class="user-avatar" style="overflow:hidden;">
            <?php if($profilePic && file_exists(__DIR__ . '/../assets/uploads/' . $profilePic)): ?>
                <img src="<?= ASSET_URL ?>uploads/<?= htmlspecialchars($profilePic) ?>" 
                     style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <?= $userInitial ?>
            <?php endif; ?>
        </div>

        <div>
            <div style="font-size: 0.7rem; color: var(--text-dim); font-weight: 500;">Good Day,</div>
            <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-main); max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($userName) ?></div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <div class="nav-section">
        <div class="nav-section-title">Overview</div>
        <a href="<?= $basePath ?>/dashboard/dashboard.php" class="sidebar-link <?= $currentPage=='dashboard.php'?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            </span>
            <span class="sidebar-text">Dashboard</span>
        </a>
    </div>
<div class="nav-section">
        <div class="nav-section-title">Account</div>
        <a href="<?= $basePath ?>/profile/profile.php" class="sidebar-link <?= $currentPage=='profile.php'?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <span class="sidebar-text">Profile Settings</span>
        </a>
    </div>

    <div class="nav-section">
        <div class="nav-section-title">Management</div>
        <a href="<?= $basePath ?>/transactions/transactions.php" class="sidebar-link <?= ($currentPage=='transactions.php'||$currentPage=='add_transaction.php')?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v20M17 5H9.5a4.5 4.5 0 0 0 0 9h5a4.5 4.5 0 0 1 0 9H7"/></svg>
            </span>
            <span class="sidebar-text">Transactions</span>
        </a>
        <a href="<?= $basePath ?>/categories/categories.php" class="sidebar-link <?= ($currentPage=='categories.php'||$currentPage=='add_category.php')?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            </span>
            <span class="sidebar-text">Categories</span>
        </a>
        <a href="<?= $basePath ?>/budgets/budgets.php" class="sidebar-link <?= ($currentPage=='budgets.php'||$currentPage=='set_budget.php')?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 1v22M17 5H9.5a4.5 4.5 0 0 0 0 9h5a4.5 4.5 0 0 1 0 9H7"/></svg>
            </span>
            <span class="sidebar-text">Budgets</span>
        </a>
        <a href="<?= $basePath ?>/savings/savings.php" class="sidebar-link <?= ($currentPage=='savings.php'||$currentPage=='add_saving_goal.php')?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </span>
            <span class="sidebar-text">Savings</span>
        </a>
        <a href="<?= $basePath ?>/recurring/recurring.php" class="sidebar-link <?= ($currentPage=='recurring.php'||$currentPage=='add_recurring.php')?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
            </span>
            <span class="sidebar-text">Recurring</span>
        </a>
        <a href="<?= $basePath ?>/reports/reports.php" class="sidebar-link <?= $currentPage=='reports.php'?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
            </span>
            <span class="sidebar-text">Reports</span>
        </a>
    </div>

    <div class="nav-section">
        <div class="nav-section-title">Tools</div>
        <a href="<?= $basePath ?>/Advice/advice.php" class="sidebar-link <?= $currentPage=='advice.php'?'active':'' ?>">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8z"/><path d="M12 6v6l4 2"/></svg>
            </span>
            <span class="sidebar-text">Smart Advice</span>
        </a>
        <a href="javascript:void(0)" onclick="openSupportModal()" class="sidebar-link">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </span>
            <span class="sidebar-text">Customer Support</span>
        </a>
        <a href="javascript:void(0)" onclick="openFeedbackModal()" class="sidebar-link">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </span>
            <span class="sidebar-text">Feedback</span>
        </a>
        <a href="<?= $basePath ?>/feedback/my-support.php" class="sidebar-link <?= $currentPage=='my-support.php'?'active':'' ?>" style="position: relative;">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                    <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
            </span>
            <span class="sidebar-text">My Support</span>
            <?php if($resolvedCount > 0): ?>
                <span style="
                    position: absolute;
                    right: 12px;
                    background: var(--success);
                    color: white;
                    font-size: 10px;
                    font-weight: 800;
                    padding: 2px 6px;
                    border-radius: 10px;
                    box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);
                "><?= $resolvedCount ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Bottom Actions -->
    <div class="logout-container">
        <a href="<?= $basePath ?>/auth/logout.php" class="sidebar-link" style="color: var(--danger);">
            <span class="sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="stroke: var(--danger);"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </span>
            <span class="sidebar-text">Logout</span>
        </a>
    </div>
</aside>

<div id="supportModal" style="
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,.65);
z-index:9999;
justify-content:center;
align-items:center;
">

<div style="
width:95%;
max-width:520px;
background:#111827;
padding:30px;
border-radius:20px;
color:white;
">

<h2 style="margin-top:0;">Contact Support</h2>
<p style="color:#94a3b8;">Let us help you resolve your issue</p>

<?php if($supportSuccess): ?>
<div style="padding:12px; background:rgba(16,185,129,.1); border-radius:10px; margin-bottom:15px; color: #10b981; font-weight: bold;">
<?= $supportSuccess ?>
</div>
<?php endif; ?>

<?php if($supportError): ?>
<div style="padding:12px; background:rgba(239,68,68,.1); border-radius:10px; margin-bottom:15px; color:#ef4444; font-weight:bold;">
<?= htmlspecialchars($supportError) ?>
</div>
<?php endif; ?>

<form method="POST" novalidate>

<input type="hidden" name="send_support" value="1">

<input type="text"
name="subject"
placeholder="Subject"
style="
width:100%;
padding:12px;
margin-bottom:15px;
border:none;
border-radius:10px;
background:#1e293b;
color:white;
box-sizing:border-box;">

<textarea
name="message"
rows="6"
placeholder="How can we help you?"

style="
width:100%;
padding:12px;
border:none;
border-radius:10px;
background:#1e293b;
color:white;
margin-bottom:18px;
box-sizing:border-box;
resize:vertical;"></textarea>

<div style="display:flex;gap:10px;">

<button type="submit"
style="
flex:1;
padding:12px;
border:none;
border-radius:10px;
background:#6366f1;
color:white;
font-weight:bold;
cursor:pointer;">
Send Request
</button>

<button type="button"
onclick="closeSupportModal()"
style="
flex:1;
padding:12px;
border:none;
border-radius:10px;
background:#374151;
color:white;
cursor:pointer;">
Close
</button>

</div>

</form>

</div>
</div>

<div id="feedbackModal" style="
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,.65);
z-index:9999;
justify-content:center;
align-items:center;
">

<div style="
width:95%;
max-width:520px;
background:#111827;
padding:30px;
border-radius:20px;
color:white;
">

<h2>Rate Your Experience</h2>
<p style="color:#94a3b8;">Tell us what you think about AMYFI</p>

<?php if($feedbackSuccess): ?>
<div style="padding:12px;background:rgba(16,185,129,.1);border-radius:10px;margin-bottom:15px;color:#10b981;font-weight:bold;">
<?= $feedbackSuccess ?>
</div>
<?php endif; ?>

<?php if($feedbackError): ?>
<div style="padding:12px;background:rgba(239,68,68,.1);border-radius:10px;margin-bottom:15px;color:#ef4444;font-weight:bold;">
<?= $feedbackError ?>
</div>
<?php endif; ?>

<form method="POST">

<input type="hidden" name="send_feedback" value="1">

<select name="rating" style="width:100%;padding:10px;margin-bottom:15px;border-radius:10px;background:#1e293b;color:white;">
    <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
    <option value="4">⭐⭐⭐⭐ Good</option>
    <option value="3">⭐⭐⭐ Average</option>
    <option value="2">⭐⭐ Poor </option>
    <option value="1">⭐ Worst </option>
</select>

<label style="display:block;font-size:0.8rem;color:#94a3b8;margin-bottom:6px;">Feedback <span style="font-style:italic;">(optional)</span></label>
<textarea
name="feedback_message"
rows="5"
placeholder="Write your feedback... (optional)"
style="width:100%;padding:12px;border:none;border-radius:10px;background:#1e293b;color:white;margin-bottom:18px;box-sizing:border-box;resize:vertical;"></textarea>

<div style="display:flex;gap:10px;">

<button type="submit"
style="flex:1;padding:12px;border:none;border-radius:10px;background:#6366f1;color:white;font-weight:bold;">
Submit
</button>

<button type="button"
onclick="closeFeedbackModal()"
style="flex:1;padding:12px;border:none;border-radius:10px;background:#374151;color:white;">
Close
</button>

</div>
</form>

</div>
</div>

<script>
<?php if($supportSuccess || $supportError): ?>
    document.addEventListener("DOMContentLoaded", function() {
        openSupportModal();
    });
<?php endif; ?>


function openSupportModal(){
    document.getElementById('supportModal').style.display='flex';
}

function closeSupportModal(){
    document.getElementById('supportModal').style.display='none';
}

function openFeedbackModal(){
    document.getElementById('feedbackModal').style.display='flex';
}

function closeFeedbackModal(){
    document.getElementById('feedbackModal').style.display='none';
}

<?php if($feedbackSuccess || $feedbackError): ?>
document.addEventListener("DOMContentLoaded", function() {
    openFeedbackModal();
});
<?php endif; ?>
</script>
