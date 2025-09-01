<?php
require_once __DIR__ . '/../classes/Dialer.php';
require_once __DIR__ . '/../classes/ARI.php';
require_once __DIR__ . '/../classes/Campaign.php';

$dialer = new Dialer();
$ari = new ARI();
$campaign = new Campaign();

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'hangup' && !empty($_POST['channel_id'])) {
        $result = $dialer->hangupCall($_POST['channel_id']);
        echo json_encode($result);
        exit;
    }
}

$activeChannels = $dialer->getActiveChannels();
$activeCampaigns = $campaign->getAll(['status' => 'active']);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-eye"></i> Real-time Monitoring</h2>
    <div class="btn-group">
        <button class="btn btn-outline-primary" id="connectBtn" onclick="toggleConnection()">
            <i class="fas fa-wifi"></i> Connect
        </button>
        <button class="btn btn-outline-secondary" onclick="refreshData()">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<!-- Connection Status -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-auto">
                <div id="connectionStatus" class="badge bg-secondary">
                    <i class="fas fa-circle"></i> Disconnected
                </div>
            </div>
            <div class="col">
                <span id="connectionInfo">Click "Connect" to start real-time monitoring</span>
            </div>
            <div class="col-auto">
                <small id="lastUpdate" class="text-muted"></small>
            </div>
        </div>
    </div>
</div>

