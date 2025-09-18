<?php
/**
 * Call Logs Page
 * Displays detailed call logs with filtering and real-time status
 */

// Get campaigns for filter dropdown
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $stmt = $db->prepare("SELECT id, name FROM campaigns ORDER BY name");
    $stmt->execute();
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $campaigns = [];
    error_log("Error loading campaigns: " . $e->getMessage());
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-phone-alt"></i> Call Logs</h2>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" onclick="refreshLogs()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <button class="btn btn-success" onclick="exportLogs()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-filter"></i> Filters</h5>
                </div>
                <div class="card-body">
                    <form id="filterForm" class="row g-3">
                        <div class="col-md-3">
                            <label for="campaignFilter" class="form-label">Campaign</label>
                            <select class="form-select" id="campaignFilter" name="campaign_id">
                                <option value="">All Campaigns</option>
                                <?php foreach ($campaigns as $campaign): ?>
                                    <option value="<?= $campaign['id'] ?>"><?= htmlspecialchars($campaign['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="statusFilter" class="form-label">Status</label>
                            <select class="form-select" id="statusFilter" name="status">
                                <option value="">All Status</option>
                                <option value="initiated">Initiated</option>
                                <option value="ringing">Ringing</option>
                                <option value="answered">Answered</option>
                                <option value="failed">Failed</option>
                                <option value="hung_up">Hung Up</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="phoneFilter" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phoneFilter" name="phone_number"
                                   placeholder="Search number...">
                        </div>
                        <div class="col-md-2">
                            <label for="dateFrom" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="dateFrom" name="date_from">
                        </div>
                        <div class="col-md-2">
                            <label for="dateTo" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="dateTo" name="date_to"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4" id="statsCards">
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5>Total Calls</h5>
                                    <h3 id="totalCalls">-</h3>
                                </div>
                                <i class="fas fa-phone fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5>Answered</h5>
                                    <h3 id="answeredCalls">-</h3>
                                </div>
                                <i class="fas fa-check fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-danger">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5>Failed</h5>
                                    <h3 id="failedCalls">-</h3>
                                </div>
                                <i class="fas fa-times fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5>Avg Duration</h5>
                                    <h3 id="avgDuration">-</h3>
                                </div>
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Call Logs Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5><i class="fas fa-list"></i> Recent Calls</h5>
                    <div class="d-flex gap-2">
                        <span class="badge bg-info" id="autoRefreshStatus">Auto-refresh: ON</span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAutoRefresh()">
                            <i class="fas fa-pause"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="callLogsTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Campaign</th>
                                    <th>Phone Number</th>
                                    <th>Lead Name</th>
                                    <th>Agent Ext</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Response</th>
                                    <th>Channel ID</th>
                                </tr>
                            </thead>
                            <tbody id="callLogsBody">
                                <tr>
                                    <td colspan="9" class="text-center">
                                        <div class="spinner-border" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <nav aria-label="Call logs pagination" id="paginationNav" class="mt-3" style="display: none;">
                        <ul class="pagination justify-content-center" id="pagination">
                            <!-- Pagination will be generated by JavaScript -->
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentPage = 0;
let itemsPerPage = 50;
let autoRefresh = true;
let autoRefreshInterval;

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadCallLogs();
    loadStats();
    startAutoRefresh();

    // Set default date to today
    document.getElementById('dateFrom').value = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
});

// Filter form submission
document.getElementById('filterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    currentPage = 0;
    loadCallLogs();
    loadStats();
});

function loadCallLogs() {
    const formData = new FormData(document.getElementById('filterForm'));
    const params = new URLSearchParams(formData);
    params.append('limit', itemsPerPage);
    params.append('offset', currentPage * itemsPerPage);

    fetch(`api/call-logs.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderCallLogs(data.data);
            } else {
                console.error('Error loading call logs:', data.error);
                showAlert('Error loading call logs: ' + data.error, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('Network error loading call logs', 'danger');
        });
}

function loadStats() {
    const campaignId = document.getElementById('campaignFilter').value;
    const params = new URLSearchParams({ stats: '1' });
    if (campaignId) params.append('campaign_id', campaignId);

    fetch(`api/call-logs.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderStats(data.data);
            }
        })
        .catch(error => console.error('Stats error:', error));
}

