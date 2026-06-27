<?php
session_start();
ob_start();
require_once '../../config/db.php';
/** @var mysqli $conn */
require_once '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* =====================================================
   PROTECT PAGE
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

$pdfMode = isset($_GET['pdf']) ? 1 : 0;

/* =====================================================
   DATE FILTER
===================================================== */
$view = $_GET['view'] ?? 'monthly';
if ($view !== 'yearly') $view = 'monthly';

$yearParam = (int)date('Y');
$monthParam = date('Y-m');

if ($view === 'yearly') {
    if (isset($_GET['year'])) {
        $yearParam = (int)$_GET['year'];
    }
    if ($yearParam < 2000 || $yearParam > 2100) $yearParam = (int)date('Y');
    $startDate   = $yearParam . '-01-01';
    $endDate     = $yearParam . '-12-31';
    $periodLabel = $yearParam;
} else {
    if (isset($_GET['month'])) {
        $monthParam = $_GET['month'];
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthParam = date('Y-m');
    }
    $startDate   = $monthParam . '-01';
    $endDate     = date('Y-m-t', strtotime($startDate));
    $periodLabel = date('F Y', strtotime($startDate));
}

/* =====================================================
   HELPERS
===================================================== */
function addAdvice(array &$cards, string $severity, string $icon, string $title, string $msg, string $tip)
{
    $cards[] = compact('severity', 'icon', 'title', 'msg', 'tip');
}

function percentage(float $part, float $total): float
{
    if ($total <= 0) return 0;
    return ($part / $total) * 100;
}

/* =====================================================
   MONTHLY TOTALS
===================================================== */
// Extra income only
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

$salaryBase = ($view === 'yearly') ? ($monthlySalary * 12) : $monthlySalary;

$totalIncome  = $salaryBase + (float)$row['extra_income'];
$totalExpense = (float)$row['total_expense'];

$balance = $totalIncome - $totalExpense;

/* =====================================================
   CATEGORY SPENDING
===================================================== */
$sqlCat = "
SELECT c.name AS category_name, COALESCE(SUM(t.amount),0) AS total_spent
FROM transactions t
LEFT JOIN categories c ON c.id = t.category_id
WHERE t.user_id = ?
AND t.type = 'expense'
AND t.is_deleted = 0
AND t.transaction_date BETWEEN ? AND ?
GROUP BY c.id, c.name
ORDER BY total_spent DESC
";

$stmt = $conn->prepare($sqlCat);
$stmt->bind_param("iss", $user_id, $startDate, $endDate);
$stmt->execute();
$resCat = $stmt->get_result();

$categorySpend = [];
$foodSpend = 0;
$topCategory = '';
$topCategoryAmount = 0;

while ($row = $resCat->fetch_assoc()) {

    $name  = $row['category_name'] ?: 'Other';
    $spent = (float)$row['total_spent'];

    $categorySpend[$name] = $spent;

    if ($spent > $topCategoryAmount) {
        $topCategoryAmount = $spent;
        $topCategory = $name;
    }

    $check = strtolower($name);

    if (
        str_contains($check, 'food') ||
        str_contains($check, 'makan') ||
        str_contains($check, 'dining') ||
        str_contains($check, 'restaurant')
    ) {
        $foodSpend += $spent;
    }
}



/* =====================================================
   MAX SINGLE EXPENSE
===================================================== */
$sqlMax = "
SELECT MAX(amount) AS max_expense
FROM transactions
WHERE user_id = ?
AND type='expense'
AND is_deleted = 0
AND transaction_date BETWEEN ? AND ?
";

$stmt = $conn->prepare($sqlMax);
$stmt->bind_param("iss", $user_id, $startDate, $endDate);
$stmt->execute();

$rowMax = $stmt->get_result()->fetch_assoc();
$maxExpense = (float)($rowMax['max_expense'] ?? 0);

/* =====================================================
   SMART SCORE SYSTEM
===================================================== */
$score = 100;

if ($totalIncome > 0) {
    $usage = percentage($totalExpense, $totalIncome);

    if ($usage >= 100) $score -= 40;
    elseif ($usage >= 90) $score -= 30;
    elseif ($usage >= 80) $score -= 20;
    elseif ($usage >= 70) $score -= 10;
}

if ($foodSpend > 0 && percentage($foodSpend, $totalExpense) >= 35) {
    $score -= 10;
}

if ($maxExpense > 0 && $maxExpense >= ($totalExpense * 0.45)) {
    $score -= 10;
}



if ($score < 0) $score = 0;

/* =====================================================
   ADVICE ENGINE
===================================================== */
$adviceCards = [];

