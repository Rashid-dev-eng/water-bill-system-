<?php
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) { header('Location: index.php'); exit; }
if (empty($_SESSION['activity_csrf_token'])) {
    $_SESSION['activity_csrf_token'] = bin2hex(random_bytes(32));
}
$activityCsrfToken = $_SESSION['activity_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjaxRequest()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_activity') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($activityCsrfToken, $token)) {
            sendJsonResponse(['success' => false, 'message' => 'Security token expired. Please refresh the page.'], 419);
        }

        $activityId = (int)($_POST['activity_id'] ?? 0);
        if ($activityId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid activity selected.'], 422);
        }

        $deleted = dbDelete('worker_activity_logs', 'id = :id', ['id' => $activityId]);
        if ($deleted === false || $deleted < 1) {
            sendJsonResponse(['success' => false, 'message' => 'The activity could not be deleted.'], 500);
        }
        sendJsonResponse(['success' => true, 'message' => 'Activity log deleted successfully.', 'activity_id' => $activityId]);
    }
}
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$startDate = $_GET['start_date'] ?? $monday;
$endDate = $_GET['end_date'] ?? $sunday;
$workerId = (int)($_GET['worker_id'] ?? 0);
$eventType = trim($_GET['event_type'] ?? '');
$search = trim($_GET['search'] ?? '');
$where = ['l.created_at >= CONCAT(?, " 00:00:00")', 'l.created_at <= CONCAT(?, " 23:59:59")'];
$params = [$startDate, $endDate];
if ($workerId > 0) { $where[] = 'l.user_id = ?'; $params[] = $workerId; }
if ($eventType !== '') { $where[] = 'l.event_type = ?'; $params[] = $eventType; }
if ($search !== '') { $where[] = '(l.description LIKE ? OR u.full_name LIKE ? OR l.ip_address LIKE ?)'; $term = '%' . $search . '%'; array_push($params, $term, $term, $term); }
$workers = dbFetchAll("SELECT id, full_name FROM users WHERE role = 'worker' ORDER BY full_name");
$eventTypes = dbFetchAll('SELECT DISTINCT event_type FROM worker_activity_logs ORDER BY event_type');
$activities = dbFetchAll('SELECT l.*, u.full_name, u.email FROM worker_activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT 500', $params);
?>
<style>.activity-card{background:#fff;border-radius:16px;padding:1.25rem;box-shadow:0 4px 20px rgba(0,0,0,.05)}.activity-description{min-width:260px}.activity-meta{font-size:.78rem;color:#7e99ae}</style>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><div><h4><i class="bi bi-activity me-2" style="color:#1b5a7a"></i>Worker Activities</h4><p class="text-muted-small mb-0">Track worker logins, changes, entries, bills, and session events.</p></div><span class="badge-dawasco"><?php echo count($activities); ?> activities</span></div>
<div class="activity-card mb-4"><form method="GET" class="row g-3"><input type="hidden" name="page" value="activities"><div class="col-md-2"><label class="form-label">Start date</label><input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>"></div><div class="col-md-2"><label class="form-label">End date</label><input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"></div><div class="col-md-3"><label class="form-label">Worker</label><select class="form-select" name="worker_id"><option value="0">All workers</option><?php foreach ($workers as $worker): ?><option value="<?php echo (int)$worker['id']; ?>" <?php echo $workerId === (int)$worker['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($worker['full_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Event</label><select class="form-select" name="event_type"><option value="">All events</option><?php foreach ($eventTypes as $type): ?><option value="<?php echo htmlspecialchars($type['event_type']); ?>" <?php echo $eventType === $type['event_type'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type['event_type']))); ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Search</label><input type="search" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Description, worker, IP"></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply filters</button><a class="btn btn-outline-secondary" href="index.php?page=activities"><i class="bi bi-arrow-counterclockwise"></i> Current week</a></div></form></div>
<div class="activity-card"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Time</th><th>Worker</th><th>Event</th><th>Activity</th><th>IP Address</th><th>Details</th></tr></thead><tbody><?php if (!$activities): ?><tr><td colspan="6" class="text-center text-muted py-4">No worker activities found for the selected filters.</td></tr><?php else: foreach ($activities as $activity): $activityJson = htmlspecialchars(json_encode(['id' => (int)$activity['id'], 'worker' => $activity['full_name'] ?? 'Deleted worker', 'event' => $activity['event_type'], 'description' => $activity['description'], 'time' => $activity['created_at'], 'metadata' => json_decode($activity['metadata'] ?: '{}', true)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?><tr><td class="text-nowrap"><?php echo date('d M Y H:i:s', strtotime($activity['created_at'])); ?></td><td><?php echo htmlspecialchars($activity['full_name'] ?? 'Deleted worker'); ?><div class="activity-meta"><?php echo htmlspecialchars($activity['email'] ?? ''); ?></div></td><td><span class="badge bg-light text-dark"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $activity['event_type']))); ?></span></td><td class="activity-description"><?php echo htmlspecialchars($activity['description']); ?></td><td><?php echo htmlspecialchars($activity['ip_address'] ?? '-'); ?></td><td><div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary" data-activity="<?php echo $activityJson; ?>" onclick="viewActivityDetails(this.dataset.activity)" title="View activity details"><i class="bi bi-eye"></i> View</button><button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?php echo (int)$activity['id']; ?>, this)" title="Delete activity log"><i class="bi bi-trash"></i></button></div></td></tr><?php endforeach; endif; ?></tbody></table></div></div>

