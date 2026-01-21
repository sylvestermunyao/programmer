<?php
require_once '../include/db.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Vehicle ID is required']);
    exit;
}

$vehicleId = intval($_GET['id']);

try {
    // Get vehicle details with category information
    $stmt = $db->prepare("
        SELECT 
            v.*,
            vc.name as category_name,
            vc.description as category_description,
            (SELECT COUNT(*) FROM bookings WHERE vehicle_id = v.id AND status IN ('confirmed', 'active')) as active_bookings
        FROM vehicles v
        LEFT JOIN vehicle_categories vc ON v.category_id = vc.id
        WHERE v.id = ?
    ");
    
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch();

    if (!$vehicle) {
        echo json_encode(['success' => false, 'error' => 'Vehicle not found']);
        exit;
    }

    // Process images and features
    $vehicle['images'] = $vehicle['images'] ? json_decode($vehicle['images'], true) : [];
    $vehicle['features'] = $vehicle['features'] ? json_decode($vehicle['features'], true) : [];

    // Get maintenance history
    $maintenance_stmt = $db->prepare("
        SELECT * FROM maintenance_records 
        WHERE vehicle_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $maintenance_stmt->execute([$vehicleId]);
    $vehicle['maintenance_history'] = $maintenance_stmt->fetchAll();

    // Get booking history
    $bookings_stmt = $db->prepare("
        SELECT 
            b.booking_reference,
            b.pickup_date,
            b.return_date,
            b.total_cost,
            b.status,
            c.full_name as customer_name
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        WHERE b.vehicle_id = ?
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $bookings_stmt->execute([$vehicleId]);
    $vehicle['booking_history'] = $bookings_stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $vehicle
    ]);

} catch (PDOException $e) {
    error_log("Get vehicle details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch vehicle details: ' . $e->getMessage()
    ]);
}
?>