-- Copyright (C) 2026 CRM-RCV
--
-- Tabla para almacenar etiquetas de documentos de pacientes

CREATE TABLE IF NOT EXISTS llx_cabinetmedfix_doc_labels (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_societe      INTEGER NOT NULL,                    -- ID del paciente
    filename        VARCHAR(255) NOT NULL,               -- Nombre del archivo
    tags            TEXT DEFAULT NULL,                    -- Etiquetas separadas por comas
    fk_user_modif   INTEGER DEFAULT NULL,                -- Último usuario que modificó
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=InnoDB;

ALTER TABLE llx_cabinetmedfix_doc_labels ADD UNIQUE INDEX uk_doc_labels_file (fk_societe, filename);
ALTER TABLE llx_cabinetmedfix_doc_labels ADD INDEX idx_doc_labels_societe (fk_societe);
