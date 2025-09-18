-- Asterisk Auto-Dialer Database Schema v2.1
--
-- Enhanced Features:
-- - Comprehensive call logging with detailed tracking
-- - Real-time call status monitoring
-- - Call statistics and analytics
-- - Enhanced debugging and error tracking
--
-- Installation Notes:
-- - If you use FreePBX, DO NOT change Asterisk configuration files manually
-- - FreePBX already has all necessary contexts and configurations
-- - Use FreePBX web interface to configure ARI users and HTTP settings
--
-- Version History:
-- v2.1: Added comprehensive call logging, enhanced debugging, fixed call origination
-- v2.0: Added WebSocket stability improvements, ReactPHP integration
-- v1.0: Initial release with basic dialing functionality

CREATE DATABASE IF NOT EXISTS asterisk_dialer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE asterisk_dialer;

-- Campaigns table
CREATE TABLE campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('active', 'paused', 'completed') DEFAULT 'paused',
    start_date DATETIME,
    end_date DATETIME,
    context VARCHAR(50) DEFAULT 'from-internal',
    outbound_context VARCHAR(50) DEFAULT 'from-internal',
    extension VARCHAR(20) DEFAULT '101',
    priority INT DEFAULT 1,
    max_calls_per_minute INT DEFAULT 10,
    retry_attempts INT DEFAULT 3,
    retry_interval INT DEFAULT 300,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);

-- Leads table
CREATE TABLE leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    name VARCHAR(255),
    status ENUM('pending', 'dialed', 'answered', 'failed', 'busy', 'no_answer', 'callback') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    last_attempt DATETIME,
    next_attempt DATETIME,
    disposition VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_phone_number (phone_number)
);

-- Call logs table for comprehensive call tracking (NEW in v2.1)
-- This table provides detailed logging for every call attempt with:
-- - Real-time status tracking (initiated → ringing → answered/failed)
-- - Duration calculation for answered calls
-- - Channel ID for Asterisk debugging
-- - Disposition tracking (ANSWERED, BUSY, NO ANSWER, etc.)
-- - Agent assignment and campaign association
CREATE TABLE call_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT,                           -- Reference to lead (NULL if direct dial)
    campaign_id INT NOT NULL,              -- Campaign this call belongs to
    phone_number VARCHAR(20) NOT NULL,     -- Phone number being dialed
    agent_extension VARCHAR(20),           -- Agent extension handling the call
    channel_id VARCHAR(100),               -- Asterisk channel ID for debugging
    call_start DATETIME,                   -- When call was initiated
    call_end DATETIME,                     -- When call ended (NULL if ongoing)
    duration INT DEFAULT 0,                -- Call duration in seconds
    status ENUM('initiated', 'ringing', 'answered', 'failed', 'hung_up') DEFAULT 'initiated',
    disposition VARCHAR(50),               -- Call result (ANSWERED, BUSY, NO ANSWER, etc.)
    recording_file VARCHAR(255),           -- Path to call recording (if enabled)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_date (campaign_id, call_start),  -- For campaign reports
    INDEX idx_channel_id (channel_id),                  -- For debugging
    INDEX idx_status_date (status, call_start)          -- For status reports
);

-- Agents table
CREATE TABLE agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    name VARCHAR(255),
    role ENUM('admin', 'agent') DEFAULT 'agent',
    status ENUM('offline', 'available', 'busy', 'break') DEFAULT 'offline',
    login_time DATETIME,
    last_activity DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Campaign templates
CREATE TABLE campaign_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    context VARCHAR(50) DEFAULT 'from-internal',
    extension VARCHAR(20) DEFAULT '101',
    priority INT DEFAULT 1,
    max_calls_per_minute INT DEFAULT 10,
    retry_attempts INT DEFAULT 3,
    retry_interval INT DEFAULT 300,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Do Not Call list
CREATE TABLE dnc_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) UNIQUE NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- System settings
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('max_concurrent_calls', '50', 'Maximum concurrent calls allowed'),
('predictive_ratio', '1.5', 'Predictive dialing ratio'),
('call_timeout', '30', 'Call timeout in seconds'),
('default_retry_interval', '300', 'Default retry interval in seconds'),
('enable_recording', '1', 'Enable call recording (1=yes, 0=no)'),
('recording_path', '/var/lib/asterisk/sounds/recordings/', 'Path to store recordings');

-- Insert default admin user (password: admin123)
INSERT INTO agents (username, password, extension, name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '100', 'System Administrator', 'admin');

-- Create indexes for better performance
CREATE INDEX idx_leads_next_attempt ON leads(next_attempt);
CREATE INDEX idx_call_logs_start_time ON call_logs(call_start);
CREATE INDEX idx_agents_status ON agents(status);

-- Views for reporting
CREATE VIEW campaign_stats AS
SELECT 
    c.id,
    c.name,
    c.status,
    COUNT(l.id) as total_leads,
    COUNT(CASE WHEN l.status = 'pending' THEN 1 END) as pending_leads,
    COUNT(CASE WHEN l.status = 'answered' THEN 1 END) as answered_leads,
    COUNT(CASE WHEN l.status = 'failed' THEN 1 END) as failed_leads,
    ROUND((COUNT(CASE WHEN l.status = 'answered' THEN 1 END) / COUNT(l.id)) * 100, 2) as success_rate
FROM campaigns c
LEFT JOIN leads l ON c.id = l.campaign_id
GROUP BY c.id, c.name, c.status;