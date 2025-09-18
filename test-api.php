<?php
/**
 * API Testing Script
 * 
 * Tests all API endpoints to ensure they're working correctly
 * 
 * Usage: php test-api.php
 */

class APITester {
    private $baseUrl;
    private $results = [];
    
    public function __construct($baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function runTests() {
        echo "🚀 Starting API Tests\n";
        echo "Base URL: {$this->baseUrl}\n\n";
        
        // Test System Status
        $this->testSystemStatus();
        
        // Test Charts API
        $this->testChartsAPI();
        
        // Test Campaigns API
        $this->testCampaignsAPI();
        
        // Test Leads API (requires campaign ID from campaigns test)
        $this->testLeadsAPI();
        
        $this->printSummary();
    }
    
    private function testSystemStatus() {
        echo "📊 Testing System Status API\n";
        
        $response = $this->makeRequest('GET', '/api/status.php');
        $this->assertResponse('System Status', $response, 200);
        
        if ($response && isset($response['data']['health'])) {
            $health = $response['data']['health']['overall'];
            echo "   System Health: $health\n";
        }
        
        echo "\n";
    }
    
    private function testChartsAPI() {
        echo "📈 Testing Charts API\n";
        
        // Test overview
        $response = $this->makeRequest('GET', '/api/charts.php?type=overview');
        $this->assertResponse('Charts Overview', $response, 200);
        
        // Test dispositions
        $response = $this->makeRequest('GET', '/api/charts.php?type=dispositions');
        $this->assertResponse('Charts Dispositions', $response, 200);
        
        // Test with date filter
        $response = $this->makeRequest('GET', '/api/charts.php?type=daily&date_from=2024-01-01&date_to=2024-01-31');
        $this->assertResponse('Charts Daily with Date Filter', $response, 200);
        
        // Test invalid type
        $response = $this->makeRequest('GET', '/api/charts.php?type=invalid');
        $this->assertResponse('Charts Invalid Type', $response, 400, false);
        
        echo "\n";
    }
    
    private function testCampaignsAPI() {
        echo "📋 Testing Campaigns API\n";
        
        // Test GET all campaigns
        $response = $this->makeRequest('GET', '/api/campaigns.php');
        $this->assertResponse('Get All Campaigns', $response, 200);
        
        // Test POST create campaign
        $campaignData = [
            'action' => 'create',
            'name' => 'Test Campaign ' . date('Y-m-d H:i:s'),
            'context' => 'from-internal',
            'max_calls_per_minute' => 50,
            'agent_extension' => '1001',
            'description' => 'API Test Campaign'
        ];
        
        $response = $this->makeRequest('POST', '/api/campaigns.php', $campaignData);
        $this->assertResponse('Create Campaign', $response, 201);
        
        $campaignId = null;
        if ($response && isset($response['data']['id'])) {
            $campaignId = $response['data']['id'];
            $this->results['test_campaign_id'] = $campaignId;
            echo "   Created Campaign ID: $campaignId\n";
        }
        
        // Test GET specific campaign
        if ($campaignId) {
            $response = $this->makeRequest('GET', "/api/campaigns.php?id=$campaignId");
            $this->assertResponse('Get Specific Campaign', $response, 200);
        }
        
        // Test PUT update campaign
        if ($campaignId) {
            $updateData = [
                'id' => $campaignId,
                'name' => 'Updated Test Campaign',
                'max_calls_per_minute' => 75
            ];
            
            $response = $this->makeRequest('PUT', '/api/campaigns.php', $updateData);
            $this->assertResponse('Update Campaign', $response, 200);
        }
        
        // Test campaign actions (if campaign exists)
        if ($campaignId) {
            // Test start campaign
            $response = $this->makeRequest('POST', '/api/campaigns.php', [
                'action' => 'start',
                'id' => $campaignId
            ]);
            $this->assertResponse('Start Campaign', $response, [200, 500]); // May fail if no leads
            
            // Test pause campaign
            $response = $this->makeRequest('POST', '/api/campaigns.php', [
                'action' => 'pause',
                'id' => $campaignId
            ]);
            $this->assertResponse('Pause Campaign', $response, [200, 500]);
            
            // Test stop campaign
            $response = $this->makeRequest('POST', '/api/campaigns.php', [
                'action' => 'stop',
                'id' => $campaignId
            ]);
            $this->assertResponse('Stop Campaign', $response, [200, 500]);
        }
        
        // Test invalid requests
        $response = $this->makeRequest('POST', '/api/campaigns.php', ['action' => 'invalid']);
        $this->assertResponse('Invalid Action', $response, 400, false);
        
        echo "\n";
    }
    
    private function testLeadsAPI() {
        echo "👥 Testing Leads API\n";
        
        $campaignId = $this->results['test_campaign_id'] ?? 1;
        
        // Test POST create lead
        $leadData = [
            'action' => 'create',
            'campaign_id' => $campaignId,
            'phone' => '15551234567',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com'
        ];
        
        $response = $this->makeRequest('POST', '/api/leads.php', $leadData);
        $this->assertResponse('Create Lead', $response, 201);
        
        $leadId = null;
        if ($response && isset($response['data']['id'])) {
            $leadId = $response['data']['id'];
            echo "   Created Lead ID: $leadId\n";
        }
        
        // Test GET leads for campaign
        $response = $this->makeRequest('GET', "/api/leads.php?campaign_id=$campaignId");
        $this->assertResponse('Get Campaign Leads', $response, 200);
        
        // Test GET specific lead
        if ($leadId) {
            $response = $this->makeRequest('GET', "/api/leads.php?campaign_id=$campaignId&id=$leadId");
            $this->assertResponse('Get Specific Lead', $response, 200);
        }
        
        // Test PUT update lead
        if ($leadId) {
            $updateData = [
                'campaign_id' => $campaignId,
                'id' => $leadId,
                'first_name' => 'Johnny',
                'status' => 'dialed'
            ];
            
            $response = $this->makeRequest('PUT', '/api/leads.php', $updateData);
            $this->assertResponse('Update Lead', $response, 200);
        }
        
        // Test bulk import
        $bulkData = [
            'action' => 'bulk_import',
            'campaign_id' => $campaignId,
            'leads' => [
                ['phone' => '15551234568', 'first_name' => 'Jane', 'last_name' => 'Smith'],
                ['phone' => '15551234569', 'first_name' => 'Bob', 'last_name' => 'Johnson'],
                ['phone' => '15551234570', 'first_name' => 'Alice', 'last_name' => 'Williams']
            ]
        ];
        
        $response = $this->makeRequest('POST', '/api/leads.php', $bulkData);
        $this->assertResponse('Bulk Import Leads', $response, 201);
        
        if ($response && isset($response['data']['imported'])) {
            echo "   Bulk Import: {$response['data']['imported']} leads imported\n";
        }
        
        // Test invalid requests
        $response = $this->makeRequest('GET', '/api/leads.php'); // Missing campaign_id
        $this->assertResponse('Missing Campaign ID', $response, 400, false);
        
        $response = $this->makeRequest('POST', '/api/leads.php', [
            'action' => 'create',
            'campaign_id' => $campaignId,
            'phone' => 'invalid-phone'
        ]);
        $this->assertResponse('Invalid Phone Number', $response, 400, false);
        
        echo "\n";
    }
    
    private function makeRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => $method
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo "   ❌ cURL Error: $error\n";
            return null;
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            echo "   ❌ Invalid JSON response\n";
            return null;
        }
        
