-- Add assign_mode column to llx_whatsapp_config (per-line assignment mode)
-- Values: 'manual', 'roundrobin', 'leastactive'
ALTER TABLE llx_whatsapp_config ADD COLUMN assign_mode VARCHAR(20) DEFAULT 'manual' AFTER country_code;
