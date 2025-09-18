<?php
require_once __DIR__ . '/ARI.php';
require_once __DIR__ . '/Campaign.php';
require_once __DIR__ . '/../config/database.php';

class Dialer {
    private $ari;
    private $campaign;
    private $db;

    public function __construct() {
        $this->ari = new ARI();
        $this->campaign = new Campaign();
        $this->db = Database::getInstance()->getConnection();
    }

    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] DIALER: $message" . PHP_EOL;
        file_put_contents(__DIR__ . '/../logs/error.log', $logMessage, FILE_APPEND | LOCK_EX);
        error_log($logMessage);
    }
    
    public function startCampaign($campaignId) {
        $this->log("Starting campaign ID: $campaignId");

        $campaignData = $this->campaign->getById($campaignId);
        if (!$campaignData) {
            $this->log("Campaign ID $campaignId not found", 'ERROR');
            return ['success' => false, 'message' => 'Campaign not found'];
        }

        $this->log("Campaign found: " . $campaignData['name'] . " with status: " . $campaignData['status']);

        if ($campaignData['status'] !== 'paused') {
            $this->log("Campaign is not in paused state (current: " . $campaignData['status'] . ")", 'ERROR');
            return ['success' => false, 'message' => 'Campaign is not in paused state'];
        }

        $this->log("Updating campaign status to active");
        $this->campaign->update($campaignId, array_merge($campaignData, ['status' => 'active']));

        $this->log("Processing campaign leads");
        $this->processCampaignLeads($campaignId);

        $this->log("Campaign started successfully");
        return ['success' => true, 'message' => 'Campaign started'];
    }
    
    public function pauseCampaign($campaignId) {
        $campaignData = $this->campaign->getById($campaignId);
        if (!$campaignData) {
            return ['success' => false, 'message' => 'Campaign not found'];
        }
        
        $this->campaign->update($campaignId, array_merge($campaignData, ['status' => 'paused']));
        
        return ['success' => true, 'message' => 'Campaign paused'];
    }
    
    public function stopCampaign($campaignId) {
        $campaignData = $this->campaign->getById($campaignId);
        if (!$campaignData) {
            return ['success' => false, 'message' => 'Campaign not found'];
        }
        
        if ($campaignData['status'] !== 'active') {
            return ['success' => false, 'message' => 'Campaign is not active'];
        }
        
        $this->campaign->update($campaignId, array_merge($campaignData, ['status' => 'paused']));
        
        $this->resetCampaignLeads($campaignId);
        
        return ['success' => true, 'message' => 'Campaign stopped and leads reset'];
    }
    
    public function processCampaignLeads($campaignId) {
        $this->log("Processing leads for campaign ID: $campaignId");

        $campaignData = $this->campaign->getById($campaignId);
        if (!$campaignData) {
            $this->log("Cannot get campaign data for ID: $campaignId", 'ERROR');
            return;
        }

        $maxCalls = $campaignData['max_calls_per_minute'];
        $this->log("Max calls per minute: $maxCalls");

        $leads = $this->campaign->getLeads([
            'campaign_id' => $campaignId,
            'status' => 'pending',
            'limit' => $maxCalls
        ]);
        $leadCount = count($leads);
        $this->log("Found $leadCount pending leads to dial");

        if ($leadCount === 0) {
            $this->log("No pending leads found for campaign $campaignId", 'WARN');
            return;
        }

        foreach ($leads as $index => $lead) {
            $this->log("Processing lead " . ($index + 1) . "/$leadCount: " . $lead['phone_number']);
            $result = $this->dialLead($campaignId, $lead);

            if ($result['success']) {
                $this->log("Successfully initiated call to " . $lead['phone_number']);
            } else {
                $this->log("Failed to initiate call to " . $lead['phone_number'] . ": " . $result['message'], 'ERROR');
            }

            // Rate limiting
            $sleepTime = 60000000 / $maxCalls; // microseconds
            $this->log("Sleeping for " . round($sleepTime/1000000, 2) . " seconds (rate limiting)");
            usleep($sleepTime);
        }

        $this->log("Finished processing $leadCount leads for campaign $campaignId");
    }
    
    public function dialLead($campaignId, $lead) {
        try {
            $this->log("Dialing lead ID: " . $lead['id'] . ", phone: " . $lead['phone_number']);

            $campaignData = $this->campaign->getById($campaignId);
            if (!$campaignData) {
                $this->log("Cannot get campaign data for lead dial", 'ERROR');
                return ['success' => false, 'message' => 'Campaign data not found'];
            }

            $outboundContext = $campaignData['outbound_context'] ?? 'from-internal';
            $agentContext = $campaignData['context'] ?? 'from-internal';
            $endpoint = 'Local/' . $lead['phone_number'] . '@' . $outboundContext;
            $agentExtension = $campaignData['extension'] ?? '101';

            $this->log("Dialing: endpoint=$endpoint, context=$outboundContext, agent=$agentExtension");

            $variables = [
                'CAMPAIGN_ID' => $campaignId,
                'LEAD_ID' => $lead['id'],
                'AGENT_EXTENSION' => $agentExtension,
                'CAMPAIGN_NAME' => $campaignData['name'],
                'AGENT_CONTEXT' => $agentContext
            ];

            $this->log("Making outbound call to: $endpoint for agent: $agentExtension");

            // Create the outbound call using ARI originateCall method with dialed number as caller ID
            $response = $this->ari->originateCall($endpoint, $agentExtension, $agentContext, 1, $variables, $lead['phone_number']);

            $this->log("ARI response: " . json_encode($response));

            if ($response && isset($response['id'])) {
                $this->log("Call originated successfully, channel ID: " . $response['id']);

                $this->campaign->updateLeadStatus($lead['id'], 'dialed');

                $this->logCall([
                    'lead_id' => $lead['id'],
                    'campaign_id' => $campaignId,
                    'phone_number' => $lead['phone_number'],
                    'agent_extension' => $agentExtension,
                    'channel_id' => $response['id'],
                    'status' => 'initiated',
                    'call_start' => date('Y-m-d H:i:s')
                ]);

                return ['success' => true, 'channel_id' => $response['id']];
            } else {
                $this->log("ARI originate failed - no channel ID in response", 'ERROR');
                $this->campaign->updateLeadStatus($lead['id'], 'failed', 'originate_failed');
                return ['success' => false, 'message' => 'Failed to originate call - no channel ID'];
            }

        } catch (Exception $e) {
            $this->log("Exception in dialLead: " . $e->getMessage(), 'ERROR');
            $this->campaign->updateLeadStatus($lead['id'], 'failed', 'error', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function resetCampaignLeads($campaignId) {
        try {
            $sql = "UPDATE leads SET 
                        status = 'pending',
                        attempts = 0,
                        last_attempt = NULL,
                        next_attempt = NULL,
                        disposition = NULL,
                        notes = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE campaign_id = :campaign_id 
                    AND status IN ('dialed', 'failed', 'no_answer', 'busy')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':campaign_id' => $campaignId]);
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Reset campaign leads error: " . $e->getMessage());
            return 0;
        }
    }
    
    public function handleStasisStart($event) {
        // Handle when a channel enters the Stasis application  
        $channel = $event['channel'];
        $channelId = $channel['id'];
        $args = $event['args'] ?? [];
        $agentExtension = $args[0] ?? null; // Agent extension from appArgs
        
        error_log("StasisStart: Channel {$channelId}, Agent: {$agentExtension}");
        
        if ($agentExtension) {
            // Wait a moment for the outbound call to be established
            sleep(1);
            
            // Check if the outbound call was answered
            $channelInfo = $this->ari->getChannel($channelId);
            
            if ($channelInfo && $channelInfo['state'] === 'Up') {
                // Call was answered, now connect to agent
                $this->connectToAgent($channelId, $agentExtension, $agentContext);
            } else {
                // Call not answered yet, we'll handle this in ChannelStateChange
                error_log("Outbound call not answered yet, waiting...");
            }
        }
    }
    
    private function connectToAgent($outboundChannelId, $agentExtension, $agentContext = 'from-internal') {
        try {
            error_log("Connecting answered call {$outboundChannelId} to agent {$agentExtension}");

            // Create agent channel
            $agentChannel = $this->ari->originateCall("Local/{$agentExtension}@{$agentContext}", $agentExtension, $agentContext, 1);
            
            if ($agentChannel && isset($agentChannel['id'])) {
                $agentChannelId = $agentChannel['id'];
                
                // Create a bridge
                $bridgeResponse = $this->ari->createBridge();
                
                if ($bridgeResponse) {
                    $bridgeId = is_array($bridgeResponse) ? $bridgeResponse['id'] : $bridgeResponse;
                    
                    // Add both channels to the bridge
                    $this->ari->addChannelToBridge($bridgeId, $outboundChannelId);
                    $this->ari->addChannelToBridge($bridgeId, $agentChannelId);
                    
                    error_log("Successfully bridged outbound call to agent");
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Connect to agent error: " . $e->getMessage());
            return false;
        }
    }
    
    public function handleChannelEvent($event) {
        $channelId = $event['channel']['id'] ?? null;
        $eventType = $event['type'] ?? null;

        if (!$channelId) {
            $this->log("No channel ID in event: " . json_encode($event), 'WARN');
            return;
        }

        $this->log("Processing ARI event: $eventType for channel: $channelId");

        $callLog = $this->getCallLogByChannelId($channelId);
        if (!$callLog) {
            $this->log("No call log found for channel ID: $channelId, Event: $eventType", 'WARN');
            return;
        }

        $this->log("Found call log ID: " . $callLog['id'] . " for channel: $channelId, Current status: " . $callLog['status']);

        switch ($eventType) {
            case 'ChannelStateChange':
                $this->handleChannelStateChange($event, $callLog);
                break;

            case 'ChannelDestroyed':
                $this->handleChannelDestroyed($event, $callLog);
                break;

            case 'ChannelDtmfReceived':
                $this->handleDtmfReceived($event, $callLog);
                break;

            default:
                $this->log("Unhandled event type: $eventType for channel: $channelId", 'DEBUG');
                break;
        }
    }
    
    private function handleChannelStateChange($event, $callLog) {
        $state = $event['channel']['state'] ?? null;
        $channelId = $event['channel']['id'];
        $channel = $event['channel'];

        // Update call log status based on ARI channel state events immediately
        if ($callLog && $callLog['status'] === 'initiated') {
            switch ($state) {
                case 'Ring':
                case 'Ringing':
                    $this->updateCallLog($callLog['id'], ['status' => 'ringing']);
                    $this->campaign->updateLeadStatus($callLog['lead_id'], 'ringing');
                    $this->log("ARI Event: Call ringing - Lead ID: " . $callLog['lead_id'] . ", Channel: $channelId");
                    break;
                case 'Up':
                    $this->updateCallLog($callLog['id'], ['status' => 'answered', 'call_start' => date('Y-m-d H:i:s')]);
                    $this->campaign->updateLeadStatus($callLog['lead_id'], 'answered');
                    $this->log("ARI Event: Call answered - Lead ID: " . $callLog['lead_id'] . ", Channel: $channelId");
                    break;
                case 'Busy':
                    $this->updateCallLog($callLog['id'], ['status' => 'busy']);
                    $this->campaign->updateLeadStatus($callLog['lead_id'], 'busy', 'BUSY');
                    $this->log("ARI Event: Line busy - Lead ID: " . $callLog['lead_id'] . ", Channel: $channelId");
                    break;
                case 'Down':
                    // Let handleChannelDestroyed handle final status with hangup cause
                    break;
            }
        }

        switch ($state) {
            case 'Ringing':
                if ($callLog && $callLog['status'] !== 'ringing') {
                    $this->updateCallLog($callLog['id'], ['status' => 'ringing']);
                }
                break;

            case 'Up':
                // Call was answered
                if ($callLog) {
                    $updateData = ['status' => 'answered'];

                    // Only set call_start if not already set
                    if (empty($callLog['call_start'])) {
                        $updateData['call_start'] = date('Y-m-d H:i:s');
                    }

                    $this->updateCallLog($callLog['id'], $updateData);
                    $this->campaign->updateLeadStatus($callLog['lead_id'], 'answered');
                }

                // If this is an outbound call managed by our ARI app, connect to agent
                if (isset($channel['channelvars']['AGENT_EXTENSION'])) {
                    $agentExtension = $channel['channelvars']['AGENT_EXTENSION'];
                    $agentContext = $channel['channelvars']['AGENT_CONTEXT'] ?? 'from-internal';
                    $this->connectToAgent($channelId, $agentExtension, $agentContext);
                }

                break;

            case 'Down':
                if ($callLog) {
                    $this->handleCallEnd($callLog, 'hung_up');
                }
                break;
        }
    }
    
    private function handleChannelDestroyed($event, $callLog) {
        $hangupCause = $event['cause'] ?? null;
        $hangupCauseText = $event['cause_txt'] ?? null;

        // Use ARI hangup cause to determine final call status
        $status = $this->getStatusFromHangupCause($hangupCause);
        $disposition = $this->getDispositionFromHangupCause($hangupCause);

        $this->log("Channel destroyed - Hangup cause: $hangupCause ($hangupCauseText), Status: $status, Disposition: $disposition");

        $this->handleCallEnd($callLog, $status, $disposition);
    }
    
    private function handleCallEnd($callLog, $status, $disposition = null) {
        $callEnd = date('Y-m-d H:i:s');
        $duration = 0;

        // Calculate duration if call_start exists
        if ($callLog['call_start']) {
            $duration = strtotime($callEnd) - strtotime($callLog['call_start']);
        }

        $this->log("Ending call - Lead ID: " . $callLog['lead_id'] . ", Status: $status, Disposition: $disposition, Duration: {$duration}s");

        // Update call log with final status from ARI hangup cause
        $this->updateCallLog($callLog['id'], [
            'status' => $status,
            'call_end' => $callEnd,
            'duration' => $duration,
            'disposition' => $disposition
        ]);

        // Update lead status based on ARI-determined call outcome
        if ($callLog['lead_id']) {
            $this->campaign->updateLeadStatus($callLog['lead_id'], $status, $disposition);

            // Schedule retry for unsuccessful calls
            if ($status !== 'answered' && $this->shouldRetry($callLog['lead_id'])) {
                $this->scheduleRetry($callLog['lead_id']);
            }
        }
    }
    
    private function handleDtmfReceived($event, $callLog) {
        $digit = $event['digit'] ?? null;
        
        if ($digit) {
            $this->updateCallLog($callLog['id'], [
                'disposition' => 'DTMF_' . $digit
            ]);
        }
    }
    
    private function getCallLogByChannelId($channelId) {
        $sql = "SELECT * FROM call_logs WHERE channel_id = :channel_id ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':channel_id' => $channelId]);
        return $stmt->fetch();
    }
    
    private function logCall($data) {
        $sql = "INSERT INTO call_logs (lead_id, campaign_id, phone_number, agent_extension, channel_id, status, call_start)
                VALUES (:lead_id, :campaign_id, :phone_number, :agent_extension, :channel_id, :status, :call_start)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':lead_id' => $data['lead_id'],
            ':campaign_id' => $data['campaign_id'],
            ':phone_number' => $data['phone_number'],
            ':agent_extension' => $data['agent_extension'],
            ':channel_id' => $data['channel_id'],
            ':status' => $data['status'],
            ':call_start' => $data['call_start'] ?? date('Y-m-d H:i:s')
        ]);
    }
    
    private function updateCallLog($id, $data) {
        $setParts = [];
        $params = [':id' => $id];
        
        foreach ($data as $key => $value) {
            $setParts[] = "$key = :$key";
            $params[":$key"] = $value;
        }
        
        $sql = "UPDATE call_logs SET " . implode(', ', $setParts) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    private function shouldRecord() {
        $sql = "SELECT setting_value FROM settings WHERE setting_key = 'enable_recording'";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch();
        return $result && $result['setting_value'] === '1';
    }
    
    private function shouldRetry($leadId) {
        $sql = "SELECT l.attempts, c.retry_attempts 
                FROM leads l 
                JOIN campaigns c ON l.campaign_id = c.id 
                WHERE l.id = :lead_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':lead_id' => $leadId]);
        $result = $stmt->fetch();
        
        return $result && $result['attempts'] < $result['retry_attempts'];
    }
    
    private function scheduleRetry($leadId) {
        $sql = "SELECT retry_interval FROM campaigns c 
                JOIN leads l ON c.id = l.campaign_id 
                WHERE l.id = :lead_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':lead_id' => $leadId]);
        $result = $stmt->fetch();
        
        if ($result) {
            $nextAttempt = date('Y-m-d H:i:s', time() + $result['retry_interval']);
            
            $sql = "UPDATE leads SET status = 'pending', next_attempt = :next_attempt WHERE id = :lead_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':lead_id' => $leadId,
                ':next_attempt' => $nextAttempt
            ]);
        }
    }
    
    private function getStatusFromHangupCause($cause) {
        $statuses = [
            16 => 'answered',       // Normal clearing - call was answered
            17 => 'busy',           // User busy
            18 => 'no_answer',      // No user responding
            19 => 'no_answer',      // No answer from user
            20 => 'no_answer',      // Subscriber absent
            21 => 'failed',         // Call rejected
            22 => 'failed',         // Number changed
            27 => 'failed',         // Destination out of order
            28 => 'failed',         // Invalid number format
            34 => 'failed',         // No circuit/channel available
            38 => 'failed',         // Network out of order
            41 => 'failed',         // Temporary failure
            42 => 'failed',         // Switching equipment congestion
            44 => 'failed',         // Requested channel not available
        ];

        return $statuses[$cause] ?? 'failed';
    }

    private function getDispositionFromHangupCause($cause) {
        $dispositions = [
            16 => 'ANSWERED',       // Normal clearing
            17 => 'BUSY',           // User busy
            18 => 'NO ANSWER',      // No user responding
            19 => 'NO ANSWER',      // No answer from user
            20 => 'NO ANSWER',      // Subscriber absent
            21 => 'REJECTED',       // Call rejected
            22 => 'INVALID',        // Number changed
            27 => 'UNREACHABLE',    // Destination out of order
            28 => 'INVALID',        // Invalid number format
            34 => 'CONGESTION',     // No circuit/channel available
            38 => 'UNREACHABLE',    // Network out of order
            41 => 'FAILED',         // Temporary failure
            42 => 'CONGESTION',     // Switching equipment congestion
            44 => 'CONGESTION',     // Requested channel not available
        ];

        return $dispositions[$cause] ?? 'FAILED';
    }
    
    public function getActiveChannels() {
        try {
            return $this->ari->getChannels();
        } catch (Exception $e) {
            return [];
        }
    }
    
    public function hangupCall($channelId) {
        try {
            return $this->ari->hangupChannel($channelId);
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}