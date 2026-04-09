-- Copyright (C) 2024 DatiLab
--
-- Multi-agent assignment: allow multiple users per conversation
-- Each conversation can have several agents assigned; all see the conversation.
-- fk_user_assigned in llx_whatsapp_conversations remains as the "primary responder".

CREATE TABLE IF NOT EXISTS llx_whatsapp_conversation_agents
(
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_conversation     INTEGER NOT NULL,
    fk_user             INTEGER NOT NULL,
    role                VARCHAR(20) DEFAULT 'agent',  -- agent, observer
    date_creation       DATETIME NOT NULL,
    fk_user_creat       INTEGER,
    INDEX idx_conv (fk_conversation),
    INDEX idx_user (fk_user),
    UNIQUE KEY uk_conv_user (fk_conversation, fk_user),
    FOREIGN KEY (fk_conversation) REFERENCES llx_whatsapp_conversations(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
