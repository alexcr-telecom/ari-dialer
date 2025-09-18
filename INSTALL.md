# Installation Guide - ARI Dialer

This comprehensive guide provides step-by-step instructions for installing and configuring the ARI Dialer system with WebSocket integration and comprehensive call logging.

## ✨ What's New in v2.1

### Major Features Added
- **📊 Comprehensive Call Logging**: Real-time call tracking with detailed logs
- **🔍 Advanced Call Logs UI**: Rich web interface with filtering, statistics, and auto-refresh
- **📈 Call Statistics**: Visual dashboards showing call metrics and success rates
- **🛠 Enhanced Debugging**: Detailed logging system in `logs/error.log`
- **⚡ Fixed Call Origination**: Resolved critical bug preventing calls from starting
- **🔄 Real-time Updates**: Live call status monitoring with WebSocket events

### Call Logging Features
- **Date/Time tracking** for every call attempt
- **Phone number** and **lead information**
- **Call status** (initiated, ringing, answered, failed, hung_up)
- **Response/disposition** tracking
- **Duration** calculation
- **Channel ID** monitoring for debugging
- **Agent extension** assignment
- **Campaign association**

### Before vs After v2.1
| Feature | Before | After |
|---------|---------|--------|
| Call visibility | ❌ None | ✅ Complete logs |
| Call debugging | ❌ Limited | ✅ Detailed traces |
| Real-time status | ❌ No | ✅ Live updates |
| Call statistics | ❌ None | ✅ Rich dashboards |
| Error logging | ❌ Basic | ✅ Comprehensive |

## Prerequisites

Before starting the installation, ensure you have:

- Root or sudo access to your server
- Basic knowledge of Linux command line
- Asterisk PBX already installed and running
- MySQL/MariaDB server running
- Web server (Apache/Nginx) installed

## Step 1: System Preparation

### Update System Packages

```bash
# Ubuntu/Debian
sudo apt update && sudo apt upgrade -y

# CentOS/RHEL
sudo yum update -y
```

### Install Required Packages

```bash
# Ubuntu/Debian
sudo apt install -y php php-mysql php-curl php-json php-mbstring php-xml php-zip mysql-client composer

# CentOS/RHEL (with MariaDB 5.5+)
sudo yum install -y php php-mysql php-curl php-json php-mbstring php-xml php-zip mariadb composer

# Note: MariaDB 5.5.65 has been tested and is fully supported
```

## Step 2: Download and Extract

```bash
# Navigate to web root
cd /var/www/html

# Clone the repository
git clone https://github.com/alexcr-telecom/ari-dialer.git
cd ari-dialer

# Install PHP dependencies via Composer
composer install

# Or if Composer is not globally installed
curl -sS https://getcomposer.org/installer | php
php composer.phar install
```

## Step 3: Database Setup

### Create Database and User

Log into MySQL as root:

```bash
mysql -u root -p
```

**For MariaDB 5.5.65 (tested), use this working approach:**

```bash
# Create database manually first (replace YOUR_ROOT_PASSWORD)
mysql -u root -pYOUR_ROOT_PASSWORD -e "CREATE DATABASE asterisk_dialer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Create user and grant privileges (MariaDB 5.5 compatible)
mysql -u root -pYOUR_ROOT_PASSWORD -e "GRANT ALL PRIVILEGES ON asterisk_dialer.* TO 'dialer_user'@'localhost' IDENTIFIED BY 'secure_password_123'; FLUSH PRIVILEGES;"
```

**Or execute these SQL commands interactively:**

```sql
-- Create database
CREATE DATABASE asterisk_dialer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user with secure password (MariaDB 5.5+ compatible syntax)
GRANT ALL PRIVILEGES ON asterisk_dialer.* TO 'dialer_user'@'localhost' IDENTIFIED BY 'YourSecurePassword123!';
FLUSH PRIVILEGES;

-- Exit MySQL
EXIT;
```

### Import Database Schema

**Important Notes:**
- The schema is compatible with MySQL 5.5+ and MariaDB 5.5+
- The security tables must be imported AFTER the main schema due to foreign key dependencies
- Schema has been updated for full MariaDB 5.5.65 compatibility
- Database creation uses compatible syntax for older MariaDB versions

```bash
# Import main schema first (REQUIRED)
mysql -u dialer_user -p asterisk_dialer < sql/schema.sql

# Import security tables second (has foreign key dependencies)
mysql -u dialer_user -p asterisk_dialer < sql/security_tables.sql
```

