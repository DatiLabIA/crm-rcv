-- Copyright (C) 2024 DatiLab
-- Table for user favorites

CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_favorites (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_extcons      INTEGER NOT NULL,
    fk_user         INTEGER NOT NULL,
    datec           DATETIME,
    UNIQUE KEY uk_extcons_favorite (fk_extcons, fk_user)
) ENGINE=innodb;