<!-- Active Campaigns -->
<?php if (!empty($activeCampaigns)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h6><i class="fas fa-bullhorn"></i> Active Campaigns</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($activeCampaigns as $camp): ?>
            <div class="col-md-4 mb-3">
                <div class="card border-success">
                    <div class="card-body">
                        <h6 class="card-title"><?php echo htmlspecialchars($camp['name']); ?></h6>
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="text-primary"><?php echo $camp['total_leads']; ?></div>
                                <small>Total</small>
                            </div>
                            <div class="col-4">
                                <div class="text-warning"><?php echo $camp['pending_leads']; ?></div>
                                <small>Pending</small>
                            </div>
                            <div class="col-4">
                                <div class="text-success"><?php echo $camp['answered_leads']; ?></div>
                                <small>Answered</small>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="progress" style="height: 5px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $camp['success_rate']; ?>%"></div>
                            </div>
                            <small><?php echo $camp['success_rate']; ?>% success rate</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Live Calls -->
<div class="card">
    <div class="card-header monitoring-live">
        <h6><i class="fas fa-phone"></i> Active Calls</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="activeCallsTable">
                <thead class="table-dark">
                    <tr>
                        <th>Channel ID</th>
                        <th>Status</th>
                        <th>Caller</th>
                        <th>Callee</th>
                        <th>Duration</th>
                        <th>Campaign</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activeChannels)): ?>
                        <tr id="noCallsRow">
                            <td colspan="7" class="text-center text-muted">No active calls</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activeChannels as $channel): ?>
                        <tr data-channel="<?php echo $channel['id']; ?>">
                            <td class="font-monospace"><?php echo substr($channel['id'], -8); ?></td>
                            <td>
                                <span class="call-status <?php echo strtolower($channel['state']); ?>">
                                    <?php echo ucfirst($channel['state']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($channel['caller']['number'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($channel['connected']['number'] ?? 'Unknown'); ?></td>
                            <td class="duration-cell" data-start="<?php echo $channel['creationtime'] ?? ''; ?>">-</td>
                            <td><?php echo htmlspecialchars($channel['channelvars']['CAMPAIGN_ID'] ?? ''); ?></td>
                            <td>
                                <button class="btn btn-sm btn-danger" onclick="hangupCall('<?php echo $channel['id']; ?>')">
                                    <i class="fas fa-phone-slash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Events Log -->
<div class="card mt-4">
    <div class="card-header">
        <h6><i class="fas fa-list"></i> Events Log</h6>
        <button class="btn btn-sm btn-outline-secondary float-end" onclick="clearEvents()">
            <i class="fas fa-trash"></i> Clear
        </button>
    </div>
    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
        <div id="eventsLog">
            <div class="text-muted text-center">Events will appear here when connected</div>
        </div>
    </div>
</div>

<script>
let websocket = null;
let connected = false;
let reconnectAttempts = 0;
const maxReconnectAttempts = 5;

function toggleConnection() {
    if (connected) {
        disconnect();
    } else {
        connect();
    }
}

function connect() {
    if (websocket && websocket.readyState === WebSocket.OPEN) {
        return;
    }
    
    const wsUrl = '<?php echo $ari->getWebSocketUrl(); ?>';
    updateConnectionStatus('connecting', 'Connecting to Asterisk ARI...');
    
    try {
        websocket = new WebSocket(wsUrl);
        
        websocket.onopen = function(event) {
            connected = true;
            reconnectAttempts = 0;
            updateConnectionStatus('connected', 'Connected to Asterisk ARI');
            document.getElementById('connectBtn').innerHTML = '<i class="fas fa-wifi"></i> Disconnect';
            logEvent('System', 'Connected to ARI WebSocket', 'success');
        };
        
        websocket.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                handleAriEvent(data);
                updateLastUpdate();
            } catch (e) {
                console.error('Error parsing WebSocket message:', e);
            }
        };
        
        websocket.onclose = function(event) {
            connected = false;
            updateConnectionStatus('disconnected', 'Disconnected from Asterisk ARI');
            document.getElementById('connectBtn').innerHTML = '<i class="fas fa-wifi"></i> Connect';
            
            if (event.code !== 1000 && reconnectAttempts < maxReconnectAttempts) {
                reconnectAttempts++;
                logEvent('System', `Connection lost. Reconnecting... (${reconnectAttempts}/${maxReconnectAttempts})`, 'warning');
                setTimeout(connect, 2000 * reconnectAttempts);
            } else {
                logEvent('System', 'Disconnected from ARI WebSocket', 'danger');
            }
        };
        
        websocket.onerror = function(error) {
            console.error('WebSocket error:', error);
            updateConnectionStatus('error', 'Connection error');
            logEvent('System', 'WebSocket connection error', 'danger');
        };
        
    } catch (e) {
        console.error('Error creating WebSocket:', e);
        updateConnectionStatus('error', 'Failed to create WebSocket connection');
    }
}

function disconnect() {
    if (websocket) {
        websocket.close(1000, 'User disconnected');
        websocket = null;
    }
    connected = false;
    updateConnectionStatus('disconnected', 'Manually disconnected');
}

function updateConnectionStatus(status, message) {
    const statusEl = document.getElementById('connectionStatus');
    const infoEl = document.getElementById('connectionInfo');
    
    statusEl.className = 'badge bg-' + (
        status === 'connected' ? 'success' :
        status === 'connecting' ? 'warning' :
        status === 'error' ? 'danger' : 'secondary'
    );
    
    statusEl.innerHTML = '<i class="fas fa-circle"></i> ' + 
        (status === 'connected' ? 'Connected' :
         status === 'connecting' ? 'Connecting' :
         status === 'error' ? 'Error' : 'Disconnected');
    
    infoEl.textContent = message;
}

function handleAriEvent(event) {
    const eventType = event.type;
    const timestamp = new Date().toLocaleTimeString();
    
    logEvent('ARI', `${eventType}: ${JSON.stringify(event)}`, 'info');
    
    switch (eventType) {
        case 'ChannelCreated':
            handleChannelCreated(event);
            break;
        case 'ChannelStateChange':
            handleChannelStateChange(event);
            break;
        case 'ChannelDestroyed':
            handleChannelDestroyed(event);
            break;
        case 'ChannelDtmfReceived':
            handleChannelDtmf(event);
            break;
    }
}

function handleChannelCreated(event) {
    const channel = event.channel;
    addChannelToTable(channel);
    logEvent('Call', `New call created: ${channel.id}`, 'info');
}

function handleChannelStateChange(event) {
    const channel = event.channel;
    const channelId = channel.id;
    const state = channel.state;
    
    updateChannelInTable(channelId, {
        state: state,
        caller: channel.caller,
        connected: channel.connected
    });
    
    logEvent('Call', `Channel ${channelId.substr(-8)} state: ${state}`, 'info');
}

function handleChannelDestroyed(event) {
    const channelId = event.channel.id;
    removeChannelFromTable(channelId);
    logEvent('Call', `Call ended: ${channelId.substr(-8)}`, 'warning');
}

function handleChannelDtmf(event) {
    const channelId = event.channel.id;
    const digit = event.digit;
    logEvent('DTMF', `Channel ${channelId.substr(-8)} pressed: ${digit}`, 'info');
}

function addChannelToTable(channel) {
    const table = document.getElementById('activeCallsTable').getElementsByTagName('tbody')[0];
    const noCallsRow = document.getElementById('noCallsRow');
    
    if (noCallsRow) {
        noCallsRow.remove();
    }
    
    const row = table.insertRow();
    row.setAttribute('data-channel', channel.id);
    row.innerHTML = `
        <td class="font-monospace">${channel.id.substr(-8)}</td>
        <td><span class="call-status ${channel.state.toLowerCase()}">${channel.state}</span></td>
        <td>${channel.caller?.number || 'Unknown'}</td>
        <td>${channel.connected?.number || 'Unknown'}</td>
        <td class="duration-cell" data-start="${channel.creationtime}">00:00:00</td>
        <td>${channel.channelvars?.CAMPAIGN_ID || ''}</td>
        <td>
            <button class="btn btn-sm btn-danger" onclick="hangupCall('${channel.id}')">
                <i class="fas fa-phone-slash"></i>
            </button>
        </td>
    `;
}

function updateChannelInTable(channelId, data) {
    const row = document.querySelector(`tr[data-channel="${channelId}"]`);
    if (!row) return;
    
    if (data.state) {
        const statusCell = row.querySelector('.call-status');
        if (statusCell) {
            statusCell.className = `call-status ${data.state.toLowerCase()}`;
            statusCell.textContent = data.state;
        }
    }
    
    if (data.caller) {
        row.cells[2].textContent = data.caller.number || 'Unknown';
    }
    
    if (data.connected) {
        row.cells[3].textContent = data.connected.number || 'Unknown';
    }
}

function removeChannelFromTable(channelId) {
    const row = document.querySelector(`tr[data-channel="${channelId}"]`);
    if (row) {
        row.remove();
    }
    
    const table = document.getElementById('activeCallsTable').getElementsByTagName('tbody')[0];
    if (table.rows.length === 0) {
        const noCallsRow = table.insertRow();
        noCallsRow.id = 'noCallsRow';
        noCallsRow.innerHTML = '<td colspan="7" class="text-center text-muted">No active calls</td>';
    }
}

function hangupCall(channelId) {
    if (confirm('Hangup this call?')) {
        fetch('?page=monitoring&action=hangup', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `channel_id=${encodeURIComponent(channelId)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                logEvent('Action', `Manually hung up channel ${channelId.substr(-8)}`, 'warning');
            } else {
                alert('Failed to hangup call: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error hanging up call:', error);
            alert('Error hanging up call');
        });
    }
}

function logEvent(type, message, level = 'info') {
    const eventsLog = document.getElementById('eventsLog');
    const timestamp = new Date().toLocaleTimeString();
    
    const eventEl = document.createElement('div');
    eventEl.className = `alert alert-${level} alert-sm py-1 mb-1`;
    eventEl.innerHTML = `<small><strong>${timestamp}</strong> [${type}] ${message}</small>`;
    
    eventsLog.appendChild(eventEl);
    eventsLog.scrollTop = eventsLog.scrollHeight;
    
    if (eventsLog.children.length > 100) {
        eventsLog.removeChild(eventsLog.firstChild);
    }
}

function clearEvents() {
    document.getElementById('eventsLog').innerHTML = '<div class="text-muted text-center">Events will appear here when connected</div>';
}

function refreshData() {
    location.reload();
}

function updateLastUpdate() {
    document.getElementById('lastUpdate').textContent = 'Last update: ' + new Date().toLocaleTimeString();
}

function updateDurations() {
    document.querySelectorAll('.duration-cell').forEach(cell => {
        const startTime = cell.getAttribute('data-start');
        if (startTime) {
            const start = new Date(startTime);
            const now = new Date();
            const duration = Math.floor((now - start) / 1000);
            
            if (duration >= 0) {
                const hours = Math.floor(duration / 3600);
                const minutes = Math.floor((duration % 3600) / 60);
                const seconds = duration % 60;
                
                cell.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }
        }
    });
}

setInterval(updateDurations, 1000);

document.addEventListener('DOMContentLoaded', function() {
    updateLastUpdate();
    
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && connected) {
            updateLastUpdate();
        }
    });
});
</script>