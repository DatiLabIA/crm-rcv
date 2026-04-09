-- Copyright (C) 2025 DatiLab <info@datilab.com>

ALTER TABLE llx_importfill_job ADD INDEX idx_importfill_job_entity (entity);
ALTER TABLE llx_importfill_job ADD INDEX idx_importfill_job_status (status);
ALTER TABLE llx_importfill_job ADD INDEX idx_importfill_job_fk_user (fk_user_author);
ALTER TABLE llx_importfill_job ADD CONSTRAINT fk_importfill_job_user FOREIGN KEY (fk_user_author) REFERENCES llx_user(rowid);