if ($totalIncome == 0 && $totalExpense == 0) {

    addAdvice(
        $adviceCards,
        'normal',
        '📘',
        'Start Tracking Your Money',
        "No records found for $periodLabel.",
        "Track your daily spending for 7 days to unlock more accurate insights."
    );

} else {

    /* Balance */
    if ($balance > 0) {
        addAdvice(
            $adviceCards,
            'normal',
            '💰',
            'Positive Cash Flow',
            "You saved RM " . number_format($balance, 2) . " this month.",
            "Move part of your surplus into savings goals or emergency funds."
        );
    } else {
        addAdvice(
            $adviceCards,
            'priority',
            '⚠️',
            'Negative Cash Flow',
            "Expenses exceeded income by RM " . number_format(abs($balance), 2) . ".",
            "Reduce non-essential spending next month and review subscriptions."
        );
    }

    /* Food */
    if ($foodSpend > 0) {
        $foodPercent = percentage($foodSpend, $totalExpense);

        if ($foodPercent >= 30) {
            addAdvice(
                $adviceCards,
                'priority',
                '🍔',
                'High Food Spending',
                number_format($foodPercent, 1) . "% of expenses went to food.",
                "Try weekly meal budgeting, cooking at home, or limiting delivery orders."
            );
        }
    }

    /* Largest category */
    if ($topCategoryAmount > 0) {
        addAdvice(
            $adviceCards,
            'normal',
            '📊',
            'Top Spending Category',
            "$topCategory was your highest expense category.",
            "Review this category first for the fastest savings improvement."
        );
    }

    /* Single Large Expense */
    if ($maxExpense > 0 && $maxExpense >= ($totalExpense * 0.40)) {
        addAdvice(
            $adviceCards,
            'priority',
            '🧾',
            'Large Transaction Detected',
            "One transaction used a large share of monthly expenses.",
            "Check whether it was planned, essential, or avoidable."
        );
    }



    /* Income usage */
    if ($totalIncome > 0) {
        $ratio = percentage($totalExpense, $totalIncome);

        if ($ratio >= 90) {
            addAdvice(
                $adviceCards,
                'priority',
                '🚨',
                'Overspending Risk',
                number_format($ratio, 1) . "% of income has been spent.",
                "Aim to keep spending below 80% of income."
            );
        } elseif ($ratio <= 60) {
            addAdvice(
                $adviceCards,
                'normal',
                '🌟',
                'Healthy Spending Ratio',
                "Only " . number_format($ratio, 1) . "% of income was used.",
                "Excellent control. Continue this habit consistently."
            );
        }
    }
}

if (empty($adviceCards)) {
    addAdvice(
        $adviceCards,
        'normal',
        '📈',
        'Stable Progress',
        "Your spending looks balanced this month.",
        "Keep tracking regularly for stronger long-term results."
    );
}

if ($pdfMode === 1) {

    $html = '
    <html>
    <head>
    <style>
        body{
            font-family: DejaVu Sans, sans-serif;
            font-size:12px;
            color:#111;
        }
        h1{
            text-align:center;
            margin-bottom:5px;
        }
        .sub{
            text-align:center;
            color:#666;
            margin-bottom:20px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            margin-bottom:20px;
        }
        th,td{
            border:1px solid #ccc;
            padding:8px;
        }
        th{
            background:#f3f3f3;
        }
        .card{
            border:1px solid #ddd;
            padding:12px;
            margin-bottom:12px;
            border-radius:6px;
        }
        .priority{
            border-left:5px solid red;
        }
        .normal{
            border-left:5px solid green;
        }
    </style>
    </head>
    <body>

    <h1>Financial Insights Report</h1>
    <div class="sub">Period: '.$periodLabel.'</div>

    <table>
        <tr>
            <th>Income</th>
            <th>Expenses</th>
            <th>Balance</th>
            <th>Score</th>
        </tr>
        <tr>
            <td>RM '.number_format($totalIncome,2).'</td>
            <td>RM '.number_format($totalExpense,2).'</td>
            <td>RM '.number_format($balance,2).'</td>
            <td>'.$score.'/100</td>
        </tr>
    </table>

    <h2>Insights & Recommendations</h2>
    ';

foreach($adviceCards as $c){

    $html .= '
    <div class="card '.$c['severity'].'">
        <strong>'.$c['title'].'</strong><br><br>
        '.$c['msg'].'<br><br>
        <b>Recommendation:</b> '.$c['tip'].'
    </div>
    ';
}

$html .= '
    </body>
    </html>
';

try {

    $options = new Options();

    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);

    $dompdf->loadHtml($html);

    $dompdf->setPaper('A4', 'portrait');

    $dompdf->render();

    // Clear output buffer
    if (ob_get_length()) {
        ob_end_clean();
    }

    $pdfOutput = $dompdf->output();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="financial_advice.pdf"');
    header('Content-Length: ' . strlen($pdfOutput));

    echo $pdfOutput;
    exit;

} catch (Exception $e) {

    if (ob_get_length()) {
        ob_end_clean();
    }

    die("PDF Error: " . $e->getMessage());
}
}

