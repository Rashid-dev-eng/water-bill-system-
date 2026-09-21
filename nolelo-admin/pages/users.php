<style>
.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: white;
    background: linear-gradient(135deg, #1b5a7a, #4fc3f7);
}
.status-badge {
    padding: 0.3rem 0.8rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
}
.status-badge.active { background: #d4edda; color: #155724; }
.status-badge.inactive { background: #f8d7da; color: #721c24; }
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
.assignment-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 1.5rem;
    margin-top: 1rem;
}
.assignment-section .section-title {
    font-weight: 600;
    color: #1a3f55;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}
.assignment-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.8rem;
    background: white;
    border-radius: 8px;
    border: 1px solid #e9eff4;
    transition: all 0.3s ease;
    cursor: pointer;
}
.assignment-checkbox:hover {
    border-color: #1b5a7a;
    background: #f0f7fa;
}
.assignment-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.assignment-checkbox label {
    cursor: pointer;
    margin: 0;
    font-size: 0.9rem;
}
</style>

<?php
// check_admin.php - Include this at the top of protected pages
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    if (function_exists('isAjaxRequest') && isAjaxRequest()) {
        sendJsonResponse(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }
    header('Location: login.php');
    exit;
}
// NO OUTPUT BEFORE THIS POINT - THIS IS CRITICAL

// Handle AJAX requests FIRST before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // This is an AJAX request - handle it and exit
    $response = ['success' => false, 'message' => 'Unknown action'];
    $action = $_POST['action'] ?? '';
    $assignedBy = (int)($_SESSION['user_id'] ?? 0);
    
    try {
        global $pdo;
        
        if ($assignedBy <= 0) {
            throw new Exception('Invalid admin session.');
        }
        
        if ($action === 'add_user') {
            $full_name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'worker';
            $pin = trim($_POST['pin'] ?? '1234');
            $nickname = trim($_POST['nickname'] ?? '');
            $categories = array_values(array_filter(array_map('intval', $_POST['categories'] ?? [])));
            $sites = array_values(array_filter(array_map('intval', $_POST['sites'] ?? [])));
            
            if (empty($full_name) || empty($phone) || empty($email)) {
                throw new Exception('Please fill in all required fields.');
            }
            
            // Check if email exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Email already exists.');
            }
            
            // Check if phone exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                throw new Exception('Phone number already exists.');
            }
            
            $password = password_hash($pin, PASSWORD_DEFAULT);
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
            
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users (full_name, nickname, phone, email, password, pin, role, status) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $result = $stmt->execute([$full_name, $nickname, $phone, $email, $password, $pin, $role]);
            
            if ($result) {
                $user_id = $pdo->lastInsertId();
                
                // Assign categories only when specific ones were selected.
                if (!empty($categories)) {
                    foreach ($categories as $cat_id) {
                        $stmt = $pdo->prepare("INSERT INTO user_categories (user_id, category_id, assigned_by) VALUES (?, ?, ?)");
                        $stmt->execute([$user_id, $cat_id, $assignedBy]);
                    }
                }
                
                // Assign sites only when specific ones were selected.
                if (!empty($sites)) {
                    foreach ($sites as $site_id) {
                        $stmt = $pdo->prepare("INSERT INTO user_sites (user_id, site_id, assigned_by) VALUES (?, ?, ?)");
                        $stmt->execute([$user_id, $site_id, $assignedBy]);
                    }
                }
                
                // Get assigned categories and sites names for response
                $stmt = $pdo->prepare("SELECT uc.category_id as id, c.name FROM user_categories uc JOIN categories c ON uc.category_id = c.id WHERE uc.user_id = ?");
                $stmt->execute([$user_id]);
                $assigned_categories = $stmt->fetchAll();
                
                $stmt = $pdo->prepare("SELECT us.site_id as id, s.name FROM user_sites us JOIN sites s ON us.site_id = s.id WHERE us.user_id = ?");
                $stmt->execute([$user_id]);
                $assigned_sites = $stmt->fetchAll();
                
                $response['success'] = true;
                $response['message'] = 'User added successfully!';
                $response['user'] = [
                    'id' => $user_id,
                    'full_name' => $full_name,
                    'phone' => $phone,
                    'email' => $email,
                    'role' => $role,
                    'status' => 'active',
                    'nickname' => $nickname,
                    'categories' => array_column($assigned_categories, 'name'),
                    'sites' => array_column($assigned_sites, 'name'),
                    'category_ids' => array_values(array_map('intval', array_column($assigned_categories, 'id'))),
                    'site_ids' => array_values(array_map('intval', array_column($assigned_sites, 'id')))
                ];
                
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } else {
                throw new Exception('Failed to add user to database.');
            }
        } elseif ($action === 'edit_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'worker';
            $pin = trim($_POST['pin'] ?? '');
            $nickname = trim($_POST['nickname'] ?? '');
            $categories = array_values(array_filter(array_map('intval', $_POST['categories'] ?? [])));
            $sites = array_values(array_filter(array_map('intval', $_POST['sites'] ?? [])));
            
            if (empty($full_name) || empty($phone) || empty($email)) {
                throw new Exception('Please fill in all required fields.');
            }
            
            if ($user_id <= 0) {
                throw new Exception('Invalid user ID.');
            }

            $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                throw new Exception('User not found.');
            }
            if ($targetUser['role'] === 'admin' && strtolower((string)$targetUser['email']) === 'rashidhassanally0203@gmail.com') {
                throw new Exception('The Super Admin account cannot be edited.');
            }
            
            // Check if email exists for other users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Email already exists.');
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Phone number already exists.');
            }
            
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
            
            // Build update query
            if (!empty($pin)) {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, nickname = ?, phone = ?, email = ?, role = ?, pin = ?, password = ? WHERE id = ?");
                $result = $stmt->execute([$full_name, $nickname, $phone, $email, $role, $pin, password_hash($pin, PASSWORD_DEFAULT), $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, nickname = ?, phone = ?, email = ?, role = ? WHERE id = ?");
                $result = $stmt->execute([$full_name, $nickname, $phone, $email, $role, $user_id]);
            }
            
            if ($result) {
                // Delete existing assignments
                $stmt = $pdo->prepare("DELETE FROM user_categories WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                $stmt = $pdo->prepare("DELETE FROM user_sites WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Assign categories only when specific ones were selected.
                if (!empty($categories)) {
                    foreach ($categories as $cat_id) {
                        $stmt = $pdo->prepare("INSERT INTO user_categories (user_id, category_id, assigned_by) VALUES (?, ?, ?)");
                        $stmt->execute([$user_id, $cat_id, $assignedBy]);
                    }
                }
                
                // Assign sites only when specific ones were selected.
                if (!empty($sites)) {
                    foreach ($sites as $site_id) {
                        $stmt = $pdo->prepare("INSERT INTO user_sites (user_id, site_id, assigned_by) VALUES (?, ?, ?)");
                        $stmt->execute([$user_id, $site_id, $assignedBy]);
                    }
                }
                
                $response['success'] = true;
                $response['message'] = 'User updated successfully!';
                
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } else {
                throw new Exception('Failed to update user.');
            }
        } elseif ($action === 'delete_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if ($user_id <= 0) {
                throw new Exception('Invalid user ID.');
            }
            
            if ($user_id == 1) {
                throw new Exception('Cannot delete the Super Admin user.');
            }
            
            $stmt = $pdo->prepare("SELECT id, role, deleted_at FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $targetUser = $stmt->fetch();
            if ($targetUser && $targetUser['role'] === 'admin') {
                throw new Exception('Admin accounts cannot be moved to trash.');
            }
            if (!$targetUser || $targetUser['role'] !== 'worker') {
                throw new Exception('Only worker accounts can be moved to trash.');
            }
            if (!empty($targetUser['deleted_at'])) {
                throw new Exception('That worker is already in the trash.');
            }

            // Soft-delete: preserve credentials, assignments, bills, and reading history.
            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', deleted_at = NOW(), deleted_by = ? WHERE id = ?");
            $result = $stmt->execute([$assignedBy, $user_id]);
            
            if ($result && $stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Worker moved to trash and access was revoked.';
                $response['deleted_id'] = $user_id;
            } else {
                throw new Exception('Failed to delete user.');
            }
        } elseif ($action === 'restore_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id <= 0) {
                throw new Exception('Invalid user ID.');
            }

            $stmt = $pdo->prepare("UPDATE users SET status = 'active', deleted_at = NULL, deleted_by = NULL WHERE id = ? AND role = 'worker' AND deleted_at IS NOT NULL");
            $stmt->execute([$user_id]);
            if ($stmt->rowCount() < 1) {
                throw new Exception('Worker was not found in the trash.');
            }
            $response = ['success' => true, 'message' => 'Worker restored successfully.', 'user_id' => $user_id];
        } elseif ($action === 'permanent_delete_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id <= 0) {
                throw new Exception('Invalid user ID.');
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'worker' AND deleted_at IS NOT NULL LIMIT 1");
            $stmt->execute([$user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Only workers already in the trash can be permanently deleted.');
            }

            // Explicit permanent deletion removes dependent bills first because generated_by
            // is a required foreign key; soft-delete remains the safe default.
            $stmt = $pdo->prepare("DELETE FROM bills WHERE user_id = ? OR generated_by = ?");
            $stmt->execute([$user_id, $user_id]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'worker' AND deleted_at IS NOT NULL");
            $stmt->execute([$user_id]);
            if ($stmt->rowCount() < 1) {
                throw new Exception('Permanent deletion failed.');
            }
            $response = ['success' => true, 'message' => 'Worker permanently deleted.', 'user_id' => $user_id];
        } else {
            throw new Exception('Invalid action: ' . $action);
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    sendJsonResponse($response);
}

// If not AJAX, continue with normal page display
try {
    global $pdo;
    $stmt = $pdo->query("SELECT id, full_name, nickname, phone, email, role, status, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC");
    $usersById = [];
    foreach ($stmt->fetchAll() as $loadedUser) {
        $usersById[(int)$loadedUser['id']] = $loadedUser;
    }
    $users = array_values($usersById);

    $stmt = $pdo->query("SELECT id, full_name, nickname, phone, email, role, status, deleted_at, created_at FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
    $deleted_users = $stmt->fetchAll();
    
    // Get all categories for assignment
    $stmt = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
    $all_categories = $stmt->fetchAll();
    
    // Get all sites for assignment
    $stmt = $pdo->query("SELECT id, name FROM sites WHERE status = 'active' ORDER BY name");
    $all_sites = $stmt->fetchAll();
    
    // Get user assignments for display
    foreach ($users as &$user) {
        $stmt = $pdo->prepare("SELECT uc.category_id as id, c.name FROM user_categories uc JOIN categories c ON uc.category_id = c.id WHERE uc.user_id = ?");
        $stmt->execute([$user['id']]);
        $user['categories'] = $stmt->fetchAll();
        $user['category_ids'] = array_values(array_map('intval', array_column($user['categories'], 'id')));
        
        $stmt = $pdo->prepare("SELECT us.site_id as id, s.name FROM user_sites us JOIN sites s ON us.site_id = s.id WHERE us.user_id = ?");
        $stmt->execute([$user['id']]);
        $user['sites'] = $stmt->fetchAll();
        $user['site_ids'] = array_values(array_map('intval', array_column($user['sites'], 'id')));
    }
    unset($user);
} catch (Exception $e) {
    $users = [];
    $deleted_users = [];
    $all_categories = [];
    $all_sites = [];
}
?>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<h4><i class="bi bi-people-fill me-2" style="color:#1b5a7a;"></i>User Management</h4>
<p class="text-muted-small">Manage users, roles, and permissions.</p>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- Add User Form Card -->
<div class="form-card" id="addUserFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-person-plus me-2"></i> Add New User
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm()">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="addUserForm" onsubmit="return submitForm('add')">
        <input type="hidden" name="action" value="add_user">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Full Name *</label>
                <input type="text" class="form-control" name="full_name" id="addFullName" required placeholder="Enter full name">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nickname</label>
                <input type="text" class="form-control" name="nickname" id="addNickname" placeholder="Optional nickname">
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone Number *</label>
                <input type="tel" class="form-control" name="phone" id="addPhone" required placeholder="+255 712 345 678">
            </div>
            <div class="col-md-6">
                <label class="form-label">Email *</label>
                <input type="email" class="form-control" name="email" id="addEmail" required placeholder="user@nolelo.tz">
            </div>
            <div class="col-md-6">
                <label class="form-label">Role *</label>
                <select class="form-select" name="role" id="addRole" required>
                    <option value="worker">Worker</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Initial PIN *</label>
                <input type="password" class="form-control" name="pin" id="addPin" required placeholder="Enter 4-digit PIN" maxlength="4" pattern="[0-9]{4}">
            </div>
            
            <!-- Category Assignment -->
            <div class="col-12">
                <div class="assignment-section">
                    <div class="section-title">
                        <i class="bi bi-tags me-2"></i> Category Assignment
                        <span class="text-muted-small ms-2">(Select the categories this user can access)</span>
                    </div>
                    <div class="row g-2" id="addCategoryList">
                        <?php foreach ($all_categories as $cat): ?>
                        <div class="col-md-3 col-6">
                            <div class="assignment-checkbox">
                                <input type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" id="add_cat_<?php echo $cat['id']; ?>">
                                <label for="add_cat_<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted-small mt-2">
                        <i class="bi bi-info-circle me-1"></i> 
                        <span id="addCategoryCount">0</span> categories selected
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="selectAllCategories('add')">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllCategories('add')">Deselect All</button>
                    </div>
                </div>
            </div>
            
            <!-- Site Assignment -->
            <div class="col-12">
                <div class="assignment-section">
                    <div class="section-title">
                        <i class="bi bi-geo-alt me-2"></i> Site Assignment
                        <span class="text-muted-small ms-2">(Select the sites this user can access)</span>
                    </div>
                    <div class="row g-2" id="addSiteList">
                        <?php foreach ($all_sites as $site): ?>
                        <div class="col-md-3 col-6">
                            <div class="assignment-checkbox">
                                <input type="checkbox" name="sites[]" value="<?php echo $site['id']; ?>" id="add_site_<?php echo $site['id']; ?>">
                                <label for="add_site_<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['name']); ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted-small mt-2">
                        <i class="bi bi-info-circle me-1"></i> 
                        <span id="addSiteCount">0</span> sites selected
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="selectAllSites('add')">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllSites('add')">Deselect All</button>
                    </div>
                </div>
            </div>
            
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Access Control:</strong> Only the selected categories and sites will be available to this user.
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save User
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm()">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Edit User Form Card -->
<div class="form-card" id="editUserFormCard">
    <div class="d-flex justify-content-between align-items-center">
        <div class="form-title">
            <i class="bi bi-pencil-square me-2"></i> Edit User
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="cancelForm()">
            <i class="bi bi-x-lg"></i> Cancel
        </button>
    </div>
    <form id="editUserForm" onsubmit="return submitForm('edit')">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Full Name *</label>
                <input type="text" class="form-control" name="full_name" id="editFullName" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone Number *</label>
                <input type="tel" class="form-control" name="phone" id="editPhone" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nickname</label>
                <input type="text" class="form-control" name="nickname" id="editNickname">
            </div>
            <div class="col-md-6">
                <label class="form-label">Email *</label>
                <input type="email" class="form-control" name="email" id="editEmail" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Role *</label>
                <select class="form-select" name="role" id="editRole" required>
                    <option value="worker">Worker</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Leave PIN blank to keep current PIN
                </div>
                <label class="form-label">New PIN</label>
                <input type="password" class="form-control" name="pin" id="editPin" placeholder="Enter new PIN to change" maxlength="4" pattern="[0-9]{4}">
            </div>
            
            <!-- Category Assignment (Edit) -->
            <div class="col-12">
                <div class="assignment-section">
                    <div class="section-title">
                        <i class="bi bi-tags me-2"></i> Category Assignment
                        <span class="text-muted-small ms-2">(Select the categories this user can access)</span>
                    </div>
                    <div class="row g-2" id="editCategoryList">
                        <?php foreach ($all_categories as $cat): ?>
                        <div class="col-md-3 col-6">
                            <div class="assignment-checkbox">
                                <input type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" id="edit_cat_<?php echo $cat['id']; ?>">
                                <label for="edit_cat_<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted-small mt-2">
                        <i class="bi bi-info-circle me-1"></i> 
                        <span id="editCategoryCount">0</span> categories selected
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="selectAllCategories('edit')">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllCategories('edit')">Deselect All</button>
                    </div>
                </div>
            </div>
            
            <!-- Site Assignment (Edit) -->
            <div class="col-12">
                <div class="assignment-section">
                    <div class="section-title">
                        <i class="bi bi-geo-alt me-2"></i> Site Assignment
                        <span class="text-muted-small ms-2">(Select the sites this user can access)</span>
                    </div>
                    <div class="row g-2" id="editSiteList">
                        <?php foreach ($all_sites as $site): ?>
                        <div class="col-md-3 col-6">
                            <div class="assignment-checkbox">
                                <input type="checkbox" name="sites[]" value="<?php echo $site['id']; ?>" id="edit_site_<?php echo $site['id']; ?>">
                                <label for="edit_site_<?php echo $site['id']; ?>"><?php echo htmlspecialchars($site['name']); ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted-small mt-2">
                        <i class="bi bi-info-circle me-1"></i> 
                        <span id="editSiteCount">0</span> sites selected
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="selectAllSites('edit')">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllSites('edit')">Deselect All</button>
                    </div>
                </div>
            </div>
            
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Update User
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelForm()">
                    <i class="bi bi-x"></i> Cancel
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="card border-0 shadow-sm p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>All Users</h5>
        <button class="btn btn-primary" onclick="showAddUserForm()">
            <i class="bi bi-person-plus"></i> Add User
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-modern" id="usersTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Categories & Sites</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <?php if (empty($users)): ?>
                <tr id="noUsersRow">
                    <td colspan="6" class="text-center py-4">
                        <div class="text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            No users found. Click "Add User" to create one.
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($users as $user): ?>
                <?php $isProtectedSuperAdmin = $user['role'] === 'admin' && strtolower((string)$user['email']) === 'rashidhassanally0203@gmail.com'; ?>
                <tr id="userRow_<?php echo $user['id']; ?>">
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="text-muted-small">@<?php echo htmlspecialchars($user['nickname'] ?? strtolower(str_replace(' ', '', $user['full_name']))); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><i class="bi bi-phone me-1"></i> <?php echo htmlspecialchars($user['phone']); ?></div>
                        <div class="text-muted-small"><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($user['email']); ?></div>
                    </td>
                    <td>
                        <span class="badge-role <?php echo $user['role']; ?>">
                            <i class="bi bi-<?php echo $user['role'] === 'admin' ? 'shield-lock' : 'person-workspace'; ?> me-1"></i>
                            <?php echo ucfirst($user['role']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($user['categories'])): ?>
                        <div class="text-muted-small">
                            <strong>Categories:</strong> 
                            <?php echo implode(', ', array_column($user['categories'], 'name')); ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['sites'])): ?>
                        <div class="text-muted-small">
                            <strong>Sites:</strong> 
                            <?php echo implode(', ', array_column($user['sites'], 'name')); ?>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($user['categories']) && empty($user['sites'])): ?>
                        <span class="badge-dawasco">No assignment</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $user['status']; ?>">
                            <i class="bi bi-<?php echo $user['status'] === 'active' ? 'check-circle' : 'x-circle'; ?> me-1"></i>
                            <?php echo ucfirst($user['status']); ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if ($isProtectedSuperAdmin): ?>
                        <span class="badge bg-dark"><i class="bi bi-shield-lock me-1"></i>Protected</span>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick='showEditUserForm(<?php echo json_encode($user, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Trash -->
<div class="card border-0 shadow-sm p-3 mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-trash3 me-2 text-danger"></i>Worker Trash</h5>
        <span class="text-muted-small">Deleted workers retain credentials, assignments, bills, and entry history until permanently removed.</span>
    </div>
    <div class="table-responsive">
        <table class="table table-modern" id="trashTable">
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Contact</th>
                    <th>Deleted</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="trashTableBody">
                <?php if (empty($deleted_users)): ?>
                <tr id="emptyTrashRow"><td colspan="4" class="text-center py-4 text-muted">Trash is empty.</td></tr>
                <?php else: ?>
                <?php foreach ($deleted_users as $deletedUser): ?>
                <tr id="trashRow_<?php echo (int)$deletedUser['id']; ?>">
                    <td><strong><?php echo htmlspecialchars($deletedUser['full_name']); ?></strong><div class="text-muted-small">@<?php echo htmlspecialchars($deletedUser['nickname'] ?? ''); ?></div></td>
                    <td><?php echo htmlspecialchars($deletedUser['phone']); ?><div class="text-muted-small"><?php echo htmlspecialchars($deletedUser['email']); ?></div></td>
                    <td><?php echo htmlspecialchars($deletedUser['deleted_at']); ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary me-1" href="index.php?page=entries&filter_worker=<?php echo (int)$deletedUser['id']; ?>"><i class="bi bi-clock-history"></i> History</a>
                        <button class="btn btn-sm btn-outline-success me-1" onclick='restoreUser(<?php echo (int)$deletedUser['id']; ?>, <?php echo json_encode($deletedUser['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                        <button class="btn btn-sm btn-outline-danger" onclick='permanentlyDeleteUser(<?php echo (int)$deletedUser['id']; ?>, <?php echo json_encode($deletedUser['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-trash3"></i> Delete permanently</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Show Add User Form
function showAddUserForm() {
    document.getElementById('addUserForm').reset();
    document.querySelectorAll('#addCategoryList input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('#addSiteList input[type="checkbox"]').forEach(cb => cb.checked = false);
    updateCounts('add');
    document.getElementById('addUserFormCard').classList.add('show');
    document.getElementById('editUserFormCard').classList.remove('show');
    document.getElementById('addUserFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function extractIds(list) {
    if (!Array.isArray(list)) {
        return [];
    }

    return list.map(item => {
        if (item && typeof item === 'object' && 'id' in item) {
            return parseInt(item.id, 10);
        }

        return parseInt(item, 10);
    }).filter(id => Number.isFinite(id) && id > 0);
}

function applyAssignmentSelections(type, categoryIds, siteIds) {
    const prefix = type === 'add' ? 'add' : 'edit';

    document.querySelectorAll('#' + prefix + 'CategoryList input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('#' + prefix + 'SiteList input[type="checkbox"]').forEach(cb => cb.checked = false);

    extractIds(categoryIds).forEach(catId => {
        const cb = document.getElementById(prefix + '_cat_' + catId);
        if (cb) cb.checked = true;
    });

    extractIds(siteIds).forEach(siteId => {
        const cb = document.getElementById(prefix + '_site_' + siteId);
        if (cb) cb.checked = true;
    });

    updateCounts(type);
}

// Show Edit User Form
function showEditUserForm(user) {
    document.getElementById('editUserForm').reset();
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editFullName').value = user.full_name;
    document.getElementById('editPhone').value = user.phone;
    document.getElementById('editNickname').value = user.nickname || '';
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editPin').value = '';

    applyAssignmentSelections('edit', user.category_ids || user.categories, user.site_ids || user.sites);
    
    // We'll load assignments via AJAX
    loadUserAssignments(user.id);
    
    document.getElementById('editUserFormCard').classList.add('show');
    document.getElementById('addUserFormCard').classList.remove('show');
    document.getElementById('editUserFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Load user assignments for edit
function loadUserAssignments(userId) {
    fetch('get_user_assignments.php?user_id=' + encodeURIComponent(userId), {
        credentials: 'same-origin',
        cache: 'no-store'
    })
        .then(parseAjaxResponse)
        .then(data => {
            if (String(document.getElementById('editUserId').value) !== String(userId)) {
                return;
            }

            if (data.success) {
                applyAssignmentSelections('edit', data.categories || [], data.sites || []);
            }
        })
        .catch(error => console.error('Error loading assignments:', error));
}

// Update selection counts
function updateCounts(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    const catCount = document.querySelectorAll('#' + prefix + 'CategoryList input[type="checkbox"]:checked').length;
    const siteCount = document.querySelectorAll('#' + prefix + 'SiteList input[type="checkbox"]:checked').length;
    document.getElementById(prefix + 'CategoryCount').textContent = catCount;
    document.getElementById(prefix + 'SiteCount').textContent = siteCount;
}

// Select all categories
function selectAllCategories(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    document.querySelectorAll('#' + prefix + 'CategoryList input[type="checkbox"]').forEach(cb => cb.checked = true);
    updateCounts(type);
}

// Deselect all categories
function deselectAllCategories(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    document.querySelectorAll('#' + prefix + 'CategoryList input[type="checkbox"]').forEach(cb => cb.checked = false);
    updateCounts(type);
}

// Select all sites
function selectAllSites(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    document.querySelectorAll('#' + prefix + 'SiteList input[type="checkbox"]').forEach(cb => cb.checked = true);
    updateCounts(type);
}

// Deselect all sites
function deselectAllSites(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    document.querySelectorAll('#' + prefix + 'SiteList input[type="checkbox"]').forEach(cb => cb.checked = false);
    updateCounts(type);
}

// Cancel Form
function cancelForm() {
    document.getElementById('addUserFormCard').classList.remove('show');
    document.getElementById('editUserFormCard').classList.remove('show');
    document.getElementById('addUserForm').reset();
    document.getElementById('editUserForm').reset();
    document.querySelectorAll('#addCategoryList input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('#addSiteList input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('#editCategoryList input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('#editSiteList input[type="checkbox"]').forEach(cb => cb.checked = false);
    updateCounts('add');
    updateCounts('edit');
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

// Submit form via AJAX
function submitForm(type) {
    const form = type === 'add' ? document.getElementById('addUserForm') : document.getElementById('editUserForm');
    if (form.dataset.submitting === '1') {
        return false;
    }
    form.dataset.submitting = '1';
    const formData = new FormData(form);
    
    // Show loading
    document.getElementById('loadingOverlay').classList.add('show');
    form.querySelectorAll('button[type="submit"]').forEach(button => button.disabled = true);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseAjaxResponse)
    .then(data => {
        document.getElementById('loadingOverlay').classList.remove('show');
        form.dataset.submitting = '0';
        form.querySelectorAll('button[type="submit"]').forEach(button => button.disabled = false);
        
        if (data.success) {
            showToast('success', data.message);
            
            if (type === 'add' && data.user) {
                // Reload the canonical database list instead of appending a second client-side row.
                setTimeout(() => location.reload(), 300);
                return;

                // Add new row to table
                const tbody = document.getElementById('usersTableBody');
                const noUsersRow = document.getElementById('noUsersRow');
                if (noUsersRow) noUsersRow.remove();
                
                const user = data.user;
                if (document.getElementById('userRow_' + user.id)) {
                    location.reload();
                    return;
                }
                const newRow = document.createElement('tr');
                newRow.id = 'userRow_' + user.id;
                
                let assignmentsHtml = '';
                if (user.categories && user.categories.length > 0) {
                    assignmentsHtml += '<div class="text-muted-small"><strong>Categories:</strong> ' + user.categories.join(', ') + '</div>';
                }
                if (user.sites && user.sites.length > 0) {
                    assignmentsHtml += '<div class="text-muted-small"><strong>Sites:</strong> ' + user.sites.join(', ') + '</div>';
                }
                if ((!user.categories || user.categories.length === 0) && (!user.sites || user.sites.length === 0)) {
                    assignmentsHtml = '<span class="badge-dawasco">No assignment</span>';
                }
                
                newRow.innerHTML = `
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="user-avatar">${user.full_name.charAt(0).toUpperCase()}</div>
                            <div>
                                <div class="fw-semibold">${user.full_name}</div>
                                <div class="text-muted-small">@${user.nickname || user.full_name.toLowerCase().replace(/ /g, '')}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><i class="bi bi-phone me-1"></i> ${user.phone}</div>
                        <div class="text-muted-small"><i class="bi bi-envelope me-1"></i> ${user.email}</div>
                    </td>
                    <td>
                        <span class="badge-role ${user.role}">
                            <i class="bi bi-${user.role === 'admin' ? 'shield-lock' : 'person-workspace'} me-1"></i>
                            ${user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                        </span>
                    </td>
                    <td>${assignmentsHtml}</td>
                    <td>
                        <span class="status-badge active">
                            <i class="bi bi-check-circle me-1"></i> Active
                        </span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick='showEditUserForm(${JSON.stringify(user).replace(/</g, "\\u003c")})'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${user.id}, '${user.full_name}')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(newRow);
                cancelForm();
            } else if (type === 'edit') {
                // Reload page to show updated assignments
                setTimeout(() => location.reload(), 300);
            }
        } else {
            showToast('danger', data.message || 'An error occurred.');
        }
    })
    .catch(error => {
        document.getElementById('loadingOverlay').classList.remove('show');
        form.dataset.submitting = '0';
        form.querySelectorAll('button[type="submit"]').forEach(button => button.disabled = false);
        showToast('danger', 'Error: ' + error.message);
        console.error('Fetch Error:', error);
    });
    
    return false;
}

// Delete user via AJAX
function deleteUser(userId, userName) {
    if (confirm(`Move "${userName}" to trash? Their access will stop immediately, but their credentials and history can be restored.`)) {
        document.getElementById('loadingOverlay').classList.add('show');
        
        const formData = new FormData();
        formData.append('action', 'delete_user');
        formData.append('user_id', userId);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: formData
        })
        .then(parseAjaxResponse)
        .then(data => {
            document.getElementById('loadingOverlay').classList.remove('show');
            
            if (data.success) {
                showToast('success', data.message);
                const row = document.getElementById('userRow_' + userId);
                if (row) row.remove();
                
                const tbody = document.getElementById('usersTableBody');
                if (tbody.children.length === 0) {
                    tbody.innerHTML = `
                        <tr id="noUsersRow">
                            <td colspan="6" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No users found. Click "Add User" to create one.
                                </div>
                            </td>
                        </tr>
                    `;
                }
            } else {
                showToast('danger', data.message || 'Failed to delete user.');
            }
        })
        .catch(error => {
            document.getElementById('loadingOverlay').classList.remove('show');
            showToast('danger', 'Error: ' + error.message);
            console.error('Fetch Error:', error);
        });
    }
}

function submitTrashAction(action, userId, userName) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('user_id', userId);
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData
    })
    .then(parseAjaxResponse)
    .then(data => {
        if (!data.success) {
            throw new Error(data.message || 'Trash action failed.');
        }
        showToast('success', data.message);
        setTimeout(() => location.reload(), 700);
    })
    .catch(error => showToast('danger', 'Error: ' + error.message));
}

function restoreUser(userId, userName) {
    if (confirm(`Restore "${userName}" with all previous credentials and assignments?`)) {
        submitTrashAction('restore_user', userId, userName);
    }
}

function permanentlyDeleteUser(userId, userName) {
    if (confirm(`Permanently delete "${userName}" and all dependent history? This cannot be undone.`)) {
        submitTrashAction('permanent_delete_user', userId, userName);
    }
}

// Show Toast Message
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

// Update counts when checkboxes change
document.addEventListener('change', function(e) {
    if (e.target && e.target.type === 'checkbox') {
        const form = e.target.closest('form');
        if (form) {
            const type = form.id === 'addUserForm' ? 'add' : 'edit';
            updateCounts(type);
        }
    }
});
</script>
