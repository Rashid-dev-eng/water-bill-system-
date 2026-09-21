<?php
require_once __DIR__ . '/session_bootstrap.php';
// Add this to index.php after session_start()
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'config.php';

$sessionTimeoutMinutes = max(1, (int)nolelo_setting('session_timeout', 60));
if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $sessionTimeoutMinutes * 60) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Check database connection
if (!DB_CONNECTED) {
    die('<div class="alert alert-danger m-4">Database connection failed. Please check your configuration.</div>');
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'users', 'categories', 'entries', 'bills', 'reports', 'activities', 'settings'];
if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// AJAX page handlers must run before the shared HTML layout is emitted.
// Otherwise the saved JSON response is prefixed with the dashboard markup.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && in_array($page, ['users', 'categories', 'entries', 'bills', 'reports', 'activities', 'settings'], true)) {
    require __DIR__ . '/pages/' . $page . '.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
    <title><?php echo htmlspecialchars(nolelo_setting('app_name', APP_NAME)); ?> · Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    
    <style>
        /* All styles here - same as before */
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f4f8; }
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
            justify-content: space-between;
        }
        .sidebar .brand i { color: #4fc3f7; margin-right: 10px; font-size: 1.6rem; }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.7);
            border-radius: 12px;
            padding: 0.7rem 1rem;
            margin: 0.2rem 0.4rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }
        .sidebar .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        .sidebar .nav-link.active {
            background: rgba(79, 195, 247, 0.2);
            color: white;
            box-shadow: 0 0 20px rgba(79, 195, 247, 0.1);
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
        .topbar {
            background: rgba(255,255,255,0.9);
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
        .topbar .page-title { 
            font-weight: 700; 
            font-size: 1.3rem;
            background: linear-gradient(135deg, #0b2b3f, #1a5a7a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .topbar .admin-badge {
            background: linear-gradient(135deg, #e3f0fa, #c5dce8);
            padding: 0.4rem 1.2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #0b2b3f;
        }
        .card-stat {
            border: none;
            border-radius: 20px;
            background: white;
            padding: 1.2rem 1.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        .card-stat::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1b5a7a, #4fc3f7);
        }
        .card-stat:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .card-stat .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #e3f0fa, #c5dce8);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0b2b3f;
            font-size: 1.3rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1b5a7a, #2a7a9e);
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(27, 90, 122, 0.3);
        }
        .btn-outline-primary {
            border: 2px solid #1b5a7a;
            color: #1b5a7a;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-outline-primary:hover {
            background: #1b5a7a;
            color: white;
            transform: translateY(-2px);
        }
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
        .table-modern tbody tr:hover {
            transform: scale(1.01);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .table-modern tbody td {
            border: none;
            padding: 1rem;
            vertical-align: middle;
        }
        .badge-role {
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .badge-role.worker {
            background: linear-gradient(135deg, #d4e2ed, #b8cedc);
            color: #0b2b3f;
        }
        .badge-role.admin {
            background: linear-gradient(135deg, #ffd700, #f5a623);
            color: #0b2b3f;
        }
        .activity-timeline { max-height: 300px; overflow-y: auto; }
        .activity-item {
            border-left: 3px solid #1b5a7a;
            padding: 0.8rem 0.8rem 0.8rem 1.2rem;
            margin-bottom: 0.5rem;
            background: rgba(255,255,255,0.6);
            border-radius: 0 12px 12px 0;
            transition: all 0.3s ease;
        }
        .activity-item:hover {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 1040;
        }
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
            .sidebar-overlay { display: none; }
        }
        @media (max-width: 576px) {
            .sidebar-widget { width: 280px; }
            .topbar { padding: 0.6rem 1rem; }
            .topbar .page-title { font-size: 1rem; }
        }
        .separator { border-top: 1px solid #e5edf5; margin: 2rem 0; }
        .text-muted-small { font-size: 0.8rem; color: #7e99ae; }
        .badge-dawasco { 
            background: linear-gradient(135deg, #d2e7f5, #b8d4e8);
            color: #0b2b3f;
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-weight: 600;
        }
        .footer {
            background: white;
            border-top: 1px solid rgba(0,0,0,0.05);
            padding: 1.2rem 0;
            margin-top: auto;
        }
        .main-content {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .content-area {
            flex: 1;
            padding: 1.5rem 2rem;
        }
        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        .modal-header {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1.5rem 2rem;
        }
        .modal-body { padding: 2rem; }
        .modal-footer {
            border-top: 1px solid rgba(0,0,0,0.05);
            padding: 1.5rem 2rem;
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9eff4;
            padding: 0.6rem 1rem;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1b5a7a;
            box-shadow: 0 0 0 4px rgba(27, 90, 122, 0.1);
        }
        .form-label {
            font-weight: 600;
            color: #1a3f55;
            margin-bottom: 0.4rem;
        }
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
    
    <div id="modalContainer"></div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarWidget = document.getElementById('sidebarWidget');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const closeSidebarBtn = document.getElementById('closeSidebarBtn');

            function openSidebar() {
                if (sidebarWidget) {
                    sidebarWidget.classList.add('open');
                    sidebarOverlay.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }
            
            function closeSidebar() {
                if (sidebarWidget) {
                    sidebarWidget.classList.remove('open');
                    sidebarOverlay.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            if (hamburgerBtn) hamburgerBtn.addEventListener('click', openSidebar);
            if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', closeSidebar);
            if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeSidebar();
            });
        });
        
        function showModal(title, content, submitText = 'Save', submitAction = null) {
            const container = document.getElementById('modalContainer');
            container.innerHTML = `
                <div class="modal fade" id="globalModal" tabindex="-1">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title fw-bold">${title}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">${content}</div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="modalSubmitBtn">${submitText}</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            const modal = new bootstrap.Modal(document.getElementById('globalModal'));
            modal.show();
            if (submitAction) {
                document.getElementById('modalSubmitBtn').addEventListener('click', submitAction);
            }
        }
        
        function closeModal() {
            const modal = document.getElementById('globalModal');
            if (modal) {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) bsModal.hide();
            }
        }
    </script>
    <script>
        window.NOLELO_ADMIN_PAGE = <?php echo json_encode($page); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const realtimeState = {
                latestEntryId: null,
                initialized: false,
                isFetching: false
            };

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function formatNumber(value, decimals) {
                const numericValue = Number(value ?? 0);
                return numericValue.toLocaleString('en-US', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                });
            }

            function getToastContainer() {
                let container = document.getElementById('adminRealtimeToastContainer');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'adminRealtimeToastContainer';
                    container.style.position = 'fixed';
                    container.style.top = '20px';
                    container.style.right = '20px';
                    container.style.zIndex = '2000';
                    container.style.maxWidth = '360px';
                    container.style.width = 'calc(100% - 40px)';
                    document.body.appendChild(container);
                }
                return container;
            }

            function hideToast(toastId) {
                const toast = document.getElementById(toastId);
                if (!toast) {
                    return;
                }

                toast.classList.remove('show');
                setTimeout(function() {
                    if (toast && toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }

            function showRealtimeToast(type, message) {
                const container = getToastContainer();
                const toastId = 'admin_rt_' + Date.now();
                const toast = document.createElement('div');
                toast.id = toastId;
                toast.className = 'alert alert-' + type + ' alert-dismissible fade show shadow-sm';
                toast.style.minWidth = '280px';
                toast.style.marginBottom = '0.75rem';
                toast.style.borderRadius = '14px';
                toast.style.borderWidth = '0';
                toast.innerHTML = `
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-${type === 'success' ? 'check-circle' : 'info-circle'} mt-1"></i>
                        <div class="flex-grow-1">${escapeHtml(message)}</div>
                        <button type="button" class="btn-close ms-2" aria-label="Close"></button>
                    </div>
                `;

                toast.querySelector('.btn-close').addEventListener('click', function() {
                    hideToast(toastId);
                });

                container.appendChild(toast);

                setTimeout(function() {
                    hideToast(toastId);
                }, 3000);
            }

            function parseRealtimeResponse(response) {
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
                        throw new Error('Empty realtime response.');
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

            function renderRecentActivities(activities) {
                if (!Array.isArray(activities) || activities.length === 0) {
                    return '<div class="text-muted-small py-3">No recent activity yet.</div>';
                }

                return activities.map(function(activity) {
                    return `
                        <div class="activity-item">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">${escapeHtml(activity.full_name)}</span>
                                <span class="time">${escapeHtml(activity.time_label)}</span>
                            </div>
                            <div class="text-muted-small">
                                Reading &middot; ${escapeHtml(activity.site_name)} &middot;
                                Morning: ${formatNumber(activity.morning_reading, 1)} m\u00b3 &middot;
                                Evening: ${formatNumber(activity.evening_reading, 1)} m\u00b3
                            </div>
                        </div>
                    `;
                }).join('');
            }

            function renderEntryRows(rows) {
                if (!Array.isArray(rows) || rows.length === 0) {
                    return `
                        <tr id="noEntriesRow">
                            <td colspan="9" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No entries found. Click "Add Entry" to create one.
                                </div>
                            </td>
                        </tr>
                    `;
                }

                return rows.map(function(entry) {
                    return `
                        <tr id="entryRow_${entry.id}">
                            <td><span class="fw-semibold">${escapeHtml(entry.entry_date_formatted)}</span></td>
                            <td>${escapeHtml(entry.worker_name)}</td>
                            <td>${escapeHtml(entry.site_name)}</td>
                            <td>${formatNumber(entry.morning_reading, 1)}</td>
                            <td>${formatNumber(entry.evening_reading, 1)}</td>
                            <td><span class="fw-semibold">${formatNumber(entry.units_consumed, 1)}</span></td>
                            <td><span class="text-success fw-semibold">${formatNumber(entry.cost, 0)}</span></td>
                            <td><span class="badge-dawasco">${escapeHtml((entry.status || 'pending').charAt(0).toUpperCase() + (entry.status || 'pending').slice(1))}</span></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-info me-1" data-entry="${escapeHtml(JSON.stringify(entry))}" onclick="viewEntryDetails(this.dataset.entry)" title="View entry details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick='deleteEntry(${entry.id}, ${JSON.stringify(entry.worker_name || "")})'>
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            function renderEntryFooter(snapshot) {
                if (!snapshot || !Array.isArray(snapshot.rows) || snapshot.rows.length === 0) {
                    return '';
                }

                return `
                    <tr>
                        <td colspan="5" class="text-end fw-bold">Total:</td>
                        <td class="fw-bold">${formatNumber(snapshot.total_units, 1)}</td>
                        <td class="fw-bold text-success">${formatNumber(snapshot.total_cost, 0)}</td>
                        <td colspan="2"></td>
                    </tr>
                `;
            }

            function updateDashboard(snapshot) {
                const welcomeNumbers = document.querySelectorAll('.welcome-stats .welcome-stat .number');
                if (welcomeNumbers[0]) {
                    welcomeNumbers[0].textContent = formatNumber(snapshot.today_entries, 0);
                }
                if (welcomeNumbers[1]) {
                    welcomeNumbers[1].textContent = formatNumber(snapshot.weekly_usage, 1) + 'm\u00b3';
                }
                if (welcomeNumbers[2]) {
                    welcomeNumbers[2].textContent = formatNumber(snapshot.weekly_revenue, 0);
                }
                if (welcomeNumbers[3]) {
                    welcomeNumbers[3].textContent = formatNumber(snapshot.active_users, 0);
                }

                const summaryNumbers = document.querySelectorAll('.card-stat h5');
                if (summaryNumbers[0]) {
                    summaryNumbers[0].textContent = formatNumber(snapshot.total_users, 0);
                }
                if (summaryNumbers[1]) {
                    summaryNumbers[1].textContent = formatNumber(snapshot.total_sites, 0);
                }
                if (summaryNumbers[2]) {
                    summaryNumbers[2].textContent = formatNumber(snapshot.total_categories, 0);
                }
                if (summaryNumbers[3]) {
                    summaryNumbers[3].textContent = formatNumber(snapshot.today_entries, 0);
                }

                const activityTimeline = document.querySelector('.activity-timeline');
                if (activityTimeline) {
                    activityTimeline.innerHTML = renderRecentActivities(snapshot.recent_activities || []);
                }
            }

            function updateEntries(snapshot) {
                const statsNumbers = document.querySelectorAll('.entry-stats .stat-number');
                if (statsNumbers[0]) {
                    statsNumbers[0].textContent = formatNumber(snapshot.total_entries, 0);
                }
                if (statsNumbers[1]) {
                    statsNumbers[1].textContent = formatNumber(snapshot.total_units, 1);
                }
                if (statsNumbers[2]) {
                    statsNumbers[2].textContent = formatNumber(snapshot.total_cost, 0);
                }

                const tableBody = document.getElementById('entriesTableBody');
                if (tableBody) {
                    tableBody.innerHTML = renderEntryRows(snapshot.rows || []);
                }

                const table = document.getElementById('entriesTable');
                if (table) {
                    let tableFoot = table.querySelector('tfoot');
                    if (snapshot.rows && snapshot.rows.length) {
                        if (!tableFoot) {
                            tableFoot = document.createElement('tfoot');
                            table.appendChild(tableFoot);
                        }
                        tableFoot.innerHTML = renderEntryFooter(snapshot);
                    } else if (tableFoot) {
                        tableFoot.remove();
                    }
                }
            }

            function applyRealtimeData(data) {
                if (!data || !data.success) {
                    throw new Error(data && data.message ? data.message : 'Failed to load realtime data.');
                }

                const latestEntryId = Number(data.latest_entry_id || 0);
                const shouldToast = realtimeState.initialized && latestEntryId > (realtimeState.latestEntryId || 0);

                realtimeState.latestEntryId = latestEntryId;
                realtimeState.initialized = true;

                if (window.NOLELO_ADMIN_PAGE === 'dashboard' && data.dashboard) {
                    updateDashboard(data.dashboard);
                }

                if (window.NOLELO_ADMIN_PAGE === 'entries' && data.entries) {
                    updateEntries(data.entries);
                }

                if (shouldToast) {
                    showRealtimeToast('success', 'New worker entry received.');
                }
            }

            function pollRealtime() {
                if (realtimeState.isFetching || document.visibilityState === 'hidden') {
                    return;
                }

                realtimeState.isFetching = true;

                const params = new URLSearchParams(window.location.search || '');
                params.set('page', window.NOLELO_ADMIN_PAGE || 'dashboard');
                params.set('_', String(Date.now()));

                fetch('realtime.php?' + params.toString(), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    if (response.status === 401) {
                        window.location.href = 'login.php';
                        return null;
                    }

                    if (!response.ok) {
                        return parseRealtimeResponse(response).catch(function() {
                            return {};
                        }).then(function(payload) {
                            throw new Error(payload.message || ('Realtime request failed with status ' + response.status + '.'));
                        });
                    }

                    return parseRealtimeResponse(response);
                })
                .then(function(data) {
                    if (data) {
                        applyRealtimeData(data);
                    }
                })
                .catch(function(error) {
                    console.error('Realtime sync failed:', error);
                })
                .finally(function() {
                    realtimeState.isFetching = false;
                });
            }

            pollRealtime();
            setInterval(pollRealtime, 1000);

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    pollRealtime();
                }
            });
        });
    </script>
</body>
</html>
