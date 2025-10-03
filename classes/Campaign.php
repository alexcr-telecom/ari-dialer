<?php
require_once __DIR__ . '/../config/database.php';

class Campaign {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function create($data) {
        // Process destination data based on type
        $processedData = $this->processDestinationData($data);

        $sql = "INSERT INTO campaigns (name, description, status, start_date, end_date, context, outbound_context, extension, destination_type, ivr_id, queue_extension, agent_extension, priority, max_calls_per_minute, retry_attempts, retry_interval)
                VALUES (:name, :description, :status, :start_date, :end_date, :context, :outbound_context, :extension, :destination_type, :ivr_id, :queue_extension, :agent_extension, :priority, :max_calls_per_minute, :retry_attempts, :retry_interval)";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? '',
            ':status' => $data['status'] ?? 'paused',
            ':start_date' => $data['start_date'] ?? null,
            ':end_date' => $data['end_date'] ?? null,
            ':context' => $processedData['context'],
            ':outbound_context' => $data['outbound_context'] ?? 'from-internal',
            ':extension' => $processedData['extension'],
            ':destination_type' => $processedData['destination_type'],
            ':ivr_id' => $processedData['ivr_id'],
            ':queue_extension' => $processedData['queue_extension'],
            ':agent_extension' => $processedData['agent_extension'],
            ':priority' => $data['priority'] ?? 1,
            ':max_calls_per_minute' => $data['max_calls_per_minute'] ?? 10,
            ':retry_attempts' => $data['retry_attempts'] ?? 3,
            ':retry_interval' => $data['retry_interval'] ?? 300
        ]);

        if ($result) {
            return $this->db->lastInsertId();
        }
        return false;
    }
    
    public function getAll($filters = []) {
        $sql = "SELECT * FROM campaign_stats WHERE 1=1";
        $params = [];
        
        if (!empty($filters['name'])) {
            $sql .= " AND name LIKE :name";
            $params[':name'] = '%' . $filters['name'] . '%';
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        
        $sql .= " ORDER BY id DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getById($id) {
        $sql = "SELECT * FROM campaigns WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function update($id, $data) {
        // Process destination data based on type
        $processedData = $this->processDestinationData($data);

        $sql = "UPDATE campaigns SET
                name = :name,
                description = :description,
                status = :status,
                start_date = :start_date,
                end_date = :end_date,
                context = :context,
                outbound_context = :outbound_context,
                extension = :extension,
                destination_type = :destination_type,
                ivr_id = :ivr_id,
                queue_extension = :queue_extension,
                agent_extension = :agent_extension,
                priority = :priority,
                max_calls_per_minute = :max_calls_per_minute,
                retry_attempts = :retry_attempts,
                retry_interval = :retry_interval
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':status' => $data['status'],
            ':start_date' => $data['start_date'] ?? null,
            ':end_date' => $data['end_date'] ?? null,
            ':context' => $processedData['context'],
            ':outbound_context' => $data['outbound_context'],
            ':extension' => $processedData['extension'],
            ':destination_type' => $processedData['destination_type'],
            ':ivr_id' => $processedData['ivr_id'],
            ':queue_extension' => $processedData['queue_extension'],
            ':agent_extension' => $processedData['agent_extension'],
            ':priority' => $data['priority'],
            ':max_calls_per_minute' => $data['max_calls_per_minute'],
            ':retry_attempts' => $data['retry_attempts'],
            ':retry_interval' => $data['retry_interval']
        ]);
    }
    
    public function delete($id) {
        $sql = "DELETE FROM campaigns WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function duplicate($id) {
        // Get the original campaign
        $original = $this->getById($id);
        if (!$original) {
            return false;
        }

        // Create a copy with modified name
        $copyData = [
            'name' => $original['name'] . ' (Copy)',
            'description' => $original['description'],
            'status' => 'paused', // Always start as paused
            'start_date' => $original['start_date'],
            'end_date' => $original['end_date'],
            'context' => $original['context'],
            'outbound_context' => $original['outbound_context'],
            'extension' => $original['extension'],
            'destination_type' => $original['destination_type'],
            'ivr_id' => $original['ivr_id'],
            'queue_extension' => $original['queue_extension'],
            'agent_extension' => $original['agent_extension'],
            'priority' => $original['priority'],
            'max_calls_per_minute' => $original['max_calls_per_minute'],
            'retry_attempts' => $original['retry_attempts'],
            'retry_interval' => $original['retry_interval']
        ];

        return $this->create($copyData);
    }
    
    public function addLeadOld($campaignId, $phoneNumber, $name = null) {
        $sql = "INSERT INTO leads (campaign_id, phone_number, name) VALUES (:campaign_id, :phone_number, :name)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':campaign_id' => $campaignId,
            ':phone_number' => $this->formatPhoneNumber($phoneNumber),
            ':name' => $name
        ]);
    }
    
    public function addLeadsBulk($campaignId, $leads) {
        $this->db->beginTransaction();
        try {
            $sql = "INSERT IGNORE INTO leads (campaign_id, phone_number, name) VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            
            foreach ($leads as $lead) {
                $phone = $this->formatPhoneNumber($lead['phone']);
                if (!$this->isDncNumber($phone)) {
                    $stmt->execute([$campaignId, $phone, $lead['name'] ?? null]);
                }
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    public function getLeadsOld($campaignId, $status = null, $limit = null, $offset = null) {
        $sql = "SELECT * FROM leads WHERE campaign_id = :campaign_id";
        $params = [':campaign_id' => $campaignId];
        
        if ($status) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }
        
        $sql .= " ORDER BY created_at ASC";
        
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateLeadStatus($leadId, $status, $disposition = null, $notes = null) {
        $sql = "UPDATE leads SET status = :status, disposition = :disposition, notes = :notes, updated_at = CURRENT_TIMESTAMP";
        
        if ($status === 'dialed') {
            $sql .= ", last_attempt = CURRENT_TIMESTAMP, attempts = attempts + 1";
        }
        
        $sql .= " WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':id' => $leadId,
            ':status' => $status,
            ':disposition' => $disposition,
            ':notes' => $notes
        ]);
    }
    
    private function formatPhoneNumber($phone) {
        // Keep numbers in their original format - remove only non-numeric/+ characters
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        // Don't add +1 prefix - keep numbers as imported/added
        return $phone;
    }
    
    private function isDncNumber($phone) {
        $sql = "SELECT COUNT(*) FROM dnc_list WHERE phone_number = :phone";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':phone' => $phone]);
        return $stmt->fetchColumn() > 0;
    }
    
    public function getStats($campaignId = null) {
        if ($campaignId) {
            $sql = "SELECT * FROM campaign_stats WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $campaignId]);
            return $stmt->fetch();
        } else {
            $sql = "SELECT 
                        COUNT(*) as total_campaigns,
                        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_campaigns,
                        SUM(total_leads) as total_leads,
                        SUM(answered_leads) as answered_leads,
                        ROUND(AVG(success_rate), 2) as avg_success_rate
                    FROM campaign_stats";
            $stmt = $this->db->query($sql);
            return $stmt->fetch();
        }
    }
    
    // API-specific methods for leads management
    public function addLead($data) {
        $sql = "INSERT INTO leads (campaign_id, phone_number, first_name, last_name, email, status, priority) 
                VALUES (:campaign_id, :phone_number, :first_name, :last_name, :email, :status, :priority)";
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            ':campaign_id' => $data['campaign_id'],
            ':phone_number' => $data['phone'],
            ':first_name' => $data['first_name'] ?? '',
            ':last_name' => $data['last_name'] ?? '',
            ':email' => $data['email'] ?? '',
            ':status' => $data['status'] ?? 'pending',
            ':priority' => $data['priority'] ?? 5
        ]);
        
        if ($result) {
            return $this->db->lastInsertId();
        }
        return false;
    }
    
    public function getLeadById($campaignId, $leadId) {
        $sql = "SELECT * FROM leads WHERE campaign_id = :campaign_id AND id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':campaign_id' => $campaignId, ':id' => $leadId]);
        return $stmt->fetch();
    }
    
    public function getLeads($filters) {
        $sql = "SELECT * FROM leads WHERE campaign_id = :campaign_id";
        $params = [':campaign_id' => $filters['campaign_id'] ?? null];
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['phone'])) {
            $sql .= " AND phone_number = :phone";
            $params[':phone'] = $filters['phone'];
        }
        
        $sql .= " ORDER BY created_at ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateLead($campaignId, $leadId, $data) {
        $setParts = [];
        $params = [':campaign_id' => $campaignId, ':id' => $leadId];
        
        foreach ($data as $field => $value) {
            if ($field === 'phone') {
                $setParts[] = "phone_number = :phone_number";
                $params[':phone_number'] = $value;
            } else {
                $setParts[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        if (empty($setParts)) {
            return false;
        }
        
        $sql = "UPDATE leads SET " . implode(', ', $setParts) . " WHERE campaign_id = :campaign_id AND id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function deleteLead($campaignId, $leadId) {
        $sql = "DELETE FROM leads WHERE campaign_id = :campaign_id AND id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':campaign_id' => $campaignId, ':id' => $leadId]);
    }
    
    public function bulkImportLeads($leads) {
        $this->db->beginTransaction();
        try {
            $imported = 0;
            $skipped = 0;
            
            $sql = "INSERT IGNORE INTO leads (campaign_id, phone_number, first_name, last_name, email, status, priority) 
                    VALUES (:campaign_id, :phone_number, :first_name, :last_name, :email, :status, :priority)";
            $stmt = $this->db->prepare($sql);
            
            foreach ($leads as $lead) {
                $result = $stmt->execute([
                    ':campaign_id' => $lead['campaign_id'],
                    ':phone_number' => $lead['phone'],
                    ':first_name' => $lead['first_name'] ?? '',
                    ':last_name' => $lead['last_name'] ?? '',
                    ':email' => $lead['email'] ?? '',
                    ':status' => $lead['status'] ?? 'pending',
                    ':priority' => $lead['priority'] ?? 5
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }
            
            $this->db->commit();
            return ['success' => true, 'imported' => $imported, 'skipped' => $skipped];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Bulk import error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process destination data based on destination type
     * @param array $data
     * @return array
     */
    private function processDestinationData($data) {
        $destinationType = $data['destination_type'] ?? 'custom';
        $result = [
            'destination_type' => $destinationType,
            'ivr_id' => null,
            'queue_extension' => null,
            'agent_extension' => null,
            'context' => $data['context'] ?? 'from-internal',
            'extension' => $data['extension'] ?? '101'
        ];

        switch ($destinationType) {
            case 'ivr':
                $result['ivr_id'] = !empty($data['ivr_id']) ? $data['ivr_id'] : null;
                if ($result['ivr_id']) {
                    $result['context'] = "ivr-{$result['ivr_id']}";
                    $result['extension'] = 's'; // IVRs typically start with 's' extension
                }
                break;

            case 'queue':
                $result['queue_extension'] = !empty($data['queue_extension']) ? $data['queue_extension'] : null;
                if ($result['queue_extension']) {
                    $result['context'] = 'ext-queues';
                    $result['extension'] = $result['queue_extension'];
                }
                break;

            case 'extension':
                $result['agent_extension'] = !empty($data['agent_extension']) ? $data['agent_extension'] : null;
                if ($result['agent_extension']) {
                    $result['context'] = 'from-internal';
                    $result['extension'] = $result['agent_extension'];
                }
                break;

            case 'custom':
            default:
                // Use the provided context and extension values
                $result['context'] = $data['context'] ?? 'from-internal';
                $result['extension'] = $data['extension'] ?? '101';
                break;
        }

        return $result;
    }
}