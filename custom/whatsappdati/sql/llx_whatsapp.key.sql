-- Copyright (C) 2024 DatiLab
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- ============================================================================
-- WhatsApp Business Module - Database Key Creation
-- Only safe ADD INDEX statements (no FOREIGN KEY, no CONVERT)
-- Cross-module foreign keys are intentionally omitted to ensure
-- reliable installation across all Dolibarr environments.
-- ============================================================================

ALTER TABLE llx_whatsapp_config ADD INDEX idx_entity (entity);
ALTER TABLE llx_whatsapp_conversations ADD INDEX idx_entity_conv (entity);
ALTER TABLE llx_whatsapp_messages ADD INDEX idx_entity_msg (entity);
ALTER TABLE llx_whatsapp_templates ADD INDEX idx_entity_tpl (entity);
ALTER TABLE llx_whatsapp_queue ADD INDEX idx_entity_q (entity);