<div class="modal fade" id="activityDetailsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Activity Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="activityDetailsBody"></div></div></div></div>

<script>
const activityCsrfToken = <?php echo json_encode($activityCsrfToken); ?>;

function deleteActivity(activityId, button) {
    if (!confirm('Delete this activity log permanently? This action cannot be undone.')) return;
    button.disabled = true;
    const formData = new FormData();
    formData.append('action', 'delete_activity');
    formData.append('activity_id', activityId);
    formData.append('csrf_token', activityCsrfToken);
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(response) {
        return response.text().then(function(text) {
            try {
                return JSON.parse(text);
            } catch (error) {
                throw new Error('The server returned an unexpected response. Please refresh the page and try again.');
            }
        });
    })
    .then(function(data) {
        if (!data.success) throw new Error(data.message || 'Unable to delete activity.');
        const row = button.closest('tr');
        if (row) row.remove();
    })
    .catch(function(error) {
        button.disabled = false;
        alert(error.message);
    });
}

function viewActivityDetails(encoded) {
    try {
        const activity = JSON.parse(encoded);
        const body = document.getElementById('activityDetailsBody');
        const metadata = activity.metadata || {};
        body.innerHTML = '';
        const heading = document.createElement('div');
        heading.className = 'alert alert-light border';
        const workerName = document.createElement('strong');
        workerName.textContent = activity.worker || 'Deleted worker';
        const description = document.createElement('div');
        description.textContent = activity.description || 'Activity recorded';
        const timestamp = document.createElement('small');
        timestamp.textContent = activity.time || '';
        heading.append(workerName, document.createElement('br'), description, timestamp);
        body.appendChild(heading);
        if (metadata.before || metadata.after) {
            const title = document.createElement('h6');
            title.className = 'fw-bold mt-3';
            title.textContent = metadata.entry_id ? 'What changed in entry #' + metadata.entry_id : 'What changed';
            body.appendChild(title);
            const table = document.createElement('table');
            table.className = 'table table-bordered align-middle';
            table.innerHTML = '<thead><tr><th>Field</th><th class="text-danger">Before</th><th class="text-success">After</th></tr></thead><tbody></tbody>';
            const fields = {date:'Date', reading_type:'Reading type', morning_reading:'Morning reading', evening_reading:'Evening reading', units_consumed:'Units consumed', cost:'Cost', notes:'Notes', category_id:'Category', site_id:'Site'};
            const rows = table.querySelector('tbody');
            Object.keys(fields).forEach(function(key) {
                const before = metadata.before && metadata.before[key] !== undefined ? metadata.before[key] : '-';
                const after = metadata.after && metadata.after[key] !== undefined ? metadata.after[key] : '-';
                if (String(before) === String(after)) return;
                const row = document.createElement('tr');
                [fields[key], before, after].forEach(function(value, index) {
                    const cell = document.createElement('td');
                    let displayValue = index === 0 ? value : (value === '' ? '(empty)' : String(value));
                    if (key === 'reading_type' && index > 0) {
                        displayValue = displayValue.replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });
                    }
                    cell.textContent = displayValue;
                    row.appendChild(cell);
                });
                rows.appendChild(row);
            });
            if (!rows.children.length) { const row = document.createElement('tr'); row.innerHTML = '<td colspan="3" class="text-muted">No field values changed.</td>'; rows.appendChild(row); }
            body.appendChild(table);
        } else {
            const title = document.createElement('h6');
            title.className = 'fw-bold mt-3';
            title.textContent = 'Activity information';
            body.appendChild(title);
            const table = document.createElement('table');
            table.className = 'table table-bordered align-middle';
            table.innerHTML = '<thead><tr><th>Information</th><th>Value</th></tr></thead><tbody></tbody>';
            const labels = {
                entry_id: 'Entry number',
                entry_date: 'Entry date',
                date: 'Date',
                reading_type: 'Reading type',
                morning_reading: 'Morning reading',
                evening_reading: 'Evening reading',
                units_consumed: 'Units consumed',
                cost: 'Cost',
                notes: 'Notes',
                category_id: 'Category',
                site_id: 'Site'
            };
            const rows = table.querySelector('tbody');
            Object.keys(metadata).forEach(function(key) {
                const value = metadata[key];
                if (value !== null && typeof value === 'object') return;
                const row = document.createElement('tr');
                const label = document.createElement('td');
                label.className = 'fw-semibold';
                label.textContent = labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });
                const result = document.createElement('td');
                let displayValue = value === null || value === '' ? '(empty)' : String(value);
                if (key === 'reading_type') {
                    displayValue = displayValue.replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });
                }
                result.textContent = displayValue;
                row.append(label, result);
                rows.appendChild(row);
            });
            if (!rows.children.length) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="2" class="text-muted">No additional information recorded.</td>';
                rows.appendChild(row);
            }
            body.appendChild(table);
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('activityDetailsModal')).show();
    } catch (error) { alert('Unable to open activity details.'); console.error(error); }
}
</script>
