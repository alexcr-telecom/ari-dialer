# ARI Dialer API Documentation

Complete REST API documentation for the ARI Dialer system.

## Base URL

```
http://your-server/ari-dialer/api/
```

## Authentication

Currently, the API uses session-based authentication. Ensure you're logged in to the web interface or implement API key authentication in your production environment.

## Response Format

All API responses follow a consistent JSON format:

### Success Response
```json
{
  "success": true,
  "data": {...},
  "message": "Optional success message"
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message",
  "code": 400,
  "details": {...}
}
```

## Common HTTP Status Codes

- `200` - Success
- `201` - Created
- `400` - Bad Request (validation error)
- `404` - Not Found
- `405` - Method Not Allowed
- `500` - Internal Server Error

---

## Campaigns API

Manage campaigns and campaign operations.

### Endpoints

#### `GET /api/campaigns.php`

List all campaigns or get a specific campaign.

**Query Parameters:**
- `id` (optional) - Campaign ID to retrieve specific campaign
- `name` (optional) - Filter by campaign name
- `status` (optional) - Filter by status: `active`, `paused`, `stopped`, `completed`
- `limit` (optional) - Number of results (1-100, default: 50)
- `offset` (optional) - Offset for pagination (default: 0)

**Examples:**
```bash
# Get all campaigns
curl "http://your-server/ari-dialer/api/campaigns.php"

# Get specific campaign
curl "http://your-server/ari-dialer/api/campaigns.php?id=1"

# Get active campaigns with pagination
curl "http://your-server/ari-dialer/api/campaigns.php?status=active&limit=10&offset=20"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Test Campaign",
      "context": "from-internal",
      "status": "active",
      "max_calls_per_minute": 50,
      "agent_extension": "1001",
      "caller_id": "Campaign Test",
      "created_at": "2024-01-15 10:30:00",
      "updated_at": "2024-01-15 10:30:00"
    }
  ]
}
```

#### `POST /api/campaigns.php`

Create a campaign or perform campaign actions.

**Create Campaign:**
```json
{
  "action": "create",
  "name": "New Campaign",
  "context": "from-internal",
  "max_calls_per_minute": 100,
  "agent_extension": "1001",
  "caller_id": "Test Campaign",
  "description": "Campaign description"
}
```

**Campaign Control Actions:**
```json
{
  "action": "start",
  "id": 1
}
```

Available actions: `start`, `pause`, `stop`, `create`

**Examples:**
```bash
# Create campaign
curl -X POST "http://your-server/ari-dialer/api/campaigns.php" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "create",
    "name": "Test Campaign",
    "context": "from-internal",
    "max_calls_per_minute": 50
  }'

# Start campaign
curl -X POST "http://your-server/ari-dialer/api/campaigns.php" \
  -H "Content-Type: application/json" \
  -d '{"action": "start", "id": 1}'
```

#### `PUT /api/campaigns.php`

Update an existing campaign.

**Request Body:**
```json
{
  "id": 1,
  "name": "Updated Campaign Name",
  "max_calls_per_minute": 75
}
```

**Example:**
```bash
curl -X PUT "http://your-server/ari-dialer/api/campaigns.php" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1,
    "name": "Updated Campaign",
    "max_calls_per_minute": 75
  }'
```

#### `DELETE /api/campaigns.php`

Delete a campaign.

**Query Parameters:**
- `id` (required) - Campaign ID to delete

**Example:**
```bash
curl -X DELETE "http://your-server/ari-dialer/api/campaigns.php?id=1"
```

---

## Leads API

Manage leads for campaigns.

### Endpoints

#### `GET /api/leads.php`

Get leads for a campaign.

**Query Parameters:**
- `campaign_id` (required) - Campaign ID
- `id` (optional) - Specific lead ID
- `status` (optional) - Filter by status: `pending`, `dialed`, `answered`, `busy`, `no_answer`, `failed`
- `phone` (optional) - Filter by phone number
- `limit` (optional) - Number of results (1-1000, default: 100)
- `offset` (optional) - Offset for pagination (default: 0)

