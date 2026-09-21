<?php
if (ob_get_level() === 0) {
    ob_start();
}
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'nolelo_water');
define('DB_USER', 'root');
define('DB_PASS', '');

// Application Settings
define('APP_NAME', 'NOLELO Clean Water Supply');
define('APP_VERSION', '2.0.1');
define('CURRENCY', 'TSh');
define('DAWASCO_RATE', 1500);

// Create PDO connection
$pdo = null;
$db_connected = false;
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db_connected = true;
} catch(PDOException $e) {
    $db_connected = false;
    error_log("Database connection failed: " . $e->getMessage());
}
define('DB_CONNECTED', $db_connected);

function ensureActivityLogTable() {
    global $pdo;
    if (!$pdo instanceof PDO) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS worker_activity_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NULL,
        event_type VARCHAR(50) NOT NULL,
        description VARCHAR(255) NOT NULL,
        metadata TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_activity_user (user_id),
        KEY idx_activity_event (event_type),
        KEY idx_activity_created (created_at),
        CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function logWorkerActivity($userId, $eventType, $description, array $metadata = []) {
    $result = dbInsert('worker_activity_logs', [
        'user_id' => $userId ? (int)$userId : null,
        'event_type' => substr((string)$eventType, 0, 50),
        'description' => substr((string)$description, 0, 255),
        'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
    return $result !== false;
}

if (DB_CONNECTED) ensureActivityLogTable();

// Helper functions
function dbQuery($sql, $params = []) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        error_log('Database query skipped: database connection is unavailable.');
        return false;
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch(PDOException $e) {
        error_log("Database error: " . $e->getMessage() . " SQL: " . $sql);
        return false;
    }
}

function dbFetchAll($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt ? $stmt->fetchAll() : [];
}

function dbFetchOne($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt ? $stmt->fetch() : null;
}

function dbInsert($table, $data) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        error_log('Database insert skipped: database connection is unavailable.');
        return false;
    }
    $fields = array_keys($data);
    $placeholders = ':' . implode(', :', $fields);
    $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES ($placeholders)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return $pdo->lastInsertId();
    } catch(PDOException $e) {
        error_log("Insert error: " . $e->getMessage());
        return false;
    }
}

function dbUpdate($table, $data, $where, $whereParams = []) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        error_log('Database update skipped: database connection is unavailable.');
        return false;
    }
    $sets = [];
    foreach ($data as $key => $value) {
        $sets[] = "$key = :$key";
    }
    $sql = "UPDATE $table SET " . implode(', ', $sets) . " WHERE $where";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($data, $whereParams));
        return $stmt->rowCount();
    } catch(PDOException $e) {
        error_log("Update error: " . $e->getMessage());
        return false;
    }
}

function dbDelete($table, $where, $params = []) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        error_log('Database delete skipped: database connection is unavailable.');
        return false;
    }
    $sql = "DELETE FROM $table WHERE $where";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch(PDOException $e) {
        error_log("Delete error: " . $e->getMessage());
        return false;
    }
}

function sendJsonResponse($payload, $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!function_exists('nolelo_worker_json_response')) {
    function nolelo_worker_json_response(array $payload, $statusCode = 200) {
        sendJsonResponse($payload, $statusCode);
    }
}

