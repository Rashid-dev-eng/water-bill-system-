<?php
if (!function_exists('getWorkerData')) {
    require_once __DIR__ . '/../config.php';
}

if (session_status() === PHP_SESSION_NONE) {
    require_once dirname(__DIR__) . '/session_bootstrap.php';
}

if (!isset($_SESSION['user_id'])) {
    if (function_exists('sendJsonResponse')) {
        sendJsonResponse(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }

    echo '<script>window.location.href = "../login.php";</script>';
    exit;
}

$worker = getWorkerData();
$assignments = getWorkerAssignments($_SESSION['user_id']);
$initial = strtoupper(substr($worker['full_name'] ?? 'W', 0, 1));

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    if ($action === 'update_profile') {
        $nickname = trim($_POST['nickname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($email !== '') {
            $existingEmail = dbFetchOne('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $_SESSION['user_id']]);
            if ($existingEmail) {
                $response['message'] = 'This email address is already in use.';
            }
        }

        if (!$response['message']) {
            $existingPhone = $phone !== ''
                ? dbFetchOne('SELECT id FROM users WHERE phone = ? AND id <> ?', [$phone, $_SESSION['user_id']])
                : null;
            if ($existingPhone) {
                $response['message'] = 'This phone number is already in use.';
            }
        }
        
        $result = false;
        if (!$response['message']) {
            $result = dbUpdate('users',
                ['nickname' => $nickname, 'phone' => $phone, 'email' => $email],
                'id = :id AND role = :role AND status = :status AND deleted_at IS NULL',
                ['id' => $_SESSION['user_id'], 'role' => 'worker', 'status' => 'active']
            );
        }
        
        if ($result !== false && !$response['message']) {
            $worker = getWorkerData();
            $response['success'] = true;
            $response['message'] = 'Profile updated successfully.';
            $response['worker'] = $worker;
            logWorkerActivity($_SESSION['user_id'], 'profile_updated', 'Worker updated profile details.', ['fields' => ['nickname', 'phone', 'email']]);
            $_SESSION['full_name'] = $worker['full_name'] ?? $_SESSION['full_name'];
        } elseif (!$response['message']) {
            $response['message'] = 'Failed to update profile.';
        }
    } elseif ($action === 'change_pin') {
        $current_pin = trim($_POST['current_pin'] ?? '');
        $new_pin = trim($_POST['new_pin'] ?? '');
        $confirm_pin = trim($_POST['confirm_pin'] ?? '');
        
        // Verify current PIN
        $user = dbFetchOne("SELECT pin FROM users WHERE id = ?", [$_SESSION['user_id']]);
        
        if ($user['pin'] !== $current_pin) {
            $response['message'] = '❌ Current PIN is incorrect.';
        } elseif ($new_pin !== $confirm_pin) {
            $response['message'] = '❌ PINs do not match.';
        } elseif (strlen($new_pin) !== 4 || !ctype_digit($new_pin)) {
            $response['message'] = '❌ PIN must be 4 digits.';
        } else {
            $result = dbUpdate('users', ['pin' => $new_pin], 'id = :id', ['id' => $_SESSION['user_id']]);
            if ($result !== false) {
                $response['success'] = true;
                $response['message'] = '✅ PIN changed successfully!';
            } else {
                $response['message'] = '❌ Failed to change PIN.';
            }
        }
    }
    
    // Return JSON for AJAX requests
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        sendJsonResponse($response);
    }
    
    $_SESSION['toast'] = $response;
    echo '<script>window.location.href="index.php?page=profile";</script>';
    exit;
}

$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
?>

<style>
.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1b5a7a, #4fc3f7);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    font-weight: 700;
    color: white;
    margin: 0 auto;
    box-shadow: 0 8px 30px rgba(27,90,122,0.3);
}
.profile-card {
    background: white;
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.04);
    text-align: center;
}
.profile-card .name { font-size: 1.3rem; font-weight: 700; margin-top: 1rem; }
.profile-card .username { color: #7e99ae; font-size: 0.9rem; }
.profile-card .role-badge { background: #d4e2ed; color: #15475f; padding: 0.3rem 1rem; border-radius: 50px; font-size: 0.8rem; font-weight: 600; display: inline-block; }
.details-card {
    background: white;
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.04);
}
.details-card .section-title {
    font-weight: 700;
    color: #1b5a7a;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9eff4;
}
.assignment-tag {
    background: #d2e7f5;
    color: #0b2b3f;
    padding: 0.4rem 1rem;
    border-radius: 50px;
    display: inline-block;
    font-weight: 500;
    font-size: 0.85rem;
}
</style>

<?php if ($toast): ?>
<div class="alert alert-<?php echo $toast['success'] ? 'success' : 'danger'; ?> alert-dismissible fade show flash-message">
    <?php echo $toast['message']; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="bi bi-person-circle me-2" style="color:#1b5a7a;"></i>My Profile</h4>
    <span class="text-muted-small"><i class="bi bi-shield-check me-1"></i> Secure</span>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="profile-card">
            <div class="profile-avatar"><?php echo $initial; ?></div>
            <div class="name"><?php echo htmlspecialchars($worker['full_name'] ?? 'Worker'); ?></div>
            <div class="username" id="profileNicknameDisplay">@<?php echo htmlspecialchars($worker['nickname'] ?? strtolower(str_replace(' ', '', $worker['full_name'] ?? ''))); ?></div>
            <div class="mt-2">
                <span class="role-badge"><i class="bi bi-person-workspace me-1"></i> Worker</span>
            </div>
            <div class="mt-3">
                <button class="btn btn-outline-primary btn-sm" onclick="showForcePinChange()">
                    <i class="bi bi-shield-lock"></i> Change PIN
                </button>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="details-card">
            <div class="section-title"><i class="bi bi-pencil-square me-2"></i>Edit Profile</div>
            <form method="POST" id="profileForm">
                <input type="hidden" name="action" value="update_profile">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($worker['full_name'] ?? ''); ?>" disabled style="background:#f8f9fa;">
                        <small class="text-muted"><i class="bi bi-lock me-1"></i> Cannot be changed</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nickname</label>
                        <input type="text" class="form-control" name="nickname" value="<?php echo htmlspecialchars($worker['nickname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-phone me-1"></i> Phone Number</label>
                        <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($worker['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="bi bi-envelope me-1"></i> Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($worker['email'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Update Profile
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Assigned Categories & Sites -->
        <div class="details-card mt-3">
            <div class="section-title"><i class="bi bi-tags me-2"></i>My Assignments</div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($assignments['categories'] as $cat): ?>
                <span class="assignment-tag"><i class="bi bi-tag"></i> <?php echo htmlspecialchars($cat['name']); ?></span>
                <?php endforeach; ?>
                <?php foreach ($assignments['sites'] as $site): ?>
                <span class="assignment-tag"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($site['name']); ?></span>
                <?php endforeach; ?>
                <?php if (empty($assignments['categories']) && empty($assignments['sites'])): ?>
                <span class="text-muted">No assignments yet.</span>
                <?php endif; ?>
            </div>
            <div class="text-muted-small mt-2"><i class="bi bi-info-circle me-1"></i> You have access to these categories and sites</div>
        </div>
    </div>
</div>

<script>
document.getElementById('profileForm')?.addEventListener('submit', function (event) {
    event.preventDefault();
    const form = event.currentTarget;
    fetch('index.php?page=profile', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin',
        body: new FormData(form)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Failed to update profile.');
        const worker = data.worker || {};
        form.querySelector('[name="nickname"]').value = worker.nickname || '';
        form.querySelector('[name="phone"]').value = worker.phone || '';
        form.querySelector('[name="email"]').value = worker.email || '';
        const nicknameDisplay = document.getElementById('profileNicknameDisplay');
        if (nicknameDisplay) {
            nicknameDisplay.textContent = '@' + (worker.nickname || String(worker.full_name || 'Worker').split(/\s+/)[0].toLowerCase());
        }
        if (typeof showToast === 'function') showToast('success', data.message);
        // Re-read the page after the toast so all shared identity areas use fresh DB data.
        window.setTimeout(function () {
            window.location.replace('index.php?page=profile&profile_updated=' + Date.now());
        }, 650);
    })
    .catch(error => {
        if (typeof showToast === 'function') showToast('danger', error.message);
    });
});
</script>
