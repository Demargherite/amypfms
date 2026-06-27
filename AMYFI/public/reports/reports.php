<?php
session_start();
require_once '../../config/db.php';
require_once '../../vendor/autoload.php';
/** @var mysqli $conn */

use Dompdf\Dompdf;
use Dompdf\Options;

/* =====================================================
   SECURITY
===================================================== */
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$stmtStatus = $conn->prepare("SELECT status FROM users WHERE id = ?");
$stmtStatus->bind_param("i", $user_id);
$stmtStatus->execute();
$rowStatus = $stmtStatus->get_result()->fetch_assoc();

if ($rowStatus['status'] !== 'active') {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$salaryStmt = $conn->prepare("
    SELECT monthly_income
    FROM user_settings
    WHERE user_id = ?
    LIMIT 1
");
$salaryStmt->bind_param("i", $user_id);
$salaryStmt->execute();

$salaryRow = $salaryStmt->get_result()->fetch_assoc();

$monthlySalary = (float)($salaryRow['monthly_income'] ?? 0);

/* =====================================================
   SETTINGS
===================================================== */
$view = $_GET['view'] ?? 'monthly';
$allowedViews = ['monthly', 'yearly', 'category'];

if (!in_array($view, $allowedViews)) {
    $view = 'monthly';
}

$today = new DateTime();

/* =====================================================
   FILTERS
===================================================== */
$filterMonth = $_GET['month'] ?? $today->format('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = $today->format('Y-m');
}

$monthStart = $filterMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)$today->format('Y');

if ($filterYear < 2000 || $filterYear > 2100) {
    $filterYear = (int)$today->format('Y');
}

$yearStart = $filterYear . '-01-01';
$yearEnd   = $filterYear . '-12-31';

$selectedCategoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

/* =====================================================
   HELPERS
===================================================== */
/**
 * @param float|int $num
 * @return string
 */
function money($num)
{
    return 'RM ' . number_format((float)$num, 2);
}

/**
 * @param float|int $top
 * @param float|int $bottom
 * @return float|int
 */
function safePercent($top, $bottom)
{
    if ($bottom <= 0) return 0;
    return ($top / $bottom) * 100;
}

/**
 * @param mysqli $conn
 * @param int $user_id
 * @param string $startDate
 * @param string $endDate
 * @param float|int $salaryMultiplier
 * @param float|int $monthlySalary
 * @return array
 */
function getTotalsForPeriod(mysqli $conn, int $user_id, string $startDate, string $endDate, float $salaryMultiplier = 1, float $monthlySalary = 0)
{
    // Extra income transaction only
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS extra_income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS total_expense
        FROM transactions
        WHERE user_id = ?
        AND is_deleted = 0
        AND transaction_date BETWEEN ? AND ?
    ");

    $stmt->bind_param("iss", $user_id, $startDate, $endDate);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $income = ($monthlySalary * $salaryMultiplier) + (float)$row['extra_income'];
    $expense = (float)$row['total_expense'];
    $balance = $income - $expense;

    return [$income, $expense, $balance];
}

/**
 * @param mysqli $conn
 * @param int $userId
 * @param string $startDate
 * @param string $endDate
 * @return array|null
 */
function getTopCategory(mysqli $conn, int $userId, string $startDate, string $endDate)
{
    $sql = "
        SELECT c.name, SUM(t.amount) total
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = ?
        AND t.type='expense'
        AND t.is_deleted = 0
        AND t.transaction_date BETWEEN ? AND ?
        GROUP BY c.name
        ORDER BY total DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $userId, $startDate, $endDate);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

/* =====================================================
   DEFAULT VALUES
===================================================== */
$chartLabels = [];
$chartValues = [];
$chartTitle  = '';
$hasData     = false;
$summaryText = '';
$trendText   = '';

$monthIncome = $monthExpense = $monthBalance = 0;
$yearIncome  = $yearExpense  = $yearBalance  = 0;
$catInc = $catExp = $catBal = 0;
$categoryList = [];

/* =====================================================
   MONTHLY VIEW
===================================================== */
if ($view === 'monthly') {

    list($monthIncome, $monthExpense, $monthBalance) =
        getTotalsForPeriod($conn, $user_id, $monthStart, $monthEnd, 1, $monthlySalary);

    $hasData = ($monthIncome > 0 || $monthExpense > 0);

    $sql = "
        SELECT c.name AS category_name,
               SUM(t.amount) AS total_spent
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = ?
        AND t.type='expense'
        AND t.is_deleted = 0
        AND t.transaction_date BETWEEN ? AND ?
        GROUP BY c.name
        ORDER BY total_spent DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $monthStart, $monthEnd);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $chartLabels[] = $r['category_name'] ?: 'Other';
        $chartValues[] = (float)$r['total_spent'];
    }

    $chartTitle = "Monthly Expenses by Category";

    $top = getTopCategory($conn, $user_id, $monthStart, $monthEnd);

    if ($top) {
        $summaryText = "Highest spending category: " .
            htmlspecialchars($top['name']) .
            " (" . money($top['total']) . ")";
    }

    $savingRate = safePercent($monthBalance, max($monthIncome, 1));

    if ($monthIncome > 0) {
        $trendText = "Savings Rate: " . number_format($savingRate, 1) . "%";
    }
}

