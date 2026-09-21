<style>
.category-item, .site-item {
    transition: all 0.3s ease;
    border-radius: 12px;
    padding: 0.8rem 1.2rem;
    background: white;
    margin-bottom: 0.5rem;
    border: 1px solid rgba(0,0,0,0.05);
}
.category-item:hover, .site-item:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border-color: #1b5a7a;
}
.category-icon, .site-icon {
    width: 35px;
    height: 35px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #e3f0fa, #c5dce8);
    color: #0b2b3f;
}
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

// NO OUTPUT BEFORE THIS POINT - CRITICAL

// Handle AJAX requests FIRST before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $response = ['success' => false, 'message' => 'Unknown action'];
    $action = $_POST['action'] ?? '';
    
    try {
        global $pdo;
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        if ($createdBy <= 0) {
            throw new Exception('Invalid admin session.');
        }
        
        // Debug: Log the action
        error_log("Action: " . $action);
        error_log("POST data: " . print_r($_POST, true));
        
        if ($action === 'add_category') {
            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Category name is required.');
            }
            
            // Check if category exists
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception('Category "' . $name . '" already exists.');
            }
            
            $stmt = $pdo->prepare("INSERT INTO categories (name, description, status, created_by) VALUES (?, ?, 'active', ?)");
            $result = $stmt->execute([$name, $description, $createdBy]);
            
            if ($result) {
                $category_id = $pdo->lastInsertId();
                $response['success'] = true;
                $response['message'] = 'Category "' . $name . '" added successfully!';
                $response['category'] = [
                    'id' => $category_id,
                    'name' => $name,
                    'description' => $description
                ];
            } else {
                throw new Exception('Failed to add category. Please try again.');
            }
        } elseif ($action === 'edit_category') {
            $category_id = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Category name is required.');
            }
            
            if ($category_id <= 0) {
                throw new Exception('Invalid category ID.');
            }
            
            // Check if category exists for other categories
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $stmt->execute([$name, $category_id]);
            if ($stmt->fetch()) {
                throw new Exception('Category "' . $name . '" already exists.');
            }
            
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
            $result = $stmt->execute([$name, $description, $category_id]);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'Category updated successfully!';
            } else {
                throw new Exception('Failed to update category. Please try again.');
            }
        } elseif ($action === 'delete_category') {
            $category_id = (int)($_POST['category_id'] ?? 0);
            
            if ($category_id <= 0) {
                throw new Exception('Invalid category ID.');
            }
            
            // Check if category has sites
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $site_count = $stmt->fetchColumn();
            
            if ($site_count > 0) {
                throw new Exception('Cannot delete category with ' . $site_count . ' existing sites. Move or delete sites first.');
            }
            
            // Delete category assignments first
            $stmt = $pdo->prepare("DELETE FROM user_categories WHERE category_id = ?");
            $stmt->execute([$category_id]);
            
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $result = $stmt->execute([$category_id]);
            
            if ($result && $stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Category deleted successfully!';
                $response['deleted_id'] = $category_id;
            } else {
                throw new Exception('Failed to delete category. Category may not exist.');
            }
        } elseif ($action === 'add_site') {
            $name = trim($_POST['site_name'] ?? '');
            $category_id = (int)($_POST['category'] ?? 0);
            $location = trim($_POST['location'] ?? '');
            $address = trim($_POST['address'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Site name is required.');
            }
            
            if ($category_id <= 0) {
                throw new Exception('Please select a category.');
            }
            
            // Check if site exists
            $stmt = $pdo->prepare("SELECT id FROM sites WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                throw new Exception('Site "' . $name . '" already exists.');
            }
            
            $stmt = $pdo->prepare("INSERT INTO sites (name, category_id, location, address, status, created_by) VALUES (?, ?, ?, ?, 'active', ?)");
            $result = $stmt->execute([$name, $category_id, $location, $address, $createdBy]);
            
            if ($result) {
                $site_id = $pdo->lastInsertId();
                
                // Get category name for response
                $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $category = $stmt->fetch();
                
                $response['success'] = true;
                $response['message'] = 'Site "' . $name . '" added successfully!';
                $response['site'] = [
                    'id' => $site_id,
                    'name' => $name,
                    'category_id' => $category_id,
                    'category_name' => $category['name'] ?? 'Unknown',
                    'location' => $location,
                    'address' => $address
                ];
            } else {
                throw new Exception('Failed to add site. Please try again.');
            }
        } elseif ($action === 'edit_site') {
            $site_id = (int)($_POST['site_id'] ?? 0);
            $name = trim($_POST['site_name'] ?? '');
            $category_id = (int)($_POST['category'] ?? 0);
            $location = trim($_POST['location'] ?? '');
            $address = trim($_POST['address'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Site name is required.');
            }
            
            if ($category_id <= 0) {
                throw new Exception('Please select a category.');
            }
            
            if ($site_id <= 0) {
                throw new Exception('Invalid site ID.');
            }
            
            // Check if site exists for other sites
            $stmt = $pdo->prepare("SELECT id FROM sites WHERE name = ? AND id != ?");
            $stmt->execute([$name, $site_id]);
            if ($stmt->fetch()) {
                throw new Exception('Site "' . $name . '" already exists.');
            }
            
            $stmt = $pdo->prepare("UPDATE sites SET name = ?, category_id = ?, location = ?, address = ? WHERE id = ?");
            $result = $stmt->execute([$name, $category_id, $location, $address, $site_id]);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'Site updated successfully!';
            } else {
                throw new Exception('Failed to update site. Please try again.');
            }
        } elseif ($action === 'delete_site') {
            $site_id = (int)($_POST['site_id'] ?? 0);
            
            if ($site_id <= 0) {
                throw new Exception('Invalid site ID.');
            }
            
            // Delete site assignments first
            $stmt = $pdo->prepare("DELETE FROM user_sites WHERE site_id = ?");
            $stmt->execute([$site_id]);
            
            $stmt = $pdo->prepare("DELETE FROM sites WHERE id = ?");
            $result = $stmt->execute([$site_id]);
            
            if ($result && $stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Site deleted successfully!';
                $response['deleted_id'] = $site_id;
            } else {
                throw new Exception('Failed to delete site. Site may not exist.');
            }
        } else {
            throw new Exception('Invalid action: ' . $action);
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        error_log("Error in categories.php: " . $e->getMessage());
    }
    
    sendJsonResponse($response);
}