/* =====================================================
   PAGE
===================================================== */
$pageTitle = "Smart Advice";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<div style="margin-bottom: 4rem; display: flex; flex-direction: column; align-items: center; gap: 2.5rem; text-align: center;">
    <div>
        <h1 style="margin: 0 0 0.5rem 0; font-size: 2.75rem; font-weight: 800; letter-spacing: -0.04em; background: linear-gradient(to bottom, #fff, #94a3b8); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;">Financial Insights</h1>
    </div>

    <!-- View Switcher -->
    <div class="segmented-control">
        <a href="?view=monthly" class="segmented-item <?= $view === 'monthly' ? 'active' : '' ?>">Monthly Advice</a>
        <a href="?view=yearly" class="segmented-item <?= $view === 'yearly' ? 'active' : '' ?>">Yearly Summary</a>
    </div>
</div>

<!-- FILTER -->
<div class="glass-tile" style="padding: 1rem 1.5rem; margin-bottom: 3.5rem;">
    <form method="get" style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: center; justify-content: center;">
        <input type="hidden" name="view" value="<?= $view ?>">
        
        <?php if ($view === 'yearly'): ?>
            <div style="display: flex; align-items: center; gap: 0.875rem;">
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.7;">Select Year</label>
                <select name="year" class="form-control" style="max-width: 140px; background: var(--bg-dark-alt); border-color: var(--border); font-weight: 600;">
                    <?php 
                    $curr = (int)date('Y');
                    for($y = $curr; $y >= $curr-5; $y--): ?>
                        <option value="<?= $y ?>" <?= (isset($yearParam) && $yearParam == $y) ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        <?php else: ?>
            <div style="display: flex; align-items: center; gap: 0.875rem;">
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.7;">Select Month</label>
                <input type="month" name="month" class="form-control"
                       value="<?= isset($monthParam) ? htmlspecialchars($monthParam) : '' ?>"
                       style="max-width: 200px; background: var(--bg-dark-alt); border-color: var(--border); font-weight: 600;">
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.75rem; border-radius: 12px; font-weight: 800; box-shadow: 0 8px 20px var(--primary-glow);">Update Strategy</button>
        <a href="?view=<?= $view ?>&<?php if($view=='monthly'): ?>month=<?= $monthParam ?><?php else: ?>year=<?= $yearParam ?><?php endif; ?>&pdf=1" class="btn btn-secondary" style="padding:0.65rem 1.75rem; border-radius:12px;">Download PDF</a>
    </form>
</div>

<!-- SCORE -->
<div class="glass-tile premium-card" style="padding: 4rem 2rem; margin-bottom: 3.5rem; text-align: center; border-bottom: 4px solid <?= $score >= 80 ? 'var(--success)' : ($score >= 60 ? 'var(--warning)' : 'var(--danger)') ?>;">
    <div style="font-size: 0.875rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 1.5rem;">Financial Health Score</div>
    <div style="font-size: 6rem; font-weight: 900; line-height: 1; margin-bottom: 1.5rem;
        background: linear-gradient(135deg, <?= $score >= 80 ? 'var(--success), #34d399' : ($score >= 60 ? 'var(--warning), #fbbf24' : 'var(--danger), #f87171') ?>);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        text-shadow: 0 10px 40px <?= $score >= 80 ? 'rgba(16, 185, 129, 0.2)' : ($score >= 60 ? 'rgba(245, 158, 11, 0.2)' : 'rgba(239, 68, 68, 0.2)') ?>;">
        <?= $score ?><span style="font-size: 2rem; opacity: 0.4; margin-left: 8px;">/100</span>
    </div>
    <p class="text-muted" style="max-width: 550px; margin: 0 auto; line-height: 1.7; font-size: 1.05rem;">
        Your score is calculated based on monthly income usage, balance stability, and spending behavioral consistency.
    </p>
</div>

<!-- SUMMARY -->
<div class="stat-grid" style="margin-bottom: 4rem;">
    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box success">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="stat-label">Income</div>
        </div>
        <div class="stat-value">RM <?= number_format($totalIncome,2) ?></div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box danger">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </div>
            <div class="stat-label">Expenses</div>
        </div>
        <div class="stat-value text-danger">RM <?= number_format($totalExpense,2) ?></div>
    </div>

    <div class="glass-tile premium-card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div class="icon-box primary">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
            </div>
            <div class="stat-label">Net Balance</div>
        </div>
        <div class="stat-value <?= $balance >= 0 ? 'text-success':'text-danger' ?>">
            RM <?= number_format($balance,2) ?>
        </div>
    </div>
