<?php
session_start();

class Database {
    private $host = '127.0.0.1';
    private $db_name = 'car_rental_db';
    private $username = 'root';
    private $password = '';
    private $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username, 
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            die("Database connection failed. Please try again later.");
        }
        
        return $this->conn;
    }
    
    public function sanitize($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
    
    public function logSecurityEvent($userType, $userId, $ipAddress, $action, $description, $severity = 'low') {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO security_logs 
                (user_type, user_id, ip_address, action, description, severity) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userType, $userId, $ipAddress, $action, $description, $severity]);
        } catch (Exception $e) {
            error_log("Security log error: " . $e->getMessage());
        }
    }
}

$database = new Database();
$db = $database->getConnection();

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

function validateCSRFToken() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security violation: Invalid CSRF token");
    }
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 900) {
    $key = "rate_limit_" . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => time()
        ];
        return true;
    }
    
    $rateData = $_SESSION[$key];
    
    if (time() - $rateData['first_attempt'] > $timeWindow) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => time()
        ];
        return true;
    }
    
    if ($rateData['attempts'] >= $maxAttempts) {
        return false;
    }
    
    $_SESSION[$key]['attempts']++;
    return true;
}
?>