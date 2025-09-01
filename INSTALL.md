# Installation Guide - Asterisk Auto-Dialer

This guide provides step-by-step instructions for installing and configuring the Asterisk Auto-Dialer system.

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
sudo apt install -y php php-mysql php-curl php-json php-mbstring php-xml mysql-client

# CentOS/RHEL
sudo yum install -y php php-mysql php-curl php-json php-mbstring php-xml mysql
```

## Step 2: Download and Extract

```bash
# Navigate to web root
cd /var/www/html

# Download the application (replace with actual repository)
git clone https://github.com/yourrepo/asterisk-dialer.git ari-dialer

# Or download and extract zip file
wget https://github.com/yourrepo/asterisk-dialer/archive/main.zip
unzip main.zip
mv asterisk-dialer-main ari-dialer

# Navigate to application directory
cd ari-dialer
```

## Step 3: Database Setup

### Create Database and User

Log into MySQL as root:

```bash
mysql -u root -p
```

Execute the following SQL commands:

```sql
-- Create database
CREATE DATABASE asterisk_dialer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user with secure password
CREATE USER 'dialer_user'@'localhost' IDENTIFIED BY 'YourSecurePassword123!';

-- Grant privileges
GRANT ALL PRIVILEGES ON asterisk_dialer.* TO 'dialer_user'@'localhost';
FLUSH PRIVILEGES;

-- Exit MySQL
EXIT;
```

### Import Database Schema

```bash
# Import main schema
mysql -u dialer_user -p asterisk_dialer < sql/schema.sql

# Import security tables
mysql -u dialer_user -p asterisk_dialer < sql/security_tables.sql
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

# Configuration files (more restrictive)
sudo chmod 600 /var/www/html/ari-dialer/config/config.php

# Create required directories
sudo mkdir -p /var/www/html/ari-dialer/{uploads,logs,recordings}

# Set writable permissions
sudo chmod 777 /var/www/html/ari-dialer/uploads
sudo chmod 777 /var/www/html/ari-dialer/logs
sudo chmod 777 /var/www/html/ari-dialer/recordings
```

## Step 8: Start Services

### Restart All Services

```bash
sudo systemctl restart asterisk
sudo systemctl restart apache2  # or nginx
sudo systemctl restart mysql

# Verify services are running
sudo systemctl status asterisk
sudo systemctl status apache2   # or nginx
sudo systemctl status mysql
```

### Test Connectivity

```bash
# Test Asterisk ARI
curl -u ari_user:ari_secure_password http://localhost:8088/ari/asterisk/info

# Test web server
curl -I http://localhost/ari-dialer/
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
# Application logs
tail -f /var/www/html/ari-dialer/logs/error.log

# Asterisk logs
sudo tail -f /var/log/asterisk/full

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
4. **Calls not connecting**: Check dialplan configuration

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