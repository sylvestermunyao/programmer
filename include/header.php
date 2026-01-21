<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the base URL for absolute paths
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
$base_url = rtrim($base_url, '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JKUAT CAR RENTAL</title>
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
            background-color: var(--background-color);
        }

        .header {
            background: var(--surface-color);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 70px;
        }

        .nav-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary-color);
            text-decoration: none;
        }

        .nav-logo svg {
            width: 24px;
            height: 24px;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-link {
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 500;
            transition: var(--transition);
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-size: 0.875rem;
        }

        .nav-link:hover {
            color: var(--primary-color);
            background-color: var(--background-color);
        }

        .login-btn, .admin-btn {
            background-color: var(--primary-color);
            color: white !important;
            padding: 0.5rem 1.5rem;
        }

        .login-btn:hover, .admin-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .logout-btn {
            background-color: var(--error-color);
            color: white !important;
        }

        .logout-btn:hover {
            background-color: #dc2626;
        }

        .nav-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
        }

        .nav-toggle span {
            width: 25px;
            height: 3px;
            background-color: var(--text-primary);
            margin: 3px 0;
            transition: var(--transition);
        }

        @media (max-width: 768px) {
            .nav-menu {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                width: 100%;
                background: var(--surface-color);
                flex-direction: column;
                padding: 1rem;
                box-shadow: var(--shadow-md);
            }

            .nav-toggle {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="navbar">
            <div class="nav-container">
                <a href="/car_hire/index.php" class="nav-logo">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                    </svg>
                    <span>JKUAT-OCHMS</span>
                </a>
                
                <div class="nav-menu">
                    <?php if (isset($_SESSION['customer_id'])): ?>
                        <a href="/car_hire/client/dashboard.php" class="nav-link">Dashboard</a>
                        <a href="/car_hire/client/dashboard.php?page=bookings" class="nav-link">My Bookings</a>
                        <a href="/car_hire/client/dashboard.php?page=profile" class="nav-link">Profile</a>
                        <a href="/car_hire/logout.php" class="nav-link logout-btn">Logout</a>
                    <?php elseif (isset($_SESSION['admin_id'])): ?>
                        <a href="/car_hire/admin/admin_dashboard.php" class="nav-link">Dashboard</a>
                        <a href="/car_hire/admin/admin_dashboard.php?page=bookings" class="nav-link">Bookings</a>
                        <a href="/car_hire/admin/admin_dashboard.php?page=vehicles" class="nav-link">Vehicles</a>
                        <a href="/car_hire/admin/admin_dashboard.php?page=customers" class="nav-link">Customers</a>
                        <a href="/car_hire/admin/logout.php" class="nav-link logout-btn">Logout</a>
                    <?php else: ?>
                        <a href="/car_hire/index.php" class="nav-link">Home</a>
                        <a href="/car_hire/index.php#about" class="nav-link">About</a>
                        <a href="/car_hire/index.php#contact" class="nav-link">Contact</a>
                        <a href="/car_hire/client/login.php" class="nav-link login-btn">Client Login</a>
                        <a href="/car_hire/admin/admin_login.php" class="nav-link admin-btn">Admin Login</a>
                    <?php endif; ?>
                </div>
                
                <div class="nav-toggle">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </nav>
    </header>

    <main>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const navToggle = document.querySelector('.nav-toggle');
            const navMenu = document.querySelector('.nav-menu');
            
            if (navToggle && navMenu) {
                navToggle.addEventListener('click', function() {
                    const isVisible = navMenu.style.display === 'flex';
                    navMenu.style.display = isVisible ? 'none' : 'flex';
                });
            }

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.nav-container') && window.innerWidth <= 768) {
                    navMenu.style.display = 'none';
                }
            });
        });
    </script>