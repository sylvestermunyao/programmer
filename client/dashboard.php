<?php
require_once '../include/db.php';

if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

$page = $_GET['page'] ?? 'overview';

// Initialize variables with default values
$customer = [];
$bookings = [];
$vehicles = [];
$confirmedBookings = [];
$activeRentals = [];
$completedRentals = [];

try {
    // Get customer info
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$_SESSION['customer_id']]);
    $customer = $stmt->fetch();

    // Get customer bookings - fixed query to get all booking data
    $stmt = $db->prepare("
        SELECT b.*, v.make, v.model, v.registration_number, v.color, v.fuel_type, v.transmission,
               vc.name as category_name, vc.description as category_description
        FROM bookings b 
        JOIN vehicles v ON b.vehicle_id = v.id 
        JOIN vehicle_categories vc ON v.category_id = vc.id
        WHERE b.customer_id = ? 
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$_SESSION['customer_id']]);
    $bookings = $stmt->fetchAll();

    // Get available vehicles for booking - only vehicles with status 'available'
    $stmt = $db->prepare("
        SELECT v.*, vc.name as category_name, vc.description as category_description
        FROM vehicles v 
        JOIN vehicle_categories vc ON v.category_id = vc.id
        WHERE v.status = 'available'
        ORDER BY v.rate_per_day ASC
    ");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();

    // Get booking stats - only if we have bookings
    if ($bookings) {
        $confirmedBookings = array_filter($bookings, fn($b) => $b['status'] === 'confirmed');
        $activeRentals = array_filter($bookings, fn($b) => $b['status'] === 'active');
        $completedRentals = array_filter($bookings, fn($b) => $b['status'] === 'completed');
    }

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // Set empty arrays to prevent undefined variable errors
    $bookings = [];
    $vehicles = [];
    $confirmedBookings = [];
    $activeRentals = [];
    $completedRentals = [];
}

