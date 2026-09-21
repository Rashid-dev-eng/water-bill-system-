<style>
.bill-preview {
    background: linear-gradient(135deg, #f8fafc, #e8f0f8);
    border-radius: 16px;
    padding: 2rem;
    border: 2px solid #1b5a7a;
    margin-top: 1.5rem;
}
.bill-preview .bill-header {
    border-bottom: 2px solid #1b5a7a;
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}
.bill-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}
.bill-total {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1b5a7a;
}
.bill-option {
    cursor: pointer;
    transition: 0.15s;
}
.bill-option:hover { background: #f0f5fa; }
.bill-option.active { background: #e3f0fa; border-color: #1b5a7a; }
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
.pdf-export {
    width: 190mm !important;
    max-width: 190mm !important;
    box-sizing: border-box !important;
    padding: 8mm !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    overflow: visible !important;
}
.pdf-export .table-responsive { overflow: visible !important; }
.pdf-export table {
    width: 100% !important;
    table-layout: fixed !important;
    font-size: 7.5pt !important;
}
.pdf-export th, .pdf-export td {
    padding: 3px 4px !important;
    overflow-wrap: anywhere;
    word-break: normal;
}
.pdf-export thead { display: table-header-group; }
.pdf-export tr, .pdf-export .bill-item, .pdf-export .bill-header {
    break-inside: avoid;
    page-break-inside: avoid;
}
@media print {
    .no-print { display: none !important; }
    @page { size: A4 portrait; margin: 12mm; }
    body { background: white !important; margin: 0 !important; padding: 0 !important; }
    .bill-preview { border: none !important; box-shadow: none !important; width: 100% !important; padding: 0 !important; }
    .bill-preview .table-responsive { overflow: visible !important; }
    .bill-preview table { width: 100% !important; table-layout: fixed !important; font-size: 8pt !important; }
    .bill-preview th, .bill-preview td { padding: 4px !important; overflow-wrap: anywhere; }
    .bill-preview thead { display: table-header-group; }
    .bill-preview tr, .bill-preview .bill-item { break-inside: avoid; page-break-inside: avoid; }
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
        
        if ($action === 'generate_bill') {
            $filter = $_POST['filter'] ?? 'all';
            $filter_value = $_POST['filter_value'] ?? '';
            $start_date = $_POST['start_date'] ?? $defaultStartDate;
            $end_date = $_POST['end_date'] ?? $defaultEndDate;
            $reading_type = $_POST['reading_type'] ?? 'all';

            if (!in_array($filter, ['all', 'category', 'site', 'user'], true)) {
                throw new Exception('Invalid bill filter selected.');
            }
            if ($filter !== 'all' && (int)$filter_value <= 0) {
                throw new Exception('Please select a value for the chosen bill filter.');
            }
            if (!in_array($reading_type, ['all', 'morning', 'evening', 'both'], true)) {
                throw new Exception('Please select a valid reading type.');
            }
            if ($start_date > $end_date) {
                throw new Exception('The start date cannot be after the end date.');
            }
            
            // Build query based on filters
            $where = "we.entry_date BETWEEN :start_date AND :end_date";
            $params = ['start_date' => $start_date, 'end_date' => $end_date];
            
            if ($filter === 'category' && !empty($filter_value)) {
                $where .= " AND we.site_id IS NULL AND we.category_id = :category_id";
                $params['category_id'] = $filter_value;
            } elseif ($filter === 'site' && !empty($filter_value)) {
                $where .= " AND we.site_id = :site_id";
                $params['site_id'] = $filter_value;
            } elseif ($filter === 'user' && !empty($filter_value)) {
                $where .= " AND we.user_id = :user_id";
                $params['user_id'] = $filter_value;
            }
            
            // A completed day is stored as one Both record. Include that
            // record for a Morning/Evening view, then render only the
            // selected period below.
            if ($reading_type === 'morning') {
                $where .= " AND we.reading_type IN ('morning', 'both')";
            } elseif ($reading_type === 'evening') {
                $where .= " AND we.reading_type IN ('evening', 'both')";
            } elseif ($reading_type === 'both') {
                $where .= " AND we.reading_type = 'both'";
            }
            
            $sql = "
                SELECT 
                    we.*,
                    u.full_name as worker_name,
                    u.phone as worker_phone,
                    u.email as worker_email,
                    s.name as site_name,
                    COALESCE(c_entry.name, c_site.name) as category_name
                FROM water_entries we
                JOIN users u ON we.user_id = u.id
                LEFT JOIN sites s ON we.site_id = s.id
                LEFT JOIN categories c_entry ON we.category_id = c_entry.id
                LEFT JOIN categories c_site ON s.category_id = c_site.id
                WHERE $where
                ORDER BY we.entry_date
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $entries = $stmt->fetchAll();
            $currentRate = (float)nolelo_setting('dawasco_rate', DAWASCO_RATE);
            foreach ($entries as &$entry) {
                $entry['filtered_units'] = $reading_type === 'morning'
                    ? (float)($entry['morning_reading'] ?? 0)
                    : ($reading_type === 'evening'
                        ? (float)($entry['evening_reading'] ?? 0)
                        : (float)($entry['units_consumed'] ?? 0));
                $entry['units_consumed'] = $entry['filtered_units'];
                $entry['cost'] = $entry['filtered_units'] * $currentRate;
                $entry['display_reading_type'] = in_array($reading_type, ['morning', 'evening'], true)
                    ? $reading_type
                    : ($entry['reading_type'] ?? 'morning');
                $entry['display_reading'] = $entry['display_reading_type'] === 'evening'
                    ? (float)($entry['evening_reading'] ?? 0)
                    : ($entry['display_reading_type'] === 'both'
                        ? 'M: ' . number_format((float)($entry['morning_reading'] ?? 0), 1) . ' / E: ' . number_format((float)($entry['evening_reading'] ?? 0), 1)
                        : (float)($entry['morning_reading'] ?? 0));
            }
            unset($entry);
            
            $total_units = array_sum(array_column($entries, 'units_consumed')) ?? 0;
            $total_cost = array_sum(array_column($entries, 'cost')) ?? 0;
            $entries_count = count($entries);
            
            if ($entries_count === 0) {
                throw new Exception('No entries found for the selected filters.');
            }
            
            // Generate bill number
            $bill_number = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $response['success'] = true;
            $response['message'] = 'Bill generated successfully!';
            $response['bill'] = [
                'bill_number' => $bill_number,
                'entries' => $entries,
                'total_units' => $total_units,
                'total_cost' => $total_cost,
                'entries_count' => $entries_count,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'reading_type' => $reading_type,
                'filter' => $filter,
                'filter_value' => $filter_value,
                'generated_date' => date('Y-m-d H:i:s')
            ];
        } else {
            throw new Exception('Invalid action: ' . $action);
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    sendJsonResponse($response);
}

// Get data for filters
try {
    global $pdo;
    $categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();
    $sites = $pdo->query("SELECT id, name FROM sites WHERE status = 'active' ORDER BY name")->fetchAll();
    $users = $pdo->query("SELECT id, full_name FROM users WHERE role = 'worker' AND status = 'active' ORDER BY full_name")->fetchAll();
} catch (Exception $e) {
    $categories = [];
    $sites = [];
    $users = [];
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

<h4><i class="bi bi-receipt-cutoff me-2" style="color:#1b5a7a;"></i>Bill Generation</h4>
<p class="text-muted-small">Generate water bills with various filters.</p>

<!-- Bill Generator Form -->
<div class="card border-0 shadow-sm p-3 mb-4 no-print">
    <form id="billForm" onsubmit="return generateBill()">
        <input type="hidden" name="filter" id="billFilter" value="all">
        <div class="row g-3">
            <div class="col-12">
                <label class="text-muted-small fw-semibold mb-1">Filter by</label>
                <div class="d-flex flex-wrap gap-2" id="billFilterOptions">
                    <button type="button" class="btn btn-sm btn-outline-primary bill-option active" data-filter="all" onclick="updateFilter('all')">
                        <i class="bi bi-globe"></i> All organization
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary bill-option" data-filter="category" onclick="updateFilter('category')">
                        <i class="bi bi-tags"></i> Category
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary bill-option" data-filter="site" onclick="updateFilter('site')">
                        <i class="bi bi-geo-alt"></i> Site
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary bill-option" data-filter="user" onclick="updateFilter('user')">
                        <i class="bi bi-person"></i> User
                    </button>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Filter Value</label>
                <select class="form-select form-select-sm" id="filterSelect" name="filter_value">
                    <option value="">All (default)</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control form-control-sm" name="start_date" id="startDate" value="<?php echo htmlspecialchars($defaultStartDate); ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control form-control-sm" name="end_date" id="endDate" value="<?php echo htmlspecialchars($defaultEndDate); ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Reading Type</label>
                <select class="form-select form-select-sm" name="reading_type" id="readingType">
                    <option value="none">None</option>
                    <option value="all" selected>All reading types</option>
                    <option value="both">Both</option>
                    <option value="morning">Morning only</option>
                    <option value="evening">Evening only</option>
                </select>
            </div>
            <div class="col-12 mt-2 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-file-spreadsheet"></i> Generate Bill
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="printBill()">
                    <i class="bi bi-printer"></i> Print
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="downloadPDF()">
                    <i class="bi bi-download"></i> Download PDF
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Bill Preview -->
<div id="billPreviewContainer">
    <?php if (isset($_SESSION['bill_data'])): 
        $bill = $_SESSION['bill_data'];
        unset($_SESSION['bill_data']);
    ?>
    <div class="bill-preview" id="billPreview" data-bill-number="<?php echo htmlspecialchars($bill['bill_number'], ENT_QUOTES); ?>">
        <div class="bill-header text-center">
            <h3 class="fw-bold" style="color:#1b5a7a;">
                <i class="bi bi-droplet-half"></i> NOLELO Clean Water Supply
            </h3>
            <p class="text-muted-small">Water Bill Statement</p>
        </div>
        
        <div class="row g-3">
            <div class="col-md-6">
                <div class="bill-item">
                    <span>Bill Number:</span>
                    <strong><?php echo $bill['bill_number']; ?></strong>
                </div>
                <div class="bill-item">
                    <span>Organization:</span>
                    <strong>NOLELO Clean Water Supply</strong>
                </div>
                <div class="bill-item">
                    <span>Period:</span>
                    <strong><?php echo date('d M Y', strtotime($bill['start_date'])) . ' – ' . date('d M Y', strtotime($bill['end_date'])); ?></strong>
                </div>
            </div>
            <div class="col-md-6">
                <div class="bill-item">
                    <span>Generated Date:</span>
                    <strong><?php echo date('d M Y H:i:s'); ?></strong>
                </div>
                <div class="bill-item">
                    <span>Reading Type:</span>
                    <strong><?php echo ucfirst($bill['reading_type']); ?></strong>
                </div>
                <div class="bill-item">
                    <span>Total Entries:</span>
                    <strong><?php echo $bill['entries_count']; ?></strong>
                </div>
            </div>
        </div>
        
        <?php if (!empty($bill['entries'])): ?>
        <hr>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Worker</th>
                        <th>Site</th>
                        <th>Category</th>
                        <th>Reading Type</th>
                        <th>Reading</th>
                        <th>Units</th>
                        <th>Cost (TSh)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bill['entries'] as $entry): ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></td>
                        <td><?php echo htmlspecialchars($entry['worker_name']); ?></td>
                        <td><?php echo htmlspecialchars($entry['site_name'] ?? $entry['category_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($entry['category_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($entry['display_reading_type'] ?? $entry['reading_type'] ?? 'morning')); ?></td>
                        <td><?php echo is_numeric($entry['display_reading'] ?? null) ? number_format((float)$entry['display_reading'], 1) : htmlspecialchars((string)$entry['display_reading']); ?></td>
                        <td><strong><?php echo number_format($entry['units_consumed'] ?? 0, 1); ?></strong></td>
                        <td><strong><?php echo number_format($entry['cost'] ?? 0); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end fw-bold">Total:</td>
                        <td class="fw-bold"><?php echo number_format($bill['total_units'], 1); ?> m³</td>
                    <td class="fw-bold text-success"><?php echo number_format($bill['total_cost']); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
        
        <hr>
        <div class="row g-3 mt-2">
            <div class="col-md-4">
                <div class="bill-item">
                    <span>Total Units Consumed:</span>
                    <strong class="bill-total"><?php echo number_format($bill['total_units'], 1); ?> m³</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bill-item">
                    <span>Rate per Unit:</span>
                    <strong><?php echo number_format((float)nolelo_setting('dawasco_rate', DAWASCO_RATE), 2); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bill-item">
                    <span>Total Amount:</span>
                    <strong class="text-success bill-total"><?php echo number_format($bill['total_cost']); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?></strong>
                </div>
            </div>
        </div>
        
        <div class="mt-3 text-center text-muted-small">
            <i class="bi bi-info-circle"></i> 
            DAWASCO rate: <?php echo number_format((float)nolelo_setting('dawasco_rate', DAWASCO_RATE), 2); ?> <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?> per unit · All prices in <?php echo htmlspecialchars(nolelo_setting('currency', CURRENCY)); ?>
            <br>
            <small>This is a computer-generated bill. No signature required.</small>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
// ===== FILTER FUNCTIONS =====
function updateFilter(filter) {
    document.querySelectorAll('.bill-option').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`.bill-option[data-filter="${filter}"]`).classList.add('active');
    document.getElementById('billFilter').value = filter;
    
    const select = document.getElementById('filterSelect');
    let options = '';
    
    if (filter === 'all') {
        options = `<option value="">All (default)</option>`;
    } else if (filter === 'category') {
        options = `<option value="">Select category</option>
                  <?php foreach ($categories as $cat): ?>
                  <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                  <?php endforeach; ?>`;
    } else if (filter === 'site') {
        options = `<option value="">Select site</option>
                  <?php foreach ($sites as $site): ?>
                  <option value="<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['name']); ?></option>
                  <?php endforeach; ?>`;
    } else if (filter === 'user') {
        options = `<option value="">Select user</option>
                  <?php foreach ($users as $user): ?>
                  <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                  <?php endforeach; ?>`;
    }
    select.innerHTML = options;
}

function parseBillAjaxResponse(response) {
    if (response.status === 401) {
        window.location.href = 'login.php';
        throw new Error('Session expired. Please log in again.');
    }

    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        return response.json();
    }

    return response.text().then(text => {
        const trimmed = text.trim();

        if (!trimmed) {
            throw new Error('Empty server response.');
        }

        try {
            return JSON.parse(trimmed);
        } catch (error) {
            if (trimmed.startsWith('<')) {
                throw new Error('Server returned HTML instead of JSON.');
            }

            throw new Error(trimmed.slice(0, 180));
        }
    });
}

// ===== GENERATE BILL =====
function generateBill() {
    const form = document.getElementById('billForm');
    const formData = new FormData(form);
    formData.append('action', 'generate_bill');
    
    document.getElementById('loadingOverlay').classList.add('show');
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseBillAjaxResponse)
    .then(data => {
        document.getElementById('loadingOverlay').classList.remove('show');
        
        if (data.success) {
            showToast('success', data.message);
            // Store bill data and reload to show preview
            const billData = encodeURIComponent(JSON.stringify(data.bill));
            const url = new URL(window.location.href);
            url.searchParams.set('bill', billData);
            window.location.href = url.toString();
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

// ===== PRINT BILL =====
function printBill() {
    const preview = document.getElementById('billPreview');
    if (!preview) {
        showToast('warning', 'Please generate a bill first.');
        return;
    }
    
    // Check if bill preview exists
    const billContent = document.getElementById('billPreview');
    if (!billContent || billContent.innerHTML.trim() === '') {
        showToast('warning', 'Please generate a bill first.');
        return;
    }
    
    // Store original content
    const originalContent = document.body.innerHTML;
    
    // Create print content
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Bill - NOLELO Water Supply</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <style>
                @page { size: A4 portrait; margin: 12mm; }
                body { margin: 0; font-family: Arial, sans-serif; color: #1f2937; }
                .bill-preview { width: 100%; box-sizing: border-box; border: 1px solid #ddd; padding: 8mm; border-radius: 8px; }
                .bill-header { border-bottom: 2px solid #1b5a7a; padding-bottom: 15px; margin-bottom: 20px; }
                .bill-item { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eee; }
                .bill-total { font-size: 1.3rem; font-weight: 700; color: #1b5a7a; }
                .table-responsive { overflow: visible !important; }
                table { width: 100%; table-layout: fixed; font-size: 8pt; border-collapse: collapse; }
                th, td { padding: 4px; overflow-wrap: anywhere; vertical-align: top; }
                thead { display: table-header-group; }
                tr, .bill-item, .bill-header { break-inside: avoid; page-break-inside: avoid; }
                @media print { .no-print { display: none !important; } }
            </style>
        </head>
        <body>
            ${document.getElementById('billPreview').outerHTML}
            <div class="text-center mt-4 text-muted">
                <small>Generated: ${new Date().toLocaleString()}</small>
            </div>
        </body>
        </html>
    `;
    
    // Open print window
    const printWindow = window.open('', '_blank', 'width=1000,height=800');
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    setTimeout(() => {
        printWindow.print();
        showToast('success', 'Print job sent successfully!');
    }, 500);
}

// ===== DOWNLOAD PDF =====
function downloadPDF() {
    const preview = document.getElementById('billPreview');
    if (!preview || preview.innerHTML.trim() === '') {
        showToast('warning', 'Please generate a bill first.');
        return;
    }

    if (typeof html2pdf === 'undefined') {
        showToast('danger', 'PDF library did not load. Please refresh the page and try again.');
        return;
    }

    const billNumber = (preview.dataset.billNumber || 'bill').toString().trim() || 'bill';
    const filename = billNumber.replace(/[^a-z0-9_-]+/gi, '_') + '.pdf';
    const wrapper = document.createElement('div');
    wrapper.style.position = 'fixed';
    wrapper.style.left = '-10000px';
    wrapper.style.top = '0';
    wrapper.style.width = '210mm';

    const clone = preview.cloneNode(true);
    clone.classList.add('pdf-export');
    wrapper.appendChild(clone);
    document.body.appendChild(wrapper);

    document.getElementById('loadingOverlay').classList.add('show');
    showToast('info', 'Preparing PDF download...');

    html2pdf()
        .set({
            margin: [8, 8, 8, 8],
            filename: filename,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.bill-item', '.bill-header'] },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        })
        .from(clone)
        .save()
        .then(() => {
            showToast('success', 'PDF downloaded successfully!');
        })
        .catch(error => {
            showToast('danger', 'PDF download failed: ' + error.message);
        })
        .finally(() => {
            document.getElementById('loadingOverlay').classList.remove('show');
            if (wrapper.parentNode) {
                wrapper.parentNode.removeChild(wrapper);
            }
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
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" onclick="hideToast('${toastId}')"></button>
        </div>
    `;
    
    container.appendChild(toast);
    
    // Auto hide after 3 seconds
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

// ===== CHECK FOR BILL DATA ON LOAD =====
document.addEventListener('DOMContentLoaded', function() {
    // Check if bill data exists in URL
    const urlParams = new URLSearchParams(window.location.search);
    const billData = urlParams.get('bill');
    
    if (billData) {
        try {
            const bill = JSON.parse(decodeURIComponent(billData));
            // Store in session and reload without param
            fetch('save_bill_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bill: bill })
            })
            .then(() => {
                const url = new URL(window.location.href);
                url.searchParams.delete('bill');
                window.location.href = url.toString();
            });
        } catch (e) {
            console.error('Error parsing bill data:', e);
        }
    }
});

// ===== UPDATE FILTER ON LOAD =====
// Initialize filter dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Set default filter
    updateFilter('all');
});
</script>
