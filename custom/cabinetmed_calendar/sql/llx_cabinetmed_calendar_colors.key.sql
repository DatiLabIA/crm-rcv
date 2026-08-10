-- Índices para llx_cabinetmed_calendar_colors
-- Usamos IF NOT EXISTS para que sean seguros en reinstalaciones

ALTER TABLE llx_cabinetmed_calendar_colors
    ADD UNIQUE IF NOT EXISTS uk_calendar_color (fk_extcons, fk_user);

ALTER TABLE llx_cabinetmed_calendar_colors
    ADD INDEX IF NOT EXISTS idx_calendar_color_extcons (fk_extcons);

ALTER TABLE llx_cabinetmed_calendar_colors
    ADD INDEX IF NOT EXISTS idx_calendar_color_user (fk_user);
