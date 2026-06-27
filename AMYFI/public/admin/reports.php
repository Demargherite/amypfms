<?php
/* =====================================================
   admin/reports.php
   PHASE 5 - SYSTEM REPORTS & ANALYTICS
===================================================== */
session_start();
require_once '../../config/db.php';
/** @var mysqli $conn */

require_once '../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/* =====================================================
   FILTER
===================================================== */
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

/* =====================================================
   QUICK TOTALS
===================================================== */
$totalUsers = 0;
$activeUsers = 0;
$newUsers = 0;
$monthlyUsers = 0;

$stmt = $conn->prepare("SELECT COUNT(*) total FROM users WHERE YEAR(created_at) = ?");
$stmt->bind_param("i", $year);
$stmt->execute();
$totalUsers = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

$stmt = $conn->prepare("SELECT COUNT(*) total FROM users WHERE status='active' AND YEAR(created_at) = ?");
$stmt->bind_param("i", $year);
$stmt->execute();
$activeUsers = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

$stmt = $conn->prepare("
SELECT COUNT(*) total
FROM users
WHERE YEAR(created_at) = ?
AND WEEK(created_at,1) = WEEK(CURDATE(),1)
");
$stmt->bind_param("i", $year);
$stmt->execute();
$newUsers = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

$stmt = $conn->prepare("
SELECT COUNT(*) total
FROM users
WHERE YEAR(created_at) = ?
AND MONTH(created_at) = MONTH(CURDATE())
");
$stmt->bind_param("i", $year);
$stmt->execute();
$monthlyUsers = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

/* =====================================================
   MONTHLY USER GROWTH
===================================================== */
$monthLabels = [];
$userGrowth = [];

$stmt = $conn->prepare("
SELECT 
    MONTH(created_at) m,
    COUNT(*) total
FROM users
WHERE YEAR(created_at)=?
GROUP BY MONTH(created_at)
ORDER BY MONTH(created_at)
");
$stmt->bind_param("i", $year);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while($row = $res->fetch_assoc()){
    $data[(int)$row['m']] = (int)$row['total'];
}

for($i=1;$i<=12;$i++){
    $monthLabels[] = date('M', mktime(0,0,0,$i,1));
    $userGrowth[] = $data[$i] ?? 0;
}

/* =====================================================
   USER TYPE DISTRIBUTION
===================================================== */
$catLabels = [];
$catValues = [];

$stmt = $conn->prepare("
SELECT user_type, COUNT(id) total
FROM users
WHERE YEAR(created_at) = ?
GROUP BY user_type
ORDER BY total DESC
");
$stmt->bind_param("i", $year);
$stmt->execute();
$res = $stmt->get_result();

while($row = $res->fetch_assoc()){

    $label = $row['user_type'];

    // Convert database value to nice label
    switch($label){

        case 'student':
            $label = 'Student';
            break;

        case 'worker_single':
            $label = 'Worker (Single)';
            break;

        case 'worker_married':
            $label = 'Worker (Married)';
            break;

        case 'worker_married_children':
            $label = 'Married + Kids';
            break;

        case 'freelancer':
            $label = 'Freelancer';
            break;

        default:
            $label = 'Other';
    }

    $catLabels[] = $label;
    $catValues[] = (int)$row['total'];
}



if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    $html = "
    <h1 style='font-family: sans-serif;'>Admin Analytics Report - $year</h1>
    <hr>
    <h3 style='font-family: sans-serif;'>Quick Totals</h3>
    <table width='100%' border='0' cellspacing='0' cellpadding='8' style='font-family: sans-serif;'>
        <tr>
            <td><strong>Total Users:</strong> " . number_format($totalUsers) . "</td>
            <td><strong>Active Users:</strong> " . number_format($activeUsers) . "</td>
        </tr>
        <tr>
            <td><strong>New Users (Week):</strong> " . number_format($newUsers) . "</td>
            <td><strong>New Users (Month):</strong> " . number_format($monthlyUsers) . "</td>
        </tr>
    </table>
    
    <br><h3 style='font-family: sans-serif;'>Monthly User Growth ($year)</h3>
    <table width='100%' border='1' cellspacing='0' cellpadding='8' style='font-family: sans-serif;'>
        <tr style='background-color: #f1f5f9;'>
            <th align='left'>Month</th>
            <th align='left'>Users Joined</th>
        </tr>";
    
    for ($i = 0; $i < count($monthLabels); $i++) {
        $html .= "<tr><td>" . htmlspecialchars($monthLabels[$i]) . "</td><td>" . number_format($userGrowth[$i]) . "</td></tr>";
    }

    $html .= "
    </table>

    <br><h3 style='font-family: sans-serif;'>User Type Breakdown</h3>
    <table width='100%' border='1' cellspacing='0' cellpadding='8' style='font-family: sans-serif;'>
        <tr style='background-color: #f1f5f9;'>
            <th align='left'>User Type</th>
            <th align='left'>Count</th>
            <th align='left'>Percentage</th>
        </tr>";
    
    $totalCatUsers = array_sum($catValues);
    for ($i = 0; $i < count($catLabels); $i++) {
        $percent = $totalCatUsers > 0 ? round(($catValues[$i] / $totalCatUsers) * 100, 1) : 0;
        $html .= "<tr>
            <td>" . htmlspecialchars($catLabels[$i]) . "</td>
            <td>" . number_format($catValues[$i]) . "</td>
            <td>" . $percent . "%</td>
        </tr>";
    }

    $html .= "
    </table>
    <br><br>
    <p style='font-family: sans-serif; font-size: 12px; color: #666;'>Generated on " . date('d M Y H:i') . "</p>
    ";

    $options = new Options();
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('admin_report.pdf', ['Attachment' => true]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Reports - AMYFI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --warning: #f59e0b;
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

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .filter-select {
            padding: 0.85rem 1.25rem;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            transition: var(--transition);
            min-width: 150px;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            background-color: rgba(15, 23, 42, 0.8);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        .btn-generate {
            padding: 0.85rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: none;
            border-radius: var(--radius-md);
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-generate:hover {
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: center;
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
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.2;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media (min-width: 992px) {
            .chart-grid {
                grid-template-columns: 1.5fr 1fr;
            }
            .full-width {
                grid-column: 1 / -1;
            }
        }

        .chart-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: var(--transition);
        }

        .chart-box:hover {
            border-color: rgba(99, 102, 241, 0.3);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .chart-box h3 {
            margin: 0 0 1.5rem 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-box h3 svg {
            color: var(--primary);
        }

        .canvas-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Breakdown Table Styles */
        .breakdown-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            color: var(--text-main);
            margin-top: 0.5rem;
        }

        .breakdown-table th {
            padding: 1rem 1.5rem;
            text-align: left;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.4);
        }

        .breakdown-table th:first-child {
            border-top-left-radius: var(--radius-md);
        }

        .breakdown-table th:last-child {
            border-top-right-radius: var(--radius-md);
        }

        .breakdown-table td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(51, 65, 85, 0.3);
            vertical-align: middle;
            transition: var(--transition);
        }

        .breakdown-table tbody tr {
            transition: var(--transition);
        }

        .breakdown-table tbody tr:hover {
            background: rgba(15, 23, 42, 0.4);
        }

        .breakdown-table tbody tr:hover td {
            border-bottom-color: rgba(99, 102, 241, 0.4);
        }

        .breakdown-table tbody tr:last-child td {
            border-bottom: none;
        }


        .badge-count {
            display: inline-block;
            padding: 0.35rem 0.85rem;
            background: rgba(99, 102, 241, 0.1);
            color: #818cf8;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.95rem;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

    </style>
</head>
<body>

    <header class="header">
        <div class="header-title">
            <h2>Analytics & Reports</h2>
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
        
        <div class="card">
            <form method="GET" class="filter-form">
                <select name="year" class="filter-select">
                    <?php for($y=date('Y');$y>=2023;$y--): ?>
                        <option value="<?= $y ?>" <?= $year==$y ? 'selected' : '' ?>>
                            Fiscal Year <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn-generate">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    Update View
                </button>
                <a href="?year=<?= $year ?>&download=pdf" class="btn-generate" style="background: linear-gradient(135deg, #10b981, #059669); text-decoration: none;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download PDF
                </a>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Users</h3>
                <h2><?= number_format($totalUsers) ?></h2>
            </div>
            <div class="stat-card">
                <h3>Active Users</h3>
                <h2><?= number_format($activeUsers) ?></h2>
            </div>
            <div class="stat-card">
                <h3>New Users (Week)</h3>
                <h2 style="color: var(--success);"><?= number_format($newUsers) ?></h2>
            </div>
            <div class="stat-card">
    <h3>New Users (Month)</h3>
    <h2 style="color: var(--warning);">
        <?= number_format($monthlyUsers) ?>
    </h2>
</div>
        </div>

        <div class="chart-grid">
            <div class="chart-box full-width">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    User Growth (<?= $year ?>)
                </h3>
                <div class="canvas-container">
                    <canvas id="growthChart"></canvas>
                </div>
            </div>

            <div class="chart-box full-width">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    User Type Distribution
                </h3>
                <div class="canvas-container" style="height: 350px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <div class="chart-box full-width">
                <h3>User Type Breakdown</h3>

                <div style="overflow-x:auto; border-radius: var(--radius-md); border: 1px solid var(--border);">
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th>User Type</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totalCatUsers = array_sum($catValues);
                            for($i=0; $i<count($catLabels); $i++):
                                $count = $catValues[$i];
                                $label = $catLabels[$i];
                                $percent = $totalCatUsers > 0 ? round(($count / $totalCatUsers) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td style="font-weight: 500;"><?= htmlspecialchars($label) ?></td>
                                <td><span class="badge-count"><?= number_format($count) ?></span></td>
                                <td style="color: var(--text-muted); font-weight: 500;"><?= $percent ?>%</td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <script>
        // Set default text colors for elegant dark mode
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.font.family = "'Outfit', sans-serif";
        Chart.defaults.scale.grid.color = 'rgba(51, 65, 85, 0.4)';

        new Chart(document.getElementById('growthChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($monthLabels) ?>,
                datasets: [{
                    label: 'Users Joined',
                    data: <?= json_encode($userGrowth) ?>,
                    backgroundColor: '#6366f1',
                    borderRadius: 6,
                    barPercentage: 0.6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });

        new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($catLabels) ?>,
                datasets: [{
                    data: <?= json_encode($catValues) ?>,
                    backgroundColor: [
                        '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                }
            }
        });


    </script>
</body>
</html>