-- Migration: Add app_secret column to whatsapp_config table
-- Required for webhook signature verification (X-Hub-Signature-256)

ALTER TABLE llx_whatsapp_config ADD COLUMN app_secret VARCHAR(255) DEFAULT NULL AFTER webhook_verify_token;
