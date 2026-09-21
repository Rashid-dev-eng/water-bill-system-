<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['is_worker']) && $_SESSION['is_worker'] === true) {
    header('Location: index.php');
    exit;
}

$error = $_SESSION['worker_login_error'] ?? '';
$login_value = $_SESSION['worker_login_input'] ?? '';
unset($_SESSION['worker_login_error'], $_SESSION['worker_login_input']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    $_SESSION['worker_login_input'] = $login;

    if (empty($login) || empty($pin)) {
        $_SESSION['worker_login_error'] = 'Please enter your email/phone and PIN.';
        header('Location: login.php');
        exit;
    } elseif (strlen($pin) !== 4 || !ctype_digit($pin)) {
        $_SESSION['worker_login_error'] = 'Please enter a valid 4-digit PIN.';
        header('Location: login.php');
        exit;
    }

    try {
        global $pdo;
        if (!defined('DB_CONNECTED') || !DB_CONNECTED || !$pdo instanceof PDO) {
            $_SESSION['worker_login_error'] = 'Database is unavailable. Please start MySQL and try again.';
            header('Location: login.php');
            exit;
        }

        // Check if login is email or phone
        $stmt = $pdo->prepare("SELECT id, full_name, email, phone, pin, role, status, deleted_at FROM users WHERE (email = ? OR phone = ?) AND deleted_at IS NULL");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && $user['pin'] === $pin) {
            if ($user['status'] !== 'active') {
                $_SESSION['worker_login_error'] = 'Your account is ' . $user['status'] . '. Please contact administrator.';
                header('Location: login.php');
                exit;
            }

            if ($user['role'] !== 'worker') {
                $_SESSION['worker_login_error'] = 'You do not have worker privileges.';
                header('Location: login.php');
                exit;
            }

            // Login successful
            unset($_SESSION['worker_login_error'], $_SESSION['worker_login_input']);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['is_worker'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            logWorkerActivity($user['id'], 'login', 'Worker logged in successfully.');

            header('Location: index.php');
            exit;
        }

        $_SESSION['worker_login_error'] = 'Invalid credentials. Please try again.';
        header('Location: login.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['worker_login_error'] = 'Database error: ' . $e->getMessage();
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOLELO · Worker Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body {
            background: linear-gradient(135deg, #0b2b3f 0%, #1a5a7a 50%, #2a7a9e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(79,195,247,0.1) 0%, transparent 70%);
            top: -200px;
            right: -200px;
            border-radius: 50%;
        }
        body::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(79,195,247,0.08) 0%, transparent 70%);
            bottom: -150px;
            left: -150px;
            border-radius: 50%;
        }
        .login-container {
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(10px);
            border-radius: 28px;
            padding: 2.5rem;
            max-width: 440px;
            width: 100%;
            box-shadow: 0 30px 80px rgba(0,0,0,0.3);
            position: relative;
            z-index: 1;
        }
        .login-header { text-align: center; margin-bottom: 2rem; }
        .login-header .logo { font-size: 2.5rem; font-weight: 700; color: #0b2b3f; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .login-header .logo i { color: #4fc3f7; font-size: 2.8rem; }
        .login-header .subtitle { color: #7e99ae; font-size: 0.95rem; margin-top: 0.3rem; }
        .form-control {
            border-radius: 14px;
            padding: 0.8rem 1rem;
            border: 2px solid #e9eff4;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        .form-control:focus { border-color: #1b5a7a; box-shadow: 0 0 0 4px rgba(27,90,122,0.1); }
        .form-control.is-invalid { border-color: #dc3545; }
        .form-label { font-weight: 600; color: #1a3f55; font-size: 0.9rem; margin-bottom: 0.4rem; }
        .btn-login {
            background: linear-gradient(135deg, #1b5a7a, #2a7a9e);
            border: none;
            padding: 0.9rem;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(27,90,122,0.4); }
        .btn-login::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            transition: all 0.5s ease;
        }
        .btn-login:hover::after { left: 100%; }
        .input-group-icon { position: relative; }
        .input-group-icon .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7e99ae;
            font-size: 1.1rem;
            z-index: 5;
        }
        .input-group-icon .form-control {
            padding-left: 44px;
            padding-right: 44px;
        }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #7e99ae;
            cursor: pointer;
            padding: 0;
            z-index: 10;
            font-size: 1.1rem;
        }
        .toggle-password:hover { color: #1b5a7a; }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 0.8rem 1rem;
            border-radius: 14px;
            margin-bottom: 1rem;
            display: <?php echo !empty($error) ? 'block' : 'none'; ?>;
            font-size: 0.9rem;
        }
        .decorative-line {
            height: 3px;
            width: 60px;
            background: linear-gradient(90deg, #4fc3f7, #1b5a7a);
            border-radius: 10px;
            margin: 0.5rem auto 0;
        }
        @media (max-width: 480px) { .login-container { padding: 1.8rem; } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo"><i class="bi bi-droplet-half"></i> NOLELO</div>
            <div class="decorative-line"></div>
            <div class="subtitle mt-2"><i class="bi bi-person-badge"></i> Worker Portal · Secure Access</div>
        </div>
        
        <div class="error-message" id="errorMessage">
            <i class="bi bi-exclamation-circle me-2"></i>
            <span id="errorText"><?php echo $error; ?></span>
        </div>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label"><i class="bi bi-envelope me-1"></i> Email or Phone</label>
                <div class="input-group-icon">
                    <span class="input-icon"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" name="login" placeholder="Enter email or phone" value="<?php echo htmlspecialchars($login_value); ?>" required>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label"><i class="bi bi-lock me-1"></i> PIN (4 digits)</label>
                <div class="input-group-icon">
                    <span class="input-icon"><i class="bi bi-shield-lock"></i></span>
                    <input type="password" class="form-control" name="pin" placeholder="Enter 4-digit PIN" maxlength="4" pattern="[0-9]{4}" required>
                    <button type="button" class="toggle-password" onclick="togglePin()">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
            </button>
        </form>
        
        <div class="text-center mt-3 text-muted small">
            <i class="bi bi-info-circle"></i> Use your assigned email or phone and PIN.
        </div>

        <div class="text-center mt-3 text-muted small">
            <i class="bi bi-shield-check"></i> Encrypted · Secure Worker Access
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const errorMessage = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');

            if (errorMessage && errorText && errorText.textContent.trim() !== '') {
                setTimeout(function() {
                    errorMessage.style.display = 'none';
                }, 3000);
            }
        });

        function togglePin() {
            const pinInput = document.querySelector('input[name="pin"]');
            const toggleBtn = document.querySelector('.toggle-password');
            if (pinInput.type === 'password') {
                pinInput.type = 'text';
                toggleBtn.innerHTML = '<i class="bi bi-eye-slash"></i>';
            } else {
                pinInput.type = 'password';
                toggleBtn.innerHTML = '<i class="bi bi-eye"></i>';
            }
        }
    </script>
</body>
</html>
