<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

if (!DB_CONNECTED) {
    sendJsonResponse(['success' => false, 'message' => 'Database connection failed.'], 503);
}

function buildAdminDashboardSnapshot() {
    $totalUsers = dbFetchOne("SELECT COUNT(*) as count FROM users WHERE status = 'active'") ?: ['count' => 0];
    $totalSites = dbFetchOne("SELECT COUNT(*) as count FROM sites WHERE status = 'active'") ?: ['count' => 0];
    $totalCategories = dbFetchOne("SELECT COUNT(*) as count FROM categories WHERE status = 'active'") ?: ['count' => 0];
    $todayEntries = dbFetchOne("SELECT COUNT(*) as count FROM water_entries WHERE entry_date = CURDATE()") ?: ['count' => 0];
    $weeklyUsage = dbFetchOne("SELECT SUM(units_consumed) as total FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)") ?: ['total' => 0];
    $weeklyRevenue = dbFetchOne("SELECT SUM(units_consumed) as total FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)") ?: ['total' => 0];
    $weeklyRevenue['total'] = (float)($weeklyRevenue['total'] ?? 0) * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);

    $activities = dbFetchAll("
        SELECT
            we.id,
            we.created_at,
            we.morning_reading,
            we.evening_reading,
            we.reading_type,
            u.full_name,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name
        FROM water_entries we
        JOIN users u ON we.user_id = u.id
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        ORDER BY we.created_at DESC, we.id DESC
        LIMIT 5
    ");

    $recentActivities = [];
    foreach ($activities as $activity) {
        $createdAt = $activity['created_at'] ?? null;
        $recentActivities[] = [
            'id' => (int)($activity['id'] ?? 0),
            'full_name' => $activity['full_name'] ?? 'Unknown',
            'site_name' => $activity['site_name'] ?? ($activity['category_name'] ?? 'Unknown'),
            'category_name' => $activity['category_name'] ?? 'Unknown',
            'created_at' => $createdAt,
            'time_label' => $createdAt ? date('H:i', strtotime($createdAt)) : '--:--',
            'morning_reading' => (float)($activity['morning_reading'] ?? 0),
            'evening_reading' => (float)($activity['evening_reading'] ?? 0),
            'reading_type' => $activity['reading_type'] ?? 'both',
        ];
    }

    return [
        'today_entries' => (int)($todayEntries['count'] ?? 0),
        'weekly_usage' => (float)($weeklyUsage['total'] ?? 0),
        'weekly_revenue' => (float)($weeklyRevenue['total'] ?? 0),
        'active_users' => (int)($totalUsers['count'] ?? 0),
        'total_users' => (int)($totalUsers['count'] ?? 0),
        'total_sites' => (int)($totalSites['count'] ?? 0),
        'total_categories' => (int)($totalCategories['count'] ?? 0),
        'recent_activities' => $recentActivities,
    ];
}

function buildAdminEntriesSnapshot(array $filters) {
    global $pdo;

    $whereConditions = [];
    $params = [];

    if (!empty($filters['filter_date'])) {
        $whereConditions[] = "we.entry_date = ?";
        $params[] = $filters['filter_date'];
    }

    if (!empty($filters['filter_worker'])) {
        $whereConditions[] = "we.user_id = ?";
        $params[] = (int)$filters['filter_worker'];
    }

    if (!empty($filters['filter_site'])) {
        $whereConditions[] = "we.site_id = ?";
        $params[] = (int)$filters['filter_site'];
    }

    if (!empty($filters['filter_status'])) {
        $whereConditions[] = "we.status = ?";
        $params[] = $filters['filter_status'];
    }

    $whereSql = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    $entries = dbFetchAll("
        SELECT
            we.id,
            we.entry_date,
            DATE_FORMAT(we.entry_date, '%Y-%m-%d') as entry_date_formatted,
            we.morning_reading,
            we.evening_reading,
            we.reading_type,
            we.notes,
            we.created_at,
            we.updated_at,
            we.units_consumed,
            we.cost,
            we.status,
            u.full_name as worker_name,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name
        FROM water_entries we
        JOIN users u ON we.user_id = u.id
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        $whereSql
        ORDER BY we.entry_date DESC, we.created_at DESC
        LIMIT 100
    ", $params);

    $rows = [];
    foreach ($entries as $entry) {
        $rows[] = [
            'id' => (int)($entry['id'] ?? 0),
            'entry_date_formatted' => $entry['entry_date_formatted'] ?? '',
            'worker_name' => $entry['worker_name'] ?? 'Unknown',
            'site_name' => $entry['site_name'] ?? ($entry['category_name'] ?? 'Unknown'),
            'category_name' => $entry['category_name'] ?? 'Unknown',
            'morning_reading' => (float)($entry['morning_reading'] ?? 0),
            'evening_reading' => (float)($entry['evening_reading'] ?? 0),
            'reading_type' => $entry['reading_type'] ?? 'both',
            'notes' => $entry['notes'] ?? '',
            'created_at' => $entry['created_at'] ?? '',
            'updated_at' => $entry['updated_at'] ?? '',
            'units_consumed' => (float)($entry['units_consumed'] ?? 0),
            'cost' => (float)($entry['units_consumed'] ?? 0) * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE),
            'status' => $entry['status'] ?? 'pending',
        ];
    }

    $totalUnits = array_sum(array_map(static function ($value) {
        return (float)$value;
    }, array_column($rows, 'units_consumed')));

    $totalCost = array_sum(array_map(static function ($value) {
        return (float)$value;
    }, array_column($rows, 'cost')));

    return [
        'total_entries' => count($rows),
        'total_units' => $totalUnits,
        'total_cost' => $totalCost,
        'rows' => $rows,
    ];
}

try {
    $latestEntry = dbFetchOne("SELECT id FROM water_entries ORDER BY created_at DESC, id DESC LIMIT 1");
    $page = $_GET['page'] ?? 'dashboard';

    $payload = [
        'success' => true,
        'page' => $page,
        'latest_entry_id' => (int)($latestEntry['id'] ?? 0),
        'dashboard' => buildAdminDashboardSnapshot(),
        'entries' => buildAdminEntriesSnapshot($_GET),
        'server_time' => date('Y-m-d H:i:s'),
    ];

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    sendJsonResponse($payload);
} catch (Throwable $e) {
    error_log('Realtime sync failed: ' . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Unable to load realtime data.',
    ], 500);
}
