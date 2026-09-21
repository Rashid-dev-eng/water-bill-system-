<?php
if (!function_exists('nolelo_worker_bills_escape')) {
    function nolelo_worker_bills_escape($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nolelo_worker_bills_time')) {
    function nolelo_worker_bills_time(array $bill = null) {
        if (!$bill || empty($bill['generated_at'])) {
            return date('d M Y H:i:s');
        }

        return date('d M Y H:i:s', strtotime($bill['generated_at']));
    }
}

if (!function_exists('nolelo_worker_bills_reading_display')) {
    function nolelo_worker_bills_reading_display(array $entry) {
        $type = $entry['reading_type'] ?? 'morning';
        $morning = (float)($entry['morning_reading'] ?? 0);
        $evening = (float)($entry['evening_reading'] ?? 0);

        if ($type === 'evening') {
            return number_format($evening > 0 ? $evening : $morning, 1);
        }

        if ($type === 'both') {
            return 'M: ' . number_format($morning, 1) . ' / E: ' . number_format($evening, 1);
        }

        return number_format($morning > 0 ? $morning : $evening, 1);
    }
}

if (!function_exists('nolelo_worker_bills_apply_reading_filter')) {
    function nolelo_worker_bills_apply_reading_filter(array &$entry, $readingType) {
        $displayType = in_array($readingType, ['morning', 'evening'], true)
            ? $readingType
            : ($entry['reading_type'] ?? 'morning');
        $entry['display_reading_type'] = $displayType;
        $entry['reading_display'] = $displayType === 'evening'
            ? number_format((float)($entry['evening_reading'] ?? 0), 1)
            : ($displayType === 'both'
                ? 'M: ' . number_format((float)($entry['morning_reading'] ?? 0), 1) . ' / E: ' . number_format((float)($entry['evening_reading'] ?? 0), 1)
                : number_format((float)($entry['morning_reading'] ?? 0), 1));
    }
}

if (!function_exists('nolelo_worker_bills_render_period')) {
    function nolelo_worker_bills_render_period($startDate, $endDate) {
        return date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate));
    }
}

$worker = getWorkerData();
if (!$worker) {
    echo '<div class="alert alert-danger flash-message">Worker profile not found.</div>';
    return;
}

$access = getWorkerAccessProfile($_SESSION['user_id']);
$categoryOptions = $access['has_category_assignment'] ? $access['assigned_categories'] : $access['categories'];
$siteOptions = $access['has_site_assignment'] ? $access['assigned_sites'] : $access['sites'];

$scope = $_GET['scope'] ?? 'all';
$scope = in_array($scope, ['all', 'category', 'site'], true) ? $scope : 'all';
$targetId = (int)($_GET['target_id'] ?? 0);
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$readingType = $_GET['reading_type'] ?? 'none';
$readingType = in_array($readingType, ['none', 'morning', 'evening', 'both'], true) ? $readingType : 'none';

