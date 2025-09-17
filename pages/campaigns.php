<?php
require_once __DIR__ . '/../classes/Campaign.php';

$campaign = new Campaign();
$action = $_GET['action'] ?? 'list';
$campaignId = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        if ($campaign->create($_POST)) {
            echo '<div class="alert alert-success">Campaign created successfully!</div>';
            $action = 'list';
        } else {
            echo '<div class="alert alert-danger">Error creating campaign.</div>';
        }
    } elseif ($action === 'edit' && $campaignId) {
        if ($campaign->update($campaignId, $_POST)) {
            echo '<div class="alert alert-success">Campaign updated successfully!</div>';
            $action = 'list';
        } else {
            echo '<div class="alert alert-danger">Error updating campaign.</div>';
        }
    } elseif ($action === 'add_leads' && $campaignId) {
        if (!empty($_POST['phone_numbers'])) {
            $phones = explode("\n", $_POST['phone_numbers']);
            $leads = [];
            foreach ($phones as $phone) {
                $phone = trim($phone);
                if (!empty($phone)) {
                    $leads[] = ['phone' => $phone, 'name' => null];
                }
            }
            try {
                $campaign->addLeadsBulk($campaignId, $leads);
                echo '<div class="alert alert-success">' . count($leads) . ' leads added successfully!</div>';
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error adding leads: ' . $e->getMessage() . '</div>';
            }
        }
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $leads = [];
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (!empty($data[0])) {
                    $leads[] = ['phone' => $data[0], 'name' => $data[1] ?? null];
                }
            }
            fclose($handle);
            try {
                $campaign->addLeadsBulk($campaignId, $leads);
                echo '<div class="alert alert-success">' . count($leads) . ' leads imported successfully!</div>';
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error importing leads: ' . $e->getMessage() . '</div>';
            }
        }
    }
}

