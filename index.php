<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/ErrorHandler.php';
require_once __DIR__ . '/classes/Auth.php';

// Initialize error handling
ErrorHandler::init();

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$page = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asterisk Auto-Dialer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="?page=dashboard">
                <i class="fas fa-phone"></i> Asterisk Dialer
            </a>
            <div class="navbar-nav">
                <a class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>" href="?page=dashboard">Dashboard</a>
                <a class="nav-link <?php echo $page === 'campaigns' ? 'active' : ''; ?>" href="?page=campaigns">Campaigns</a>
                <a class="nav-link <?php echo $page === 'call-logs' ? 'active' : ''; ?>" href="?page=call-logs">Call Logs</a>
                <a class="nav-link <?php echo $page === 'cdr' ? 'active' : ''; ?>" href="?page=cdr">CDR</a>
                <a class="nav-link <?php echo $page === 'monitoring' ? 'active' : ''; ?>" href="?page=monitoring">Monitoring</a>
            </div>
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($currentUser['name'] ?? $currentUser['username']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="?page=profile"><i class="fas fa-user-cog"></i> Profile</a></li>
                        <?php if ($auth->isAdmin()): ?>
                            <li><a class="dropdown-item" href="?page=admin"><i class="fas fa-cogs"></i> Administration</a></li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php
        switch ($page) {
            case 'campaigns':
                include 'pages/campaigns.php';
                break;
            case 'call-logs':
                include 'pages/call-logs.php';
                break;
            case 'cdr':
                include 'pages/cdr.php';
                break;
            case 'monitoring':
                include 'pages/monitoring.php';
                break;
            case 'profile':
                include 'pages/profile.php';
                break;
            case 'admin':
                if ($auth->isAdmin()) {
                    include 'pages/admin.php';
                } else {
                    echo '<div class="alert alert-danger">Access denied. Administrator privileges required.</div>';
                }
                break;
            default:
                include 'pages/dashboard.php';
                break;
        }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>