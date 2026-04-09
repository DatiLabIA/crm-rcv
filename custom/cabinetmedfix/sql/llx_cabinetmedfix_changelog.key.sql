-- Copyright (C) 2026 CRM-RCV
--
-- Constraints/foreign keys for llx_cabinetmedfix_changelog
-- (executed after table creation)

ALTER TABLE llx_cabinetmedfix_changelog ADD CONSTRAINT fk_changelog_societe FOREIGN KEY (fk_societe) REFERENCES llx_societe(rowid);
ALTER TABLE llx_cabinetmedfix_changelog ADD CONSTRAINT fk_changelog_user FOREIGN KEY (fk_user) REFERENCES llx_user(rowid);
