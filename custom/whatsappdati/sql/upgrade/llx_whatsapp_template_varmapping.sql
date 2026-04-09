-- Copyright (C) 2024 DatiLab
-- Variable mapping & header media for templates
-- ============================================================================

-- Column to store variable-to-source mapping as JSON
-- Example: {"1":{"type":"contact_name","label":"Nombre paciente"},"2":{"type":"free_text","label":"Motivo"}}
ALTER TABLE llx_whatsapp_templates ADD COLUMN variable_mapping TEXT DEFAULT NULL AFTER variables;

-- Column to store the header image mode: 'on_template' or 'on_send'
ALTER TABLE llx_whatsapp_templates ADD COLUMN header_image_mode VARCHAR(20) DEFAULT 'on_send' AFTER header_content;

-- Column to store uploaded media URL/handle for templates with header IMAGE/VIDEO/DOCUMENT
-- When header_image_mode = 'on_template', this stores the Meta media handle or public URL
ALTER TABLE llx_whatsapp_templates ADD COLUMN header_media_url TEXT DEFAULT NULL AFTER header_image_mode;

-- Column to store local file path of uploaded header media
ALTER TABLE llx_whatsapp_templates ADD COLUMN header_media_local TEXT DEFAULT NULL AFTER header_media_url;
