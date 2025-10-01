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
            // If $lead is an ID, fetch the lead data
            if (is_numeric($lead)) {
                $leadId = $lead;
                $sql = "SELECT * FROM leads WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':id' => $leadId]);
                $lead = $stmt->fetch();

                if (!$lead) {
                    $this->log("Lead ID $leadId not found", 'ERROR');
                    return ['success' => false, 'message' => 'Lead not found'];
                }
            }

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
                'AGENT_CONTEXT' => $agentContext,
                'LEAD_NAME' => $lead['name'] ?? '',
                'CALLERID(name)' => $lead['name'] ?? '',
                'CALLERID(num)' => $lead['phone_number']
            ];

            $this->log("Making outbound call to: $endpoint for agent: $agentExtension");

            // Create caller ID object with separate name and number fields
            $callerId = $this->ari->createCallerID(
                !empty($lead['name']) ? $lead['name'] : '',
                $lead['phone_number']
            );

            $this->log("Using caller object - Name: '" . $callerId['name'] . "', Number: '" . $callerId['number'] . "'");

            // Create the outbound call using ARI originateCall method with CallerID object
            $response = $this->ari->originateCall($endpoint, $agentExtension, $agentContext, 1, $variables, $callerId);

            $this->log("ARI response: " . json_encode($response));

            if ($response && isset($response['id'])) {
                $this->log("Call originated successfully, channel ID: " . $response['id']);

                $this->campaign->updateLeadStatus($lead['id'], 'dialed');

                // Create dialer_cdr record immediately with channel information
                $this->createDialerCdr($lead['id'], $response['id'], $campaignId, $lead['phone_number'], $lead['name'] ?? '', $agentExtension);

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

        try {
            // Look up the dialer_cdr record by channel_id to get lead and campaign info
            $sql = "SELECT * FROM dialer_cdr WHERE channel_id = :channel_id ORDER BY created_at DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':channel_id' => $channelId]);
            $cdrRecord = $stmt->fetch();

            if (!$cdrRecord) {
                $this->log("No dialer_cdr record found for channel: $channelId (may not be a dialer-originated call)", 'DEBUG');
                return;
            }

            $leadId = $cdrRecord['lead_id'];
            $this->log("Processing event for lead ID: $leadId, channel: $channelId");

            switch ($eventType) {
                case 'ChannelStateChange':
                    $this->handleChannelStateChange($event, $leadId);
                    break;

                case 'ChannelDestroyed':
                    $this->handleChannelDestroyed($event, $leadId);
                    break;

                default:
                    $this->log("Unhandled event type: $eventType for channel: $channelId", 'DEBUG');
                    break;
            }

        } catch (Exception $e) {
            // Handle database connection errors gracefully
            if (strpos($e->getMessage(), 'MySQL server has gone away') !== false ||
                strpos($e->getMessage(), 'Lost connection') !== false) {
                $this->log("Database connection lost during event processing for channel $channelId: " . $e->getMessage(), 'ERROR');
                // Don't throw the error, just log it to prevent the WebSocket client from crashing
            } else {
                $this->log("Error processing channel event for $channelId: " . $e->getMessage(), 'ERROR');
                throw $e; // Re-throw non-database errors
            }
        }
    }
    
    private function handleChannelStateChange($event, $leadId) {
        $state = $event['channel']['state'] ?? null;
        $channelId = $event['channel']['id'];
        $channel = $event['channel'];

        $this->log("Channel state change for lead $leadId: $state");

        switch ($state) {
            case 'Ring':
            case 'Ringing':
                $this->campaign->updateLeadStatus($leadId, 'ringing');
                $this->log("ARI Event: Call ringing - Lead ID: $leadId, Channel: $channelId");
                break;

            case 'Up':
                // Call was answered
                $this->campaign->updateLeadStatus($leadId, 'answered');
                $this->log("ARI Event: Call answered - Lead ID: $leadId, Channel: $channelId");

                // If this is an outbound call managed by our ARI app, connect to agent
                if (isset($channel['channelvars']['AGENT_EXTENSION'])) {
                    $agentExtension = $channel['channelvars']['AGENT_EXTENSION'];
                    $agentContext = $channel['channelvars']['AGENT_CONTEXT'] ?? 'from-internal';
                    $this->connectToAgent($channelId, $agentExtension, $agentContext);
                }
                break;

            case 'Busy':
                $this->campaign->updateLeadStatus($leadId, 'busy', 'BUSY');
                $this->log("ARI Event: Line busy - Lead ID: $leadId, Channel: $channelId");
                break;

            case 'Down':
                // Will be handled by ChannelDestroyed event
                break;
        }
    }
    
    private function handleChannelDestroyed($event, $leadId) {
        $hangupCause = $event['cause'] ?? null;
        $hangupCauseText = $event['cause_txt'] ?? null;
        $channelId = $event['channel']['id'] ?? null;

        // Use ARI hangup cause to determine final call status
        $status = $this->getStatusFromHangupCause($hangupCause);
        $disposition = $this->getDispositionFromHangupCause($hangupCause);

        $this->log("Channel destroyed for lead $leadId - Hangup cause: $hangupCause ($hangupCauseText), Status: $status, Disposition: $disposition");

        // Update lead status based on ARI-determined call outcome
        $this->campaign->updateLeadStatus($leadId, $status, $disposition);

        // Save call to dialer_cdr after call ends
        $this->saveDialerCdr($leadId, $channelId, $status, $disposition);

        // Schedule retry for unsuccessful calls
        if ($status !== 'answered' && $this->shouldRetry($leadId)) {
            $this->scheduleRetry($leadId);
        }
    }

    private function createDialerCdr($leadId, $channelId, $campaignId, $phoneNumber, $leadName, $agentExtension) {
        try {
            $sql = "INSERT INTO dialer_cdr
                    (campaign_id, lead_id, channel_id, phone_number, lead_name, agent_extension, call_start, status)
                    VALUES (:campaign_id, :lead_id, :channel_id, :phone_number, :lead_name, :agent_extension, NOW(), 'initiated')";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':lead_id' => $leadId,
                ':channel_id' => $channelId,
                ':phone_number' => $phoneNumber,
                ':lead_name' => $leadName,
                ':agent_extension' => $agentExtension
            ]);

            $this->log("Created dialer_cdr record for lead $leadId, channel $channelId");
        } catch (Exception $e) {
            $this->log("Error creating dialer_cdr: " . $e->getMessage(), 'ERROR');
        }
    }

    private function saveDialerCdr($leadId, $channelId, $status, $disposition) {
        try {
            // Try to get CDR record from Asterisk CDR database
            $cdrDb = Database::getCdrConnectionInstance();
            $uniqueid = null;
            $callEnd = null;
            $duration = 0;
            $billsec = 0;

            if ($cdrDb && Database::isCdrAvailable()) {
                // Get the dialer_cdr record to find phone number
                $sql = "SELECT phone_number FROM dialer_cdr WHERE channel_id = :channel_id LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':channel_id' => $channelId]);
                $dialerRecord = $stmt->fetch();

                if ($dialerRecord) {
                    // Find CDR record by destination number
                    $cdrSql = "SELECT uniqueid, calldate, duration, billsec, disposition
                               FROM cdr
                               WHERE dst = :phone_number
                               ORDER BY calldate DESC
                               LIMIT 1";
                    $cdrStmt = $cdrDb->prepare($cdrSql);
                    $cdrStmt->execute([':phone_number' => $dialerRecord['phone_number']]);
                    $cdrRecord = $cdrStmt->fetch();

                    if ($cdrRecord) {
                        $uniqueid = $cdrRecord['uniqueid'];
                        $duration = $cdrRecord['duration'];
                        $billsec = $cdrRecord['billsec'];
                        // Calculate call end from start + duration
                        if ($cdrRecord['calldate'] && $duration) {
                            $callEnd = date('Y-m-d H:i:s', strtotime($cdrRecord['calldate']) + $duration);
                        }
                    }
                }
            }

            // If no CDR record found, use current time
            if (!$callEnd) {
                $callEnd = date('Y-m-d H:i:s');
            }

            // Update existing dialer_cdr record
            $updateSql = "UPDATE dialer_cdr
                          SET uniqueid = :uniqueid,
                              call_end = :call_end,
                              duration = :duration,
                              billsec = :billsec,
                              disposition = :disposition,
                              status = :status
                          WHERE channel_id = :channel_id";

            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([
                ':uniqueid' => $uniqueid,
                ':call_end' => $callEnd,
                ':duration' => $duration,
                ':billsec' => $billsec,
                ':disposition' => $disposition,
                ':status' => $status,
                ':channel_id' => $channelId
            ]);

            $this->log("Updated dialer_cdr for lead $leadId, channel $channelId, uniqueid: $uniqueid");

        } catch (Exception $e) {
            $this->log("Error updating dialer_cdr: " . $e->getMessage(), 'ERROR');
        }
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