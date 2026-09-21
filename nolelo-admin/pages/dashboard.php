<style>
.dashboard-welcome {
    background: linear-gradient(135deg, #0b2b3f, #1a5a7a);
    border-radius: 20px;
    padding: 2rem;
    color: white;
    margin-bottom: 2rem;
}
.welcome-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}
.welcome-stat {
    background: rgba(255,255,255,0.1);
    border-radius: 16px;
    padding: 1rem;
    text-align: center;
    backdrop-filter: blur(10px);
}
.welcome-stat .number { font-size: 2rem; font-weight: 700; }
.welcome-stat .label { font-size: 0.8rem; opacity: 0.8; }
.quick-action {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid transparent;
}
.quick-action:hover {
    border-color: #1b5a7a;
    transform: translateY(-5px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.08);
}
.quick-action i { font-size: 2rem; color: #1b5a7a; }
.chart-container { position: relative; height: 180px; }
</style>

<?php
// Admin check
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: index.php');
    exit;
}
// ... rest of your code ...

// Get real data from database
$total_users = dbFetchOne("SELECT COUNT(*) as count FROM users WHERE status = 'active'")['count'] ?? 0;
$total_sites = dbFetchOne("SELECT COUNT(*) as count FROM sites WHERE status = 'active'")['count'] ?? 0;
$total_categories = dbFetchOne("SELECT COUNT(*) as count FROM categories WHERE status = 'active'")['count'] ?? 0;
$today_entries = dbFetchOne("SELECT COUNT(*) as count FROM water_entries WHERE entry_date = CURDATE()")['count'] ?? 0;
$total_usage = dbFetchOne("SELECT SUM(units_consumed) as total FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['total'] ?? 0;
$total_revenue_units = dbFetchOne("SELECT SUM(units_consumed) as total FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['total'] ?? 0;
$total_revenue = (float)$total_revenue_units * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);

// Get recent activity
$recent_activities = dbFetchAll("
    SELECT u.full_name, we.entry_date, we.created_at, we.morning_reading, we.evening_reading, s.name as site_name
    FROM water_entries we
    JOIN users u ON we.user_id = u.id
    JOIN sites s ON we.site_id = s.id
    ORDER BY we.created_at DESC
    LIMIT 5
");

// Build chart series from recorded usage, including zeroes for empty periods.
$dailyChartLabels = [];
$dailyChartValues = [];
$dailyRows = dbFetchAll("SELECT entry_date, COALESCE(SUM(units_consumed), 0) AS total FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY entry_date");
$dailyMap = [];
foreach ($dailyRows as $row) {
    $dailyMap[$row['entry_date']] = (float)$row['total'];
}
$today = new DateTime('today');
for ($offset = 6; $offset >= 0; $offset--) {
    $day = (clone $today)->modify("-$offset days");
    $key = $day->format('Y-m-d');
    $dailyChartLabels[] = $day->format('D');
    $dailyChartValues[] = $dailyMap[$key] ?? 0;
}

$weeklyChartLabels = [];
$weeklyChartValues = [];
$weeklyRows = dbFetchAll("SELECT YEARWEEK(entry_date, 1) AS week_key, COALESCE(SUM(units_consumed), 0) AS total FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 27 DAY) GROUP BY YEARWEEK(entry_date, 1) ORDER BY week_key");
$weeklyMap = [];
foreach ($weeklyRows as $row) {
    $weeklyMap[(string)$row['week_key']] = (float)$row['total'];
}
$weekStart = (clone $today)->modify('monday this week');
for ($offset = 3; $offset >= 0; $offset--) {
    $start = (clone $weekStart)->modify("-$offset weeks");
    $weeklyChartLabels[] = $start->format('d M');
    $weeklyChartValues[] = $weeklyMap[$start->format('oW')] ?? 0;
}

$monthlyChartLabels = [];
$monthlyChartValues = [];
$monthlyRows = dbFetchAll("SELECT DATE_FORMAT(entry_date, '%Y-%m') AS month_key, COALESCE(SUM(units_consumed), 0) AS total FROM water_entries WHERE entry_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') GROUP BY DATE_FORMAT(entry_date, '%Y-%m') ORDER BY month_key");
$monthlyMap = [];
foreach ($monthlyRows as $row) {
    $monthlyMap[$row['month_key']] = (float)$row['total'];
}
$monthStart = new DateTime('first day of this month');
for ($offset = 5; $offset >= 0; $offset--) {
    $month = (clone $monthStart)->modify("-$offset months");
    $monthlyChartLabels[] = $month->format('M Y');
    $monthlyChartValues[] = $monthlyMap[$month->format('Y-m')] ?? 0;
}
?>

<div class="dashboard-welcome">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h2 class="fw-bold">Welcome back, Admin! 👋</h2>
            <p class="opacity-75">Here's what's happening with your water supply system today.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <span class="badge-dawasco" style="background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1.5rem;">
                <i class="bi bi-calendar3 me-1"></i> <?php echo date('l, d M Y'); ?>
            </span>
        </div>
    </div>
    <div class="welcome-stats">
        <div class="welcome-stat">
            <div class="number"><?php echo $today_entries; ?></div>
            <div class="label">Today's Entries</div>
        </div>
        <div class="welcome-stat">
            <div class="number"><?php echo number_format($total_usage, 1); ?>m³</div>
            <div class="label">Weekly Usage</div>
        </div>
        <div class="welcome-stat">
            <div class="number"><?php echo number_format($total_revenue); ?></div>
            <div class="label">Revenue (TSh)</div>
        </div>
        <div class="welcome-stat">
            <div class="number"><?php echo $total_users; ?></div>
            <div class="label">Active Users</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="location.href='index.php?page=users'">
            <i class="bi bi-person-plus"></i>
            <div class="fw-semibold mt-2">Add User</div>
            <div class="text-muted-small">Register new user</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="location.href='index.php?page=entries'">
            <i class="bi bi-clipboard-plus"></i>
            <div class="fw-semibold mt-2">Add Entry</div>
            <div class="text-muted-small">Record water reading</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="location.href='index.php?page=bills'">
            <i class="bi bi-receipt"></i>
            <div class="fw-semibold mt-2">Generate Bill</div>
            <div class="text-muted-small">Create invoice</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="location.href='index.php?page=reports'">
            <i class="bi bi-graph-up"></i>
            <div class="fw-semibold mt-2">View Reports</div>
            <div class="text-muted-small">Analytics & insights</div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card-stat d-flex align-items-center">
            <div class="stat-icon me-3"><i class="bi bi-people fs-5"></i></div>
            <div><span class="text-muted-small">Users</span><h5 class="mb-0 fw-bold"><?php echo $total_users; ?></h5></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-stat d-flex align-items-center">
            <div class="stat-icon me-3"><i class="bi bi-boxes fs-5"></i></div>
            <div><span class="text-muted-small">Sites</span><h5 class="mb-0 fw-bold"><?php echo $total_sites; ?></h5></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-stat d-flex align-items-center">
            <div class="stat-icon me-3"><i class="bi bi-tags fs-5"></i></div>
            <div><span class="text-muted-small">Categories</span><h5 class="mb-0 fw-bold"><?php echo $total_categories; ?></h5></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-stat d-flex align-items-center">
            <div class="stat-icon me-3"><i class="bi bi-calendar-check fs-5"></i></div>
            <div><span class="text-muted-small">Today entries</span><h5 class="mb-0 fw-bold"><?php echo $today_entries; ?></h5></div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 h-100">
            <h6 class="fw-semibold"><i class="bi bi-calendar-day me-1"></i> Daily usage (m³)</h6>
            <div class="chart-container"><canvas id="dailyChart"></canvas></div>
            <div class="text-muted-small mt-1">Last 7 days: <?php echo number_format(array_sum($dailyChartValues), 1); ?> m³</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 h-100">
            <h6 class="fw-semibold"><i class="bi bi-calendar-week me-1"></i> Weekly trend</h6>
            <div class="chart-container"><canvas id="weeklyChart"></canvas></div>
            <div class="text-muted-small mt-1">Last 4 weeks: <?php echo number_format(array_sum($weeklyChartValues), 1); ?> m³</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 h-100">
            <h6 class="fw-semibold"><i class="bi bi-calendar-month me-1"></i> Monthly overview</h6>
            <div class="chart-container"><canvas id="monthlyChart"></canvas></div>
            <div class="text-muted-small mt-1">Last 6 months: <?php echo number_format(array_sum($monthlyChartValues), 1); ?> m³</div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="card border-0 shadow-sm p-3">
    <h5 class="fw-semibold mb-3"><i class="bi bi-clock-history me-2" style="color:#1b5a7a;"></i>Recent Activity</h5>
    <div class="activity-timeline">
        <?php foreach ($recent_activities as $activity): ?>
        <div class="activity-item">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold"><?php echo htmlspecialchars($activity['full_name']); ?></span> 
                <span class="time"><?php echo date('H:i', strtotime($activity['created_at'] ?? $activity['entry_date'])); ?></span>
            </div>
            <div class="text-muted-small">
                Reading · <?php echo htmlspecialchars($activity['site_name']); ?> · 
                Morning: <?php echo $activity['morning_reading']; ?> m³ · 
                Evening: <?php echo $activity['evening_reading']; ?> m³
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dailyLabels = <?php echo json_encode($dailyChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const dailyValues = <?php echo json_encode($dailyChartValues); ?>;
    const weeklyLabels = <?php echo json_encode($weeklyChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const weeklyValues = <?php echo json_encode($weeklyChartValues); ?>;
    const monthlyLabels = <?php echo json_encode($monthlyChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const monthlyValues = <?php echo json_encode($monthlyChartValues); ?>;

    // Daily Chart
    new Chart(document.getElementById('dailyChart'), {
        type: 'bar',
        data: { 
            labels: dailyLabels,
            datasets: [{ 
                label: 'm³', 
                data: dailyValues,
                backgroundColor: 'rgba(27, 90, 122, 0.8)',
                borderColor: '#1b5a7a',
                borderWidth: 1,
                borderRadius: 6
            }] 
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    // Weekly Chart
    new Chart(document.getElementById('weeklyChart'), {
        type: 'line',
        data: { 
            labels: weeklyLabels,
            datasets: [{ 
                label: 'm³', 
                data: weeklyValues,
                borderColor: '#1b5a7a',
                backgroundColor: 'rgba(27, 90, 122, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#1b5a7a',
                pointBorderColor: 'white',
                pointBorderWidth: 2
            }] 
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    // Monthly Chart
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: { 
            labels: monthlyLabels,
            datasets: [{ 
                label: 'm³', 
                data: monthlyValues,
                backgroundColor: 'rgba(79, 195, 247, 0.8)',
                borderColor: '#4fc3f7',
                borderWidth: 1,
                borderRadius: 6
            }] 
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
});
</script>
