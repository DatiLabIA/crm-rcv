-- Copyright (C) 2025 DatiLab <info@datilab.com>

ALTER TABLE llx_importfill_job_line ADD INDEX idx_importfill_job_line_fk_job (fk_job);
ALTER TABLE llx_importfill_job_line ADD INDEX idx_importfill_job_line_action (action);
ALTER TABLE llx_importfill_job_line ADD INDEX idx_importfill_job_line_key_value (key_value);
ALTER TABLE llx_importfill_job_line ADD CONSTRAINT fk_importfill_job_line_job FOREIGN KEY (fk_job) REFERENCES llx_importfill_job(rowid) ON DELETE CASCADE;