// Get data from database
try {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name");
    $categories = $stmt->fetchAll();
    
    $stmt = $pdo->query("
        SELECT s.*, c.name as category_name 
        FROM sites s 
        JOIN categories c ON s.category_id = c.id 
        WHERE s.status = 'active' 
        ORDER BY s.name
    ");
    $sites = $stmt->fetchAll();
} catch (Exception $e) {
    $categories = [];
    $sites = [];
    error_log("Error fetching data: " . $e->getMessage());
}
?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<h4><i class="bi bi-tags-fill me-2" style="color:#1b5a7a;"></i>Categories & Sites</h4>
<p class="text-muted-small">Manage water categories and sites.</p>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- ===== ADD CATEGORY FORM ===== -->
<div class="form-card" id="addCategoryFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-plus-circle me-2"></i> Add New Category
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm('addCategory')">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="addCategoryForm" onsubmit="return submitCategoryForm('add')">
        <input type="hidden" name="action" value="add_category">
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label">Category Name *</label>
                <input type="text" class="form-control" name="category_name" id="addCatName" required placeholder="Enter category name">
            </div>
            <div class="col-md-12">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" id="addCatDesc" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Category
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm('addCategory')">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ===== EDIT CATEGORY FORM ===== -->
<div class="form-card" id="editCategoryFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-pencil-square me-2"></i> Edit Category
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm('editCategory')">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="editCategoryForm" onsubmit="return submitCategoryForm('edit')">
        <input type="hidden" name="action" value="edit_category">
        <input type="hidden" name="category_id" id="editCatId">
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label">Category Name *</label>
                <input type="text" class="form-control" name="category_name" id="editCatName" required>
            </div>
            <div class="col-md-12">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" id="editCatDesc" rows="2"></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Update Category
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm('editCategory')">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ===== ADD SITE FORM ===== -->
<div class="form-card" id="addSiteFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-plus-circle me-2"></i> Add New Site
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm('addSite')">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="addSiteForm" onsubmit="return submitSiteForm('add')">
        <input type="hidden" name="action" value="add_site">
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label">Site Name *</label>
                <input type="text" class="form-control" name="site_name" id="addSiteName" required placeholder="Enter site name">
            </div>
            <div class="col-md-12">
                <label class="form-label">Category *</label>
                <select class="form-select" name="category" id="addSiteCategory" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Location</label>
                <input type="text" class="form-control" name="location" id="addSiteLocation" placeholder="Enter site location">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="address" id="addSiteAddress" placeholder="Enter full address">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Site
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm('addSite')">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ===== EDIT SITE FORM ===== -->
<div class="form-card" id="editSiteFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-pencil-square me-2"></i> Edit Site
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm('editSite')">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="editSiteForm" onsubmit="return submitSiteForm('edit')">
        <input type="hidden" name="action" value="edit_site">
        <input type="hidden" name="site_id" id="editSiteId">
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label">Site Name *</label>
                <input type="text" class="form-control" name="site_name" id="editSiteName" required>
            </div>
            <div class="col-md-12">
                <label class="form-label">Category *</label>
                <select class="form-select" name="category" id="editSiteCategory" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Location</label>
                <input type="text" class="form-control" name="location" id="editSiteLocation" placeholder="Enter site location">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="address" id="editSiteAddress" placeholder="Enter full address">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Update Site
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm('editSite')">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ===== CATEGORIES & SITES DISPLAY ===== -->
<div class="row g-3">
    <!-- Categories Column -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><i class="bi bi-tags me-2" style="color:#1b5a7a;"></i>Categories</h5>
                <button class="btn btn-primary btn-sm" onclick="showAddCategoryForm()">
                    <i class="bi bi-plus"></i> Add Category
                </button>
            </div>
            <div class="list-group list-group-flush" id="categoriesList">
                <?php if (empty($categories)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                    No categories found. Click "Add Category" to create one.
                </div>
                <?php else: ?>
                <?php foreach ($categories as $category): 
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE category_id = ?");
                    $stmt->execute([$category['id']]);
                    $site_count = $stmt->fetchColumn();
                ?>
                <div class="category-item d-flex justify-content-between align-items-center" id="categoryRow_<?php echo $category['id']; ?>">
                    <div class="d-flex align-items-center gap-3">
                        <div class="category-icon">
                            <i class="bi bi-folder"></i>
                        </div>
                        <div>
                            <span class="fw-semibold"><?php echo htmlspecialchars($category['name']); ?></span>
                            <div class="text-muted-small"><?php echo $site_count; ?> sites</div>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick='showEditCategoryForm(<?php echo json_encode($category, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo addslashes($category['name']); ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Sites Column -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><i class="bi bi-geo-alt me-2" style="color:#1b5a7a;"></i>Sites</h5>
                <button class="btn btn-primary btn-sm" onclick="showAddSiteForm()">
                    <i class="bi bi-plus"></i> Add Site
                </button>
            </div>
            <div class="list-group list-group-flush" id="sitesList">
                <?php if (empty($sites)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                    No sites found. Click "Add Site" to create one.
                </div>
                <?php else: ?>
                <?php foreach ($sites as $site): ?>
                <div class="site-item d-flex justify-content-between align-items-center" id="siteRow_<?php echo $site['id']; ?>">
                    <div class="d-flex align-items-center gap-3">
                        <div class="site-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <div>
                            <span class="fw-semibold"><?php echo htmlspecialchars($site['name']); ?></span>
                            <div class="text-muted-small">
                                <i class="bi bi-tag me-1"></i> <?php echo htmlspecialchars($site['category_name']); ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick='showEditSiteForm(<?php echo json_encode($site, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteSite(<?php echo $site['id']; ?>, '<?php echo addslashes($site['name']); ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// ===== SHOW FORMS =====
function showAddCategoryForm() {
    document.getElementById('addCategoryFormCard').classList.add('show');
    document.getElementById('editCategoryFormCard').classList.remove('show');
    document.getElementById('addSiteFormCard').classList.remove('show');
    document.getElementById('editSiteFormCard').classList.remove('show');
    document.getElementById('addCategoryFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showEditCategoryForm(category) {
    document.getElementById('editCatId').value = category.id;
    document.getElementById('editCatName').value = category.name;
    document.getElementById('editCatDesc').value = category.description || '';
    
    document.getElementById('editCategoryFormCard').classList.add('show');
    document.getElementById('addCategoryFormCard').classList.remove('show');
    document.getElementById('addSiteFormCard').classList.remove('show');
    document.getElementById('editSiteFormCard').classList.remove('show');
    document.getElementById('editCategoryFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showAddSiteForm() {
    document.getElementById('addSiteFormCard').classList.add('show');
    document.getElementById('editSiteFormCard').classList.remove('show');
    document.getElementById('addCategoryFormCard').classList.remove('show');
    document.getElementById('editCategoryFormCard').classList.remove('show');
    document.getElementById('addSiteFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showEditSiteForm(site) {
    document.getElementById('editSiteId').value = site.id;
    document.getElementById('editSiteName').value = site.name;
    document.getElementById('editSiteCategory').value = site.category_id;
    document.getElementById('editSiteLocation').value = site.location || '';
    document.getElementById('editSiteAddress').value = site.address || '';
    
    document.getElementById('editSiteFormCard').classList.add('show');
    document.getElementById('addSiteFormCard').classList.remove('show');
    document.getElementById('addCategoryFormCard').classList.remove('show');
    document.getElementById('editCategoryFormCard').classList.remove('show');
    document.getElementById('editSiteFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ===== CANCEL FORMS =====
function cancelForm(type) {
    if (type === 'addCategory' || type === 'all') {
        document.getElementById('addCategoryFormCard').classList.remove('show');
        document.getElementById('addCategoryForm').reset();
    }
    if (type === 'editCategory' || type === 'all') {
        document.getElementById('editCategoryFormCard').classList.remove('show');
        document.getElementById('editCategoryForm').reset();
    }
    if (type === 'addSite' || type === 'all') {
        document.getElementById('addSiteFormCard').classList.remove('show');
        document.getElementById('addSiteForm').reset();
    }
    if (type === 'editSite' || type === 'all') {
        document.getElementById('editSiteFormCard').classList.remove('show');
        document.getElementById('editSiteForm').reset();
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

// ===== SUBMIT CATEGORY FORM =====
function submitCategoryForm(type) {
    const form = type === 'add' ? document.getElementById('addCategoryForm') : document.getElementById('editCategoryForm');
    const formData = new FormData(form);
    
    // Debug: Log form data
    console.log('Submitting category form:', Object.fromEntries(formData));
    
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
        console.log('Response data:', data);
        document.getElementById('loadingOverlay').classList.remove('show');
        
        if (data.success) {
            showToast('success', data.message);
            // Reload after the toast has been visible for 3 seconds
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

// ===== SUBMIT SITE FORM =====
function submitSiteForm(type) {
    const form = type === 'add' ? document.getElementById('addSiteForm') : document.getElementById('editSiteForm');
    const formData = new FormData(form);
    
    // Debug: Log form data
    console.log('Submitting site form:', Object.fromEntries(formData));
    
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
        console.log('Response data:', data);
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

// ===== DELETE CATEGORY =====
function deleteCategory(categoryId, categoryName) {
    if (confirm(`Are you sure you want to delete category "${categoryName}"? This will also remove all user assignments to this category.`)) {
        document.getElementById('loadingOverlay').classList.add('show');
        
        const formData = new FormData();
        formData.append('action', 'delete_category');
        formData.append('category_id', categoryId);
        
        console.log('Deleting category:', categoryId);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(parseAjaxResponse)
        .then(data => {
            console.log('Response data:', data);
            document.getElementById('loadingOverlay').classList.remove('show');
            
            if (data.success) {
                showToast('success', data.message);
                const row = document.getElementById('categoryRow_' + categoryId);
                if (row) row.remove();
            } else {
                showToast('danger', data.message || 'Failed to delete category.');
            }
        })
        .catch(error => {
            document.getElementById('loadingOverlay').classList.remove('show');
            showToast('danger', 'Error: ' + error.message);
            console.error('Fetch Error:', error);
        });
    }
}

// ===== DELETE SITE =====
function deleteSite(siteId, siteName) {
    if (confirm(`Are you sure you want to delete site "${siteName}"? This will also remove all user assignments to this site.`)) {
        document.getElementById('loadingOverlay').classList.add('show');
        
        const formData = new FormData();
        formData.append('action', 'delete_site');
        formData.append('site_id', siteId);
        
        console.log('Deleting site:', siteId);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(parseAjaxResponse)
        .then(data => {
            console.log('Response data:', data);
            document.getElementById('loadingOverlay').classList.remove('show');
            
            if (data.success) {
                showToast('success', data.message);
                const row = document.getElementById('siteRow_' + siteId);
                if (row) row.remove();
            } else {
                showToast('danger', data.message || 'Failed to delete site.');
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
</script>
