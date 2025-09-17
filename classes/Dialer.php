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

            // Create the outbound call using ARI originateCall method
            $response = $this->ari->originateCall($endpoint, $agentExtension, $agentContext, 1, $variables);

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
        
        if (!$channelId) return;
        
        $callLog = $this->getCallLogByChannelId($channelId);
        if (!$callLog) return;
        
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
        }
    }
    
    private function handleChannelStateChange($event, $callLog) {
        $state = $event['channel']['state'] ?? null;
        $channelId = $event['channel']['id'];
        $channel = $event['channel'];
        
        switch ($state) {
            case 'Ringing':
                if ($callLog) {
                    $this->updateCallLog($callLog['id'], ['status' => 'ringing']);
                }
                break;
                
            case 'Up':
                // Call was answered
                if ($callLog) {
                    $this->updateCallLog($callLog['id'], [
                        'status' => 'answered',
                        'call_start' => date('Y-m-d H:i:s')
                    ]);
                    
                    $this->campaign->updateLeadStatus($callLog['lead_id'], 'answered');
                }
                
                // If this is an outbound call managed by our ARI app, connect to agent
                if (isset($channel['channelvars']['AGENT_EXTENSION'])) {
                    $agentExtension = $channel['channelvars']['AGENT_EXTENSION'];
                    $agentContext = $channel['channelvars']['AGENT_CONTEXT'] ?? 'from-internal';
                    $this->connectToAgent($channelId, $agentExtension, $agentContext);
                }
                
                if ($this->shouldRecord()) {
                    $this->ari->startRecording($channelId);
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
        $disposition = $this->getDispositionFromHangupCause($hangupCause);
        
        $this->handleCallEnd($callLog, 'hung_up', $disposition);
    }
    
    private function handleCallEnd($callLog, $status, $disposition = null) {
        $callEnd = date('Y-m-d H:i:s');
        $duration = 0;
        
        if ($callLog['call_start']) {
            $duration = strtotime($callEnd) - strtotime($callLog['call_start']);
        }
        
        $this->updateCallLog($callLog['id'], [
            'status' => $status,
            'call_end' => $callEnd,
            'duration' => $duration,
            'disposition' => $disposition
        ]);
        
        if ($callLog['lead_id']) {
            $leadStatus = $disposition === 'ANSWERED' ? 'answered' : 
                         ($disposition === 'BUSY' ? 'busy' : 
                         ($disposition === 'NO ANSWER' ? 'no_answer' : 'failed'));
            
            $this->campaign->updateLeadStatus($callLog['lead_id'], $leadStatus, $disposition);
            
            if ($leadStatus !== 'answered' && $this->shouldRetry($callLog['lead_id'])) {
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
    
    private function getDispositionFromHangupCause($cause) {
        $dispositions = [
            16 => 'ANSWERED',       // Normal clearing
            17 => 'BUSY',           // User busy
            18 => 'NO ANSWER',      // No user responding
            19 => 'NO ANSWER',      // No answer from user
            21 => 'REJECTED',       // Call rejected
            27 => 'UNREACHABLE',    // Destination out of order
            34 => 'CONGESTION',     // No circuit/channel available
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