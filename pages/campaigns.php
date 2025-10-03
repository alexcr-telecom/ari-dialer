<?php
require_once __DIR__ . '/../classes/Campaign.php';
require_once __DIR__ . '/../classes/AsteriskDB.php';

$campaign = new Campaign();
$asteriskDB = AsteriskDB::getInstance();
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
        $importedCount = 0;
        $importSuccess = false;

        if (!empty($_POST['phone_numbers'])) {
            $phones = explode("\n", $_POST['phone_numbers']);
            $leads = [];
            foreach ($phones as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    // Check if line contains comma (CSV format: phone,name)
                    if (strpos($line, ',') !== false) {
                        $parts = str_getcsv($line);
                        $phone = trim($parts[0]);
                        $name = isset($parts[1]) ? trim($parts[1]) : null;
                    } else {
                        // Just a phone number
                        $phone = $line;
                        $name = null;
                    }

                    if (!empty($phone)) {
                        $leads[] = ['phone' => $phone, 'name' => $name];
                    }
                }
            }
            try {
                $campaign->addLeadsBulk($campaignId, $leads);
                $importedCount = count($leads);
                $importSuccess = true;
                echo '<div class="alert alert-success">' . $importedCount . ' leads added successfully!</div>';
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
                $importedCount = count($leads);
                $importSuccess = true;
                echo '<div class="alert alert-success">' . $importedCount . ' leads imported successfully!</div>';
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error importing leads: ' . $e->getMessage() . '</div>';
            }
        }

        // If import was successful, show the imported leads
        if ($importSuccess && $importedCount > 0) {
            // Get the most recently added leads for this campaign
            $recentLeads = $campaign->getLeads([
                'campaign_id' => $campaignId,
                'limit' => 50,
                'offset' => 0
            ]);

            echo '<div class="card mt-3">
                    <div class="card-header">
                        <h6><i class="fas fa-list"></i> Recently Imported Leads (' . $importedCount . ' total)</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <a href="?page=campaigns&action=view&id=' . $campaignId . '" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View All Campaign Leads
                            </a>
                            <a href="?page=campaigns&action=add_leads&id=' . $campaignId . '" class="btn btn-secondary">
                                <i class="fas fa-plus"></i> Add More Leads
                            </a>
                        </div>';

            if (count($recentLeads) > 0) {
                echo '<div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Phone Number</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Added</th>
                                </tr>
                            </thead>
                            <tbody>';

                foreach (array_slice($recentLeads, 0, 50) as $lead) {
                    echo '<tr>
                            <td><strong>' . htmlspecialchars($lead['phone_number']) . '</strong></td>
                            <td>';
                    if (!empty($lead['name'])) {
                        echo '<strong>' . htmlspecialchars($lead['name']) . '</strong>';
                    } else {
                        echo '<em class="text-muted">No name</em>';
                    }
                    echo '</td>
                            <td><span class="badge bg-secondary">Pending</span></td>
                            <td>' . date('Y-m-d H:i', strtotime($lead['created_at'])) . '</td>
                          </tr>';
                }

                echo '</tbody>
                        </table>
                      </div>';

                if ($importedCount > 50) {
                    echo '<div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Showing first 50 of ' . $importedCount . ' imported leads.
                            <a href="?page=campaigns&action=view&id=' . $campaignId . '">View all leads</a>
                          </div>';
                }
            }

            echo '</div>
                </div>';
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

if ($action === 'duplicate' && $campaignId) {
    $newId = $campaign->duplicate($campaignId);
    if ($newId) {
        // Redirect to prevent duplicate on refresh
        header('Location: ?page=campaigns&duplicated=' . $newId);
        exit;
    } else {
        // Redirect with error
        header('Location: ?page=campaigns&error=duplicate_failed');
        exit;
    }
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

<?php
// Display messages after redirect
if (isset($_GET['duplicated'])) {
    $newId = (int)$_GET['duplicated'];
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
            Campaign duplicated successfully! <a href="?page=campaigns&action=edit&id=' . $newId . '" class="alert-link">Edit the new campaign</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
}
if (isset($_GET['error']) && $_GET['error'] === 'duplicate_failed') {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            Error duplicating campaign.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
}
?>

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
                            <button class="btn btn-sm btn-info" onclick="duplicateCampaign(<?php echo $c['id']; ?>)" title="Duplicate Campaign">
                                <i class="fas fa-copy"></i>
                            </button>
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

                        <!-- Agent Destination Configuration -->
                        <div class="card border-light mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">Agent Destination</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="destination_type" class="form-label">Destination Type</label>
                                            <select class="form-select" name="destination_type" id="destination_type" onchange="toggleDestinationFields()">
                                                <option value="custom" <?php echo ($campaignData['destination_type'] ?? 'custom') === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                                <option value="ivr" <?php echo ($campaignData['destination_type'] ?? '') === 'ivr' ? 'selected' : ''; ?>>IVR</option>
                                                <option value="queue" <?php echo ($campaignData['destination_type'] ?? '') === 'queue' ? 'selected' : ''; ?>>Queue</option>
                                                <option value="extension" <?php echo ($campaignData['destination_type'] ?? '') === 'extension' ? 'selected' : ''; ?>>Extension</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- IVR Selector -->
                                    <div class="col-md-3" id="ivr_selector" style="display: none;">
                                        <div class="mb-3">
                                            <label for="ivr_id" class="form-label">Select IVR</label>
                                            <select class="form-select" name="ivr_id" id="ivr_id">
                                                <option value="">-- Select IVR --</option>
                                                <?php
                                                $ivrs = $asteriskDB->getIVRs();
                                                foreach ($ivrs as $ivr): ?>
                                                    <option value="<?php echo $ivr['id']; ?>" <?php echo ($campaignData['ivr_id'] ?? '') == $ivr['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($ivr['name'] ?: "IVR {$ivr['id']}"); ?>
                                                        <?php if ($ivr['description']): ?>
                                                            - <?php echo htmlspecialchars($ivr['description']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Queue Selector -->
                                    <div class="col-md-3" id="queue_selector" style="display: none;">
                                        <div class="mb-3">
                                            <label for="queue_extension" class="form-label">Select Queue</label>
                                            <select class="form-select" name="queue_extension" id="queue_extension">
                                                <option value="">-- Select Queue --</option>
                                                <?php
                                                $queues = $asteriskDB->getQueues();
                                                foreach ($queues as $queue): ?>
                                                    <option value="<?php echo $queue['extension']; ?>" <?php echo ($campaignData['queue_extension'] ?? '') == $queue['extension'] ? 'selected' : ''; ?>>
                                                        <?php echo $queue['extension']; ?>
                                                        <?php if ($queue['description']): ?>
                                                            - <?php echo htmlspecialchars($queue['description']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Extension Selector -->
                                    <div class="col-md-3" id="extension_selector" style="display: none;">
                                        <div class="mb-3">
                                            <label for="agent_extension" class="form-label">Select Extension</label>
                                            <select class="form-select" name="agent_extension" id="agent_extension">
                                                <option value="">-- Select Extension --</option>
                                                <?php
                                                $extensions = $asteriskDB->getExtensions();
                                                foreach ($extensions as $ext): ?>
                                                    <option value="<?php echo $ext['extension']; ?>" <?php echo ($campaignData['agent_extension'] ?? '') == $ext['extension'] ? 'selected' : ''; ?>>
                                                        <?php echo $ext['extension']; ?>
                                                        <?php if ($ext['name']): ?>
                                                            - <?php echo htmlspecialchars($ext['name']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Custom Fields -->
                                    <div class="col-md-9" id="custom_selector">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="context" class="form-label">Context</label>
                                                    <input type="text" class="form-control" name="context" id="context"
                                                           value="<?php echo htmlspecialchars($campaignData['context'] ?? Config::ASTERISK_CONTEXT); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="extension" class="form-label">Extension</label>
                                                    <input type="text" class="form-control" name="extension" id="extension"
                                                           value="<?php echo htmlspecialchars($campaignData['extension'] ?? '101'); ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
    $stats = $campaign->getStats($campaignId);

    // Pagination for leads
    $leadsPerPage = 50;
    $currentPage = isset($_GET['leads_page']) ? max(1, (int)$_GET['leads_page']) : 1;
    $offset = ($currentPage - 1) * $leadsPerPage;

    // Get total leads count
    $totalLeads = $stats['total_leads'] ?? 0;
    $totalPages = ceil($totalLeads / $leadsPerPage);

    // Get leads for current page
    $leads = $campaign->getLeads([
        'campaign_id' => $campaignId,
        'limit' => $leadsPerPage,
        'offset' => $offset
    ]);
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
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6>Leads</h6>
            <small class="text-muted">
                <?php
                $startRecord = ($currentPage - 1) * $leadsPerPage + 1;
                $endRecord = min($currentPage * $leadsPerPage, $totalLeads);
                echo "Showing $startRecord-$endRecord of $totalLeads leads";
                ?>
            </small>
        </div>
        <div class="card-body">
            <?php if (count($leads) > 0): ?>
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
                                <td>
                                    <strong><?php echo htmlspecialchars($lead['phone_number']); ?></strong>
                                    <?php if (!empty($lead['name'])): ?>
                                        <br><small class="text-muted">ID: <?php echo htmlspecialchars($lead['name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($lead['name'])): ?>
                                        <strong><?php echo htmlspecialchars($lead['name']); ?></strong>
                                    <?php else: ?>
                                        <em class="text-muted">No name</em>
                                    <?php endif; ?>
                                </td>
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

                <!-- Pagination Controls -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Leads pagination">
                    <ul class="pagination justify-content-center mt-3">
                        <?php if ($currentPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=campaigns&action=view&id=<?php echo $campaignId; ?>&leads_page=<?php echo $currentPage - 1; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        // Show page numbers (max 5 visible)
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);

                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=campaigns&action=view&id=<?php echo $campaignId; ?>&leads_page=1">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif;
                        endif;

                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=campaigns&action=view&id=<?php echo $campaignId; ?>&leads_page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor;

                        if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=campaigns&action=view&id=<?php echo $campaignId; ?>&leads_page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                            </li>
                        <?php endif; ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=campaigns&action=view&id=<?php echo $campaignId; ?>&leads_page=<?php echo $currentPage + 1; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-phone fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No leads found</h5>
                    <p class="text-muted">Add leads to this campaign to get started.</p>
                    <a href="?page=campaigns&action=add_leads&id=<?php echo $campaignId; ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Leads
                    </a>
                </div>
            <?php endif; ?>
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
                                      placeholder="Enter phone numbers, one per line...&#10;+15551234567,John Smith&#10;5559876543,Jane Doe&#10;Or just phone numbers:&#10;+15551234567&#10;5559876543"></textarea>
                            <div class="form-text">
                                You can enter phone numbers with optional names in CSV format (phone,name) or just phone numbers alone.
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">OR Upload CSV File</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv">
                            <div class="form-text">
                                <strong>CSV format:</strong> phone_number,name<br>
                                <strong>Example:</strong><br>
                                +15551234567,John Smith<br>
                                5559876543,Jane Doe<br>
                                <em>Name column is optional but recommended for caller ID display</em>
                            </div>
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

function duplicateCampaign(id) {
    if (confirm('Duplicate this campaign? A copy will be created with status set to paused.')) {
        window.location.href = `?page=campaigns&action=duplicate&id=${id}`;
    }
}

function deleteCampaign(id) {
    if (confirm('Delete this campaign? This will also delete all associated leads.')) {
        window.location.href = `?page=campaigns&action=delete&id=${id}`;
    }
}

function toggleDestinationFields() {
    const destinationTypeElement = document.getElementById('destination_type');
    if (!destinationTypeElement) {
        return; // Element doesn't exist, probably not on the create/edit page
    }

    const destinationType = destinationTypeElement.value;

    // Get all selector elements
    const selectors = {
        ivr_selector: document.getElementById('ivr_selector'),
        queue_selector: document.getElementById('queue_selector'),
        extension_selector: document.getElementById('extension_selector'),
        custom_selector: document.getElementById('custom_selector')
    };

    // Hide all selectors (check if they exist first)
    Object.values(selectors).forEach(element => {
        if (element) {
            element.style.display = 'none';
        }
    });

    // Show the appropriate selector
    if (destinationType === 'ivr' && selectors.ivr_selector) {
        selectors.ivr_selector.style.display = 'block';
    } else if (destinationType === 'queue' && selectors.queue_selector) {
        selectors.queue_selector.style.display = 'block';
    } else if (destinationType === 'extension' && selectors.extension_selector) {
        selectors.extension_selector.style.display = 'block';
    } else if (destinationType === 'custom' && selectors.custom_selector) {
        selectors.custom_selector.style.display = 'block';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Only run if we're on a page that has the destination_type element
    if (document.getElementById('destination_type')) {
        toggleDestinationFields();
    }
});
</script>