<?php
require_once '../include/db.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Category ID is required']);
    exit;
}

$categoryId = intval($_GET['id']);

try {
    // Get category details - Simple query without GROUP BY to avoid SQL issues
    $stmt = $db->prepare("
        SELECT 
            vc.*
        FROM vehicle_categories vc
        WHERE vc.id = ?
    ");
    
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();

    if (!$category) {
        echo json_encode(['success' => false, 'error' => 'Category not found']);
        exit;
    }

    // Process features
    $category['features'] = $category['features'] ? json_decode($category['features'], true) : [];

    // Get vehicle count separately - More reliable than GROUP BY
    $vehicle_count_stmt = $db->prepare("
        SELECT COUNT(*) as vehicle_count 
        FROM vehicles 
        WHERE category_id = ? AND status = 'available'
    ");
    $vehicle_count_stmt->execute([$categoryId]);
    $vehicle_count_result = $vehicle_count_stmt->fetch();
    $category['vehicle_count'] = $vehicle_count_result['vehicle_count'];

    // Get average vehicle rate separately
    $avg_rate_stmt = $db->prepare("
        SELECT COALESCE(AVG(rate_per_day), 0) as avg_vehicle_rate 
        FROM vehicles 
        WHERE category_id = ? AND status = 'available'
    ");
    $avg_rate_stmt->execute([$categoryId]);
    $avg_rate_result = $avg_rate_stmt->fetch();
    $category['avg_vehicle_rate'] = $avg_rate_result['avg_vehicle_rate'];

    // Get vehicles in this category
    $vehicles_stmt = $db->prepare("
        SELECT 
            id,
            make,
            model,
            year,
            registration_number,
            rate_per_day,
            status,
            images
        FROM vehicles 
        WHERE category_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $vehicles_stmt->execute([$categoryId]);
    $vehicles = $vehicles_stmt->fetchAll();
    
    // Process vehicle images
    foreach ($vehicles as &$vehicle) {
        $vehicle['images'] = $vehicle['images'] ? json_decode($vehicle['images'], true) : [];
    }
    $category['vehicles'] = $vehicles;

    // Get booking statistics for this category
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_bookings,
            COALESCE(AVG(total_cost), 0) as avg_booking_value,
            COALESCE(SUM(total_cost), 0) as total_revenue
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        WHERE v.category_id = ?
        AND b.status = 'completed'
        AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats_stmt->execute([$categoryId]);
    $category['booking_stats'] = $stats_stmt->fetch();

    // Get recent bookings for this category
    $recent_bookings_stmt = $db->prepare("
        SELECT 
            b.booking_reference,
            b.pickup_date,
            b.return_date,
            b.total_cost,
            b.status,
            c.full_name as customer_name,
            v.make,
            v.model
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN customers c ON b.customer_id = c.id
        WHERE v.category_id = ?
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $recent_bookings_stmt->execute([$categoryId]);
    $category['recent_bookings'] = $recent_bookings_stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $category
    ]);

} catch (PDOException $e) {
    error_log("Get category details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch category details: ' . $e->getMessage()
    ]);
}
?>