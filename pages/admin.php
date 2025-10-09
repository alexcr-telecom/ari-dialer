<?php
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
$auth->requireAdmin();

$tab = $_GET['tab'] ?? 'users';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_user':
            if (!empty($_POST['username']) && !empty($_POST['password']) && !empty($_POST['extension'])) {
                $result = $auth->createUser($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'danger';
            } else {
                $message = 'All fields are required.';
                $messageType = 'danger';
            }
            break;
            
        case 'delete_user':
            if (!empty($_POST['user_id'])) {
                $result = $auth->deleteUser($_POST['user_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'danger';
            }
            break;
            
        case 'update_settings':
            if (!empty($_POST['settings'])) {
                $db = Database::getInstance()->getConnection();
                $updated = 0;
                
                foreach ($_POST['settings'] as $key => $value) {
                    $sql = "UPDATE settings SET setting_value = :value WHERE setting_key = :key";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([':value' => $value, ':key' => $key])) {
                        $updated++;
                    }
                }
                
                if ($updated > 0) {
                    $message = "$updated settings updated successfully.";
                    $messageType = 'success';
                } else {
                    $message = 'No settings were updated.';
                    $messageType = 'warning';
                }
            }
            break;
    }
}

$users = $auth->getUsers();
$activityLogs = $auth->getActivityLogs(50);

// Get system statistics
$db = Database::getInstance()->getConnection();

$systemStats = [];
$sql = "SELECT
            (SELECT COUNT(*) FROM agents) as total_users,
            (SELECT COUNT(*) FROM agents WHERE status = 'available') as online_users,
            (SELECT COUNT(*) FROM campaigns) as total_campaigns,
            (SELECT COUNT(*) FROM campaigns WHERE status = 'active') as active_campaigns,
            (SELECT COUNT(*) FROM leads) as total_leads,
            (SELECT COUNT(*) FROM dialer_cdr WHERE DATE(call_start) = CURDATE()) as calls_today";
$stmt = $db->query($sql);
$systemStats = $stmt->fetch();

// Get settings
$sql = "SELECT setting_key, setting_value FROM settings ORDER BY setting_key";
$stmt = $db->query($sql);
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-cogs"></i> System Administration</h2>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Navigation Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'users' ? 'active' : ''; ?>" href="?page=admin&tab=users">
            <i class="fas fa-users"></i> User Management
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'settings' ? 'active' : ''; ?>" href="?page=admin&tab=settings">
            <i class="fas fa-sliders-h"></i> System Settings
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'logs' ? 'active' : ''; ?>" href="?page=admin&tab=logs">
            <i class="fas fa-list-alt"></i> Activity Logs
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'stats' ? 'active' : ''; ?>" href="?page=admin&tab=stats">
            <i class="fas fa-chart-bar"></i> System Stats
        </a>
    </li>
</ul>

<!-- System Statistics Overview -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-primary"><?php echo $systemStats['total_users']; ?></h4>
                <small>Total Users</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-success"><?php echo $systemStats['online_users']; ?></h4>
                <small>Online Now</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-info"><?php echo $systemStats['total_campaigns']; ?></h4>
                <small>Campaigns</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-warning"><?php echo $systemStats['active_campaigns']; ?></h4>
                <small>Active</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-primary"><?php echo number_format($systemStats['total_leads']); ?></h4>
                <small>Total Leads</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-success"><?php echo number_format($systemStats['calls_today']); ?></h4>
                <small>Calls Today</small>
            </div>
        </div>
    </div>
</div>

<?php if ($tab === 'users'): ?>
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-users"></i> User Management</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Name</th>
                                    <th>Extension</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($user['extension']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $user['status'] === 'available' ? 'success' : 
                                                ($user['status'] === 'busy' ? 'warning' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['login_time']): ?>
                                            <?php echo date('M j, H:i', strtotime($user['login_time'])); ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user?')">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-user-plus"></i> Create New User</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_user">
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name" id="name">
                        </div>
                        
                        <div class="mb-3">
                            <label for="extension" class="form-label">Extension</label>
                            <input type="text" class="form-control" name="extension" id="extension" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="password" required minlength="8">
                        </div>
                        
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" name="role" id="role">
                                <option value="agent">Agent</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create User
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'settings'): ?>
    <div class="card">
        <div class="card-header">
            <h6><i class="fas fa-sliders-h"></i> System Settings</h6>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="row">
                    <?php foreach ($settings as $key => $value): ?>
                        <div class="col-md-6 mb-3">
                            <label for="<?php echo $key; ?>" class="form-label">
                                <?php echo ucwords(str_replace('_', ' ', $key)); ?>
                            </label>
                            <?php if (strpos($key, 'enable_') === 0 || in_array($key, ['enable_recording', 'enable_audit_log'])): ?>
                                <select class="form-select" name="settings[<?php echo $key; ?>]" id="<?php echo $key; ?>">
                                    <option value="1" <?php echo $value == '1' ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="0" <?php echo $value == '0' ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control" name="settings[<?php echo $key; ?>]" 
                                       id="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Settings
                </button>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'logs'): ?>
    <div class="card">
        <div class="card-header">
            <h6><i class="fas fa-list-alt"></i> Activity Logs</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                <table class="table table-sm">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activityLogs as $log): ?>
                        <tr>
                            <td><?php echo date('M j, H:i:s', strtotime($log['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo strpos($log['action'], 'login') !== false ? 'success' : 
                                        (strpos($log['action'], 'failed') !== false ? 'danger' : 'primary'); 
                                ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $log['action'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'stats'): ?>
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-chart-pie"></i> User Status Distribution</h6>
                </div>
                <div class="card-body">
                    <canvas id="userStatusChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-chart-bar"></i> Campaign Status</h6>
                </div>
                <div class="card-body">
                    <canvas id="campaignStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mt-4">
        <div class="card-header">
            <h6><i class="fas fa-server"></i> System Information</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>PHP Version:</strong></td>
                            <td><?php echo phpversion(); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Server Time:</strong></td>
                            <td><?php echo date('Y-m-d H:i:s T'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Timezone:</strong></td>
                            <td><?php echo date_default_timezone_get(); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Memory Usage:</strong></td>
                            <td><?php echo round(memory_get_usage() / 1024 / 1024, 2); ?> MB</td>
                        </tr>
                        <tr>
                            <td><strong>Memory Limit:</strong></td>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Max Execution Time:</strong></td>
                            <td><?php echo ini_get('max_execution_time'); ?>s</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'stats'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // User Status Chart
    <?php
    $sql = "SELECT status, COUNT(*) as count FROM agents GROUP BY status";
    $stmt = $db->query($sql);
    $userStatusData = [];
    while ($row = $stmt->fetch()) {
        $userStatusData[$row['status']] = $row['count'];
    }
    ?>
    
    const userStatusChart = new Chart(document.getElementById('userStatusChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_keys($userStatusData)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_values($userStatusData)); ?>,
                backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    
    // Campaign Status Chart
    <?php
    $sql = "SELECT status, COUNT(*) as count FROM campaigns GROUP BY status";
    $stmt = $db->query($sql);
    $campaignStatusData = [];
    while ($row = $stmt->fetch()) {
        $campaignStatusData[$row['status']] = $row['count'];
    }
    ?>
    
    const campaignStatusChart = new Chart(document.getElementById('campaignStatusChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($campaignStatusData)); ?>,
            datasets: [{
                label: 'Campaigns',
                data: <?php echo json_encode(array_values($campaignStatusData)); ?>,
                backgroundColor: ['#17a2b8', '#28a745', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
});
</script>
<?php endif; ?>