/* =====================================================
   YEARLY VIEW
===================================================== */
elseif ($view === 'yearly') {

    list($yearIncome, $yearExpense, $yearBalance) =
        getTotalsForPeriod($conn, $user_id, $yearStart, $yearEnd, 12, $monthlySalary);

    $hasData = ($yearIncome > 0 || $yearExpense > 0);

    $sql = "
        SELECT MONTH(transaction_date) month_num,
               DATE_FORMAT(transaction_date,'%b') month_name,
               SUM(amount) total_spent
        FROM transactions
        WHERE user_id = ?
        AND type='expense'
        AND is_deleted = 0
        AND transaction_date BETWEEN ? AND ?
        GROUP BY month_num, month_name
        ORDER BY month_num
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $yearStart, $yearEnd);
    $stmt->execute();

    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $chartLabels[] = $r['month_name'];
        $chartValues[] = (float)$r['total_spent'];
    }

    $chartTitle = "Yearly Expenses by Month";

    $savingRate = safePercent($yearBalance, max($yearIncome, 1));

    if ($yearIncome > 0) {
        $trendText = "Annual Savings Rate: " . number_format($savingRate, 1) . "%";
    }

    if ($yearExpense > $yearIncome && $yearIncome > 0) {
        $summaryText = "You spent more than you earned this year.";
    } else {
        $summaryText = "Your yearly cashflow remains healthy.";
    }
}

/* =====================================================
   CATEGORY VIEW
===================================================== */
else {

    $catSql = "
        SELECT id, name
        FROM categories
        WHERE (user_id = ? OR user_id IS NULL)
        AND type='expense'
        ORDER BY name
    ";

    $stmtCat = $conn->prepare($catSql);
    $stmtCat->bind_param("i", $user_id);
    $stmtCat->execute();
    $categoryList = $stmtCat->get_result();

    if ($selectedCategoryId > 0) {

        $sqlName = "
            SELECT name
            FROM categories
            WHERE id = ?
            AND (user_id = ? OR user_id IS NULL)
            LIMIT 1
        ";

        $stmt = $conn->prepare($sqlName);
        $stmt->bind_param("ii", $selectedCategoryId, $user_id);
        $stmt->execute();

        $catNameRow = $stmt->get_result()->fetch_assoc();
        $categoryName = $catNameRow['name'] ?? 'Category';

        $sql = "
            SELECT 
                SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS total_income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense
            FROM transactions
            WHERE user_id = ?
            AND category_id = ?
            AND is_deleted = 0
            AND transaction_date BETWEEN ? AND ?
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $user_id, $selectedCategoryId, $yearStart, $yearEnd);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $catInc = (float)($row['total_income'] ?? 0);
        $catExp = (float)($row['total_expense'] ?? 0);
        $catBal = $catInc - $catExp;

        $hasData = ($catInc > 0 || $catExp > 0);

        $sql = "
            SELECT MONTH(transaction_date) month_num,
                   DATE_FORMAT(transaction_date,'%b') month_name,
                   SUM(amount) total_spent
            FROM transactions
            WHERE user_id = ?
            AND category_id = ?
            AND type='expense'
            AND is_deleted = 0
            AND transaction_date BETWEEN ? AND ?
            GROUP BY month_num, month_name
            ORDER BY month_num
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $user_id, $selectedCategoryId, $yearStart, $yearEnd);
        $stmt->execute();

        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $chartLabels[] = $r['month_name'];
            $chartValues[] = (float)$r['total_spent'];
        }

        $chartTitle = $categoryName . " Spending by Month";
        $summaryText = "Detailed category analysis for " . htmlspecialchars($categoryName);
    }
}