**Troubleshooting Database Installation:**
```bash
# MariaDB 5.5.65 Specific Issues:

# If you get "You have an error in your SQL syntax" with CREATE USER IF NOT EXISTS:
# This is fixed in the current version using GRANT...IDENTIFIED BY syntax

# If you get "Incorrect table definition; there can be only one TIMESTAMP" error:
# This is fixed in the current version, but if using older schema files,
# the updated schema converts some TIMESTAMP columns to DATETIME for compatibility

# If you get "Can't create table" errno 150 (foreign key error):
# Make sure you import schema.sql BEFORE security_tables.sql
# The security tables depend on tables created in the main schema

# Test database connection after import:
mysql -u dialer_user -p asterisk_dialer -e "SHOW TABLES;"

# Verify MariaDB version compatibility:
mysql --version
```

## Step 4: Application Configuration

### Copy Configuration File

```bash
cp config/config.php.example config/config.php
```

### Edit Configuration

```bash
nano config/config.php
```

Update the configuration with your settings:

```php
<?php
class Config {
    // Database Configuration
    const DB_HOST = 'localhost';
    const DB_NAME = 'asterisk_dialer';
    const DB_USER = 'dialer_user';
    const DB_PASS = 'YourSecurePassword123!';
    
    // Asterisk ARI Configuration
    const ARI_HOST = 'localhost';           # Asterisk server IP
    const ARI_PORT = 8088;                  # ARI HTTP port
    const ARI_USER = 'ari_user';            # ARI username
    const ARI_PASS = 'ari_secure_password'; # ARI password
    const ARI_APP = 'dialer_app';           # ARI application name
    
    // Dialer Configuration
    const ASTERISK_CONTEXT = 'from-internal';  # Dialplan context
    const MAX_CALLS_PER_MINUTE = 100;          # Rate limiting
    const DEFAULT_TIMEZONE = 'America/New_York'; # Your timezone
    
    // File Paths
    const UPLOAD_DIR = '/var/www/html/ari-dialer/uploads/';
    const LOG_DIR = '/var/www/html/ari-dialer/logs/';
}
```

## Step 5: Asterisk Configuration

**⚠️ FreePBX Users**: If you are using FreePBX, DO NOT modify the Asterisk configuration files manually (`ari.conf`, `http.conf`, `extensions.conf`). FreePBX already has all the necessary contexts and configurations. Use the FreePBX web interface to configure ARI users and HTTP settings instead.

### Configure ARI

Edit `/etc/asterisk/ari.conf`:

```bash
sudo nano /etc/asterisk/ari.conf
```

Add/modify the following sections:

```ini
[general]
enabled = yes
pretty = yes
websocket_write_timeout = 100

[ari_user]
type = user
read_only = no
password = ari_secure_password
```

### Configure HTTP Interface

Edit `/etc/asterisk/http.conf`:

```bash
sudo nano /etc/asterisk/http.conf
```

Ensure the following settings:

```ini
[general]
enabled=yes
bindaddr=0.0.0.0
bindport=8088
```

### Configure Dialplan

Edit `/etc/asterisk/extensions.conf`:

```bash
sudo nano /etc/asterisk/extensions.conf
```

Add the dialer context:

```ini
[from-internal]
; Dialer outbound calls
exten => _X.,1,NoOp(Dialer Call: Campaign ${CAMPAIGN_ID} calling ${EXTEN})
 same => n,Set(CALLERID(name)=Campaign ${CAMPAIGN_ID})
 same => n,Dial(PJSIP/${EXTEN},30)
 same => n,Hangup()

; Handle internal extensions
exten => _XXX,1,NoOp(Internal call to ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},20)
 same => n,Hangup()
```

### Reload Asterisk Configuration

```bash
sudo asterisk -rx "core reload"
sudo asterisk -rx "http show status"
sudo asterisk -rx "ari show apps"
```

## Step 6: Web Server Configuration

### Apache Configuration

Create virtual host file:

```bash
sudo nano /etc/apache2/sites-available/dialer.conf
```

Add the following configuration:

```apache
<VirtualHost *:80>
    ServerName dialer.yourdomain.com
    DocumentRoot /var/www/html/ari-dialer
    
    <Directory /var/www/html/ari-dialer>
        AllowOverride All
        Require all granted
        Options -Indexes
        
        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
    </Directory>
    
    # Deny access to sensitive files
    <FilesMatch "\.(conf|sql|md)$">
        Require all denied
    </FilesMatch>
    
    <Directory "/var/www/html/ari-dialer/config">
        Require all denied
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/dialer_error.log
    CustomLog ${APACHE_LOG_DIR}/dialer_access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite dialer
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

### Nginx Configuration

If using Nginx, add to your configuration:

```bash
sudo nano /etc/nginx/sites-available/dialer
```

```nginx
server {
    listen 80;
    server_name dialer.yourdomain.com;
    root /var/www/html/ari-dialer;
    index index.php index.html;
    
    # Security
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
    
    # Main location
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }
    
    # Deny access to sensitive files
    location ~ \.(conf|sql|md)$ {
        deny all;
    }
    
    location ^~ /config/ {
        deny all;
    }
    
    # Logging
    access_log /var/log/nginx/dialer_access.log;
    error_log /var/log/nginx/dialer_error.log;
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/dialer /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Step 7: File Permissions and Directories

