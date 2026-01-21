<?php
require_once '../include/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$page = $_GET['page'] ?? 'overview';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_booking'])) {
        $result = createBooking($_POST);
    } elseif (isset($_POST['create_vehicle'])) {
        $result = createVehicle($_POST, $_FILES);
    } elseif (isset($_POST['update_booking_status'])) {
        $result = updateBookingStatus($_POST);
    } elseif (isset($_POST['delete_vehicle'])) {
        $result = deleteVehicle($_POST['vehicle_id']);
    } elseif (isset($_POST['update_vehicle'])) {
        $result = updateVehicle($_POST, $_FILES);
    } elseif (isset($_POST['create_category'])) {
        $result = createCategory($_POST, $_FILES);
    } elseif (isset($_POST['update_category'])) {
        $result = updateCategory($_POST, $_FILES);
    } elseif (isset($_POST['delete_category'])) {
        $result = deleteCategory($_POST['category_id']);
    } elseif (isset($_POST['update_customer_status'])) {
        $result = updateCustomerStatus($_POST);
    } elseif (isset($_POST['update_customer'])) {
        $result = updateCustomer($_POST);
    } elseif (isset($_POST['create_admin'])) {
        $result = createAdmin($_POST);
    } elseif (isset($_POST['update_admin'])) {
        $result = updateAdmin($_POST);
    } elseif (isset($_POST['delete_admin'])) {
        $result = deleteAdmin($_POST['admin_id']);
    } elseif (isset($_POST['update_admin_status'])) {
        $result = updateAdminStatus($_POST);
    }
}

// Get dashboard statistics from database
$stats = [];
$recentBookings = [];
$vehicles = [];
$customers = [];
$vehicleCategories = [];
$admins = [];