function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function getSettings($key = null) {
    if ($key) {
        $result = dbFetchOne("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
        return $result ? $result['setting_value'] : null;
    }
    $results = dbFetchAll("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    foreach ($results as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function nolelo_setting($key, $default = null) {
    static $settings = null;
    if ($settings === null) {
        $settings = getSettings();
    }
    return array_key_exists($key, $settings) && $settings[$key] !== '' ? $settings[$key] : $default;
}

// Keep worker dates, timestamps, and default forms aligned with admin settings.
$configuredTimezone = nolelo_setting('timezone', 'Africa/Dar_es_Salaam');
if (@date_default_timezone_set($configuredTimezone) === false) {
    date_default_timezone_set('Africa/Dar_es_Salaam');
}

// Get worker data from session
function getWorkerData() {
    if (isset($_SESSION['user_id'])) {
        return dbFetchOne("SELECT id, full_name, nickname, phone, email, role, status, deleted_at FROM users WHERE id = ?", [$_SESSION['user_id']]);
    }
    return null;
}

// Get worker assignments
function getWorkerAssignments($user_id) {
    $assignedCategories = dbFetchAll("
        SELECT c.id, c.name FROM user_categories uc 
        JOIN categories c ON uc.category_id = c.id 
        WHERE uc.user_id = ? AND c.status = 'active'
    ", [$user_id]);

    $assignedSites = dbFetchAll("
        SELECT s.id, s.name FROM user_sites us 
        JOIN sites s ON us.site_id = s.id 
        WHERE us.user_id = ? AND s.status = 'active'
    ", [$user_id]);

    return [
        'categories' => $assignedCategories,
        'sites' => $assignedSites,
        'assigned_categories' => $assignedCategories,
        'assigned_sites' => $assignedSites,
        'has_category_assignment' => !empty($assignedCategories),
        'has_site_assignment' => !empty($assignedSites),
        'is_full_access' => false,
    ];
}

function getWorkerAccessProfile($user_id) {
    return getWorkerAssignments($user_id);
}

// Get last entries for worker
function getLastEntries($user_id) {
    return dbFetchAll("
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
        LIMIT 5
    ", [$user_id]);
}

function getLatestEntryForCategoryIds($user_id, array $categoryIds) {
    if (empty($categoryIds)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $params = array_merge([$user_id], array_values($categoryIds));

    return dbFetchOne("
        SELECT
            we.*,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name
        FROM water_entries we
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        WHERE we.user_id = ? AND we.category_id IN ($placeholders)
        ORDER BY we.entry_date DESC, we.created_at DESC
        LIMIT 1
    ", $params);
}

function getLatestEntryForSiteIds($user_id, array $siteIds) {
    if (empty($siteIds)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
    $params = array_merge([$user_id], array_values($siteIds));

    return dbFetchOne("
        SELECT
            we.*,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name
        FROM water_entries we
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        WHERE we.user_id = ? AND we.site_id IN ($placeholders)
        ORDER BY we.entry_date DESC, we.created_at DESC
        LIMIT 1
    ", $params);
}

// Get team entries (all workers in same sites)
function getTeamEntries($user_id) {
    // Get sites assigned to this worker
    $sites = dbFetchAll("SELECT site_id FROM user_sites WHERE user_id = ?", [$user_id]);
    $site_ids = array_column($sites, 'site_id');
    
    if (empty($site_ids)) {
        $site_ids = array_column(dbFetchAll("SELECT id FROM sites WHERE status = 'active' ORDER BY id"), 'id');
        if (empty($site_ids)) {
            return [];
        }
    }
    
    $placeholders = implode(',', array_fill(0, count($site_ids), '?'));
    $params = array_merge([$user_id], $site_ids);
    
    return dbFetchAll("
        SELECT
            we.*,
            u.full_name as worker_name,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name
        FROM water_entries we
        JOIN users u ON we.user_id = u.id
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        WHERE we.user_id != ? AND we.site_id IN ($placeholders)
        ORDER BY we.entry_date DESC, we.created_at DESC
        LIMIT 20
    ", $params);
}

function ensureDefaultWorker() {
    global $pdo;
    try {
        $defaults = [
            'full_name' => 'Grace Mushi',
            'nickname' => 'grace',
            'phone' => '+255 712 345 678',
            'email' => 'grace@nolelo.tz',
            'pin' => '9012',
            'role' => 'worker',
            'status' => 'active',
        ];

        $password = password_hash($defaults['pin'], PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$defaults['email']]);
        $worker = $stmt->fetch();

        // Existing worker records are user-managed. Never overwrite profile
        // values during a normal request; only create the demo account once.
        if (!$worker) {
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, nickname, phone, email, password, pin, role, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $defaults['full_name'],
                $defaults['nickname'],
                $defaults['phone'],
                $defaults['email'],
                $password,
                $defaults['pin'],
                $defaults['role'],
                $defaults['status']
            ]);
        }

    } catch (Exception $e) {
        error_log("Error creating default worker: " . $e->getMessage());
    }
}

if (DB_CONNECTED) {
    ensureDefaultWorker();
}
?>
