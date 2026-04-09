-- Copyright (C) 2024 Your Company
-- Indexes and foreign keys for module CabinetMed ExtCons

-- 1. Indexes for Types
ALTER TABLE llx_cabinetmed_extcons_types ADD UNIQUE INDEX uk_extcons_types_code (code, entity);
ALTER TABLE llx_cabinetmed_extcons_types ADD INDEX idx_extcons_types_entity (entity);
ALTER TABLE llx_cabinetmed_extcons_types ADD INDEX idx_extcons_types_active (active);

-- 2. Indexes and FK for Main Table
ALTER TABLE llx_cabinetmed_extcons ADD INDEX idx_extcons_soc (fk_soc);
ALTER TABLE llx_cabinetmed_extcons ADD INDEX idx_extcons_user (fk_user);
ALTER TABLE llx_cabinetmed_extcons ADD INDEX idx_extcons_entity (entity);
ALTER TABLE llx_cabinetmed_extcons ADD INDEX idx_extcons_tipo (tipo_atencion);
ALTER TABLE llx_cabinetmed_extcons ADD INDEX idx_extcons_date_start (date_start);

ALTER TABLE llx_cabinetmed_extcons ADD CONSTRAINT fk_extcons_soc FOREIGN KEY (fk_soc) REFERENCES llx_societe (rowid);
ALTER TABLE llx_cabinetmed_extcons ADD CONSTRAINT fk_extcons_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid);
ALTER TABLE llx_cabinetmed_extcons ADD CONSTRAINT fk_extcons_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user (rowid);
ALTER TABLE llx_cabinetmed_extcons ADD CONSTRAINT fk_extcons_user_modif FOREIGN KEY (fk_user_modif) REFERENCES llx_user (rowid);

-- 3. Indexes and FK for Fields
ALTER TABLE llx_cabinetmed_extcons_fields ADD INDEX idx_extcons_fields_type (fk_type);
ALTER TABLE llx_cabinetmed_extcons_fields ADD INDEX idx_extcons_fields_entity (entity);

ALTER TABLE llx_cabinetmed_extcons_fields ADD CONSTRAINT fk_extcons_fields_type 
    FOREIGN KEY (fk_type) REFERENCES llx_cabinetmed_extcons_types (rowid) ON DELETE CASCADE;