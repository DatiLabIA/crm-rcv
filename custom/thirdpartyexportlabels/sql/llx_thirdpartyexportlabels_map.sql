-- Copyright (C) 2026 DatiLab <info@datilab.com>
--
-- ThirdpartyExportLabels - Mapping configuration table
--

CREATE TABLE llx_thirdpartyexportlabels_map (
    rowid       INT AUTO_INCREMENT PRIMARY KEY,
    extrafield_name VARCHAR(128) NOT NULL,
    table_name  VARCHAR(128) NOT NULL,
    key_field   VARCHAR(128) NOT NULL DEFAULT 'rowid',
    label_field VARCHAR(128) NOT NULL,
    active      TINYINT NOT NULL DEFAULT 1,
    tms         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_extrafield_name (extrafield_name)
) ENGINE=InnoDB;