if ($action === 'delete' && $campaignId) {
    if ($campaign->delete($campaignId)) {
        echo '<div class="alert alert-success">Campaign deleted successfully!</div>';
    } else {
        echo '<div class="alert alert-danger">Error deleting campaign.</div>';
    }
    $action = 'list';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-bullhorn"></i> Campaign Management</h2>
    <?php if ($action === 'list'): ?>
        <a href="?page=campaigns&action=create" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Campaign
        </a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <input type="text" class="form-control" id="searchCampaigns" placeholder="Search campaigns...">
        </div>
        <div class="col-md-3">
            <select class="form-select" id="filterStatus">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="paused">Paused</option>
                <option value="completed">Completed</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover" id="campaignsTable">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Total Leads</th>
                    <th>Answered</th>
                    <th>Success Rate</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $campaigns = $campaign->getAll();
                foreach ($campaigns as $c): ?>
                <tr>
                    <td><?php echo $c['id']; ?></td>
                    <td><?php echo htmlspecialchars($c['name']); ?></td>
                    <td>
                        <span class="campaign-status <?php echo $c['status']; ?>">
                            <?php echo ucfirst($c['status']); ?>
                        </span>
                    </td>
                    <td><?php echo $c['total_leads']; ?></td>
                    <td><?php echo $c['answered_leads']; ?></td>
                    <td><?php echo $c['success_rate'] ?? 0; ?>%</td>
                    <td>
                        <div class="btn-group" role="group">
                            <?php if ($c['status'] === 'paused'): ?>
                                <button class="btn btn-sm btn-start-campaign" onclick="startCampaign(<?php echo $c['id']; ?>)">
                                    <i class="fas fa-play"></i>
                                </button>
                            <?php elseif ($c['status'] === 'active'): ?>
                                <button class="btn btn-sm btn-warning" onclick="pauseCampaign(<?php echo $c['id']; ?>)">
                                    <i class="fas fa-pause"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="stopCampaign(<?php echo $c['id']; ?>)" title="Stop and Reset">
                                    <i class="fas fa-stop"></i>
                                </button>
                            <?php endif; ?>
                            <a href="?page=campaigns&action=view&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="?page=campaigns&action=edit&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-secondary">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button class="btn btn-sm btn-danger" onclick="deleteCampaign(<?php echo $c['id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
    <?php 
    $campaignData = [];
    if ($action === 'edit' && $campaignId) {
        $campaignData = $campaign->getById($campaignId);
    }
    ?>
    
    <div class="row">
        <div class="col-md-8">
            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h5><?php echo $action === 'create' ? 'Create New Campaign' : 'Edit Campaign'; ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Campaign Name *</label>
                                    <input type="text" class="form-control" name="name" id="name" required 
                                           value="<?php echo htmlspecialchars($campaignData['name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" name="status" id="status">
                                        <option value="paused" <?php echo ($campaignData['status'] ?? '') === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                        <option value="active" <?php echo ($campaignData['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="completed" <?php echo ($campaignData['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="description" rows="3"><?php echo htmlspecialchars($campaignData['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="context" class="form-label">Context</label>
                                    <input type="text" class="form-control" name="context" id="context" 
                                           value="<?php echo htmlspecialchars($campaignData['context'] ?? Config::ASTERISK_CONTEXT); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="extension" class="form-label">Extension</label>
                                    <input type="text" class="form-control" name="extension" id="extension" 
                                           value="<?php echo htmlspecialchars($campaignData['extension'] ?? '101'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="priority" class="form-label">Priority</label>
                                    <input type="number" class="form-control" name="priority" id="priority" 
                                           value="<?php echo $campaignData['priority'] ?? 1; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label for="outbound_context" class="form-label">Outbound Context</label>
                                    <input type="text" class="form-control" name="outbound_context" id="outbound_context" 
                                           value="<?php echo htmlspecialchars($campaignData['outbound_context'] ?? 'from-internal'); ?>"
                                           placeholder="from-internal">
                                    <div class="form-text">Context for outbound calls (LOCAL/$NUMBER@context)</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="max_calls_per_minute" class="form-label">Max Calls/Minute</label>
                                    <input type="number" class="form-control" name="max_calls_per_minute" id="max_calls_per_minute" 
                                           value="<?php echo $campaignData['max_calls_per_minute'] ?? 10; ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="retry_attempts" class="form-label">Retry Attempts</label>
                                    <input type="number" class="form-control" name="retry_attempts" id="retry_attempts" 
                                           value="<?php echo $campaignData['retry_attempts'] ?? 3; ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="retry_interval" class="form-label">Retry Interval (seconds)</label>
                                    <input type="number" class="form-control" name="retry_interval" id="retry_interval" 
                                           value="<?php echo $campaignData['retry_interval'] ?? 300; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="datetime-local" class="form-control" name="start_date" id="start_date"
                                           value="<?php echo isset($campaignData['start_date']) && $campaignData['start_date'] ? date('Y-m-d\TH:i', strtotime($campaignData['start_date'])) : date('Y-m-d\T09:00'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="datetime-local" class="form-control" name="end_date" id="end_date"
                                           value="<?php echo isset($campaignData['end_date']) && $campaignData['end_date'] ? date('Y-m-d\TH:i', strtotime($campaignData['end_date'])) : date('Y-m-d\T18:00'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'create' ? 'Create Campaign' : 'Update Campaign'; ?>
                        </button>
                        <a href="?page=campaigns" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($action === 'view' && $campaignId): ?>
    <?php 
    $campaignData = $campaign->getById($campaignId);
    $leads = $campaign->getLeads($campaignId, null, 100);
    $stats = $campaign->getStats($campaignId);
    ?>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><?php echo htmlspecialchars($campaignData['name']); ?></h5>
                    <div>
                        <a href="?page=campaigns&action=add_leads&id=<?php echo $campaignId; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> Add Leads
                        </a>
                        <a href="?page=campaigns&action=edit&id=<?php echo $campaignId; ?>" class="btn btn-sm btn-secondary">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Status:</strong> 
                                <span class="campaign-status <?php echo $campaignData['status']; ?>">
                                    <?php echo ucfirst($campaignData['status']); ?>
                                </span>
                            </p>
                            <p><strong>Context:</strong> <?php echo htmlspecialchars($campaignData['context']); ?></p>
                            <p><strong>Outbound Context:</strong> <?php echo htmlspecialchars($campaignData['outbound_context'] ?? 'from-internal'); ?></p>
                            <p><strong>Extension:</strong> <?php echo htmlspecialchars($campaignData['extension']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Max Calls/Min:</strong> <?php echo $campaignData['max_calls_per_minute']; ?></p>
                            <p><strong>Retry Attempts:</strong> <?php echo $campaignData['retry_attempts']; ?></p>
                            <p><strong>Retry Interval:</strong> <?php echo $campaignData['retry_interval']; ?>s</p>
                        </div>
                    </div>
                    <?php if ($campaignData['description']): ?>
                        <p><strong>Description:</strong> <?php echo htmlspecialchars($campaignData['description']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stats-card">
                <div class="card-body">
                    <h6 class="card-title">Campaign Statistics</h6>
                    <div class="row text-center">
                        <div class="col-6">
                            <h4 class="text-primary"><?php echo $stats['total_leads']; ?></h4>
                            <small>Total Leads</small>
                        </div>
                        <div class="col-6">
                            <h4 class="text-success"><?php echo $stats['answered_leads']; ?></h4>
                            <small>Answered</small>
                        </div>
                        <div class="col-12 mt-3">
                            <h4 class="text-info"><?php echo $stats['success_rate']; ?>%</h4>
                            <small>Success Rate</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h6>Leads</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Phone Number</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Last Attempt</th>
                            <th>Disposition</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($lead['phone_number']); ?></td>
                            <td><?php echo htmlspecialchars($lead['name'] ?? ''); ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $lead['status'] === 'answered' ? 'success' : 
                                        ($lead['status'] === 'pending' ? 'secondary' : 'warning'); 
                                ?>">
                                    <?php echo ucfirst($lead['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $lead['attempts']; ?></td>
                            <td><?php echo $lead['last_attempt'] ? date('Y-m-d H:i', strtotime($lead['last_attempt'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($lead['disposition'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($action === 'add_leads' && $campaignId): ?>
    <?php $campaignData = $campaign->getById($campaignId); ?>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Add Leads to: <?php echo htmlspecialchars($campaignData['name']); ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label for="phone_numbers" class="form-label">Phone Numbers (one per line)</label>
                            <textarea class="form-control" name="phone_numbers" id="phone_numbers" rows="10" 
                                      placeholder="Enter phone numbers, one per line..."></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">OR Upload CSV File</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv">
                            <div class="form-text">CSV format: phone_number,name (optional)</div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Add Leads
                        </button>
                        <a href="?page=campaigns&action=view&id=<?php echo $campaignId; ?>" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function startCampaign(id) {
    if (confirm('Start this campaign?')) {
        fetch('api/campaigns.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'start', id: id})
        }).then(() => location.reload());
    }
}

function pauseCampaign(id) {
    if (confirm('Pause this campaign?')) {
        fetch('api/campaigns.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'pause', id: id})
        }).then(() => location.reload());
    }
}

function stopCampaign(id) {
    if (confirm('Stop this campaign and reset all dialed numbers? This will mark all leads as pending again.')) {
        fetch('api/campaigns.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'stop', id: id})
        }).then(() => location.reload());
    }
}

function deleteCampaign(id) {
    if (confirm('Delete this campaign? This will also delete all associated leads.')) {
        window.location.href = `?page=campaigns&action=delete&id=${id}`;
    }
}
</script>