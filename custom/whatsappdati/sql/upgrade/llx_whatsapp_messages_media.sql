-- Copyright (C) 2024 DatiLab
-- Migration: Add media fields to messages table for multimedia support
--
-- NOTE: This file is for UPGRADES ONLY from versions prior to media support.
-- For fresh installations, these columns already exist in the main llx_whatsapp.sql DDL.
-- L7: Kept for backward-compatible upgrade path.

ALTER TABLE llx_whatsapp_messages ADD COLUMN media_filename VARCHAR(255) AFTER media_mime_type;
ALTER TABLE llx_whatsapp_messages ADD COLUMN media_local_path VARCHAR(500) AFTER media_filename;
