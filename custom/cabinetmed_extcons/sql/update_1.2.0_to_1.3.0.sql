-- Migration from v1.2.0 to v1.3.0
-- Adds dedicated observaciones column (MEDIUMTEXT for image support)
-- Changes custom_data to MEDIUMTEXT to prevent JSON truncation with base64 images
-- NOTE: This runs automatically via module activation (migrateTo130). Manual execution optional.

-- 1. Add observaciones column (dedicated, always visible for all consultation types)
ALTER TABLE llx_cabinetmed_extcons ADD COLUMN observaciones MEDIUMTEXT AFTER medicamentos;

-- 2. Increase custom_data from TEXT (64KB) to MEDIUMTEXT (16MB) to prevent JSON corruption
ALTER TABLE llx_cabinetmed_extcons MODIFY COLUMN custom_data MEDIUMTEXT;

-- 3. Migrate existing observaciones data from custom_data JSON to the new column
-- Tries multiple possible field names (observaciones, observaciones_generales, observacion)
UPDATE llx_cabinetmed_extcons 
SET observaciones = JSON_UNQUOTE(JSON_EXTRACT(custom_data, '$.observaciones')),
    custom_data = JSON_REMOVE(custom_data, '$.observaciones')
WHERE custom_data IS NOT NULL 
  AND custom_data != ''
  AND JSON_VALID(custom_data)
  AND JSON_EXTRACT(custom_data, '$.observaciones') IS NOT NULL;

UPDATE llx_cabinetmed_extcons 
SET observaciones = JSON_UNQUOTE(JSON_EXTRACT(custom_data, '$.observaciones_generales')),
    custom_data = JSON_REMOVE(custom_data, '$.observaciones_generales')
WHERE custom_data IS NOT NULL 
  AND custom_data != ''
  AND JSON_VALID(custom_data)
  AND JSON_EXTRACT(custom_data, '$.observaciones_generales') IS NOT NULL
  AND (observaciones IS NULL OR observaciones = '');

UPDATE llx_cabinetmed_extcons 
SET observaciones = JSON_UNQUOTE(JSON_EXTRACT(custom_data, '$.observacion')),
    custom_data = JSON_REMOVE(custom_data, '$.observacion')
WHERE custom_data IS NOT NULL 
  AND custom_data != ''
  AND JSON_VALID(custom_data)
  AND JSON_EXTRACT(custom_data, '$.observacion') IS NOT NULL
  AND (observaciones IS NULL OR observaciones = '');

-- NOTE: Field names like 'observacion_paciente_nuevo', 'observacion_control', etc.
-- are handled by the PHP migration (migrateTo132) which searches ALL JSON keys
-- containing "observ" dynamically. Run module deactivate/reactivate to execute.
