<?php
// logout.php - Customer Logout Page
session_start();

// Include database connection if needed for logging logout activity
require_once 'include/db.php';

// Log logout activity if customer is logged in
if (isset($_SESSION['customer_id'])) {
    $customer_id = $_SESSION['customer_id'];
    $logout_time = date('Y-m-d H:i:s');
    
    try {
        // You can log the logout activity if you have an activity log table
        $stmt = $db->prepare("INSERT INTO customer_activity_log (customer_id, activity_type, activity_time) VALUES (?, 'logout', ?)");
        $stmt->execute([$customer_id, $logout_time]);
    } catch (PDOException $e) {
        // Log error but don't show to user
        error_log("Error logging logout activity: " . $e->getMessage());
    }
}

// Destroy all session data
session_unset();
session_destroy();

// Clear any session cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to index.php in car_hire directory
header("Location: index.php");
exit();
?>