<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../classes/CDR.php';

$cdr = new CDR();

$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'campaign_id' => $_GET['campaign_id'] ?? '',
    'agent_extension' => $_GET['agent_extension'] ?? ''
];

$filters = array_filter($filters, function($value) { return $value !== ''; });

try {
    $stats = $cdr->getStatistics($filters);
    
    $dispositions = [
        'ANSWERED' => $stats['answered_calls'] ?? 0,
        'BUSY' => $stats['busy_calls'] ?? 0,
        'NO ANSWER' => $stats['no_answer_calls'] ?? 0,
        'FAILED' => $stats['failed_calls'] ?? 0
    ];
    
    $hourly = $cdr->getCallsByHour($filters);
    $daily = $cdr->getCallsByDate($filters);
    
    echo json_encode([
        'dispositions' => $dispositions,
        'hourly' => $hourly,
        'daily' => $daily,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}