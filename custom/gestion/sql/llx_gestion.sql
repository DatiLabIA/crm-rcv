-- Módulo Gestión - Dolibarr
-- Copyright (C) 2024 DatiLab

CREATE TABLE IF NOT EXISTS llx_gestion_programa (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity INTEGER DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_gestion_diagnostico (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT,
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity INTEGER DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_gestion_eps (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50),
    descripcion VARCHAR(255),
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity INTEGER DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_gestion_medicamento (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    etiqueta VARCHAR(255),
    estado TINYINT DEFAULT 1,
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity INTEGER DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_gestion_medicamento_det (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_medicamento INTEGER NOT NULL,
    concentracion VARCHAR(100),
    unidad VARCHAR(50),
    concentracion_display VARCHAR(155) GENERATED ALWAYS AS (
        CASE
            WHEN concentracion IS NOT NULL AND unidad IS NOT NULL AND concentracion != '' AND unidad != ''
                THEN CONCAT(concentracion, ' ', unidad)
            WHEN concentracion IS NOT NULL AND concentracion != ''
                THEN concentracion
            ELSE NULL
        END
    ) STORED,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fk_med (fk_medicamento),
    CONSTRAINT fk_gestion_med_det FOREIGN KEY (fk_medicamento) REFERENCES llx_gestion_medicamento(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_gestion_medico (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(50),
    nombre VARCHAR(255) NOT NULL,
    tipo_doc VARCHAR(10),
    numero_identificacion VARCHAR(50),
    tarjeta_profesional VARCHAR(50),
    ciudades TEXT COMMENT 'JSON array de ciudades',
    departamentos TEXT COMMENT 'JSON array de departamentos',
    especialidades TEXT COMMENT 'JSON array de especialidades',
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity INTEGER DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla pivote para relación muchos a muchos entre médicos y EPS
CREATE TABLE IF NOT EXISTS llx_gestion_medico_eps (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_medico INTEGER NOT NULL,
    fk_eps INTEGER NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medico_eps_medico (fk_medico),
    INDEX idx_medico_eps_eps (fk_eps),
    UNIQUE KEY uk_medico_eps (fk_medico, fk_eps),
    CONSTRAINT fk_gestion_me_medico FOREIGN KEY (fk_medico) REFERENCES llx_gestion_medico(rowid) ON DELETE CASCADE,
    CONSTRAINT fk_gestion_me_eps FOREIGN KEY (fk_eps) REFERENCES llx_gestion_eps(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_gestion_operador (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity INTEGER DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
