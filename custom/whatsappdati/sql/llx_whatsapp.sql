-- Copyright (C) 2024 DatiLab
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- ============================================================================
-- WhatsApp Business Module - Database Tables
-- ============================================================================

-- ConfiguraciÃ³n del mÃ³dulo WhatsApp
CREATE TABLE IF NOT EXISTS llx_whatsapp_config
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    label                   VARCHAR(100) DEFAULT NULL,
    app_id                  VARCHAR(50) DEFAULT NULL,
    phone_number_id         VARCHAR(50) NOT NULL,
    business_account_id     VARCHAR(50) NOT NULL,
    access_token            TEXT NOT NULL,
    webhook_verify_token    VARCHAR(100) NOT NULL,
    app_secret              VARCHAR(255) DEFAULT NULL,
    webhook_url             VARCHAR(255),
    fk_user_default_agent   INTEGER DEFAULT NULL,
    country_code            VARCHAR(5) DEFAULT '57',
    assign_mode             VARCHAR(20) DEFAULT 'manual',
    status                  TINYINT DEFAULT 1,
    date_creation           DATETIME NOT NULL,
    date_modification       DATETIME,
    fk_user_creat           INTEGER,
    fk_user_modif           INTEGER,
    import_key              VARCHAR(14)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversaciones de WhatsApp
