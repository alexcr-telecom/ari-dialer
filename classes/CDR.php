<?php
require_once __DIR__ . '/../config/database.php';

class CDR {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function getCallRecords($filters = [], $limit = 100, $offset = 0) {
        $sql = "SELECT 
                    cl.id,
                    cl.phone_number,
                    cl.agent_extension,
                    cl.call_start,
                    cl.call_end,
                    cl.duration,
                    cl.status,
                    cl.disposition,
                    cl.recording_file,
                    c.name as campaign_name,
                    l.name as lead_name
                FROM call_logs cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                LEFT JOIN leads l ON cl.lead_id = l.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(cl.call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(cl.call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['phone_number'])) {
            $sql .= " AND cl.phone_number LIKE :phone_number";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }
        
        if (!empty($filters['campaign_id'])) {
            $sql .= " AND cl.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }
        
        if (!empty($filters['disposition'])) {
            $sql .= " AND cl.disposition = :disposition";
            $params[':disposition'] = $filters['disposition'];
        }
        
        if (!empty($filters['agent_extension'])) {
            $sql .= " AND cl.agent_extension = :agent_extension";
            $params[':agent_extension'] = $filters['agent_extension'];
        }
        
        if (!empty($filters['min_duration'])) {
            $sql .= " AND cl.duration >= :min_duration";
            $params[':min_duration'] = $filters['min_duration'];
        }
        
        if (!empty($filters['max_duration'])) {
            $sql .= " AND cl.duration <= :max_duration";
            $params[':max_duration'] = $filters['max_duration'];
        }
        
        $sql .= " ORDER BY cl.call_start DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getCallRecordCount($filters = []) {
        $sql = "SELECT COUNT(*) as total 
                FROM call_logs cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(cl.call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(cl.call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['phone_number'])) {
            $sql .= " AND cl.phone_number LIKE :phone_number";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }
        
        if (!empty($filters['campaign_id'])) {
            $sql .= " AND cl.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }
        
        if (!empty($filters['disposition'])) {
            $sql .= " AND cl.disposition = :disposition";
            $params[':disposition'] = $filters['disposition'];
        }
        
        if (!empty($filters['agent_extension'])) {
            $sql .= " AND cl.agent_extension = :agent_extension";
            $params[':agent_extension'] = $filters['agent_extension'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getStatistics($filters = []) {
        $sql = "SELECT 
                    COUNT(*) as total_calls,
                    COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) as answered_calls,
                    COUNT(CASE WHEN disposition = 'BUSY' THEN 1 END) as busy_calls,
                    COUNT(CASE WHEN disposition = 'NO ANSWER' THEN 1 END) as no_answer_calls,
                    COUNT(CASE WHEN disposition NOT IN ('ANSWERED', 'BUSY', 'NO ANSWER') THEN 1 END) as failed_calls,
                    AVG(duration) as avg_duration,
                    SUM(duration) as total_duration,
                    ROUND((COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) / COUNT(*)) * 100, 2) as answer_rate
                FROM call_logs cl
                WHERE cl.call_start IS NOT NULL";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(cl.call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(cl.call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['campaign_id'])) {
            $sql .= " AND cl.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    public function exportToCSV($filters = []) {
        $records = $this->getCallRecords($filters, 10000, 0);
        
        $filename = 'cdr_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = sys_get_temp_dir() . '/' . $filename;
        
        $file = fopen($filepath, 'w');
        
        fputcsv($file, [
            'ID', 'Campaign', 'Phone Number', 'Lead Name', 'Agent Extension',
            'Call Start', 'Call End', 'Duration (seconds)', 'Status', 'Disposition'
        ]);
        
        foreach ($records as $record) {
            fputcsv($file, [
                $record['id'],
                $record['campaign_name'],
                $record['phone_number'],
                $record['lead_name'],
                $record['agent_extension'],
                $record['call_start'],
                $record['call_end'],
                $record['duration'],
                $record['status'],
                $record['disposition']
            ]);
        }
        
        fclose($file);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'records_count' => count($records)
        ];
    }
    
    public function getDispositions() {
        $sql = "SELECT DISTINCT disposition FROM call_logs WHERE disposition IS NOT NULL ORDER BY disposition";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getAgentExtensions() {
        $sql = "SELECT DISTINCT agent_extension FROM call_logs WHERE agent_extension IS NOT NULL ORDER BY agent_extension";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getCampaigns() {
        $sql = "SELECT id, name FROM campaigns ORDER BY name";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
    
    public function getCallsByHour($filters = []) {
        $sql = "SELECT 
                    HOUR(call_start) as hour,
                    COUNT(*) as call_count,
                    COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) as answered_count
                FROM call_logs 
                WHERE call_start IS NOT NULL";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        $sql .= " GROUP BY HOUR(call_start) ORDER BY hour";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getCallsByDate($filters = [], $days = 7) {
        $sql = "SELECT 
                    DATE(call_start) as call_date,
                    COUNT(*) as call_count,
                    COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) as answered_count
                FROM call_logs 
                WHERE call_start IS NOT NULL 
                AND call_start >= DATE_SUB(NOW(), INTERVAL :days DAY)";
        
        $params = [':days' => $days];
        
        if (!empty($filters['campaign_id'])) {
            $sql .= " AND campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }
        
        $sql .= " GROUP BY DATE(call_start) ORDER BY call_date";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getTopPerformingCampaigns($limit = 10) {
        $sql = "SELECT 
                    c.name,
                    COUNT(cl.id) as total_calls,
                    COUNT(CASE WHEN cl.disposition = 'ANSWERED' THEN 1 END) as answered_calls,
                    ROUND((COUNT(CASE WHEN cl.disposition = 'ANSWERED' THEN 1 END) / COUNT(cl.id)) * 100, 2) as success_rate,
                    AVG(cl.duration) as avg_duration
                FROM campaigns c
                LEFT JOIN call_logs cl ON c.id = cl.campaign_id
                WHERE cl.call_start IS NOT NULL
                GROUP BY c.id, c.name
                HAVING total_calls > 0
                ORDER BY success_rate DESC, total_calls DESC
                LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}