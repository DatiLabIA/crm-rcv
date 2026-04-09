-- ============================================================================
-- Actualización CabinetMed ExtCons v1.0.3 -> v1.1.0
-- Nuevas características:
--   1. Múltiples encargados por atención
--   2. Sistema de favoritos por usuario
-- ============================================================================

-- ============================================================================
-- 1. TABLA DE RELACIÓN: Múltiples encargados por atención
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_users (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_extcons      INTEGER NOT NULL,
    fk_user         INTEGER NOT NULL,
    role            VARCHAR(64) DEFAULT 'assigned',  -- 'assigned', 'supervisor', 'observer', etc.
    datec           DATETIME,
    fk_user_creat   INTEGER,
    UNIQUE KEY uk_extcons_user (fk_extcons, fk_user)
) ENGINE=innodb;

-- Índices para performance
ALTER TABLE llx_cabinetmed_extcons_users ADD INDEX idx_extcons_users_extcons (fk_extcons);
ALTER TABLE llx_cabinetmed_extcons_users ADD INDEX idx_extcons_users_user (fk_user);

-- Foreign keys
ALTER TABLE llx_cabinetmed_extcons_users 
    ADD CONSTRAINT fk_extcons_users_extcons 
    FOREIGN KEY (fk_extcons) REFERENCES llx_cabinetmed_extcons(rowid) ON DELETE CASCADE;

ALTER TABLE llx_cabinetmed_extcons_users 
    ADD CONSTRAINT fk_extcons_users_user 
    FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;

-- ============================================================================
-- 2. TABLA DE FAVORITOS: Atenciones marcadas como favoritas por cada usuario
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_cabinetmed_extcons_favorites (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_extcons      INTEGER NOT NULL,
    fk_user         INTEGER NOT NULL,
    datec           DATETIME,
    UNIQUE KEY uk_extcons_favorite (fk_extcons, fk_user)
) ENGINE=innodb;

-- Índices para performance
ALTER TABLE llx_cabinetmed_extcons_favorites ADD INDEX idx_favorites_extcons (fk_extcons);
ALTER TABLE llx_cabinetmed_extcons_favorites ADD INDEX idx_favorites_user (fk_user);

-- Foreign keys
ALTER TABLE llx_cabinetmed_extcons_favorites 
    ADD CONSTRAINT fk_favorites_extcons 
    FOREIGN KEY (fk_extcons) REFERENCES llx_cabinetmed_extcons(rowid) ON DELETE CASCADE;

ALTER TABLE llx_cabinetmed_extcons_favorites 
    ADD CONSTRAINT fk_favorites_user 
    FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;

-- ============================================================================
-- 3. MIGRACIÓN: Mover fk_user existente a la nueva tabla de relación
-- ============================================================================
-- Esto copia los encargados actuales (fk_user) a la nueva tabla de relación
INSERT INTO llx_cabinetmed_extcons_users (fk_extcons, fk_user, role, datec, fk_user_creat)
SELECT rowid, fk_user, 'assigned', NOW(), fk_user_creat
FROM llx_cabinetmed_extcons
WHERE fk_user IS NOT NULL AND fk_user > 0
ON DUPLICATE KEY UPDATE role = 'assigned';

-- Nota: El campo fk_user en la tabla principal se mantiene por compatibilidad
-- pero ahora se considera como "encargado principal" o legacy
