# ARI Dialer v2.1

Professional Asterisk ARI Auto-Dialer Application with comprehensive call logging, real-time WebSocket integration, and advanced debugging capabilities for high-performance predictive dialing.

## 🚀 Features

### 🆕 New in v2.1
- **📊 Comprehensive Call Logging**: Real-time tracking of every call attempt with detailed status
- **🔍 Advanced Call Logs UI**: Rich web interface with filtering, search, and auto-refresh
- **📈 Call Statistics Dashboard**: Visual metrics showing success rates, duration, and trends
- **🛠 Enhanced Debugging**: Detailed logging system with timestamps and channel tracking
- **⚡ Fixed Call Origination**: Resolved critical bug preventing calls from starting
- **🔄 Real-time Updates**: Live call status monitoring with WebSocket events

### Core Features
- **Real-time WebSocket Connection**: Persistent connection to Asterisk ARI with automatic reconnection
- **Predictive Dialing**: Intelligent call pacing and queue management with rate limiting
- **Web-based Management**: Intuitive interface for campaign and lead management
- **Advanced Analytics**: Real-time monitoring and comprehensive reporting
- **Multi-campaign Support**: Run multiple campaigns simultaneously
- **Call Recording**: Automatic call recording and playback
- **Lead Management**: Import/export leads with advanced filtering
- **Security**: Built-in authentication, session management, and SQL injection protection
- **High Availability**: Auto-reconnection, error handling, and fault tolerance

### Call Logging Features
- **Date/Time Tracking**: Precise timestamps for call initiation and completion
- **Phone Number Logging**: Track all dialed numbers with lead association
- **Status Monitoring**: Real-time status updates (initiated → ringing → answered/failed)
- **Duration Calculation**: Automatic call duration tracking for answered calls
- **Channel ID Tracking**: Asterisk channel IDs for advanced debugging
- **Agent Assignment**: Track which agent handled each call
- **Campaign Association**: Link calls to specific campaigns for reporting
- **Response Tracking**: Capture call dispositions (ANSWERED, BUSY, NO ANSWER, etc.)

## 📋 System Requirements

### Minimum Requirements
- **PHP**: 7.4+ (8.0+ recommended)
- **Asterisk**: 16+ with ARI enabled (20+ recommended)
- **Database**: MySQL 5.7+ / MariaDB 10.3+
- **Web Server**: Apache 2.4+ / Nginx 1.18+
- **Memory**: 2GB RAM minimum
- **Storage**: 10GB+ available space

### PHP Extensions Required
- PDO MySQL, cURL, JSON, MBString, OpenSSL, Session, XML

## 🔧 Quick Installation

### 1. Clone Repository
```bash
git clone https://github.com/alexcr-telecom/ari-dialer.git
cd ari-dialer
```

### 2. Install Dependencies
```bash
# Install Composer if not available
curl -sS https://getcomposer.org/installer | php
php composer.phar install

# Or if Composer is globally installed
composer install
```

### 3. Database Setup
```bash
# Create database and user
mysql -u root -p << EOF
CREATE DATABASE asterisk_dialer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dialer_user'@'localhost' IDENTIFIED BY 'secure_password_123';
GRANT ALL PRIVILEGES ON asterisk_dialer.* TO 'dialer_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import database schema
mysql -u dialer_user -p asterisk_dialer < sql/schema.sql
mysql -u dialer_user -p asterisk_dialer < sql/security_tables.sql
```

### 4. Configuration
```bash
# Copy and edit configuration
cp config/config.php.example config/config.php
nano config/config.php
```

### 5. Set Permissions
```bash
sudo chown -R www-data:www-data .
sudo chmod 755 -R .
sudo chmod 600 config/config.php
sudo mkdir -p {uploads,logs,recordings}
sudo chmod 777 {uploads,logs,recordings}
```

### 6. Start Services
```bash
# Start the ARI WebSocket service
php services/ari-service.php &

# Or run as systemd service (recommended)
sudo cp services/asterisk-dialer.service /etc/systemd/system/
sudo systemctl enable asterisk-dialer
sudo systemctl start asterisk-dialer
```

## 🎯 Usage

### Access Web Interface
- **URL**: `http://your-server/ari-dialer/`
- **Default Login**: 
  - Username: `admin`
  - Password: `admin123`
  - ⚠️ **Change immediately after first login!**

### Starting Your First Campaign
1. **Login** to the web interface
2. **Create Campaign**: Campaigns → New Campaign
3. **Add Leads**: Upload CSV or add manually
4. **Configure Settings**: Set dial parameters, timing, agents
5. **Start Dialing**: Monitor progress in real-time

### Command Line Operations
```bash
# Check ARI service status
sudo systemctl status asterisk-dialer

# View service logs
tail -f logs/ari-service.log

# Test ARI connection
php test-ari-direct.php

# Check system requirements
php check-requirements.php
```

## 🔧 Configuration

### Asterisk Configuration

**ARI Configuration** (`/etc/asterisk/ari.conf`):
```ini
[general]
enabled = yes
pretty = yes
websocket_write_timeout = 100

[ari_user]
type = user
read_only = no
password = your_secure_password
```

**HTTP Configuration** (`/etc/asterisk/http.conf`):
```ini
[general]
enabled=yes
bindaddr=0.0.0.0
bindport=8088
```