// Handle booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_vehicle'])) {
    try {
        $vehicle_id = $_POST['vehicle_id'];
        $pickup_date = $_POST['pickup_date'];
        $return_date = $_POST['return_date'];
        $pickup_location = $_POST['pickup_location'];
        $return_location = $_POST['return_location'];
        $special_requests = $_POST['special_requests'] ?? '';
        
        // Calculate total days and cost
        $total_days = max(1, (strtotime($return_date) - strtotime($pickup_date)) / (60 * 60 * 24));
        
        // Get vehicle details
        $stmt = $db->prepare("SELECT rate_per_day, security_deposit FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $vehicle = $stmt->fetch();
        
        $subtotal = $vehicle['rate_per_day'] * $total_days;
        $total_cost = $subtotal;
        
        // Generate booking reference
        $booking_reference = 'CR' . date('ym') . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        // Create booking
        $stmt = $db->prepare("
            INSERT INTO bookings (
                booking_reference, customer_id, vehicle_id, pickup_date, return_date,
                pickup_location, return_location, total_days, rate_per_day, subtotal,
                total_cost, security_deposit, terms_accepted, status, special_requests
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending', ?)
        ");
        
        $stmt->execute([
            $booking_reference,
            $_SESSION['customer_id'],
            $vehicle_id,
            $pickup_date,
            $return_date,
            $pickup_location,
            $return_location,
            $total_days,
            $vehicle['rate_per_day'],
            $subtotal,
            $total_cost,
            $vehicle['security_deposit'],
            $special_requests
        ]);
        
        // Update vehicle status to reserved
        $stmt = $db->prepare("UPDATE vehicles SET status = 'reserved' WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        
        $booking_success = "Booking created successfully! Reference: " . $booking_reference;
        
        // Refresh bookings and vehicles list
        $stmt = $db->prepare("
            SELECT b.*, v.make, v.model, v.registration_number, v.color, v.fuel_type, v.transmission,
                   vc.name as category_name, vc.description as category_description
            FROM bookings b 
            JOIN vehicles v ON b.vehicle_id = v.id 
            JOIN vehicle_categories vc ON v.category_id = vc.id
            WHERE b.customer_id = ? 
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$_SESSION['customer_id']]);
        $bookings = $stmt->fetchAll();
        
        $stmt = $db->prepare("
            SELECT v.*, vc.name as category_name, vc.description as category_description
            FROM vehicles v 
            JOIN vehicle_categories vc ON v.category_id = vc.id
            WHERE v.status = 'available'
            ORDER BY v.rate_per_day ASC
        ");
        $stmt->execute();
        $vehicles = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Booking error: " . $e->getMessage());
        $booking_error = "Failed to create booking. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Premium Car Rentals</title>
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
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 70px);
        }

        .sidebar {
            width: 280px;
            background: var(--surface-color);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem 0;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .user-avatar svg {
            width: 24px;
            height: 24px;
        }

        .user-details h3 {
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }

        .user-details p {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 0 1rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: var(--text-primary);
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .nav-item:hover {
            background-color: var(--background-color);
            color: var(--primary-color);
        }

        .nav-item.active {
            background-color: var(--primary-color);
            color: white;
        }

        .nav-item svg {
            width: 18px;
            height: 18px;
        }

        .dashboard-main {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }

        .dashboard-header {
            margin-bottom: 2rem;
        }

        .dashboard-header h1 {
            margin-bottom: 0.5rem;
            font-size: 2rem;
        }

        .dashboard-header p {
            color: var(--text-secondary);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .stat-icon svg {
            width: 24px;
            height: 24px;
        }

        .stat-info h3 {
            margin-bottom: 0.25rem;
            font-size: 1.5rem;
        }

        .stat-info p {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .recent-bookings {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .recent-bookings h2 {
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }

        .bookings-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .booking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--background-color);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        .booking-info h4 {
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .booking-info p {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-pending { background-color: #fef3c7; color: #d97706; }
        .status-confirmed { background-color: #d1fae5; color: #065f46; }
        .status-active { background-color: #dbeafe; color: #1e40af; }
        .status-completed { background-color: #dcfce7; color: #166534; }
        .status-cancelled { background-color: #fee2e2; color: #991b1b; }

        .vehicles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
        }

        .vehicle-card {
            background: var(--surface-color);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }

        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .vehicle-image {
            height: 200px;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            position: relative;
        }

        .vehicle-image.no-image {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
        }

        .vehicle-image.no-image svg {
            width: 60px;
            height: 60px;
        }

        .vehicle-info {
            padding: 1.5rem;
        }

        .vehicle-info h3 {
            margin-bottom: 0.5rem;
            font-size: 1.125rem;
        }

        .vehicle-year {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .vehicle-category {
            background-color: var(--primary-light);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .vehicle-features {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .vehicle-features span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .vehicle-features svg {
            width: 16px;
            height: 16px;
        }

        .vehicle-pricing {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .period {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .vehicle-actions {
            display: flex;
            gap: 0.5rem;
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

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background-color: var(--background-color);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .bookings-table {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: var(--background-color);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tr:hover {
            background-color: var(--background-color);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            color: var(--border-color);
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .profile-form {
            background: var(--surface-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background-color: var(--surface-color);
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: var(--surface-color);
            margin: 2% auto;
            padding: 0;
            border-radius: var(--border-radius-lg);
            width: 90%;
            max-width: 700px;
            max-height: 95vh;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0.5rem;
            line-height: 1;
        }

        .close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            flex: 1;
            max-height: calc(95vh - 140px);
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-shrink: 0;
            background: var(--surface-color);
            border-bottom-left-radius: var(--border-radius-lg);
            border-bottom-right-radius: var(--border-radius-lg);
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .vehicle-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--background-color);
            border-radius: var(--border-radius);
        }

        .vehicle-detail-item {
            display: flex;
            flex-direction: column;
        }

        .vehicle-detail-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .vehicle-detail-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Scrollbar styling for modal */
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: var(--background-color);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }

        /* Ensure form is fully scrollable */
        .booking-form-container {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .booking-form-content {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .bookings-table {
                overflow-x: auto;
            }

            table {
                min-width: 600px;
            }

            .vehicles-grid {
                grid-template-columns: 1fr;
            }

            .vehicle-details {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 2% auto;
                max-height: 98vh;
            }

            .modal-body {
                padding: 1.5rem;
                max-height: calc(98vh - 140px);
            }

            .modal-header {
                padding: 1.25rem 1.5rem;
            }

            .modal-footer {
                padding: 1.25rem 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .modal-content {
                width: 98%;
                margin: 1% auto;
                max-height: 99vh;
            }

            .modal-body {
                padding: 1rem;
                max-height: calc(99vh - 140px);
            }

            .modal-header {
                padding: 1rem 1.25rem;
            }

            .modal-footer {
                padding: 1rem 1.25rem;
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <?php require_once '../include/header.php'; ?>
    
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="user-avatar">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </div>
                    <div class="user-details">
                        <h3><?= htmlspecialchars($customer['full_name'] ?? 'Customer') ?></h3>
                        <p><?= htmlspecialchars($customer['email'] ?? '') ?></p>
                    </div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="?page=overview" class="nav-item <?= $page === 'overview' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span>Overview</span>
                </a>
                <a href="?page=bookings" class="nav-item <?= $page === 'bookings' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <span>My Bookings</span>
                </a>
                <a href="?page=book" class="nav-item <?= $page === 'book' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                    </svg>
                    <span>Book Vehicle</span>
                </a>
                <a href="?page=profile" class="nav-item <?= $page === 'profile' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                    </svg>
                    <span>Profile</span>
                </a>
            </nav>
        </aside>
        
        <main class="dashboard-main">
            <?php if ($page === 'overview'): ?>
                <div class="dashboard-header">
                    <h1>Welcome back, <?= htmlspecialchars($customer['full_name'] ?? 'Customer') ?>!</h1>
                    <p>Here's your rental activity overview</p>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: var(--success-color);">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?= count($confirmedBookings) ?></h3>
                            <p>Confirmed Bookings</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: var(--primary-color);">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?= count($activeRentals) ?></h3>
                            <p>Active Rentals</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: var(--secondary-color);">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?= count($completedRentals) ?></h3>
                            <p>Completed Rentals</p>
                        </div>
                    </div>
                </div>
                
                <div class="recent-bookings">
                    <h2>Recent Bookings</h2>
                    <?php if (!empty($bookings)): ?>
                        <div class="bookings-list">
                            <?php foreach (array_slice($bookings, 0, 5) as $booking): ?>
                                <div class="booking-item">
                                    <div class="booking-info">
                                        <h4><?= htmlspecialchars($booking['make']) ?> <?= htmlspecialchars($booking['model']) ?></h4>
                                        <p>Ref: <?= htmlspecialchars($booking['booking_reference']) ?></p>
                                        <p><?= date('M j, Y', strtotime($booking['pickup_date'])) ?> - <?= date('M j, Y', strtotime($booking['return_date'])) ?></p>
                                    </div>
                                    <div class="booking-status">
                                        <span class="status-badge status-<?= $booking['status'] ?>">
                                            <?= ucfirst($booking['status']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                            </svg>
                            <h3>No Bookings Yet</h3>
                            <p>You haven't made any bookings yet. Start by renting a vehicle!</p>
                            <a href="?page=book" class="btn btn-primary" style="margin-top: 1rem;">Book a Vehicle</a>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($page === 'bookings'): ?>
                <div class="dashboard-header">
                    <h1>My Bookings</h1>
                    <p>Manage your vehicle rentals</p>
                </div>
                
                <?php if (!empty($bookings)): ?>
                    <div class="bookings-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Reference</th>
                                    <th>Pickup Date</th>
                                    <th>Return Date</th>
                                    <th>Total Days</th>
                                    <th>Total Cost</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($booking['make']) ?> <?= htmlspecialchars($booking['model']) ?></strong>
                                            <br>
                                            <small style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($booking['category_name']) ?> • 
                                                <?= htmlspecialchars($booking['color']) ?> • 
                                                <?= htmlspecialchars($booking['fuel_type']) ?> • 
                                                <?= htmlspecialchars($booking['transmission']) ?>
                                            </small>
                                            <br>
                                            <small style="color: var(--text-muted);">
                                                Reg: <?= htmlspecialchars($booking['registration_number']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($booking['booking_reference']) ?></strong>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($booking['pickup_date'])) ?>
                                            <?php if (!empty($booking['pickup_time']) && $booking['pickup_time'] != '00:00:00'): ?>
                                                <br><small><?= date('g:i A', strtotime($booking['pickup_time'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($booking['return_date'])) ?>
                                            <?php if (!empty($booking['return_time']) && $booking['return_time'] != '00:00:00'): ?>
                                                <br><small><?= date('g:i A', strtotime($booking['return_time'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $booking['total_days'] ?> days</td>
                                        <td>
                                            <strong>KSh <?= number_format($booking['total_cost'], 2) ?></strong>
                                            <?php if ($booking['security_deposit'] > 0): ?>
                                                <br><small>Deposit: KSh <?= number_format($booking['security_deposit'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $booking['status'] ?>">
                                                <?= ucfirst($booking['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline view-booking" 
                                                    data-booking-id="<?= $booking['id'] ?>"
                                                    data-booking-reference="<?= htmlspecialchars($booking['booking_reference']) ?>"
                                                    data-vehicle="<?= htmlspecialchars($booking['make'] . ' ' . $booking['model']) ?>"
                                                    data-pickup="<?= date('M j, Y', strtotime($booking['pickup_date'])) ?>"
                                                    data-return="<?= date('M j, Y', strtotime($booking['return_date'])) ?>"
                                                    data-status="<?= $booking['status'] ?>"
                                                    data-total-cost="<?= number_format($booking['total_cost'], 2) ?>">
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                        </svg>
                        <h3>No Bookings Yet</h3>
                        <p>You haven't made any bookings yet. Start by renting a vehicle!</p>
                        <a href="?page=book" class="btn btn-primary" style="margin-top: 1rem;">Book a Vehicle</a>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($page === 'book'): ?>
                <div class="dashboard-header">
                    <h1>Book a Vehicle</h1>
                    <p>Choose from our available fleet</p>
                </div>

                <?php if (isset($booking_success)): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($booking_success) ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($booking_error)): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($booking_error) ?>
                    </div>
                <?php endif; ?>
                
                <div class="vehicles-grid">
                    <?php if (!empty($vehicles)): ?>
                        <?php foreach ($vehicles as $vehicle): 
                            // Decode images JSON
                            $images = $vehicle['images'] ? json_decode($vehicle['images'], true) : [];
                            $first_image = !empty($images) ? $images[0] : null;
                        ?>
                            <div class="vehicle-card">
                                <div class="vehicle-image <?= !$first_image ? 'no-image' : '' ?>" 
                                     style="<?= $first_image ? "background-image: url('../uploads/vehicles/{$first_image}')" : '' ?>">
                                    <?php if (!$first_image): ?>
                                        <svg viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="vehicle-info">
                                    <h3><?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?></h3>
                                    <p class="vehicle-year"><?= htmlspecialchars($vehicle['year']) ?> • <?= htmlspecialchars($vehicle['fuel_type']) ?> • <?= htmlspecialchars($vehicle['transmission']) ?></p>
                                    <p class="vehicle-category"><?= htmlspecialchars($vehicle['category_name']) ?></p>
                                    <div class="vehicle-features">
                                        <span>
                                            <svg viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                            </svg>
                                            <?= htmlspecialchars($vehicle['seating_capacity']) ?> seats
                                        </span>
                                        <span>
                                            <svg viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-5.5-2.5l7.51-3.49L17.5 6.5 9.99 9.99 6.5 17.5zm5.5-6.6c.61 0 1.1.49 1.1 1.1s-.49 1.1-1.1 1.1-1.1-.49-1.1-1.1.49-1.1 1.1-1.1z"/>
                                            </svg>
                                            <?= htmlspecialchars($vehicle['color']) ?>
                                        </span>
                                        <span>
                                            <svg viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M3 12v7c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2H5v-1c0-3.87 3.13-7 7-7s7 3.13 7 7v1h-2c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2v-7a9 9 0 00-18 0z"/>
                                            </svg>
                                            <?= htmlspecialchars($vehicle['doors']) ?> doors
                                        </span>
                                    </div>
                                    <div class="vehicle-pricing">
                                        <span class="price">KSh <?= number_format($vehicle['rate_per_day'], 2) ?></span>
                                        <span class="period">/ day</span>
                                    </div>
                                    <div class="vehicle-pricing">
                                        <span class="period">Security Deposit: KSh <?= number_format($vehicle['security_deposit'], 2) ?></span>
                                    </div>
                                    <div class="vehicle-actions">
                                        <button class="btn btn-primary book-vehicle" 
                                                data-vehicle-id="<?= $vehicle['id'] ?>"
                                                data-vehicle-name="<?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?>"
                                                data-vehicle-rate="<?= $vehicle['rate_per_day'] ?>"
                                                data-vehicle-deposit="<?= $vehicle['security_deposit'] ?>">
                                            Book Now
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                            </svg>
                            <h3>No Vehicles Available</h3>
                            <p>All vehicles are currently booked. Please check back later.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($page === 'profile'): ?>
                <div class="dashboard-header">
                    <h1>My Profile</h1>
                    <p>Manage your account information</p>
                </div>
                
                <div class="profile-form">
                    <form id="profileForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" 
                                       value="<?= htmlspecialchars($customer['full_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?= htmlspecialchars($customer['email'] ?? '') ?>" required readonly>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" 
                                       value="<?= htmlspecialchars($customer['date_of_birth'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" rows="3"><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" 
                                       value="<?= htmlspecialchars($customer['city'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" 
                                       value="<?= htmlspecialchars($customer['state'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="zip_code">ZIP Code</label>
                                <input type="text" id="zip_code" name="zip_code" 
                                       value="<?= htmlspecialchars($customer['zip_code'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Booking Modal -->
    <div id="bookingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Book Vehicle</h2>
                <button class="close">&times;</button>
            </div>
            <div class="booking-form-container">
                <div class="booking-form-content">
                    <form id="bookingForm" method="POST">
                        <input type="hidden" name="book_vehicle" value="1">
                        <input type="hidden" id="modalVehicleId" name="vehicle_id">
                        
                        <div class="modal-body">
                            <div class="vehicle-details" id="modalVehicleDetails">
                                <!-- Vehicle details will be populated by JavaScript -->
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="pickup_date">Pickup Date</label>
                                    <input type="date" id="pickup_date" name="pickup_date" required 
                                           min="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="return_date">Return Date</label>
                                    <input type="date" id="return_date" name="return_date" required 
                                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="pickup_location">Pickup Location</label>
                                    <select id="pickup_location" name="pickup_location" required>
                                        <option value="">Select pickup location</option>
                                        <option value="Nairobi CBD">Nairobi CBD</option>
                                        <option value="Jomo Kenyatta Airport">Jomo Kenyatta Airport</option>
                                        <option value="Wilson Airport">Wilson Airport</option>
                                        <option value="Westlands">Westlands</option>
                                        <option value="Karen">Karen</option>
                                        <option value="Other">Other (Specify in notes)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="return_location">Return Location</label>
                                    <select id="return_location" name="return_location" required>
                                        <option value="">Select return location</option>
                                        <option value="Nairobi CBD">Nairobi CBD</option>
                                        <option value="Jomo Kenyatta Airport">Jomo Kenyatta Airport</option>
                                        <option value="Wilson Airport">Wilson Airport</option>
                                        <option value="Westlands">Westlands</option>
                                        <option value="Karen">Karen</option>
                                        <option value="Other">Other (Specify in notes)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="special_requests">Special Requests (Optional)</label>
                                <textarea id="special_requests" name="special_requests" rows="3" 
                                          placeholder="Any special requirements or notes..."></textarea>
                            </div>
                            
                            <div id="bookingSummary" class="vehicle-details">
                                <!-- Booking summary will be populated by JavaScript -->
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" id="cancelBooking">Cancel</button>
                            <button type="submit" class="btn btn-primary">Confirm Booking</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('bookingModal');
            const bookingForm = document.getElementById('bookingForm');
            const closeBtn = document.querySelector('.close');
            const cancelBtn = document.getElementById('cancelBooking');
            const pickupDate = document.getElementById('pickup_date');
            const returnDate = document.getElementById('return_date');
            const bookingSummary = document.getElementById('bookingSummary');

            // Vehicle booking functionality
            const bookButtons = document.querySelectorAll('.book-vehicle');
            bookButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const vehicleId = this.getAttribute('data-vehicle-id');
                    const vehicleName = this.getAttribute('data-vehicle-name');
                    const vehicleRate = this.getAttribute('data-vehicle-rate');
                    const vehicleDeposit = this.getAttribute('data-vehicle-deposit');
                    
                    openBookingModal(vehicleId, vehicleName, vehicleRate, vehicleDeposit);
                });
            });

            // Booking view functionality
            const viewButtons = document.querySelectorAll('.view-booking');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const bookingReference = this.getAttribute('data-booking-reference');
                    const vehicle = this.getAttribute('data-vehicle');
                    const pickup = this.getAttribute('data-pickup');
                    const returnDate = this.getAttribute('data-return');
                    const status = this.getAttribute('data-status');
                    const totalCost = this.getAttribute('data-total-cost');
                    
                    viewBookingDetails(bookingId, bookingReference, vehicle, pickup, returnDate, status, totalCost);
                });
            });

            // Profile form handling
            const profileForm = document.getElementById('profileForm');
            if (profileForm) {
                profileForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    updateProfile(this);
                });
            }

            // Modal functionality
            function openBookingModal(vehicleId, vehicleName, vehicleRate, vehicleDeposit) {
                document.getElementById('modalVehicleId').value = vehicleId;
                
                // Populate vehicle details
                const vehicleDetails = document.getElementById('modalVehicleDetails');
                vehicleDetails.innerHTML = `
                    <div class="vehicle-detail-item">
                        <span class="vehicle-detail-label">Vehicle</span>
                        <span class="vehicle-detail-value">${vehicleName}</span>
                    </div>
                    <div class="vehicle-detail-item">
                        <span class="vehicle-detail-label">Daily Rate</span>
                        <span class="vehicle-detail-value">KSh ${parseFloat(vehicleRate).toLocaleString('en-KE', {minimumFractionDigits: 2})}</span>
                    </div>
                    <div class="vehicle-detail-item">
                        <span class="vehicle-detail-label">Security Deposit</span>
                        <span class="vehicle-detail-value">KSh ${parseFloat(vehicleDeposit).toLocaleString('en-KE', {minimumFractionDigits: 2})}</span>
                    </div>
                `;
                
                // Reset form
                bookingForm.reset();
                updateBookingSummary();
                
                // Show modal
                modal.style.display = 'block';
                
                // Focus on first input field
                setTimeout(() => {
                    pickupDate.focus();
                }, 300);
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            // Close modal events
            closeBtn.addEventListener('click', closeModal);
            cancelBtn.addEventListener('click', closeModal);

            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            // Update booking summary when dates change
            pickupDate.addEventListener('change', updateBookingSummary);
            returnDate.addEventListener('change', updateBookingSummary);

            function updateBookingSummary() {
                if (pickupDate.value && returnDate.value) {
                    const start = new Date(pickupDate.value);
                    const end = new Date(returnDate.value);
                    const timeDiff = end.getTime() - start.getTime();
                    const dayDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                    
                    if (dayDiff > 0) {
                        const vehicleRate = parseFloat(document.querySelector('.book-vehicle[data-vehicle-id="' + document.getElementById('modalVehicleId').value + '"]').getAttribute('data-vehicle-rate'));
                        const subtotal = vehicleRate * dayDiff;
                        const deposit = parseFloat(document.querySelector('.book-vehicle[data-vehicle-id="' + document.getElementById('modalVehicleId').value + '"]').getAttribute('data-vehicle-deposit'));
                        const total = subtotal;
                        
                        bookingSummary.innerHTML = `
                            <div class="vehicle-detail-item">
                                <span class="vehicle-detail-label">Rental Period</span>
                                <span class="vehicle-detail-value">${dayDiff} day(s)</span>
                            </div>
                            <div class="vehicle-detail-item">
                                <span class="vehicle-detail-label">Subtotal</span>
                                <span class="vehicle-detail-value">KSh ${subtotal.toLocaleString('en-KE', {minimumFractionDigits: 2})}</span>
                            </div>
                            <div class="vehicle-detail-item">
                                <span class="vehicle-detail-label">Security Deposit</span>
                                <span class="vehicle-detail-value">KSh ${deposit.toLocaleString('en-KE', {minimumFractionDigits: 2})}</span>
                            </div>
                            <div class="vehicle-detail-item">
                                <span class="vehicle-detail-label">Total Amount</span>
                                <span class="vehicle-detail-value" style="color: var(--primary-color); font-size: 1.1em;">KSh ${total.toLocaleString('en-KE', {minimumFractionDigits: 2})}</span>
                            </div>
                        `;
                        bookingSummary.style.display = 'grid';
                    } else {
                        bookingSummary.innerHTML = '<div class="vehicle-detail-item"><span class="vehicle-detail-value" style="color: var(--error-color);">Return date must be after pickup date</span></div>';
                        bookingSummary.style.display = 'block';
                    }
                } else {
                    bookingSummary.innerHTML = '';
                    bookingSummary.style.display = 'none';
                }
            }

            // Form validation
            bookingForm.addEventListener('submit', function(e) {
                if (!pickupDate.value || !returnDate.value) {
                    e.preventDefault();
                    alert('Please select both pickup and return dates.');
                    return;
                }

                const start = new Date(pickupDate.value);
                const end = new Date(returnDate.value);
                if (end <= start) {
                    e.preventDefault();
                    alert('Return date must be after pickup date.');
                    return;
                }

                if (!document.getElementById('pickup_location').value || !document.getElementById('return_location').value) {
                    e.preventDefault();
                    alert('Please select both pickup and return locations.');
                    return;
                }

                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = 'Processing...';
                submitBtn.disabled = true;
            });

            function viewBookingDetails(bookingId, bookingReference, vehicle, pickup, returnDate, status, totalCost) {
                const statusColors = {
                    'pending': '#d97706',
                    'confirmed': '#065f46',
                    'active': '#1e40af',
                    'completed': '#166534',
                    'cancelled': '#991b1b'
                };
                
                const statusColor = statusColors[status] || '#64748b';
                
                const modal = document.createElement('div');
                modal.className = 'modal';
                modal.style.display = 'block';
                modal.innerHTML = `
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Booking Details</h2>
                            <button class="close" onclick="this.closest('.modal').style.display='none'">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="vehicle-details">
                                <div class="vehicle-detail-item">
                                    <span class="vehicle-detail-label">Booking Reference</span>
                                    <span class="vehicle-detail-value">${bookingReference}</span>
                                </div>
                                <div class="vehicle-detail-item">
                                    <span class="vehicle-detail-label">Vehicle</span>
                                    <span class="vehicle-detail-value">${vehicle}</span>
                                </div>
                                <div class="vehicle-detail-item">
                                    <span class="vehicle-detail-label">Pickup Date</span>
                                    <span class="vehicle-detail-value">${pickup}</span>
                                </div>
                                <div class="vehicle-detail-item">
                                    <span class="vehicle-detail-label">Return Date</span>
                                    <span class="vehicle-detail-value">${returnDate}</span>
                                </div>
                                <div class="vehicle-detail-item">
                                    <span class="vehicle-detail-label">Status</span>
                                    <span class="vehicle-detail-value" style="color: ${statusColor};">${status.charAt(0).toUpperCase() + status.slice(1)}</span>
                                </div>
                                <div class="vehicle-detail-item">
                                    <span class="vehicle-detail-label">Total Cost</span>
                                    <span class="vehicle-detail-value">KSh ${totalCost}</span>
                                </div>
                            </div>
                            <div style="text-align: center; margin-top: 1rem;">
                                <p style="color: var(--text-secondary); font-size: 0.875rem;">
                                    For more detailed information about your booking, please contact our support team.
                                </p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" onclick="this.closest('.modal').style.display='none'">Close</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(modal);
                
                // Close modal when clicking outside
                modal.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                        document.body.removeChild(modal);
                    }
                });
            }

            function updateProfile(form) {
                const formData = new FormData(form);
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                
                // Show loading state
                submitBtn.innerHTML = 'Updating...';
                submitBtn.disabled = true;
                
                // Simulate API call
                setTimeout(() => {
                    alert('Profile updated successfully!');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 1500);
            }

            // Add animation to cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe cards for animation
            document.querySelectorAll('.vehicle-card, .stat-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
        });
    </script>
    
    <?php require_once '../include/footer.php'; ?>
</body>
</html>