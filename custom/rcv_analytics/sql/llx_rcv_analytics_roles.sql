-- ============================================================================
-- RCV Analytics — Sistema de roles/alcance por programa y medicamento
-- ----------------------------------------------------------------------------
-- Estas tablas también se crean automáticamente al abrir la página
-- custom/rcv_analytics/admin/roles.php (RcvAnalyticsScope::ensureTables()).
-- Este script se provee para ejecución manual opcional en phpMyAdmin.
-- ============================================================================

CREATE TABLE IF NOT EXISTS llx_rcv_analytics_role (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    all_access TINYINT DEFAULT 0,
    entity INTEGER DEFAULT 1,
    datec DATETIME,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_rcv_analytics_role_programa (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_role INTEGER NOT NULL,
    fk_programa INTEGER NOT NULL,
    UNIQUE KEY uk_role_prog (fk_role, fk_programa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_rcv_analytics_role_medicamento (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_role INTEGER NOT NULL,
    fk_medicamento INTEGER NOT NULL,
    UNIQUE KEY uk_role_med (fk_role, fk_medicamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_rcv_analytics_user_role (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_user INTEGER NOT NULL,
    fk_role INTEGER NOT NULL,
    UNIQUE KEY uk_user_role (fk_user, fk_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
