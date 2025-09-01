<?php
require_once __DIR__ . '/../config/database.php';

class Campaign {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function create($data) {
        $sql = "INSERT INTO campaigns (name, description, status, start_date, end_date, context, outbound_context, extension, priority, max_calls_per_minute, retry_attempts, retry_interval) 
                VALUES (:name, :description, :status, :start_date, :end_date, :context, :outbound_context, :extension, :priority, :max_calls_per_minute, :retry_attempts, :retry_interval)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':status' => $data['status'] ?? 'paused',
            ':start_date' => $data['start_date'] ?? null,
            ':end_date' => $data['end_date'] ?? null,
            ':context' => $data['context'] ?? Config::ASTERISK_CONTEXT,
            ':outbound_context' => $data['outbound_context'] ?? 'from-internal',
            ':extension' => $data['extension'] ?? '101',
            ':priority' => $data['priority'] ?? 1,
            ':max_calls_per_minute' => $data['max_calls_per_minute'] ?? 10,
            ':retry_attempts' => $data['retry_attempts'] ?? 3,
            ':retry_interval' => $data['retry_interval'] ?? 300
        ]);
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
        $sql = "UPDATE campaigns SET 
                name = :name, 
                description = :description, 
                status = :status, 
                start_date = :start_date, 
                end_date = :end_date, 
                context = :context, 
                outbound_context = :outbound_context,
                extension = :extension, 
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
            ':context' => $data['context'],
            ':outbound_context' => $data['outbound_context'],
            ':extension' => $data['extension'],
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
    
    public function addLead($campaignId, $phoneNumber, $name = null) {
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
    
    public function getLeads($campaignId, $status = null, $limit = null, $offset = null) {
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
}