CREATE TABLE IF NOT EXISTS llx_whatsapp_conversations
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    conversation_id         VARCHAR(100) NOT NULL UNIQUE,
    phone_number            VARCHAR(50) NOT NULL,
    contact_name            VARCHAR(255),
    fk_soc                  INTEGER,              -- Link a tercero Dolibarr
    fk_user_assigned        INTEGER,              -- Agente asignado
    status                  VARCHAR(20) DEFAULT 'active', -- active, archived, closed
    last_message_date       DATETIME,
    last_message_preview    TEXT,
    unread_count            INTEGER DEFAULT 0,
    window_expires_at       DATETIME,             -- Ventana 24h WhatsApp
    date_creation           DATETIME NOT NULL,
    date_modification       DATETIME,
    import_key              VARCHAR(14),
    INDEX idx_phone (phone_number),
    INDEX idx_status (status),
    INDEX idx_assigned (fk_user_assigned),
    INDEX idx_entity (entity),
    INDEX idx_last_message (last_message_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensajes de WhatsApp
CREATE TABLE IF NOT EXISTS llx_whatsapp_messages
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    message_id              VARCHAR(100) UNIQUE,
    fk_conversation         INTEGER NOT NULL,
    direction               VARCHAR(10) NOT NULL, -- inbound, outbound
    message_type            VARCHAR(20) DEFAULT 'text', -- text, template, image, document, video, audio
    content                 TEXT,
    template_name           VARCHAR(100),
    template_params         TEXT,                 -- JSON string
    media_url               VARCHAR(500),
    media_mime_type         VARCHAR(100),
    media_filename          VARCHAR(255),
    media_local_path        VARCHAR(500),
    status                  VARCHAR(20) DEFAULT 'pending', -- pending, sent, delivered, read, failed
    error_message           TEXT,
    fk_user_sender          INTEGER,
    timestamp               DATETIME NOT NULL,
    date_creation           DATETIME NOT NULL,
    import_key              VARCHAR(14),
    INDEX idx_conversation (fk_conversation),
    INDEX idx_status (status),
    INDEX idx_timestamp (timestamp),
    INDEX idx_entity (entity),
    FOREIGN KEY (fk_conversation) REFERENCES llx_whatsapp_conversations(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plantillas de WhatsApp
CREATE TABLE IF NOT EXISTS llx_whatsapp_templates
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    template_id             VARCHAR(100),
    name                    VARCHAR(100) NOT NULL,
    language                VARCHAR(10) DEFAULT 'es',
    category                VARCHAR(50),          -- MARKETING, UTILITY, AUTHENTICATION
    status                  VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected
    header_type             VARCHAR(20),          -- TEXT, IMAGE, VIDEO, DOCUMENT, none
    header_content          TEXT,
    body_text               TEXT NOT NULL,
    footer_text             VARCHAR(60),
    buttons                 TEXT,                 -- JSON string
    variables               TEXT,                 -- JSON string - Lista de variables {{1}}, {{2}}
    sync_date               DATETIME,
    date_creation           DATETIME NOT NULL,
    date_modification       DATETIME,
    fk_user_creat           INTEGER,
    fk_user_modif           INTEGER,
    import_key              VARCHAR(14),
    INDEX idx_name (name),
    INDEX idx_status (status),
    INDEX idx_entity (entity),
    UNIQUE KEY uk_template (entity, name, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de mensajes programados y envÃ­os masivos
CREATE TABLE IF NOT EXISTS llx_whatsapp_queue
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    batch_id                VARCHAR(50),              -- Groups items from same bulk send
    phone_number            VARCHAR(50) NOT NULL,
    contact_name            VARCHAR(255),
    fk_soc                  INTEGER,
    fk_template             INTEGER,
    template_name           VARCHAR(100),
    template_params         TEXT,                 -- JSON string
    message_content         TEXT,
    scheduled_date          DATETIME NOT NULL,
    status                  VARCHAR(20) DEFAULT 'pending', -- pending, processing, sent, failed, cancelled
    retry_count             INTEGER DEFAULT 0,
    max_retries             INTEGER DEFAULT 3,
    error_message           TEXT,
    message_id_wa           VARCHAR(100),         -- WhatsApp message ID returned by API
    date_sent               DATETIME,
    date_creation           DATETIME NOT NULL,
    fk_user_creat           INTEGER,
    import_key              VARCHAR(14),
    INDEX idx_batch (batch_id),
    INDEX idx_scheduled (scheduled_date),
    INDEX idx_status (status),
    INDEX idx_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etiquetas / Tags para conversaciones
CREATE TABLE IF NOT EXISTS llx_whatsapp_tags
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    label                   VARCHAR(100) NOT NULL,
    color                   VARCHAR(7) DEFAULT '#25D366', -- HEX color
    description             VARCHAR(255),
    position                INTEGER DEFAULT 0,
    active                  TINYINT DEFAULT 1,
    date_creation           DATETIME NOT NULL,
    fk_user_creat           INTEGER,
    INDEX idx_entity (entity),
    UNIQUE KEY uk_tag_label (entity, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RelaciÃ³n muchos-a-muchos: conversaciones <-> tags
CREATE TABLE IF NOT EXISTS llx_whatsapp_conversation_tags
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_conversation         INTEGER NOT NULL,
    fk_tag                  INTEGER NOT NULL,
    date_creation           DATETIME NOT NULL,
    fk_user_creat           INTEGER,
    INDEX idx_conversation (fk_conversation),
    INDEX idx_tag (fk_tag),
    UNIQUE KEY uk_conv_tag (fk_conversation, fk_tag),
    FOREIGN KEY (fk_conversation) REFERENCES llx_whatsapp_conversations(rowid) ON DELETE CASCADE,
    FOREIGN KEY (fk_tag) REFERENCES llx_whatsapp_tags(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Respuestas rÃ¡pidas / Quick Replies
CREATE TABLE IF NOT EXISTS llx_whatsapp_quick_replies
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    shortcut                VARCHAR(50) NOT NULL,       -- e.g. /gracias, /horario
    title                   VARCHAR(150) NOT NULL,      -- Descriptive name
    content                 TEXT NOT NULL,               -- Full reply text
    category                VARCHAR(100),               -- Optional grouping
    position                INTEGER DEFAULT 0,
    active                  TINYINT DEFAULT 1,
    date_creation           DATETIME NOT NULL,
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat           INTEGER,
    INDEX idx_entity (entity),
    INDEX idx_category (category),
    UNIQUE KEY uk_quick_reply_shortcut (entity, shortcut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chatbot / Respuestas automÃ¡ticas
CREATE TABLE IF NOT EXISTS llx_whatsapp_chatbot_rules
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    name                    VARCHAR(200) NOT NULL,          -- Rule display name
    trigger_type            VARCHAR(30) NOT NULL,           -- exact, contains, starts_with, regex, default, new_conversation
    trigger_value           VARCHAR(500),                   -- Keyword/pattern (NULL for default/new_conversation)
    response_type           VARCHAR(30) DEFAULT 'text',     -- text, template
    response_text           TEXT,                           -- Response message body
    response_template_name  VARCHAR(200),                   -- Template name if response_type=template
    response_template_params TEXT,                          -- JSON params for template
    delay_seconds           INTEGER DEFAULT 0,              -- Delay before auto-reply (0 = immediate)
    condition_type          VARCHAR(30) DEFAULT 'always',   -- always, outside_hours, unassigned
    work_hours_start        TIME DEFAULT '09:00:00',        -- For outside_hours condition
    work_hours_end          TIME DEFAULT '18:00:00',        -- For outside_hours condition
    max_triggers_per_conv   INTEGER DEFAULT 0,              -- 0 = unlimited, N = max times per conversation
    priority                INTEGER DEFAULT 10,             -- Lower = higher priority
    stop_on_match           TINYINT DEFAULT 1,              -- Stop checking rules after this match
    active                  TINYINT DEFAULT 1,
    date_creation           DATETIME NOT NULL,
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat           INTEGER,
    INDEX idx_entity (entity),
    INDEX idx_trigger (trigger_type),
    INDEX idx_priority (priority),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de ejecuciones del chatbot (para limitar triggers por conversaciÃ³n)
CREATE TABLE IF NOT EXISTS llx_whatsapp_chatbot_log
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_rule                 INTEGER NOT NULL,
    fk_conversation         INTEGER NOT NULL,
    fk_message_in           INTEGER,                        -- Inbound message that triggered the rule
    fk_message_out          INTEGER,                        -- Outbound auto-reply message
    date_triggered          DATETIME NOT NULL,
    INDEX idx_rule (fk_rule),
    INDEX idx_conversation (fk_conversation),
    INDEX idx_rule_conv (fk_rule, fk_conversation),
    FOREIGN KEY (fk_rule) REFERENCES llx_whatsapp_chatbot_rules(rowid) ON DELETE CASCADE,
    FOREIGN KEY (fk_conversation) REFERENCES llx_whatsapp_conversations(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensajes programados (individuales)
CREATE TABLE IF NOT EXISTS llx_whatsapp_scheduled
(
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER DEFAULT 1 NOT NULL,
    fk_line                 INTEGER DEFAULT NULL,
    phone_number            VARCHAR(50) NOT NULL,
    contact_name            VARCHAR(255),
    fk_soc                  INTEGER,                        -- Link to thirdparty
    fk_conversation         INTEGER,                        -- Link to conversation (if exists)
    message_type            VARCHAR(20) DEFAULT 'text',     -- text, template
    message_content         TEXT,                           -- Message body for text type
    template_name           VARCHAR(100),                   -- Template name if message_type=template
    template_params         TEXT,                           -- JSON params for template
    scheduled_date          DATETIME NOT NULL,              -- When to send
    recurrence_type         VARCHAR(20) DEFAULT 'once',     -- once, daily, weekly, monthly
    recurrence_end_date     DATETIME,                       -- When recurrence stops (NULL = no end)
    next_execution          DATETIME,                       -- Next scheduled run (for recurring)
    status                  VARCHAR(20) DEFAULT 'pending',  -- pending, sent, failed, cancelled, paused
    last_execution          DATETIME,                       -- Last time this was executed
    execution_count         INTEGER DEFAULT 0,              -- How many times executed (for recurring)
    retry_count             INTEGER DEFAULT 0,
    max_retries             INTEGER DEFAULT 3,
    error_message           TEXT,
    message_id_wa           VARCHAR(100),                   -- Last WhatsApp message ID
    note                    VARCHAR(500),                   -- User note/description
    date_creation           DATETIME NOT NULL,
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat           INTEGER,
    INDEX idx_entity (entity),
    INDEX idx_scheduled (scheduled_date),
    INDEX idx_next_exec (next_execution),
    INDEX idx_status (status),
    INDEX idx_phone (phone_number),
    INDEX idx_recurrence (recurrence_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relación muchos-a-muchos: conversaciones <-> agentes asignados
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

-- Relación muchos-a-muchos: líneas <-> agentes asignados
CREATE TABLE IF NOT EXISTS llx_whatsapp_line_agents
(
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_line             INTEGER NOT NULL,
    fk_user             INTEGER NOT NULL,
    date_creation       DATETIME NOT NULL,
    fk_user_creat       INTEGER,
    INDEX idx_line (fk_line),
    INDEX idx_user (fk_user),
    UNIQUE KEY uk_line_user (fk_line, fk_user),
    FOREIGN KEY (fk_line) REFERENCES llx_whatsapp_config(rowid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
