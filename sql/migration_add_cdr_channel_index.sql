-- Migration: Add channel_id index to dialer_cdr table
-- Version: 2.1.2
-- Date: 2025-10-09
-- Description: Adds index on channel_id column for improved performance during ARI event processing
--
-- This migration is safe to run on existing installations.
-- The index improves lookup performance when WebSocket events need to find CDR records by channel_id.
--
-- Related Fix: Database reconnection in event handler (commit 7c8d3b8)

USE asterisk_dialer;

-- Check if index already exists, if not create it
-- Note: MySQL/MariaDB will error if index already exists, so check first in production

-- Add index on channel_id for faster event processing lookups
ALTER TABLE dialer_cdr ADD INDEX idx_channel_id (channel_id);

-- Verify the index was created
SHOW INDEX FROM dialer_cdr WHERE Key_name = 'idx_channel_id';

-- Display success message
SELECT 'Migration completed: idx_channel_id added to dialer_cdr table' AS Status;
