-- Copyright (C) 2026 CRM-RCV
--
-- SQL to add custom input reasons for medication dispensation
-- These are added to the Dolibarr dictionary table llx_c_input_reason
--

-- Use high rowid values to avoid conflicts with Dolibarr core data
INSERT INTO llx_c_input_reason (rowid, code, label, active) VALUES (100, 'SRC_DONATION', 'Donación', 1);
INSERT INTO llx_c_input_reason (rowid, code, label, active) VALUES (101, 'SRC_COLLECTION', 'Recolección', 1);
