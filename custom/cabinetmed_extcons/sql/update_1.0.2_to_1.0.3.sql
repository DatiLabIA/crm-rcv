-- Copyright (C) 2024 Your Company
-- Update from version 1.0.2 to 1.0.3
-- Add custom fields support for consultation types

-- Create consultation types table
CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_types (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER NOT NULL DEFAULT 1,
    code            VARCHAR(32) NOT NULL,
    label           VARCHAR(255) NOT NULL,
    description     TEXT,
    active          INTEGER DEFAULT 1,
    position        INTEGER DEFAULT 0,
    fields_config   TEXT,
    datec           DATETIME,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat   INTEGER,
    fk_user_modif   INTEGER
) ENGINE=innodb;

-- Add indexes for types
ALTER TABLE llx_cabinetmed_extcons_types ADD UNIQUE INDEX uk_extcons_types_code (code, entity);
ALTER TABLE llx_cabinetmed_extcons_types ADD INDEX idx_extcons_types_entity (entity);
ALTER TABLE llx_cabinetmed_extcons_types ADD INDEX idx_extcons_types_active (active);

-- Create custom fields table
CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_fields (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER NOT NULL DEFAULT 1,
    fk_type         INTEGER NOT NULL,
    field_name      VARCHAR(64) NOT NULL,
    field_label     VARCHAR(255) NOT NULL,
    field_type      VARCHAR(32) NOT NULL,
    field_options   TEXT,
    required        INTEGER DEFAULT 0,
    position        INTEGER DEFAULT 0,
    active          INTEGER DEFAULT 1,
    datec           DATETIME,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

-- Add indexes for fields
ALTER TABLE llx_cabinetmed_extcons_fields ADD INDEX idx_extcons_fields_type (fk_type);
ALTER TABLE llx_cabinetmed_extcons_fields ADD INDEX idx_extcons_fields_entity (entity);

-- Add foreign key with check
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'llx_cabinetmed_extcons_fields' 
    AND CONSTRAINT_NAME = 'fk_extcons_fields_type' 
    AND CONSTRAINT_TYPE = 'FOREIGN KEY');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE llx_cabinetmed_extcons_fields ADD CONSTRAINT fk_extcons_fields_type FOREIGN KEY (fk_type) REFERENCES llx_cabinetmed_extcons_types (rowid) ON DELETE CASCADE',
    'SELECT "Foreign key fk_extcons_fields_type already exists" AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add custom_data column with check
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'llx_cabinetmed_extcons' 
    AND COLUMN_NAME = 'custom_data');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE llx_cabinetmed_extcons ADD COLUMN custom_data TEXT AFTER medicamentos',
    'SELECT "Column custom_data already exists" AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE llx_cabinetmed_extcons
    ADD COLUMN status SMALLINT NOT NULL DEFAULT 0 AFTER medicamentos;
