-- Copyright (C) 2024 Your Company
-- Structure for module CabinetMed ExtCons

-- 1. Table for consultation types
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

-- 2. Main consultation table
CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity              INTEGER NOT NULL DEFAULT 1,
    fk_soc              INTEGER NOT NULL,
    fk_user             INTEGER,
    date_start          DATETIME,
    date_end            DATETIME,
    tipo_atencion       VARCHAR(32),
    
    -- Adherence fields
    cumplimiento        VARCHAR(10),
    razon_inc           VARCHAR(255),
    mes_actual          VARCHAR(2),
    proximo_mes         VARCHAR(2),
    dificultad          INTEGER DEFAULT 0,
    
    -- Medical control fields
    motivo              VARCHAR(255),
    diagnostico         TEXT,
    
    -- Nursing fields
    procedimiento       VARCHAR(255),
    insumos_enf         VARCHAR(255),
    
    -- Pharmacy fields
    rx_num              VARCHAR(64),
    medicamentos        TEXT,
    
    -- Observaciones (universal, all consultation types, supports images)
    observaciones       MEDIUMTEXT,
    
    -- Custom fields data (JSON) & Status
    status              SMALLINT NOT NULL DEFAULT 0,
    custom_data         MEDIUMTEXT,
    
    -- Recurrence fields
    recurrence_enabled    TINYINT(1) NOT NULL DEFAULT 0,
    recurrence_interval   INT NOT NULL DEFAULT 1,
    recurrence_unit       VARCHAR(10) NOT NULL DEFAULT 'weeks',
    recurrence_end_type   VARCHAR(10) NOT NULL DEFAULT 'forever',
    recurrence_end_date   DATE NULL,
    recurrence_parent_id  INT NULL,
    
    -- Common fields
    note_private        TEXT,
    note_public         TEXT,
    
    -- System fields
    datec               DATETIME,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat       INTEGER,
    fk_user_modif       INTEGER,
    
    import_key          VARCHAR(14)
) ENGINE=innodb;

-- 3. Table for custom fields per consultation type
CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_fields (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER NOT NULL DEFAULT 1,
    fk_type         INTEGER NOT NULL,
    field_name      VARCHAR(64) NOT NULL,
    field_label     VARCHAR(255) NOT NULL,
    field_type      VARCHAR(32) NOT NULL,
    field_options   TEXT,
    conditional_field VARCHAR(64) NULL COMMENT 'Nombre del campo del cual depende la visibilidad',
    conditional_value TEXT NULL COMMENT 'Valor(es) que activan la visibilidad',
    required        INTEGER DEFAULT 0,
    position        INTEGER DEFAULT 0,
    active          INTEGER DEFAULT 1,
    datec           DATETIME,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;