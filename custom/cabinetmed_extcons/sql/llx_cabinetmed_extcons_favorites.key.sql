-- Copyright (C) 2024 DatiLab
-- Keys for llx_cabinetmed_extcons_favorites table

ALTER TABLE llx_cabinetmed_extcons_favorites ADD INDEX idx_favorites_extcons (fk_extcons);
ALTER TABLE llx_cabinetmed_extcons_favorites ADD INDEX idx_favorites_user (fk_user);

ALTER TABLE llx_cabinetmed_extcons_favorites 
    ADD CONSTRAINT fk_favorites_extcons 
    FOREIGN KEY (fk_extcons) REFERENCES llx_cabinetmed_extcons(rowid) ON DELETE CASCADE;

ALTER TABLE llx_cabinetmed_extcons_favorites 
    ADD CONSTRAINT fk_favorites_user 
    FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;
