-- Copyright (C) 2024 DatiLab
-- Migration: Add bulk send fields to queue table
--
-- NOTE: This file is for UPGRADES ONLY from versions prior to bulk send support.
-- For fresh installations, these columns already exist in the main llx_whatsapp.sql DDL.
-- L7: Kept for backward-compatible upgrade path.

ALTER TABLE llx_whatsapp_queue ADD COLUMN batch_id VARCHAR(50) AFTER entity;
ALTER TABLE llx_whatsapp_queue ADD COLUMN contact_name VARCHAR(255) AFTER phone_number;
ALTER TABLE llx_whatsapp_queue ADD COLUMN template_name VARCHAR(100) AFTER fk_template;
ALTER TABLE llx_whatsapp_queue ADD COLUMN max_retries INTEGER DEFAULT 3 AFTER retry_count;
ALTER TABLE llx_whatsapp_queue ADD COLUMN message_id_wa VARCHAR(100) AFTER error_message;
ALTER TABLE llx_whatsapp_queue ADD INDEX idx_batch (batch_id);
ALTER TABLE llx_whatsapp_queue MODIFY COLUMN status VARCHAR(20) DEFAULT 'pending';
