-- Copyright (C) 2024-2026 DatiLab
--
-- Migration: Business Hours, Agent Transfer Logs, CSAT Surveys
-- ============================================================================

-- ============================================================================
-- Agent Transfer Log (audit trail for conversation transfers between agents)
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_whatsapp_transfers
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_conversation         INTEGER NOT NULL,
    from_user_id            INTEGER,                -- Agent who initiated the transfer (NULL = system/unassigned)
    to_user_id              INTEGER NOT NULL,        -- Agent receiving the conversation
    note                    TEXT,                    -- Context note from transferring agent
    date_transfer           DATETIME NOT NULL,
    INDEX idx_entity (entity),
    INDEX idx_conversation (fk_conversation),
    INDEX idx_from_user (from_user_id),
    INDEX idx_to_user (to_user_id),
    FOREIGN KEY (fk_conversation) REFERENCES llx_whatsapp_conversations(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CSAT (Customer Satisfaction) Surveys
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_whatsapp_csat
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_conversation         INTEGER NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    phone_number            VARCHAR(50) NOT NULL,
    rating                  TINYINT DEFAULT NULL,    -- 1-5 stars (NULL = pending response)
    feedback_text           TEXT,                    -- Optional text feedback from customer
    fk_user_agent           INTEGER,                 -- Agent who handled the conversation
    sent_at                 DATETIME NOT NULL,       -- When survey was sent
    responded_at            DATETIME DEFAULT NULL,   -- When customer responded
    message_id_wa           VARCHAR(100),            -- WhatsApp message ID of the survey
    status                  VARCHAR(20) DEFAULT 'sent', -- sent, responded, expired
    date_creation           DATETIME NOT NULL,
    INDEX idx_entity (entity),
    INDEX idx_conversation (fk_conversation),
    INDEX idx_rating (rating),
    INDEX idx_agent (fk_user_agent),
    INDEX idx_status (status),
    INDEX idx_sent (sent_at),
    FOREIGN KEY (fk_conversation) REFERENCES llx_whatsapp_conversations(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
