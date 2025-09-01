<?php
require_once __DIR__ . '/../classes/CDR.php';

$cdr = new CDR();

$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'phone_number' => $_GET['phone_number'] ?? '',
    'campaign_id' => $_GET['campaign_id'] ?? '',
    'disposition' => $_GET['disposition'] ?? '',
    'agent_extension' => $_GET['agent_extension'] ?? '',
    'min_duration' => $_GET['min_duration'] ?? '',
    'max_duration' => $_GET['max_duration'] ?? ''
];

$filters = array_filter($filters, function($value) { return $value !== ''; });

$page = max(1, (int)($_GET['page_num'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export = $cdr->exportToCSV($filters);
    if ($export['success']) {
        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        header('Content-Length: ' . filesize($export['filepath']));
        readfile($export['filepath']);
        unlink($export['filepath']);
        exit;
    }
}

$records = $cdr->getCallRecords($filters, $limit, $offset);
$totalRecords = $cdr->getCallRecordCount($filters);
$totalPages = ceil($totalRecords / $limit);
$stats = $cdr->getStatistics($filters);

$dispositions = $cdr->getDispositions();
$agents = $cdr->getAgentExtensions();
$campaigns = $cdr->getCampaigns();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-list-alt"></i> Call Detail Records</h2>
    <div class="btn-group">
        <button class="btn btn-outline-primary" onclick="showCharts()">
            <i class="fas fa-chart-bar"></i> Charts
        </button>
        <a href="?page=cdr&export=csv&<?php echo http_build_query($filters); ?>" class="btn btn-success">
            <i class="fas fa-download"></i> Export CSV
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<?php if ($stats): ?>
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-primary"><?php echo number_format($stats['total_calls']); ?></h4>
                <small>Total Calls</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-success"><?php echo number_format($stats['answered_calls']); ?></h4>
                <small>Answered</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-warning"><?php echo number_format($stats['busy_calls']); ?></h4>
                <small>Busy</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-info"><?php echo number_format($stats['no_answer_calls']); ?></h4>
                <small>No Answer</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-success"><?php echo $stats['answer_rate']; ?>%</h4>
                <small>Answer Rate</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stats-card text-center">
            <div class="card-body">
                <h4 class="text-primary"><?php echo gmdate("H:i:s", $stats['avg_duration'] ?? 0); ?></h4>
                <small>Avg Duration</small>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <h6><i class="fas fa-filter"></i> Filters</h6>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="cdr">
            
            <div class="col-md-3">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo $_GET['date_from'] ?? ''; ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo $_GET['date_to'] ?? ''; ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Phone Number</label>
                <input type="text" class="form-control" name="phone_number" value="<?php echo htmlspecialchars($_GET['phone_number'] ?? ''); ?>" placeholder="Search phone number...">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Campaign</label>
                <select class="form-select" name="campaign_id">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $campaign): ?>
                        <option value="<?php echo $campaign['id']; ?>" <?php echo ($_GET['campaign_id'] ?? '') == $campaign['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($campaign['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Disposition</label>
                <select class="form-select" name="disposition">
                    <option value="">All Dispositions</option>
                    <?php foreach ($dispositions as $disposition): ?>
                        <option value="<?php echo $disposition; ?>" <?php echo ($_GET['disposition'] ?? '') === $disposition ? 'selected' : ''; ?>>
                            <?php echo $disposition; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Agent Extension</label>
                <select class="form-select" name="agent_extension">
                    <option value="">All Agents</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo $agent; ?>" <?php echo ($_GET['agent_extension'] ?? '') === $agent ? 'selected' : ''; ?>>
                            <?php echo $agent; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Min Duration (sec)</label>
                <input type="number" class="form-control" name="min_duration" value="<?php echo $_GET['min_duration'] ?? ''; ?>" min="0">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Max Duration (sec)</label>
                <input type="number" class="form-control" name="max_duration" value="<?php echo $_GET['max_duration'] ?? ''; ?>" min="0">
            </div>
            
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                <a href="?page=cdr" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6>Call Records (<?php echo number_format($totalRecords); ?> total)</h6>
        <small>Page <?php echo $page; ?> of <?php echo $totalPages; ?></small>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Campaign</th>
                        <th>Phone Number</th>
                        <th>Lead Name</th>
                        <th>Agent</th>
                        <th>Call Start</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Disposition</th>
                        <th>Recording</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" class="text-center">No records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['campaign_name'] ?? '-'); ?></td>
                            <td>
                                <a href="?page=cdr&phone_number=<?php echo urlencode($record['phone_number']); ?>">
                                    <?php echo htmlspecialchars($record['phone_number']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($record['lead_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($record['agent_extension'] ?? '-'); ?></td>
                            <td>
                                <?php if ($record['call_start']): ?>
                                    <span title="<?php echo $record['call_start']; ?>">
                                        <?php echo date('M j, H:i', strtotime($record['call_start'])); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['duration'] > 0): ?>
                                    <?php echo gmdate("H:i:s", $record['duration']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="call-status <?php echo strtolower($record['status']); ?>">
                                    <?php echo ucfirst($record['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($record['disposition']): ?>
                                    <span class="badge bg-<?php 
                                        echo $record['disposition'] === 'ANSWERED' ? 'success' : 
                                            ($record['disposition'] === 'BUSY' ? 'warning' : 
                                            ($record['disposition'] === 'NO ANSWER' ? 'info' : 'secondary')); 
                                    ?>">
                                        <?php echo $record['disposition']; ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['recording_file']): ?>
                                    <a href="recordings/<?php echo $record['recording_file']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-play"></i>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="CDR pagination">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page_num' => $page - 1])); ?>">Previous</a>
                </li>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page_num' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page_num' => $page + 1])); ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Charts Modal -->
<div class="modal fade" id="chartsModal" tabindex="-1" aria-labelledby="chartsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="chartsModalLabel">Call Analytics</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <canvas id="dispositionChart"></canvas>
                    </div>
                    <div class="col-md-6">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showCharts() {
    loadChartData();
    new bootstrap.Modal(document.getElementById('chartsModal')).show();
}

function loadChartData() {
    const filters = <?php echo json_encode($filters); ?>;
    
    fetch('api/charts.php?' + new URLSearchParams(filters))
        .then(response => response.json())
        .then(data => {
            createDispositionChart(data.dispositions);
            createHourlyChart(data.hourly);
            createDailyChart(data.daily);
        });
}

function createDispositionChart(data) {
    const ctx = document.getElementById('dispositionChart').getContext('2d');
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: Object.keys(data),
            datasets: [{
                data: Object.values(data),
                backgroundColor: ['#28a745', '#ffc107', '#17a2b8', '#dc3545', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: { display: true, text: 'Call Dispositions' }
            }
        }
    });
}

function createHourlyChart(data) {
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.hour + ':00'),
            datasets: [{
                label: 'Total Calls',
                data: data.map(d => d.call_count),
                backgroundColor: '#007bff'
            }, {
                label: 'Answered',
                data: data.map(d => d.answered_count),
                backgroundColor: '#28a745'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: { display: true, text: 'Calls by Hour' }
            }
        }
    });
}

function createDailyChart(data) {
    const ctx = document.getElementById('dailyChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.call_date),
            datasets: [{
                label: 'Total Calls',
                data: data.map(d => d.call_count),
                borderColor: '#007bff',
                fill: false
            }, {
                label: 'Answered',
                data: data.map(d => d.answered_count),
                borderColor: '#28a745',
                fill: false
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: { display: true, text: 'Daily Call Volume' }
            }
        }
    });
}
</script>