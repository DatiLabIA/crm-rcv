-- Copyright (C) 2026 CRM-RCV
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- Tabla para almacenar el historial detallado de cambios en terceros/pacientes

CREATE TABLE IF NOT EXISTS llx_cabinetmedfix_changelog (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_societe      INTEGER NOT NULL,                    -- ID del tercero/paciente modificado
    fk_user         INTEGER NOT NULL,                    -- Usuario que realizó el cambio
    datec           DATETIME NOT NULL,                   -- Fecha y hora del cambio
    action_type     VARCHAR(32) NOT NULL DEFAULT 'MODIFY', -- MODIFY, CREATE, DELETE
    field_name      VARCHAR(255) NOT NULL,               -- Nombre técnico del campo (ej: 'name', 'options_blood_type')
    field_label     VARCHAR(255) DEFAULT NULL,            -- Nombre legible del campo (ej: 'Nombre', 'Tipo de sangre')
    field_type      VARCHAR(32) DEFAULT 'standard',      -- 'standard' o 'extrafield'
    old_value       TEXT DEFAULT NULL,                    -- Valor anterior
    new_value       TEXT DEFAULT NULL,                    -- Valor nuevo
    ip_address      VARCHAR(250) DEFAULT NULL,            -- IP del usuario
    user_agent      VARCHAR(255) DEFAULT NULL,            -- Navegador del usuario
    entity          INTEGER DEFAULT 1 NOT NULL            -- Multi-company entity
) ENGINE=InnoDB;

ALTER TABLE llx_cabinetmedfix_changelog ADD INDEX idx_changelog_fk_societe (fk_societe);
ALTER TABLE llx_cabinetmedfix_changelog ADD INDEX idx_changelog_fk_user (fk_user);
ALTER TABLE llx_cabinetmedfix_changelog ADD INDEX idx_changelog_datec (datec);
ALTER TABLE llx_cabinetmedfix_changelog ADD INDEX idx_changelog_field_name (field_name);
ALTER TABLE llx_cabinetmedfix_changelog ADD INDEX idx_changelog_action_type (action_type);