        $decoded['_http_code'] = $httpCode;
        return $decoded;
    }
    
    private function assertResponse($testName, $response, $expectedCode, $shouldSucceed = true) {
        if (!$response) {
            echo "   ❌ $testName: No response\n";
            $this->results['failed'][] = $testName;
            return;
        }
        
        $httpCode = $response['_http_code'];
        $success = $response['success'] ?? false;
        
        // Handle multiple acceptable codes
        $expectedCodes = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        $codeMatch = in_array($httpCode, $expectedCodes);
        
        if ($codeMatch && ($success === $shouldSucceed)) {
            echo "   ✅ $testName: HTTP $httpCode\n";
            $this->results['passed'][] = $testName;
        } else {
            $status = $shouldSucceed ? 'should succeed' : 'should fail';
            echo "   ❌ $testName: HTTP $httpCode, Success: " . ($success ? 'true' : 'false') . " ($status)\n";
            if (isset($response['error'])) {
                echo "      Error: {$response['error']}\n";
            }
            $this->results['failed'][] = $testName;
        }
    }
    
    private function printSummary() {
        $passed = count($this->results['passed'] ?? []);
        $failed = count($this->results['failed'] ?? []);
        $total = $passed + $failed;
        
        echo "📋 Test Summary\n";
        echo "==============\n";
        echo "Total Tests: $total\n";
        echo "Passed: $passed ✅\n";
        echo "Failed: $failed ❌\n";
        
        if ($failed > 0) {
            echo "\nFailed Tests:\n";
            foreach ($this->results['failed'] as $test) {
                echo "  - $test\n";
            }
        }
        
        $percentage = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
        echo "\nSuccess Rate: $percentage%\n";
        
        if ($percentage === 100.0) {
            echo "🎉 All tests passed!\n";
        } elseif ($percentage >= 80) {
            echo "👍 Most tests passed, check failed tests above\n";
        } else {
            echo "⚠️  Many tests failed, check your API configuration\n";
        }
    }
}

// Configuration
$baseUrl = 'http://localhost/ari-dialer';

// Allow command line override
if ($argc > 1) {
    $baseUrl = $argv[1];
}

// Run tests
$tester = new APITester($baseUrl);
$tester->runTests();