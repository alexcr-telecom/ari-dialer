<?php
require_once __DIR__ . '/../classes/Campaign.php';
require_once __DIR__ . '/../classes/CDR.php';
require_once __DIR__ . '/../classes/ARI.php';
require_once __DIR__ . '/../config/config.php';

$campaign = new Campaign();
$cdr = new CDR();
$ari = new ARI();

$dashboardStats = [
    'campaigns' => $campaign->getStats(),
    'calls' => $cdr->getStatistics(['date_from' => date('Y-m-d')]),
    'recent_calls' => $cdr->getCallRecords([], 10),
    'top_campaigns' => $cdr->getTopPerformingCampaigns(5),
    'calls_by_hour' => $cdr->getCallsByHour(['date_from' => date('Y-m-d')]),
    'calls_by_date' => $cdr->getCallsByDate([], 7)
];

$ariConnection = $ari->testConnection();
$databaseStatus = Config::getDatabaseStatus();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
    </div>
</div>

<!-- System Status -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-<?php echo $ariConnection['success'] ? 'success' : 'danger'; ?>">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="fas fa-server fa-2x text-<?php echo $ariConnection['success'] ? 'success' : 'danger'; ?>"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Asterisk ARI Status</h6>
                        <p class="mb-0 text-<?php echo $ariConnection['success'] ? 'success' : 'danger'; ?>">
                            <?php echo $ariConnection['message']; ?>
                        </p>
                        <?php if ($ariConnection['success'] && isset($ariConnection['data']['system'])): ?>
                            <small class="text-muted">
                                Build: <?php echo $ariConnection['data']['build']['date'] ?? 'Unknown'; ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-<?php echo ($databaseStatus['asterisk']['status'] == 'success' && $databaseStatus['cdr']['status'] == 'success') ? 'success' : 'danger'; ?>">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="fas fa-database fa-2x text-<?php echo ($databaseStatus['asterisk']['status'] == 'success' && $databaseStatus['cdr']['status'] == 'success') ? 'success' : 'danger'; ?>"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Database Status</h6>
                        <p class="mb-0">
                            <small class="text-<?php echo $databaseStatus['asterisk']['status'] == 'success' ? 'success' : 'danger'; ?>">
                                Asterisk: <?php echo $databaseStatus['asterisk']['status'] == 'success' ? 'OK' : 'Failed'; ?>
                            </small><br>
                            <small class="text-<?php echo $databaseStatus['cdr']['status'] == 'success' ? 'success' : 'danger'; ?>">
                                CDR: <?php echo $databaseStatus['cdr']['status'] == 'success' ? 'OK' : 'Failed'; ?>
                            </small>
                        </p>
                        <?php if ($databaseStatus['asterisk']['status'] != 'success' || $databaseStatus['cdr']['status'] != 'success'): ?>
                            <small class="text-danger">Check database configuration</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="fas fa-clock fa-2x text-primary"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">System Time</h6>
                        <p class="mb-0" id="currentTime"><?php echo date('Y-m-d H:i:s'); ?></p>
                        <small class="text-muted">Server time (<?php echo date('T'); ?>)</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Key Metrics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <i class="fas fa-bullhorn fa-2x text-primary mb-2"></i>
                <h4 class="text-primary"><?php echo $dashboardStats['campaigns']['total_campaigns'] ?? 0; ?></h4>
                <h6>Total Campaigns</h6>
                <small class="text-success">
                    <?php echo $dashboardStats['campaigns']['active_campaigns'] ?? 0; ?> Active
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <i class="fas fa-phone fa-2x text-success mb-2"></i>
                <h4 class="text-success"><?php echo number_format($dashboardStats['calls']['total_calls'] ?? 0); ?></h4>
                <h6>Calls Today</h6>
                <small class="text-success">
                    <?php echo $dashboardStats['calls']['answer_rate'] ?? 0; ?>% Answer Rate
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <i class="fas fa-users fa-2x text-info mb-2"></i>
                <h4 class="text-info"><?php echo number_format($dashboardStats['campaigns']['total_leads'] ?? 0); ?></h4>
                <h6>Total Leads</h6>
                <small class="text-warning">
                    <?php echo number_format($dashboardStats['campaigns']['answered_leads'] ?? 0); ?> Contacted
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card text-center">
            <div class="card-body">
                <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                <h4 class="text-warning"><?php echo $dashboardStats['campaigns']['avg_success_rate'] ?? 0; ?>%</h4>
                <h6>Avg Success Rate</h6>
                <small class="text-info">
                    Across all campaigns
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-chart-bar"></i> Calls by Hour (Today)</h6>
            </div>
            <div class="card-body">
                <canvas id="hourlyCallsChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-chart-line"></i> Daily Call Volume (7 days)</h6>
            </div>
            <div class="card-body">
                <canvas id="dailyCallsChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity & Top Campaigns -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6><i class="fas fa-history"></i> Recent Calls</h6>
                <a href="?page=cdr" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($dashboardStats['recent_calls'])): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-phone-slash fa-2x mb-2"></i>
                        <p>No recent calls</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Number</th>
                                    <th>Campaign</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dashboardStats['recent_calls'] as $call): ?>
                                <tr>
                                    <td>
                                        <?php if ($call['call_start']): ?>
                                            <?php echo date('H:i:s', strtotime($call['call_start'])); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($call['phone_number']); ?></td>
                                    <td>
                                        <small><?php echo htmlspecialchars($call['campaign_name'] ?? 'Unknown'); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($call['duration'] > 0): ?>
                                            <?php echo gmdate("i:s", $call['duration']); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($call['disposition']): ?>
                                            <span class="badge bg-<?php 
                                                echo $call['disposition'] === 'ANSWERED' ? 'success' : 
                                                    ($call['disposition'] === 'BUSY' ? 'warning' : 
                                                    ($call['disposition'] === 'NO ANSWER' ? 'info' : 'secondary')); 
                                            ?>">
                                                <?php echo $call['disposition']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6><i class="fas fa-trophy"></i> Top Campaigns</h6>
                <a href="?page=campaigns" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($dashboardStats['top_campaigns'])): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-chart-bar fa-2x mb-2"></i>
                        <p>No campaign data</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($dashboardStats['top_campaigns'] as $index => $topCamp): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($topCamp['name']); ?></div>
                            <small class="text-muted">
                                <?php echo $topCamp['total_calls']; ?> calls, 
                                <?php echo $topCamp['answered_calls']; ?> answered
                            </small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success"><?php echo $topCamp['success_rate']; ?>%</div>
                            <small class="text-muted">success</small>
                        </div>
                    </div>
                    <?php if ($index < count($dashboardStats['top_campaigns']) - 1): ?>
                        <hr class="my-2">
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card mt-4">
            <div class="card-header">
                <h6><i class="fas fa-bolt"></i> Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="?page=campaigns&action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Campaign
                    </a>
                    <a href="?page=monitoring" class="btn btn-success">
                        <i class="fas fa-eye"></i> Live Monitoring
                    </a>
                    <a href="?page=cdr&date_from=<?php echo date('Y-m-d'); ?>" class="btn btn-info">
                        <i class="fas fa-list-alt"></i> Today's CDR
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update current time
function updateCurrentTime() {
    const now = new Date();
    document.getElementById('currentTime').textContent = now.toLocaleString();
}

