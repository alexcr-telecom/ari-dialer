-- Security-related tables for the Asterisk Auto-Dialer

USE asterisk_dialer;

-- Activity logs table
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES agents(id) ON DELETE SET NULL,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created_at (created_at)
);

-- Session management table
CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES agents(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at)
);

-- API keys table (for future API access)
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    permissions JSON,
    is_active BOOLEAN DEFAULT TRUE,
    last_used TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES agents(id) ON DELETE CASCADE,
    INDEX idx_api_key (api_key),
    INDEX idx_user_active (user_id, is_active)
);

-- Security settings table
CREATE TABLE security_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES agents(id) ON DELETE SET NULL
);

-- Insert default security settings
INSERT INTO security_settings (setting_name, setting_value, description) VALUES
('session_timeout', '3600', 'Session timeout in seconds (1 hour)'),
('max_login_attempts', '5', 'Maximum login attempts before lockout'),
('lockout_duration', '900', 'Account lockout duration in seconds (15 minutes)'),
('password_min_length', '8', 'Minimum password length'),
('password_require_special', '1', 'Require special characters in password (1=yes, 0=no)'),
('enable_2fa', '0', 'Enable two-factor authentication (1=yes, 0=no)'),
('api_rate_limit', '100', 'API requests per minute per user'),
('enable_audit_log', '1', 'Enable detailed audit logging (1=yes, 0=no)');

-- Failed login attempts tracking
CREATE TABLE failed_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    ip_address VARCHAR(45),
    user_agent TEXT,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username_ip (username, ip_address),
    INDEX idx_attempt_time (attempt_time)
);

-- Account lockouts
CREATE TABLE account_lockouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    ip_address VARCHAR(45),
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    unlock_at TIMESTAMP,
    reason VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES agents(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_unlock_at (unlock_at)
);

-- Cleanup procedure for old logs
DELIMITER //
CREATE PROCEDURE CleanupSecurityLogs()
BEGIN
    -- Clean up old activity logs (keep last 90 days)
    DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- Clean up old failed login attempts (keep last 30 days)
    DELETE FROM failed_login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Clean up expired sessions
    DELETE FROM user_sessions WHERE expires_at < NOW();
    
    -- Clean up expired lockouts
    UPDATE account_lockouts SET is_active = FALSE WHERE unlock_at < NOW() AND is_active = TRUE;
END //
DELIMITER ;

-- Create event scheduler to run cleanup daily
-- CREATE EVENT SecurityLogCleanup
-- ON SCHEDULE EVERY 1 DAY
-- DO CALL CleanupSecurityLogs();

-- Views for security monitoring
CREATE VIEW active_sessions AS
SELECT 
    us.id as session_id,
    a.username,
    a.name,
    a.role,
    us.ip_address,
    us.created_at as login_time,
    us.last_activity,
    TIMESTAMPDIFF(MINUTE, us.last_activity, NOW()) as idle_minutes
FROM user_sessions us
JOIN agents a ON us.user_id = a.id
WHERE us.expires_at > NOW()
ORDER BY us.last_activity DESC;

CREATE VIEW security_summary AS
SELECT 
    (SELECT COUNT(*) FROM agents WHERE status = 'available') as active_users,
    (SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()) as active_sessions,
    (SELECT COUNT(*) FROM failed_login_attempts WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as recent_failed_logins,
    (SELECT COUNT(*) FROM account_lockouts WHERE is_active = TRUE) as active_lockouts,
    (SELECT COUNT(*) FROM activity_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as activities_last_24h;