$noChartData = (count($chartLabels) === 0 || array_sum($chartValues) <= 0);

if (isset($_GET['download']) && $_GET['download'] === 'pdf') {

    $title = "Financial Report";

    if ($view === 'monthly') {
        $title = "Monthly Report - $filterMonth";
        $income = $monthIncome;
        $expense = $monthExpense;
        $balance = $monthBalance;
    }

    elseif ($view === 'yearly') {
        $title = "Yearly Report - $filterYear";
        $income = $yearIncome;
        $expense = $yearExpense;
        $balance = $yearBalance;
    }

    else {
        $title = "Category Report";
        $income = $catInc;
        $expense = $catExp;
        $balance = $catBal;
    }

    $rows = "";

    for ($i=0; $i<count($chartLabels); $i++) {
        $label = $chartLabels[$i];
        $value = number_format($chartValues[$i],2);

        $rows .= "
        <tr>
            <td>$label</td>
            <td>RM $value</td>
        </tr>";
    }

    $html = "
    <h1>$title</h1>
    <hr>

    <h3>Summary</h3>

    <p>Total Income: RM ".number_format($income,2)."</p>
    <p>Total Expense: RM ".number_format($expense,2)."</p>
    <p>Net Balance: RM ".number_format($balance,2)."</p>

    <h3>Breakdown</h3>

    <table width='100%' border='1' cellspacing='0' cellpadding='8'>
        <tr>
            <th>Category</th>
            <th>Amount</th>
        </tr>
        $rows
    </table>

    <br><br>
    <p>Generated on ".date('d M Y H:i')."</p>
    ";

    $options = new Options();
    $dompdf = new Dompdf($options);

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4','portrait');
    $dompdf->render();

    $dompdf->stream('report.pdf', ['Attachment'=>true]);
    exit;
}

$pageTitle = "Financial Reports";

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="margin-bottom: 4rem; display: flex; flex-direction: column; align-items: center; gap: 2.5rem; text-align: center;">
    <div>
        <h1 style="margin: 0 0 0.5rem 0; font-size: 2.75rem; font-weight: 800; letter-spacing: -0.04em; background: linear-gradient(to bottom, #fff, #94a3b8); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;">Analytics Dashboard</h1>
        <p class="text-muted" style="font-size: 1.15rem; max-width: 600px; margin: 0 auto;">Deep dive into your financial dynamics and cash flow trends.</p>
    </div>

    <!-- View Switcher -->
    <div class="segmented-control">
        <a href="?view=monthly" class="segmented-item <?= $view === 'monthly' ? 'active' : '' ?>">Monthly</a>
        <a href="?view=yearly" class="segmented-item <?= $view === 'yearly' ? 'active' : '' ?>">Yearly</a>
        <a href="?view=category" class="segmented-item <?= $view === 'category' ? 'active' : '' ?>">By Category</a>
    </div>

    <div style="margin-top:1.5rem;">
        <a href="?<?= http_build_query(array_merge($_GET,['download'=>'pdf'])) ?>"
           class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; background: linear-gradient(135deg, #10b981, #059669); font-weight: 700; padding: 0.85rem 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);">
           <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
           Download PDF Report
        </a>
    </div>
</div>

<?php if ($view === 'monthly'): ?>
<div class="glass-tile" style="padding: 1rem 1.5rem; margin-bottom: 3.5rem;">
    <form method="get" style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: center; justify-content: center;">
        <input type="hidden" name="view" value="monthly">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.7;">Select Month</label>
            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filterMonth) ?>" style="max-width: 180px; background: var(--bg-dark-alt); border-color: var(--border); font-weight: 600;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.75rem; border-radius: 12px; font-weight: 800; box-shadow: 0 8px 20px var(--primary-glow);">Update View</button>
    </form>
