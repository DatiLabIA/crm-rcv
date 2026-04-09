-- Copyright (C) 2025 DatiLab <info@datilab.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

CREATE TABLE llx_importfill_job (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,
    datec           datetime NOT NULL,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_author  integer NOT NULL,
    status          varchar(20) DEFAULT 'draft' NOT NULL,
    filename_original varchar(255),
    filepath        varchar(500),
    mapping_json    text,
    options_json    text,
    stats_json      text,
    import_mode     varchar(30) DEFAULT 'fill_empty' NOT NULL,
    delimiter_char  varchar(5) DEFAULT ',' NOT NULL,
    encoding        varchar(30) DEFAULT 'UTF-8' NOT NULL,
    has_header      smallint DEFAULT 1 NOT NULL,
    note_private    text,
    note_public     text
) ENGINE=innodb;