function renderCallLogs(logs) {
    const tbody = document.getElementById('callLogsBody');

    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No call logs found</td></tr>';
        return;
    }

    tbody.innerHTML = logs.map(log => `
        <tr class="call-row" data-status="${log.status}">
            <td>
                <div class="fw-bold">${formatDateTime(log.call_start || log.created_at)}</div>
                <small class="text-muted">${formatTime(log.call_start || log.created_at)}</small>
            </td>
            <td>
                <span class="badge bg-secondary">${escapeHtml(log.campaign_name || 'Unknown')}</span>
            </td>
            <td>
                <div class="fw-bold">${escapeHtml(log.phone_number)}</div>
                ${log.lead_name ? `<small class="text-muted">${escapeHtml(log.lead_name)}</small>` : ''}
            </td>
            <td>${escapeHtml(log.lead_name || '-')}</td>
            <td>
                <span class="badge bg-info">${escapeHtml(log.agent_extension || '-')}</span>
            </td>
            <td>${renderStatus(log.status)}</td>
            <td>${formatDuration(log.duration)}</td>
            <td>
                <span class="badge ${getDispositionClass(log.disposition)}">
                    ${escapeHtml(log.disposition || 'No Response')}
                </span>
            </td>
            <td>
                <small class="text-muted font-monospace">${escapeHtml(log.channel_id || '-')}</small>
            </td>
        </tr>
    `).join('');
}

function renderStats(stats) {
    let totalCalls = 0, answeredCalls = 0, failedCalls = 0, totalDuration = 0;

    stats.forEach(stat => {
        totalCalls += parseInt(stat.total_calls);
        answeredCalls += parseInt(stat.answered_calls);
        failedCalls += parseInt(stat.failed_calls);
        totalDuration += parseFloat(stat.avg_duration || 0);
    });

    const avgDuration = stats.length > 0 ? totalDuration / stats.length : 0;

    document.getElementById('totalCalls').textContent = totalCalls;
    document.getElementById('answeredCalls').textContent = answeredCalls;
    document.getElementById('failedCalls').textContent = failedCalls;
    document.getElementById('avgDuration').textContent = formatDuration(avgDuration);
}

function renderStatus(status) {
    const statusMap = {
        'initiated': '<span class="badge bg-warning">Initiated</span>',
        'ringing': '<span class="badge bg-info">Ringing</span>',
        'answered': '<span class="badge bg-success">Answered</span>',
        'failed': '<span class="badge bg-danger">Failed</span>',
        'hung_up': '<span class="badge bg-secondary">Hung Up</span>'
    };
    return statusMap[status] || `<span class="badge bg-light text-dark">${escapeHtml(status)}</span>`;
}

function getDispositionClass(disposition) {
    if (!disposition) return 'bg-light text-dark';

    const lowerDisp = disposition.toLowerCase();
    if (lowerDisp.includes('answer')) return 'bg-success';
    if (lowerDisp.includes('busy')) return 'bg-warning';
    if (lowerDisp.includes('fail') || lowerDisp.includes('error')) return 'bg-danger';
    return 'bg-secondary';
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString();
}

function formatTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleTimeString();
}

function formatDuration(seconds) {
    if (!seconds || seconds === 0) return '-';

    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hrs > 0) {
        return `${hrs}h ${mins}m ${secs}s`;
    } else if (mins > 0) {
        return `${mins}m ${secs}s`;
    } else {
        return `${secs}s`;
    }
}

function refreshLogs() {
    loadCallLogs();
    loadStats();
    showAlert('Call logs refreshed', 'success');
}

function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);

    autoRefreshInterval = setInterval(() => {
        if (autoRefresh) {
            loadCallLogs();
            loadStats();
        }
    }, 10000); // Refresh every 10 seconds
}

function toggleAutoRefresh() {
    autoRefresh = !autoRefresh;
    const status = document.getElementById('autoRefreshStatus');
    const button = status.nextElementSibling;

    if (autoRefresh) {
        status.textContent = 'Auto-refresh: ON';
        status.className = 'badge bg-info';
        button.innerHTML = '<i class="fas fa-pause"></i>';
    } else {
        status.textContent = 'Auto-refresh: OFF';
        status.className = 'badge bg-secondary';
        button.innerHTML = '<i class="fas fa-play"></i>';
    }
}

function exportLogs() {
    const formData = new FormData(document.getElementById('filterForm'));
    const params = new URLSearchParams(formData);
    params.append('export', '1');

    // Create download link
    const link = document.createElement('a');
    link.href = `api/call-logs.php?${params}`;
    link.download = `call-logs-${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function showAlert(message, type = 'info') {
    // Create alert element
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 1050; max-width: 300px;';
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(alert);

    // Auto remove after 3 seconds
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 3000);
}
</script>

<style>
.call-row[data-status="answered"] {
    background-color: rgba(25, 135, 84, 0.1);
}
.call-row[data-status="failed"] {
    background-color: rgba(220, 53, 69, 0.1);
}
.call-row[data-status="initiated"] {
    background-color: rgba(255, 193, 7, 0.1);
}

.font-monospace {
    font-family: 'Courier New', Courier, monospace !important;
    font-size: 0.8em;
}

.table-responsive {
    max-height: 600px;
    overflow-y: auto;
}

.card-header .badge {
    font-size: 0.8em;
}
</style>