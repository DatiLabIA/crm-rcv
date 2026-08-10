-- Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed
--
-- Tabla para almacenar colores personalizados por usuario sobre consultas del calendario
-- Cada usuario puede asignar su propio color de etiqueta a cualquier consulta

CREATE TABLE IF NOT EXISTS llx_cabinetmed_calendar_colors (
    rowid       INTEGER NOT NULL AUTO_INCREMENT,
    fk_extcons  INTEGER NOT NULL,           -- FK a llx_cabinetmed_extcons.rowid
    fk_user     INTEGER NOT NULL,           -- FK a llx_user.rowid (color personal por usuario)
    color       VARCHAR(7) NOT NULL,        -- Código hex del color ej: #FF5733
    datec       DATETIME,                   -- Fecha de creación del registro
    tms         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid)
) ENGINE=innodb;
