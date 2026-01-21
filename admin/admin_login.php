<?php
require_once '../include/db.php';

if (isset($_SESSION['admin_id'])) {
    header("Location: /car_hire/admin/admin_dashboard.php");
    exit();
}

$error = '';

if ($_POST) {
    $csrf_token = $database->sanitize($_POST['csrf_token'] ?? '');
    
    if ($csrf_token !== $_SESSION['csrf_token']) {
        $error = "Security violation detected.";
    } else {
        $username = $database->sanitize($_POST['username']);
        $password = $_POST['password'];
        
        // Rate limiting check
        if (!checkRateLimit("admin_login_" . getClientIP())) {
            $error = "Too many login attempts. Please try again in 15 minutes.";
        } else {
            try {
                $stmt = $db->prepare("
                    SELECT id, username, email, password_hash, role, first_name, last_name, is_active,
                           failed_login_attempts, account_locked_until 
                    FROM admins 
                    WHERE username = ? AND is_active = 1
                ");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();
                
                if ($admin) {
                    // Check if account is locked
                    if ($admin['account_locked_until'] && strtotime($admin['account_locked_until']) > time()) {
                        $error = "Account temporarily locked. Please try again later.";
                    } elseif (password_verify($password, $admin['password_hash'])) {
                        // Successful login
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_role'] = $admin['role'];
                        $_SESSION['admin_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                        
                        // Update last login
                        $updateStmt = $db->prepare("
                            UPDATE admins 
                            SET last_login = NOW(), last_login_ip = ?, failed_login_attempts = 0, account_locked_until = NULL 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([getClientIP(), $admin['id']]);
                        
                        // Log security event
                        $database->logSecurityEvent(
                            'admin', 
                            $admin['id'], 
                            getClientIP(), 
                            'admin_login_success', 
                            'Admin logged in successfully'
                        );
                        
                        header("Location: /car_hire/admin/admin_dashboard.php");
                        exit();
                    } else {
                        // Failed login
                        $failedAttempts = $admin['failed_login_attempts'] + 1;
                        $lockUntil = null;
                        
                        if ($failedAttempts >= 5) {
                            $lockUntil = date('Y-m-d H:i:s', time() + 900); // 15 minutes
                        }
                        
                        $updateStmt = $db->prepare("
                            UPDATE admins 
                            SET failed_login_attempts = ?, account_locked_until = ? 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$failedAttempts, $lockUntil, $admin['id']]);
                        
                        $database->logSecurityEvent(
                            'admin', 
                            $admin['id'], 
                            getClientIP(), 
                            'admin_login_failed', 
                            'Failed admin login attempt',
                            'high'
                        );
                        
                        $error = "Invalid username or password. Attempts remaining: " . (5 - $failedAttempts);
                    }
                } else {
                    $error = "Invalid username or password.";
                }
            } catch (PDOException $e) {
                error_log("Admin login error: " . $e->getMessage());
                $error = "Login failed. Please try again.";
            }
        }
    }
}

$csrf_token = generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Premium Car Rentals</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary-color: #64748b;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --background-color: #f8fafc;
            --surface-color: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.6;
            color: var(--text-primary);
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .admin-login-container {
            width: 100%;
            max-width: 450px;
        }

        .admin-login-form {
            background: var(--surface-color);
            padding: 3rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            text-align: center;
            position: relative;
        }

        .back-to-home {
            position: absolute;
            top: 1rem;
            left: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-weight: 500;
            transition: var(--transition);
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
        }

        .back-to-home:hover {
            color: var(--primary-color);
            background-color: var(--background-color);
        }

        .back-to-home svg {
            width: 16px;
            height: 16px;
        }

        .login-header {
            margin-bottom: 2rem;
            margin-top: 1rem;
        }

        .login-header svg {
            width: 64px;
            height: 64px;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .login-header h2 {
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
        }

        .login-header p {
            color: var(--text-secondary);
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid var(--error-color);
            color: var(--error-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-group svg {
            position: absolute;
            left: 1rem;
            color: var(--text-muted);
            z-index: 1;
            width: 20px;
            height: 20px;
        }

        .input-group input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background-color: var(--surface-color);
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle svg {
            position: static;
            width: 18px;
            height: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
            line-height: 1;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background-color: #475569;
            transform: translateY(-1px);
        }

        .btn-block {
            width: 100%;
        }

        .login-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .security-notice {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding: 1rem;
            background-color: #fef3c7;
            border-radius: var(--border-radius);
            color: #92400e;
            font-size: 0.875rem;
            text-align: left;
        }

        .security-notice svg {
            width: 20px;
            height: 20px;
            color: #d97706;
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            .admin-login-form {
                padding: 2rem 1.5rem;
            }
            
            .back-to-home {
                position: static;
                justify-content: center;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body class="admin-login-page">
    <div class="admin-login-container">
        <div class="admin-login-form">
            <a href="/car_hire/index.php" class="back-to-home">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
                </svg>
                Back to Home
            </a>
            
            <div class="login-header">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                </svg>
                <h2>Admin Portal</h2>
                <p>Premium Car Rentals Management System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-group">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                        <input type="text" id="username" name="username" required 
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 17c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="password-toggle">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="eye-icon">
                                <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="login-actions">
                    <button type="submit" class="btn btn-primary btn-block">Login to Admin</button>
                    <a href="/car_hire/client/login.php" class="btn btn-secondary btn-block">Client Login</a>
                </div>
            </form>
            
            <div class="security-notice">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                </svg>
                <p>Secure admin access only. All activities are logged.</p>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordToggle = document.querySelector('.password-toggle');
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.querySelector('.eye-icon');
            
            if (passwordToggle && passwordInput) {
                passwordToggle.addEventListener('click', function() {
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        eyeIcon.innerHTML = '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>';
                    } else {
                        passwordInput.type = 'password';
                        eyeIcon.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
                    }
                });
            }

            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const username = document.getElementById('username');
                const password = document.getElementById('password');
                let isValid = true;

                if (!username.value.trim()) {
                    isValid = false;
                    username.style.borderColor = 'var(--error-color)';
                }

                if (!password.value.trim()) {
                    isValid = false;
                    password.style.borderColor = 'var(--error-color)';
                }

                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });

            // Clear error styles on input
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('input', function() {
                    this.style.borderColor = '';
                });
            });
        });
    </script>
</body>
</html>