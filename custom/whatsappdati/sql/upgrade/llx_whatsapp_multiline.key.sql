-- Copyright (C) 2024-2026 DatiLab
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- ============================================================================
-- WhatsApp Business Module - Multi-line Migration
-- Adds support for multiple WhatsApp phone lines
-- ============================================================================

-- 1. Add line management columns to config table (each row = one line)
ALTER TABLE llx_whatsapp_config ADD COLUMN label VARCHAR(100) DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_config ADD COLUMN fk_user_default_agent INTEGER DEFAULT NULL AFTER webhook_url;
ALTER TABLE llx_whatsapp_config ADD COLUMN country_code VARCHAR(5) DEFAULT '57' AFTER fk_user_default_agent;

-- 2. Add fk_line to conversations (which line received/handles this conversation)
ALTER TABLE llx_whatsapp_conversations ADD COLUMN fk_line INTEGER DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_conversations ADD INDEX idx_fk_line (fk_line);

-- 3. Add fk_line to templates (templates are per WhatsApp Business Account = per line)
ALTER TABLE llx_whatsapp_templates ADD COLUMN fk_line INTEGER DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_templates ADD INDEX idx_fk_line (fk_line);

-- 4. Add fk_line to queue (which line sends the bulk messages)
ALTER TABLE llx_whatsapp_queue ADD COLUMN fk_line INTEGER DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_queue ADD INDEX idx_fk_line (fk_line);

-- 5. Add fk_line to scheduled messages
ALTER TABLE llx_whatsapp_scheduled ADD COLUMN fk_line INTEGER DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_scheduled ADD INDEX idx_fk_line (fk_line);

-- 6. Add fk_line to chatbot rules (rules can be per-line or global when NULL)
ALTER TABLE llx_whatsapp_chatbot_rules ADD COLUMN fk_line INTEGER DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_chatbot_rules ADD INDEX idx_fk_line (fk_line);

-- 7. Add fk_line to messages for traceability
ALTER TABLE llx_whatsapp_messages ADD COLUMN fk_line INTEGER DEFAULT NULL AFTER entity;
ALTER TABLE llx_whatsapp_messages ADD INDEX idx_fk_line (fk_line);

-- 8. Set label for existing config row (if any) so migration is seamless
UPDATE llx_whatsapp_config SET label = 'Principal' WHERE label IS NULL;

-- 9. Drop unique constraint on conversations.conversation_id since same phone
--    can now have conversations on different lines
-- (This ALTER may fail if constraint doesn't exist — that's OK)
-- ALTER TABLE llx_whatsapp_conversations DROP INDEX conversation_id;

-- 10. Make template uniqueness per-line
-- Drop old unique key and recreate including fk_line
-- ALTER TABLE llx_whatsapp_templates DROP INDEX uk_template;
-- ALTER TABLE llx_whatsapp_templates ADD UNIQUE KEY uk_template_line (entity, fk_line, name, language);