setInterval(updateCurrentTime, 1000);

// Charts
document.addEventListener('DOMContentLoaded', function() {
    // Hourly Calls Chart
    const hourlyData = <?php echo json_encode($dashboardStats['calls_by_hour']); ?>;
    const hourlyChart = new Chart(document.getElementById('hourlyCallsChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: Array.from({length: 24}, (_, i) => i + ':00'),
            datasets: [{
                label: 'Total Calls',
                data: Array.from({length: 24}, (_, hour) => {
                    const found = hourlyData.find(d => parseInt(d.hour) === hour);
                    return found ? found.call_count : 0;
                }),
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }, {
                label: 'Answered',
                data: Array.from({length: 24}, (_, hour) => {
                    const found = hourlyData.find(d => parseInt(d.hour) === hour);
                    return found ? found.answered_count : 0;
                }),
                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
    
    // Daily Calls Chart
    const dailyData = <?php echo json_encode($dashboardStats['calls_by_date']); ?>;
    const dailyChart = new Chart(document.getElementById('dailyCallsChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: dailyData.map(d => new Date(d.call_date).toLocaleDateString()),
            datasets: [{
                label: 'Total Calls',
                data: dailyData.map(d => d.call_count),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            }, {
                label: 'Answered',
                data: dailyData.map(d => d.answered_count),
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
});
</script>