try {
    // Get dashboard statistics
    $stats = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM bookings WHERE status = 'confirmed') as confirmed_bookings,
            (SELECT COUNT(*) FROM bookings WHERE status = 'active') as active_rentals,
            (SELECT COUNT(*) FROM vehicles WHERE status = 'available') as available_vehicles,
            (SELECT COUNT(*) FROM customers WHERE customer_status = 'active') as active_customers,
            (SELECT SUM(total_cost) FROM bookings WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE())) as monthly_revenue
    ")->fetch();

    // Get recent bookings
    $recentBookings = $db->query("
        SELECT b.*, c.full_name, v.make, v.model, v.registration_number
        FROM bookings b 
        JOIN customers c ON b.customer_id = c.id 
        JOIN vehicles v ON b.vehicle_id = v.id 
        ORDER BY b.created_at DESC 
        LIMIT 10
    ")->fetchAll();

    // Get vehicles
    $vehicles = $db->query("
        SELECT v.*, vc.name as category_name 
        FROM vehicles v 
        JOIN vehicle_categories vc ON v.category_id = vc.id 
        ORDER BY v.created_at DESC 
        LIMIT 10
    ")->fetchAll();

    // Get customers
    $customers = $db->query("
        SELECT id, full_name, email, phone, customer_status, created_at 
        FROM customers 
        ORDER BY created_at DESC 
        LIMIT 10
    ")->fetchAll();

    // Get vehicle categories for forms
    $vehicleCategories = $db->query("SELECT id, name FROM vehicle_categories WHERE is_active = 1")->fetchAll();

    // Get admins for admin management page
    $admins = $db->query("
        SELECT id, username, email, role, first_name, last_name, 
               last_login, is_active, created_at
        FROM admins 
        ORDER BY created_at DESC
    ")->fetchAll();

} catch (PDOException $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
}

// Function to create booking
function createBooking($data) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO bookings (
                booking_reference, customer_id, vehicle_id, pickup_date, return_date,
                pickup_location, return_location, total_days, rate_per_day, subtotal,
                total_cost, security_deposit, status, terms_accepted
            ) VALUES (
                :reference, :customer_id, :vehicle_id, :pickup_date, :return_date,
                :pickup_location, :return_location, :total_days, :rate_per_day, :subtotal,
                :total_cost, :security_deposit, 'pending', 1
            )
        ");
        
        // Generate unique reference
        $reference = 'CR' . date('ym') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        // Calculate days and costs
        $pickup = new DateTime($data['pickup_date']);
        $return = new DateTime($data['return_date']);
        $total_days = $return->diff($pickup)->days ?: 1;
        
        // Get vehicle rate
        $vehicle_stmt = $db->prepare("SELECT rate_per_day, security_deposit FROM vehicles WHERE id = ?");
        $vehicle_stmt->execute([$data['vehicle_id']]);
        $vehicle = $vehicle_stmt->fetch();
        
        $rate_per_day = $vehicle['rate_per_day'];
        $subtotal = $rate_per_day * $total_days;
        $total_cost = $subtotal;
        
        $stmt->execute([
            ':reference' => $reference,
            ':customer_id' => $data['customer_id'],
            ':vehicle_id' => $data['vehicle_id'],
            ':pickup_date' => $data['pickup_date'],
            ':return_date' => $data['return_date'],
            ':pickup_location' => $data['pickup_location'],
            ':return_location' => $data['return_location'],
            ':total_days' => $total_days,
            ':rate_per_day' => $rate_per_day,
            ':subtotal' => $subtotal,
            ':total_cost' => $total_cost,
            ':security_deposit' => $vehicle['security_deposit']
        ]);
        
        return ['success' => true, 'message' => 'Booking created successfully!'];
        
    } catch (PDOException $e) {
        error_log("Create booking error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create booking: ' . $e->getMessage()];
    }
}

// Function to create vehicle
function createVehicle($data, $files) {
    global $db;
    
    try {
        $images = [];
        
        // Handle image upload
        if (!empty($files['images']['name'][0])) {
            $uploadDir = '../uploads/vehicles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            foreach ($files['images']['tmp_name'] as $key => $tmp_name) {
                if ($files['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = uniqid() . '_' . basename($files['images']['name'][$key]);
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $images[] = $fileName;
                    }
                }
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO vehicles (
                category_id, make, model, year, color, registration_number,
                fuel_type, transmission, seating_capacity, doors,
                rate_per_day, security_deposit, images, features, status
            ) VALUES (
                :category_id, :make, :model, :year, :color, :registration_number,
                :fuel_type, :transmission, :seating_capacity, :doors,
                :rate_per_day, :security_deposit, :images, :features, 'available'
            )
        ");
        
        $features = isset($data['features']) ? json_encode($data['features']) : '[]';
        $images_json = !empty($images) ? json_encode($images) : '[]';
        
        $stmt->execute([
            ':category_id' => $data['category_id'],
            ':make' => $data['make'],
            ':model' => $data['model'],
            ':year' => $data['year'],
            ':color' => $data['color'],
            ':registration_number' => $data['registration_number'],
            ':fuel_type' => $data['fuel_type'],
            ':transmission' => $data['transmission'],
            ':seating_capacity' => $data['seating_capacity'],
            ':doors' => $data['doors'],
            ':rate_per_day' => $data['rate_per_day'],
            ':security_deposit' => $data['security_deposit'],
            ':images' => $images_json,
            ':features' => $features
        ]);
        
        return ['success' => true, 'message' => 'Vehicle added successfully!'];
        
    } catch (PDOException $e) {
        error_log("Create vehicle error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add vehicle: ' . $e->getMessage()];
    }
}

// Function to update vehicle
function updateVehicle($data, $files) {
    global $db;
    
    try {
        // Get current vehicle data
        $stmt = $db->prepare("SELECT images FROM vehicles WHERE id = ?");
        $stmt->execute([$data['vehicle_id']]);
        $currentVehicle = $stmt->fetch();
        
        $currentImages = $currentVehicle['images'] ? json_decode($currentVehicle['images'], true) : [];
        $images = $currentImages;
        
        // Handle image upload
        if (!empty($files['images']['name'][0])) {
            $uploadDir = '../uploads/vehicles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            foreach ($files['images']['tmp_name'] as $key => $tmp_name) {
                if ($files['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = uniqid() . '_' . basename($files['images']['name'][$key]);
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $images[] = $fileName;
                    }
                }
            }
        }
        
        // Handle image removal
        if (isset($data['remove_images'])) {
            foreach ($data['remove_images'] as $imageToRemove) {
                $imagePath = $uploadDir . $imageToRemove;
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $images = array_filter($images, function($img) use ($imageToRemove) {
                    return $img !== $imageToRemove;
                });
            }
            $images = array_values($images); // Reindex array
        }
        
        $stmt = $db->prepare("
            UPDATE vehicles SET
                category_id = :category_id,
                make = :make,
                model = :model,
                year = :year,
                color = :color,
                registration_number = :registration_number,
                fuel_type = :fuel_type,
                transmission = :transmission,
                seating_capacity = :seating_capacity,
                doors = :doors,
                rate_per_day = :rate_per_day,
                security_deposit = :security_deposit,
                images = :images,
                features = :features,
                status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :vehicle_id
        ");
        
        $features = isset($data['features']) ? json_encode($data['features']) : '[]';
        $images_json = !empty($images) ? json_encode($images) : '[]';
        
        $stmt->execute([
            ':category_id' => $data['category_id'],
            ':make' => $data['make'],
            ':model' => $data['model'],
            ':year' => $data['year'],
            ':color' => $data['color'],
            ':registration_number' => $data['registration_number'],
            ':fuel_type' => $data['fuel_type'],
            ':transmission' => $data['transmission'],
            ':seating_capacity' => $data['seating_capacity'],
            ':doors' => $data['doors'],
            ':rate_per_day' => $data['rate_per_day'],
            ':security_deposit' => $data['security_deposit'],
            ':images' => $images_json,
            ':features' => $features,
            ':status' => $data['status'],
            ':vehicle_id' => $data['vehicle_id']
        ]);
        
        return ['success' => true, 'message' => 'Vehicle updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update vehicle error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update vehicle: ' . $e->getMessage()];
    }
}

// Function to update booking status
function updateBookingStatus($data) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            UPDATE bookings 
            SET status = :status, 
                cancellation_reason = :cancellation_reason,
                cancelled_by = 'admin',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :booking_id
        ");
        
        $stmt->execute([
            ':status' => $data['status'],
            ':cancellation_reason' => $data['cancellation_reason'] ?? null,
            ':booking_id' => $data['booking_id']
        ]);
        
        // If confirming booking, update vehicle status
        if ($data['status'] === 'confirmed') {
            $vehicle_stmt = $db->prepare("
                UPDATE vehicles v 
                JOIN bookings b ON v.id = b.vehicle_id 
                SET v.status = 'reserved' 
                WHERE b.id = ?
            ");
            $vehicle_stmt->execute([$data['booking_id']]);
        }
        
        // If completing booking, update vehicle status back to available
        if ($data['status'] === 'completed') {
            $vehicle_stmt = $db->prepare("
                UPDATE vehicles v 
                JOIN bookings b ON v.id = b.vehicle_id 
                SET v.status = 'available' 
                WHERE b.id = ?
            ");
            $vehicle_stmt->execute([$data['booking_id']]);
        }
        
        return ['success' => true, 'message' => 'Booking status updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update booking status error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update booking status: ' . $e->getMessage()];
    }
}

// Function to delete vehicle
function deleteVehicle($vehicleId) {
    global $db;
    
    try {
        // First delete associated images
        $stmt = $db->prepare("SELECT images FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch();
        
        if ($vehicle && $vehicle['images']) {
            $images = json_decode($vehicle['images'], true);
            $uploadDir = '../uploads/vehicles/';
            foreach ($images as $image) {
                $imagePath = $uploadDir . $image;
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
        }
        
        $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        
        return ['success' => true, 'message' => 'Vehicle deleted successfully!'];
        
    } catch (PDOException $e) {
        error_log("Delete vehicle error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete vehicle: ' . $e->getMessage()];
    }
}

// Function to create category
function createCategory($data, $files) {
    global $db;
    
    try {
        $image_path = null;
        
        // Handle image upload
        if (!empty($files['image_path']['name'])) {
            $uploadDir = '../uploads/categories/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            if ($files['image_path']['error'] === UPLOAD_ERR_OK) {
                $fileName = uniqid() . '_' . basename($files['image_path']['name']);
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($files['image_path']['tmp_name'], $filePath)) {
                    $image_path = $fileName;
                }
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO vehicle_categories (
                name, description, base_rate_per_day, rate_per_km, security_deposit,
                minimum_rental_days, maximum_rental_days, insurance_daily_rate,
                features, image_path, is_active
            ) VALUES (
                :name, :description, :base_rate_per_day, :rate_per_km, :security_deposit,
                :minimum_rental_days, :maximum_rental_days, :insurance_daily_rate,
                :features, :image_path, 1
            )
        ");
        
        $features = isset($data['features']) ? json_encode($data['features']) : '[]';
        
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':base_rate_per_day' => $data['base_rate_per_day'],
            ':rate_per_km' => $data['rate_per_km'] ?? 0.00,
            ':security_deposit' => $data['security_deposit'],
            ':minimum_rental_days' => $data['minimum_rental_days'] ?? 1,
            ':maximum_rental_days' => $data['maximum_rental_days'] ?? 30,
            ':insurance_daily_rate' => $data['insurance_daily_rate'] ?? 0.00,
            ':features' => $features,
            ':image_path' => $image_path
        ]);
        
        return ['success' => true, 'message' => 'Category added successfully!'];
        
    } catch (PDOException $e) {
        error_log("Create category error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add category: ' . $e->getMessage()];
    }
}

// Function to update category
function updateCategory($data, $files) {
    global $db;
    
    try {
        // Get current category data
        $stmt = $db->prepare("SELECT image_path FROM vehicle_categories WHERE id = ?");
        $stmt->execute([$data['category_id']]);
        $currentCategory = $stmt->fetch();
        
        $image_path = $currentCategory['image_path'];
        
        // Handle image upload
        if (!empty($files['image_path']['name'])) {
            $uploadDir = '../uploads/categories/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Remove old image if exists
            if ($image_path && file_exists($uploadDir . $image_path)) {
                unlink($uploadDir . $image_path);
            }
            
            if ($files['image_path']['error'] === UPLOAD_ERR_OK) {
                $fileName = uniqid() . '_' . basename($files['image_path']['name']);
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($files['image_path']['tmp_name'], $filePath)) {
                    $image_path = $fileName;
                }
            }
        }
        
        // Handle image removal
        if (isset($data['remove_image']) && $data['remove_image'] == '1') {
            if ($image_path && file_exists($uploadDir . $image_path)) {
                unlink($uploadDir . $image_path);
            }
            $image_path = null;
        }
        
        $stmt = $db->prepare("
            UPDATE vehicle_categories SET
                name = :name,
                description = :description,
                base_rate_per_day = :base_rate_per_day,
                rate_per_km = :rate_per_km,
                security_deposit = :security_deposit,
                minimum_rental_days = :minimum_rental_days,
                maximum_rental_days = :maximum_rental_days,
                insurance_daily_rate = :insurance_daily_rate,
                features = :features,
                image_path = :image_path,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :category_id
        ");
        
        $features = isset($data['features']) ? json_encode($data['features']) : '[]';
        $is_active = isset($data['is_active']) ? 1 : 0;
        
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':base_rate_per_day' => $data['base_rate_per_day'],
            ':rate_per_km' => $data['rate_per_km'] ?? 0.00,
            ':security_deposit' => $data['security_deposit'],
            ':minimum_rental_days' => $data['minimum_rental_days'] ?? 1,
            ':maximum_rental_days' => $data['maximum_rental_days'] ?? 30,
            ':insurance_daily_rate' => $data['insurance_daily_rate'] ?? 0.00,
            ':features' => $features,
            ':image_path' => $image_path,
            ':is_active' => $is_active,
            ':category_id' => $data['category_id']
        ]);
        
        return ['success' => true, 'message' => 'Category updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update category error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update category: ' . $e->getMessage()];
    }
}

// Function to delete category
function deleteCategory($categoryId) {
    global $db;
    
    try {
        // First check if category has vehicles
        $check_stmt = $db->prepare("SELECT COUNT(*) as vehicle_count FROM vehicles WHERE category_id = ?");
        $check_stmt->execute([$categoryId]);
        $result = $check_stmt->fetch();
        
        if ($result['vehicle_count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete category. There are vehicles associated with this category.'];
        }
        
        // Get category image
        $stmt = $db->prepare("SELECT image_path FROM vehicle_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $category = $stmt->fetch();
        
        // Delete image file if exists
        if ($category && $category['image_path']) {
            $imagePath = '../uploads/categories/' . $category['image_path'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        
        $stmt = $db->prepare("DELETE FROM vehicle_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        
        return ['success' => true, 'message' => 'Category deleted successfully!'];
        
    } catch (PDOException $e) {
        error_log("Delete category error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete category: ' . $e->getMessage()];
    }
}

// Function to update customer status
function updateCustomerStatus($data) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            UPDATE customers 
            SET customer_status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :customer_id
        ");
        
        $stmt->execute([
            ':status' => $data['status'],
            ':customer_id' => $data['customer_id']
        ]);
        
        return ['success' => true, 'message' => 'Customer status updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update customer status error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update customer status: ' . $e->getMessage()];
    }
}

// Function to update customer details
function updateCustomer($data) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            UPDATE customers SET
                full_name = :full_name,
                email = :email,
                phone = :phone,
                national_id = :national_id,
                date_of_birth = :date_of_birth,
                address = :address,
                city = :city,
                state = :state,
                zip_code = :zip_code,
                country = :country,
                driving_license = :driving_license,
                license_expiry = :license_expiry,
                email_verified = :email_verified,
                phone_verified = :phone_verified,
                marketing_emails = :marketing_emails,
                preferred_communication = :preferred_communication,
                customer_status = :customer_status,
                trust_score = :trust_score,
                notes = :notes,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :customer_id
        ");
        
        // Handle checkbox values
        $email_verified = isset($data['email_verified']) ? 1 : 0;
        $phone_verified = isset($data['phone_verified']) ? 1 : 0;
        $marketing_emails = isset($data['marketing_emails']) ? 1 : 0;
        
        $stmt->execute([
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':national_id' => $data['national_id'],
            ':date_of_birth' => $data['date_of_birth'],
            ':address' => $data['address'],
            ':city' => $data['city'],
            ':state' => $data['state'],
            ':zip_code' => $data['zip_code'],
            ':country' => $data['country'],
            ':driving_license' => $data['driving_license'],
            ':license_expiry' => $data['license_expiry'],
            ':email_verified' => $email_verified,
            ':phone_verified' => $phone_verified,
            ':marketing_emails' => $marketing_emails,
            ':preferred_communication' => $data['preferred_communication'],
            ':customer_status' => $data['customer_status'],
            ':trust_score' => $data['trust_score'],
            ':notes' => $data['notes'],
            ':customer_id' => $data['customer_id']
        ]);
        
        return ['success' => true, 'message' => 'Customer updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update customer error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update customer: ' . $e->getMessage()];
    }
}

// Function to create admin
function createAdmin($data) {
    global $db;
    
    try {
        // Check if username already exists
        $check_stmt = $db->prepare("SELECT id FROM admins WHERE username = ? OR email = ?");
        $check_stmt->execute([$data['username'], $data['email']]);
        
        if ($check_stmt->fetch()) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        
        // Hash password
        $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            INSERT INTO admins (
                username, email, password_hash, role, 
                first_name, last_name, is_active
            ) VALUES (
                :username, :email, :password_hash, :role,
                :first_name, :last_name, :is_active
            )
        ");
        
        $stmt->execute([
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':password_hash' => $password_hash,
            ':role' => $data['role'],
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':is_active' => isset($data['is_active']) ? 1 : 0
        ]);
        
        return ['success' => true, 'message' => 'Admin created successfully!'];
        
    } catch (PDOException $e) {
        error_log("Create admin error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create admin: ' . $e->getMessage()];
    }
}

// Function to update admin
function updateAdmin($data) {
    global $db;
    
    try {
        // Check if username or email already exists (excluding current admin)
        $check_stmt = $db->prepare("SELECT id FROM admins WHERE (username = ? OR email = ?) AND id != ?");
        $check_stmt->execute([$data['username'], $data['email'], $data['admin_id']]);
        
        if ($check_stmt->fetch()) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        
        // Build update query based on whether password is being changed
        if (!empty($data['password'])) {
            $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $query = "
                UPDATE admins SET
                    username = :username,
                    email = :email,
                    password_hash = :password_hash,
                    role = :role,
                    first_name = :first_name,
                    last_name = :last_name,
                    two_factor_enabled = :two_factor_enabled,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :admin_id
            ";
            $params = [
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':password_hash' => $password_hash,
                ':role' => $data['role'],
                ':first_name' => $data['first_name'],
                ':last_name' => $data['last_name'],
                ':two_factor_enabled' => isset($data['two_factor_enabled']) ? 1 : 0,
                ':is_active' => isset($data['is_active']) ? 1 : 0,
                ':admin_id' => $data['admin_id']
            ];
        } else {
            $query = "
                UPDATE admins SET
                    username = :username,
                    email = :email,
                    role = :role,
                    first_name = :first_name,
                    last_name = :last_name,
                    two_factor_enabled = :two_factor_enabled,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :admin_id
            ";
            $params = [
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':role' => $data['role'],
                ':first_name' => $data['first_name'],
                ':last_name' => $data['last_name'],
                ':two_factor_enabled' => isset($data['two_factor_enabled']) ? 1 : 0,
                ':is_active' => isset($data['is_active']) ? 1 : 0,
                ':admin_id' => $data['admin_id']
            ];
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        return ['success' => true, 'message' => 'Admin updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update admin error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update admin: ' . $e->getMessage()];
    }
}

// Function to delete admin
function deleteAdmin($adminId) {
    global $db;
    
    try {
        // Prevent deleting super_admin if only one exists
        if ($adminId == 1) {
            return ['success' => false, 'message' => 'Cannot delete the primary super admin.'];
        }
        
        // Check if this is the last admin
        $check_stmt = $db->prepare("SELECT COUNT(*) as admin_count FROM admins WHERE is_active = 1 AND id != ?");
        $check_stmt->execute([$adminId]);
        $result = $check_stmt->fetch();
        
        if ($result['admin_count'] == 0) {
            return ['success' => false, 'message' => 'Cannot delete the last active admin.'];
        }
        
        $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        
        return ['success' => true, 'message' => 'Admin deleted successfully!'];
        
    } catch (PDOException $e) {
        error_log("Delete admin error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete admin: ' . $e->getMessage()];
    }
}

// Function to update admin status
function updateAdminStatus($data) {
    global $db;
    
    try {
        // Prevent deactivating the primary super admin
        if ($data['admin_id'] == 1 && $data['is_active'] == 0) {
            return ['success' => false, 'message' => 'Cannot deactivate the primary super admin.'];
        }
        
        $stmt = $db->prepare("
            UPDATE admins 
            SET is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :admin_id
        ");
        
        $stmt->execute([
            ':is_active' => $data['is_active'],
            ':admin_id' => $data['admin_id']
        ]);
        
        return ['success' => true, 'message' => 'Admin status updated successfully!'];
        
    } catch (PDOException $e) {
        error_log("Update admin status error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update admin status: ' . $e->getMessage()];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Premium Car Rentals</title>
    <style>
        /* All your existing CSS styles remain exactly the same */
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

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
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

        .admin-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .admin-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .admin-avatar svg {
            width: 24px;
            height: 24px;
        }

        .admin-details h3 {
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }

        .admin-details p {
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

        .admin-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .admin-header {
            background: var(--surface-color);
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-header h1 {
            font-size: 1.5rem;
            margin: 0;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .welcome-msg {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.875rem;
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

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--error-color);
            color: white;
        }

        .admin-content {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
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

        .dashboard-sections {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .section {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .section h3 {
            margin-bottom: 1rem;
            font-size: 1.125rem;
        }

        .table-container {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background-color: var(--background-color);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table tr:hover {
            background-color: var(--background-color);
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
        .status-available { background-color: #d1fae5; color: #065f46; }
        .status-unavailable { background-color: #fee2e2; color: #991b1b; }
        .status-under_maintenance { background-color: #fef3c7; color: #d97706; }
        .status-reserved { background-color: #dbeafe; color: #1e40af; }
        .status-pending_verification { background-color: #fef3c7; color: #d97706; }
        .status-inactive { background-color: #f3f4f6; color: #374151; }
        .status-suspended { background-color: #fee2e2; color: #991b1b; }
        .status-super_admin { background-color: #7c3aed; color: white; }
        .status-admin { background-color: #2563eb; color: white; }
        .status-manager { background-color: #059669; color: white; }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .action-card {
            background: var(--background-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-primary);
            text-align: center;
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }

        .action-card:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-card svg {
            width: 32px;
            height: 32px;
            margin-bottom: 0.5rem;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .action-card span {
            font-weight: 500;
            font-size: 0.875rem;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--surface-color);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-check-input {
            width: 1rem;
            height: 1rem;
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

        .image-preview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .image-preview-item {
            position: relative;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .image-preview-item img {
            width: 100%;
            height: 80px;
            object-fit: cover;
        }

        .vehicle-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .vehicle-images {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .vehicle-image {
            border-radius: var(--border-radius);
            overflow: hidden;
            position: relative;
        }

        .vehicle-image img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .remove-image {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--error-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 12px;
        }

        .current-images {
            margin-top: 1rem;
        }

        .current-images h4 {
            margin-bottom: 0.5rem;
        }

        .category-image-preview {
            max-width: 200px;
            max-height: 150px;
            border-radius: var(--border-radius);
            margin-top: 0.5rem;
        }

        .auto-fill-info {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .auto-fill-info p {
            margin: 0;
            color: #0369a1;
            font-size: 0.875rem;
        }

        .auto-filled-field {
            background-color: #f8fafc;
            border-color: #e2e8f0;
            color: #64748b;
        }

        .auto-filled-field:focus {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }

        @media (max-width: 1024px) {
            .dashboard-sections {
                grid-template-columns: 1fr;
            }
            
            .vehicle-details {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .admin-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .admin-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
                justify-content: space-between;
            }

            .table-container {
                overflow-x: auto;
            }

            .data-table {
                min-width: 800px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }
        }
    </style>
</head>
<body class="admin-dashboard-page">
    <div class="admin-container">
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                        </svg>
                    </div>
                    <div class="admin-details">
                        <h3><?= htmlspecialchars($_SESSION['admin_name']) ?></h3>
                        <p><?= htmlspecialchars($_SESSION['admin_role']) ?></p>
                    </div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="?page=overview" class="nav-item <?= $page === 'overview' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="?page=bookings" class="nav-item <?= $page === 'bookings' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <span>Bookings</span>
                </a>
                <a href="?page=vehicles" class="nav-item <?= $page === 'vehicles' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                    </svg>
                    <span>Vehicles</span>
                </a>
                <a href="?page=categories" class="nav-item <?= $page === 'categories' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2l-5.5 9h11L12 2zm0 3.84L13.93 9h-3.87L12 5.84zM17.5 13c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zm0 7c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5zM3 21.5h8v-8H3v8zm2-6h4v4H5v-4z"/>
                    </svg>
                    <span>Categories</span>
                </a>
                <a href="?page=customers" class="nav-item <?= $page === 'customers' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M16 4c0-1.11.89-2 2-2s2 .89 2 2-.89 2-2 2-2-.89-2-2zm4 18v-6h2.5l-2.54-7.63A2.01 2.01 0 0018.06 7h-2.12c-.93 0-1.76.53-2.18 1.37L12.5 16H15v6h5zm-7.5-6v6H3v-6l3.5-6H3V4h10v4H8.5l-3.5 6z"/>
                    </svg>
                    <span>Customers</span>
                </a>
                <a href="?page=payments" class="nav-item <?= $page === 'payments' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                    </svg>
                    <span>Payments</span>
                </a>
                <a href="?page=reports" class="nav-item <?= $page === 'reports' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                    </svg>
                    <span>Reports</span>
                </a>
                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                    <a href="?page=admins" class="nav-item <?= $page === 'admins' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                        <span>Admin Users</span>
                    </a>
                    <a href="?page=settings" class="nav-item <?= $page === 'settings' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                        </svg>
                        <span>Settings</span>
                    </a>
                <?php endif; ?>
            </nav>
        </aside>
        
        <main class="admin-main">
            <header class="admin-header">
                <div class="header-left">
                    <h1>
                        <?php 
                        switch($page) {
                            case 'overview': echo 'Dashboard Overview'; break;
                            case 'bookings': echo 'Bookings Management'; break;
                            case 'vehicles': echo 'Vehicle Fleet'; break;
                            case 'categories': echo 'Vehicle Categories'; break;
                            case 'customers': echo 'Customer Management'; break;
                            case 'payments': echo 'Payment Records'; break;
                            case 'reports': echo 'Reports & Analytics'; break;
                            case 'admins': echo 'Admin Users Management'; break;
                            case 'settings': echo 'System Settings'; break;
                            default: echo 'Admin Dashboard';
                        }
                        ?>
                    </h1>
                </div>
                <div class="header-right">
                    <span class="welcome-msg">Welcome, <?= htmlspecialchars($_SESSION['admin_name']) ?></span>
                    <a href="logout.php" class="btn btn-outline btn-sm">Logout</a>
                </div>
            </header>
            
            <div class="admin-content">
                <?php if (isset($result)): ?>
                    <div class="alert <?= $result['success'] ? 'alert-success' : 'alert-error' ?>">
                        <?= htmlspecialchars($result['message']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'overview'): ?>
                    <!-- Overview content -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon" style="background-color: var(--success-color);">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                </svg>
                            </div>
                            <div class="stat-info">
                                <h3>KSh <?= number_format($stats['monthly_revenue'] ?? 0, 2) ?></h3>
                                <p>Monthly Revenue</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background-color: var(--primary-color);">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                                </svg>
                            </div>
                            <div class="stat-info">
                                <h3><?= $stats['confirmed_bookings'] ?? 0 ?></h3>
                                <p>Confirmed Bookings</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background-color: var(--accent-color);">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                                </svg>
                            </div>
                            <div class="stat-info">
                                <h3><?= $stats['available_vehicles'] ?? 0 ?></h3>
                                <p>Available Vehicles</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background-color: var(--secondary-color);">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M16 4c0-1.11.89-2 2-2s2 .89 2 2-.89 2-2 2-2-.89-2-2zm4 18v-6h2.5l-2.54-7.63A2.01 2.01 0 0018.06 7h-2.12c-.93 0-1.76.53-2.18 1.37L12.5 16H15v6h5zm-7.5-6v6H3v-6l3.5-6H3V4h10v4H8.5l-3.5 6z"/>
                                </svg>
                            </div>
                            <div class="stat-info">
                                <h3><?= $stats['active_customers'] ?? 0 ?></h3>
                                <p>Active Customers</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-sections">
                        <div class="section">
                            <h3>Recent Bookings</h3>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Customer</th>
                                            <th>Vehicle</th>
                                            <th>Pickup Date</th>
                                            <th>Status</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentBookings as $booking): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($booking['booking_reference']) ?></td>
                                                <td><?= htmlspecialchars($booking['full_name']) ?></td>
                                                <td><?= htmlspecialchars($booking['make']) ?> <?= htmlspecialchars($booking['model']) ?></td>
                                                <td><?= date('M j, Y', strtotime($booking['pickup_date'])) ?></td>
                                                <td>
                                                    <span class="status-badge status-<?= $booking['status'] ?>">
                                                        <?= ucfirst($booking['status']) ?>
                                                    </span>
                                                </td>
                                                <td>KSh <?= number_format($booking['total_cost'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="section">
                            <h3>Quick Actions</h3>
                            <div class="quick-actions">
                                <a href="#" class="action-card" onclick="openModal('createBookingModal')">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                    </svg>
                                    <span>Create Booking</span>
                                </a>
                                <a href="#" class="action-card" onclick="openModal('createVehicleModal')">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                    </svg>
                                    <span>Add Vehicle</span>
                                </a>
                                <a href="#" class="action-card" onclick="openModal('createCategoryModal')">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                    </svg>
                                    <span>Add Category</span>
                                </a>
                                <a href="?page=customers" class="action-card">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                    </svg>
                                    <span>Add Customer</span>
                                </a>
                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                    <a href="?page=admins" class="action-card" onclick="openModal('createAdminModal')">
                                        <svg viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                        </svg>
                                        <span>Add Admin User</span>
                                    </a>
                                <?php endif; ?>
                                <a href="?page=reports" class="action-card">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                                    </svg>
                                    <span>View Reports</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($page === 'bookings'): ?>
                    <!-- Bookings content -->
                    <div class="section-header">
                        <h2>Bookings Management</h2>
                        <button class="btn btn-primary" onclick="openModal('createBookingModal')">Create New Booking</button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Customer</th>
                                    <th>Vehicle</th>
                                    <th>Pickup Date</th>
                                    <th>Return Date</th>
                                    <th>Total Cost</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentBookings as $booking): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($booking['booking_reference']) ?></td>
                                        <td><?= htmlspecialchars($booking['full_name']) ?></td>
                                        <td><?= htmlspecialchars($booking['make']) ?> <?= htmlspecialchars($booking['model']) ?></td>
                                        <td><?= date('M j, Y', strtotime($booking['pickup_date'])) ?></td>
                                        <td><?= date('M j, Y', strtotime($booking['return_date'])) ?></td>
                                        <td>KSh <?= number_format($booking['total_cost'], 2) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $booking['status'] ?>">
                                                <?= ucfirst($booking['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline" onclick="viewBooking(<?= $booking['id'] ?>)">View</button>
                                                <button class="btn btn-sm btn-primary" onclick="editBooking(<?= $booking['id'] ?>)">Edit</button>
                                                <?php if ($booking['status'] === 'pending'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="updateBookingStatus(<?= $booking['id'] ?>, 'confirmed')">Approve</button>
                                                    <button class="btn btn-sm btn-danger" onclick="updateBookingStatus(<?= $booking['id'] ?>, 'cancelled')">Reject</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php elseif ($page === 'vehicles'): ?>
                    <!-- Vehicles content -->
                    <div class="section-header">
                        <h2>Vehicle Fleet</h2>
                        <button class="btn btn-primary" onclick="openModal('createVehicleModal')">Add New Vehicle</button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Make & Model</th>
                                    <th>Registration</th>
                                    <th>Category</th>
                                    <th>Rate/Day</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?></td>
                                        <td><?= htmlspecialchars($vehicle['registration_number']) ?></td>
                                        <td><?= htmlspecialchars($vehicle['category_name']) ?></td>
                                        <td>KSh <?= number_format($vehicle['rate_per_day'], 2) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $vehicle['status'] ?>">
                                                <?= ucfirst($vehicle['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline" onclick="viewVehicle(<?= $vehicle['id'] ?>)">View</button>
                                                <button class="btn btn-sm btn-primary" onclick="editVehicle(<?= $vehicle['id'] ?>)">Edit</button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteVehicle(<?= $vehicle['id'] ?>)">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php elseif ($page === 'categories'): ?>
                    <!-- Categories content -->
                    <div class="section-header">
                        <h2>Vehicle Categories</h2>
                        <button class="btn btn-primary" onclick="openModal('createCategoryModal')">Add New Category</button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Base Rate/Day</th>
                                    <th>Security Deposit</th>
                                    <th>Min/Max Days</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $categories = $db->query("SELECT * FROM vehicle_categories ORDER BY created_at DESC")->fetchAll();
                                foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($category['name']) ?></td>
                                        <td>KSh <?= number_format($category['base_rate_per_day'], 2) ?></td>
                                        <td>KSh <?= number_format($category['security_deposit'], 2) ?></td>
                                        <td><?= $category['minimum_rental_days'] ?> / <?= $category['maximum_rental_days'] ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $category['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $category['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline" onclick="viewCategory(<?= $category['id'] ?>)">View</button>
                                                <button class="btn btn-sm btn-primary" onclick="editCategory(<?= $category['id'] ?>)">Edit</button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?= $category['id'] ?>)">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php elseif ($page === 'customers'): ?>
                    <!-- Customers content -->
                    <div class="section-header">
                        <h2>Customer Management</h2>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($customer['full_name']) ?></td>
                                        <td><?= htmlspecialchars($customer['email']) ?></td>
                                        <td><?= htmlspecialchars($customer['phone'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $customer['customer_status'] ?>">
                                                <?= ucfirst($customer['customer_status']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M j, Y', strtotime($customer['created_at'])) ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline" onclick="viewCustomer(<?= $customer['id'] ?>)">View</button>
                                                <button class="btn btn-sm btn-primary" onclick="editCustomer(<?= $customer['id'] ?>)">Edit</button>
                                                <?php if ($customer['customer_status'] !== 'active'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="activateCustomer(<?= $customer['id'] ?>)">Activate</button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-warning" onclick="updateCustomerStatus(<?= $customer['id'] ?>)">Status</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php elseif ($page === 'admins' && $_SESSION['admin_role'] === 'super_admin'): ?>
                    <!-- Admin Users Management -->
                    <div class="section-header">
                        <h2>Admin Users Management</h2>
                        <button class="btn btn-primary" onclick="openModal('createAdminModal')">Add New Admin</button>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Last Login</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($admin['username']) ?></td>
                                        <td><?= htmlspecialchars($admin['first_name']) ?> <?= htmlspecialchars($admin['last_name']) ?></td>
                                        <td><?= htmlspecialchars($admin['email']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= str_replace(' ', '_', $admin['role']) ?>">
                                                <?= ucfirst(str_replace('_', ' ', $admin['role'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($admin['last_login']): ?>
                                                <?= date('M j, Y H:i', strtotime($admin['last_login'])) ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $admin['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $admin['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline" onclick="viewAdmin(<?= $admin['id'] ?>)">View</button>
                                                <?php if ($admin['id'] != $_SESSION['admin_id'] && $admin['role'] != 'super_admin'): ?>
                                                    <button class="btn btn-sm btn-primary" onclick="editAdmin(<?= $admin['id'] ?>)">Edit</button>
                                                    <?php if ($admin['is_active']): ?>
                                                        <button class="btn btn-sm btn-warning" onclick="updateAdminStatus(<?= $admin['id'] ?>, 0)">Deactivate</button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-success" onclick="updateAdminStatus(<?= $admin['id'] ?>, 1)">Activate</button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteAdmin(<?= $admin['id'] ?>)">Delete</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                        <h3>Page Under Development</h3>
                        <p>This section is currently being developed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- All modals -->
    <!-- Create Booking Modal -->
    <div id="createBookingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Booking</h3>
                <button class="modal-close" onclick="closeModal('createBookingModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-control form-select" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= $customer['id'] ?>"><?= htmlspecialchars($customer['full_name']) ?> (<?= htmlspecialchars($customer['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vehicle</label>
                        <select name="vehicle_id" class="form-control form-select" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <?php if ($vehicle['status'] === 'available'): ?>
                                    <option value="<?= $vehicle['id'] ?>"><?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?> - <?= htmlspecialchars($vehicle['registration_number']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Pickup Date</label>
                            <input type="date" name="pickup_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Pickup Location</label>
                            <input type="text" name="pickup_location" class="form-control" required value="Nairobi Office">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Return Location</label>
                            <input type="text" name="return_location" class="form-control" required value="Nairobi Office">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('createBookingModal')">Cancel</button>
                    <button type="submit" name="create_booking" class="btn btn-primary">Create Booking</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Vehicle Modal -->
    <div id="createVehicleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Vehicle</h3>
                <button class="modal-close" onclick="closeModal('createVehicleModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="createVehicleForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="category_id" class="form-control form-select" required onchange="loadCategoryData(this.value)">
                            <option value="">Select Category</option>
                            <?php 
                            $allCategories = $db->query("SELECT * FROM vehicle_categories WHERE is_active = 1")->fetchAll();
                            foreach ($allCategories as $category): ?>
                                <option value="<?= $category['id'] ?>" 
                                        data-base-rate="<?= $category['base_rate_per_day'] ?>" 
                                        data-security-deposit="<?= $category['security_deposit'] ?>" 
                                        data-features='<?= $category['features'] ?>'
                                        data-image-path="<?= $category['image_path'] ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="categoryAutoFillInfo" class="auto-fill-info" style="display: none;">
                        <p>✓ Category data auto-filled. You can modify these values as needed.</p>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Make</label>
                            <input type="text" name="make" id="vehicle_make" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Model</label>
                            <input type="text" name="model" id="vehicle_model" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Year</label>
                            <input type="number" name="year" id="vehicle_year" class="form-control" required min="2000" max="<?= date('Y') + 1 ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Color</label>
                            <input type="text" name="color" id="vehicle_color" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="registration_number" id="vehicle_registration" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fuel Type</label>
                            <select name="fuel_type" id="vehicle_fuel_type" class="form-control form-select" required>
                                <option value="petrol">Petrol</option>
                                <option value="diesel">Diesel</option>
                                <option value="electric">Electric</option>
                                <option value="hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Transmission</label>
                            <select name="transmission" id="vehicle_transmission" class="form-control form-select" required>
                                <option value="manual">Manual</option>
                                <option value="automatic">Automatic</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Seating Capacity</label>
                            <input type="number" name="seating_capacity" id="vehicle_seating" class="form-control" required min="2" max="20" value="5">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Doors</label>
                            <input type="number" name="doors" id="vehicle_doors" class="form-control" required min="2" max="6" value="4">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Rate Per Day (KSh)</label>
                            <input type="number" name="rate_per_day" id="rate_per_day" class="form-control auto-filled-field" required min="0" step="0.01" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Security Deposit (KSh)</label>
                            <input type="number" name="security_deposit" id="security_deposit" class="form-control auto-filled-field" required min="0" step="0.01" readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category Image Preview</label>
                        <div id="categoryImagePreview" class="image-preview">
                            <p>No category image available</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vehicle Images</label>
                        <input type="file" name="images[]" class="form-control" multiple accept="image/*">
                        <small>You can select multiple images</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Features</label>
                        <div id="featuresContainer">
                            <div class="form-check">
                                <input type="checkbox" name="features[]" value="AC" id="featureAC" class="form-check-input">
                                <label class="form-label">Air Conditioning</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="features[]" value="Bluetooth" id="featureBluetooth" class="form-check-input">
                                <label class="form-label">Bluetooth</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="features[]" value="GPS" id="featureGPS" class="form-check-input">
                                <label class="form-label">GPS Navigation</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="features[]" value="Rear Camera" id="featureRearCamera" class="form-check-input">
                                <label class="form-label">Rear Camera</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="features[]" value="4WD" id="feature4WD" class="form-check-input">
                                <label class="form-label">4WD</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="features[]" value="Leather Seats" id="featureLeather" class="form-check-input">
                                <label class="form-label">Leather Seats</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('createVehicleModal')">Cancel</button>
                    <button type="submit" name="create_vehicle" class="btn btn-primary">Add Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Vehicle Modal -->
    <div id="editVehicleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Vehicle</h3>
                <button class="modal-close" onclick="closeModal('editVehicleModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="editVehicleForm">
                <div class="modal-body">
                    <input type="hidden" name="vehicle_id" id="editVehicleId">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="editCategoryId" class="form-control form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($vehicleCategories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Make</label>
                            <input type="text" name="make" id="editMake" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Model</label>
                            <input type="text" name="model" id="editModel" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Year</label>
                            <input type="number" name="year" id="editYear" class="form-control" required min="2000" max="<?= date('Y') + 1 ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Color</label>
                            <input type="text" name="color" id="editColor" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="registration_number" id="editRegistrationNumber" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fuel Type</label>
                            <select name="fuel_type" id="editFuelType" class="form-control form-select" required>
                                <option value="petrol">Petrol</option>
                                <option value="diesel">Diesel</option>
                                <option value="electric">Electric</option>
                                <option value="hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Transmission</label>
                            <select name="transmission" id="editTransmission" class="form-control form-select" required>
                                <option value="manual">Manual</option>
                                <option value="automatic">Automatic</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Seating Capacity</label>
                            <input type="number" name="seating_capacity" id="editSeatingCapacity" class="form-control" required min="2" max="20">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Doors</label>
                            <input type="number" name="doors" id="editDoors" class="form-control" required min="2" max="6">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Rate Per Day (KSh)</label>
                            <input type="number" name="rate_per_day" id="editRatePerDay" class="form-control" required min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Security Deposit (KSh)</label>
                            <input type="number" name="security_deposit" id="editSecurityDeposit" class="form-control" required min="0" step="0.01">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="editStatus" class="form-control form-select" required>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                            <option value="under_maintenance">Under Maintenance</option>
                            <option value="reserved">Reserved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Current Images</label>
                        <div class="current-images">
                            <div id="currentImagesContainer" class="image-preview">
                                <!-- Current images will be loaded here -->
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Add New Images</label>
                        <input type="file" name="images[]" class="form-control" multiple accept="image/*">
                        <small>You can select multiple images to add</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Features</label>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="AC" id="featureAC" class="form-check-input">
                            <label class="form-label">Air Conditioning</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Bluetooth" id="featureBluetooth" class="form-check-input">
                            <label class="form-label">Bluetooth</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="GPS" id="featureGPS" class="form-check-input">
                            <label class="form-label">GPS Navigation</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Rear Camera" id="featureRearCamera" class="form-check-input">
                            <label class="form-label">Rear Camera</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editVehicleModal')">Cancel</button>
                    <button type="submit" name="update_vehicle" class="btn btn-primary">Update Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Vehicle Modal -->
    <div id="viewVehicleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Vehicle Details</h3>
                <button class="modal-close" onclick="closeModal('viewVehicleModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="vehicleDetailsContent">
                    <!-- Vehicle details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewVehicleModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Update Booking Status Modal -->
    <div id="updateBookingStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Booking Status</h3>
                <button class="modal-close" onclick="closeModal('updateBookingStatusModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="bookingId">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control form-select" required>
                            <option value="confirmed">Confirm</option>
                            <option value="cancelled">Cancel</option>
                            <option value="active">Mark as Active</option>
                            <option value="completed">Mark as Completed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cancellation Reason (if applicable)</label>
                        <textarea name="cancellation_reason" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('updateBookingStatusModal')">Cancel</button>
                    <button type="submit" name="update_booking_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Category Modal -->
    <div id="createCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Category</h3>
                <button class="modal-close" onclick="closeModal('createCategoryModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Base Rate Per Day (KSh)</label>
                            <input type="number" name="base_rate_per_day" class="form-control" required min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rate Per KM (KSh)</label>
                            <input type="number" name="rate_per_km" class="form-control" min="0" step="0.01" value="0.00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Security Deposit (KSh)</label>
                            <input type="number" name="security_deposit" class="form-control" required min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Insurance Daily Rate (KSh)</label>
                            <input type="number" name="insurance_daily_rate" class="form-control" min="0" step="0.01" value="0.00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Minimum Rental Days</label>
                            <input type="number" name="minimum_rental_days" class="form-control" min="1" value="1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Maximum Rental Days</label>
                            <input type="number" name="maximum_rental_days" class="form-control" min="1" value="30">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category Image</label>
                        <input type="file" name="image_path" class="form-control" accept="image/*">
                        <small>Upload a representative image for this category</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Features</label>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="AC" class="form-check-input">
                            <label class="form-label">Air Conditioning</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Bluetooth" class="form-check-input">
                            <label class="form-label">Bluetooth</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="GPS" class="form-check-input">
                            <label class="form-label">GPS Navigation</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Rear Camera" class="form-check-input">
                            <label class="form-label">Rear Camera</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="4WD" class="form-check-input">
                            <label class="form-label">4WD</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Leather Seats" class="form-check-input">
                            <label class="form-label">Leather Seats</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('createCategoryModal')">Cancel</button>
                    <button type="submit" name="create_category" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div id="editCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Category</h3>
                <button class="modal-close" onclick="closeModal('editCategoryModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="editCategoryForm">
                <div class="modal-body">
                    <input type="hidden" name="category_id" id="editCategoryId">
                    <div class="form-group">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="name" id="editCategoryName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="editCategoryDescription" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Base Rate Per Day (KSh)</label>
                            <input type="number" name="base_rate_per_day" id="editBaseRatePerDay" class="form-control" required min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rate Per KM (KSh)</label>
                            <input type="number" name="rate_per_km" id="editRatePerKm" class="form-control" min="0" step="0.01">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Security Deposit (KSh)</label>
                            <input type="number" name="security_deposit" id="editSecurityDepositCat" class="form-control" required min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Insurance Daily Rate (KSh)</label>
                            <input type="number" name="insurance_daily_rate" id="editInsuranceDailyRate" class="form-control" min="0" step="0.01">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Minimum Rental Days</label>
                            <input type="number" name="minimum_rental_days" id="editMinRentalDays" class="form-control" min="1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Maximum Rental Days</label>
                            <input type="number" name="maximum_rental_days" id="editMaxRentalDays" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Current Image</label>
                        <div id="currentCategoryImageContainer">
                            <!-- Current image will be loaded here -->
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category Image</label>
                        <input type="file" name="image_path" class="form-control" accept="image/*">
                        <small>Upload a new image to replace the current one</small>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="remove_image" value="1" class="form-check-input">
                        <label class="form-label">Remove current image</label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="editIsActive" class="form-check-input" value="1">
                            <label class="form-label">Active</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Features</label>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="AC" id="editFeatureAC" class="form-check-input">
                            <label class="form-label">Air Conditioning</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Bluetooth" id="editFeatureBluetooth" class="form-check-input">
                            <label class="form-label">Bluetooth</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="GPS" id="editFeatureGPS" class="form-check-input">
                            <label class="form-label">GPS Navigation</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Rear Camera" id="editFeatureRearCamera" class="form-check-input">
                            <label class="form-label">Rear Camera</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="4WD" id="editFeature4WD" class="form-check-input">
                            <label class="form-label">4WD</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="features[]" value="Leather Seats" id="editFeatureLeather" class="form-check-input">
                            <label class="form-label">Leather Seats</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editCategoryModal')">Cancel</button>
                    <button type="submit" name="update_category" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Category Modal -->
    <div id="viewCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Category Details</h3>
                <button class="modal-close" onclick="closeModal('viewCategoryModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="categoryDetailsContent">
                    <!-- Category details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewCategoryModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- View Customer Modal -->
    <div id="viewCustomerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Customer Details</h3>
                <button class="modal-close" onclick="closeModal('viewCustomerModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="customerDetailsContent">
                    <!-- Customer details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewCustomerModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Customer Modal -->
    <div id="editCustomerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Customer</h3>
                <button class="modal-close" onclick="closeModal('editCustomerModal')">&times;</button>
            </div>
            <form method="POST" action="" id="editCustomerForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="editCustomerId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" id="editFullName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="editEmail" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="editPhone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">National ID</label>
                            <input type="text" name="national_id" id="editNationalId" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="editDateOfBirth" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Driving License</label>
                            <input type="text" name="driving_license" id="editDrivingLicense" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">License Expiry</label>
                            <input type="date" name="license_expiry" id="editLicenseExpiry" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Trust Score</label>
                            <input type="number" name="trust_score" id="editTrustScore" class="form-control" min="0" max="100" value="100">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" id="editAddress" class="form-control">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" name="city" id="editCity" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">State</label>
                            <input type="text" name="state" id="editState" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">ZIP Code</label>
                            <input type="text" name="zip_code" id="editZipCode" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Country</label>
                            <input type="text" name="country" id="editCountry" class="form-control" value="Kenya">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="customer_status" id="editCustomerStatus" class="form-control form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="pending_verification">Pending Verification</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Preferred Communication</label>
                            <select name="preferred_communication" id="editPreferredCommunication" class="form-control form-select">
                                <option value="email">Email</option>
                                <option value="sms">SMS</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" name="email_verified" id="editEmailVerified" class="form-check-input" value="1">
                                <label class="form-label">Email Verified</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" name="phone_verified" id="editPhoneVerified" class="form-check-input" value="1">
                                <label class="form-label">Phone Verified</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="marketing_emails" id="editMarketingEmails" class="form-check-input" value="1">
                            <label class="form-label">Subscribe to Marketing Emails</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="editNotes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editCustomerModal')">Cancel</button>
                    <button type="submit" name="update_customer" class="btn btn-primary">Update Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Customer Status Modal -->
    <div id="updateCustomerStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Customer Status</h3>
                <button class="modal-close" onclick="closeModal('updateCustomerStatusModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="customerStatusId">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control form-select" required>
                            <option value="active">Activate Account</option>
                            <option value="inactive">Deactivate Account</option>
                            <option value="suspended">Suspend Account</option>
                            <option value="pending_verification">Set as Pending Verification</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add any notes about this status change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('updateCustomerStatusModal')">Cancel</button>
                    <button type="submit" name="update_customer_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Admin Modal -->
    <div id="createAdminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Admin User</h3>
                <button class="modal-close" onclick="closeModal('createAdminModal')">&times;</button>
            </div>
            <form method="POST" action="" id="createAdminForm">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                            <small class="text-muted">Must be unique</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                            <small class="text-muted">Must be unique</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="8">
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control form-select" required>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" value="1" checked>
                                <label class="form-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="two_factor_enabled" class="form-check-input" value="1">
                            <label class="form-label">Enable Two-Factor Authentication</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('createAdminModal')">Cancel</button>
                    <button type="submit" name="create_admin" class="btn btn-primary">Create Admin</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Admin Modal -->
    <div id="editAdminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Admin User</h3>
                <button class="modal-close" onclick="closeModal('editAdminModal')">&times;</button>
            </div>
            <form method="POST" action="" id="editAdminForm">
                <div class="modal-body">
                    <input type="hidden" name="admin_id" id="editAdminId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" id="editFirstName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" id="editLastName" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" id="editUsername" class="form-control" required>
                            <small class="text-muted">Must be unique</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="editAdminEmail" class="form-control" required>
                            <small class="text-muted">Must be unique</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Password (leave blank to keep current)</label>
                            <input type="password" name="password" class="form-control" minlength="8">
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select name="role" id="editAdminRole" class="form-control form-select" required>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="editAdminActive" class="form-check-input" value="1">
                                <label class="form-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="two_factor_enabled" id="editTwoFactorEnabled" class="form-check-input" value="1">
                            <label class="form-label">Enable Two-Factor Authentication</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editAdminModal')">Cancel</button>
                    <button type="submit" name="update_admin" class="btn btn-primary">Update Admin</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Admin Modal -->
    <div id="viewAdminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Admin User Details</h3>
                <button class="modal-close" onclick="closeModal('viewAdminModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="adminDetailsContent">
                    <!-- Admin details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('viewAdminModal')">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // Category auto-fill functionality
        function loadCategoryData(categoryId) {
            if (!categoryId) {
                document.getElementById('categoryAutoFillInfo').style.display = 'none';
                document.getElementById('categoryImagePreview').innerHTML = '<p>No category image available</p>';
                // Enable editable fields
                document.getElementById('rate_per_day').readOnly = false;
                document.getElementById('security_deposit').readOnly = false;
                document.getElementById('rate_per_day').classList.remove('auto-filled-field');
                document.getElementById('security_deposit').classList.remove('auto-filled-field');
                return;
            }

            const selectedOption = document.querySelector(`#category_id option[value="${categoryId}"]`);
            if (selectedOption) {
                const baseRate = selectedOption.getAttribute('data-base-rate');
                const securityDeposit = selectedOption.getAttribute('data-security-deposit');
                const features = JSON.parse(selectedOption.getAttribute('data-features') || '[]');
                const imagePath = selectedOption.getAttribute('data-image-path');
                
                // Auto-fill the fields
                document.getElementById('rate_per_day').value = baseRate;
                document.getElementById('security_deposit').value = securityDeposit;
                
                // Make fields read-only and styled
                document.getElementById('rate_per_day').readOnly = true;
                document.getElementById('security_deposit').readOnly = true;
                document.getElementById('rate_per_day').classList.add('auto-filled-field');
                document.getElementById('security_deposit').classList.add('auto-filled-field');
                
                // Auto-check features
                const featureCheckboxes = document.querySelectorAll('#featuresContainer input[type="checkbox"]');
                featureCheckboxes.forEach(checkbox => {
                    checkbox.checked = features.includes(checkbox.value);
                });
                
                // Show category image preview
                const imagePreview = document.getElementById('categoryImagePreview');
                if (imagePath && imagePath !== 'null') {
                    imagePreview.innerHTML = `
                        <div class="image-preview-item">
                            <img src="../uploads/categories/${imagePath}" alt="Category Image" style="max-width: 200px; max-height: 150px;">
                        </div>
                    `;
                } else {
                    imagePreview.innerHTML = '<p>No category image available</p>';
                }
                
                // Show auto-fill info
                document.getElementById('categoryAutoFillInfo').style.display = 'block';
            }
        }

        // Booking functions
        function updateBookingStatus(bookingId, status) {
            document.getElementById('bookingId').value = bookingId;
            document.querySelector('select[name="status"]').value = status;
            openModal('updateBookingStatusModal');
        }

        function viewBooking(bookingId) {
            alert('View booking details for ID: ' + bookingId);
        }

        function editBooking(bookingId) {
            alert('Edit booking for ID: ' + bookingId);
        }

        // Vehicle functions
        function viewVehicle(vehicleId) {
            fetch('get_vehicle_details.php?id=' + vehicleId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load vehicle details');
                    }
                    
                    const data = result.data;
                    const content = document.getElementById('vehicleDetailsContent');
                    content.innerHTML = `
                        <div class="vehicle-details">
                            <div class="vehicle-images">
                                ${data.images && data.images.length > 0 ? 
                                    data.images.map(img => `
                                        <div class="vehicle-image">
                                            <img src="../uploads/vehicles/${img}" alt="${data.make} ${data.model}">
                                        </div>
                                    `).join('') : 
                                    '<p>No images available</p>'
                                }
                            </div>
                            <div>
                                <h4>${data.make} ${data.model} (${data.year})</h4>
                                <p><strong>Registration:</strong> ${data.registration_number}</p>
                                <p><strong>Color:</strong> ${data.color}</p>
                                <p><strong>Category:</strong> ${data.category_name}</p>
                                <p><strong>Fuel Type:</strong> ${data.fuel_type}</p>
                                <p><strong>Transmission:</strong> ${data.transmission}</p>
                                <p><strong>Seating Capacity:</strong> ${data.seating_capacity}</p>
                                <p><strong>Doors:</strong> ${data.doors}</p>
                                <p><strong>Rate per Day:</strong> KSh ${parseFloat(data.rate_per_day).toLocaleString()}</p>
                                <p><strong>Security Deposit:</strong> KSh ${parseFloat(data.security_deposit).toLocaleString()}</p>
                                <p><strong>Status:</strong> <span class="status-badge status-${data.status}">${data.status}</span></p>
                                ${data.features && data.features.length > 0 ? `
                                    <p><strong>Features:</strong> ${data.features.join(', ')}</p>
                                ` : ''}
                                <p><strong>Created:</strong> ${new Date(data.created_at).toLocaleDateString()}</p>
                                <p><strong>Last Updated:</strong> ${new Date(data.updated_at).toLocaleDateString()}</p>
                            </div>
                        </div>
                    `;
                    openModal('viewVehicleModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading vehicle details: ' + error.message);
                });
        }

        function editVehicle(vehicleId) {
            fetch('get_vehicle_details.php?id=' + vehicleId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load vehicle details');
                    }
                    
                    const data = result.data;
                    // Populate form fields
                    document.getElementById('editVehicleId').value = data.id;
                    document.getElementById('editCategoryId').value = data.category_id;
                    document.getElementById('editMake').value = data.make;
                    document.getElementById('editModel').value = data.model;
                    document.getElementById('editYear').value = data.year;
                    document.getElementById('editColor').value = data.color;
                    document.getElementById('editRegistrationNumber').value = data.registration_number;
                    document.getElementById('editFuelType').value = data.fuel_type;
                    document.getElementById('editTransmission').value = data.transmission;
                    document.getElementById('editSeatingCapacity').value = data.seating_capacity;
                    document.getElementById('editDoors').value = data.doors;
                    document.getElementById('editRatePerDay').value = data.rate_per_day;
                    document.getElementById('editSecurityDeposit').value = data.security_deposit;
                    document.getElementById('editStatus').value = data.status;

                    // Populate features checkboxes
                    document.getElementById('featureAC').checked = data.features.includes('AC');
                    document.getElementById('featureBluetooth').checked = data.features.includes('Bluetooth');
                    document.getElementById('featureGPS').checked = data.features.includes('GPS');
                    document.getElementById('featureRearCamera').checked = data.features.includes('Rear Camera');

                    // Populate current images
                    const imagesContainer = document.getElementById('currentImagesContainer');
                    imagesContainer.innerHTML = '';
                    
                    if (data.images && data.images.length > 0) {
                        data.images.forEach(image => {
                            const imageDiv = document.createElement('div');
                            imageDiv.className = 'image-preview-item';
                            imageDiv.innerHTML = `
                                <div class="vehicle-image">
                                    <img src="../uploads/vehicles/${image}" alt="${data.make} ${data.model}">
                                    <button type="button" class="remove-image" onclick="removeImage('${image}')">&times;</button>
                                </div>
                            `;
                            imagesContainer.appendChild(imageDiv);
                        });
                    } else {
                        imagesContainer.innerHTML = '<p>No images available</p>';
                    }

                    openModal('editVehicleModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading vehicle details for editing: ' + error.message);
                });
        }

        function removeImage(imageName) {
            if (!confirm('Are you sure you want to remove this image?')) {
                return;
            }
            
            // Create hidden input to track removed images
            let removeInput = document.querySelector('input[name="remove_images[]"][value="' + imageName + '"]');
            if (!removeInput) {
                removeInput = document.createElement('input');
                removeInput.type = 'hidden';
                removeInput.name = 'remove_images[]';
                removeInput.value = imageName;
                document.getElementById('editVehicleForm').appendChild(removeInput);
            }
            
            // Remove image from display
            const imageElement = document.querySelector(`.vehicle-image img[src*="${imageName}"]`).closest('.image-preview-item');
            if (imageElement) {
                imageElement.remove();
            }
        }

        function deleteVehicle(vehicleId) {
            if (!confirm('Are you sure you want to delete this vehicle? This action cannot be undone.')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'vehicle_id';
            input.value = vehicleId;
            
            const submit = document.createElement('input');
            submit.type = 'hidden';
            submit.name = 'delete_vehicle';
            submit.value = '1';
            
            form.appendChild(input);
            form.appendChild(submit);
            document.body.appendChild(form);
            form.submit();
        }

        // Category functions
        function viewCategory(categoryId) {
            fetch('get_category_details.php?id=' + categoryId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load category details');
                    }
                    
                    const data = result.data;
                    const content = document.getElementById('categoryDetailsContent');
                    content.innerHTML = `
                        <div class="vehicle-details">
                            ${data.image_path ? `
                                <div>
                                    <img src="../uploads/categories/${data.image_path}" alt="${data.name}" class="category-image-preview">
                                </div>
                            ` : ''}
                            <div>
                                <h4>${data.name}</h4>
                                <p><strong>Description:</strong> ${data.description || 'N/A'}</p>
                                <p><strong>Base Rate per Day:</strong> KSh ${parseFloat(data.base_rate_per_day).toLocaleString()}</p>
                                <p><strong>Rate per KM:</strong> KSh ${parseFloat(data.rate_per_km).toLocaleString()}</p>
                                <p><strong>Security Deposit:</strong> KSh ${parseFloat(data.security_deposit).toLocaleString()}</p>
                                <p><strong>Insurance Daily Rate:</strong> KSh ${parseFloat(data.insurance_daily_rate).toLocaleString()}</p>
                                <p><strong>Rental Period:</strong> ${data.minimum_rental_days} - ${data.maximum_rental_days} days</p>
                                <p><strong>Vehicles in Category:</strong> ${data.vehicle_count}</p>
                                <p><strong>Status:</strong> <span class="status-badge status-${data.is_active ? 'active' : 'inactive'}">${data.is_active ? 'Active' : 'Inactive'}</span></p>
                                ${data.features && data.features.length > 0 ? `
                                    <p><strong>Features:</strong> ${data.features.join(', ')}</p>
                                ` : ''}
                                <p><strong>Created:</strong> ${new Date(data.created_at).toLocaleDateString()}</p>
                                <p><strong>Last Updated:</strong> ${new Date(data.updated_at).toLocaleDateString()}</p>
                            </div>
                        </div>
                    `;
                    openModal('viewCategoryModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading category details: ' + error.message);
                });
        }

        function editCategory(categoryId) {
            fetch('get_category_details.php?id=' + categoryId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load category details');
                    }
                    
                    const data = result.data;
                    // Populate form fields
                    document.getElementById('editCategoryId').value = data.id;
                    document.getElementById('editCategoryName').value = data.name;
                    document.getElementById('editCategoryDescription').value = data.description || '';
                    document.getElementById('editBaseRatePerDay').value = data.base_rate_per_day;
                    document.getElementById('editRatePerKm').value = data.rate_per_km;
                    document.getElementById('editSecurityDepositCat').value = data.security_deposit;
                    document.getElementById('editInsuranceDailyRate').value = data.insurance_daily_rate;
                    document.getElementById('editMinRentalDays').value = data.minimum_rental_days;
                    document.getElementById('editMaxRentalDays').value = data.maximum_rental_days;
                    document.getElementById('editIsActive').checked = data.is_active;

                    // Populate features checkboxes
                    document.getElementById('editFeatureAC').checked = data.features.includes('AC');
                    document.getElementById('editFeatureBluetooth').checked = data.features.includes('Bluetooth');
                    document.getElementById('editFeatureGPS').checked = data.features.includes('GPS');
                    document.getElementById('editFeatureRearCamera').checked = data.features.includes('Rear Camera');
                    document.getElementById('editFeature4WD').checked = data.features.includes('4WD');
                    document.getElementById('editFeatureLeather').checked = data.features.includes('Leather Seats');

                    // Populate current image
                    const imageContainer = document.getElementById('currentCategoryImageContainer');
                    imageContainer.innerHTML = '';
                    
                    if (data.image_path) {
                        imageContainer.innerHTML = `
                            <img src="../uploads/categories/${data.image_path}" alt="${data.name}" class="category-image-preview">
                        `;
                    } else {
                        imageContainer.innerHTML = '<p>No image available</p>';
                    }

                    openModal('editCategoryModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading category details for editing: ' + error.message);
                });
        }

        function deleteCategory(categoryId) {
            if (!confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'category_id';
            input.value = categoryId;
            
            const submit = document.createElement('input');
            submit.type = 'hidden';
            submit.name = 'delete_category';
            submit.value = '1';
            
            form.appendChild(input);
            form.appendChild(submit);
            document.body.appendChild(form);
            form.submit();
        }

        // Customer functions
        function viewCustomer(customerId) {
            fetch('get_customer_details.php?id=' + customerId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load customer details');
                    }
                    
                    const data = result.data;
                    const content = document.getElementById('customerDetailsContent');
                    
                    let bookingHistoryHtml = '';
                    if (data.booking_history && data.booking_history.length > 0) {
                        bookingHistoryHtml = `
                            <h4>Recent Bookings</h4>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Vehicle</th>
                                            <th>Pickup Date</th>
                                            <th>Return Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${data.booking_history.map(booking => `
                                            <tr>
                                                <td>${booking.booking_reference}</td>
                                                <td>${booking.make} ${booking.model}</td>
                                                <td>${new Date(booking.pickup_date).toLocaleDateString()}</td>
                                                <td>${new Date(booking.return_date).toLocaleDateString()}</td>
                                                <td>KSh ${parseFloat(booking.total_cost).toLocaleString()}</td>
                                                <td><span class="status-badge status-${booking.status}">${booking.status}</span></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                    } else {
                        bookingHistoryHtml = '<p>No booking history found.</p>';
                    }
                    
                    content.innerHTML = `
                        <div class="vehicle-details">
                            <div>
                                <h4>${data.full_name}</h4>
                                <p><strong>Email:</strong> ${data.email}</p>
                                <p><strong>Phone:</strong> ${data.phone || 'N/A'}</p>
                                <p><strong>National ID:</strong> ${data.national_id || 'N/A'}</p>
                                <p><strong>Date of Birth:</strong> ${data.date_of_birth ? new Date(data.date_of_birth).toLocaleDateString() : 'N/A'}</p>
                                <p><strong>Driving License:</strong> ${data.driving_license || 'N/A'}</p>
                                <p><strong>License Expiry:</strong> ${data.license_expiry ? new Date(data.license_expiry).toLocaleDateString() : 'N/A'}</p>
                                <p><strong>Address:</strong> ${data.address || 'N/A'}</p>
                                <p><strong>City:</strong> ${data.city || 'N/A'}</p>
                                <p><strong>State:</strong> ${data.state || 'N/A'}</p>
                                <p><strong>ZIP Code:</strong> ${data.zip_code || 'N/A'}</p>
                                <p><strong>Country:</strong> ${data.country || 'N/A'}</p>
                            </div>
                            <div>
                                <h4>Account Information</h4>
                                <p><strong>Status:</strong> <span class="status-badge status-${data.customer_status}">${data.customer_status}</span></p>
                                <p><strong>Trust Score:</strong> ${data.trust_score}/100</p>
                                <p><strong>Email Verified:</strong> ${data.email_verified ? 'Yes' : 'No'}</p>
                                <p><strong>Phone Verified:</strong> ${data.phone_verified ? 'Yes' : 'No'}</p>
                                <p><strong>Preferred Communication:</strong> ${data.preferred_communication}</p>
                                <p><strong>Marketing Emails:</strong> ${data.marketing_emails ? 'Subscribed' : 'Not Subscribed'}</p>
                                <p><strong>Total Bookings:</strong> ${data.total_bookings || 0}</p>
                                <p><strong>Completed Bookings:</strong> ${data.completed_bookings || 0}</p>
                                <p><strong>Active Bookings:</strong> ${data.active_bookings || 0}</p>
                                <p><strong>Last Login:</strong> ${data.last_login ? new Date(data.last_login).toLocaleString() : 'Never'}</p>
                                <p><strong>Member Since:</strong> ${new Date(data.created_at).toLocaleDateString()}</p>
                                ${data.notes ? `<p><strong>Notes:</strong> ${data.notes}</p>` : ''}
                            </div>
                        </div>
                        ${bookingHistoryHtml}
                    `;
                    openModal('viewCustomerModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details: ' + error.message);
                });
        }

        function editCustomer(customerId) {
            fetch('get_customer_details.php?id=' + customerId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load customer details');
                    }
                    
                    const data = result.data;
                    
                    // Populate form fields
                    document.getElementById('editCustomerId').value = data.id;
                    document.getElementById('editFullName').value = data.full_name;
                    document.getElementById('editEmail').value = data.email;
                    document.getElementById('editPhone').value = data.phone || '';
                    document.getElementById('editNationalId').value = data.national_id || '';
                    document.getElementById('editDateOfBirth').value = data.date_of_birth || '';
                    document.getElementById('editDrivingLicense').value = data.driving_license || '';
                    document.getElementById('editLicenseExpiry').value = data.license_expiry || '';
                    document.getElementById('editAddress').value = data.address || '';
                    document.getElementById('editCity').value = data.city || '';
                    document.getElementById('editState').value = data.state || '';
                    document.getElementById('editZipCode').value = data.zip_code || '';
                    document.getElementById('editCountry').value = data.country || 'Kenya';
                    document.getElementById('editCustomerStatus').value = data.customer_status;
                    document.getElementById('editPreferredCommunication').value = data.preferred_communication || 'email';
                    document.getElementById('editTrustScore').value = data.trust_score || 100;
                    document.getElementById('editEmailVerified').checked = data.email_verified == 1;
                    document.getElementById('editPhoneVerified').checked = data.phone_verified == 1;
                    document.getElementById('editMarketingEmails').checked = data.marketing_emails == 1;
                    document.getElementById('editNotes').value = data.notes || '';
                    
                    openModal('editCustomerModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details for editing: ' + error.message);
                });
        }

        function updateCustomerStatus(customerId, status = '') {
            document.getElementById('customerStatusId').value = customerId;
            
            if (status) {
                document.querySelector('#updateCustomerStatusModal select[name="status"]').value = status;
            }
            
            openModal('updateCustomerStatusModal');
        }

        function activateCustomer(customerId) {
            updateCustomerStatus(customerId, 'active');
        }

        // Admin functions
        function viewAdmin(adminId) {
            fetch('get_admin_details.php?id=' + adminId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load admin details');
                    }
                    
                    const data = result.data;
                    const content = document.getElementById('adminDetailsContent');
                    
                    content.innerHTML = `
                        <div class="vehicle-details">
                            <div>
                                <h4>${data.first_name} ${data.last_name}</h4>
                                <p><strong>Username:</strong> ${data.username}</p>
                                <p><strong>Email:</strong> ${data.email}</p>
                                <p><strong>Role:</strong> <span class="status-badge status-${data.role.replace(' ', '_')}">${data.role}</span></p>
                                <p><strong>Status:</strong> <span class="status-badge status-${data.is_active ? 'active' : 'inactive'}">${data.is_active ? 'Active' : 'Inactive'}</span></p>
                                <p><strong>Two-Factor Auth:</strong> ${data.two_factor_enabled ? 'Enabled' : 'Disabled'}</p>
                                <p><strong>Failed Login Attempts:</strong> ${data.failed_login_attempts}</p>
                                ${data.account_locked_until ? `
                                    <p><strong>Account Locked Until:</strong> ${new Date(data.account_locked_until).toLocaleString()}</p>
                                ` : ''}
                            </div>
                            <div>
                                <h4>Activity Information</h4>
                                <p><strong>Last Login:</strong> ${data.last_login ? new Date(data.last_login).toLocaleString() : 'Never'}</p>
                                <p><strong>Last Login IP:</strong> ${data.last_login_ip || 'N/A'}</p>
                                <p><strong>Password Last Changed:</strong> ${new Date(data.password_changed_at).toLocaleDateString()}</p>
                                <p><strong>Member Since:</strong> ${new Date(data.created_at).toLocaleDateString()}</p>
                                <p><strong>Last Updated:</strong> ${new Date(data.updated_at).toLocaleDateString()}</p>
                            </div>
                        </div>
                    `;
                    openModal('viewAdminModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading admin details: ' + error.message);
                });
        }

        function editAdmin(adminId) {
            fetch('get_admin_details.php?id=' + adminId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to load admin details');
                    }
                    
                    const data = result.data;
                    
                    // Populate form fields
                    document.getElementById('editAdminId').value = data.id;
                    document.getElementById('editFirstName').value = data.first_name;
                    document.getElementById('editLastName').value = data.last_name;
                    document.getElementById('editUsername').value = data.username;
                    document.getElementById('editAdminEmail').value = data.email;
                    document.getElementById('editAdminRole').value = data.role;
                    document.getElementById('editAdminActive').checked = data.is_active == 1;
                    document.getElementById('editTwoFactorEnabled').checked = data.two_factor_enabled == 1;
                    
                    openModal('editAdminModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading admin details for editing: ' + error.message);
                });
        }

        function deleteAdmin(adminId) {
            if (!confirm('Are you sure you want to delete this admin user? This action cannot be undone.')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'admin_id';
            input.value = adminId;
            
            const submit = document.createElement('input');
            submit.type = 'hidden';
            submit.name = 'delete_admin';
            submit.value = '1';
            
            form.appendChild(input);
            form.appendChild(submit);
            document.body.appendChild(form);
            form.submit();
        }

        function updateAdminStatus(adminId, status) {
            if (!confirm('Are you sure you want to change this admin\'s status?')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'admin_id';
            input.value = adminId;
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'is_active';
            statusInput.value = status;
            
            const submit = document.createElement('input');
            submit.type = 'hidden';
            submit.name = 'update_admin_status';
            submit.value = '1';
            
            form.appendChild(input);
            form.appendChild(statusInput);
            form.appendChild(submit);
            document.body.appendChild(form);
            form.submit();
        }

        // Image preview for vehicle creation
        document.querySelector('input[name="images[]"]')?.addEventListener('change', function(e) {
            const previewContainer = this.parentNode.querySelector('.image-preview');
            if (!previewContainer) return;
            
            previewContainer.innerHTML = '';
            
            for (let file of e.target.files) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-preview-item';
                    div.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                    previewContainer.appendChild(div);
                }
                reader.readAsDataURL(file);
            }
        });

        // Image preview for category creation
        document.querySelector('input[name="image_path"]')?.addEventListener('change', function(e) {
            const previewContainer = this.parentNode;
            let existingPreview = previewContainer.querySelector('.category-image-preview');
            
            if (existingPreview) {
                existingPreview.remove();
            }
            
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'category-image-preview';
                    previewContainer.appendChild(img);
                }
                reader.readAsDataURL(e.target.files[0]);
            }
        });

        // Add image preview containers after file inputs
        document.querySelectorAll('input[name="images[]"]').forEach(fileInput => {
            const previewContainer = document.createElement('div');
            previewContainer.className = 'image-preview';
            fileInput.parentNode.appendChild(previewContainer);
        });

        document.addEventListener('DOMContentLoaded', function() {
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
            document.querySelectorAll('.stat-card, .action-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
        });

        // Utility function for formatting numbers
        function formatNumber(number) {
            return new Intl.NumberFormat('en-KE').format(number);
        }

        // Utility function for formatting currency
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-KE', {
                style: 'currency',
                currency: 'KES'
            }).format(amount);
        }

        // Password validation for admin forms
        document.querySelector('#createAdminForm')?.addEventListener('submit', function(e) {
            const password = this.querySelector('input[name="password"]').value;
            const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
        });

        document.querySelector('#editAdminForm')?.addEventListener('submit', function(e) {
            const password = this.querySelector('input[name="password"]').value;
            const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
            
            if (password && password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
        });
    </script>
</body>
</html>