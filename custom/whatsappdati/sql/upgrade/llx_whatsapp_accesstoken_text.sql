-- Migration: Extend access_token column from VARCHAR(500) to TEXT
-- WhatsApp System User permanent tokens can exceed 500 characters
-- Run this if the module was installed before this migration
ALTER TABLE llx_whatsapp_config MODIFY COLUMN access_token TEXT NOT NULL;
