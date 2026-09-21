<?php
if (!function_exists('nolelo_worker_dashboard_escape')) {
    function nolelo_worker_dashboard_escape($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nolelo_worker_dashboard_time')) {
    function nolelo_worker_dashboard_time(array $entry = null) {
        if (!$entry || empty($entry['created_at'])) {
            return 'No entries yet';
        }

        return date('d M Y, h:i A', strtotime($entry['created_at']));
    }
}

$worker = getWorkerData();
$nickname = $worker['nickname'] ?? '';
$display_name = !empty($nickname) ? $nickname : explode(' ', $worker['full_name'] ?? 'Worker')[0];
$assignments = getWorkerAssignments($_SESSION['user_id']);

$category_ids = array_map('intval', array_column(
    !empty($assignments['assigned_categories']) ? $assignments['assigned_categories'] : $assignments['categories'],
    'id'
));
$site_ids = array_map('intval', array_column(
    !empty($assignments['assigned_sites']) ? $assignments['assigned_sites'] : $assignments['sites'],
    'id'
));

$my_entries = dbFetchOne("SELECT COUNT(*) as count FROM water_entries WHERE user_id = ?", [$_SESSION['user_id']]);
$total_units = dbFetchOne("SELECT COALESCE(SUM(units_consumed), 0) as total FROM water_entries WHERE user_id = ?", [$_SESSION['user_id']]);
$total_cost = dbFetchOne("SELECT COALESCE(SUM(units_consumed), 0) as total FROM water_entries WHERE user_id = ?", [$_SESSION['user_id']]);
$total_cost['total'] = (float)($total_cost['total'] ?? 0) * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
$today_entries = dbFetchOne("SELECT COUNT(*) as count FROM water_entries WHERE user_id = ? AND entry_date = CURDATE()", [$_SESSION['user_id']]);
$recent_entries = getLastEntries($_SESSION['user_id']);

$latest_category_entry = !empty($category_ids) ? getLatestEntryForCategoryIds($_SESSION['user_id'], $category_ids) : null;
$latest_site_entry = !empty($site_ids) ? getLatestEntryForSiteIds($_SESSION['user_id'], $site_ids) : null;
$latest_overall_entry = dbFetchOne("
    SELECT
        we.*,
        s.name as site_name,
        COALESCE(c_entry.name, c_site.name) as category_name
    FROM water_entries we
    LEFT JOIN sites s ON we.site_id = s.id
    LEFT JOIN categories c_entry ON we.category_id = c_entry.id
    LEFT JOIN categories c_site ON s.category_id = c_site.id
    WHERE we.user_id = ?
    ORDER BY we.entry_date DESC, we.created_at DESC
    LIMIT 1
", [$_SESSION['user_id']]);
?>

<style>
.dashboard-hero {
    background: linear-gradient(135deg, #0b2b3f 0%, #1a5a7a 100%);
    border-radius: 24px;
    padding: 2rem;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.dashboard-hero::after {
    content: '';
    position: absolute;
    right: -80px;
    top: -80px;
    width: 280px;
    height: 280px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,195,247,0.18), transparent 70%);
}
.dashboard-title {
    font-size: 1.85rem;
    font-weight: 800;
}
.dashboard-subtitle {
    opacity: 0.85;
    margin-top: 0.35rem;
}
.mini-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.15);
    color: #fff;
    margin: 0.2rem 0.25rem 0 0;
    font-size: 0.8rem;
    backdrop-filter: blur(8px);
}
.summary-card {
    background: #fff;
    border-radius: 18px;
    padding: 1rem 1.15rem;
    border: 1px solid rgba(27,90,122,0.08);
    box-shadow: 0 4px 18px rgba(0,0,0,0.04);
    height: 100%;
}
.summary-card .label {
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #7e99ae;
    font-weight: 700;
}
.summary-card .value {
    font-size: 1.55rem;
    font-weight: 800;
    color: #0b2b3f;
}
.latest-card {
    background: #fff;
    border-radius: 18px;
    padding: 1.2rem 1.25rem;
    box-shadow: 0 4px 18px rgba(0,0,0,0.04);
    border: 1px solid rgba(27,90,122,0.08);
    border-left: 5px solid #1b5a7a;
    height: 100%;
}
.latest-card.category { border-left-color: #1b5a7a; }
.latest-card.site { border-left-color: #ffa726; }
.latest-card.overall { border-left-color: #66bb6a; }
.latest-card .entry-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #1b5a7a;
}
.latest-card .entry-meta {
    font-size: 0.82rem;
    color: #7e99ae;
}
.recent-feed {
    background: #fff;
    border-radius: 18px;
    padding: 1rem 1.1rem;
    box-shadow: 0 4px 18px rgba(0,0,0,0.04);
    border: 1px solid rgba(27,90,122,0.08);
}
.recent-feed .feed-row {
    display: grid;
    grid-template-columns: 130px 1fr 110px 100px;
    gap: 0.75rem;
    align-items: center;
    padding: 0.9rem 0;
    border-bottom: 1px solid #edf2f7;
}
.recent-feed .feed-row:last-child {
    border-bottom: none;
}
.recent-feed .feed-date {
    font-weight: 700;
    color: #0b2b3f;
}
.recent-feed .feed-target {
    font-weight: 600;
    color: #1a3f55;
}
.recent-feed .feed-meta {
    font-size: 0.82rem;
    color: #7e99ae;
}
.recent-feed .feed-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.7rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    background: #eef5fb;
    color: #1b5a7a;
}
.recent-feed .feed-value {
    font-weight: 800;
    color: #1b5a7a;
    text-align: right;
}
.entry-badge {
    padding: 0.2rem 0.75rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
}
.entry-badge.morning { background: #d2e7f5; color: #0b2b3f; }
.entry-badge.evening { background: #ffe082; color: #7a5a00; }
.quick-action {
    background: #fff;
    border-radius: 18px;
    padding: 1.4rem;
    text-align: center;
    transition: all 0.25s ease;
    cursor: pointer;
    border: 1px solid rgba(27,90,122,0.08);
    box-shadow: 0 4px 18px rgba(0,0,0,0.04);
    height: 100%;
}
.quick-action:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    border-color: #1b5a7a;
}
.quick-action i {
    font-size: 2rem;
    color: #1b5a7a;
    background: linear-gradient(135deg, #e3f0fa, #c5dce8);
    padding: 0.85rem;
    border-radius: 16px;
}
.quick-action .label {
    font-weight: 700;
    margin-top: 0.65rem;
}
@media (max-width: 767.98px) {
    .dashboard-hero { padding: 1.3rem; }
    .dashboard-title { font-size: 1.35rem; }
    .recent-feed .feed-row {
        grid-template-columns: 1fr;
        gap: 0.35rem;
    }
    .recent-feed .feed-value {
        text-align: left;
    }
}
</style>

<div class="dashboard-hero">
    <div class="row align-items-center g-3">
        <div class="col-lg-8">
            <div class="dashboard-title"><i class="bi bi-hand-thumbs-up me-2"></i>Welcome back, <?php echo nolelo_worker_dashboard_escape($display_name); ?></div>
            <div class="dashboard-subtitle">Here is your live water reading summary for today.</div>
            <div class="mt-3">
                <span class="mini-chip"><i class="bi bi-clipboard-data"></i> <?php echo (int)($my_entries['count'] ?? 0); ?> entries</span>
                <span class="mini-chip"><i class="bi bi-droplet"></i> <?php echo number_format((float)($total_units['total'] ?? 0), 1); ?> m3</span>
<span class="mini-chip"><i class="bi bi-currency-exchange"></i> <?php echo number_format((float)($total_cost['total'] ?? 0)); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></span>
            </div>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="mini-chip"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></div>
            <?php foreach (($assignments['assigned_categories'] ?: $assignments['categories']) as $category): ?>
                <div class="mini-chip"><i class="bi bi-tags"></i> <?php echo nolelo_worker_dashboard_escape($category['name']); ?></div>
            <?php endforeach; ?>
            <?php foreach (($assignments['assigned_sites'] ?: $assignments['sites']) as $site): ?>
                <div class="mini-chip"><i class="bi bi-geo-alt"></i> <?php echo nolelo_worker_dashboard_escape($site['name']); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="summary-card">
            <div class="label">My Entries</div>
            <div class="value"><?php echo (int)($my_entries['count'] ?? 0); ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="summary-card">
            <div class="label">Total Units</div>
            <div class="value"><?php echo number_format((float)($total_units['total'] ?? 0), 1); ?> m3</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="summary-card">
            <div class="label">Total Cost</div>
<div class="value"><?php echo number_format((float)($total_cost['total'] ?? 0)); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="summary-card">
            <div class="label">Today Entries</div>
            <div class="value"><?php echo (int)($today_entries['count'] ?? 0); ?></div>
        </div>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h5 class="fw-semibold mb-0"><i class="bi bi-clock-history me-2" style="color:#1b5a7a;"></i>My Recent Entries</h5>
    <div class="text-muted-small">Only your own records are shown here.</div>
</div>

<div class="recent-feed mb-4">
    <?php if (!empty($recent_entries)): ?>
        <?php foreach ($recent_entries as $entry): ?>
            <?php
                $scopeLabel = !empty($entry['site_id']) ? 'Site' : 'Category';
                $targetLabel = $entry['site_name'] ?? ($entry['category_name'] ?? 'Reading');
                $readingLabel = ucfirst((string)($entry['reading_type'] ?? 'morning'));
                $entryDate = !empty($entry['entry_date']) ? date('d M Y', strtotime($entry['entry_date'])) : date('d M Y');
                $entryTime = !empty($entry['created_at']) ? date('h:i A', strtotime($entry['created_at'])) : '';
            ?>
            <div class="feed-row">
                <div>
                    <div class="feed-date"><?php echo nolelo_worker_dashboard_escape($entryDate); ?></div>
                    <div class="feed-meta"><?php echo nolelo_worker_dashboard_escape($entryTime); ?></div>
                </div>
                <div>
                    <div class="feed-target"><?php echo nolelo_worker_dashboard_escape($targetLabel); ?></div>
                    <div class="feed-meta"><?php echo nolelo_worker_dashboard_escape($scopeLabel); ?></div>
                </div>
                <div>
                    <span class="feed-badge"><?php echo nolelo_worker_dashboard_escape($readingLabel); ?></span>
                </div>
                <div class="feed-value"><?php echo number_format((float)($entry['units_consumed'] ?? 0), 1); ?> m3</div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            You have no recorded entries yet.
        </div>
    <?php endif; ?>
</div>

<h5 class="fw-semibold mb-3"><i class="bi bi-lightning me-2" style="color:#ffa726;"></i>Quick Actions</h5>
<div class="row g-3">
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="window.location.href='index.php?page=entries'">
            <i class="bi bi-clipboard-plus"></i>
            <div class="label">Enter Reading</div>
            <div class="text-muted-small">Record category or site readings</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="window.location.href='index.php?page=history'">
            <i class="bi bi-clock-history"></i>
            <div class="label">View History</div>
            <div class="text-muted-small">See your own recordings</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="window.location.href='index.php?page=bills'">
            <i class="bi bi-receipt"></i>
            <div class="label">My Bills</div>
            <div class="text-muted-small">View bill history</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="quick-action" onclick="window.location.href='index.php?page=profile'">
            <i class="bi bi-person-circle"></i>
            <div class="label">Profile</div>
            <div class="text-muted-small">Manage your account</div>
        </div>
    </div>
</div>
