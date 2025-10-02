-- Migration: Add destination-related columns to campaigns table
-- MariaDB 5.5 compatible version (does not use IF NOT EXISTS)
-- This adds support for IVR, Queue, Extension, and Custom destination types
-- Run this on existing installations to add the missing columns
--
-- Note: This script will show errors if columns already exist, which is safe to ignore

USE asterisk_dialer;

-- Add destination_type column
ALTER TABLE campaigns
ADD COLUMN destination_type ENUM('custom', 'ivr', 'queue', 'extension') DEFAULT 'custom' AFTER extension;

-- Add ivr_id column
ALTER TABLE campaigns
ADD COLUMN ivr_id INT NULL AFTER destination_type;

-- Add queue_extension column
ALTER TABLE campaigns
ADD COLUMN queue_extension VARCHAR(20) NULL AFTER ivr_id;

-- Add agent_extension column
ALTER TABLE campaigns
ADD COLUMN agent_extension VARCHAR(20) NULL AFTER queue_extension;

-- Add indexes for the new columns
ALTER TABLE campaigns
ADD INDEX idx_destination_type (destination_type);

ALTER TABLE campaigns
ADD INDEX idx_ivr_id (ivr_id);
