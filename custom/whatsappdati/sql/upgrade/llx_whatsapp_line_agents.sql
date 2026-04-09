-- Multi-agent per line support
-- Each WhatsApp line can have multiple agents assigned

CREATE TABLE IF NOT EXISTS llx_whatsapp_line_agents (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_line INTEGER NOT NULL,
    fk_user INTEGER NOT NULL,
    date_creation DATETIME NOT NULL,
    fk_user_creat INTEGER,
    INDEX idx_line (fk_line),
    INDEX idx_user (fk_user),
    UNIQUE KEY uk_line_user (fk_line, fk_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
