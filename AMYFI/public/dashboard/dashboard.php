<?php
try {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    session_start();

    if (!isset($_SESSION['user_id'])) {
        header("Location: ../auth/login.php");
        exit;
    }

require_once '../../config/db.php';
/** @var mysqli $conn */
$user_id = $_SESSION['user_id'];



$stmtStatus = $conn->prepare("SELECT status FROM users WHERE id = ?");
$stmtStatus->bind_param("i", $user_id);
$stmtStatus->execute();
$rowStatus = $stmtStatus->get_result()->fetch_assoc();

if ($rowStatus['status'] !== 'active') {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$stmt = $conn->prepare("
SELECT COALESCE(SUM(current_amount), 0) AS total_savings
FROM savings_goals
WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_savings = $stmt->get_result()->fetch_assoc()['total_savings'];

/***********************************************
 * USER TYPE & BASE INCOME (ONBOARDING)
 ***********************************************/
$stmt = $conn->prepare("
    SELECT u.user_type, us.monthly_income
    FROM users u
    LEFT JOIN user_settings us ON us.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

$user_type   = $data['user_type'] ?? '';
$base_income = (float)($data['monthly_income'] ?? 0);

$saving_goal = 0;

$stmt = $conn->prepare("
    SELECT savings_goal
    FROM user_settings
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$saving_goal = (float)($stmt->get_result()->fetch_assoc()['savings_goal'] ?? 0);
$stmt->close();

$percent = 0;

if ($saving_goal > 0) {
    $percent = min(100, round(($total_savings / $saving_goal) * 100));
}
/***********************************************
 * MONTH FILTER
 ***********************************************/
$monthParam = $_GET['month'] ?? date('Y-m');
$startDate  = $monthParam . '-01';
$endDate    = date('Y-m-t', strtotime($startDate));
$monthLabel = date('F Y', strtotime($startDate));

/***********************************************
 * RECURRING INCOME
 ***********************************************/
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(rt.amount),0) AS recurring_income
    FROM recurring_transactions rt
    WHERE rt.user_id = ?
      AND rt.type = 'income'
      AND rt.is_active = 1
      AND rt.next_run_date <= ?
      AND NOT EXISTS (
          SELECT 1 FROM transactions t
          WHERE t.user_id = rt.user_id
            AND t.type = 'income'
            AND t.category_id = rt.category_id
            AND t.is_deleted = 0
            AND DATE_FORMAT(t.transaction_date,'%Y-%m')
                = DATE_FORMAT(rt.next_run_date,'%Y-%m')
      )
");
$stmt->bind_param("is", $user_id, $endDate);
$stmt->execute();
$recurring_income = $stmt->get_result()->fetch_assoc()['recurring_income'] ?? 0;

/***********************************************
 * TOTAL INCOME
 ***********************************************/
$total_income = $base_income + $recurring_income;

/***********************************************
 * MANUAL EXPENSE
 ***********************************************/
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount),0) AS total_expense
    FROM transactions
    WHERE user_id = ?
      AND type = 'expense'
      AND is_deleted = 0
      AND transaction_date BETWEEN ? AND ?
");
$stmt->bind_param("iss", $user_id, $startDate, $endDate);
$stmt->execute();
$total_expense = $stmt->get_result()->fetch_assoc()['total_expense'] ?? 0;

/***********************************************
 * RECURRING EXPENSE (ADD ON)
 ***********************************************/
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(rt.amount),0) AS recurring_expense
    FROM recurring_transactions rt
    WHERE rt.user_id = ?
      AND rt.type = 'expense'
      AND rt.is_active = 1
      AND rt.next_run_date BETWEEN ? AND ?
      AND (rt.end_date IS NULL OR rt.end_date >= ?)
      AND NOT EXISTS (
          SELECT 1 FROM transactions t
          WHERE t.user_id = rt.user_id
            AND t.type = 'expense'
            AND t.category_id = rt.category_id
            AND t.is_deleted = 0
            AND DATE_FORMAT(t.transaction_date,'%Y-%m')
                = DATE_FORMAT(rt.next_run_date,'%Y-%m')
      )
");
$stmt->bind_param("isss", $user_id, $startDate, $endDate, $startDate);
$stmt->execute();
$recurring_expense = $stmt->get_result()->fetch_assoc()['recurring_expense'] ?? 0;

/* add recurring into total expense (existing total_expense from transactions) */
$total_expense += (float)$recurring_expense;

/*BALANCE*/
$remaining_balance = $total_income - $total_expense - $total_savings;

/***********************************************
 * PIE CHART
 ***********************************************/
$stmt = $conn->prepare("
    SELECT c.name, COALESCE(SUM(t.amount),0) AS total_spent
    FROM categories c
    LEFT JOIN transactions t
      ON t.category_id = c.id
     AND t.user_id = ?
     AND t.type = 'expense'
     AND t.is_deleted = 0
     AND t.transaction_date BETWEEN ? AND ?
    WHERE (c.user_id = ? OR c.user_id IS NULL)
      AND c.type = 'expense'
    GROUP BY c.id
    HAVING total_spent > 0
");
$stmt->bind_param("issi", $user_id, $startDate, $endDate, $user_id);
$stmt->execute();

$chartLabels = [];
$chartValues = [];
$res_chart = $stmt->get_result();
while ($row = $res_chart->fetch_assoc()) {
    $chartLabels[] = $row['name'];
    $chartValues[] = (float)$row['total_spent'];
}

/***********************************************
 * HIGHEST EXPENSE CATEGORY
 ***********************************************/
$stmt = $conn->prepare("
    SELECT c.name, SUM(t.amount) AS total_spent
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = ?
      AND t.type = 'expense'
      AND t.is_deleted = 0
      AND t.transaction_date BETWEEN ? AND ?
    GROUP BY c.id
    ORDER BY total_spent DESC
    LIMIT 1
");

$stmt->bind_param("iss", $user_id, $startDate, $endDate);
$stmt->execute();
$topCategory = $stmt->get_result()->fetch_assoc();

$top_category_name = $topCategory['name'] ?? '';
$top_category_spent = (float)($topCategory['total_spent'] ?? 0);

/***********************************************
 * SAVING GOAL ALERT + CATEGORY TIP
 ***********************************************/
$saving_alert = false;
$saving_tip = "";
$shortfall = 0;

if ($saving_goal > 0 && $remaining_balance < $saving_goal) {

    $saving_alert = true;
    $shortfall = $saving_goal - $remaining_balance;

    if ($top_category_name) {

        $saving_tip = "Your highest spending category this month is <strong>$top_category_name</strong> (RM "
        . number_format($top_category_spent,2) .
        "). Try reducing spending in this category to help reach your saving goal next month.";

    } else {

        $saving_tip = "Review your monthly spending and reduce unnecessary expenses to reach your saving goal.";
    }
}


/***********************************************
 * FINANCIAL SUCCESS STATUS
 ***********************************************/
$financial_success = false;

if ($remaining_balance >= $saving_goal) {
    $financial_success = true;
}

$extra_saving_suggestion = 0;

if ($financial_success) {

    $extra_saving_suggestion = $remaining_balance - $saving_goal;

    if ($extra_saving_suggestion < 0) {
        $extra_saving_suggestion = 0;
    }
}

/***********************************************
 * UPCOMING BILLS (MONTH-SAFE)
 ***********************************************/
$stmt = $conn->prepare("
    SELECT 
        rt.amount,
        rt.next_run_date,
        c.name AS category_name
    FROM recurring_transactions rt
    JOIN categories c ON c.id = rt.category_id
    WHERE rt.user_id = ?
      AND rt.type = 'expense'
      AND rt.is_active = 1
      AND rt.next_run_date <= LAST_DAY(?)
      AND NOT EXISTS (
          SELECT 1
          FROM transactions t
          WHERE t.user_id = rt.user_id
            AND t.category_id = rt.category_id
            AND t.type = 'expense'
            AND t.is_deleted = 0
            AND DATE_FORMAT(t.transaction_date, '%Y-%m')
                = DATE_FORMAT(rt.next_run_date, '%Y-%m')
      )
    ORDER BY rt.next_run_date ASC
    LIMIT 5
");


$stmt->bind_param("is", $user_id, $startDate);
$stmt->execute();
$res_bills = $stmt->get_result();


/***********************************************
 * BUDGET ALERTS (FIXED)
 ***********************************************/
$stmt = $conn->prepare("
    SELECT 
        c.name AS category_name,
        b.limit_amount,
        COALESCE(SUM(t.amount),0) AS spent_amount
    FROM budgets b
    JOIN categories c ON c.id = b.category_id
    LEFT JOIN transactions t
      ON t.category_id = b.category_id
     AND t.user_id = b.user_id
     AND t.type = 'expense'
     AND t.is_deleted = 0
     AND t.transaction_date BETWEEN ? AND ?
    WHERE b.user_id = ?
    GROUP BY b.id, c.name, b.limit_amount
    HAVING spent_amount > b.limit_amount
");
$stmt->bind_param("ssi", $startDate, $endDate, $user_id);
$stmt->execute();
$res_alerts = $stmt->get_result();

$pageTitle = "Dashboard";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>


    <!-- Header Section -->
    <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="margin-bottom: 0.25rem; font-size: 2rem;">Hello, <?= htmlspecialchars($_SESSION['user_name']) ?></h1>
            <p class="text-muted" style="margin: 0; font-size: 0.875rem; font-weight: 500;">
                Today is <?= date("l, d F Y") ?> — <span class="text-primary"><?= htmlspecialchars($monthLabel) ?> Overview</span>
            </p>
        </div>
        <div class="card" style="padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.75rem;">
            <form method="get" style="display: flex; gap: 0.5rem; align-items: center;">
                <input type="month" name="month" value="<?= htmlspecialchars($monthParam) ?>" class="form-control" style="width: 160px; padding: 0.5rem 0.75rem;">
                <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem;">Filter</button>
            </form>
        </div>
    </header>

    <!-- Main Stats Grid -->
    <div class="stat-grid">
        <div class="card" onclick="openSavingModal()" style="cursor: pointer;">
            <div class="card-header" style="margin-bottom: 0.5rem;">
                <span class="stat-label">Click Here To Edit Monthly Saving Target</span>
            </div>
            <div class="stat-value">RM <?= number_format($saving_goal, 2) ?></div>
            <div style="font-size: 0.75rem; color: var(--text-dim);">Monthly target set in settings</div>
        </div>

        <div class="card">
            <div class="card-header" style="margin-bottom: 0.5rem;">
                <span class="stat-label">Total Savings from Saving Goals</span>
            </div>
            <div class="stat-value text-primary">RM <?= number_format($total_savings, 2) ?></div>
            <div style="font-size: 0.75rem; color: var(--text-dim);">Accumulated amount</div>
        </div>

        <div class="card">
            <div class="card-header" style="margin-bottom: 0.5rem;">
                <span class="stat-label">Total Income</span>
            </div>
            <div class="stat-value text-success">RM <?= number_format($total_income, 2) ?></div>
            <div style="font-size: 0.75rem; color: var(--text-dim);">Income for <?= htmlspecialchars($monthLabel) ?></div>
        </div>

        <div class="card">
            <div class="card-header" style="margin-bottom: 0.5rem;">
                <span class="stat-label">Total Expenses</span>
            </div>
            <div class="stat-value text-danger">RM <?= number_format($total_expense, 2) ?></div>
            <div style="font-size: 0.75rem; color: var(--text-dim);">Expenses for <?= htmlspecialchars($monthLabel) ?></div>
        </div>

        <div class="card">
            <div class="card-header" style="margin-bottom: 0.5rem;">
                <span class="stat-label">Remaining Balance</span>
            </div>
            <div class="stat-value" style="color: <?= $remaining_balance >= 0 ? 'var(--text-main)' : 'var(--danger)' ?>;">
                RM <?= number_format($remaining_balance, 2) ?>
            </div>
            <div style="font-size: 0.75rem; color: var(--text-dim);">Net balance for this month</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
        <!-- Chart Card -->
        <div class="card">
            <h3 style="font-size: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                Expense Distribution
            </h3>
            <div style="height: 300px; display: flex; align-items: center; justify-content: center;">
                <?php if (empty($chartLabels)): ?>
                    <p class="text-dim">No expense data for this month yet.</p>
                <?php else: ?>
                    <canvas id="expensesChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bills Card -->
        <div class="card">
            <h3 style="font-size: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                Upcoming Bills
            </h3>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php if ($res_bills->num_rows === 0): ?>
                    <p class="text-dim">No upcoming bills for this period.</p>
                <?php else: ?>
                    <?php while ($bill = $res_bills->fetch_assoc()): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
                            <div>
                                <div style="font-size: 0.875rem; font-weight: 600;"><?= htmlspecialchars($bill['category_name']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-dim);"><?= date('d M Y', strtotime($bill['next_run_date'])) ?></div>
                            </div>
                            <div class="text-danger" style="font-weight: 700; font-size: 0.875rem;">RM <?= number_format($bill['amount'], 2) ?></div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alerts section -->
    <div style="margin-top: 1.5rem;">
        <?php if ($financial_success && $res_alerts->num_rows === 0): ?>
            <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2);">
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <div style="font-size: 1.5rem;"></div>
                    <div>
                        <div style="font-weight: 700; color: var(--success);">You're on track!</div>
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">
                            You've hit your saving goal of RM <?= number_format($saving_goal, 2) ?>. Great job maintaining your finances!

                            <br><br>

                            <?php if($extra_saving_suggestion > 0): ?>
                                We suggest you to saving an additional
                                <strong>
                                    RM <?= number_format($extra_saving_suggestion, 2) ?>
                                </strong>
                                from your remaining balance this month.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($saving_alert): ?>
            <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-top: 1rem;">
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <div style="font-size: 1.5rem;"></div>
                    <div>
                        <div style="font-weight: 700; color: var(--danger);">Savings Shortfall</div>
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">
                            You're RM <?= number_format($shortfall, 2) ?> away from your target. <?= $saving_tip ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($res_alerts->num_rows > 0): ?>
            <?php while ($alert = $res_alerts->fetch_assoc()): ?>
                <div class="alert-card" style="background: rgba(245, 158, 11, 0.05); border-color: rgba(245, 158, 11, 0.2); margin-top: 1rem;">
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <div style="font-size: 1.5rem;"></div>
                        <div>
                            <div style="font-weight: 700; color: var(--warning);">Budget Limit Exceeded: <?= htmlspecialchars($alert['category_name']) ?></div>
                            <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">
                                Spent RM <?= number_format($alert['spent_amount'], 2) ?> against a budget of RM <?= number_format($alert['limit_amount'], 2) ?>.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>

</main>

<!-- SAVING GOAL MODAL -->
<div id="savingModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <h3 style="margin-bottom: 1.5rem;">Edit Monthly Saving Goal</h3>
        
        <?php if(isset($_SESSION['saving_goal_error'])): ?>
            <div class="alert-card" style="margin-bottom: 1rem; color: var(--danger);">
                <?= $_SESSION['saving_goal_error']; ?>
            </div>
        <?php unset($_SESSION['saving_goal_error']); endif; ?>

        <form method="POST" action="../settings/update_saving_goal.php" novalidate>
            <div class="form-group">
                <label class="form-label">Saving Goal (RM)</label>
                <input type="number" name="saving_goal" value="<?= $saving_goal ?>" class="form-control" autofocus>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                <button type="button" onclick="closeSavingModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($chartLabels) ?>;
const values = <?= json_encode($chartValues) ?>;

if (labels.length > 0) {
    const ctx = document.getElementById("expensesChart").getContext("2d");
    new Chart(ctx, {
        type: "pie",
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: [
                    "#6366f1", // Indigo (Primary)
                    "#8b5cf6", // Violet
                    "#3b82f6", // Blue
                    "#22d3ee", // Cyan (Accent)
                    "#0ea5e9", // Sky
                    "#7c3aed", // Deep Violet
                    "#06b6d4", // Dark Cyan
                    "#94a3b8"  // Slate
                ],
                borderColor: "rgba(15, 23, 42, 0.8)",
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: "bottom",
                    labels: {
                        color: "#94a3b8",
                        font: { 
                            size: 12,
                            family: "'Outfit', sans-serif"
                        },
                        padding: 20
                    }
                }
            }
        }
    });
}
function openSavingModal(){
    document.getElementById("savingModal").style.display="flex";
}

function closeSavingModal(){
    document.getElementById("savingModal").style.display="none";
}

function openSavingModal(){
    document.getElementById("savingModal").style.display="flex";
}

function closeSavingModal(){
    document.getElementById("savingModal").style.display="none";
}

<?php if(isset($_SESSION['open_saving_modal'])): ?>
document.addEventListener("DOMContentLoaded", function(){
    document.getElementById("savingModal").style.display = "flex";
});
<?php unset($_SESSION['open_saving_modal']); endif; ?>

</script>
<?php 
} catch (Throwable $e) {
    echo "<div style='padding: 2rem; background: #fee2e2; color: #991b1b; font-family: monospace;'>";
    echo "<h1>CRITICAL ERROR DETECTED</h1>";
    echo "<h3>Message: " . htmlspecialchars($e->getMessage()) . "</h3>";
    echo "<p><b>File:</b> " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p><b>Line:</b> " . htmlspecialchars($e->getLine()) . "</p>";
    echo "</div>";
}
?>
