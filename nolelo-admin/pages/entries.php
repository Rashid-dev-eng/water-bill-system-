<style>
.entry-stats {
    background: linear-gradient(135deg, #f8fafc, #e8f0f8);
    border-radius: 16px;
    padding: 1.5rem;
}
.stat-number { font-size: 2rem; font-weight: 700; color: #1b5a7a; }
.form-card {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 4px solid #1b5a7a;
    display: none;
}
.form-card.show { display: block; }
.form-card .form-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1b5a7a;
    margin-bottom: 1.5rem;
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
.filter-bar {
    background: white;
    padding: 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    border: 1px solid rgba(0,0,0,0.05);
}
</style>

<?php
$defaultReadingType = nolelo_setting('default_reading_type', 'both');
// Admin check
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        sendJsonResponse(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }
    header('Location: index.php');
    exit;
}
// ... rest of your code ...

// Handle AJAX requests FIRST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Unknown action'];
    $action = $_POST['action'] ?? '';
    
    try {
        global $pdo;
        
        if ($action === 'add_entry') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $site_id = (int)($_POST['site_id'] ?? 0);
            $entry_date = $_POST['entry_date'] ?? date('Y-m-d');
            $reading_type = $_POST['reading_type'] ?? 'both';
            $morning_input = trim((string)($_POST['morning_reading'] ?? ''));
            $evening_input = trim((string)($_POST['evening_reading'] ?? ''));
            $morning_reading = $morning_input === '' ? 0.0 : filter_var($morning_input, FILTER_VALIDATE_FLOAT);
            $evening_reading = $evening_input === '' ? 0.0 : filter_var($evening_input, FILTER_VALIDATE_FLOAT);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($user_id <= 0) {
                throw new Exception('Please select a worker.');
            }
            
            if ($site_id <= 0) {
                throw new Exception('Please select a site.');
            }
            
            if (empty($entry_date)) {
                throw new Exception('Please select a date.');
            }

            if ($morning_reading === false || $evening_reading === false) {
                throw new Exception('Meter readings must be valid numbers. Decimals like 1679.63 are allowed.');
            }

            if ($morning_reading < 0 || $evening_reading < 0) {
                throw new Exception('Meter readings cannot be negative.');
            }
            
            $site = dbFetchOne("SELECT category_id, name FROM sites WHERE id = ?", [$site_id]);
            if (!$site) {
                throw new Exception('Selected site was not found.');
            }

            // Readings are shared by site/date/period. Workers may change from day to day.
            $duplicateTypeSql = $reading_type === 'both'
                ? "reading_type IN ('morning', 'evening', 'both')"
                : "reading_type = ?";
            $stmt = $pdo->prepare("SELECT id FROM water_entries WHERE site_id = ? AND entry_date = ? AND $duplicateTypeSql LIMIT 1");
            $duplicateParams = [$site_id, $entry_date];
            if ($reading_type !== 'both') {
                $duplicateParams[] = $reading_type;
            }
            $stmt->execute($duplicateParams);
            if ($stmt->fetch()) {
                throw new Exception('That period has already been recorded for this site and date.');
            }
            
            // Calculate units and cost
            $units_consumed = 0;
            $cost = 0;
            
            if ($reading_type === 'both' || $reading_type === 'morning') {
                if ($morning_reading > 0 && $evening_reading > 0) {
                    $units_consumed = $evening_reading - $morning_reading;
                } elseif ($morning_reading > 0) {
                    $units_consumed = $morning_reading;
                }
            } elseif ($reading_type === 'evening' && $evening_reading > 0) {
                $units_consumed = $evening_reading;
            }
            
            // Complete a shared day when the opposite period arrives, regardless of worker.
            $opposite_type = $reading_type === 'morning' ? 'evening' : ($reading_type === 'evening' ? 'morning' : null);
            $opposite = null;
            if ($opposite_type !== null) {
                $stmt = $pdo->prepare("SELECT id, morning_reading, evening_reading FROM water_entries WHERE site_id = ? AND entry_date = ? AND reading_type = ? LIMIT 1");
                $stmt->execute([$site_id, $entry_date, $opposite_type]);
                $opposite = $stmt->fetch();
            }

            if ($opposite) {
                $morning_reading = $reading_type === 'morning' ? $morning_reading : (float)$opposite['morning_reading'];
                $evening_reading = $reading_type === 'evening' ? $evening_reading : (float)$opposite['evening_reading'];
                $units_consumed = max(0, $evening_reading - $morning_reading);
                $cost = $units_consumed * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
                $stmt = $pdo->prepare("UPDATE water_entries SET morning_reading = ?, evening_reading = ?, units_consumed = ?, cost = ?, reading_type = 'both', notes = ? WHERE id = ?");
                $result = $stmt->execute([$morning_reading, $evening_reading, $units_consumed, $cost, $notes !== '' ? $notes : null, $opposite['id']]);
                $entry_id = (int)$opposite['id'];
            } else {
                if ($units_consumed > 0) {
                    $cost = $units_consumed * (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
                }
            
            $stmt = $pdo->prepare("INSERT INTO water_entries (user_id, category_id, site_id, entry_date, morning_reading, evening_reading, units_consumed, cost, reading_type, notes, status) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $result = $stmt->execute([$user_id, $site['category_id'] ?? null, $site_id, $entry_date, $morning_reading, $evening_reading, $units_consumed, $cost, $reading_type, $notes]);
            }
            
            if ($result) {
                $entry_id = $pdo->lastInsertId();
                
                // Get user and site names for response
                $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                $response['success'] = true;
                $response['message'] = 'Water entry added successfully!';
                $response['entry'] = [
                    'id' => $entry_id,
                    'user_id' => $user_id,
                    'user_name' => $user['full_name'] ?? 'Unknown',
                    'site_id' => $site_id,
                    'site_name' => $site['name'] ?? 'Unknown',
                    'category_id' => $site['category_id'] ?? null,
                    'entry_date' => $entry_date,
                    'morning_reading' => $morning_reading,
                    'evening_reading' => $evening_reading,
                    'units_consumed' => $units_consumed,
                    'cost' => $cost,
                    'reading_type' => $reading_type,
                    'status' => 'pending'
                ];
            } else {
                throw new Exception('Failed to add entry. Please try again.');
            }
        } elseif ($action === 'delete_entry') {
            $entry_id = (int)($_POST['entry_id'] ?? 0);
            
            if ($entry_id <= 0) {
                throw new Exception('Invalid entry ID.');
            }
            
            $stmt = $pdo->prepare("DELETE FROM water_entries WHERE id = ?");
            $result = $stmt->execute([$entry_id]);
            
            if ($result && $stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Entry deleted successfully!';
                $response['deleted_id'] = $entry_id;
            } else {
                throw new Exception('Failed to delete entry.');
            }
        } else {
            throw new Exception('Invalid action: ' . $action);
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    sendJsonResponse($response);
}

// Build filter conditions
$where_conditions = [];
$params = [];

if (isset($_GET['filter_date']) && !empty($_GET['filter_date'])) {
    $where_conditions[] = "we.entry_date = ?";
    $params[] = $_GET['filter_date'];
}

if (isset($_GET['filter_worker']) && !empty($_GET['filter_worker'])) {
    $where_conditions[] = "we.user_id = ?";
    $params[] = (int)$_GET['filter_worker'];
}

if (isset($_GET['filter_site']) && !empty($_GET['filter_site'])) {
    $where_conditions[] = "we.site_id = ?";
    $params[] = (int)$_GET['filter_site'];
}

if (isset($_GET['filter_status']) && !empty($_GET['filter_status'])) {
    $where_conditions[] = "we.status = ?";
    $params[] = $_GET['filter_status'];
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get entries with joins
try {
    global $pdo;
    $sql = "
        SELECT 
            we.*,
            u.full_name as worker_name,
            s.name as site_name,
            COALESCE(c_entry.name, c_site.name) as category_name,
            DATE_FORMAT(we.entry_date, '%Y-%m-%d') as entry_date_formatted
        FROM water_entries we
        JOIN users u ON we.user_id = u.id
        LEFT JOIN sites s ON we.site_id = s.id
        LEFT JOIN categories c_entry ON we.category_id = c_entry.id
        LEFT JOIN categories c_site ON s.category_id = c_site.id
        $where_sql
        ORDER BY we.entry_date DESC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
    
    // Get stats
    $total_entries = count($entries);
    $total_units = array_sum(array_column($entries, 'units_consumed')) ?? 0;
    $total_cost = array_sum(array_column($entries, 'cost')) ?? 0;
    
    // Get workers and sites for filters
    $workers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'worker' AND status = 'active' ORDER BY full_name")->fetchAll();
    $sites = $pdo->query("SELECT id, name FROM sites WHERE status = 'active' ORDER BY name")->fetchAll();
    $categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $entries = [];
    $total_entries = 0;
    $total_units = 0;
    $total_cost = 0;
    $workers = [];
    $sites = [];
    $categories = [];
    error_log("Error fetching entries: " . $e->getMessage());
}
?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<h4><i class="bi bi-clipboard-data me-2" style="color:#1b5a7a;"></i>Water Entries</h4>
<p class="text-muted-small">View and manage water meter readings entered by workers.</p>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- ===== ADD ENTRY FORM ===== -->
<div class="form-card" id="addEntryFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-plus-circle me-2"></i> Add New Water Entry
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm('addEntry')">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="addEntryForm" onsubmit="return submitEntryForm('add')">
        <input type="hidden" name="action" value="add_entry">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Worker *</label>
                <select class="form-select" name="user_id" id="addEntryWorker" required>
                    <option value="">Select worker</option>
                    <?php foreach ($workers as $worker): ?>
                    <option value="<?php echo $worker['id']; ?>"><?php echo htmlspecialchars($worker['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Site *</label>
                <select class="form-select" name="site_id" id="addEntrySite" required>
                    <option value="">Select site</option>
                    <?php foreach ($sites as $site): ?>
                    <option value="<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Date *</label>
                <input type="date" class="form-control" name="entry_date" id="addEntryDate" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Reading Type</label>
                <select class="form-select" name="reading_type" id="addEntryReadingType">
                    <option value="both" <?php echo $defaultReadingType === 'both' ? 'selected' : ''; ?>>Both (Morning & Evening)</option>
                    <option value="morning" <?php echo $defaultReadingType === 'morning' ? 'selected' : ''; ?>>Morning only</option>
                    <option value="evening" <?php echo $defaultReadingType === 'evening' ? 'selected' : ''; ?>>Evening only</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Morning Reading (m³)</label>
                <input type="number" class="form-control" name="morning_reading" id="addEntryMorning" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="col-md-6">
                <label class="form-label">Evening Reading (m³)</label>
                <input type="number" class="form-control" name="evening_reading" id="addEntryEvening" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" id="addEntryNotes" rows="2" placeholder="Optional notes"></textarea>
            </div>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Units will be calculated automatically based on readings.
                    <br>
                    <small>DAWASCO rate: <?php echo number_format((float)nolelo_setting('dawasco_rate', DAWASCO_RATE), 2); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?> per unit</small>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Entry
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm('addEntry')">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="entry-stats text-center">
            <div class="stat-number"><?php echo $total_entries; ?></div>
            <div class="text-muted-small">Total Entries</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="entry-stats text-center">
            <div class="stat-number"><?php echo number_format($total_units, 1); ?></div>
            <div class="text-muted-small">Total Units (m³)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="entry-stats text-center">
            <div class="stat-number"><?php echo number_format($total_cost); ?></div>
            <div class="text-muted-small">Total Cost (TSh)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="entry-stats text-center">
            <div class="stat-number"><?php echo date('d M Y'); ?></div>
            <div class="text-muted-small">Today's Date</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" action="" id="filterForm">
        <div class="row g-2">
            <div class="col-md-2">
                <label class="text-muted-small">Date</label>
                <input type="date" class="form-control form-control-sm" name="filter_date" value="<?php echo $_GET['filter_date'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="text-muted-small">Worker</label>
                <select class="form-select form-select-sm" name="filter_worker">
                    <option value="">All Workers</option>
                    <?php foreach ($workers as $worker): ?>
                    <option value="<?php echo $worker['id']; ?>" <?php echo (isset($_GET['filter_worker']) && $_GET['filter_worker'] == $worker['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($worker['full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="text-muted-small">Site</label>
                <select class="form-select form-select-sm" name="filter_site">
                    <option value="">All Sites</option>
                    <?php foreach ($sites as $site): ?>
                    <option value="<?php echo $site['id']; ?>" <?php echo (isset($_GET['filter_site']) && $_GET['filter_site'] == $site['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($site['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="text-muted-small">Status</label>
                <select class="form-select form-select-sm" name="filter_status">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="verified" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] == 'verified') ? 'selected' : ''; ?>>Verified</option>
                    <option value="rejected" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel"></i> Apply Filter
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Entries Table -->
<div class="card border-0 shadow-sm p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>All Entries</h5>
        <button class="btn btn-primary" onclick="showAddEntryForm()">
            <i class="bi bi-plus"></i> Add Entry
        </button>
    </div>
    
    <div class="table-responsive">
        <table class="table table-modern" id="entriesTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Worker</th>
                    <th>Site</th>
                    <th>Morning</th>
                    <th>Evening</th>
                    <th>Units</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="entriesTableBody">
                <?php if (empty($entries)): ?>
                <tr id="noEntriesRow">
                    <td colspan="9" class="text-center py-4">
                        <div class="text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            No entries found. Click "Add Entry" to create one.
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                <tr id="entryRow_<?php echo $entry['id']; ?>">
                    <td><span class="fw-semibold"><?php echo $entry['entry_date_formatted']; ?></span></td>
                    <td><?php echo htmlspecialchars($entry['worker_name']); ?></td>
                    <td><?php echo htmlspecialchars($entry['site_name'] ?? $entry['category_name'] ?? '-'); ?></td>
                    <td><?php echo number_format($entry['morning_reading'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($entry['evening_reading'] ?? 0, 2); ?></td>
                    <td><span class="fw-semibold"><?php echo number_format($entry['units_consumed'] ?? 0, 2); ?></span></td>
                    <td><span class="text-success fw-semibold"><?php echo number_format($entry['cost'] ?? 0); ?></span></td>
                    <td>
                        <span class="badge-dawasco">
                            <?php echo ucfirst($entry['status'] ?? 'pending'); ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php $entryJson = htmlspecialchars(json_encode($entry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>
                        <button class="btn btn-sm btn-outline-info me-1" data-entry="<?php echo $entryJson; ?>" onclick="viewEntryDetails(this.dataset.entry)" title="View entry details">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteEntry(<?php echo $entry['id']; ?>, '<?php echo addslashes($entry['worker_name']); ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($entries)): ?>
            <tfoot>
                <tr>
                    <td colspan="5" class="text-end fw-bold">Total:</td>
                    <td class="fw-bold"><?php echo number_format($total_units, 2); ?></td>
                    <td class="fw-bold text-success"><?php echo number_format($total_cost); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <div class="text-muted-small">* DAWASCO rate: <?php echo number_format((float)nolelo_setting('dawasco_rate', DAWASCO_RATE), 2); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?> / unit</div>
</div>

<div class="modal fade" id="entryDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Entry Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3" id="entryDetailsContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
function viewEntryDetails(encodedEntry) {
    try {
        const entry = JSON.parse(encodedEntry);
        const content = document.getElementById('entryDetailsContent');
        const value = (item, fallback = '-') => item === null || item === undefined || item === '' ? fallback : item;
        content.innerHTML = '';
        [
            ['Worker', value(entry.worker_name)],
            ['Date', value(entry.entry_date_formatted || entry.entry_date)],
            ['Category', value(entry.category_name)],
            ['Site', value(entry.site_name)],
            ['Reading Type', value(entry.reading_type)],
            ['Morning Reading', Number(entry.morning_reading || 0).toFixed(2) + ' m3'],
            ['Evening Reading', Number(entry.evening_reading || 0).toFixed(2) + ' m3'],
            ['Units Consumed', Number(entry.units_consumed || 0).toFixed(2) + ' m3'],
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
        bootstrap.Modal.getOrCreateInstance(document.getElementById('entryDetailsModal')).show();
    } catch (error) {
        showToast('danger', 'Unable to open entry details.');
        console.error(error);
    }
}

// ===== SHOW ADD ENTRY FORM =====
function showAddEntryForm() {
    document.getElementById('addEntryFormCard').classList.add('show');
    document.getElementById('addEntryFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ===== CANCEL FORM =====
function cancelForm(type) {
    if (type === 'addEntry' || type === 'all') {
        document.getElementById('addEntryFormCard').classList.remove('show');
        document.getElementById('addEntryForm').reset();
        document.getElementById('addEntryDate').value = '<?php echo date('Y-m-d'); ?>';
    }
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

// ===== SUBMIT ENTRY FORM =====
function submitEntryForm(type) {
    const form = document.getElementById('addEntryForm');
    const formData = new FormData(form);
    
    // Validate readings
    const readingType = document.getElementById('addEntryReadingType').value;
    const morning = parseFloat(document.getElementById('addEntryMorning').value) || 0;
    const evening = parseFloat(document.getElementById('addEntryEvening').value) || 0;
    
    if (readingType === 'both' || readingType === 'morning') {
        if (morning <= 0 && evening <= 0) {
            showToast('danger', 'Please enter at least one reading value.');
            return false;
        }
    }
    
    if (readingType === 'evening' && evening <= 0) {
        showToast('danger', 'Please enter an evening reading.');
        return false;
    }
    
    document.getElementById('loadingOverlay').classList.add('show');
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(parseAjaxResponse)
    .then(data => {
        document.getElementById('loadingOverlay').classList.remove('show');
        
        if (data.success) {
            showToast('success', data.message);
            setTimeout(() => location.reload(), 3000);
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

// ===== DELETE ENTRY =====
function deleteEntry(entryId, workerName) {
    if (confirm(`Are you sure you want to delete this entry for "${workerName}"? This action cannot be undone.`)) {
        document.getElementById('loadingOverlay').classList.add('show');
        
        const formData = new FormData();
        formData.append('action', 'delete_entry');
        formData.append('entry_id', entryId);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(parseAjaxResponse)
        .then(data => {
            document.getElementById('loadingOverlay').classList.remove('show');
            
            if (data.success) {
                showToast('success', data.message);
                const row = document.getElementById('entryRow_' + entryId);
                if (row) row.remove();
                
                // Check if table is empty
                const tbody = document.getElementById('entriesTableBody');
                if (tbody.children.length === 0) {
                    tbody.innerHTML = `
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
            } else {
                showToast('danger', data.message || 'Failed to delete entry.');
            }
        })
        .catch(error => {
            document.getElementById('loadingOverlay').classList.remove('show');
            showToast('danger', 'Error: ' + error.message);
            console.error('Fetch Error:', error);
        });
    }
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

// Auto-calculate units on reading change
document.addEventListener('DOMContentLoaded', function() {
    const morningInput = document.getElementById('addEntryMorning');
    const eveningInput = document.getElementById('addEntryEvening');
    const readingType = document.getElementById('addEntryReadingType');
    
    function calculateUnits() {
        const morning = parseFloat(morningInput.value) || 0;
        const evening = parseFloat(eveningInput.value) || 0;
        const type = readingType.value;
        
        let units = 0;
        if (type === 'both' || type === 'morning') {
            if (morning > 0 && evening > 0) {
                units = evening - morning;
            } else if (morning > 0) {
                units = morning;
            }
        } else if (type === 'evening' && evening > 0) {
            units = evening;
        }
        
        // Show units in a tooltip or console
        if (units > 0) {
            console.log('Calculated units:', units);
        }
    }
    
    if (morningInput) morningInput.addEventListener('change', calculateUnits);
    if (eveningInput) eveningInput.addEventListener('change', calculateUnits);
    if (readingType) readingType.addEventListener('change', calculateUnits);
});
</script>
