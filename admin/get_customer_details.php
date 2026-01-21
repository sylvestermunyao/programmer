<?php
require_once '../include/db.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Customer ID is required']);
    exit;
}

$customerId = intval($_GET['id']);

try {
    // Get customer details
    $stmt = $db->prepare("
        SELECT 
            c.*,
            (SELECT COUNT(*) FROM bookings WHERE customer_id = c.id) as total_bookings,
            (SELECT COUNT(*) FROM bookings WHERE customer_id = c.id AND status = 'completed') as completed_bookings,
            (SELECT COUNT(*) FROM bookings WHERE customer_id = c.id AND status IN ('confirmed', 'active')) as active_bookings
        FROM customers c
        WHERE c.id = ?
    ");
    
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        echo json_encode(['success' => false, 'error' => 'Customer not found']);
        exit;
    }

    // Remove sensitive data
    unset($customer['password_hash']);
    unset($customer['verification_token']);

    // Get booking history
    $bookings_stmt = $db->prepare("
        SELECT 
            b.id,
            b.booking_reference,
            b.pickup_date,
            b.return_date,
            b.total_cost,
            b.status,
            v.make,
            v.model,
            v.registration_number
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        WHERE b.customer_id = ?
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $bookings_stmt->execute([$customerId]);
    $customer['booking_history'] = $bookings_stmt->fetchAll();

    // Get payment history
    $payments_stmt = $db->prepare("
        SELECT 
            p.id,
            p.amount,
            p.payment_method,
            p.status,
            p.created_at,
            b.booking_reference
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        WHERE b.customer_id = ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $payments_stmt->execute([$customerId]);
    $customer['payment_history'] = $payments_stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $customer
    ]);

} catch (PDOException $e) {
    error_log("Get customer details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch customer details: ' . $e->getMessage()
    ]);
}
?>