<style>
.report-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    transition: all 0.3s ease;
    border: 1px solid rgba(0,0,0,0.05);
    margin-bottom: 1rem;
}
.report-card:hover {
    box-shadow: 0 8px 30px rgba(0,0,0,0.08);
    transform: translateY(-3px);
}
.report-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}
.report-icon.daily { background: linear-gradient(135deg, #4fc3f7, #1b5a7a); }
.report-icon.weekly { background: linear-gradient(135deg, #66bb6a, #2e7d32); }
.report-icon.monthly { background: linear-gradient(135deg, #ffa726, #e65100); }
.report-icon.custom { background: linear-gradient(135deg, #ab47bc, #6a1b9a); }
.report-option {
    cursor: pointer;
    transition: all 0.2s ease;
}
.report-option:hover {
    transform: translateY(-1px);
}
.report-option.active {
    background: #1b5a7a;
    border-color: #1b5a7a;
    color: #fff;
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
.report-preview {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    border: 2px solid #1b5a7a;
    margin-top: 1.5rem;
    display: none;
}
.report-preview.show { display: block; }
.report-pdf {
    width: 190mm !important;
    max-width: 190mm !important;
    box-sizing: border-box !important;
    padding: 8mm !important;
    background: #ffffff !important;
    color: #1f2937 !important;
    overflow: visible !important;
}
.report-pdf .table-responsive { overflow: visible !important; }
.report-pdf table {
    width: 100% !important;
    table-layout: fixed !important;
    font-size: 8pt !important;
    border-collapse: collapse !important;
}
.report-pdf th, .report-pdf td {
    padding: 4px !important;
    overflow-wrap: anywhere;
    vertical-align: top;
}
.report-pdf thead { display: table-header-group; }
.report-pdf tr, .report-pdf .report-header { break-inside: avoid; page-break-inside: avoid; }
@media print {
    .no-print { display: none !important; }
    @page { size: A4 portrait; margin: 12mm; }
    body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
    .report-preview { border: none !important; width: 100% !important; padding: 0 !important; }
    .report-preview .table-responsive { overflow: visible !important; }
    .report-preview table { width: 100% !important; table-layout: fixed !important; font-size: 8pt !important; }
    .report-preview thead { display: table-header-group; }
    .report-preview tr { break-inside: avoid; page-break-inside: avoid; }
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
$firstEntry = dbFetchOne('SELECT MIN(entry_date) AS first_date FROM water_entries');
$defaultStartDate = is_array($firstEntry) && !empty($firstEntry['first_date']) ? $firstEntry['first_date'] : date('Y-m-01');
$defaultEndDate = date('Y-m-d');
// ... rest of your code ...

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => 'Unknown action'];
    $action = $_POST['action'] ?? '';

    try {
        global $pdo;

        if ($action === 'generate_report') {
            $scope = $_POST['scope'] ?? 'all';
            $scope = in_array($scope, ['all', 'category', 'site', 'user'], true) ? $scope : 'all';
            $targetId = (int)($_POST['target_id'] ?? 0);
            $startDate = trim($_POST['start_date'] ?? $defaultStartDate);
            $endDate = trim($_POST['end_date'] ?? $defaultEndDate);
            $readingType = $_POST['reading_type'] ?? 'none';
            $readingType = in_array($readingType, ['none', 'morning', 'evening', 'both'], true) ? $readingType : 'none';
            $format = $_POST['format'] ?? 'html';

            if ($startDate > $endDate) {
                throw new Exception('The start date cannot be after the end date.');
            }

            $whereParts = ['we.entry_date BETWEEN ? AND ?'];
            $params = [$startDate, $endDate];
            $scopeLabel = 'All Organization';
            $targetLabel = 'All entries';

            if ($scope === 'category') {
                $scopeLabel = 'Category';
                if ($targetId > 0) {
                    $whereParts[] = 'we.site_id IS NULL AND we.category_id = ?';
                    $params[] = $targetId;
                    $targetRow = dbFetchOne("SELECT name FROM categories WHERE id = ? AND status = 'active'", [$targetId]);
                    $targetLabel = $targetRow['name'] ?? 'Selected category';
                } else {
                    $whereParts[] = 'we.site_id IS NULL AND we.category_id IS NOT NULL';
                    $targetLabel = 'All category entries';
                }
            } elseif ($scope === 'site') {
                $scopeLabel = 'Site';
                if ($targetId > 0) {
                    $whereParts[] = 'we.site_id = ?';
                    $params[] = $targetId;
                    $targetRow = dbFetchOne("SELECT name FROM sites WHERE id = ? AND status = 'active'", [$targetId]);
                    $targetLabel = $targetRow['name'] ?? 'Selected site';
                } else {
                    $whereParts[] = 'we.site_id IS NOT NULL';
                    $targetLabel = 'All site entries';
                }
            } elseif ($scope === 'user') {
                $scopeLabel = 'Worker';
                if ($targetId > 0) {
                    $whereParts[] = 'we.user_id = ?';
                    $params[] = $targetId;
                    $targetRow = dbFetchOne("SELECT full_name FROM users WHERE id = ? AND role = 'worker'", [$targetId]);
                    $targetLabel = $targetRow['full_name'] ?? 'Selected worker';
                } else {
                    $targetLabel = 'All workers';
                }
            }

            // Completed days are stored as Both; allow them in a selected
            // period view while the response labels the selected period.
            if ($readingType === 'morning') {
                $whereParts[] = "we.reading_type IN ('morning', 'both')";
            } elseif ($readingType === 'evening') {
                $whereParts[] = "we.reading_type IN ('evening', 'both')";
            } elseif ($readingType === 'both') {
                $whereParts[] = "we.reading_type = 'both'";
            }

            $reportRows = dbFetchAll("
                SELECT
                    we.id,
                    we.entry_date,
                    we.morning_reading,
                    we.evening_reading,
                    we.units_consumed,
                    we.cost,
                    we.reading_type,
                    we.status,
                    u.full_name as worker_name,
                    u.nickname as worker_nickname,
                    s.name as site_name,
                    COALESCE(c_entry.name, c_site.name) as category_name
                FROM water_entries we
                JOIN users u ON we.user_id = u.id
                LEFT JOIN sites s ON we.site_id = s.id
                LEFT JOIN categories c_entry ON we.category_id = c_entry.id
                LEFT JOIN categories c_site ON s.category_id = c_site.id
                WHERE " . implode(' AND ', $whereParts) . "
                ORDER BY we.entry_date DESC, we.created_at DESC, we.id DESC
            ", $params);

            if (empty($reportRows)) {
                throw new Exception('No data found for the selected filters.');
            }

            $currentRate = (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
            foreach ($reportRows as &$reportRow) {
                $reportRow['filtered_units'] = $readingType === 'morning'
                    ? (float)($reportRow['morning_reading'] ?? 0)
                    : ($readingType === 'evening'
                        ? (float)($reportRow['evening_reading'] ?? 0)
                        : (float)($reportRow['units_consumed'] ?? 0));
                $reportRow['units_consumed'] = $reportRow['filtered_units'];
                $reportRow['cost'] = $reportRow['filtered_units'] * $currentRate;
                $reportRow['display_reading_type'] = in_array($readingType, ['morning', 'evening'], true)
                    ? $readingType
                    : ($reportRow['reading_type'] ?? 'morning');
                $reportRow['display_reading'] = $reportRow['display_reading_type'] === 'evening'
                    ? (float)($reportRow['evening_reading'] ?? 0)
                    : ($reportRow['display_reading_type'] === 'both'
                        ? 'M: ' . number_format((float)($reportRow['morning_reading'] ?? 0), 1) . ' / E: ' . number_format((float)($reportRow['evening_reading'] ?? 0), 1)
                        : (float)($reportRow['morning_reading'] ?? 0));
            }
            unset($reportRow);

            $reportData = [];
            foreach ($reportRows as $row) {
                $dateKey = $row['entry_date'];
                if (!isset($reportData[$dateKey])) {
                    $reportData[$dateKey] = [
                        'date' => $dateKey,
                        'entries' => 0,
                        'workers' => [],
                        'sites' => [],
                        'total_units' => 0,
                        'total_cost' => 0,
                    ];
                }

                $reportData[$dateKey]['entries']++;
                $reportData[$dateKey]['total_units'] += (float)($row['units_consumed'] ?? 0);
                $reportData[$dateKey]['total_cost'] += (float)($row['cost'] ?? 0);

                if (!empty($row['worker_name'])) {
                    $reportData[$dateKey]['workers'][$row['worker_name']] = true;
                }

                $siteLabel = $row['site_name'] ?? ($row['category_name'] ?? '');
                if ($siteLabel !== '') {
                    $reportData[$dateKey]['sites'][$siteLabel] = true;
                }
            }

            $reportData = array_values(array_map(static function ($row) {
                return [
                    'date' => $row['date'],
                    'entries' => $row['entries'],
                    'workers' => count($row['workers']),
                    'sites' => count($row['sites']),
                    'total_units' => $row['total_units'],
                    'total_cost' => $row['total_cost'],
                ];
            }, $reportData));

            $workerNames = array_values(array_unique(array_filter(array_column($reportRows, 'worker_name'))));
            $siteNames = array_values(array_unique(array_filter(array_map(static function ($row) {
                return $row['site_name'] ?? ($row['category_name'] ?? '');
            }, $reportRows))));
            $categoryNames = array_values(array_unique(array_filter(array_column($reportRows, 'category_name'))));
            $totalEntries = count($reportRows);
            $totalUnits = array_sum(array_map(static function ($row) {
                return (float)($row['units_consumed'] ?? 0);
            }, $reportRows));
            $totalCost = array_sum(array_map(static function ($row) {
                return (float)($row['cost'] ?? 0);
            }, $reportRows));

            $response['success'] = true;
            $response['message'] = 'Report generated successfully!';
            $response['report'] = [
                'type' => $scope,
                'scope' => $scope,
                'scope_label' => $scopeLabel,
                'target_label' => $targetLabel,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reading_type' => $readingType,
                'format' => $format,
                'data' => $reportData,
                'entries' => $reportRows,
                'summary' => [
                    'total_entries' => $totalEntries,
                    'total_workers' => count($workerNames),
                    'total_sites' => count($siteNames),
                    'total_categories' => count($categoryNames),
                    'total_units' => $totalUnits,
                    'total_cost' => $totalCost,
                ],
                'generated_date' => date('Y-m-d H:i:s'),
            ];
        } else {
            throw new Exception('Invalid action: ' . $action);
        }
    } catch (Throwable $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }

    sendJsonResponse($response);
}

// Get summary data for dashboard cards
try {
    global $pdo;

    $today_stats = $pdo->query("
        SELECT COUNT(*) as entries, SUM(units_consumed) as units
        FROM water_entries WHERE entry_date = CURDATE()
    ")->fetch();

    $week_stats = $pdo->query("
        SELECT COUNT(*) as entries, SUM(units_consumed) as units
        FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetch();

    $month_stats = $pdo->query("
        SELECT COUNT(*) as entries, SUM(units_consumed) as units
        FROM water_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();
    $currentRate = (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
    foreach ([$today_stats, $week_stats, $month_stats] as &$stats) {
        $stats['cost'] = (float)($stats['units'] ?? 0) * $currentRate;
    }
    unset($stats);
} catch (Throwable $e) {
    $today_stats = ['entries' => 0, 'units' => 0, 'cost' => 0];
    $week_stats = ['entries' => 0, 'units' => 0, 'cost' => 0];
    $month_stats = ['entries' => 0, 'units' => 0, 'cost' => 0];
}

try {
    $all_categories = dbFetchAll("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
    $all_sites = dbFetchAll("SELECT id, name FROM sites WHERE status = 'active' ORDER BY name");
    $all_users = dbFetchAll("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'worker' ORDER BY full_name");
} catch (Throwable $e) {
    $all_categories = [];
    $all_sites = [];
    $all_users = [];
}

$categoryOptionsHtml = '<option value="0">None</option>';
foreach ($all_categories as $category) {
    $categoryOptionsHtml .= '<option value="' . (int)$category['id'] . '">' . htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') . '</option>';
}

$siteOptionsHtml = '<option value="0">None</option>';
foreach ($all_sites as $site) {
    $siteOptionsHtml .= '<option value="' . (int)$site['id'] . '">' . htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') . '</option>';
}

$userOptionsHtml = '<option value="0">None</option>';
foreach ($all_users as $user) {
    $userOptionsHtml .= '<option value="' . (int)$user['id'] . '">' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . '</option>';
}
?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<h4><i class="bi bi-file-earmark-spreadsheet me-2" style="color:#1b5a7a;"></i>Reports</h4>
<p class="text-muted-small">Generate reports on water usage and billing.</p>

<!-- Report Generator -->
<div class="card border-0 shadow-sm p-3 mb-4 no-print">
    <form id="reportForm" onsubmit="return generateReport()">
        <input type="hidden" name="scope" id="reportScope" value="all">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Filter Scope</label>
                <div class="d-flex flex-wrap gap-2" id="reportScopeOptions">
                    <button type="button" class="btn btn-sm btn-outline-primary report-option active" data-scope="all" onclick="updateScope('all')">
                        <i class="bi bi-globe"></i> All organization
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary report-option" data-scope="category" onclick="updateScope('category')">
                        <i class="bi bi-tags"></i> Category
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary report-option" data-scope="site" onclick="updateScope('site')">
                        <i class="bi bi-geo-alt"></i> Site
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary report-option" data-scope="user" onclick="updateScope('user')">
                        <i class="bi bi-person-workspace"></i> Worker
                    </button>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Target</label>
                <select class="form-select" name="target_id" id="reportTarget">
                    <option value="0">None</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" name="start_date" id="startDate" value="<?php echo htmlspecialchars($defaultStartDate); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" name="end_date" id="endDate" value="<?php echo htmlspecialchars($defaultEndDate); ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Reading Type</label>
                <select class="form-select" name="reading_type" id="readingType">
                    <option value="none">None</option>
                    <option value="both">Both</option>
                    <option value="morning">Morning only</option>
                    <option value="evening">Evening only</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Format</label>
                <select class="form-select" name="format" id="reportFormat">
                    <option value="pdf">PDF</option>
                    <option value="csv">CSV</option>
                    <option value="html">HTML Preview</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Generate Report
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="downloadReport()">
                    <i class="bi bi-download"></i> Download
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="printReport()">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Report Preview -->
<div class="report-preview" id="reportPreview">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold" id="reportTitle">Report Preview</h5>
        <div>
            <button class="btn btn-sm btn-outline-primary no-print" onclick="printReport()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>
    <div id="reportContent"></div>
</div>

<!-- Summary Cards -->
<div class="row g-3">
    <div class="col-md-4">
        <div class="report-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="report-icon daily"><i class="bi bi-calendar-day"></i></div>
                <div>
                    <h6 class="mb-0 fw-semibold">Daily Report</h6>
                    <div class="text-muted-small">Today's summary</div>
                </div>
            </div>
            <div class="d-flex justify-content-between">
                <span>Entries:</span>
                <strong><?php echo $today_stats['entries'] ?? 0; ?></strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Usage:</span>
                <strong><?php echo number_format($today_stats['units'] ?? 0, 1); ?> m³</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Revenue:</span>
                <strong class="text-success"><?php echo number_format($today_stats['cost'] ?? 0); ?> TSh</strong>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="report-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="report-icon weekly"><i class="bi bi-calendar-week"></i></div>
                <div>
                    <h6 class="mb-0 fw-semibold">Weekly Report</h6>
                    <div class="text-muted-small">Last 7 days</div>
                </div>
            </div>
            <div class="d-flex justify-content-between">
                <span>Entries:</span>
                <strong><?php echo $week_stats['entries'] ?? 0; ?></strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Usage:</span>
                <strong><?php echo number_format($week_stats['units'] ?? 0, 1); ?> m³</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Revenue:</span>
                <strong class="text-success"><?php echo number_format($week_stats['cost'] ?? 0); ?> TSh</strong>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="report-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="report-icon monthly"><i class="bi bi-calendar-month"></i></div>
                <div>
                    <h6 class="mb-0 fw-semibold">Monthly Report</h6>
                    <div class="text-muted-small">This month</div>
                </div>
            </div>
            <div class="d-flex justify-content-between">
                <span>Entries:</span>
                <strong><?php echo $month_stats['entries'] ?? 0; ?></strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Usage:</span>
                <strong><?php echo number_format($month_stats['units'] ?? 0, 1); ?> m³</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Revenue:</span>
                <strong class="text-success"><?php echo number_format($month_stats['cost'] ?? 0); ?> TSh</strong>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const reportTargetOptions = {
    all: <?php echo json_encode('<option value="0">None</option>'); ?>,
    category: <?php echo json_encode($categoryOptionsHtml); ?>,
    site: <?php echo json_encode($siteOptionsHtml); ?>,
    user: <?php echo json_encode($userOptionsHtml); ?>
};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function updateScope(scope) {
    const scopeInput = document.getElementById('reportScope');
    const targetSelect = document.getElementById('reportTarget');

    document.querySelectorAll('.report-option').forEach(function(button) {
        button.classList.toggle('active', button.dataset.scope === scope);
    });

    if (scopeInput) {
        scopeInput.value = scope;
    }

    if (!targetSelect) {
        return;
    }

    targetSelect.innerHTML = reportTargetOptions[scope] || reportTargetOptions.all;
    targetSelect.disabled = scope === 'all';

    if (scope === 'all') {
        targetSelect.value = '0';
    }
}

// ===== GENERATE REPORT =====
function generateReport() {
    const form = document.getElementById('reportForm');
    const formData = new FormData(form);
    formData.append('action', 'generate_report');
    
    document.getElementById('loadingOverlay').classList.add('show');
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseAjaxResponse)
    .then(data => {
        document.getElementById('loadingOverlay').classList.remove('show');
        
        if (data.success) {
            showToast('success', data.message);
            displayReport(data.report);
        } else {
            showToast('danger', data.message || 'An error occurred.');
        }
    })
    .catch(error => {
        document.getElementById('loadingOverlay').classList.remove('show');
        showToast('danger', 'Error: ' + error.message);
        console.error('Fetch Error:', error);
    });
    
    return false;
}

function parseAjaxResponse(response) {
    const contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        return response.json().then(data => {
            if (!response.ok) {
                throw new Error(data.message || ('Request failed with status ' + response.status));
            }

            return data;
        });
    }

    return response.text().then(text => {
        const preview = text.trim().slice(0, 160) || 'Unexpected non-JSON response.';
        throw new Error(preview);
    });
}

// ===== DISPLAY REPORT =====
function displayReport(report) {
    const preview = document.getElementById('reportPreview');
    const content = document.getElementById('reportContent');
    const title = document.getElementById('reportTitle');
    const showMorningReading = report.reading_type !== 'evening';
    const showEveningReading = report.reading_type !== 'morning';
    
    // Set title
    const typeNames = {
        'all': 'All Organization Report',
        'category': 'Category Report',
        'site': 'Site Report',
        'user': 'Worker Report'
    };
    const readingNames = {
        'none': 'All reading types',
        'morning': 'Morning only',
        'evening': 'Evening only',
        'both': 'Both readings'
    };
    title.textContent = typeNames[report.type] || 'Filtered Report';
    
    // Build report HTML
    let html = `
        <div class="report-header text-center mb-4">
            <h4 class="fw-bold" style="color:#1b5a7a;">
                <i class="bi bi-droplet-half"></i> NOLELO Clean Water Supply
            </h4>
            <p class="text-muted-small">${typeNames[report.type] || 'Filtered Report'}</p>
            <p class="text-muted-small">Target: ${escapeHtml(report.target_label || 'None')}</p>
            <p class="text-muted-small">Reading Type: ${readingNames[report.reading_type] || 'All reading types'}</p>
            <p class="text-muted-small">
                Period: ${formatDate(report.start_date)} - ${formatDate(report.end_date)}
            </p>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-3 text-center">
                    <div class="fw-bold fs-4">${report.summary.total_entries}</div>
                    <div class="text-muted-small">Total Entries</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-3 text-center">
                    <div class="fw-bold fs-4">${report.summary.total_workers}</div>
                    <div class="text-muted-small">Active Workers</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-3 text-center">
                    <div class="fw-bold fs-4">${report.summary.total_units.toFixed(1)} m³</div>
                    <div class="text-muted-small">Total Usage</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-3 text-center">
                    <div class="fw-bold fs-4 text-success">${formatCurrency(report.summary.total_cost)}</div>
                    <div class="text-muted-small">Total Revenue</div>
                </div>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Entries</th>
                        <th>Workers</th>
                        <th>Sites</th>
                        <th>Units (m³)</th>
                        <th>Cost (TSh)</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    report.data.forEach(row => {
        html += `
            <tr>
                <td>${formatDate(row.date)}</td>
                <td>${row.entries}</td>
                <td>${row.workers}</td>
                <td>${row.sites}</td>
                <td>${row.total_units ? row.total_units.toFixed(1) : '0.0'}</td>
                <td class="text-end">${row.total_cost ? formatCurrency(row.total_cost) : '0'}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td>Total</td>
                        <td>${report.summary.total_entries}</td>
                        <td>${report.summary.total_workers}</td>
                        <td>${report.summary.total_sites}</td>
                        <td>${report.summary.total_units.toFixed(1)}</td>
                        <td class="text-end">${formatCurrency(report.summary.total_cost)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <h6 class="fw-bold mt-4 mb-2">Meter Reading Details</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Worker</th>
                        <th>Site / Category</th>
                        <th>Reading Type</th>
                        ${showMorningReading ? '<th>Morning Reading (m³)</th>' : ''}
                        ${showEveningReading ? '<th>Evening Reading (m³)</th>' : ''}
                        <th>Units (m³)</th>
                        <th>Cost (TSh)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    report.entries.forEach(row => {
        const targetName = row.site_name || row.category_name || '-';
        html += `
            <tr>
                <td>${formatDate(row.entry_date)}</td>
                <td>${escapeHtml(row.worker_name || '-')}</td>
                <td>${escapeHtml(targetName)}</td>
                <td>${escapeHtml(capitalize(row.display_reading_type || row.reading_type || ''))}</td>
                ${showMorningReading ? `<td>${Number(row.morning_reading || 0).toFixed(1)}</td>` : ''}
                ${showEveningReading ? `<td>${Number(row.evening_reading || 0).toFixed(1)}</td>` : ''}
                <td>${Number(row.units_consumed || 0).toFixed(1)}</td>
                <td class="text-end">${formatCurrency(row.cost || 0)}</td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
        
        <div class="text-center text-muted-small mt-3">
            <i class="bi bi-info-circle"></i> 
            DAWASCO rate: ${formatRate(<?php echo (float)nolelo_setting('dawasco_rate', DAWASCO_RATE); ?>)} <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?> per unit
            <br>
            <small>Generated: ${report.generated_date}</small>
        </div>
    `;
    
    content.innerHTML = html;
    preview.classList.add('show');
    
    // Store report data for download
    window.reportData = report;
}

// ===== FORMAT HELPERS =====
function formatDate(date) {
    const d = new Date(date + 'T00:00:00');
    return d.toLocaleDateString('en-US', { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric' 
    });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US').format(amount);
}

function formatRate(amount) {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

function formatReading(value) {
    return Number(value ?? 0).toFixed(1);
}

function capitalize(value) {
    const text = String(value ?? '').trim();
    if (!text) {
        return '-';
    }

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function csvValue(value) {
    const text = String(value ?? '');
    return '"' + text.replace(/"/g, '""') + '"';
}

// ===== DOWNLOAD REPORT =====
function downloadReport() {
    const report = window.reportData;
    if (!report) {
        showToast('warning', 'Please generate a report first.');
        return;
    }
    
    const format = document.getElementById('reportFormat').value;
    
    if (format === 'pdf') {
        downloadReportPdf(report);
    } else if (format === 'csv') {
        // Generate CSV
        let csv = "Date,Entries,Workers,Sites,Units (m³),Cost (TSh)\n";
        report.data.forEach(row => {
            csv += `${row.date},${row.entries},${row.workers},${row.sites},${row.total_units ? row.total_units.toFixed(1) : '0.0'},${row.total_cost || '0'}\n`;
        });
        csv += `\nTotal,${report.summary.total_entries},${report.summary.total_workers},${report.summary.total_sites},${report.summary.total_units.toFixed(1)},${report.summary.total_cost}\n`;
        csv += "\nMeter Reading Details\n";
        const includeMorning = report.reading_type !== 'evening';
        const includeEvening = report.reading_type !== 'morning';
        const detailHeaders = ['Date', 'Worker', 'Site or Category', 'Reading Type'];
        if (includeMorning) detailHeaders.push('Morning Reading (m3)');
        if (includeEvening) detailHeaders.push('Evening Reading (m3)');
        detailHeaders.push('Units (m3)', 'Cost (TSh)');
        csv += detailHeaders.join(',') + "\n";
        report.entries.forEach(row => {
            const targetName = row.site_name || row.category_name || '-';
            const detailValues = [
                row.entry_date,
                csvValue(row.worker_name || '-'),
                csvValue(targetName),
                csvValue(capitalize(row.display_reading_type || row.reading_type || '-'))
            ];
            if (includeMorning) detailValues.push(Number(row.morning_reading || 0).toFixed(1));
            if (includeEvening) detailValues.push(Number(row.evening_reading || 0).toFixed(1));
            detailValues.push(
                Number(row.units_consumed || 0).toFixed(1),
                row.cost || 0
            );
            csv += detailValues.join(',') + "\n";
        });
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `report_${report.type}_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        
        showToast('success', 'CSV report downloaded successfully!');
    } else if (format === 'html') {
        // Show preview (already showing)
        showToast('info', 'HTML preview is already displayed above.');
    }
}

function downloadReportPdf(report) {
    if (typeof html2pdf === 'undefined') {
        showToast('danger', 'PDF library did not load. Please refresh and try again.');
        return;
    }

    const source = document.getElementById('reportContent');
    if (!source || !source.innerHTML.trim()) {
        showToast('warning', 'Please generate a report first.');
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.style.position = 'fixed';
    wrapper.style.left = '-10000px';
    wrapper.style.top = '0';
    wrapper.style.width = '190mm';

    const clone = source.cloneNode(true);
    clone.removeAttribute('id');
    clone.classList.add('report-pdf');
    wrapper.appendChild(clone);
    document.body.appendChild(wrapper);

    const filename = `report_${String(report.type || 'custom').replace(/[^a-z0-9_-]+/gi, '_')}_${new Date().toISOString().slice(0, 10)}.pdf`;
    showToast('info', 'Preparing PDF download...');

    html2pdf()
        .set({
            margin: [8, 8, 8, 8],
            filename: filename,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.report-header'] },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        })
        .from(clone)
        .save()
        .then(function() {
            showToast('success', 'PDF report downloaded successfully.');
        })
        .catch(function(error) {
            showToast('danger', 'PDF download failed: ' + error.message);
        })
        .finally(function() {
            if (wrapper.parentNode) {
                wrapper.parentNode.removeChild(wrapper);
            }
        });
}

// ===== PRINT REPORT =====
function printReport() {
    const preview = document.getElementById('reportPreview');
    if (!preview.classList.contains('show')) {
        showToast('warning', 'Please generate a report first.');
        return;
    }
    
    const printWindow = window.open('', '_blank', 'width=1000,height=800');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Report - NOLELO Water Supply</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <style>
                body { padding: 40px; font-family: Arial, sans-serif; }
                @media print { .no-print { display: none !important; } }
            </style>
        </head>
        <body>
            ${document.getElementById('reportContent').innerHTML}
            <script>
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                    }, 500);
                };
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
    
    showToast('success', 'Print job sent successfully!');
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
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" onclick="hideToast('${toastId}')"></button>
        </div>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        hideToast(toastId);
    }, 3000);
}

function hideToast(toastId) {
    const toast = document.getElementById(toastId);
    if (toast) {
        toast.classList.add('hide');
        setTimeout(() => {
            toast.remove();
        }, 500);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateScope(document.getElementById('reportScope')?.value || 'all');
});
</script>
