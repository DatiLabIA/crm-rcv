-- Update para agregar campos condicionales
-- Versión: 1.2.0
-- Fecha: 2025-02-06

-- Agregar columna para condiciones de visibilidad en campos personalizados
ALTER TABLE llx_cabinetmed_extcons_fields
ADD COLUMN conditional_field VARCHAR(64) NULL COMMENT 'Campo del cual depende la visibilidad' AFTER field_options,
ADD COLUMN conditional_value TEXT NULL COMMENT 'Valor(es) que activan la visibilidad (JSON para múltiples valores)' AFTER conditional_field;

-- Comentarios
ALTER TABLE llx_cabinetmed_extcons_fields MODIFY COLUMN conditional_field VARCHAR(64) NULL COMMENT 'Nombre del campo del cual depende la visibilidad';
ALTER TABLE llx_cabinetmed_extcons_fields MODIFY COLUMN conditional_value TEXT NULL COMMENT 'Valor o valores (JSON) que activan la visibilidad del campo';
