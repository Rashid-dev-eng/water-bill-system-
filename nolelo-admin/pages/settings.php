<style>
.setting-group {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}
.setting-group:hover {
    border-color: #1b5a7a;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}
.setting-group .group-title {
    font-weight: 600;
    color: #1a3f55;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9eff4;
}
.toast-notification {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 9999;
    min-width: 300px;
    max-width: 500px;
    animation: slideIn 0.5s ease;
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.toast-notification.hide {
    animation: slideOut 0.5s ease forwards;
}
@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9998;
    display: none;
    align-items: center;
    justify-content: center;
}
.loading-overlay.show { display: flex; }
.spinner-border {
    width: 3rem;
    height: 3rem;
}
</style>

<?php
// Admin check
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        sendJsonResponse(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }
    header('Location: index.php');
    exit;
}
// ... rest of your code ...

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Unknown action'];
    $action = $_POST['action'] ?? '';
    
    try {
        global $pdo;
        
        if ($action === 'save_settings') {
            $rate = filter_var($_POST['dawasco_rate'] ?? null, FILTER_VALIDATE_FLOAT);
            $sessionTimeout = filter_var($_POST['session_timeout'] ?? null, FILTER_VALIDATE_INT);
            $maxAttempts = filter_var($_POST['max_attempts'] ?? null, FILTER_VALIDATE_INT);
            $defaultReading = $_POST['default_reading'] ?? 'both';
            $timezone = $_POST['timezone'] ?? 'Africa/Dar_es_Salaam';
            $allowedTimezones = ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Addis_Ababa', 'UTC'];

            if ($rate === false || $rate < 0) {
                throw new Exception('DAWASCO rate must be a valid non-negative number.');
            }
            if ($sessionTimeout === false || $sessionTimeout < 1 || $sessionTimeout > 1440) {
                throw new Exception('Session timeout must be between 1 and 1440 minutes.');
            }
            if ($maxAttempts === false || $maxAttempts < 1 || $maxAttempts > 20) {
                throw new Exception('Maximum login attempts must be between 1 and 20.');
            }
            if (!in_array($defaultReading, ['morning', 'evening', 'both'], true)) {
                throw new Exception('Invalid default reading type.');
            }
            if (!in_array($timezone, $allowedTimezones, true)) {
                throw new Exception('Invalid timezone selected.');
            }

            $settings = [
                'dawasco_rate' => (string)$rate,
                'currency' => trim($_POST['currency'] ?? 'TSh'),
                'app_name' => trim($_POST['app_name'] ?? 'NOLELO Clean Water Supply'),
                'default_reading_type' => $defaultReading,
                'timezone' => $timezone,
                'session_timeout' => (string)$sessionTimeout,
                'max_login_attempts' => (string)$maxAttempts,
                'email_notifications' => isset($_POST['email_notifications']) ? 'true' : 'false',
                'maintenance_mode' => isset($_POST['maintenance_mode']) ? 'true' : 'false'
            ];

            if ($settings['currency'] === '' || $settings['app_name'] === '') {
                throw new Exception('Currency and organization name are required.');
            }

            $pdo->beginTransaction();
            try {
                foreach ($settings as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, updated_by) VALUES (?, ?, 'system', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
                    $stmt->execute([$key, $value, (int)($_SESSION['user_id'] ?? 0)]);
                }
                $pdo->commit();
            } catch (Throwable $saveError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $saveError;
            }
            $response['success'] = true;
            $response['message'] = 'Settings saved successfully and applied system-wide.';
        } else {
            throw new Exception('Invalid action: ' . $action);
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    sendJsonResponse($response);
}

// Get current settings
$settings = getSettings();
?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<h4><i class="bi bi-gear me-2" style="color:#1b5a7a;"></i>Settings</h4>
<p class="text-muted-small">System settings and preferences.</p>

<form id="settingsForm" onsubmit="return saveSettings()">
    <!-- Billing Settings -->
    <div class="setting-group">
        <div class="group-title">
            <i class="bi bi-currency-dollar me-2" style="color:#1b5a7a;"></i> Billing Settings
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">DAWASCO Rate (TSh/unit)</label>
                <input type="number" class="form-control" name="dawasco_rate" min="0" step="0.01"
                       value="<?php echo $settings['dawasco_rate'] ?? DAWASCO_RATE; ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Currency Symbol</label>
                <input type="text" class="form-control" name="currency" 
                       value="<?php echo $settings['currency'] ?? CURRENCY; ?>" required>
            </div>
        </div>
    </div>
    
    <!-- Organization Settings -->
    <div class="setting-group">
        <div class="group-title">
            <i class="bi bi-building me-2" style="color:#1b5a7a;"></i> Organization Settings
        </div>
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label">Organization Name</label>
                <input type="text" class="form-control" name="app_name" 
                       value="<?php echo $settings['app_name'] ?? APP_NAME; ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Default Reading Type</label>
                <select class="form-select" name="default_reading">
                    <option value="both" <?php echo ($settings['default_reading_type'] ?? 'both') === 'both' ? 'selected' : ''; ?>>Both (Morning & Evening)</option>
                    <option value="morning" <?php echo ($settings['default_reading_type'] ?? '') === 'morning' ? 'selected' : ''; ?>>Morning only</option>
                    <option value="evening" <?php echo ($settings['default_reading_type'] ?? '') === 'evening' ? 'selected' : ''; ?>>Evening only</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Timezone</label>
                <select class="form-select" name="timezone">
                    <option value="Africa/Dar_es_Salaam" <?php echo ($settings['timezone'] ?? 'Africa/Dar_es_Salaam') === 'Africa/Dar_es_Salaam' ? 'selected' : ''; ?>>Dar es Salaam (Tanzania)</option>
                    <option value="Africa/Nairobi" <?php echo ($settings['timezone'] ?? '') === 'Africa/Nairobi' ? 'selected' : ''; ?>>Nairobi (Kenya)</option>
                    <option value="Africa/Kampala" <?php echo ($settings['timezone'] ?? '') === 'Africa/Kampala' ? 'selected' : ''; ?>>Kampala (Uganda)</option>
                    <option value="Africa/Addis_Ababa" <?php echo ($settings['timezone'] ?? '') === 'Africa/Addis_Ababa' ? 'selected' : ''; ?>>Addis Ababa (Ethiopia)</option>
                    <option value="UTC" <?php echo ($settings['timezone'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Security Settings -->
    <div class="setting-group">
        <div class="group-title">
            <i class="bi bi-shield-lock me-2" style="color:#1b5a7a;"></i> Security Settings
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Session Timeout (minutes)</label>
                <input type="number" class="form-control" name="session_timeout"
                       value="<?php echo $settings['session_timeout'] ?? 60; ?>" min="1" max="1440" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Max Login Attempts</label>
                <input type="number" class="form-control" name="max_attempts"
                       value="<?php echo $settings['max_login_attempts'] ?? 5; ?>" min="1" max="20" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email Notifications</label>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="emailNotifications" name="email_notifications" <?php echo (($settings['email_notifications'] ?? 'true') === 'true') ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="emailNotifications">Enable email notifications</label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Maintenance Mode</label>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="maintenanceMode" name="maintenance_mode" <?php echo (($settings['maintenance_mode'] ?? 'false') === 'true') ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="maintenanceMode">Temporarily disable public actions</label>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> Save Settings
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="resetSettingsForm()">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
        </button>
    </div>
</form>

<div class="mt-4 text-muted-small">
    <i class="bi bi-info-circle me-1"></i>
    Changes take effect immediately after saving.
</div>

<script>
function parseSettingsAjaxResponse(response) {
    if (response.status === 401) {
        window.location.href = 'login.php';
        throw new Error('Session expired. Please log in again.');
    }

    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        return response.json();
    }

    return response.text().then(function(text) {
        const trimmed = text.trim();
        if (!trimmed) {
            throw new Error('Empty server response.');
        }

        try {
            return JSON.parse(trimmed);
        } catch (error) {
            if (trimmed.charAt(0) === '<') {
                throw new Error('Server returned HTML instead of JSON.');
            }

            throw new Error(trimmed.slice(0, 180));
        }
    });
}

function showToast(type, message) {
    const container = document.getElementById('toastContainer');
    const toastId = 'toast_' + Date.now();
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = 'toast-notification';
    toast.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show mb-0">
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" onclick="hideToast('${toastId}')"></button>
        </div>
    `;

    container.appendChild(toast);

    setTimeout(function() {
        hideToast(toastId);
    }, 3000);
}

function hideToast(toastId) {
    const toast = document.getElementById(toastId);
    if (toast) {
        toast.classList.add('hide');
        setTimeout(function() {
            toast.remove();
        }, 500);
    }
}

function saveSettings() {
    const form = document.getElementById('settingsForm');
    const formData = new FormData(form);
    formData.append('action', 'save_settings');

    document.getElementById('loadingOverlay').classList.add('show');

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseSettingsAjaxResponse)
    .then(function(data) {
        document.getElementById('loadingOverlay').classList.remove('show');

        if (data.success) {
            showToast('success', data.message || 'Settings saved successfully.');
            setTimeout(function() {
                window.location.reload();
            }, 800);
        } else {
            showToast('danger', data.message || 'Failed to save settings.');
        }
    })
    .catch(function(error) {
        document.getElementById('loadingOverlay').classList.remove('show');
        showToast('danger', 'Error: ' + error.message);
    });

    return false;
}

function resetSettingsForm() {
    document.getElementById('settingsForm').reset();
}
</script>
