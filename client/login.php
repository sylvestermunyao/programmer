<?php
require_once '../include/db.php';

if (isset($_SESSION['customer_id'])) {
    header("Location: /car_hire/client/dashboard.php");
    exit();
}

$error = '';
$success = '';

// Handle login
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'login') {
    $csrf_token = $database->sanitize($_POST['csrf_token'] ?? '');
    
    if ($csrf_token !== $_SESSION['csrf_token']) {
        $error = "Security violation detected.";
    } else {
        $email = $database->sanitize($_POST['email']);
        $password = $_POST['password'];
        
        // Rate limiting check
        if (!checkRateLimit("client_login_" . getClientIP())) {
            $error = "Too many login attempts. Please try again in 15 minutes.";
        } else {
            try {
                $stmt = $db->prepare("
                    SELECT id, full_name, email, password_hash, customer_status, failed_login_attempts, account_locked_until 
                    FROM customers 
                    WHERE email = ? AND customer_status IN ('active', 'pending_verification')
                ");
                $stmt->execute([$email]);
                $customer = $stmt->fetch();
                
                if ($customer) {
                    // Check if account is locked
                    if ($customer['account_locked_until'] && strtotime($customer['account_locked_until']) > time()) {
                        $error = "Account temporarily locked. Please try again later.";
                    } elseif (password_verify($password, $customer['password_hash'])) {
                        // Successful login
                        $_SESSION['customer_id'] = $customer['id'];
                        $_SESSION['customer_name'] = $customer['full_name'];
                        $_SESSION['customer_email'] = $customer['email'];
                        
                        // Reset failed login attempts
                        $updateStmt = $db->prepare("
                            UPDATE customers 
                            SET last_login = NOW(), last_login_ip = ?, failed_login_attempts = 0, account_locked_until = NULL 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([getClientIP(), $customer['id']]);
                        
                        // Log security event
                        $database->logSecurityEvent(
                            'customer', 
                            $customer['id'], 
                            getClientIP(), 
                            'customer_login_success', 
                            'Customer logged in successfully'
                        );
                        
                        header("Location: /car_hire/client/dashboard.php");
                        exit();
                    } else {
                        // Failed login
                        $failedAttempts = $customer['failed_login_attempts'] + 1;
                        $lockUntil = null;
                        
                        if ($failedAttempts >= 5) {
                            $lockUntil = date('Y-m-d H:i:s', time() + 900); // 15 minutes
                        }
                        
                        $updateStmt = $db->prepare("
                            UPDATE customers 
                            SET failed_login_attempts = ?, account_locked_until = ? 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$failedAttempts, $lockUntil, $customer['id']]);
                        
                        $database->logSecurityEvent(
                            'customer', 
                            $customer['id'], 
                            getClientIP(), 
                            'customer_login_failed', 
                            'Failed login attempt',
                            'medium'
                        );
                        
                        $error = "Invalid email or password. Attempts remaining: " . (5 - $failedAttempts);
                    }
                } else {
                    $error = "Invalid email or password.";
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "Login failed. Please try again.";
            }
        }
    }
}

// Handle signup
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'signup') {
    $csrf_token = $database->sanitize($_POST['csrf_token'] ?? '');
    
    if ($csrf_token !== $_SESSION['csrf_token']) {
        $error = "Security violation detected.";
    } else {
        // Collect and sanitize form data
        $full_name = $database->sanitize($_POST['full_name']);
        $email = $database->sanitize($_POST['email']);
        $phone = $database->sanitize($_POST['phone']);
        $national_id = $database->sanitize($_POST['national_id']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $date_of_birth = $database->sanitize($_POST['date_of_birth']);
        $address = $database->sanitize($_POST['address']);
        $city = $database->sanitize($_POST['city']);
        $state = $database->sanitize($_POST['state']);
        $zip_code = $database->sanitize($_POST['zip_code']);
        $driving_license = $database->sanitize($_POST['driving_license']);
        $license_expiry = $database->sanitize($_POST['license_expiry']);
        $marketing_emails = isset($_POST['marketing_emails']) ? 1 : 0;
        $preferred_communication = $database->sanitize($_POST['preferred_communication']);
        
        // Basic validation
        if ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            try {
                // Check if email already exists
                $checkStmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
                $checkStmt->execute([$email]);
                
                if ($checkStmt->fetch()) {
                    $error = "Email address already registered.";
                } else {
                    // Generate verification token
                    $verification_token = bin2hex(random_bytes(32));
                    
                    // Hash password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new customer
                    $insertStmt = $db->prepare("
                        INSERT INTO customers (
                            full_name, email, phone, national_id, password_hash, date_of_birth,
                            address, city, state, zip_code, driving_license, license_expiry,
                            verification_token, marketing_emails, preferred_communication, customer_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification')
                    ");
                    
                    $insertStmt->execute([
                        $full_name, $email, $phone, $national_id, $password_hash, $date_of_birth,
                        $address, $city, $state, $zip_code, $driving_license, $license_expiry,
                        $verification_token, $marketing_emails, $preferred_communication
                    ]);
                    
                    // Log security event
                    $database->logSecurityEvent(
                        'customer', 
                        $db->lastInsertId(), 
                        getClientIP(), 
                        'customer_registration', 
                        'New customer registered'
                    );
                    
                    $success = "Registration successful! Please check your email to verify your account.";
                    $_POST = []; // Clear form
                }
            } catch (PDOException $e) {
                error_log("Registration error: " . $e->getMessage());
                $error = "Registration failed. Please try again.";
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
    <title>Client Login - Premium Car Rentals</title>
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
            flex-direction: column;
        }

        .login-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .login-form, .signup-form {
            background: var(--surface-color);
            padding: 3rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 500px;
            position: relative;
        }

        .signup-form {
            max-width: 600px;
            display: none;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
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

        .alert-success {
            background-color: #f0fdf4;
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-group.required label::after {
            content: " *";
            color: var(--error-color);
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

        .input-group input, .input-group select {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background-color: var(--surface-color);
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .input-group input:focus, .input-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .input-group input.error, .input-group select.error {
            border-color: var(--error-color);
        }

        .input-group input.valid, .input-group select.valid {
            border-color: var(--success-color);
        }

        .validation-message {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            display: none;
        }

        .validation-message.error {
            color: var(--error-color);
            display: block;
        }

        .validation-message.success {
            color: var(--success-color);
            display: block;
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

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input {
            width: auto;
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

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background-color: var(--background-color);
        }

        .btn-block {
            width: 100%;
        }

        .login-actions, .signup-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .login-links, .signup-links {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .login-links a, .signup-links a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .login-links a:hover, .signup-links a:hover {
            text-decoration: underline;
        }

        .login-links p, .signup-links p {
            margin-bottom: 0.5rem;
        }

        .back-home {
            position: absolute;
            top: 1rem;
            left: 1rem;
        }

        .form-toggle {
            text-align: center;
            margin-top: 1rem;
        }

        .form-toggle a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .form-toggle a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body class="login-page">
    <?php require_once '../include/header.php'; ?>
    
    <div class="login-container">
        <!-- Login Form -->
        <div class="login-form" id="loginForm">
            <a href="/car_hire/index.php" class="btn btn-outline back-home">← Back to Home</a>
            
            <div class="login-header">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                <h2>Client Login</h2>
                <p>Access your rental dashboard</p>
            </div>
            
            <?php if ($error && (!isset($_POST['action']) || $_POST['action'] === 'login')): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success && (!isset($_POST['action']) || $_POST['action'] === 'login')): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginFormElement">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                        <input type="email" id="email" name="email" required 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
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
                    <button type="submit" class="btn btn-primary btn-block">Login</button>
                    <a href="/car_hire/admin/admin_login.php" class="btn btn-secondary btn-block">Admin Login</a>
                </div>
            </form>
            
            <div class="login-links">
                <p>Don't have an account? <a href="#" id="showSignup">Register here</a></p>
                <p><a href="/car_hire/client/forgot-password.php">Forgot your password?</a></p>
            </div>
        </div>

        <!-- Signup Form -->
        <div class="signup-form" id="signupForm">
            <a href="/car_hire/index.php" class="btn btn-outline back-home">← Back to Home</a>
            
            <div class="login-header">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                <h2>Create Account</h2>
                <p>Join Premium Car Rentals today</p>
            </div>
            
            <?php if ($error && isset($_POST['action']) && $_POST['action'] === 'signup'): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success && isset($_POST['action']) && $_POST['action'] === 'signup'): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" id="signupFormElement">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="signup">
                
                <div class="form-row">
                    <div class="form-group required">
                        <label for="full_name">Full Name</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                            <input type="text" id="full_name" name="full_name" required 
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                        </div>
                        <div class="validation-message" id="full_name_error"></div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group required">
                        <label for="email_signup">Email Address</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                            </svg>
                            <input type="email" id="email_signup" name="email" required 
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="validation-message" id="email_signup_error"></div>
                    </div>
                    
                    <div class="form-group required">
                        <label for="phone">Phone Number</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                            </svg>
                            <input type="tel" id="phone" name="phone" required 
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" 
                                   placeholder="e.g. 0712345678">
                        </div>
                        <div class="validation-message" id="phone_error"></div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group required">
                        <label for="national_id">National ID</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm-2 16c-2.05 0-3.81-1.24-4.58-3h1.71c.63.9 1.68 1.5 2.87 1.5 1.93 0 3.5-1.57 3.5-3.5S13.93 9.5 12 9.5c-1.35 0-2.52.78-3.1 1.9l1.6 1.6h-4V9l1.3 1.3C8.69 8.92 10.23 8 12 8c2.76 0 5 2.24 5 5s-2.24 5-5 5z"/>
                            </svg>
                            <input type="text" id="national_id" name="national_id" required 
                                   value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>" 
                                   placeholder="e.g. 12345678">
                        </div>
                        <div class="validation-message" id="national_id_error"></div>
                    </div>
                    
                    <div class="form-group required">
                        <label for="date_of_birth">Date of Birth</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/>
                            </svg>
                            <input type="date" id="date_of_birth" name="date_of_birth" required 
                                   value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                        </div>
                        <div class="validation-message" id="date_of_birth_error"></div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group required">
                        <label for="password_signup">Password</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 17c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                            </svg>
                            <input type="password" id="password_signup" name="password" required>
                            <button type="button" class="password-toggle">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="eye-icon-signup">
                                    <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                                </svg>
                            </button>
                        </div>
                        <div class="validation-message" id="password_signup_error"></div>
                    </div>
                    
                    <div class="form-group required">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 17c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                            </svg>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                        <div class="validation-message" id="confirm_password_error"></div>
                    </div>
                </div>
                
                <div class="form-group required">
                    <label for="address">Address</label>
                    <div class="input-group">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                        <input type="text" id="address" name="address" required 
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                    </div>
                    <div class="validation-message" id="address_error"></div>
                </div>
                
                <div class="form-row">
                    <div class="form-group required">
                        <label for="city">City</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M15 11V5l-3-3-3 3v2H3v14h18V11h-6zm-8 8H5v-2h2v2zm0-4H5v-2h2v2zm0-4H5V9h2v2zm6 8h-2v-2h2v2zm0-4h-2v-2h2v2zm0-4h-2V9h2v2zm0-4h-2V5h2v2zm6 12h-2v-2h2v2zm0-4h-2v-2h2v2z"/>
                            </svg>
                            <input type="text" id="city" name="city" required 
                                   value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                        </div>
                        <div class="validation-message" id="city_error"></div>
                    </div>
                    
                    <div class="form-group required">
                        <label for="state">County</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zM7 9c0-2.76 2.24-5 5-5s5 2.24 5 5c0 2.88-2.88 7.19-5 9.88C9.92 16.21 7 11.85 7 9z"/>
                            </svg>
                            <select id="state" name="state" required>
                                <option value="">Select County</option>
                                <option value="Nairobi" <?= (isset($_POST['state']) && $_POST['state'] === 'Nairobi') ? 'selected' : '' ?>>Nairobi</option>
                                <option value="Mombasa" <?= (isset($_POST['state']) && $_POST['state'] === 'Mombasa') ? 'selected' : '' ?>>Mombasa</option>
                                <option value="Kisumu" <?= (isset($_POST['state']) && $_POST['state'] === 'Kisumu') ? 'selected' : '' ?>>Kisumu</option>
                                <option value="Nakuru" <?= (isset($_POST['state']) && $_POST['state'] === 'Nakuru') ? 'selected' : '' ?>>Nakuru</option>
                                <option value="Eldoret" <?= (isset($_POST['state']) && $_POST['state'] === 'Eldoret') ? 'selected' : '' ?>>Eldoret</option>
                                <option value="Thika" <?= (isset($_POST['state']) && $_POST['state'] === 'Thika') ? 'selected' : '' ?>>Thika</option>
                                <option value="Malindi" <?= (isset($_POST['state']) && $_POST['state'] === 'Malindi') ? 'selected' : '' ?>>Malindi</option>
                                <option value="Kitale" <?= (isset($_POST['state']) && $_POST['state'] === 'Kitale') ? 'selected' : '' ?>>Kitale</option>
                                <option value="Garissa" <?= (isset($_POST['state']) && $_POST['state'] === 'Garissa') ? 'selected' : '' ?>>Garissa</option>
                                <option value="Kakamega" <?= (isset($_POST['state']) && $_POST['state'] === 'Kakamega') ? 'selected' : '' ?>>Kakamega</option>
                            </select>
                        </div>
                        <div class="validation-message" id="state_error"></div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group required">
                        <label for="zip_code">Postal Code</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                            </svg>
                            <input type="text" id="zip_code" name="zip_code" required 
                                   value="<?= htmlspecialchars($_POST['zip_code'] ?? '') ?>" 
                                   placeholder="e.g. 00100">
                        </div>
                        <div class="validation-message" id="zip_code_error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="preferred_communication">Preferred Communication</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                            </svg>
                            <select id="preferred_communication" name="preferred_communication">
                                <option value="email" <?= (isset($_POST['preferred_communication']) && $_POST['preferred_communication'] === 'email') ? 'selected' : '' ?>>Email</option>
                                <option value="sms" <?= (isset($_POST['preferred_communication']) && $_POST['preferred_communication'] === 'sms') ? 'selected' : '' ?>>SMS</option>
                                <option value="both" <?= (isset($_POST['preferred_communication']) && $_POST['preferred_communication'] === 'both') ? 'selected' : '' ?>>Both</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="driving_license">Driving License Number</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5H15V3H9v2H6.5c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                            </svg>
                            <input type="text" id="driving_license" name="driving_license" 
                                   value="<?= htmlspecialchars($_POST['driving_license'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="license_expiry">License Expiry Date</label>
                        <div class="input-group">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/>
                            </svg>
                            <input type="date" id="license_expiry" name="license_expiry" 
                                   value="<?= htmlspecialchars($_POST['license_expiry'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="marketing_emails" name="marketing_emails" value="1" 
                               <?= (isset($_POST['marketing_emails']) && $_POST['marketing_emails'] == 1) ? 'checked' : 'checked' ?>>
                        <label for="marketing_emails">I would like to receive marketing emails and promotions</label>
                    </div>
                </div>
                
                <div class="signup-actions">
                    <button type="submit" class="btn btn-primary btn-block">Create Account</button>
                </div>
            </form>
            
            <div class="signup-links">
                <p>Already have an account? <a href="#" id="showLogin">Login here</a></p>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle between login and signup forms
            const showSignup = document.getElementById('showSignup');
            const showLogin = document.getElementById('showLogin');
            const loginForm = document.getElementById('loginForm');
            const signupForm = document.getElementById('signupForm');
            
            if (showSignup && showLogin) {
                showSignup.addEventListener('click', function(e) {
                    e.preventDefault();
                    loginForm.style.display = 'none';
                    signupForm.style.display = 'block';
                });
                
                showLogin.addEventListener('click', function(e) {
                    e.preventDefault();
                    signupForm.style.display = 'none';
                    loginForm.style.display = 'block';
                });
            }
            
            // Password toggle functionality
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
            
            // Signup password toggle
            const passwordToggleSignup = document.querySelectorAll('.password-toggle')[1];
            const passwordInputSignup = document.getElementById('password_signup');
            const eyeIconSignup = document.querySelector('.eye-icon-signup');
            
            if (passwordToggleSignup && passwordInputSignup) {
                passwordToggleSignup.addEventListener('click', function() {
                    if (passwordInputSignup.type === 'password') {
                        passwordInputSignup.type = 'text';
                        eyeIconSignup.innerHTML = '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>';
                    } else {
                        passwordInputSignup.type = 'password';
                        eyeIconSignup.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
                    }
                });
            }

            // Real-time validation for signup form
            const signupFormElement = document.getElementById('signupFormElement');
            
            if (signupFormElement) {
                // Full Name validation
                const fullNameInput = document.getElementById('full_name');
                const fullNameError = document.getElementById('full_name_error');
                
                fullNameInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        fullNameError.textContent = 'Full name is required';
                        fullNameError.className = 'validation-message error';
                    } else if (this.value.trim().length < 2) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        fullNameError.textContent = 'Full name must be at least 2 characters';
                        fullNameError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        fullNameError.textContent = 'Looks good!';
                        fullNameError.className = 'validation-message success';
                    }
                });
                
                // Email validation
                const emailInput = document.getElementById('email_signup');
                const emailError = document.getElementById('email_signup_error');
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                
                emailInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        emailError.textContent = 'Email is required';
                        emailError.className = 'validation-message error';
                    } else if (!emailRegex.test(this.value)) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        emailError.textContent = 'Please enter a valid email address';
                        emailError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        emailError.textContent = 'Looks good!';
                        emailError.className = 'validation-message success';
                    }
                });
                
                // Phone validation (Kenyan format)
                const phoneInput = document.getElementById('phone');
                const phoneError = document.getElementById('phone_error');
                const phoneRegex = /^(07\d{8}|01\d{8}|\+2547\d{8}|\+2541\d{8})$/;
                
                phoneInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        phoneError.textContent = 'Phone number is required';
                        phoneError.className = 'validation-message error';
                    } else if (!phoneRegex.test(this.value.replace(/\s/g, ''))) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        phoneError.textContent = 'Please enter a valid Kenyan phone number (e.g., 0712345678)';
                        phoneError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        phoneError.textContent = 'Looks good!';
                        phoneError.className = 'validation-message success';
                    }
                });
                
                // National ID validation (Kenyan format)
                const nationalIdInput = document.getElementById('national_id');
                const nationalIdError = document.getElementById('national_id_error');
                const nationalIdRegex = /^\d{7,9}$/;
                
                nationalIdInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        nationalIdError.textContent = 'National ID is required';
                        nationalIdError.className = 'validation-message error';
                    } else if (!nationalIdRegex.test(this.value)) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        nationalIdError.textContent = 'Please enter a valid Kenyan National ID (7-9 digits)';
                        nationalIdError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        nationalIdError.textContent = 'Looks good!';
                        nationalIdError.className = 'validation-message success';
                    }
                });
                
                // Date of Birth validation (must be at least 18 years old)
                const dobInput = document.getElementById('date_of_birth');
                const dobError = document.getElementById('date_of_birth_error');
                
                dobInput.addEventListener('blur', function() {
                    if (!this.value) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        dobError.textContent = 'Date of birth is required';
                        dobError.className = 'validation-message error';
                    } else {
                        const today = new Date();
                        const birthDate = new Date(this.value);
                        const age = today.getFullYear() - birthDate.getFullYear();
                        const monthDiff = today.getMonth() - birthDate.getMonth();
                        
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                            age--;
                        }
                        
                        if (age < 18) {
                            this.classList.add('error');
                            this.classList.remove('valid');
                            dobError.textContent = 'You must be at least 18 years old';
                            dobError.className = 'validation-message error';
                        } else {
                            this.classList.remove('error');
                            this.classList.add('valid');
                            dobError.textContent = 'Looks good!';
                            dobError.className = 'validation-message success';
                        }
                    }
                });
                
                // Password validation
                const passwordInputSignup = document.getElementById('password_signup');
                const passwordError = document.getElementById('password_signup_error');
                
                passwordInputSignup.addEventListener('blur', function() {
                    if (!this.value) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        passwordError.textContent = 'Password is required';
                        passwordError.className = 'validation-message error';
                    } else if (this.value.length < 8) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        passwordError.textContent = 'Password must be at least 8 characters';
                        passwordError.className = 'validation-message error';
                    } else if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(this.value)) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        passwordError.textContent = 'Password must contain uppercase, lowercase, and number';
                        passwordError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        passwordError.textContent = 'Looks good!';
                        passwordError.className = 'validation-message success';
                    }
                });
                
                // Confirm Password validation
                const confirmPasswordInput = document.getElementById('confirm_password');
                const confirmPasswordError = document.getElementById('confirm_password_error');
                
                confirmPasswordInput.addEventListener('blur', function() {
                    const password = passwordInputSignup.value;
                    
                    if (!this.value) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        confirmPasswordError.textContent = 'Please confirm your password';
                        confirmPasswordError.className = 'validation-message error';
                    } else if (this.value !== password) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        confirmPasswordError.textContent = 'Passwords do not match';
                        confirmPasswordError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        confirmPasswordError.textContent = 'Passwords match!';
                        confirmPasswordError.className = 'validation-message success';
                    }
                });
                
                // Address validation
                const addressInput = document.getElementById('address');
                const addressError = document.getElementById('address_error');
                
                addressInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        addressError.textContent = 'Address is required';
                        addressError.className = 'validation-message error';
                    } else if (this.value.trim().length < 5) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        addressError.textContent = 'Please enter a valid address';
                        addressError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        addressError.textContent = 'Looks good!';
                        addressError.className = 'validation-message success';
                    }
                });
                
                // City validation
                const cityInput = document.getElementById('city');
                const cityError = document.getElementById('city_error');
                
                cityInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        cityError.textContent = 'City is required';
                        cityError.className = 'validation-message error';
                    } else if (this.value.trim().length < 2) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        cityError.textContent = 'Please enter a valid city';
                        cityError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        cityError.textContent = 'Looks good!';
                        cityError.className = 'validation-message success';
                    }
                });
                
                // County validation
                const stateInput = document.getElementById('state');
                const stateError = document.getElementById('state_error');
                
                stateInput.addEventListener('blur', function() {
                    if (!this.value) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        stateError.textContent = 'County is required';
                        stateError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        stateError.textContent = 'Looks good!';
                        stateError.className = 'validation-message success';
                    }
                });
                
                // Postal Code validation
                const zipCodeInput = document.getElementById('zip_code');
                const zipCodeError = document.getElementById('zip_code_error');
                const zipCodeRegex = /^\d{5}$/;
                
                zipCodeInput.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        zipCodeError.textContent = 'Postal code is required';
                        zipCodeError.className = 'validation-message error';
                    } else if (!zipCodeRegex.test(this.value)) {
                        this.classList.add('error');
                        this.classList.remove('valid');
                        zipCodeError.textContent = 'Please enter a valid 5-digit postal code';
                        zipCodeError.className = 'validation-message error';
                    } else {
                        this.classList.remove('error');
                        this.classList.add('valid');
                        zipCodeError.textContent = 'Looks good!';
                        zipCodeError.className = 'validation-message success';
                    }
                });
            }

            // Form validation for login
            const loginFormElement = document.getElementById('loginFormElement');
            loginFormElement.addEventListener('submit', function(e) {
                const email = document.getElementById('email');
                const password = document.getElementById('password');
                let isValid = true;

                if (!email.value.trim()) {
                    isValid = false;
                    email.style.borderColor = 'var(--error-color)';
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
    
    <?php require_once '../include/footer.php'; ?>
</body>
</html>