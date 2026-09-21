<?php
$defaultReadingType = nolelo_setting('default_reading_type', 'morning');
$defaultWorkerReadingType = in_array($defaultReadingType, ['morning', 'evening'], true) ? $defaultReadingType : 'morning';
if (!function_exists('nolelo_worker_escape')) {
    function nolelo_worker_escape($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nolelo_worker_redirect')) {
    function nolelo_worker_redirect($page) {
        echo '<script>window.location.href="index.php?page=' . rawurlencode($page) . '";</script>';
        exit;
    }
}

if (!function_exists('nolelo_worker_reading_display')) {
    function nolelo_worker_reading_display(array $entry) {
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

$worker = getWorkerData();
if (!$worker) {
    echo '<div class="alert alert-danger flash-message">Worker profile not found.</div>';
    return;
}

$access = getWorkerAccessProfile($_SESSION['user_id']);
$showCategoryForm = $access['is_full_access'] || $access['has_category_assignment'];
$showSiteForm = $access['is_full_access'] || $access['has_site_assignment'];
$categoryOptions = $access['has_category_assignment'] ? $access['assigned_categories'] : $access['categories'];
$siteOptions = $access['has_site_assignment'] ? $access['assigned_sites'] : $access['sites'];
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!function_exists('nolelo_worker_json_response')) {
    function nolelo_worker_json_response(array $payload, $statusCode = 200) {
        if (function_exists('sendJsonResponse')) {
            sendJsonResponse($payload, $statusCode);
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (nolelo_setting('maintenance_mode', 'false') === 'true' && in_array($action, ['save_entry', 'delete_entry'], true)) {
        nolelo_worker_json_response(['success' => false, 'message' => 'The system is temporarily in maintenance mode. Please try again later.'], 423);
    }

    if ($action === 'save_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $entryMode = $_POST['entry_mode'] ?? '';
        $entryDate = trim($_POST['entry_date'] ?? '');
        $readingType = $_POST['reading_type'] ?? 'morning';
        $originalReadingType = $_POST['original_reading_type'] ?? '';
        $readingValue = (float)($_POST['reading'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $validReadingTypes = ['morning', 'evening', 'both'];

        if (!in_array($readingType, $validReadingTypes, true)) {
            $readingType = 'morning';
        }

        if ($entryDate === '') {
            $response = ['success' => false, 'message' => 'Please select a date.'];
        } elseif ($readingValue <= 0) {
            $response = ['success' => false, 'message' => 'Please enter a reading value greater than zero.'];
        } elseif (!in_array($entryMode, ['category', 'site'], true)) {
            $response = ['success' => false, 'message' => 'Please choose a valid form.'];
        } else {
            $categoryId = null;
            $siteId = null;
            $targetName = '';
            $targetScope = $entryMode;
            $allowedCategoryIds = array_map('intval', array_column($categoryOptions, 'id'));
            $allowedSiteIds = array_map('intval', array_column($siteOptions, 'id'));
            $isUpdate = $entryId > 0;

            if ($entryMode === 'category') {
                $categoryId = (int)($_POST['category_id'] ?? 0);

                if ($categoryId <= 0) {
                    $response = ['success' => false, 'message' => 'Please select a category.'];
                } elseif (!in_array($categoryId, $allowedCategoryIds, true)) {
                    $response = ['success' => false, 'message' => 'You are not allowed to use that category.'];
                } else {
                    $categoryRow = dbFetchOne("SELECT id, name FROM categories WHERE id = ? AND status = 'active'", [$categoryId]);
                    if (!$categoryRow) {
                        $response = ['success' => false, 'message' => 'Selected category was not found.'];
                    } else {
                        $targetName = $categoryRow['name'];
                    }
                }
            } else {
                $siteId = (int)($_POST['site_id'] ?? 0);

                if ($siteId <= 0) {
                    $response = ['success' => false, 'message' => 'Please select a site.'];
                } elseif (!in_array($siteId, $allowedSiteIds, true)) {
                    $response = ['success' => false, 'message' => 'You are not allowed to use that site.'];
                } else {
                    $siteRow = dbFetchOne("SELECT s.id, s.name, s.category_id, c.name as category_name FROM sites s JOIN categories c ON s.category_id = c.id WHERE s.id = ? AND s.status = 'active'", [$siteId]);
                    if (!$siteRow) {
                        $response = ['success' => false, 'message' => 'Selected site was not found.'];
                    } else {
                        $targetName = $siteRow['name'];
                        $categoryId = (int)$siteRow['category_id'];
                    }
                }
            }

            if (!isset($response)) {
                $handledUpdate = false;
                $opposite = null;
                $responseReadingType = $readingType;
                if ($isUpdate) {
                    $existingEntry = dbFetchOne(
                        "SELECT id, user_id, category_id, site_id, entry_date, morning_reading, evening_reading, units_consumed, cost, reading_type, notes FROM water_entries WHERE id = ? AND user_id = ? LIMIT 1",
                        [$entryId, $_SESSION['user_id']]
                    );

                    if (!$existingEntry) {
                        $response = ['success' => false, 'message' => 'You can only edit your own reading.'];
                    } else {
                        // Edit the selected period. If a single-period record is
                        // deliberately changed from Morning to Evening (or vice
                        // versa), convert the record instead of creating a second row.
                        $editDuplicateSql = $entryMode === 'category'
                            ? "SELECT id FROM water_entries WHERE entry_date = ? AND category_id = ? AND site_id IS NULL"
                            : "SELECT id FROM water_entries WHERE entry_date = ? AND site_id = ?";
                        $editDuplicateParams = $entryMode === 'category'
                            ? [$entryDate, $categoryId]
                            : [$entryDate, $siteId];
                        if ($readingType !== 'both') {
                            $editDuplicateSql .= " AND reading_type = ?";
                            $editDuplicateParams[] = $readingType;
                        } else {
                            $editDuplicateSql .= " AND reading_type IN ('morning', 'evening', 'both')";
                        }
                        $editDuplicateSql .= " AND id != ? LIMIT 1";
                        $editDuplicateParams[] = $entryId;
                        if (dbFetchOne($editDuplicateSql, $editDuplicateParams)) {
                            $response = ['success' => false, 'message' => 'That date, target, and reading period already has another entry.'];
                        }

                        if (isset($response)) {
                            $handledUpdate = true;
                        } else {
                        $morningReading = (float)($existingEntry['morning_reading'] ?? 0);
                        $eveningReading = (float)($existingEntry['evening_reading'] ?? 0);
                        $isSinglePeriodConversion = in_array($originalReadingType, ['morning', 'evening'], true)
                            && in_array($readingType, ['morning', 'evening'], true)
                            && $originalReadingType !== $readingType
                            && ($existingEntry['reading_type'] ?? '') === $originalReadingType;

                        if ($isSinglePeriodConversion) {
                            $morningReading = $readingType === 'morning' ? $readingValue : 0;
                            $eveningReading = $readingType === 'evening' ? $readingValue : 0;
                        } elseif ($readingType === 'morning') {
                            $morningReading = $readingValue;
                        } elseif ($readingType === 'evening') {
                            $eveningReading = $readingValue;
                        }

                        $hasMorning = $morningReading > 0;
                        $hasEvening = $eveningReading > 0;
                        $unitsConsumed = ($hasMorning && $hasEvening)
                            ? max(0, $eveningReading - $morningReading)
                            : ($hasMorning ? $morningReading : $eveningReading);
                        $cost = $unitsConsumed * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
                        $responseReadingType = ($hasMorning && $hasEvening) ? 'both' : ($hasEvening ? 'evening' : 'morning');

                        $result = dbUpdate('water_entries', [
                            'entry_date' => $entryDate,
                            'category_id' => $categoryId,
                            'site_id' => $siteId,
                            'morning_reading' => $morningReading,
                            'evening_reading' => $eveningReading,
                            'units_consumed' => $unitsConsumed,
                            'cost' => $cost,
                            'reading_type' => $responseReadingType,
                            'notes' => $notes,
                        ], 'id = :id AND user_id = :user_id', [
                            'id' => $entryId,
                            'user_id' => $_SESSION['user_id'],
                        ]);
                        $handledUpdate = true;

                        if ($result !== false) {
                            $response = [
                                'success' => true,
                                'message' => 'Reading updated successfully.',
                                'entry' => [
                                    'id' => $entryId,
                                    'mode' => $targetScope,
                                    'target_name' => $targetName,
                                    'category_id' => $categoryId,
                                    'site_id' => $siteId,
                                    'entry_date' => $entryDate,
                                    'reading_type' => $responseReadingType,
                                    'reading_value' => $readingValue,
                                    'morning_reading' => $morningReading,
                                    'evening_reading' => $eveningReading,
                                    'units_consumed' => $unitsConsumed,
                                    'cost' => $cost,
                                ],
                            ];
                        } else {
                            $response = ['success' => false, 'message' => 'Failed to update the reading.'];
                        }
                        }
                    }
                }

                // Readings are shared by target/date/period, not owned by a permanent worker.
                // A different worker may complete the other period later in the same day.
                $duplicateSql = $entryMode === 'category'
                    ? "SELECT id, user_id, morning_reading, evening_reading, reading_type FROM water_entries WHERE entry_date = ? AND category_id = ? AND site_id IS NULL"
                    : "SELECT id, user_id, morning_reading, evening_reading, reading_type FROM water_entries WHERE entry_date = ? AND site_id = ?";
                $duplicateParams = $entryMode === 'category'
                    ? [$entryDate, $categoryId]
                    : [$entryDate, $siteId];
                if ($readingType !== 'both') {
                    $duplicateSql .= " AND reading_type = ?";
                    $duplicateParams[] = $readingType;
                } else {
                    $duplicateSql .= " AND reading_type IN ('morning', 'evening', 'both')";
                }

                if ($isUpdate) {
                    $duplicateSql .= " AND id != ?";
                    $duplicateParams[] = $entryId;
                }

                $duplicate = $handledUpdate ? null : dbFetchOne($duplicateSql . " LIMIT 1", $duplicateParams);
                if ($duplicate) {
                    $response = ['success' => false, 'message' => 'That period has already been recorded for this date and target.'];
                } elseif (!$handledUpdate && !isset($response)) {
                    $morningReading = $readingType === 'evening' ? 0 : $readingValue;
                    $eveningReading = $readingType === 'morning' ? 0 : $readingValue;
                    $unitsConsumed = $readingValue;

                    // If the other period was entered by another worker, combine both
                    // readings into the existing shared daily record.
                    $oppositeType = $readingType === 'morning' ? 'evening' : ($readingType === 'evening' ? 'morning' : null);
                    $oppositeSql = null;
                    $oppositeParams = [];
                    if ($oppositeType !== null) {
                        $oppositeSql = $entryMode === 'category'
                            ? "SELECT id, user_id, morning_reading, evening_reading FROM water_entries WHERE entry_date = ? AND reading_type = ? AND category_id = ? AND site_id IS NULL LIMIT 1"
                            : "SELECT id, user_id, morning_reading, evening_reading FROM water_entries WHERE entry_date = ? AND reading_type = ? AND site_id = ? LIMIT 1";
                        $oppositeParams = $entryMode === 'category'
                            ? [$entryDate, $oppositeType, $categoryId]
                            : [$entryDate, $oppositeType, $siteId];
                    }
                    $opposite = $oppositeSql ? dbFetchOne($oppositeSql, $oppositeParams) : null;
                    if ($opposite) {
                        $morningReading = $readingType === 'morning' ? $readingValue : (float)$opposite['morning_reading'];
                        $eveningReading = $readingType === 'evening' ? $readingValue : (float)$opposite['evening_reading'];
                        $unitsConsumed = max(0, $eveningReading - $morningReading);
                        $cost = $unitsConsumed * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
                        $result = dbUpdate('water_entries', [
                            'morning_reading' => $morningReading,
                            'evening_reading' => $eveningReading,
                            'units_consumed' => $unitsConsumed,
                            'cost' => $cost,
                            'reading_type' => 'both',
                            'notes' => $notes !== '' ? $notes : null,
                        ], 'id = :id', ['id' => $opposite['id']]);
                        $entryId = (int)$opposite['id'];
                        $responseReadingType = 'both';
                    } else {
                    $cost = $unitsConsumed * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
                    $payload = [
                        'user_id' => $_SESSION['user_id'],
                        'category_id' => $categoryId,
                        'site_id' => $siteId,
                        'entry_date' => $entryDate,
                        'morning_reading' => $morningReading,
                        'evening_reading' => $eveningReading,
                        'units_consumed' => $unitsConsumed,
                        'cost' => $cost,
                        'reading_type' => $readingType,
                        'notes' => $notes,
                        'status' => 'pending',
                    ];

                    if ($isUpdate) {
                        $result = dbUpdate(
                            'water_entries',
                            $payload,
                            'id = :id AND user_id = :user_id',
                            [
                                'id' => $entryId,
                                'user_id' => $_SESSION['user_id'],
                            ]
                        );
                    } else {
                        $result = dbInsert('water_entries', $payload);
                        $entryId = (int)$result;
                    }
                    }

                    if (!isset($response) && $result !== false && $result !== 0) {
                        $response = [
                            'success' => true,
                            'message' => $isUpdate ? 'Reading updated successfully.' : 'Reading saved successfully.',
                            'entry' => [
                                'id' => $entryId,
                                'mode' => $targetScope,
                                'target_name' => $targetName,
                                'category_id' => $categoryId,
                                'site_id' => $siteId,
                                'entry_date' => $entryDate,
                                'reading_type' => $responseReadingType,
                                'reading_value' => $readingValue,
                                'units_consumed' => $unitsConsumed,
                                'cost' => $cost,
                            ],
                        ];
                    } elseif (!isset($response)) {
                        $response = ['success' => false, 'message' => 'Failed to save the reading.'];
                    }
                }
            }
        }

        if (!empty($response['success'])) {
            if ($isUpdate) {
                logWorkerActivity($_SESSION['user_id'], 'entry_updated', 'Worker updated a water entry.', [
                        'entry_id' => $entryId,
                        'before' => [
                            'date' => $existingEntry['entry_date'] ?? '',
                            'category_id' => $existingEntry['category_id'] ?? null,
                            'site_id' => $existingEntry['site_id'] ?? null,
                            'morning_reading' => (float)($existingEntry['morning_reading'] ?? 0),
                            'evening_reading' => (float)($existingEntry['evening_reading'] ?? 0),
                            'units_consumed' => (float)($existingEntry['units_consumed'] ?? 0),
                            'cost' => (float)($existingEntry['cost'] ?? 0),
                            'reading_type' => $existingEntry['reading_type'] ?? '',
                            'notes' => $existingEntry['notes'] ?? '',
                        ],
                        'after' => [
                            'date' => $entryDate,
                            'category_id' => $categoryId,
                            'site_id' => $siteId,
                            'morning_reading' => (float)($morningReading ?? 0),
                            'evening_reading' => (float)($eveningReading ?? 0),
                            'units_consumed' => (float)($unitsConsumed ?? 0),
                            'cost' => (float)($cost ?? 0),
                            'reading_type' => $responseReadingType,
                            'notes' => $notes,
                        ],
                ]);
            } else {
                logWorkerActivity($_SESSION['user_id'], 'entry_created', 'Worker created a water entry.', ['entry_id' => $entryId, 'after' => ['date' => $entryDate, 'reading_type' => $responseReadingType, 'morning_reading' => (float)($morningReading ?? 0), 'evening_reading' => (float)($eveningReading ?? 0), 'units_consumed' => (float)($unitsConsumed ?? 0), 'notes' => $notes]]);
            }
        }

        if ($isAjaxRequest) {
            nolelo_worker_json_response($response, empty($response['success']) ? 422 : 200);
        }

        $_SESSION['toast'] = $response;
        nolelo_worker_redirect('entries');
    }

    if ($action === 'delete_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);

        if ($entryId <= 0) {
            $response = ['success' => false, 'message' => 'Invalid entry selected.'];
        } else {
            $existing = dbFetchOne("SELECT id FROM water_entries WHERE id = ? AND user_id = ?", [$entryId, $_SESSION['user_id']]);
            if (!$existing) {
                $response = ['success' => false, 'message' => 'Entry not found.'];
            } else {
                $deleted = dbDelete('water_entries', 'id = :id AND user_id = :user_id', [
                    'id' => $entryId,
                    'user_id' => $_SESSION['user_id'],
                ]);

                $response = $deleted ? ['success' => true, 'message' => 'Reading deleted successfully.'] : ['success' => false, 'message' => 'Failed to delete the reading.'];
            }
        }

        if ($isAjaxRequest) {
            if (!empty($response['success'])) {
                logWorkerActivity($_SESSION['user_id'], 'entry_deleted', 'Worker deleted a water entry.', ['entry_id' => $entryId]);
            }
            nolelo_worker_json_response($response, empty($response['success']) ? 422 : 200);
        }

        $_SESSION['toast'] = $response;
        nolelo_worker_redirect('entries');
    }
}

$summary = dbFetchOne("
    SELECT
        COUNT(*) as total_entries,
        COALESCE(SUM(units_consumed), 0) as total_units,
        COALESCE(SUM(units_consumed), 0) as billable_units,
        COALESCE(SUM(CASE WHEN entry_date = CURDATE() THEN 1 ELSE 0 END), 0) as today_entries
    FROM water_entries
    WHERE user_id = ?
", [$_SESSION['user_id']]) ?: [
    'total_entries' => 0,
    'total_units' => 0,
    'billable_units' => 0,
    'today_entries' => 0,
];
$summary['total_cost'] = (float)($summary['billable_units'] ?? 0) * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);

$recent_entries = dbFetchAll("
    SELECT
        we.*,
        CASE WHEN we.site_id IS NULL THEN 'category' ELSE 'site' END as scope_type,
        s.name as site_name,
        COALESCE(c_entry.name, c_site.name) as category_name
    FROM water_entries we
    LEFT JOIN sites s ON we.site_id = s.id
    LEFT JOIN categories c_entry ON we.category_id = c_entry.id
    LEFT JOIN categories c_site ON s.category_id = c_site.id
    WHERE we.user_id = ?
    ORDER BY we.entry_date DESC, we.created_at DESC
    LIMIT 10
", [$_SESSION['user_id']]);

$historyEditEntry = null;
if (!empty($_GET['edit_entry'])) {
    $historyEditEntry = dbFetchOne("SELECT we.*, CASE WHEN we.site_id IS NULL THEN 'category' ELSE 'site' END as scope_type, s.name as site_name, COALESCE(c_entry.name, c_site.name) as category_name FROM water_entries we LEFT JOIN sites s ON we.site_id = s.id LEFT JOIN categories c_entry ON we.category_id = c_entry.id LEFT JOIN categories c_site ON s.category_id = c_site.id WHERE we.id = ? AND we.user_id = ? LIMIT 1", [(int)$_GET['edit_entry'], $_SESSION['user_id']]);
}

$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
?>

<style>
.entry-hero {
    background: linear-gradient(135deg, #0b2b3f 0%, #1a5a7a 100%);
    border-radius: 22px;
    padding: 1.75rem 2rem;
    color: #fff;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.entry-hero::after {
    content: '';
    position: absolute;
    right: -70px;
    top: -60px;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,195,247,0.18), transparent 70%);
}
.entry-stat {
    background: #fff;
    border-radius: 18px;
    padding: 1rem 1.1rem;
    box-shadow: 0 4px 18px rgba(0,0,0,0.05);
    border: 1px solid rgba(27,90,122,0.08);
    height: 100%;
}
.entry-stat .label {
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #7e99ae;
    font-weight: 700;
}
.entry-stat .value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #0b2b3f;
}
.entry-form-card {
    background: #fff;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    border: 1px solid rgba(27,90,122,0.08);
    height: 100%;
}
.entry-form-card .card-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: #1b5a7a;
}
.entry-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.15);
    color: #fff;
    font-size: 0.8rem;
    margin: 0.15rem 0.25rem 0 0;
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
.scope-badge.category {
    background: #d2e7f5;
    color: #0b2b3f;
}
.scope-badge.site {
    background: #e8f5e9;
    color: #1b5e20;
}
.entry-badge {
    padding: 0.2rem 0.8rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
}
.entry-badge.morning { background: #d2e7f5; color: #0b2b3f; }
.entry-badge.evening { background: #ffe082; color: #7a5a00; }
.entry-table-wrap {
    background: #fff;
    border-radius: 20px;
    padding: 1.25rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    border: 1px solid rgba(27,90,122,0.08);
}
.entry-table-wrap .table-modern {
    min-width: 860px;
}
.form-control, .form-select {
    border-radius: 12px;
    border: 2px solid #e9eff4;
    padding: 0.65rem 0.95rem;
}
.form-control:focus, .form-select:focus {
    border-color: #1b5a7a;
    box-shadow: 0 0 0 4px rgba(27,90,122,0.1);
}
.text-muted-small {
    font-size: 0.82rem;
    color: #7e99ae;
}
@media (max-width: 767.98px) {
    .entry-hero { padding: 1.25rem; }
    .entry-table-wrap { padding: 1rem; }
}
</style>

<?php if ($toast): ?>
<div class="alert alert-<?php echo !empty($toast['success']) ? 'success' : 'danger'; ?> alert-dismissible fade show flash-message">
    <?php echo nolelo_worker_escape($toast['message'] ?? ''); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="entry-hero">
    <div class="row align-items-center g-3">
        <div class="col-lg-8">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h4 class="mb-0 fw-bold"><i class="bi bi-clipboard-data me-2"></i>Enter Meter Reading</h4>
                <span class="badge rounded-pill" style="background:rgba(255,255,255,0.16);color:#fff;">Date is required, other fields stay flexible</span>
            </div>
            <div class="mt-2 opacity-75">Record morning and evening readings separately for the same date, based on the assignments your admin gave you.</div>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="entry-chip"><i class="bi bi-calendar3"></i> <?php echo date('d M Y'); ?></div>
            <div class="entry-chip"><i class="bi bi-activity"></i> <?php echo (int)($summary['today_entries'] ?? 0); ?> today</div>
            <div class="entry-chip"><i class="bi bi-droplet"></i> <?php echo number_format((float)($summary['total_units'] ?? 0), 1); ?> m3 total</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="entry-stat">
            <div class="label">Total Entries</div>
            <div class="value"><?php echo (int)($summary['total_entries'] ?? 0); ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="entry-stat">
            <div class="label">Total Units</div>
            <div class="value"><?php echo number_format((float)($summary['total_units'] ?? 0), 1); ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="entry-stat">
            <div class="label">Total Cost</div>
            <div class="value"><?php echo number_format((float)($summary['total_cost'] ?? 0)); ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="entry-stat">
            <div class="label">Today</div>
            <div class="value"><?php echo (int)($summary['today_entries'] ?? 0); ?></div>
        </div>
    </div>
</div>

<?php if (!empty($access['assigned_categories']) || !empty($access['assigned_sites'])): ?>
<div class="alert alert-info border-0 shadow-sm">
    <strong>Your access:</strong>
    <?php if (!empty($access['assigned_categories'])): ?>
        <?php foreach ($access['assigned_categories'] as $category): ?>
            <span class="badge bg-light text-dark me-1 mb-1"><?php echo nolelo_worker_escape($category['name']); ?></span>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($access['assigned_sites'])): ?>
        <?php foreach ($access['assigned_sites'] as $site): ?>
            <span class="badge bg-light text-dark me-1 mb-1"><?php echo nolelo_worker_escape($site['name']); ?></span>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php if ($showCategoryForm): ?>
    <div class="<?php echo $showSiteForm ? 'col-lg-6' : 'col-12'; ?>">
        <div class="entry-form-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="card-title"><i class="bi bi-tags me-2"></i>Category Reading</div>
                    <div class="text-muted-small">Use this form when your admin assigned you to categories.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetWorkerEntryForm('categoryEntryForm')">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
            </div>
            <form id="categoryEntryForm" onsubmit="return submitWorkerEntryForm('categoryEntryForm');">
                <input type="hidden" name="action" value="save_entry">
                <input type="hidden" name="entry_mode" value="category">
                <input type="hidden" name="entry_id" value="">
                <input type="hidden" name="original_reading_type" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" name="entry_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id">
                            <option value="">None</option>
                            <?php foreach ($categoryOptions as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>"><?php echo nolelo_worker_escape($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Reading Type</label>
                        <select class="form-select" name="reading_type">
                            <option value="morning" <?php echo $defaultWorkerReadingType === 'morning' ? 'selected' : ''; ?>>Morning</option>
                            <option value="evening" <?php echo $defaultWorkerReadingType === 'evening' ? 'selected' : ''; ?>>Evening</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Reading Value</label>
                        <input type="number" class="form-control" name="reading" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Optional notes"></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary" data-submit-btn="1"><i class="bi bi-save me-1"></i>Save Reading</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetWorkerEntryForm('categoryEntryForm')"><i class="bi bi-x me-1"></i>Clear</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showSiteForm): ?>
    <div class="<?php echo $showCategoryForm ? 'col-lg-6' : 'col-12'; ?>">
        <div class="entry-form-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="card-title"><i class="bi bi-geo-alt me-2"></i>Site Reading</div>
                    <div class="text-muted-small">Use this form when your admin assigned you to sites.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetWorkerEntryForm('siteEntryForm')">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
            </div>
            <form id="siteEntryForm" onsubmit="return submitWorkerEntryForm('siteEntryForm');">
                <input type="hidden" name="action" value="save_entry">
                <input type="hidden" name="entry_mode" value="site">
                <input type="hidden" name="entry_id" value="">
                <input type="hidden" name="original_reading_type" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" name="entry_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Site</label>
                        <select class="form-select" name="site_id">
                            <option value="">None</option>
                            <?php foreach ($siteOptions as $site): ?>
                            <option value="<?php echo (int)$site['id']; ?>"><?php echo nolelo_worker_escape($site['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Reading Type</label>
                        <select class="form-select" name="reading_type">
                            <option value="morning" <?php echo $defaultWorkerReadingType === 'morning' ? 'selected' : ''; ?>>Morning</option>
                            <option value="evening" <?php echo $defaultWorkerReadingType === 'evening' ? 'selected' : ''; ?>>Evening</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Reading Value</label>
                        <input type="number" class="form-control" name="reading" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Optional notes"></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary" data-submit-btn="1"><i class="bi bi-save me-1"></i>Save Reading</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetWorkerEntryForm('siteEntryForm')"><i class="bi bi-x me-1"></i>Clear</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="entry-table-wrap">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2" style="color:#1b5a7a;"></i>My Recent Entries</h5>
            <div class="text-muted-small">Only your own recordings are shown here.</div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-modern align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Scope</th>
                    <th>Category</th>
                    <th>Site</th>
                    <th>Type</th>
                    <th>Reading</th>
                    <th>Units</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_entries)): ?>
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">No entries yet. Use one of the forms above to add your first reading.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($recent_entries as $entry): ?>
                <?php
                    $entryJson = htmlspecialchars(json_encode($entry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                    $readingDisplay = nolelo_worker_reading_display($entry);
                    $scopeClass = $entry['scope_type'] === 'site' ? 'site' : 'category';
                    $scopeLabel = $entry['scope_type'] === 'site' ? 'Site' : 'Category';
                ?>
                <tr id="entryRow_<?php echo (int)$entry['id']; ?>">
                    <td><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></td>
                    <td><span class="scope-badge <?php echo $scopeClass; ?>"><i class="bi bi-<?php echo $scopeClass === 'site' ? 'geo-alt' : 'tags'; ?>"></i> <?php echo $scopeLabel; ?></span></td>
                    <td><?php echo nolelo_worker_escape($entry['category_name'] ?? '-'); ?></td>
                    <td><?php echo nolelo_worker_escape($entry['site_name'] ?? '-'); ?></td>
                    <td><span class="entry-badge <?php echo nolelo_worker_escape($entry['reading_type']); ?>"><?php echo ucfirst(nolelo_worker_escape($entry['reading_type'])); ?></span></td>
                    <td><?php echo nolelo_worker_escape($readingDisplay); ?> m3</td>
                    <td><?php echo number_format((float)($entry['units_consumed'] ?? 0), 1); ?></td>
                    <td><span class="badge bg-light text-dark"><?php echo ucfirst(nolelo_worker_escape($entry['status'] ?? 'pending')); ?></span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-info me-1" data-entry="<?php echo $entryJson; ?>" onclick="viewWorkerEntryDetails(this.dataset.entry)" title="View entry details">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary me-1" data-entry="<?php echo $entryJson; ?>" onclick="editWorkerEntry(this.dataset.entry)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteWorkerEntry(<?php echo (int)$entry['id']; ?>)">
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

<div class="modal fade" id="workerEntryDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>My Entry Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3" id="workerEntryDetailsContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
function viewWorkerEntryDetails(encodedEntry) {
    try {
        const entry = JSON.parse(encodedEntry);
        const content = document.getElementById('workerEntryDetailsContent');
        const value = (item, fallback = '-') => item === null || item === undefined || item === '' ? fallback : item;
        content.innerHTML = '';
        [
            ['Date', value(entry.entry_date)],
            ['Category', value(entry.category_name)],
            ['Site', value(entry.site_name)],
            ['Reading Type', value(entry.reading_type)],
            ['Morning Reading', Number(entry.morning_reading || 0).toFixed(1) + ' m3'],
            ['Evening Reading', Number(entry.evening_reading || 0).toFixed(1) + ' m3'],
            ['Units Consumed', Number(entry.units_consumed || 0).toFixed(1) + ' m3'],
            ['Cost', Number(entry.cost || 0).toFixed(0) + ' <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY), ENT_QUOTES, 'UTF-8'); ?>'],
            ['Status', value(entry.status)],
            ['Created', value(entry.created_at)],
            ['Updated', value(entry.updated_at)],
            ['Notes', value(entry.notes, 'No notes entered.')]
        ].forEach(function(row) {
            const wrapper = document.createElement('div');
            wrapper.className = 'col-md-6';
            const label = document.createElement('div');
            label.className = 'text-muted-small fw-semibold';
            label.textContent = row[0];
            const detail = document.createElement('div');
            detail.className = 'p-2 bg-light rounded mt-1';
            detail.textContent = row[1];
            wrapper.append(label, detail);
            content.appendChild(wrapper);
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('workerEntryDetailsModal')).show();
    } catch (error) {
        showToast('danger', 'Unable to open entry details.');
        console.error(error);
    }
}

function parseWorkerAjaxResponse(response) {
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

function setSubmitButtonLabel(form, label) {
    const button = form.querySelector('[data-submit-btn]');
    if (button) {
        button.innerHTML = '<i class="bi bi-save me-1"></i>' + label;
    }
}

function resetWorkerEntryForm(formId) {
    const form = document.getElementById(formId);
    if (!form) {
        return;
    }

    form.reset();
    const entryId = form.querySelector('input[name="entry_id"]');
    if (entryId) {
        entryId.value = '';
    }

    setSubmitButtonLabel(form, 'Save Reading');
}

function submitWorkerEntryForm(formId) {
    const form = document.getElementById(formId);
    if (!form) {
        return false;
    }

    const formData = new FormData(form);
    formData.set('action', 'save_entry');

    document.querySelectorAll('#' + formId + ' [data-submit-btn]').forEach(function(button) {
        button.disabled = true;
    });

    fetch('index.php?page=entries', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseWorkerAjaxResponse)
    .then(function(data) {
        document.querySelectorAll('#' + formId + ' [data-submit-btn]').forEach(function(button) {
            button.disabled = false;
        });

        if (data.success) {
            showToast('success', data.message || 'Reading saved successfully.');
            resetWorkerEntryForm(formId);
            setTimeout(function() {
                window.location.reload();
            }, 900);
        } else {
            showToast('danger', data.message || 'An error occurred.');
        }
    })
    .catch(function(error) {
        document.querySelectorAll('#' + formId + ' [data-submit-btn]').forEach(function(button) {
            button.disabled = false;
        });

        showToast('danger', 'Error: ' + error.message);
        console.error('Entry form error:', error);
    });

    return false;
}

function editWorkerEntry(encodedEntry) {
    try {
        const entry = JSON.parse(encodedEntry);
        const formId = entry.scope_type === 'site' ? 'siteEntryForm' : 'categoryEntryForm';
        const form = document.getElementById(formId);

        if (!form) {
            showToast('warning', 'You cannot edit this entry with your current access.');
            return;
        }

        form.querySelector('input[name="entry_id"]').value = entry.id || '';
        form.querySelector('input[name="original_reading_type"]').value = entry.reading_type || '';
        form.querySelector('input[name="entry_date"]').value = entry.entry_date || '<?php echo date('Y-m-d'); ?>';
        const readingTypeSelect = form.querySelector('select[name="reading_type"]');
        const readingInput = form.querySelector('input[name="reading"]');
        const morningValue = entry.morning_reading || 0;
        const eveningValue = entry.evening_reading || 0;
        form.dataset.morningReading = morningValue;
        form.dataset.eveningReading = eveningValue;
        readingTypeSelect.value = entry.reading_type === 'evening' ? 'evening' : 'morning';
        readingInput.value = readingTypeSelect.value === 'evening' ? eveningValue : morningValue;
        readingTypeSelect.onchange = function() {
            const storedValue = this.value === 'evening'
                ? Number(form.dataset.eveningReading || 0)
                : Number(form.dataset.morningReading || 0);
            const currentValue = Number(readingInput.value || 0);

            // When converting a single-period record, carry its reading into
            // the newly selected period. For a combined record, use the real
            // stored value for the selected period.
            readingInput.value = storedValue > 0
                ? storedValue
                : (currentValue > 0 ? currentValue : 0);
        };
        form.querySelector('textarea[name="notes"]').value = entry.notes || '';

        if (formId === 'categoryEntryForm' && form.querySelector('select[name="category_id"]')) {
            form.querySelector('select[name="category_id"]').value = entry.category_id || '';
        }

        if (formId === 'siteEntryForm' && form.querySelector('select[name="site_id"]')) {
            form.querySelector('select[name="site_id"]').value = entry.site_id || '';
        }

        setSubmitButtonLabel(form, 'Update Reading');
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
        showToast('danger', 'Unable to open that entry for editing.');
        console.error(error);
    }
}

function deleteWorkerEntry(entryId) {
    if (!entryId) {
        return;
    }

    if (!confirm('Delete this reading?')) {
        return;
    }

    const formData = new FormData();
    formData.set('action', 'delete_entry');
    formData.set('entry_id', entryId);

    fetch('index.php?page=entries', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseWorkerAjaxResponse)
    .then(function(data) {
        if (data.success) {
            showToast('success', data.message || 'Reading deleted successfully.');
            const row = document.getElementById('entryRow_' + entryId);
            if (row) {
                row.remove();
            }
        } else {
            showToast('danger', data.message || 'Failed to delete the reading.');
        }
    })
    .catch(function(error) {
        showToast('danger', 'Error: ' + error.message);
        console.error('Delete error:', error);
    });
}

const historyEditEntry = <?php echo json_encode($historyEditEntry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
if (historyEditEntry) {
    editWorkerEntry(JSON.stringify(historyEditEntry));
}
</script>
