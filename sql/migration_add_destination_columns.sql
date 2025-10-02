-- Migration: Add destination-related columns to campaigns table
-- This adds support for IVR, Queue, Extension, and Custom destination types
-- Run this on existing installations to add the missing columns

USE asterisk_dialer;

-- Add destination_type column if it doesn't exist
ALTER TABLE campaigns
ADD COLUMN IF NOT EXISTS destination_type ENUM('custom', 'ivr', 'queue', 'extension') DEFAULT 'custom' AFTER extension;

-- Add ivr_id column if it doesn't exist
ALTER TABLE campaigns
ADD COLUMN IF NOT EXISTS ivr_id INT NULL AFTER destination_type;

-- Add queue_extension column if it doesn't exist
ALTER TABLE campaigns
ADD COLUMN IF NOT EXISTS queue_extension VARCHAR(20) NULL AFTER ivr_id;

-- Add agent_extension column if it doesn't exist
ALTER TABLE campaigns
ADD COLUMN IF NOT EXISTS agent_extension VARCHAR(20) NULL AFTER queue_extension;

-- Add indexes for the new columns
ALTER TABLE campaigns
ADD INDEX IF NOT EXISTS idx_destination_type (destination_type);

ALTER TABLE campaigns
ADD INDEX IF NOT EXISTS idx_ivr_id (ivr_id);
