#!/bin/bash

# Asterisk Auto-Dialer Installation Script
# Run with: sudo bash install.sh

set -e

echo "🚀 Asterisk Auto-Dialer Installation Script"
echo "=========================================="

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Please run this script as root (sudo bash install.sh)"
    exit 1
fi

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Detect OS
if [ -f /etc/debian_version ]; then
    OS="debian"
    echo "✅ Detected Debian/Ubuntu system"
elif [ -f /etc/redhat-release ]; then
    OS="redhat"
    echo "✅ Detected Red Hat/CentOS system"
else
    echo "❌ Unsupported operating system"
    exit 1
fi

echo ""
echo "📋 Step 1: Installing required packages..."

if [ "$OS" = "debian" ]; then
    apt update
    apt install -y php php-mysql php-curl php-json php-mbstring php-xml php-cli mysql-server apache2
    
    # Enable Apache modules
    a2enmod rewrite headers
    
elif [ "$OS" = "redhat" ]; then
    yum update -y
    yum install -y php php-mysql php-curl php-json php-mbstring php-xml php-cli mysql-server httpd
    
    # Enable Apache modules
    systemctl enable httpd
fi

echo ""
echo "📁 Step 2: Setting up directories and permissions..."

# Create required directories
mkdir -p /var/www/html/ari-dialer/{uploads,logs,recordings}

# Set ownership
chown -R www-data:www-data /var/www/html/ari-dialer 2>/dev/null || chown -R apache:apache /var/www/html/ari-dialer

# Set permissions
chmod -R 755 /var/www/html/ari-dialer
chmod 777 /var/www/html/ari-dialer/{uploads,logs,recordings}
chmod 600 /var/www/html/ari-dialer/config/config.php 2>/dev/null || echo "⚠️  config.php not found - will need to be created"

echo ""
echo "🔧 Step 3: Setting up configuration..."

if [ ! -f /var/www/html/ari-dialer/config/config.php ]; then
    echo "📝 Creating configuration file from template..."
    cp /var/www/html/ari-dialer/config/config.php.example /var/www/html/ari-dialer/config/config.php
    chmod 600 /var/www/html/ari-dialer/config/config.php
    echo "⚠️  Please edit /var/www/html/ari-dialer/config/config.php with your settings"
fi

echo ""
echo "🐘 Step 4: Setting up database..."

# Check if MySQL is running
if ! systemctl is-active --quiet mysql && ! systemctl is-active --quiet mysqld; then
    echo "🔄 Starting MySQL service..."
    systemctl start mysql 2>/dev/null || systemctl start mysqld
fi

echo ""
echo "🌐 Step 5: Setting up web server..."

# Create Apache virtual host
cat > /etc/apache2/sites-available/dialer.conf 2>/dev/null << EOF || cat > /etc/httpd/conf.d/dialer.conf << EOF
<VirtualHost *:80>
    ServerName localhost
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
    
    ErrorLog \${APACHE_LOG_DIR}/dialer_error.log
    CustomLog \${APACHE_LOG_DIR}/dialer_access.log combined
</VirtualHost>
EOF

# Enable site (Debian/Ubuntu)
if [ "$OS" = "debian" ]; then
    a2ensite dialer 2>/dev/null || echo "⚠️  Could not enable Apache site"
fi

echo ""
echo "🎯 Step 6: Installing ARI service..."

# Copy systemd service file
cp /var/www/html/ari-dialer/services/asterisk-dialer.service /etc/systemd/system/

# Reload systemd
systemctl daemon-reload

echo ""
echo "🔄 Step 7: Starting services..."

# Start and enable services
systemctl restart apache2 2>/dev/null || systemctl restart httpd
systemctl restart mysql 2>/dev/null || systemctl restart mysqld

systemctl enable apache2 2>/dev/null || systemctl enable httpd
systemctl enable mysql 2>/dev/null || systemctl enable mysqld

echo ""
echo "✅ Installation completed!"
echo ""
echo "📋 Next Steps:"
echo "1. Edit configuration: /var/www/html/ari-dialer/config/config.php"
echo "2. Set up database:"
echo "   mysql -u root -p"
echo "   CREATE DATABASE asterisk_dialer;"
echo "   CREATE USER 'dialer_user'@'localhost' IDENTIFIED BY 'your_password';"
echo "   GRANT ALL ON asterisk_dialer.* TO 'dialer_user'@'localhost';"
echo "   EXIT;"
echo ""
echo "3. Import database schema:"
echo "   mysql -u dialer_user -p asterisk_dialer < /var/www/html/ari-dialer/sql/schema.sql"
echo "   mysql -u dialer_user -p asterisk_dialer < /var/www/html/ari-dialer/sql/security_tables.sql"
echo ""
echo "4. Configure Asterisk ARI in /etc/asterisk/ari.conf and /etc/asterisk/http.conf"
echo ""
echo "5. Check requirements: http://your-server/ari-dialer/check-requirements.php"
echo ""
echo "6. Start ARI service:"
echo "   systemctl enable asterisk-dialer"
echo "   systemctl start asterisk-dialer"
echo ""
echo "7. Access the application: http://your-server/ari-dialer/"
echo "   Default login: admin / admin123"
echo ""
echo "🎉 Happy dialing!"