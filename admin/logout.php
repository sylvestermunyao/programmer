<?php
require_once '../include/db.php';

session_start();

// Log admin logout event
if (isset($_SESSION['admin_id'])) {
    $database->logSecurityEvent(
        'admin', 
        $_SESSION['admin_id'], 
        getClientIP(), 
        'admin_logout', 
        'Admin logged out'
    );
}

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to admin login
header("Location: admin_login.php");
exit();
?>