-- Migration from v1.1.0 to v1.2.0
-- Adds recurrence/repeat support for consultations

-- Recurrence fields on main consultation table
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN recurrence_enabled    TINYINT(1)   NOT NULL DEFAULT 0 AFTER custom_data;
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN recurrence_interval   INT          NOT NULL DEFAULT 1 AFTER recurrence_enabled;
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN recurrence_unit       VARCHAR(10)  NOT NULL DEFAULT 'weeks' AFTER recurrence_interval;
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN recurrence_end_type   VARCHAR(10)  NOT NULL DEFAULT 'forever' AFTER recurrence_unit;
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN recurrence_end_date   DATE         NULL AFTER recurrence_end_type;
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN recurrence_parent_id  INT          NULL AFTER recurrence_end_date;

-- Index for quick lookup of child occurrences
ALTER TABLE llx_cabinetmed_extcons ADD INDEX idx_recurrence_parent (recurrence_parent_id);
