<?php
if (!function_exists('nolelo_worker_history_escape')) {
    function nolelo_worker_history_escape($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$access = getWorkerAccessProfile($_SESSION['user_id']);
$categoryOptions = $access['has_category_assignment'] ? $access['assigned_categories'] : $access['categories'];
$siteOptions = $access['has_site_assignment'] ? $access['assigned_sites'] : $access['sites'];

$scope = $_GET['scope'] ?? 'all';
$scope = in_array($scope, ['all', 'category', 'site'], true) ? $scope : 'all';
$targetId = (int)($_GET['target_id'] ?? 0);
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$readingType = $_GET['reading_type'] ?? 'none';
$readingType = in_array($readingType, ['none', 'morning', 'evening', 'both'], true) ? $readingType : 'none';

$whereParts = ['we.user_id = ?'];
$params = [$_SESSION['user_id']];

if ($scope === 'category') {
    if ($targetId > 0) {
        $whereParts[] = 'we.category_id = ?';
        $params[] = $targetId;
    } elseif (!empty($categoryOptions)) {
        $categoryIds = array_map('intval', array_column($categoryOptions, 'id'));
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $whereParts[] = "we.category_id IN ($placeholders)";
        $params = array_merge($params, $categoryIds);
    }
} elseif ($scope === 'site') {
    if ($targetId > 0) {
        $whereParts[] = 'we.site_id = ?';
        $params[] = $targetId;
    } elseif (!empty($siteOptions)) {
        $siteIds = array_map('intval', array_column($siteOptions, 'id'));
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        $whereParts[] = "we.site_id IN ($placeholders)";
        $params = array_merge($params, $siteIds);
    }
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

$history = dbFetchAll("
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
    LIMIT 100
", $params);

$summary = [
    'entries' => count($history),
    'units' => array_sum(array_map(static function ($entry) {
        return (float)($entry['units_consumed'] ?? 0);
    }, $history)),
    'cost' => array_sum(array_map(static function ($entry) {
        return (float)($entry['cost'] ?? 0);
    }, $history)),
];

$categoryOptionsHtml = '<option value="0">None</option>';
foreach ($categoryOptions as $category) {
    $categoryOptionsHtml .= '<option value="' . (int)$category['id'] . '">' . nolelo_worker_history_escape($category['name']) . '</option>';
}

$siteOptionsHtml = '<option value="0">None</option>';
foreach ($siteOptions as $site) {
    $siteOptionsHtml .= '<option value="' . (int)$site['id'] . '">' . nolelo_worker_history_escape($site['name']) . '</option>';
}
?>

<style>
.history-panel {
    background: #fff;
    border-radius: 20px;
    padding: 1.25rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    border: 1px solid rgba(27,90,122,0.08);
    margin-bottom: 1.25rem;
}
.history-summary {
    background: linear-gradient(135deg, #f8fafc, #e8f0f8);
    border-radius: 18px;
    padding: 1rem;
    border: 1px solid rgba(27,90,122,0.08);
}
.history-summary .item {
    background: #fff;
    border-radius: 14px;
    padding: 0.85rem 1rem;
    border: 1px solid rgba(0,0,0,0.04);
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
}
.history-summary .value {
    font-size: 1.25rem;
    font-weight: 800;
    color: #0b2b3f;
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
@media (max-width: 767.98px) {
    .history-panel { padding: 1rem; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-clock-history me-2" style="color:#1b5a7a;"></i>Entry History</h4>
        <div class="text-muted-small">Your own readings, filtered by scope, date, and reading type.</div>
    </div>
</div>

<div class="history-panel">
    <form method="GET" class="filter-grid">
        <input type="hidden" name="page" value="history">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Organization</label>
                <select class="form-select form-select-sm" name="scope" id="historyScope">
                    <option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>All organization</option>
                    <?php if ($access['is_full_access'] || $access['has_category_assignment']): ?>
                    <option value="category" <?php echo $scope === 'category' ? 'selected' : ''; ?>>Category</option>
                    <?php endif; ?>
                    <?php if ($access['is_full_access'] || $access['has_site_assignment']): ?>
                    <option value="site" <?php echo $scope === 'site' ? 'selected' : ''; ?>>Site</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3" id="historyTargetWrap">
                <label class="form-label">Target</label>
                <select class="form-select form-select-sm" name="target_id" id="historyTarget">
                    <option value="0">None</option>
                    <?php if ($scope === 'category'): ?>
                        <?php foreach ($categoryOptions as $category): ?>
                        <option value="<?php echo (int)$category['id']; ?>" <?php echo $targetId === (int)$category['id'] ? 'selected' : ''; ?>>
                            <?php echo nolelo_worker_history_escape($category['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    <?php elseif ($scope === 'site'): ?>
                        <?php foreach ($siteOptions as $site): ?>
                        <option value="<?php echo (int)$site['id']; ?>" <?php echo $targetId === (int)$site['id'] ? 'selected' : ''; ?>>
                            <?php echo nolelo_worker_history_escape($site['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo nolelo_worker_history_escape($startDate); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo nolelo_worker_history_escape($endDate); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Reading Type</label>
                <select class="form-select form-select-sm" name="reading_type">
                    <option value="none" <?php echo $readingType === 'none' ? 'selected' : ''; ?>>None</option>
                    <option value="morning" <?php echo $readingType === 'morning' ? 'selected' : ''; ?>>Morning</option>
                    <option value="evening" <?php echo $readingType === 'evening' ? 'selected' : ''; ?>>Evening</option>
                    <option value="both" <?php echo $readingType === 'both' ? 'selected' : ''; ?>>Both</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel"></i> Apply Filter
                </button>
                <a href="index.php?page=history" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<div class="history-summary mb-4">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="item">
                <div class="text-muted-small">Results</div>
                <div class="value"><?php echo (int)$summary['entries']; ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="item">
                <div class="text-muted-small">Units</div>
                <div class="value"><?php echo number_format((float)$summary['units'], 1); ?> m3</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="item">
                <div class="text-muted-small">Cost</div>
                <div class="value"><?php echo number_format((float)$summary['cost']); ?> TSh</div>
            </div>
        </div>
    </div>
</div>

<div class="history-panel">
    <div class="table-responsive">
        <table class="table table-modern align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Scope</th>
                    <th>Category</th>
                    <th>Site</th>
                    <th>Reading Type</th>
                    <th>Reading</th>
                    <th>Units</th>
                    <th>Cost</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr>
                    <td colspan="9" class="text-center py-4 text-muted">No readings match the selected filters.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($history as $entry): ?>
                <?php
                    $entryJson = htmlspecialchars(json_encode($entry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                    $scopeClass = $entry['scope_type'] === 'site' ? 'site' : 'category';
                    $displayReadingType = in_array($readingType, ['morning', 'evening'], true)
                        ? $readingType
                        : $entry['reading_type'];
                    $readingDisplay = $displayReadingType === 'evening'
                        ? number_format((float)($entry['evening_reading'] ?? 0), 1)
                        : ($displayReadingType === 'both'
                            ? 'M: ' . number_format((float)($entry['morning_reading'] ?? 0), 1) . ' / E: ' . number_format((float)($entry['evening_reading'] ?? 0), 1)
                            : number_format((float)($entry['morning_reading'] ?? 0), 1));
                ?>
                <tr>
                    <td><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></td>
                    <td><span class="scope-badge <?php echo $scopeClass; ?>"><i class="bi bi-<?php echo $scopeClass === 'site' ? 'geo-alt' : 'tags'; ?>"></i> <?php echo ucfirst($scopeClass); ?></span></td>
                    <td><?php echo nolelo_worker_history_escape($entry['category_name'] ?? '-'); ?></td>
                    <td><?php echo nolelo_worker_history_escape($entry['site_name'] ?? '-'); ?></td>
                    <td><span class="entry-badge <?php echo nolelo_worker_history_escape($displayReadingType); ?>"><?php echo ucfirst(nolelo_worker_history_escape($displayReadingType)); ?></span></td>
                    <td><?php echo nolelo_worker_history_escape($readingDisplay); ?> m3</td>
                    <td><?php echo number_format((float)($entry['units_consumed'] ?? 0), 1); ?></td>
                    <td><?php echo number_format((float)($entry['cost'] ?? 0)); ?> TSh</td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-info me-1" data-entry="<?php echo $entryJson; ?>" onclick="viewHistoryEntry(this.dataset.entry)" title="View details"><i class="bi bi-eye"></i></button>
                        <a class="btn btn-sm btn-outline-primary me-1" href="index.php?page=entries&edit_entry=<?php echo (int)$entry['id']; ?>" title="Edit entry"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteHistoryEntry(<?php echo (int)$entry['id']; ?>)" title="Delete entry"><i class="bi bi-trash3"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="historyEntryDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>History Entry Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><div class="row g-3" id="historyEntryDetailsContent"></div></div>
        </div>
    </div>
</div>

<script>
(function() {
    window.viewHistoryEntry = function(encodedEntry) {
        try {
            const entry = JSON.parse(encodedEntry);
            const content = document.getElementById('historyEntryDetailsContent');
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
                ['Cost', Number(entry.cost || 0).toFixed(0) + ' TSh'],
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
            bootstrap.Modal.getOrCreateInstance(document.getElementById('historyEntryDetailsModal')).show();
        } catch (error) {
            if (typeof showNoleloToast === 'function') showNoleloToast('danger', 'Unable to open history details.');
            console.error(error);
        }
    };

    window.deleteHistoryEntry = function(entryId) {
        if (!entryId || !confirm('Delete this history entry? This action cannot be undone.')) return;
        const formData = new FormData();
        formData.set('action', 'delete_entry');
        formData.set('entry_id', entryId);
        fetch('index.php?page=history', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            body: formData
        }).then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok || !data.success) throw new Error(data.message || 'Failed to delete entry.');
                return data;
            });
        }).then(function(data) {
            if (typeof showNoleloToast === 'function') showNoleloToast('success', data.message || 'Entry deleted successfully.');
            setTimeout(function() { window.location.reload(); }, 500);
        }).catch(function(error) {
            if (typeof showNoleloToast === 'function') showNoleloToast('danger', error.message);
        });
    };
})();

(function() {
    const scopeSelect = document.getElementById('historyScope');
    const targetWrap = document.getElementById('historyTargetWrap');
    const targetSelect = document.getElementById('historyTarget');
    const categoryOptionsHtml = <?php echo json_encode($categoryOptionsHtml); ?>;
    const siteOptionsHtml = <?php echo json_encode($siteOptionsHtml); ?>;

    function syncTargetVisibility() {
        const scope = scopeSelect ? scopeSelect.value : 'all';
        if (!targetWrap || !targetSelect) {
            return;
        }

        if (scope === 'all') {
            targetWrap.style.display = 'none';
            targetSelect.value = '0';
        } else {
            targetWrap.style.display = '';
            targetSelect.innerHTML = scope === 'category' ? categoryOptionsHtml : siteOptionsHtml;
        }
    }

    if (scopeSelect) {
        scopeSelect.addEventListener('change', function() {
            syncTargetVisibility();
            targetSelect.value = '0';
        });
    }

    syncTargetVisibility();
})();
</script>