**Dialplan** (`/etc/asterisk/extensions.conf`):
```ini
[from-internal]
exten => _X.,1,NoOp(ARI Dialer: ${EXTEN})
 same => n,Stasis(dialer_app,${EXTEN},${CAMPAIGN_ID})
 same => n,Hangup()
```

### Application Configuration

Edit `config/config.php`:
```php
// Database settings
const DB_HOST = 'localhost';
const DB_NAME = 'asterisk_dialer';
const DB_USER = 'dialer_user';
const DB_PASS = 'secure_password_123';

// ARI settings
const ARI_HOST = 'localhost';
const ARI_PORT = 8088;
const ARI_USER = 'ari_user';
const ARI_PASS = 'your_secure_password';
const ARI_APP = 'dialer_app';

// Dialer settings
const MAX_CALLS_PER_MINUTE = 100;
const DEFAULT_TIMEZONE = 'America/New_York';
```

## 💻 Web Interface

### New Call Logs Page
Access the comprehensive call logging interface at `/index.php?page=call-logs`:

- **Real-time Auto-refresh**: Updates every 10 seconds
- **Advanced Filtering**: Filter by campaign, status, phone number, date range
- **Statistics Cards**: Total calls, answered calls, failed calls, average duration
- **Export Functionality**: Download call logs for reporting
- **Status Color Coding**: Visual indicators for call status

### Navigation Menu
- **Dashboard**: Campaign overview and system status
- **Campaigns**: Manage campaigns and leads
- **Call Logs**: *(NEW)* Detailed call tracking and analytics
- **CDR**: Call detail records
- **Monitoring**: Real-time system monitoring

## 🔌 API Endpoints

### New Call Logs API
- `GET /api/call-logs.php` - List call logs with filtering
- `GET /api/call-logs.php?stats=1` - Get call statistics
- `GET /api/call-logs.php?id={id}` - Get specific call details

**Example API Usage:**
```bash
# Get recent call logs
curl "http://localhost/ari-dialer/api/call-logs.php?limit=10"

# Filter by campaign
curl "http://localhost/ari-dialer/api/call-logs.php?campaign_id=8&status=answered"

# Get statistics
curl "http://localhost/ari-dialer/api/call-logs.php?stats=1"
```

### Existing Campaign API
- `GET /api/campaigns.php` - List campaigns
- `POST /api/campaigns.php` - Start/pause/stop campaigns
- `PUT /api/campaigns.php` - Update campaign settings
- `DELETE /api/campaigns.php` - Delete campaigns

## 🔍 Monitoring & Troubleshooting

### Enhanced Logging System
- **Application Logs**: `/logs/error.log` - Main application with detailed call traces
- **ARI Service Logs**: `/logs/ari-service.log` - WebSocket service activity
- **Apache Logs**: `/var/log/apache2/error.log` - Web server errors
- **Asterisk Logs**: `/var/log/asterisk/full.log` - PBX activity

### Real-time Monitoring
- **Dashboard**: Live campaign statistics and system health
- **Call Logs Page**: Real-time call activity with auto-refresh
- **Call Monitoring**: Active calls, queue status, agent performance
- **System Logs**: Real-time log viewer with filtering

### Log Files
```bash
# Application logs
tail -f logs/ari-service.log
tail -f logs/error.log

# Asterisk logs  
sudo tail -f /var/log/asterisk/full.log

# Web server logs
sudo tail -f /var/log/apache2/error.log
```

### Common Issues

**WebSocket Connection Issues**:
```bash
# Check if service is running
sudo systemctl status asterisk-dialer

# Verify ARI configuration
sudo asterisk -rx "ari show apps"
sudo asterisk -rx "http show status"
```

**Database Connection**:
```bash
# Test database connection
php -r "
$pdo = new PDO('mysql:host=localhost;dbname=asterisk_dialer', 'dialer_user', 'password');
echo 'Database connection successful!';
"
```

## 📊 Performance & Scalability

### Capacity
- **Concurrent Calls**: 500+ simultaneous calls
- **Call Rate**: 1000+ calls per minute
- **Campaigns**: 50+ simultaneous campaigns
- **Leads**: 1M+ leads per campaign

### Optimization Tips
- Use SSD storage for database
- Configure MySQL query cache
- Enable PHP OPcache
- Use dedicated server for high-volume operations

## 🛡️ Security Features

- **Authentication**: Secure login system with session management
- **SQL Injection Protection**: Prepared statements throughout
- **XSS Prevention**: Input sanitization and output encoding
- **CSRF Protection**: Token-based form protection
- **File Security**: Restricted file uploads and access
- **Configuration Security**: Protected config files

## 📚 Documentation

- **[Installation Guide](INSTALL.md)**: Complete step-by-step installation
- **[API Documentation](docs/api.md)**: REST API endpoints and usage
- **[Configuration Reference](docs/config.md)**: All configuration options
- **[Troubleshooting Guide](docs/troubleshooting.md)**: Common issues and solutions

## 🤝 Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📧 Support

- **Issues**: [GitHub Issues](https://github.com/alexcr-telecom/ari-dialer/issues)
- **Discussions**: [GitHub Discussions](https://github.com/alexcr-telecom/ari-dialer/discussions)
- **Email**: alexcr.telecom@gmail.com

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

**🌟 Star this repository if you find it useful!**

Made with ❤️ for the Asterisk community