### Set Ownership

```bash
sudo chown -R www-data:www-data /var/www/html/ari-dialer
```

### Set Permissions

```bash
# General permissions
sudo chmod -R 755 /var/www/html/ari-dialer

# Configuration files (readable by web server but not world-readable)
sudo chmod 644 /var/www/html/ari-dialer/config/config.php

# Create required directories
sudo mkdir -p /var/www/html/ari-dialer/{uploads,logs,recordings}

# Set writable permissions
sudo chmod 777 /var/www/html/ari-dialer/uploads
sudo chmod 777 /var/www/html/ari-dialer/logs
sudo chmod 777 /var/www/html/ari-dialer/recordings

# Create log files with proper permissions
sudo touch /var/www/html/ari-dialer/logs/error.log
sudo touch /var/www/html/ari-dialer/logs/ari-service.log
sudo chmod 666 /var/www/html/ari-dialer/logs/*.log
sudo chown www-data:www-data /var/www/html/ari-dialer/logs/*.log
```

### Configure Log Rotation (Optional)

To prevent log files from growing too large, set up log rotation:

```bash
sudo nano /etc/logrotate.d/ari-dialer
```

Add the following configuration:

```
/var/www/html/ari-dialer/logs/*.log {
    daily
    missingok
    rotate 30
    compress
    notifempty
    create 666 www-data www-data
    postrotate
        # Restart ARI service if running as systemd service
        /bin/systemctl restart asterisk-dialer 2>/dev/null || true
    endscript
}
```

## Step 8: Start Services

### Start ARI WebSocket Service

**Option 1: Run directly (for testing)**
```bash
# Start the ARI service
php services/ari-service.php

# Or run in background
nohup php services/ari-service.php > /dev/null 2>&1 &
```

**Option 2: SystemD Service (recommended for production)**
```bash
# Copy systemd service file
sudo cp services/asterisk-dialer.service /etc/systemd/system/

# Edit the service file if needed
sudo nano /etc/systemd/system/asterisk-dialer.service

# Enable and start the service
sudo systemctl daemon-reload
sudo systemctl enable asterisk-dialer
sudo systemctl start asterisk-dialer

# Check service status
sudo systemctl status asterisk-dialer
```

### Restart All Services

```bash
sudo systemctl restart asterisk
sudo systemctl restart apache2  # or nginx
sudo systemctl restart mysql

# Verify services are running
sudo systemctl status asterisk
sudo systemctl status apache2   # or nginx
sudo systemctl status mysql
sudo systemctl status asterisk-dialer  # New WebSocket service
```

### Test Connectivity

```bash
# Test Asterisk ARI
curl -u ari_user:ari_secure_password http://localhost:8088/ari/asterisk/info

# Test ARI WebSocket connection
sudo asterisk -rx "ari show apps"

# Test web server
curl -I http://localhost/ari-dialer/

# Check ARI service logs
tail -f /var/www/html/ari-dialer/logs/ari-service.log
```

## Step 9: First Login and Setup

### Access the Application

1. Open your web browser
2. Navigate to `http://your-server-ip/ari-dialer/`
3. You should see the login page

### Default Login Credentials

- **Username**: `admin`
- **Password**: `admin123`

**⚠️ IMPORTANT**: Change these credentials immediately after first login!

### Initial Setup

1. **Login** with default credentials
2. **Go to Profile** → Change password
3. **Go to Administration** → System Settings
4. **Configure** system settings as needed
5. **Test ARI connection** on the dashboard

## Step 10: Create Your First Campaign

### Add a Test Campaign

1. Go to **Campaigns** → **New Campaign**
2. Fill in the details:
   - **Name**: "Test Campaign"
   - **Context**: "from-internal"
   - **Extension**: Your agent extension
   - **Max Calls/Minute**: 5 (for testing)
3. **Save** the campaign

### Add Test Leads

1. Click **Add Leads** on your campaign
2. Enter test phone numbers (one per line)
3. **Save** the leads

### Test the System

1. Go to **Monitoring**
2. Click **Connect** to start monitoring
3. **Start** your test campaign
4. Verify calls are being initiated

