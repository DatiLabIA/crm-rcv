-- Copyright (C) 2024 DatiLab
-- Keys for llx_cabinetmed_extcons_users table

ALTER TABLE llx_cabinetmed_extcons_users ADD INDEX idx_extcons_users_extcons (fk_extcons);
ALTER TABLE llx_cabinetmed_extcons_users ADD INDEX idx_extcons_users_user (fk_user);

ALTER TABLE llx_cabinetmed_extcons_users 
    ADD CONSTRAINT fk_extcons_users_extcons 
    FOREIGN KEY (fk_extcons) REFERENCES llx_cabinetmed_extcons(rowid) ON DELETE CASCADE;

ALTER TABLE llx_cabinetmed_extcons_users 
    ADD CONSTRAINT fk_extcons_users_user 
    FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE CASCADE;
