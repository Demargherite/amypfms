<?php
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

// Protect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Month Filter
$month = $_GET['month'] ?? date("Y-m");
if (!preg_match("/^\d{4}-\d{2}$/", $month)) {
    $month = date("Y-m");
}

$startDate = $month . "-01";
$endDate   = date("Y-m-t", strtotime($startDate));
$monthLabel = date("F Y", strtotime($startDate));

// Fetch Overspending Alerts
$sql = "
SELECT 
c.id AS category_id,
c.name AS category_name,
b.limit_amount,
COALESCE(SUM(t.amount), 0) AS spent
FROM budgets b
JOIN categories c ON c.id = b.category_id
LEFT JOIN transactions t 
   ON t.category_id = b.category_id
  AND t.user_id = b.user_id
  AND t.type='expense'
  AND t.is_deleted = 0
  AND t.transaction_date BETWEEN ? AND ?
WHERE b.user_id = ?
GROUP BY b.id, c.name, b.limit_amount
HAVING spent > b.limit_amount
ORDER BY spent DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssi", $startDate, $endDate, $user_id);
$stmt->execute();
$resAlerts = $stmt->get_result();

$pageTitle = "Overspending Alerts";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
/***********************
 Alert Cards
************************/
.alert-card {
    background:#1a0f14;
    border-left:5px solid #ff3e89;
    border-radius:16px;
    padding:22px;
    margin-bottom:18px;
    display:flex;
    gap:18px;
    align-items:flex-start;
    box-shadow:0 0 20px rgba(255,0,123,0.25);
    animation:fadeIn .7s ease-out;
}

@keyframes fadeIn {
    from {opacity:0; transform:translateY(10px);}
    to {opacity:1; transform:translateY(0);}
}

.alert-icon {
    font-size:32px;
    animation:pulseGlow 1.8s infinite;
}

@keyframes pulseGlow {
    50% { text-shadow:0 0 15px #ff5ca8; transform:scale(1.15); }
}

.alert-info { flex: 1; }

.alert-info h4 {
    margin:0;
    font-size:18px;
    font-weight:700;
    color:#ff9ec7;
    text-shadow:0 0 10px #ff5ca8;
}

.alert-info p {
    font-size:15px;
    color:#ffe6f5;
    margin:6px 0;
}

.tip {
    font-size:13px;
    color:#ff9ec7;
    font-weight:600;
    margin:12px 0;
    padding:8px 12px;
    background:rgba(255,158,199,0.08);
    border-radius:10px;
    display:inline-block;
}

/* Modal styles */
.modal {
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.85);
    z-index:9999;
    justify-content:center;
    align-items:center;
}
.modal-content {
    background:#111111;
    border:2px solid #ff5ca8;
    padding:40px;
    border-radius:24px;
    color:white;
    text-align:center;
    box-shadow:0 0 40px rgba(255,92,168,0.3);
}

.btn-primary {
    background: linear-gradient(90deg, #ff5ca8, #ff9ec7);
    border: none;
    padding: 10px 20px;
    color: white;
    font-weight: 700;
    border-radius: 12px;
    cursor: pointer;
    transition: 0.3s;
}
.btn-primary:hover { transform: scale(1.05); box-shadow: 0 0 15px #ff5ca8; }

.btn-secondary {
    background: transparent;
    border: 2px solid #333;
    padding: 10px 20px;
    color: #888;
    font-weight: 700;
    border-radius: 12px;
    cursor: pointer;
}

.btn-sm { padding: 6px 14px; font-size: 13px; }

.no-alert {
    text-align:center;
    padding:40px;
    color:#ffffff;
    font-size:16px;
}
</style>

<div class="amyfi-main">
<main class="amyfi-main-content">

<div class="container">

    <div class="title-box">
        <h2 style="margin-bottom:0.5rem; font-size:2.5rem; font-weight:800; background:linear-gradient(to right, #ff5ca8, #ff9ec7); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;">Budget Health Check</h2>
        <span class="date-pill" style="font-size:1rem; padding:8px 20px;"><?= htmlspecialchars($monthLabel) ?></span>
    </div>

    <!-- Month Filter -->
    <div class="filter-box" style="margin-top: 2rem;">
        <form method="GET">
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
            <button type="submit">Check Alerts</button>
        </form>
    </div>

    <?php if ($resAlerts->num_rows === 0): ?>
        <div class="card" style="text-align:center; padding:5rem 2rem; background:rgba(16,185,129,0.05); border:1px dashed #10b981; border-radius:24px;">
            <div style="font-size:4rem; margin-bottom:1rem;">🎉</div>
            <h3 style="color:#10b981; font-weight:800; margin-bottom:0.5rem;">Total Control!</h3>
            <p class="text-muted">No overspending detected for this month. Your discipline is paying off!</p>
        </div>

    <?php else: ?>
        <div style="margin-top:3rem;">
            <?php while($row = $resAlerts->fetch_assoc()): 
                $suggested = ceil($row['spent'] / 10) * 10;
            ?>
            <div class="alert-card">
                <div class="alert-icon">⚠️</div>

                <div class="alert-info">
                    <h4><?= htmlspecialchars($row['category_name']) ?></h4>

                    <p>
                        You spent 
                        <span style="color:#ff5ca8; font-weight:700;">RM <?= number_format($row['spent'],2) ?></span>
                        which is <span style="font-weight:600;"><?= number_format($row['spent'] - $row['limit_amount'], 2) ?></span> over your RM<?= number_format($row['limit_amount'],0) ?> budget.
                    </p>

                    <div class="tip">
                        Suggested Budget: <strong>RM <?= number_format($suggested,0) ?></strong>
                    </div>

                    <div style="margin-top:1.5rem;">
                        <button class="btn btn-primary btn-sm"
                            onclick="openBudgetModal(
                                <?= $row['category_id'] ?>,
                                '<?= htmlspecialchars($row['category_name'], ENT_QUOTES) ?>',
                                '<?= $suggested ?>'
                            )">
                            Apply Smart Budget
                        </button>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>

</div>

<!-- BUDGET MODAL -->
<div id="budgetModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <div style="font-size: 3rem; margin-bottom: 1.5rem;">💎</div>
        <h3 style="margin-bottom:1rem; font-weight:800;">Smart Budget Suggestion</h3>

        <p id="budgetText" style="color:#cbd5e1; line-height:1.6; margin-bottom:2rem;"></p>

        <form method="POST" action="set_budget.php">
            <input type="hidden" name="apply_suggested" value="1">
            <input type="hidden" name="cat_id" id="modalCatId">
            <input type="hidden" name="amount" id="modalAmount">

            <div style="display:flex; flex-direction:column; gap:0.75rem;">
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    Yes, Apply RM <span id="btnAmountText"></span>
                </button>

                <button type="button"
                    onclick="closeBudgetModal()"
                    class="btn btn-secondary"
                    style="width:100%;">
                    Not Now
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openBudgetModal(catId, category, amount){
    document.getElementById('budgetModal').style.display='flex';

    document.getElementById('budgetText').innerHTML = 
        "Based on your spending, we suggest setting the <strong>" + category + "</strong> budget to <strong>RM " + amount + "</strong> for next month.";
    
    document.getElementById('modalCatId').value = catId;
    document.getElementById('modalAmount').value = amount;
    document.getElementById('btnAmountText').innerText = amount;
}

function closeBudgetModal(){
    document.getElementById('budgetModal').style.display='none';
}
</script>

</main>
<?php require_once '../../includes/footer.php'; ?>
</div>
