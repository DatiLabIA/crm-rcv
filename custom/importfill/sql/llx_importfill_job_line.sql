-- Copyright (C) 2025 DatiLab <info@datilab.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

CREATE TABLE llx_importfill_job_line (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_job          integer NOT NULL,
    line_num        integer NOT NULL,
    key_value       varchar(100),
    action          varchar(20) NOT NULL,
    fk_societe      integer DEFAULT NULL,
    message         varchar(500),
    payload_json    text,
    datec           datetime NOT NULL
) ENGINE=innodb;
