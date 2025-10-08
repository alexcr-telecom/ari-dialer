<?php
require_once __DIR__ . '/../config/database.php';

class CDR {
    private $cdrDb;
    private $dialerDb;
    private $cdrAvailable = false;

    public function __construct() {
        $this->cdrAvailable = Database::isCdrAvailable();
        $this->cdrDb = Database::getCdrConnectionInstance();
        $this->dialerDb = Database::getInstance()->getConnection();
    }

    /**
     * Check if CDR database is available
     */
    public function isCdrAvailable() {
        return $this->cdrAvailable && $this->cdrDb !== null;
    }

    public function getCallRecords($filters = [], $limit = 100, $offset = 0) {
        // Use dialer_cdr table which has campaign and lead information
        $sql = "SELECT
                    dc.id,
                    dc.uniqueid,
                    dc.phone_number as dst,
                    dc.phone_number as src,
                    dc.lead_name as clid,
                    dc.channel_id as channel,
                    '' as dstchannel,
                    'Dial' as lastapp,
                    dc.phone_number as lastdata,
                    dc.call_start as calldate,
                    dc.call_start as answer,
                    dc.call_end as end,
                    dc.duration,
                    dc.billsec,
                    dc.disposition,
                    '' as accountcode,
                    '' as userfield,
                    '' as recordingfile,
                    c.name as campaign_name,
                    dc.lead_name,
                    dc.phone_number,
                    dc.agent_extension
                FROM dialer_cdr dc
                LEFT JOIN campaigns c ON dc.campaign_id = c.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(dc.call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(dc.call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['phone_number'])) {
            $sql .= " AND dc.phone_number LIKE :phone_number";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }

        if (!empty($filters['campaign_id'])) {
            $sql .= " AND dc.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }

        if (!empty($filters['disposition'])) {
            $sql .= " AND dc.disposition = :disposition";
            $params[':disposition'] = $filters['disposition'];
        }

        if (!empty($filters['agent_extension'])) {
            $sql .= " AND dc.agent_extension = :agent_extension";
            $params[':agent_extension'] = $filters['agent_extension'];
        }

        if (!empty($filters['min_duration'])) {
            $sql .= " AND dc.duration >= :min_duration";
            $params[':min_duration'] = $filters['min_duration'];
        }

        if (!empty($filters['max_duration'])) {
            $sql .= " AND dc.duration <= :max_duration";
            $params[':max_duration'] = $filters['max_duration'];
        }

        $sql .= " ORDER BY dc.call_start DESC LIMIT :limit OFFSET :offset";

        // Add limit and offset to params
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $stmt = $this->dialerDb->prepare($sql);

        // Bind all parameters at once
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        $records = $stmt->fetchAll();

        // Format records for display
        foreach ($records as &$record) {
            $record['call_start'] = $record['calldate'];
            $record['status'] = strtolower($record['disposition'] ?? 'unknown');
            $record['recording_file'] = null;
        }

        return $records;
    }

    private function enhanceRecordsWithDialerData($records) {
        if (empty($records)) {
            return $records;
        }

        // Get phone numbers from CDR records
        $phoneNumbers = array_unique(array_column($records, 'dst'));
        $phoneNumbers = array_filter($phoneNumbers);

        if (empty($phoneNumbers)) {
            return $records;
        }

        // Get campaign and lead data for these phone numbers
        $placeholders = str_repeat('?,', count($phoneNumbers) - 1) . '?';
        $sql = "SELECT
                    l.phone_number,
                    l.name as lead_name,
                    c.name as campaign_name,
                    c.extension as agent_extension
                FROM leads l
                LEFT JOIN campaigns c ON l.campaign_id = c.id
                WHERE l.phone_number IN ($placeholders)";

        $stmt = $this->dialerDb->prepare($sql);
        $stmt->execute($phoneNumbers);
        $dialerData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Create lookup array
        $lookup = [];
        foreach ($dialerData as $row) {
            $lookup[$row['phone_number']] = $row;
        }

        // Enhance CDR records
        foreach ($records as &$record) {
            $phoneNumber = $record['dst'];
            if (isset($lookup[$phoneNumber])) {
                $record['campaign_name'] = $lookup[$phoneNumber]['campaign_name'] ?? '';
                $record['lead_name'] = $lookup[$phoneNumber]['lead_name'] ?? '';
                $record['agent_extension'] = $lookup[$phoneNumber]['agent_extension'] ?? '';
            }

            // Format fields for display
            $record['call_start'] = $record['calldate'];
            $record['call_end'] = $record['end'];
            $record['status'] = strtolower($record['disposition']);
            $record['recording_file'] = $record['recordingfile'] ?? null;
        }

        return $records;
    }


    public function getCallRecordCount($filters = []) {
        $sql = "SELECT COUNT(*) as total FROM dialer_cdr dc WHERE 1=1";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(dc.call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(dc.call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['phone_number'])) {
            $sql .= " AND dc.phone_number LIKE :phone_number";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }

        if (!empty($filters['campaign_id'])) {
            $sql .= " AND dc.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }

        if (!empty($filters['disposition'])) {
            $sql .= " AND dc.disposition = :disposition";
            $params[':disposition'] = $filters['disposition'];
        }

        if (!empty($filters['agent_extension'])) {
            $sql .= " AND dc.agent_extension = :agent_extension";
            $params[':agent_extension'] = $filters['agent_extension'];
        }

        $stmt = $this->dialerDb->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result['total'];
    }

    public function getStatistics($filters = []) {
        $sql = "SELECT
                    COUNT(*) as total_calls,
                    COUNT(CASE WHEN dc.disposition = 'ANSWERED' THEN 1 END) as answered_calls,
                    COUNT(CASE WHEN dc.disposition = 'BUSY' THEN 1 END) as busy_calls,
                    COUNT(CASE WHEN dc.disposition = 'NO ANSWER' THEN 1 END) as no_answer_calls,
                    COUNT(CASE WHEN dc.disposition NOT IN ('ANSWERED', 'BUSY', 'NO ANSWER') THEN 1 END) as failed_calls,
                    AVG(dc.duration) as avg_duration,
                    SUM(dc.duration) as total_duration,
                    ROUND((COUNT(CASE WHEN dc.disposition = 'ANSWERED' THEN 1 END) / COUNT(*)) * 100, 2) as answer_rate
                FROM dialer_cdr dc
                WHERE dc.call_start IS NOT NULL";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(dc.call_start) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(dc.call_start) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['campaign_id'])) {
            $sql .= " AND dc.campaign_id = :campaign_id";
            $params[':campaign_id'] = $filters['campaign_id'];
        }

        $stmt = $this->dialerDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function exportToCSV($filters = []) {
        $records = $this->getCallRecords($filters, 10000, 0);

        $filename = 'cdr_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = sys_get_temp_dir() . '/' . $filename;

        $file = fopen($filepath, 'w');

        fputcsv($file, [
            'Campaign',
            'Phone Number',
            'Lead Name',
            'Agent Extension',
            'Call Start',
            'Call End',
            'Duration (seconds)',
            'Status',
            'Disposition'
        ]);

        foreach ($records as $record) {
            fputcsv($file, [
                $record['campaign_name'] ?? '',
                $record['phone_number'] ?? '',
                $record['lead_name'] ?? '',
                $record['agent_extension'] ?? '',
                $record['calldate'] ?? '',
                $record['end'] ?? '',
                $record['duration'] ?? 0,
                $record['status'] ?? '',
                $record['disposition'] ?? ''
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
        $sql = "SELECT DISTINCT disposition FROM dialer_cdr WHERE disposition IS NOT NULL AND disposition != '' ORDER BY disposition";
        $stmt = $this->dialerDb->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAgentExtensions() {
        // Get agent extensions from campaigns table in dialer database
        $sql = "SELECT DISTINCT extension FROM campaigns WHERE extension IS NOT NULL AND extension != '' ORDER BY extension";
        $stmt = $this->dialerDb->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getCampaigns() {
        // Get campaigns from dialer database
        $sql = "SELECT id, name FROM campaigns ORDER BY name";
        $stmt = $this->dialerDb->query($sql);
        return $stmt->fetchAll();
    }

    public function getCallsByHour($filters = []) {
        if (!$this->isCdrAvailable()) {
            // Return empty array if CDR not available
            return [];
        }

        $sql = "SELECT
                    HOUR(calldate) as hour,
                    COUNT(*) as call_count,
                    COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) as answered_count
                FROM cdr
                WHERE calldate IS NOT NULL";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(calldate) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(calldate) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        $sql .= " GROUP BY HOUR(calldate) ORDER BY hour";

        $stmt = $this->cdrDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getCallsByDate($filters = [], $days = 7) {
        if (!$this->isCdrAvailable()) {
            // Return empty array if CDR not available
            return [];
        }

        $sql = "SELECT
                    DATE(calldate) as call_date,
                    COUNT(*) as call_count,
                    COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) as answered_count
                FROM cdr
                WHERE calldate IS NOT NULL
                AND calldate >= DATE_SUB(NOW(), INTERVAL :days DAY)";

        $params = [':days' => $days];

        $sql .= " GROUP BY DATE(calldate) ORDER BY call_date";

        $stmt = $this->cdrDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getTopPerformingCampaigns($limit = 10) {
        // Without call_logs, we use campaign_stats view
        $sql = "SELECT
                    name,
                    total_leads as total_calls,
                    answered_leads as answered_calls,
                    success_rate,
                    0 as avg_duration
                FROM campaign_stats
                WHERE total_leads > 0
                ORDER BY success_rate DESC, total_leads DESC
                LIMIT :limit";

        $stmt = $this->dialerDb->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function testConnection() {
        if (!$this->isCdrAvailable()) {
            return ['success' => false, 'message' => 'CDR database not available - running in standalone mode'];
        }

        try {
            $this->cdrDb->query("SELECT 1 FROM cdr LIMIT 1");
            return ['success' => true, 'message' => 'CDR database connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'CDR database connection failed: ' . $e->getMessage()];
        }
    }
}