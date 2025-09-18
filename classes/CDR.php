<?php
require_once __DIR__ . '/../config/cdr_database.php';
require_once __DIR__ . '/../config/database.php';

class CDR {
    private $cdrDb;
    private $dialerDb;

    public function __construct() {
        $this->cdrDb = CdrDatabase::getInstance()->getConnection();
        $this->dialerDb = Database::getInstance()->getConnection();
    }

    public function getCallRecords($filters = [], $limit = 100, $offset = 0) {
        $sql = "SELECT
                    cdr.uniqueid,
                    cdr.src,
                    cdr.dst,
                    cdr.clid,
                    cdr.channel,
                    cdr.dstchannel,
                    cdr.lastapp,
                    cdr.lastdata,
                    cdr.calldate,
                    '' as answer,
                    '' as end,
                    cdr.duration,
                    cdr.billsec,
                    cdr.disposition,
                    cdr.accountcode,
                    cdr.userfield,
                    '' as campaign_name,
                    '' as lead_name,
                    cdr.dst as phone_number,
                    '' as agent_extension
                FROM cdr
                WHERE 1=1";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(cdr.calldate) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(cdr.calldate) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['phone_number'])) {
            $sql .= " AND (cdr.src LIKE :phone_number OR cdr.dst LIKE :phone_number)";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }

        if (!empty($filters['disposition'])) {
            $sql .= " AND cdr.disposition = :disposition";
            $params[':disposition'] = $filters['disposition'];
        }

        if (!empty($filters['agent_extension'])) {
            $sql .= " AND (cdr.src = :agent_extension OR cdr.dst = :agent_extension)";
            $params[':agent_extension'] = $filters['agent_extension'];
        }

        if (!empty($filters['min_duration'])) {
            $sql .= " AND cdr.duration >= :min_duration";
            $params[':min_duration'] = $filters['min_duration'];
        }

        if (!empty($filters['max_duration'])) {
            $sql .= " AND cdr.duration <= :max_duration";
            $params[':max_duration'] = $filters['max_duration'];
        }

        $sql .= " ORDER BY cdr.calldate DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->cdrDb->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $records = $stmt->fetchAll();

        // Enhance records with campaign and lead info from dialer database
        return $this->enhanceRecordsWithDialerData($records);
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
            $record['recording_file'] = null; // CDR doesn't typically store recording file paths
        }

        return $records;
    }

    public function getCallRecordCount($filters = []) {
        $sql = "SELECT COUNT(*) as total FROM cdr WHERE 1=1";

        $params = [];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(calldate) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(calldate) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['phone_number'])) {
            $sql .= " AND (src LIKE :phone_number OR dst LIKE :phone_number)";
            $params[':phone_number'] = '%' . $filters['phone_number'] . '%';
        }

        if (!empty($filters['disposition'])) {
            $sql .= " AND disposition = :disposition";
            $params[':disposition'] = $filters['disposition'];
        }

        if (!empty($filters['agent_extension'])) {
            $sql .= " AND (src = :agent_extension OR dst = :agent_extension)";
            $params[':agent_extension'] = $filters['agent_extension'];
        }

        $stmt = $this->cdrDb->prepare($sql);
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

        $stmt = $this->cdrDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function exportToCSV($filters = []) {
        $records = $this->getCallRecords($filters, 10000, 0);

        $filename = 'cdr_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = sys_get_temp_dir() . '/' . $filename;

        $file = fopen($filepath, 'w');

        fputcsv($file, [
            'Unique ID', 'Source', 'Destination', 'Caller ID', 'Channel', 'Destination Channel',
            'Last App', 'Last Data', 'Call Start', 'Answer Time', 'Call End',
            'Duration (seconds)', 'Bill Seconds', 'Disposition', 'Account Code', 'User Field',
            'Campaign', 'Lead Name'
        ]);

        foreach ($records as $record) {
            fputcsv($file, [
                $record['uniqueid'],
                $record['src'],
                $record['dst'],
                $record['clid'],
                $record['channel'],
                $record['dstchannel'],
                $record['lastapp'],
                $record['lastdata'],
                $record['calldate'],
                $record['answer'],
                $record['end'],
                $record['duration'],
                $record['billsec'],
                $record['disposition'],
                $record['accountcode'],
                $record['userfield'],
                $record['campaign_name'],
                $record['lead_name']
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
        $sql = "SELECT DISTINCT disposition FROM cdr WHERE disposition IS NOT NULL AND disposition != '' ORDER BY disposition";
        $stmt = $this->cdrDb->query($sql);
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
        // This would require more complex logic to correlate CDR with campaigns
        // For now, return data from dialer database
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

        $stmt = $this->dialerDb->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function testConnection() {
        try {
            $this->cdrDb->query("SELECT 1 FROM cdr LIMIT 1");
            return ['success' => true, 'message' => 'CDR database connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'CDR database connection failed: ' . $e->getMessage()];
        }
    }
}