$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function nolelo_worker_bills_query_entries($workerId, $scope, $targetId, $startDate, $endDate, $readingType, $access, &$resolvedScopeLabel = 'All organization', &$resolvedTargetLabel = '') {
    $whereParts = ['we.user_id = ?'];
    $params = [$workerId];

    $categoryOptions = $access['has_category_assignment'] ? $access['assigned_categories'] : $access['categories'];
    $siteOptions = $access['has_site_assignment'] ? $access['assigned_sites'] : $access['sites'];
    $categoryIds = array_map('intval', array_column($categoryOptions, 'id'));
    $siteIds = array_map('intval', array_column($siteOptions, 'id'));

    // Never expose a worker's unassigned historical rows, even if old data exists.
    $assignmentParts = [];
    if (!empty($siteIds)) {
        $sitePlaceholders = implode(',', array_fill(0, count($siteIds), '?'));
        $assignmentParts[] = "we.site_id IN ($sitePlaceholders)";
        $params = array_merge($params, $siteIds);
    }
    if (!empty($categoryIds)) {
        $categoryPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $assignmentParts[] = "(we.site_id IS NULL AND we.category_id IN ($categoryPlaceholders))";
        $params = array_merge($params, $categoryIds);
    }
    $whereParts[] = !empty($assignmentParts) ? '(' . implode(' OR ', $assignmentParts) . ')' : '1 = 0';

    if ($scope === 'category') {
        $resolvedScopeLabel = 'Category';
        if ($targetId > 0) {
            if (!in_array($targetId, $categoryIds, true)) {
                $whereParts[] = '1 = 0';
            } else {
                $whereParts[] = 'we.category_id = ?';
                $params[] = $targetId;
            }
            $targetRow = dbFetchOne("SELECT name FROM categories WHERE id = ?", [$targetId]);
            $resolvedTargetLabel = $targetRow['name'] ?? 'Selected category';
        } elseif (!empty($categoryOptions)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $whereParts[] = "we.category_id IN ($placeholders)";
            $params = array_merge($params, $categoryIds);
            $resolvedTargetLabel = 'All assigned categories';
        }
    } elseif ($scope === 'site') {
        $resolvedScopeLabel = 'Site';
        if ($targetId > 0) {
            if (!in_array($targetId, $siteIds, true)) {
                $whereParts[] = '1 = 0';
            } else {
                $whereParts[] = 'we.site_id = ?';
                $params[] = $targetId;
            }
            $targetRow = dbFetchOne("SELECT name FROM sites WHERE id = ?", [$targetId]);
            $resolvedTargetLabel = $targetRow['name'] ?? 'Selected site';
        } elseif (!empty($siteOptions)) {
            $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
            $whereParts[] = "we.site_id IN ($placeholders)";
            $params = array_merge($params, $siteIds);
            $resolvedTargetLabel = 'All assigned sites';
        }
    } else {
        $resolvedScopeLabel = 'Assigned readings';
        $resolvedTargetLabel = 'Assigned categories and sites';
    }

    if ($startDate !== '') {
        $whereParts[] = 'we.entry_date >= ?';
        $params[] = $startDate;
    }

    if ($endDate !== '') {
        $whereParts[] = 'we.entry_date <= ?';
        $params[] = $endDate;
    }

    if ($readingType === 'morning') {
        $whereParts[] = "we.reading_type IN ('morning', 'both')";
    } elseif ($readingType === 'evening') {
        $whereParts[] = "we.reading_type IN ('evening', 'both')";
    } elseif ($readingType === 'both') {
        $whereParts[] = "we.reading_type = 'both'";
    }

    $entries = dbFetchAll("
        SELECT
            we.*,
            CASE WHEN we.site_id IS NULL THEN 'category' ELSE 'site' END as scope_type,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name
        FROM water_entries we
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY we.entry_date DESC, we.created_at DESC
    ", $params);

    $currentRate = (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
    foreach ($entries as &$entry) {
        $entry['cost'] = (float)($entry['units_consumed'] ?? 0) * $currentRate;
        nolelo_worker_bills_apply_reading_filter($entry, $readingType);
    }
    unset($entry);

    return $entries;
}

$resolvedScopeLabel = '';
$resolvedTargetLabel = '';

// Handle AJAX bill actions before rendering HTML.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Unknown action'];

    try {
        global $pdo;

        if (nolelo_setting('maintenance_mode', 'false') === 'true' && in_array($action, ['generate_bill', 'delete_bill'], true)) {
            throw new Exception('The system is temporarily in maintenance mode. Please try again later.');
        }

        if ($action === 'generate_bill') {
            $startDate = trim($_POST['start_date'] ?? date('Y-m-01'));
            $endDate = trim($_POST['end_date'] ?? date('Y-m-d'));
            $scope = $_POST['scope'] ?? 'all';
            $scope = in_array($scope, ['all', 'category', 'site'], true) ? $scope : 'all';
            $targetId = (int)($_POST['target_id'] ?? 0);
            $readingType = $_POST['reading_type'] ?? 'none';
            $readingType = in_array($readingType, ['none', 'morning', 'evening', 'both'], true) ? $readingType : 'none';

            $resolvedScopeLabel = '';
            $resolvedTargetLabel = '';
            $entries = nolelo_worker_bills_query_entries(
                $_SESSION['user_id'],
                $scope,
                $targetId,
                $startDate,
                $endDate,
                $readingType,
                $access,
                $resolvedScopeLabel,
                $resolvedTargetLabel
            );

            if (empty($entries)) {
                throw new Exception('No entries found for the selected filters.');
            }

            $totalUnits = array_sum(array_map(static function ($entry) {
                return (float)($entry['units_consumed'] ?? 0);
            }, $entries));
            $totalCost = array_sum(array_map(static function ($entry) {
                return (float)($entry['cost'] ?? 0);
            }, $entries));

            $billNumber = 'INV-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();

            $billId = dbInsert('bills', [
                'bill_number' => $billNumber,
                'user_id' => $_SESSION['user_id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reading_type' => $readingType,
                'category_id' => $scope === 'category' && $targetId > 0 ? $targetId : null,
                'site_id' => $scope === 'site' && $targetId > 0 ? $targetId : null,
                'total_units' => $totalUnits,
                'rate_per_unit' => (float)nolelo_setting('dawasco_rate', DAWASCO_RATE),
                'total_amount' => $totalCost,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'net_amount' => $totalCost,
                'status' => 'generated',
                'generated_by' => $_SESSION['user_id'],
                'notes' => 'Generated from worker portal',
            ]);

            if (!$billId) {
                throw new Exception('Failed to save bill header.');
            }

            foreach ($entries as $entry) {
                $inserted = dbInsert('bill_items', [
                    'bill_id' => $billId,
                    'water_entry_id' => $entry['id'],
                    'entry_date' => $entry['entry_date'],
                    'units' => $entry['units_consumed'],
                    'amount' => $entry['cost'],
                ]);

                if (!$inserted) {
                    throw new Exception('Failed to save bill items.');
                }
            }

            $pdo->commit();

            $response = [
                'success' => true,
                'message' => 'Bill generated successfully.',
                'bill' => [
                    'bill_id' => (int)$billId,
                    'bill_number' => $billNumber,
                    'worker_name' => $worker['full_name'] ?? 'Worker',
                    'worker_email' => $worker['email'] ?? '',
                    'scope_label' => $resolvedScopeLabel,
                    'target_label' => $resolvedTargetLabel,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reading_type' => $readingType,
                    'generated_at' => date('Y-m-d H:i:s'),
                    'entries' => $entries,
                    'total_units' => $totalUnits,
                    'total_cost' => $totalCost,
                    'rate_per_unit' => (float)nolelo_setting('dawasco_rate', DAWASCO_RATE),
                    'status' => 'generated',
                    'generated_by_name' => $worker['full_name'] ?? 'Worker',
                ],
            ];
            logWorkerActivity($_SESSION['user_id'], 'bill_generated', 'Worker generated a bill.', ['bill_id' => (int)$billId, 'start_date' => $startDate, 'end_date' => $endDate, 'reading_type' => $readingType]);
        } elseif ($action === 'view_bill') {
            $billId = (int)($_POST['bill_id'] ?? 0);
            if ($billId <= 0) {
                throw new Exception('Invalid bill selected.');
            }

            $billRow = dbFetchOne("
                SELECT
                    b.*,
                    u.full_name as worker_name,
                    u.email as worker_email,
                    s.name as site_name,
                    c.name as category_name,
                    g.full_name as generated_by_name
                FROM bills b
                LEFT JOIN users u ON b.user_id = u.id
                LEFT JOIN sites s ON b.site_id = s.id
                LEFT JOIN categories c ON b.category_id = c.id
                LEFT JOIN users g ON b.generated_by = g.id
                WHERE b.id = ? AND b.user_id = ?
                LIMIT 1
            ", [$billId, $_SESSION['user_id']]);

            if (!$billRow) {
                throw new Exception('Bill not found.');
            }

            $billItems = dbFetchAll("
                SELECT
                    bi.*,
                    we.reading_type,
                    we.morning_reading,
                    we.evening_reading,
                    we.category_id,
                    we.site_id,
                    s.name as site_name,
                    COALESCE(c_entry.name, c_site.name) as category_name,
                    CASE WHEN we.site_id IS NULL THEN 'category' ELSE 'site' END as scope_type
                FROM bill_items bi
                JOIN water_entries we ON bi.water_entry_id = we.id
                LEFT JOIN sites s ON we.site_id = s.id
                LEFT JOIN categories c_entry ON we.category_id = c_entry.id
                LEFT JOIN categories c_site ON s.category_id = c_site.id
                WHERE bi.bill_id = ?
                ORDER BY bi.entry_date ASC, bi.id ASC
            ", [$billId]);

            $billReadingType = $billRow['reading_type'] ?? 'none';
            $entries = array_map(static function ($entry) use ($billReadingType) {
                nolelo_worker_bills_apply_reading_filter($entry, $billReadingType);
                return $entry;
            }, $billItems);

            $response = [
                'success' => true,
                'message' => 'Bill loaded successfully.',
                'bill' => [
                    'bill_id' => (int)$billRow['id'],
                    'bill_number' => $billRow['bill_number'],
                    'worker_name' => $billRow['worker_name'] ?? 'Worker',
                    'worker_email' => $billRow['worker_email'] ?? '',
                    'scope_label' => !empty($billRow['site_id']) ? 'Site' : (!empty($billRow['category_id']) ? 'Category' : 'All organization'),
                    'target_label' => $billRow['site_name'] ?? ($billRow['category_name'] ?? 'Assigned readings'),
                    'start_date' => $billRow['start_date'],
                    'end_date' => $billRow['end_date'],
                    'reading_type' => $billRow['reading_type'] ?? 'none',
                    'generated_at' => $billRow['generated_at'] ?? date('Y-m-d H:i:s'),
                    'entries' => $entries,
                    'total_units' => (float)($billRow['total_units'] ?? 0),
                    'total_cost' => (float)($billRow['total_amount'] ?? 0),
                    'rate_per_unit' => (float)($billRow['rate_per_unit'] ?? nolelo_setting('dawasco_rate', DAWASCO_RATE)),
                    'status' => $billRow['status'] ?? 'generated',
                    'generated_by_name' => $billRow['generated_by_name'] ?? 'Worker',
                ],
            ];
            logWorkerActivity($_SESSION['user_id'], 'bill_viewed', 'Worker viewed a bill.', ['bill_id' => $billId]);
        } elseif ($action === 'delete_bill') {
            $billId = (int)($_POST['bill_id'] ?? 0);
            if ($billId <= 0) {
                throw new Exception('Invalid bill selected.');
            }

            $billRow = dbFetchOne("SELECT id FROM bills WHERE id = ? AND user_id = ?", [$billId, $_SESSION['user_id']]);
            if (!$billRow) {
                throw new Exception('Bill not found.');
            }

            $deleted = dbDelete('bills', 'id = :id AND user_id = :user_id', [
                'id' => $billId,
                'user_id' => $_SESSION['user_id'],
            ]);

            if (!$deleted) {
                throw new Exception('Failed to delete bill.');
            }

            $response = [
                'success' => true,
                'message' => 'Bill deleted successfully.',
                'bill_id' => $billId,
            ];
            logWorkerActivity($_SESSION['user_id'], 'bill_deleted', 'Worker deleted a bill.', ['bill_id' => $billId]);
        } else {
            throw new Exception('Invalid action.');
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $response = [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }

    if ($isAjaxRequest) {
        nolelo_worker_json_response($response, empty($response['success']) ? 422 : 200);
    }
}

$bill_history = dbFetchAll("
    SELECT
        b.*,
        u.full_name as worker_name,
        s.name as site_name,
        c.name as category_name
    FROM bills b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN sites s ON b.site_id = s.id
    LEFT JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ?
    ORDER BY b.generated_at DESC, b.id DESC
    LIMIT 20
", [$_SESSION['user_id']]);

$categoryOptionsHtml = '<option value="0">None</option>';
foreach ($categoryOptions as $category) {
    $categoryOptionsHtml .= '<option value="' . (int)$category['id'] . '">' . nolelo_worker_bills_escape($category['name']) . '</option>';
}

$siteOptionsHtml = '<option value="0">None</option>';
foreach ($siteOptions as $site) {
    $siteOptionsHtml .= '<option value="' . (int)$site['id'] . '">' . nolelo_worker_bills_escape($site['name']) . '</option>';
}
?>

<style>
.bill-hero {
    background: linear-gradient(135deg, #0b2b3f 0%, #1a5a7a 100%);
    border-radius: 24px;
    padding: 1.75rem 2rem;
    color: #fff;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.bill-hero::after {
    content: '';
    position: absolute;
    right: -70px;
    top: -70px;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,195,247,0.18), transparent 70%);
}
.bill-panel, .bill-history-panel, .bill-preview-shell {
    background: #fff;
    border-radius: 20px;
    padding: 1.25rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    border: 1px solid rgba(27,90,122,0.08);
    margin-bottom: 1.25rem;
}
.bill-preview {
    background: linear-gradient(135deg, #f8fafc, #e8f0f8);
    border-radius: 18px;
    padding: 1.5rem;
    border: 2px solid #1b5a7a;
}
.bill-preview .bill-header {
    border-bottom: 2px solid #1b5a7a;
    padding-bottom: 1rem;
    margin-bottom: 1rem;
}
.bill-item {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.45rem 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}
.bill-total {
    font-size: 1.2rem;
    font-weight: 800;
    color: #1b5a7a;
}
.bill-toolbar {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.bill-option {
    cursor: pointer;
    transition: 0.15s;
}
.bill-option.active {
    background: #e3f0fa;
    border-color: #1b5a7a;
}
.scope-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    border-radius: 999px;
    padding: 0.25rem 0.7rem;
    font-size: 0.75rem;
    font-weight: 700;
}
.scope-badge.category { background: #d2e7f5; color: #0b2b3f; }
.scope-badge.site { background: #e8f5e9; color: #1b5e20; }
.entry-badge {
    padding: 0.2rem 0.75rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
}
.entry-badge.morning { background: #d2e7f5; color: #0b2b3f; }
.entry-badge.evening { background: #ffe082; color: #7a5a00; }
.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
}
.status-badge.generated { background: #d2e7f5; color: #0b2b3f; }
.status-badge.paid { background: #d4edda; color: #155724; }
.filter-grid .form-control,
.filter-grid .form-select {
    border-radius: 12px;
    border: 2px solid #e9eff4;
}
.filter-grid .form-control:focus,
.filter-grid .form-select:focus {
    border-color: #1b5a7a;
    box-shadow: 0 0 0 4px rgba(27,90,122,0.1);
}
.table-wrap {
    overflow-x: auto;
}
.bill-history-table {
    min-width: 920px;
}
.pdf-export {
    width: 190mm !important;
    max-width: 190mm !important;
    box-sizing: border-box !important;
    padding: 8mm !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    overflow: visible !important;
}
.pdf-export .table-responsive {
    overflow: visible !important;
}
.pdf-export .bill-history-table {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    font-size: 7.5pt !important;
}
.pdf-export .bill-history-table th,
.pdf-export .bill-history-table td {
    padding: 3px 4px !important;
    overflow-wrap: anywhere;
    word-break: normal;
}
.pdf-export .bill-history-table thead { display: table-header-group; }
.pdf-export .bill-history-table tr { break-inside: avoid; page-break-inside: avoid; }
.pdf-export .bill-header,
.pdf-export .bill-item { break-inside: avoid; page-break-inside: avoid; }
@media (max-width: 767.98px) {
    .bill-hero { padding: 1.3rem; }
    .bill-panel, .bill-history-panel, .bill-preview-shell { padding: 1rem; }
}
</style>

<div class="bill-hero">
    <div class="row align-items-center g-3">
        <div class="col-lg-8">
            <h4 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff me-2"></i>My Bills</h4>
            <div class="opacity-75 mt-2">Generate a bill from your own readings, view the details, download the PDF, or delete a specific bill.</div>
        </div>
        <div class="col-lg-4 text-lg-end">
            <span class="badge rounded-pill" style="background:rgba(255,255,255,0.15);color:#fff;padding:0.45rem 1rem;">Worker: <?php echo nolelo_worker_bills_escape($worker['full_name'] ?? 'Worker'); ?></span>
        </div>
    </div>
</div>

<div class="bill-panel">
    <form id="billForm" onsubmit="return generateBill();">
        <div class="row g-3 filter-grid">
            <div class="col-md-3">
                <label class="form-label">Organization</label>
                <select class="form-select form-select-sm" name="scope" id="billScope">
                    <option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>All organization</option>
                    <?php if ($access['is_full_access'] || $access['has_category_assignment']): ?>
                    <option value="category" <?php echo $scope === 'category' ? 'selected' : ''; ?>>Category</option>
                    <?php endif; ?>
                    <?php if ($access['is_full_access'] || $access['has_site_assignment']): ?>
                    <option value="site" <?php echo $scope === 'site' ? 'selected' : ''; ?>>Site</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3" id="billTargetWrap">
                <label class="form-label">Target</label>
                <select class="form-select form-select-sm" name="target_id" id="billTarget">
                    <?php if ($scope === 'category'): ?>
                        <?php echo $categoryOptionsHtml; ?>
                    <?php elseif ($scope === 'site'): ?>
                        <?php echo $siteOptionsHtml; ?>
                    <?php else: ?>
                        <option value="0">None</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control form-control-sm" name="start_date" id="billStartDate" value="<?php echo nolelo_worker_bills_escape($startDate); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control form-control-sm" name="end_date" id="billEndDate" value="<?php echo nolelo_worker_bills_escape($endDate); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Reading Type</label>
                <select class="form-select form-select-sm" name="reading_type" id="billReadingType">
                    <option value="none" <?php echo $readingType === 'none' ? 'selected' : ''; ?>>None</option>
                    <option value="morning" <?php echo $readingType === 'morning' ? 'selected' : ''; ?>>Morning</option>
                    <option value="evening" <?php echo $readingType === 'evening' ? 'selected' : ''; ?>>Evening</option>
                    <option value="both" <?php echo $readingType === 'both' ? 'selected' : ''; ?>>Both</option>
                </select>
            </div>
            <div class="col-12">
                <div class="bill-toolbar">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-file-earmark-text me-1"></i>Generate Bill
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="downloadCurrentBillPdf();">
                        <i class="bi bi-download me-1"></i>Download PDF
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearCurrentBillPreview();">
                        <i class="bi bi-x-circle me-1"></i>Clear Preview
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="bill-preview-shell" id="billPreviewShell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-0 fw-bold"><i class="bi bi-eye me-2" style="color:#1b5a7a;"></i>Bill Preview</h5>
            <div class="text-muted-small">The preview updates after you generate or open a bill.</div>
        </div>
    </div>
    <div id="billPreviewContainer">
        <div class="text-center text-muted py-4">Generate or open a bill to see the preview here.</div>
    </div>
</div>

<div class="bill-history-panel">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2" style="color:#1b5a7a;"></i>Bill History</h5>
            <div class="text-muted-small">Your latest 20 bills are listed below.</div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table table-modern bill-history-table align-middle" id="billHistoryTable">
            <thead>
                <tr>
                    <th>Bill Number</th>
                    <th>Scope</th>
                    <th>Target</th>
                    <th>Period</th>
                    <th>Units</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bill_history)): ?>
                <tr id="noBillsRow">
                    <td colspan="8" class="text-center py-4 text-muted">No bills generated yet.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($bill_history as $bill): ?>
                <tr id="billRow_<?php echo (int)$bill['id']; ?>">
                    <td class="fw-semibold"><?php echo nolelo_worker_bills_escape($bill['bill_number']); ?></td>
                    <td>
                        <span class="scope-badge <?php echo !empty($bill['site_id']) ? 'site' : 'category'; ?>">
                            <?php echo !empty($bill['site_id']) ? 'Site' : (!empty($bill['category_id']) ? 'Category' : 'All organization'); ?>
                        </span>
                    </td>
                    <td><?php echo nolelo_worker_bills_escape($bill['site_name'] ?? ($bill['category_name'] ?? 'Assigned readings')); ?></td>
                    <td><?php echo nolelo_worker_bills_escape(nolelo_worker_bills_render_period($bill['start_date'], $bill['end_date'])); ?></td>
                    <td><?php echo number_format((float)($bill['total_units'] ?? 0), 1); ?> m3</td>
                    <td><strong><?php echo number_format((float)($bill['total_amount'] ?? 0)); ?> TSh</strong></td>
                    <td><span class="status-badge <?php echo nolelo_worker_bills_escape($bill['status'] ?? 'generated'); ?>"><?php echo ucfirst(nolelo_worker_bills_escape($bill['status'] ?? 'generated')); ?></span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="viewBill(<?php echo (int)$bill['id']; ?>)">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="downloadBillPdf(<?php echo (int)$bill['id']; ?>)">
                            <i class="bi bi-download"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteBill(<?php echo (int)$bill['id']; ?>)">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
const workerCategoryOptionsHtml = <?php echo json_encode($categoryOptionsHtml); ?>;
const workerSiteOptionsHtml = <?php echo json_encode($siteOptionsHtml); ?>;

function parseBillAjaxResponse(response) {
    if (response.status === 401) {
        window.location.href = 'login.php';
        throw new Error('Session expired. Please log in again.');
    }

    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        return response.json().then(function(data) {
            if (!response.ok) {
                throw new Error(data.message || ('Request failed with status ' + response.status));
            }

            return data;
        });
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

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function buildBillPreviewMarkup(bill) {
    const entries = Array.isArray(bill.entries) ? bill.entries : [];
    const rows = entries.map(function(entry) {
        const reading = entry.reading_display || (entry.reading_type === 'evening'
            ? Number(entry.evening_reading || 0).toFixed(1)
            : entry.reading_type === 'both'
                ? 'M: ' + Number(entry.morning_reading || 0).toFixed(1) + ' / E: ' + Number(entry.evening_reading || 0).toFixed(1)
                : Number(entry.morning_reading || 0).toFixed(1));

        return `
            <tr>
                <td>${escapeHtml(entry.entry_date ? new Date(entry.entry_date + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-')}</td>
                <td>${escapeHtml(entry.scope_type === 'site' ? 'Site' : 'Category')}</td>
                <td>${escapeHtml(entry.category_name || '-')}</td>
                <td>${escapeHtml(entry.site_name || '-')}</td>
                <td>${escapeHtml(entry.display_reading_type || entry.reading_type || 'morning')}</td>
                <td>${escapeHtml(reading)}</td>
                <td>${escapeHtml(Number(entry.units || entry.units_consumed || 0).toFixed(1))}</td>
                <td>${escapeHtml(Number(entry.amount || entry.cost || 0).toFixed(0))}</td>
            </tr>
        `;
    }).join('');

    return `
        <div class="bill-preview" data-bill-id="${escapeHtml(bill.bill_id || '')}">
            <div class="bill-header text-center">
                <h3 class="fw-bold mb-1" style="color:#1b5a7a;"><i class="bi bi-droplet-half"></i> NOLELO Clean Water Supply</h3>
                <p class="mb-0 text-muted">Water Bill Statement</p>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="bill-item"><span>Bill Number:</span><strong>${escapeHtml(bill.bill_number || '')}</strong></div>
                    <div class="bill-item"><span>Worker:</span><strong>${escapeHtml(bill.worker_name || '')}</strong></div>
                    <div class="bill-item"><span>Worker Email:</span><strong>${escapeHtml(bill.worker_email || '-')}</strong></div>
                    <div class="bill-item"><span>Scope:</span><strong>${escapeHtml(bill.scope_label || 'All organization')}</strong></div>
                </div>
                <div class="col-md-6">
                    <div class="bill-item"><span>Target:</span><strong>${escapeHtml(bill.target_label || 'Assigned readings')}</strong></div>
                    <div class="bill-item"><span>Period:</span><strong>${escapeHtml(formatBillDate(bill.start_date))} - ${escapeHtml(formatBillDate(bill.end_date))}</strong></div>
                    <div class="bill-item"><span>Reading Type:</span><strong>${escapeHtml((bill.reading_type || 'none').charAt(0).toUpperCase() + (bill.reading_type || 'none').slice(1))}</strong></div>
                    <div class="bill-item"><span>Generated At:</span><strong>${escapeHtml(formatBillDateTime(bill.generated_at || ''))}</strong></div>
                </div>
            </div>
            <hr>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle bill-history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Scope</th>
                            <th>Category</th>
                            <th>Site</th>
                            <th>Type</th>
                            <th>Reading</th>
                            <th>Units</th>
                            <th>Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || '<tr><td colspan="8" class="text-center text-muted">No entry rows available.</td></tr>'}
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="6" class="text-end">Total</td>
                            <td>${escapeHtml(Number(bill.total_units || 0).toFixed(1))} m3</td>
                    <td>${escapeHtml(Number(bill.total_cost || 0).toFixed(0))} <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-4">
                    <div class="bill-item"><span>Total Units Consumed:</span><strong class="bill-total">${escapeHtml(Number(bill.total_units || 0).toFixed(1))} m3</strong></div>
                </div>
                <div class="col-md-4">
                    <div class="bill-item"><span>Rate per Unit:</span><strong>${escapeHtml(Number(bill.rate_per_unit || 0).toFixed(2))} <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></strong></div>
                </div>
                <div class="col-md-4">
                    <div class="bill-item"><span>Total Amount:</span><strong class="bill-total text-success">${escapeHtml(Number(bill.total_cost || 0).toFixed(0))} <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></strong></div>
                </div>
            </div>
            <div class="mt-3 text-center text-muted">
                <small>Generated by ${escapeHtml(bill.generated_by_name || bill.worker_name || '')}. This is a computer-generated bill.</small>
            </div>
        </div>
    `;
}

function formatBillDate(dateValue) {
    if (!dateValue) {
        return '-';
    }

    const date = new Date(dateValue + 'T00:00:00');
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

function formatBillDateTime(dateValue) {
    if (!dateValue) {
        return '-';
    }

    const date = new Date(dateValue.replace(' ', 'T'));
    return date.toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

function displayBillPreview(bill) {
    const container = document.getElementById('billPreviewContainer');
    if (!container) {
        return;
    }

    window.currentBillData = bill;
    container.innerHTML = buildBillPreviewMarkup(bill);

    const shell = document.getElementById('billPreviewShell');
    if (shell) {
        shell.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function buildHistoryRow(bill) {
    const scopeClass = bill.scope_label === 'Site' ? 'site' : 'category';
    return `
        <tr id="billRow_${escapeHtml(bill.bill_id)}">
            <td class="fw-semibold">${escapeHtml(bill.bill_number || '')}</td>
            <td><span class="scope-badge ${scopeClass}">${escapeHtml(bill.scope_label || 'All organization')}</span></td>
            <td>${escapeHtml(bill.target_label || 'Assigned readings')}</td>
            <td>${escapeHtml(formatBillDate(bill.start_date) + ' - ' + formatBillDate(bill.end_date))}</td>
            <td>${escapeHtml(Number(bill.total_units || 0).toFixed(1))} m3</td>
                        <td><strong>${escapeHtml(Number(bill.total_cost || 0).toFixed(0))} <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></strong></td>
            <td><span class="status-badge ${escapeHtml(bill.status || 'generated')}">${escapeHtml((bill.status || 'generated').charAt(0).toUpperCase() + (bill.status || 'generated').slice(1))}</span></td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="viewBill(${escapeHtml(bill.bill_id)})"><i class="bi bi-eye"></i></button>
                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="downloadBillPdf(${escapeHtml(bill.bill_id)})"><i class="bi bi-download"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteBill(${escapeHtml(bill.bill_id)})"><i class="bi bi-trash3"></i></button>
            </td>
        </tr>
    `;
}

function updateBillTargetOptions() {
    const scope = document.getElementById('billScope');
    const targetWrap = document.getElementById('billTargetWrap');
    const target = document.getElementById('billTarget');

    if (!scope || !targetWrap || !target) {
        return;
    }

    if (scope.value === 'all') {
        targetWrap.style.display = 'none';
        target.value = '0';
        return;
    }

    targetWrap.style.display = '';
    target.innerHTML = scope.value === 'category' ? workerCategoryOptionsHtml : workerSiteOptionsHtml;
}

function generateBill() {
    const form = document.getElementById('billForm');
    const formData = new FormData(form);
    formData.set('action', 'generate_bill');

    fetch('index.php?page=bills', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseBillAjaxResponse)
    .then(function(data) {
        if (data.success) {
            showToast('success', data.message || 'Bill generated successfully.');
            displayBillPreview(data.bill);

            const historyBody = document.querySelector('#billHistoryTable tbody');
            const emptyRow = document.getElementById('noBillsRow');
            if (historyBody) {
                if (emptyRow) {
                    emptyRow.remove();
                }

                historyBody.insertAdjacentHTML('afterbegin', buildHistoryRow(data.bill));
            }
        } else {
            showToast('danger', data.message || 'Unable to generate bill.');
        }
    })
    .catch(function(error) {
        showToast('danger', 'Error: ' + error.message);
        console.error('Generate bill error:', error);
    });

    return false;
}

function downloadCurrentBillPdf() {
    if (!window.currentBillData) {
        showToast('warning', 'Please generate or open a bill first.');
        return;
    }

    downloadBillPdf(window.currentBillData);
}

function downloadBillPdf(billId) {
    if (typeof billId === 'object' && billId !== null) {
        downloadBillPdfFromData(billId);
        return;
    }

    const formData = new FormData();
    formData.set('action', 'view_bill');
    formData.set('bill_id', billId);

    fetch('index.php?page=bills', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseBillAjaxResponse)
    .then(function(data) {
        if (data.success) {
            downloadBillPdfFromData(data.bill);
        } else {
            showToast('danger', data.message || 'Unable to load bill.');
        }
    })
    .catch(function(error) {
        showToast('danger', 'Error: ' + error.message);
        console.error('Download bill error:', error);
    });
}

function downloadBillPdfFromData(bill) {
    if (typeof html2pdf === 'undefined') {
        showToast('danger', 'PDF library did not load. Please refresh and try again.');
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.style.position = 'fixed';
    wrapper.style.left = '-10000px';
    wrapper.style.top = '0';
    wrapper.style.width = '210mm';
    wrapper.innerHTML = buildBillPreviewMarkup(bill);
    wrapper.firstElementChild.classList.add('pdf-export');
    document.body.appendChild(wrapper);

    const fileName = String(bill.bill_number || 'bill').replace(/[^a-z0-9_-]+/gi, '_') + '.pdf';

    html2pdf()
        .set({
            margin: [8, 8, 8, 8],
            filename: fileName,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.bill-item', '.bill-header'] },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        })
        .from(wrapper.firstElementChild)
        .save()
        .then(function() {
            showToast('success', 'PDF downloaded successfully.');
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

function viewBill(billId) {
    const formData = new FormData();
    formData.set('action', 'view_bill');
    formData.set('bill_id', billId);

    fetch('index.php?page=bills', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseBillAjaxResponse)
    .then(function(data) {
        if (data.success) {
            displayBillPreview(data.bill);
            showToast('success', data.message || 'Bill opened successfully.');
        } else {
            showToast('danger', data.message || 'Unable to load bill.');
        }
    })
    .catch(function(error) {
        showToast('danger', 'Error: ' + error.message);
        console.error('View bill error:', error);
    });
}

function deleteBill(billId) {
    if (!billId || !confirm('Delete this bill permanently?')) {
        return;
    }

    const formData = new FormData();
    formData.set('action', 'delete_bill');
    formData.set('bill_id', billId);

    fetch('index.php?page=bills', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseBillAjaxResponse)
    .then(function(data) {
        if (data.success) {
            showToast('success', data.message || 'Bill deleted successfully.');
            const row = document.getElementById('billRow_' + billId);
            if (row) {
                row.remove();
            }

            if (window.currentBillData && Number(window.currentBillData.bill_id) === Number(billId)) {
                clearCurrentBillPreview();
            }
        } else {
            showToast('danger', data.message || 'Unable to delete bill.');
        }
    })
    .catch(function(error) {
        showToast('danger', 'Error: ' + error.message);
        console.error('Delete bill error:', error);
    });
}

function clearCurrentBillPreview() {
    window.currentBillData = null;
    const container = document.getElementById('billPreviewContainer');
    if (container) {
        container.innerHTML = '<div class="text-center text-muted py-4">Generate or open a bill to see the preview here.</div>';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateBillTargetOptions();

    const scopeSelect = document.getElementById('billScope');
    if (scopeSelect) {
        scopeSelect.addEventListener('change', function() {
            updateBillTargetOptions();
            const target = document.getElementById('billTarget');
            if (target) {
                target.value = '0';
            }
        });
    }

    const historyBody = document.querySelector('#billHistoryTable tbody');
    if (historyBody) {
        historyBody.querySelectorAll('tr[id^="billRow_"]').forEach(function(row) {
            const billId = row.id.replace('billRow_', '');
            const viewButton = row.querySelector('.btn.btn-outline-primary');
            const downloadButton = row.querySelectorAll('.btn.btn-outline-primary')[1];
            const deleteButton = row.querySelector('.btn.btn-outline-danger');

            if (viewButton) {
                viewButton.onclick = function() {
                    viewBill(billId);
                };
            }

            if (downloadButton) {
                downloadButton.onclick = function() {
                    downloadBillPdf(billId);
                };
            }

            if (deleteButton) {
                deleteButton.onclick = function() {
                    deleteBill(billId);
                };
            }
        });
    }
});
</script>
