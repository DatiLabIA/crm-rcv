-- ============================================================
-- Script de migración para módulo Gestión
-- Ejecutar si la tabla llx_gestion_medico ya existe con la estructura anterior
-- ============================================================

-- 1. Crear tabla pivote médico-EPS
CREATE TABLE IF NOT EXISTS llx_gestion_medico_eps (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_medico INTEGER NOT NULL,
    fk_eps INTEGER NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medico_eps_medico (fk_medico),
    INDEX idx_medico_eps_eps (fk_eps),
    UNIQUE KEY uk_medico_eps (fk_medico, fk_eps)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Migrar datos de EPS existentes a la tabla pivote
INSERT IGNORE INTO llx_gestion_medico_eps (fk_medico, fk_eps)
SELECT rowid, fk_eps FROM llx_gestion_medico WHERE fk_eps IS NOT NULL AND fk_eps > 0;

-- 3. Renombrar campos singulares a plurales y convertir datos existentes
-- Ciudad -> Ciudades (JSON)
ALTER TABLE llx_gestion_medico ADD COLUMN IF NOT EXISTS ciudades TEXT COMMENT 'JSON array de ciudades';
UPDATE llx_gestion_medico SET ciudades = CONCAT('["', ciudad, '"]') WHERE ciudad IS NOT NULL AND ciudad != '' AND (ciudades IS NULL OR ciudades = '');
ALTER TABLE llx_gestion_medico DROP COLUMN IF EXISTS ciudad;

-- Departamento -> Departamentos (JSON)
ALTER TABLE llx_gestion_medico ADD COLUMN IF NOT EXISTS departamentos TEXT COMMENT 'JSON array de departamentos';
UPDATE llx_gestion_medico SET departamentos = CONCAT('["', departamento, '"]') WHERE departamento IS NOT NULL AND departamento != '' AND (departamentos IS NULL OR departamentos = '');
ALTER TABLE llx_gestion_medico DROP COLUMN IF EXISTS departamento;

-- Especialidad -> Especialidades (JSON)
ALTER TABLE llx_gestion_medico ADD COLUMN IF NOT EXISTS especialidades TEXT COMMENT 'JSON array de especialidades';
UPDATE llx_gestion_medico SET especialidades = CONCAT('["', especialidad, '"]') WHERE especialidad IS NOT NULL AND especialidad != '' AND (especialidades IS NULL OR especialidades = '');
ALTER TABLE llx_gestion_medico DROP COLUMN IF EXISTS especialidad;

-- 4. Eliminar columna fk_eps (ya migrada a tabla pivote)
ALTER TABLE llx_gestion_medico DROP COLUMN IF EXISTS fk_eps;

-- 5. Agregar campo calculado concentracion_display en detalle de medicamentos
-- Concatena concentración + unidad (ej: "500 mg")
ALTER TABLE llx_gestion_medicamento_det
    ADD COLUMN IF NOT EXISTS concentracion_display VARCHAR(155) GENERATED ALWAYS AS (
        CASE
            WHEN concentracion IS NOT NULL AND unidad IS NOT NULL AND concentracion != '' AND unidad != ''
                THEN CONCAT(concentracion, ' ', unidad)
            WHEN concentracion IS NOT NULL AND concentracion != ''
                THEN concentracion
            ELSE NULL
        END
    ) STORED;