</div>

<!-- ADVICE -->
<div style="margin-bottom:2rem;">
    <h3 style="margin-bottom:1.5rem;">Insights & Recommendations</h3>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.5rem;">

        <?php foreach($adviceCards as $c): ?>

        <div class="glass-tile premium-card"
             style="padding: 2.25rem;
             display: flex;
             flex-direction: column;
             border: 1px solid <?= $c['severity'] == 'priority' ? 'rgba(239, 68, 68, 0.25)' : 'var(--border)' ?>;
             background: <?= $c['severity'] == 'priority' ? 'rgba(239, 68, 68, 0.05)' : 'var(--surface)' ?>;">

            <div style="display: flex; gap: 1.5rem; margin-bottom: 1.75rem;">
                <div class="icon-box" style="background: var(--bg-dark-alt); <?= $c['severity'] == 'priority' ? 'color: var(--danger); border-color: rgba(239, 68, 68, 0.2);' : 'color: var(--primary);' ?>">
                    <?= $c['icon'] ?>
                </div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 0.5rem 0; font-size: 1.15rem; font-weight: 700; color: <?= $c['severity'] == 'priority' ? 'var(--danger)' : 'var(--text-main)' ?>;">
                        <?= $c['title'] ?>
                    </h4>
                    <p style="margin: 0; color: var(--text-dim); font-size: 0.95rem; line-height: 1.6;">
                        <?= $c['msg'] ?>
                    </p>
                </div>
            </div>

            <div style="
                margin-top: auto;
                background: rgba(var(--primary-rgb), 0.04);
                border: 1px solid rgba(var(--primary-rgb), 0.08);
                padding: 1.25rem;
                border-radius: 14px;
            ">
                <div style="
                    font-size: 0.7rem;
                    font-weight: 800;
                    margin-bottom: 0.6rem;
                    color: var(--primary);
                    text-transform: uppercase;
                    letter-spacing: 0.1em;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                ">
                    <span style="width: 5px; height: 5px; border-radius: 50%; background: var(--primary);"></span>
                    Smart Recommendation
                </div>
                <p style="margin: 0; font-size: 0.9rem; font-weight: 600; color: var(--text-main); line-height: 1.6;">
                    <?= $c['tip'] ?>
                </p>
            </div>
        </div>

        <?php endforeach; ?>
        
        <?php if (count($adviceCards) === 0): ?>
            <div class="glass-tile" style="grid-column: 1 / -1; padding: 5rem 2rem; text-align: center; border-style: dashed; border-width: 2px;">
                <div style="width: 80px; height: 80px; background: rgba(99, 102, 241, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: var(--primary);">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><path d="M22 10V4h-6"/><path d="M22 4l-10 10"/></svg>
                </div>
                <h3 style="margin-bottom: 0.5rem; font-weight: 700; color: var(--text-main);">No Insights Available</h3>
                <p class="text-muted" style="max-width: 400px; margin: 0 auto;">There isn't enough transaction data to generate personalized advice for this period. Try switching to a Yearly view or adding more records.</p>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- FOOTER CTA -->
<div class="glass-tile" style="margin-top: 5rem; padding: 5rem 2rem; text-align: center; border: none; position: relative; overflow: hidden; background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);">
    <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: url('data:image/svg+xml,%3Csvg%20width=%2220%22%20height=%2220%22%20viewBox=%220%200%2020%2020%22%20xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cpath%20d=%22M0%200h20L0%2020z%22%20fill=%22white%22%20fill-opacity=%220.03%22/%3E%3C/svg%3E'); opacity: 0.4;"></div>
    <div style="position: relative; z-index: 1;">
        <h2 style="color: white; margin-bottom: 1.5rem; font-size: 2.75rem; font-weight: 800; letter-spacing: -0.04em;">Small habits create big wealth.</h2>
        <p style="max-width: 650px; margin: 0 auto 3rem; color: rgba(255, 255, 255, 0.8); font-size: 1.15rem; line-height: 1.7;">
            Master your financial flow by reviewing insights regularly. Consistent tracking creates the clarity needed for long-term growth.
        </p>
        <a href="../dashboard/dashboard.php" class="btn btn-primary" style="background: white; color: var(--primary); padding: 1rem 3rem; font-weight: 800; font-size: 1.05rem; border-radius: 14px; box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
            Go to Dashboard
        </a>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>