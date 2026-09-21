<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header('Location: index.php');
    exit;
}

$error = $_SESSION['admin_login_error'] ?? '';
$login_value = $_SESSION['admin_login_input'] ?? '';
unset($_SESSION['admin_login_error'], $_SESSION['admin_login_input']);

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? $_POST['email'] ?? $_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    $_SESSION['admin_login_input'] = $login;

    if (empty($login)) {
        $_SESSION['admin_login_error'] = 'Please enter your email or phone number.';
        header('Location: login.php');
        exit;
    }

    try {
        global $pdo;
        if (!defined('DB_CONNECTED') || !DB_CONNECTED || !$pdo instanceof PDO) {
            $_SESSION['admin_login_error'] = 'Database is unavailable. Please start MySQL and try again.';
            header('Location: login.php');
            exit;
        }
        $login_field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, full_name, email, phone, password, role, status, deleted_at FROM users WHERE $login_field = ? AND deleted_at IS NULL");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check if user is active
            if ($user['status'] !== 'active') {
                $_SESSION['admin_login_error'] = 'Your account is ' . $user['status'] . '. Please contact administrator.';
                header('Location: login.php');
                exit;
            }

            // Check if user is admin
            if ($user['role'] !== 'admin') {
                $_SESSION['admin_login_error'] = 'You do not have admin privileges.';
                header('Location: login.php');
                exit;
            }

            // Login successful
            unset($_SESSION['admin_login_error'], $_SESSION['admin_login_input']);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['is_admin'] = true;
            $_SESSION['login_time'] = time();

            // Update last login
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);

            header('Location: index.php');
            exit;
        }

        $_SESSION['admin_login_error'] = 'Invalid credentials. Please try again.';
        header('Location: login.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['admin_login_error'] = 'Database error: ' . $e->getMessage();
        error_log("Login error: " . $e->getMessage());
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
    <title>NOLELO · Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body {
            background: linear-gradient(135deg, #0b2b3f 0%, #1a5a7a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 24px;
            padding: 2.5rem;
            max-width: 450px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header .logo {
            font-size: 2.5rem;
            font-weight: 700;
            color: #0b2b3f;
        }
        .login-header .logo i {
            color: #4fc3f7;
        }
        .login-header .subtitle {
            color: #7e99ae;
            font-size: 0.9rem;
            margin-top: 0.3rem;
        }
        .form-control {
            border-radius: 12px;
            padding: 0.7rem 1rem;
            border: 2px solid #e9eff4;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #1b5a7a;
            box-shadow: 0 0 0 4px rgba(27, 90, 122, 0.1);
        }
        .form-control.is-invalid {
            border-color: #dc3545;
        }
        .form-label {
            font-weight: 600;
            color: #1a3f55;
            font-size: 0.9rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #1b5a7a, #2a7a9e);
            border: none;
            padding: 0.8rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            color: white;
            width: 100%;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(27, 90, 122, 0.3);
        }
        .btn-login:active {
            transform: translateY(0);
        }
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: #7e99ae;
            font-size: 0.85rem;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 0.8rem 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: <?php echo !empty($error) ? 'block' : 'none'; ?>;
        }
        .input-group-icon {
            position: relative;
        }
        .input-group-icon .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #7e99ae;
            font-size: 1.1rem;
            z-index: 5;
        }
        .input-group-icon .form-control {
            padding-left: 40px;
            padding-right: 45px;
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #7e99ae;
            cursor: pointer;
            padding: 0;
            z-index: 10;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toggle-password:hover {
            color: #1b5a7a;
        }
        .toggle-password:focus {
            outline: none;
        }
        .water-bg {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: linear-gradient(180deg, transparent, rgba(79, 195, 247, 0.1));
            pointer-events: none;
        }
        .water-bg::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 100%;
            background: repeating-linear-gradient(90deg, 
                rgba(79, 195, 247, 0.05) 0px, 
                rgba(79, 195, 247, 0.05) 50px,
                transparent 50px,
                transparent 100px
            );
            animation: wave 10s linear infinite;
        }
        @keyframes wave {
            0% { transform: translateX(0); }
            100% { transform: translateX(100px); }
        }
    </style>
</head>
<body>
    <div class="water-bg"></div>
    
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="bi bi-droplet-half"></i> NOLELO
            </div>
            <div class="subtitle">Clean Water Supply · Admin Login</div>
        </div>
        
        <div class="error-message" id="errorMessage">
            <i class="bi bi-exclamation-circle me-2"></i>
            <span id="errorText"><?php echo $error; ?></span>
        </div>
        
        <form method="POST" action="" id="loginForm">
            <div class="mb-3">
                <label class="form-label">Email or Phone Number</label>
                <div class="input-group-icon">
                    <span class="input-icon"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" name="login" id="loginInput" 
                           placeholder="Enter email or phone number" 
                           value="<?php echo htmlspecialchars($login_value); ?>"
                           autocomplete="username">
                </div>
                <small class="text-muted">Enter your registered email or phone number</small>
            </div>
            
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group-icon">
                    <span class="input-icon"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" name="password" id="passwordInput" 
                           placeholder="Enter your password" autocomplete="current-password">
                    <button type="button" class="toggle-password" id="togglePassword">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
            </button>
        </form>
        
        <div class="login-footer">
            <p>Secure admin access · <i class="bi bi-shield-lock"></i> Encrypted</p>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            const toggleBtn = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('passwordInput');
            
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const icon = toggleBtn.querySelector('i');
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.className = 'bi bi-eye-slash';
                    } else {
                        passwordInput.type = 'password';
                        icon.className = 'bi bi-eye';
                    }
                });
            }
            
            // Auto-show error if exists
            const errorText = document.getElementById('errorText');
            const errorMessage = document.getElementById('errorMessage');
            
            if (errorText && errorText.textContent.trim() !== '') {
                errorMessage.style.display = 'block';
                setTimeout(function() {
                    errorMessage.style.display = 'none';
                }, 3000);
            }
        });
        
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginInput = document.getElementById('loginInput');
            const passwordInput = document.getElementById('passwordInput');
            let hasError = false;
            
            if (loginInput.value.trim() === '') {
                e.preventDefault();
                loginInput.classList.add('is-invalid');
                showError('Please enter your email or phone number.');
                hasError = true;
            }
            
            if (passwordInput.value.trim() === '') {
                e.preventDefault();
                passwordInput.classList.add('is-invalid');
                if (!hasError) {
                    showError('Please enter your password.');
                }
                hasError = true;
            }
            
            if (hasError) {
                return false;
            }
            
            return true;
        });
        
        function showError(message) {
            const errorMessage = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');
            errorText.textContent = message;
            errorMessage.style.display = 'block';
            
            setTimeout(function() {
                errorMessage.style.display = 'none';
            }, 3000);
        }
        
        // Remove invalid class on input
        document.getElementById('loginInput').addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });
        document.getElementById('passwordInput').addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });
    </script>
</body>
</html>