**Examples:**
```bash
# Get all leads for campaign
curl "http://your-server/ari-dialer/api/leads.php?campaign_id=1"

# Get specific lead
curl "http://your-server/ari-dialer/api/leads.php?campaign_id=1&id=123"

# Filter by status
curl "http://your-server/ari-dialer/api/leads.php?campaign_id=1&status=pending&limit=50"
```

#### `POST /api/leads.php`

Add leads to a campaign.

**Single Lead:**
```json
{
  "action": "create",
  "campaign_id": 1,
  "phone": "15551234567",
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "priority": 5
}
```

**Bulk Import:**
```json
{
  "action": "bulk_import",
  "campaign_id": 1,
  "leads": [
    {
      "phone": "15551234567",
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com"
    },
    {
      "phone": "15551234568",
      "first_name": "Jane",
      "last_name": "Smith",
      "email": "jane@example.com"
    }
  ]
}
```

**Examples:**
```bash
# Add single lead
curl -X POST "http://your-server/ari-dialer/api/leads.php" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "create",
    "campaign_id": 1,
    "phone": "15551234567",
    "first_name": "John",
    "last_name": "Doe"
  }'

# Bulk import
curl -X POST "http://your-server/ari-dialer/api/leads.php" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "bulk_import",
    "campaign_id": 1,
    "leads": [
      {"phone": "15551234567", "first_name": "John", "last_name": "Doe"},
      {"phone": "15551234568", "first_name": "Jane", "last_name": "Smith"}
    ]
  }'
```

#### `PUT /api/leads.php`

Update a lead.

**Request Body:**
```json
{
  "campaign_id": 1,
  "id": 123,
  "status": "answered",
  "first_name": "Updated Name"
}
```

#### `DELETE /api/leads.php`

Delete a lead.

**Query Parameters:**
- `campaign_id` (required) - Campaign ID
- `id` (required) - Lead ID

**Example:**
```bash
curl -X DELETE "http://your-server/ari-dialer/api/leads.php?campaign_id=1&id=123"
```

---

## Charts API

Get analytics and reporting data.

### Endpoints

#### `GET /api/charts.php`

Get chart data and statistics.

**Query Parameters:**
- `type` (optional) - Chart type: `overview`, `dispositions`, `hourly`, `daily`, `statistics` (default: `overview`)
- `date_from` (optional) - Start date (Y-m-d format)
- `date_to` (optional) - End date (Y-m-d format)
- `campaign_id` (optional) - Filter by campaign ID
- `agent_extension` (optional) - Filter by agent extension

**Examples:**
```bash
# Get overview data
curl "http://your-server/ari-dialer/api/charts.php?type=overview"

# Get dispositions for specific campaign
curl "http://your-server/ari-dialer/api/charts.php?type=dispositions&campaign_id=1"

# Get daily stats for date range
curl "http://your-server/ari-dialer/api/charts.php?type=daily&date_from=2024-01-01&date_to=2024-01-31"

# Get hourly data for today
curl "http://your-server/ari-dialer/api/charts.php?type=hourly&date_from=2024-01-15&date_to=2024-01-15"
```

**Response (Overview):**
```json
{
  "success": true,
  "data": {
    "dispositions": {
      "ANSWERED": 150,
      "BUSY": 45,
      "NO ANSWER": 89,
      "FAILED": 12
    },
    "hourly_calls": [
      {"hour": "09", "calls": 25},
      {"hour": "10", "calls": 38}
    ],
    "daily_calls": [
      {"date": "2024-01-15", "calls": 296}
    ],
    "statistics": {
      "total_calls": 296,
      "answered_calls": 150,
      "success_rate": 50.68
    },
    "generated_at": "2024-01-15 14:30:00"
  }
}
```

---

## System Status API

Get system health and status information.

### Endpoints

#### `GET /api/status.php`

Get comprehensive system status.