</div>

<div class="stat-grid" style="margin-bottom: 3rem;">
    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box success">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="stat-label">Total Income</div>
        </div>
        <div class="stat-value"><?= money($monthIncome) ?></div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Recorded in <?= date('M Y', strtotime($monthStart)) ?></div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box danger">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </div>
            <div class="stat-label">Total Expenses</div>
        </div>
        <div class="stat-value text-danger"><?= money($monthExpense) ?></div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Monthly cash outflow</div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box accent">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
            </div>
            <div class="stat-label">Net Balance</div>
        </div>
        <div class="stat-value <?= $monthBalance >= 0 ? 'text-success':'text-danger' ?>">
            <?= money($monthBalance) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Overall profitability</div>
    </div>
</div>

<?php elseif ($view === 'yearly'): ?>
<div class="glass-tile" style="padding: 1rem 1.5rem; margin-bottom: 3.5rem;">
    <form method="get" style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: center; justify-content: center;">
        <input type="hidden" name="view" value="yearly">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.7;">Fiscal Year</label>
            <input type="number" min="2000" max="2100" name="year" class="form-control" value="<?= $filterYear ?>" style="max-width: 120px; background: var(--bg-dark-alt); border-color: var(--border); font-weight: 600;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.75rem; border-radius: 12px; font-weight: 800; box-shadow: 0 8px 20px var(--primary-glow);">Yearly Snapshot</button>
    </form>
</div>

<div class="stat-grid" style="margin-bottom: 3rem;">
    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box success">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="stat-label">Annual Income</div>
        </div>
        <div class="stat-value"><?= money($yearIncome) ?></div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">For the year <?= $filterYear ?></div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box danger">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </div>
            <div class="stat-label">Annual Expenses</div>
        </div>
        <div class="stat-value text-danger"><?= money($yearExpense) ?></div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Yearly spending total</div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box accent">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
            </div>
            <div class="stat-label">Annual Balance</div>
        </div>
        <div class="stat-value <?= $yearBalance >= 0 ? 'text-success':'text-danger' ?>">
            <?= money($yearBalance) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Annual net difference</div>
    </div>
</div>

