<?php
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function login($username, $password) {
        $sql = "SELECT * FROM agents WHERE username = :username";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['extension'] = $user['extension'];
            
            $this->updateLoginTime($user['id']);
            $this->logActivity($user['id'], 'login', 'User logged in');
            
            return [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'extension' => $user['extension'],
                    'name' => $user['name']
                ]
            ];
        }
        
        $this->logActivity(null, 'login_failed', 'Failed login attempt for username: ' . $username);
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->updateStatus($_SESSION['user_id'], 'offline');
            $this->logActivity($_SESSION['user_id'], 'logout', 'User logged out');
        }
        
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $sql = "SELECT id, username, role, extension, name, status, login_time, last_activity FROM agents WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $_SESSION['user_id']]);
        return $stmt->fetch();
    }
    
    public function hasRole($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
    
    public function isAdmin() {
        return $this->hasRole('admin');
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            http_response_code(403);
            die('Access denied. Administrator privileges required.');
        }
    }
    
    public function createUser($data) {
        if (!$this->isAdmin()) {
            return ['success' => false, 'message' => 'Permission denied'];
        }
        
        $sql = "INSERT INTO agents (username, password, extension, name, role) VALUES (:username, :password, :extension, :name, :role)";
        $stmt = $this->db->prepare($sql);
        
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        try {
            $result = $stmt->execute([
                ':username' => $data['username'],
                ':password' => $hashedPassword,
                ':extension' => $data['extension'],
                ':name' => $data['name'],
                ':role' => $data['role'] ?? 'agent'
            ]);
            
            if ($result) {
                $this->logActivity($_SESSION['user_id'], 'user_created', 'Created user: ' . $data['username']);
                return ['success' => true, 'message' => 'User created successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to create user'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['success' => false, 'message' => 'Username or extension already exists'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function updatePassword($currentPassword, $newPassword) {
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        $sql = "SELECT password FROM agents WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $user['id']]);
        $currentHash = $stmt->fetchColumn();
        
        if (!password_verify($currentPassword, $currentHash)) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        $sql = "UPDATE agents SET password = :password WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $user['id']
        ]);
        
        if ($result) {
            $this->logActivity($user['id'], 'password_changed', 'Password updated');
            return ['success' => true, 'message' => 'Password updated successfully'];
        }
        
        return ['success' => false, 'message' => 'Failed to update password'];
    }
    
    public function updateStatus($userId, $status) {
        $sql = "UPDATE agents SET status = :status, last_activity = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':status' => $status, ':id' => $userId]);
    }
    
    private function updateLoginTime($userId) {
        $sql = "UPDATE agents SET login_time = CURRENT_TIMESTAMP, status = 'available', last_activity = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
    }
    
    public function getUsers() {
        if (!$this->isAdmin()) {
            return [];
        }
        
        $sql = "SELECT id, username, extension, name, role, status, login_time, last_activity FROM agents ORDER BY username";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
    
    public function deleteUser($userId) {
        if (!$this->isAdmin() || $userId == $_SESSION['user_id']) {
            return ['success' => false, 'message' => 'Cannot delete this user'];
        }
        
        $sql = "DELETE FROM agents WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([':id' => $userId]);
        
        if ($result) {
            $this->logActivity($_SESSION['user_id'], 'user_deleted', 'Deleted user ID: ' . $userId);
            return ['success' => true, 'message' => 'User deleted successfully'];
        }
        
        return ['success' => false, 'message' => 'Failed to delete user'];
    }
    
    private function logActivity($userId, $action, $description, $ip = null) {
        $ip = $ip ?: ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        $sql = "INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':description' => $description,
            ':ip' => $ip
        ]);
    }
    
    public function getActivityLogs($limit = 100, $userId = null) {
        if (!$this->isAdmin()) {
            return [];
        }
        
        $sql = "SELECT al.*, a.username FROM activity_logs al 
                LEFT JOIN agents a ON al.user_id = a.id 
                WHERE 1=1";
        
        $params = [];
        
        if ($userId) {
            $sql .= " AND al.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public function rateLimitCheck($action, $maxAttempts = 5, $timeWindow = 300) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = $action . '_' . $ip;
        
        if (!isset($_SESSION['rate_limits'])) {
            $_SESSION['rate_limits'] = [];
        }
        
        $now = time();
        
        if (!isset($_SESSION['rate_limits'][$key])) {
            $_SESSION['rate_limits'][$key] = ['count' => 1, 'first_attempt' => $now];
            return true;
        }
        
        $rateLimit = $_SESSION['rate_limits'][$key];
        
        if (($now - $rateLimit['first_attempt']) > $timeWindow) {
            $_SESSION['rate_limits'][$key] = ['count' => 1, 'first_attempt' => $now];
            return true;
        }
        
        if ($rateLimit['count'] >= $maxAttempts) {
            return false;
        }
        
        $_SESSION['rate_limits'][$key]['count']++;
        return true;
    }
}