**Example:**
```bash
curl "http://your-server/ari-dialer/api/status.php"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "system": {
      "timestamp": "2024-01-15 14:30:00",
      "timezone": "America/New_York",
      "php_version": "8.1.0",
      "memory_usage": {
        "current": 8388608,
        "peak": 10485760,
        "limit": "512M"
      },
      "load_average": {
        "1min": 0.5,
        "5min": 0.3,
        "15min": 0.2
      }
    },
    "database": {
      "status": "connected",
      "host": "localhost",
      "database": "asterisk_dialer",
      "campaigns": 5,
      "leads": 1250
    },
    "asterisk": {
      "status": "connected",
      "host": "localhost:8088",
      "version": "20.5.0",
      "active_channels": 3
    },
    "ari_service": {
      "status": "running",
      "log_file": "/var/www/html/ari-dialer/logs/ari-service.log",
      "last_modified": "2024-01-15 14:29:30"
    },
    "health": {
      "overall": "healthy",
      "checks": {
        "database": true,
        "asterisk": true,
        "php": true,
        "memory": true
      },
      "score": 100.0
    }
  }
}
```

---

## Error Handling

### Common Validation Errors

**Invalid ID:**
```json
{
  "success": false,
  "error": "Invalid ID provided",
  "code": 400
}
```

**Missing Required Field:**
```json
{
  "success": false,
  "error": "Field 'name' is required",
  "code": 400
}
```

**Invalid Phone Number:**
```json
{
  "success": false,
  "error": "Invalid phone number format",
  "code": 400
}
```

**Bulk Import Errors:**
```json
{
  "success": false,
  "error": "Validation errors in bulk import",
  "code": 400,
  "details": [
    "Row 0: Phone number required",
    "Row 2: Invalid phone number format"
  ]
}
```

### Rate Limiting

The API implements basic rate limiting:
- Maximum 1000 leads per bulk import
- Maximum 100 campaigns per request
- Date range limited to 365 days for analytics

---

## Security Considerations

1. **Input Validation**: All inputs are validated and sanitized
2. **SQL Injection**: Uses prepared statements throughout
3. **XSS Protection**: Output is properly escaped
4. **CORS**: Configurable cross-origin resource sharing
5. **Error Logging**: Detailed errors logged server-side, generic errors returned to client

---

## Webhooks & Real-time Updates

For real-time updates, consider implementing WebSocket connections or webhooks:

1. **WebSocket**: Connect to the ARI service WebSocket for real-time call events
2. **Polling**: Use the status endpoint for periodic health checks
3. **Webhooks**: Configure campaign status change notifications (future feature)

---

## SDK Examples

### JavaScript/Node.js

```javascript
class ARIDialerAPI {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
  }
  
  async getCampaigns(filters = {}) {
    const params = new URLSearchParams(filters);
    const response = await fetch(`${this.baseUrl}/campaigns.php?${params}`);
    return response.json();
  }
  
  async createCampaign(campaignData) {
    return fetch(`${this.baseUrl}/campaigns.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'create', ...campaignData})
    }).then(r => r.json());
  }
  
  async startCampaign(campaignId) {
    return fetch(`${this.baseUrl}/campaigns.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'start', id: campaignId})
    }).then(r => r.json());
  }
}

// Usage
const api = new ARIDialerAPI('http://your-server/ari-dialer/api');
const campaigns = await api.getCampaigns();
```

### Python

```python
import requests

class ARIDialerAPI:
    def __init__(self, base_url):
        self.base_url = base_url
        
    def get_campaigns(self, **filters):
        response = requests.get(f"{self.base_url}/campaigns.php", params=filters)
        return response.json()
        
    def create_campaign(self, **campaign_data):
        data = {"action": "create", **campaign_data}
        response = requests.post(
            f"{self.base_url}/campaigns.php",
            json=data
        )
        return response.json()
        
    def start_campaign(self, campaign_id):
        data = {"action": "start", "id": campaign_id}
        response = requests.post(
            f"{self.base_url}/campaigns.php",
            json=data
        )
        return response.json()

# Usage
api = ARIDialerAPI('http://your-server/ari-dialer/api')
campaigns = api.get_campaigns()
```

---

## Changelog

### Version 2.0
- Added comprehensive input validation
- Implemented proper error handling with consistent response format
- Added CORS support for cross-origin requests
- Added bulk import functionality for leads
- Enhanced security with input sanitization
- Added system status endpoint
- Improved API response consistency
- Added detailed logging for debugging

### Version 1.0
- Basic CRUD operations for campaigns
- Simple chart data endpoint
- Basic error handling