<?php else: ?>
<div class="glass-tile" style="padding: 1rem 1.5rem; margin-bottom: 3.5rem;">
    <form method="get" style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: center; justify-content: center;">
        <input type="hidden" name="view" value="category">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.7;">Category</label>
            <select name="category_id" class="form-control" style="max-width: 200px; background: var(--bg-dark-alt); border-color: var(--border); font-weight: 600;">
                <option value="">Select Category</option>
                <?php foreach ($categoryList as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $selectedCategoryId == $cat['id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.7;">Year</label>
            <input type="number" name="year" class="form-control" value="<?= $filterYear ?>" style="max-width: 100px; background: var(--bg-dark-alt); border-color: var(--border); font-weight: 600;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.75rem; border-radius: 12px; font-weight: 800; box-shadow: 0 8px 20px var(--primary-glow);">Breakdown</button>
    </form>
</div>

<?php if ($selectedCategoryId > 0): ?>

<div class="stat-grid" style="margin-bottom: 3rem;">
    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box success">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m14 9 5 5-5 5"/><path d="M4 19V14a5 5 0 0 1 5-5h10"/></svg>
            </div>
            <div class="stat-label">Category Inflow</div>
        </div>
        <div class="stat-value"><?= money($catInc) ?></div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Total income in category</div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box danger">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m10 15-5-5 5-5"/><path d="M20 5v5a5 5 0 0 1-5 5H5"/></svg>
            </div>
            <div class="stat-label">Category Outflow</div>
        </div>
        <div class="stat-value text-danger"><?= money($catExp) ?></div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Total spent in category</div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box primary">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="m17 15-5 5-5-5"/></svg>
            </div>
            <div class="stat-label">Net Performance</div>
        </div>
        <div class="stat-value <?= $catBal >= 0 ? 'text-success':'text-danger' ?>">
            <?= money($catBal) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.75rem;">Category net results</div>
    </div>
</div>

<?php endif; ?>
<?php endif; ?>

<?php if ($summaryText): ?>
<div class="glass-tile premium-card" style="margin-top: 2.5rem; border-left: 4px solid var(--primary);">
    <div style="display: flex; align-items: flex-start; gap: 1.25rem;">
        <div class="icon-box primary" style="background: rgba(99, 102, 241, 0.1);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
            <div style="font-size: 0.75rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.5rem;">Smart Insight</div>
            <p style="margin: 0; font-weight: 600; font-size: 1.125rem; line-height: 1.5; color: var(--text-main);"><?= $summaryText ?></p>
            <?php if ($trendText): ?>
                <div style="margin-top: 0.75rem; font-size: 0.875rem; color: var(--text-dim); display: flex; align-items: center; gap: 0.625rem;">
                    <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--accent);"></span>
                    <?= $trendText ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($hasData && !$noChartData): ?>

<div class="chart-container" style="margin-top:3rem;">
<h3 style="margin-bottom:1.5rem;"><?= htmlspecialchars($chartTitle) ?></h3>

<div style="background:rgba(var(--primary-rgb),0.02); padding:2rem; border-radius:1rem;">
<canvas id="reportChart" height="120"></canvas>
</div>
</div>

<?php else: ?>

<div class="glass-tile" style="margin-top: 2rem; padding: 4rem 2rem; text-align: center; border-style: dashed; border-width: 2px;">
    <div style="width: 80px; height: 80px; background: rgba(99, 102, 241, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: var(--primary);">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><path d="M22 10V4h-6"/><path d="M22 4l-10 10"/></svg>
    </div>
    <h3 style="margin-bottom: 0.5rem; font-weight: 700; color: var(--text-main);">No Analytics Data</h3>
    <p class="text-muted" style="max-width: 400px; margin: 0 auto;">We couldn't find any transaction records for the selected period. Try adjusting your filters or adding new transactions.</p>
</div>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

const labels = <?= json_encode($chartLabels) ?>;
const values = <?= json_encode($chartValues) ?>;
const view   = "<?= $view ?>";

if(labels.length === 0 || values.length === 0) return;

const canvas = document.getElementById("reportChart");
if(!canvas) return;

const type = view === "monthly" ? "doughnut" : "bar";

new Chart(canvas, {
    type: type,
    data: {
        labels: labels,
        datasets: [{
            data: values,
            label: "Amount (RM)",
            backgroundColor: type === 'doughnut' ? [
                'rgba(99, 102, 241, 0.85)',
                'rgba(34, 211, 238, 0.85)',
                'rgba(139, 92, 246, 0.85)',
                'rgba(236, 72, 153, 0.85)',
                'rgba(16, 185, 129, 0.85)',
                'rgba(245, 158, 11, 0.85)',
                'rgba(244, 63, 94, 0.85)'
            ] : 'rgba(99, 102, 241, 0.7)',
            borderColor: type === 'doughnut' ? 'transparent' : 'var(--primary)',
            borderWidth: type === 'doughnut' ? 0 : 2,
            borderRadius: type === 'bar' ? 8 : 0,
            hoverOffset: type === 'doughnut' ? 12 : 0,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: type === 'doughnut' ? '65%' : 0,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#94a3b8',
                    usePointStyle: true,
                    padding: 20,
                    font: { family: "'Outfit', sans-serif", size: 12 }
                }
            },
            tooltip: {
                backgroundColor: '#1e293b',
                titleFont: { family: "'Outfit', sans-serif", size: 14 },
                bodyFont: { family: "'Outfit', sans-serif", size: 14 },
                padding: 12,
                cornerRadius: 10,
                displayColors: true,
                callbacks: {
                    label: function(context) {
                        let val = context.parsed.y !== undefined ? context.parsed.y : context.parsed;
                        return ' RM ' + new Intl.NumberFormat('en-MY', { minimumFractionDigits: 2 }).format(val);
                    }
                }
            }
        },
        scales: type === "bar" ? {
            y: { 
                beginAtZero: true,
                grid: { color: 'rgba(255, 255, 255, 0.05)' },
                ticks: { color: '#64748b' }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#64748b' }
            }
        } : {}
    }
});

});
</script>

<?php require_once '../../includes/footer.php'; ?>