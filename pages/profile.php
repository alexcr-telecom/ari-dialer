<?php
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
$currentUser = $auth->getCurrentUser();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'danger';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters long.';
            $messageType = 'danger';
        } else {
            $result = $auth->updatePassword($currentPassword, $newPassword);
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'danger';
        }
    } elseif ($action === 'update_status') {
        $status = $_POST['status'] ?? 'available';
        if ($auth->updateStatus($currentUser['id'], $status)) {
            $message = 'Status updated successfully.';
            $messageType = 'success';
            $currentUser['status'] = $status;
        } else {
            $message = 'Failed to update status.';
            $messageType = 'danger';
        }
    }
}

$activityLogs = $auth->getActivityLogs(20, $currentUser['id']);
?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-user"></i> Profile Information</h5>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Username:</strong></td>
                                <td><?php echo htmlspecialchars($currentUser['username']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Full Name:</strong></td>
                                <td><?php echo htmlspecialchars($currentUser['name'] ?? 'Not set'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Extension:</strong></td>
                                <td><?php echo htmlspecialchars($currentUser['extension']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Role:</strong></td>
                                <td>
                                    <span class="badge bg-<?php echo $currentUser['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                        <?php echo ucfirst($currentUser['role']); ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Current Status:</strong></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="update_status">
                                        <select name="status" class="form-select form-select-sm d-inline w-auto" onchange="this.form.submit()">
                                            <option value="available" <?php echo $currentUser['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                            <option value="busy" <?php echo $currentUser['status'] === 'busy' ? 'selected' : ''; ?>>Busy</option>
                                            <option value="break" <?php echo $currentUser['status'] === 'break' ? 'selected' : ''; ?>>Break</option>
                                            <option value="offline" <?php echo $currentUser['status'] === 'offline' ? 'selected' : ''; ?>>Offline</option>
                                        </select>
                                    </form>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Login Time:</strong></td>
                                <td>
                                    <?php if ($currentUser['login_time']): ?>
                                        <?php echo date('Y-m-d H:i:s', strtotime($currentUser['login_time'])); ?>
                                    <?php else: ?>
                                        Not available
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Last Activity:</strong></td>
                                <td>
                                    <?php if ($currentUser['last_activity']): ?>
                                        <?php echo date('Y-m-d H:i:s', strtotime($currentUser['last_activity'])); ?>
                                    <?php else: ?>
                                        Not available
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card mt-4">
            <div class="card-header">
                <h6><i class="fas fa-key"></i> Change Password</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password" id="current_password" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" id="new_password" required minlength="8">
                                <div class="form-text">Minimum 8 characters</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password" required minlength="8">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Activity Log -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-history"></i> Recent Activity</h6>
            </div>
            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                <?php if (empty($activityLogs)): ?>
                    <div class="text-center text-muted">
                        <i class="fas fa-history fa-2x mb-2"></i>
                        <p>No recent activity</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <div class="border-start border-primary ps-3 mb-3">
                            <div class="fw-bold"><?php echo ucwords(str_replace('_', ' ', $log['action'])); ?></div>
                            <div class="text-muted small"><?php echo htmlspecialchars($log['description']); ?></div>
                            <div class="text-muted small">
                                <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($log['created_at'])); ?>
                            </div>
                            <?php if ($log['ip_address']): ?>
                                <div class="text-muted small">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($log['ip_address']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="card mt-4">
            <div class="card-header">
                <h6><i class="fas fa-chart-pie"></i> My Statistics</h6>
            </div>
            <div class="card-body">
                <?php
                // Get user-specific statistics
                $db = Database::getInstance()->getConnection();
                
                // Today's activity
                $sql = "SELECT 
                            COUNT(*) as actions_today
                        FROM activity_logs 
                        WHERE user_id = :user_id 
                        AND DATE(created_at) = CURDATE()";
                $stmt = $db->prepare($sql);
                $stmt->execute([':user_id' => $currentUser['id']]);
                $todayStats = $stmt->fetch();
                
                // Session duration
                $sessionMinutes = 0;
                if ($currentUser['login_time']) {
                    $sessionMinutes = floor((time() - strtotime($currentUser['login_time'])) / 60);
                }
                ?>
                
                <div class="text-center">
                    <div class="row">
                        <div class="col-6">
                            <h4 class="text-primary"><?php echo $todayStats['actions_today'] ?? 0; ?></h4>
                            <small>Actions Today</small>
                        </div>
                        <div class="col-6">
                            <h4 class="text-success"><?php echo floor($sessionMinutes / 60) . 'h ' . ($sessionMinutes % 60) . 'm'; ?></h4>
                            <small>Session Time</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    
    if (confirmPassword && newPassword !== confirmPassword) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

document.getElementById('new_password').addEventListener('input', function() {
    const confirmPassword = document.getElementById('confirm_password');
    if (confirmPassword.value && this.value !== confirmPassword.value) {
        confirmPassword.setCustomValidity('Passwords do not match');
    } else {
        confirmPassword.setCustomValidity('');
    }
});
</script>