## Troubleshooting

### Check Logs

```bash
# ARI WebSocket service logs (NEW!)
tail -f /var/www/html/ari-dialer/logs/ari-service.log

# Application logs
tail -f /var/www/html/ari-dialer/logs/error.log

# Asterisk logs
sudo tail -f /var/log/asterisk/full.log

# Web server logs
sudo tail -f /var/log/apache2/error.log  # or nginx
```

### Test Components

```bash
# Test database connection
mysql -u dialer_user -p asterisk_dialer -e "SELECT COUNT(*) FROM agents;"

# Test ARI connection
curl -u ari_user:ari_secure_password http://localhost:8088/ari/asterisk/info

# Test PHP
php -v
php -m | grep mysql
```

### Common Issues

1. **Database connection error**: Verify credentials and database exists
2. **ARI connection failed**: Check Asterisk configuration and credentials
3. **Permission denied**: Verify file permissions and ownership
4. **Calls not connecting**: Check dialplan configuration and lead status
5. **WebSocket connection drops**: Check ARI service status and logs
6. **Composer dependencies missing**: Run `composer install` in application directory
7. **Calls not starting**: Check `logs/error.log` for "Found 0 pending leads" errors
8. **Call logs not appearing**: Verify database permissions and call_logs table exists
9. **Log files not writable**: Check permissions on `logs/` directory

### Database Schema Issues

**ERROR 1293 (HY000): Incorrect table definition; there can be only one TIMESTAMP**
```bash
# This occurs on older MySQL versions (< 5.6.5)
# Solution: Use the updated schema.sql which converts some TIMESTAMP columns to DATETIME
# If tables already exist, you can fix manually:
mysql -u root -p asterisk_dialer -e "ALTER TABLE campaigns MODIFY updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;"
mysql -u root -p asterisk_dialer -e "ALTER TABLE leads MODIFY updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;"
mysql -u root -p asterisk_dialer -e "ALTER TABLE settings MODIFY updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;"
```

**ERROR 1005 (HY000): Can't create table (errno: 150)**
```bash
# This occurs when foreign key constraints fail
# Solution: Import schema.sql BEFORE security_tables.sql
# The security tables depend on tables created in the main schema
# If you imported in wrong order, drop and recreate:
mysql -u root -p asterisk_dialer -e "DROP DATABASE asterisk_dialer;"
mysql -u root -p -e "CREATE DATABASE asterisk_dialer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p asterisk_dialer < sql/schema.sql
mysql -u root -p asterisk_dialer < sql/security_tables.sql
```

### WebSocket Service Issues

```bash
# Check if ARI service is running
sudo systemctl status asterisk-dialer

# View detailed service logs
journalctl -u asterisk-dialer -f

# Restart the service if needed
sudo systemctl restart asterisk-dialer

# Check Asterisk ARI applications
sudo asterisk -rx "ari show apps"

# Test WebSocket manually
php test-websocket.php
```

## Security Hardening

### Firewall Configuration

```bash
# Allow only necessary ports
sudo ufw allow 22    # SSH
sudo ufw allow 80    # HTTP
sudo ufw allow 443   # HTTPS
sudo ufw allow 8088  # ARI (restrict to local network)
sudo ufw enable
```

### SSL/TLS Configuration

For production environments, configure SSL:

```bash
# Install Let's Encrypt
sudo apt install certbot python3-certbot-apache

# Get certificate
sudo certbot --apache -d dialer.yourdomain.com
```

### Additional Security

1. Change default MySQL root password
2. Disable root login for MySQL
3. Configure fail2ban for SSH protection
4. Regular security updates
5. Monitor access logs

## Maintenance

### Regular Tasks

1. **Database backups**: Set up automated backups
2. **Log rotation**: Configure logrotate for application logs
3. **Updates**: Keep system and PHP packages updated
4. **Monitoring**: Set up system monitoring

### Backup Script Example

```bash
#!/bin/bash
# backup-dialer.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backup/dialer"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u dialer_user -p asterisk_dialer > $BACKUP_DIR/database_$DATE.sql

# Backup application files
tar -czf $BACKUP_DIR/application_$DATE.tar.gz /var/www/html/ari-dialer

# Keep only last 30 days of backups
find $BACKUP_DIR -type f -mtime +30 -delete
```

## Support

If you encounter issues during installation:

1. Check the troubleshooting section in README.md
2. Review log files for error messages
3. Verify all configuration steps were completed
4. Test each component individually
5. Consult the community forum or GitHub issues

Installation complete! Your Asterisk Auto-Dialer should now be ready for use.