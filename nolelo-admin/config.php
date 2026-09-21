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
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
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

// Helper function for database operations
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

// Apply the administrator-selected timezone to every request in this portal.
$configuredTimezone = nolelo_setting('timezone', 'Africa/Dar_es_Salaam');
if (@date_default_timezone_set($configuredTimezone) === false) {
    date_default_timezone_set('Africa/Dar_es_Salaam');
}

// ===== ADD THIS FUNCTION =====
function ensureDefaultAdmin() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute(['rashidhassanally0203@gmail.com']);
        $existing = $stmt->fetch();
        $defaultPassword = 'Chidy1032';
        $password = password_hash($defaultPassword, PASSWORD_DEFAULT);

        if ($existing) {
            $needsUpdate = (
                ($existing['full_name'] ?? '') !== 'Super Admin' ||
                ($existing['nickname'] ?? '') !== 'super' ||
                ($existing['phone'] ?? '') !== '0788315183' ||
                ($existing['pin'] ?? '') !== '1032' ||
                ($existing['role'] ?? '') !== 'admin' ||
                ($existing['status'] ?? '') !== 'active' ||
                !password_verify($defaultPassword, $existing['password'] ?? '')
            );

            if ($needsUpdate) {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = 'Super Admin',
                        nickname = 'super',
                        phone = '0788315183',
                        password = ?,
                        pin = '1032',
                        role = 'admin',
                        status = 'active'
                    WHERE id = ?
                ");
                $stmt->execute([$password, $existing['id']]);
            }
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, nickname, phone, email, password, pin, role, status, created_at) 
                VALUES ('Super Admin', 'super', '0788315183', 'rashidhassanally0203@gmail.com', ?, '1032', 'admin', 'active', NOW())
            ");
            $stmt->execute([$password]);
            error_log("Default admin created successfully!");
        }
    } catch (Exception $e) {
        error_log("Error creating default admin: " . $e->getMessage());
    }
}

// Call this after database connection
if (DB_CONNECTED) {
    ensureDefaultAdmin();
}
?>
