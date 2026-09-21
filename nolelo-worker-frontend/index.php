<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'config.php';

$sessionTimeoutMinutes = max(1, (int)nolelo_setting('session_timeout', 60));
if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $sessionTimeoutMinutes * 60) {
    if (!empty($_SESSION['user_id'])) {
        logWorkerActivity($_SESSION['user_id'], 'session_expired', 'Worker was logged out because the session timed out.');
    }
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Profile and assignment data must never be served from a browser cache after an update.
if (isset($_GET['page']) && $_GET['page'] === 'profile') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Check if worker is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_worker']) || $_SESSION['is_worker'] !== true) {
    header('Location: login.php');
    exit;
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'entries', 'history', 'bills', 'profile'];
if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Get worker data
$worker = getWorkerData();
if (!$worker || ($worker['role'] ?? '') !== 'worker' || ($worker['status'] ?? '') !== 'active' || !empty($worker['deleted_at'])) {
    $_SESSION = [];
    if (function_exists('sendJsonResponse') && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        sendJsonResponse(['success' => false, 'message' => 'Your worker account is no longer active. Please contact the administrator.'], 401);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}
$assignments = getWorkerAssignments($_SESSION['user_id']);
$full_name = $worker['full_name'] ?? 'Worker';
$nickname = $worker['nickname'] ?? '';
$display_name = !empty($nickname) ? $nickname : explode(' ', $full_name)[0];

// AJAX page handlers must run before the shared HTML layout is emitted.
// Otherwise the saved JSON response is prefixed with the dashboard markup.
$ajaxAction = $_POST['action'] ?? '';
$ajaxPage = $page;
if (in_array($ajaxAction, ['generate_bill', 'view_bill', 'delete_bill'], true)) {
    $ajaxPage = 'bills';
} elseif (in_array($ajaxAction, ['save_entry', 'delete_entry'], true)) {
    $ajaxPage = 'entries';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && in_array($ajaxPage, ['entries', 'bills', 'profile'], true)) {
    require __DIR__ . '/pages/' . $ajaxPage . '.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
    <title><?php echo htmlspecialchars(nolelo_setting('app_name', APP_NAME)); ?> · Worker Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f4f8; }
        
        /* Sidebar */
        .sidebar {
            background: linear-gradient(180deg, #0b2b3f 0%, #1a3f55 100%);
            color: #e3f0f7;
            padding: 1.5rem 0.5rem;
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
            min-height: 100vh;
            transition: all 0.3s ease;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1050;
        }
        .sidebar .brand {
            font-weight: 700;
            font-size: 1.4rem;
            padding: 0 1rem 1.5rem 1rem;
            margin-bottom: 1.5rem;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
        }
        .sidebar .brand i { color: #4fc3f7; margin-right: 10px; font-size: 1.8rem; }
        .sidebar .brand span { background: rgba(79,195,247,0.15); padding: 0.2rem 0.8rem; border-radius: 50px; font-size: 0.6rem; margin-left: 0.5rem; }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.7);
            border-radius: 12px;
            padding: 0.7rem 1rem;
            margin: 0.2rem 0.4rem;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        .sidebar .nav-link:hover { background: rgba(255,255,255,0.1); color: white; transform: translateX(5px); }
        .sidebar .nav-link.active {
            background: rgba(79,195,247,0.2);
            color: white;
            box-shadow: 0 0 20px rgba(79,195,247,0.1);
        }
        .sidebar .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: #4fc3f7;
            border-radius: 0 4px 4px 0;
        }
        .sidebar .nav-link i { width: 24px; font-size: 1.1rem; }
        .sidebar .user-info { padding: 1rem; border-top: 1px solid rgba(255,255,255,0.08); margin-top: auto; }
        .sidebar .user-info .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4fc3f7, #1b5a7a);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }
        
        /* Topbar */
        .topbar {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            padding: 0.8rem 2rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            position: sticky;
            top: 0;
            z-index: 1040;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .topbar .page-title { font-weight: 700; font-size: 1.3rem; background: linear-gradient(135deg, #0b2b3f, #1a5a7a); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .topbar .user-badge {
            background: linear-gradient(135deg, #e3f0fa, #c5dce8);
            padding: 0.4rem 1.2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #0b2b3f;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .topbar .user-badge .dot { width: 8px; height: 8px; background: #4caf50; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
        
        /* Mobile Sidebar */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1040; }
        .sidebar-overlay.show { display: block; }
        .sidebar-widget {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 300px;
            background: linear-gradient(180deg, #0b2b3f 0%, #1a3f55 100%);
            z-index: 1050;
            transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 1.5rem 0.5rem;
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(0,0,0,0.2);
        }
        .sidebar-widget.open { transform: translateX(0); }
        .sidebar-widget .close-btn { background: none; border: none; color: #cbdbe9; font-size: 1.5rem; padding: 0 0.5rem; }
        .sidebar-widget .close-btn:hover { color: white; }
        .hamburger-btn {
            display: none;
            background: none;
            border: none;
            color: #1b5a7a;
            font-size: 1.8rem;
            padding: 0.2rem 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .hamburger-btn:hover { color: #0b2b3f; transform: scale(1.1); }
        
        @media (min-width: 768px) {
            .sidebar { display: flex !important; }
            .sidebar-overlay { display: none !important; }
            .sidebar-widget { display: none !important; }
            .hamburger-btn { display: none !important; }
        }
        @media (max-width: 767.98px) {
            .sidebar { display: none !important; }
            .hamburger-btn { display: block; }
            .sidebar-widget { display: block; }
        }
        @media (max-width: 576px) {
            .sidebar-widget { width: 280px; }
            .topbar { padding: 0.6rem 1rem; }
            .topbar .page-title { font-size: 1rem; }
        }
        
        .separator { border-top: 1px solid #e5edf5; margin: 2rem 0; }
        .text-muted-small { font-size: 0.8rem; color: #7e99ae; }
        .footer { background: white; border-top: 1px solid rgba(0,0,0,0.05); padding: 1.2rem 0; margin-top: auto; }
        .main-content { display: flex; flex-direction: column; min-height: 100vh; }
        .content-area { flex: 1; padding: 1.5rem 2rem; }
        .btn-primary { background: linear-gradient(135deg, #1b5a7a, #2a7a9e); border: none; padding: 0.5rem 1.5rem; border-radius: 12px; font-weight: 600; transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(27,90,122,0.3); }
        .btn-outline-primary { border: 2px solid #1b5a7a; color: #1b5a7a; border-radius: 12px; font-weight: 600; transition: all 0.3s ease; }
        .btn-outline-primary:hover { background: #1b5a7a; color: white; transform: translateY(-2px); }
        .form-control, .form-select { border-radius: 12px; border: 2px solid #e9eff4; padding: 0.6rem 1rem; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { border-color: #1b5a7a; box-shadow: 0 0 0 4px rgba(27,90,122,0.1); }
        .form-label { font-weight: 600; color: #1a3f55; margin-bottom: 0.4rem; }
        .toast-notification { position: fixed; top: 80px; right: 20px; z-index: 9999; min-width: 300px; max-width: 500px; animation: slideIn 0.5s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .toast-notification.hide { animation: slideOut 0.5s ease forwards; }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
        .page-section { display: none; }
        .page-section.active { display: block; }
        .modal-content { border: none; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
        .table-modern {
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        .table-modern thead th {
            border: none;
            font-weight: 700;
            color: #1a3f55;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.8rem 1rem;
        }
        .table-modern tbody tr {
            background: white;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .table-modern tbody tr:hover { transform: scale(1.01); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .table-modern tbody td { border: none; padding: 1rem; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include 'sidebar.php'; ?>
            <div class="col-12 col-md-9 col-lg-10 main-content d-flex flex-column">
                <?php include 'topbar.php'; ?>
                <div class="content-area flex-grow-1">
                    <?php 
                    $page_file = 'pages/' . $page . '.php';
                    if (file_exists($page_file)) {
                        include $page_file;
                    } else {
                        echo '<div class="alert alert-danger flash-message">Page not found.</div>';
                    }
                    ?>
                    <div class="separator"></div>
                </div>
                <?php include 'footer.php'; ?>
            </div>
        </div>
    </div>

    <!-- PIN Change Modal -->
    <div class="modal fade" id="pinChangeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2" style="color:#1b5a7a;"></i>Change PIN</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Security Notice:</strong> You are required to change your PIN for security purposes.
                    </div>
                    <form id="pinChangeForm" method="POST" action="pages/profile.php">
                        <input type="hidden" name="action" value="change_pin">
                        <div class="mb-3">
                            <label class="form-label">Current PIN</label>
                            <input type="password" class="form-control" name="current_pin" placeholder="Enter current PIN" required maxlength="4" pattern="[0-9]{4}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New PIN</label>
                            <input type="password" class="form-control" name="new_pin" id="newPin" placeholder="Enter new 4-digit PIN" required maxlength="4" pattern="[0-9]{4}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New PIN</label>
                            <input type="password" class="form-control" name="confirm_pin" placeholder="Confirm new PIN" required maxlength="4" pattern="[0-9]{4}">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitPinChange()"><i class="bi bi-save"></i> Update PIN</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script>
        // ===== NAVIGATION =====
        function loadPage(page) {
            window.location.href = 'index.php?page=' + page;
        }

        // ===== SIDEBAR TOGGLE =====
        const sidebarWidget = document.getElementById('sidebarWidget');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const closeSidebarBtn = document.getElementById('closeSidebarBtn');

        function openSidebar() {
            sidebarWidget.classList.add('open');
            sidebarOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebarWidget.classList.remove('open');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        hamburgerBtn.addEventListener('click', openSidebar);
        closeSidebarBtn.addEventListener('click', closeSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });

        // ===== FOOTER TIME =====
        function updateTime() {
            const el = document.getElementById('footerTime');
            if (el) el.textContent = new Date().toLocaleTimeString();
        }
        updateTime();
        setInterval(updateTime, 1000);

        // ===== PIN CHANGE =====
        function parseProfileAjaxResponse(response) {
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
                    if (trimmed.indexOf('<') === 0) {
                        throw new Error('Server returned HTML instead of JSON.');
                    }

                    throw new Error(trimmed.slice(0, 180));
                }
            });
        }

        function showForcePinChange() {
            const modal = new bootstrap.Modal(document.getElementById('pinChangeModal'));
            modal.show();
        }

        function submitPinChange() {
            const form = document.getElementById('pinChangeForm');
            const formData = new FormData(form);
            
            const currentPin = formData.get('current_pin');
            const newPin = formData.get('new_pin');
            const confirmPin = formData.get('confirm_pin');
            
            if (!currentPin || currentPin.length !== 4) {
                showToast('danger', 'Please enter a valid current PIN.');
                return;
            }
            
            if (!newPin || newPin.length !== 4) {
                showToast('danger', 'Please enter a valid new PIN.');
                return;
            }
            
            if (newPin !== confirmPin) {
                showToast('danger', 'PINs do not match!');
                return;
            }
            
            // Submit via AJAX
            fetch('pages/profile.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: formData
            })
            .then(parseProfileAjaxResponse)
            .then(data => {
                if (data.success) {
                    showToast('success', data.message);
                    bootstrap.Modal.getInstance(document.getElementById('pinChangeModal')).hide();
                    form.reset();
                } else {
                    showToast('danger', data.message);
                }
            })
            .catch(error => {
                showToast('danger', 'Error: ' + error.message);
            });
        }

        // ===== TOAST FUNCTIONS =====
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toastId = 'toast_' + Date.now();
            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = 'toast-notification';
            toast.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show mb-0">
                    <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" onclick="hideToast('${toastId}')"></button>
                </div>
            `;
            container.appendChild(toast);
            setTimeout(() => { hideToast(toastId); }, 3000);
        }

        function hideToast(toastId) {
            const toast = document.getElementById(toastId);
            if (toast) {
                toast.classList.add('hide');
                setTimeout(() => { toast.remove(); }, 500);
            }
        }

        // Enforce the administrator-configured timeout in the browser as well
        // as on the server, so inactive workers are logged out without refresh.
        (function enforceSessionTimeout() {
            const timeoutMs = <?php echo max(1, (int)nolelo_setting('session_timeout', 60)) * 60 * 1000; ?>;
            let timer;
            function scheduleLogout() {
                window.clearTimeout(timer);
                timer = window.setTimeout(function() {
                    window.location.href = 'logout.php?reason=timeout';
                }, timeoutMs);
            }
            ['click', 'keydown', 'mousemove', 'touchstart'].forEach(function(eventName) {
                document.addEventListener(eventName, scheduleLogout, {passive: true});
            });
            const nativeFetch = window.fetch;
            window.fetch = function() {
                scheduleLogout();
                return nativeFetch.apply(this, arguments).then(function(response) {
                    scheduleLogout();
                    return response;
                });
            };
            scheduleLogout();
        })();
    </script>
</body>
</html>
