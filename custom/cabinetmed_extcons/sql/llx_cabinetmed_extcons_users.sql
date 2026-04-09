-- Copyright (C) 2024 DatiLab
-- Table for multiple assigned users per consultation

CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_users (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_extcons      INTEGER NOT NULL,
    fk_user         INTEGER NOT NULL,
    role            VARCHAR(64) DEFAULT 'assigned',
    datec           DATETIME,
    fk_user_creat   INTEGER,
    UNIQUE KEY uk_extcons_user (fk_extcons, fk_user)
) ENGINE=innodb;
