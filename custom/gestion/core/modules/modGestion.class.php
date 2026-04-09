<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modGestion extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 500006;
        $this->rights_class = 'gestion';
        $this->family = "other";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Módulo de Gestión para PSP (EPS, Médicos, Programas, etc.)";
        $this->descriptionlong = "Gestión de entidades para Programas de Soporte a Pacientes.";
        $this->version = '1.1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'key';
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://datilab.com';

        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array(),
        );

        $this->dirs = array('/gestion/temp');
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(14, 0);
        $this->langfiles = array("gestion@gestion");

        $this->const = array();

        // Permisos
        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + 1;
        $this->rights[$r][1] = 'Leer registros de Gestión';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $this->rights[$r][5] = '';
        $r++;

        $this->rights[$r][0] = $this->numero + 2;
        $this->rights[$r][1] = 'Crear/Modificar registros de Gestión';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $this->rights[$r][5] = '';
        $r++;

        $this->rights[$r][0] = $this->numero + 3;
        $this->rights[$r][1] = 'Eliminar registros de Gestión';
        $this->rights[$r][2] = 'd';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'delete';
        $this->rights[$r][5] = '';
        $r++;

        // Menús
        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu'  => '',
            'type'     => 'top',
            'titre'    => 'Gestión',
            'prefix'   => img_picto('', 'fontawesome_briefcase-medical_fas', 'class="pictofixedwidth"'),
            'mainmenu' => 'gestion',
            'leftmenu' => '',
            'url'      => '/gestion/index.php',
            'langs'    => 'gestion@gestion',
            'position' => 100,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=gestion',
            'type'     => 'left',
            'titre'    => 'Programas',
            'mainmenu' => 'gestion',
            'leftmenu' => 'gestion_programas',
            'url'      => '/gestion/programas/list.php',
            'langs'    => 'gestion@gestion',
            'position' => 100,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=gestion',
            'type'     => 'left',
            'titre'    => 'Diagnósticos',
            'mainmenu' => 'gestion',
            'leftmenu' => 'gestion_diagnosticos',
            'url'      => '/gestion/diagnosticos/list.php',
            'langs'    => 'gestion@gestion',
            'position' => 200,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=gestion',
            'type'     => 'left',
            'titre'    => 'EPS',
            'mainmenu' => 'gestion',
            'leftmenu' => 'gestion_eps',
            'url'      => '/gestion/eps/list.php',
            'langs'    => 'gestion@gestion',
            'position' => 300,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=gestion',
            'type'     => 'left',
            'titre'    => 'Medicamentos',
            'mainmenu' => 'gestion',
            'leftmenu' => 'gestion_medicamentos',
            'url'      => '/gestion/medicamentos/list.php',
            'langs'    => 'gestion@gestion',
            'position' => 400,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=gestion',
            'type'     => 'left',
            'titre'    => 'Médicos',
            'mainmenu' => 'gestion',
            'leftmenu' => 'gestion_medicos',
            'url'      => '/gestion/medicos/list.php',
            'langs'    => 'gestion@gestion',
            'position' => 500,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=gestion',
            'type'     => 'left',
            'titre'    => 'Operadores',
            'mainmenu' => 'gestion',
            'leftmenu' => 'gestion_operadores',
            'url'      => '/gestion/operadores/list.php',
            'langs'    => 'gestion@gestion',
            'position' => 600,
            'enabled'  => '$conf->gestion->enabled',
            'perms'    => '$user->hasRight("gestion", "read")',
            'target'   => '',
            'user'     => 2,
        );
    }

    // ═══════════════════════════════════════════════════════
    //  HELPERS: Verificación de estructura de BD
    // ═══════════════════════════════════════════════════════

    /**
     * Verifica si una tabla existe en la base de datos
     *
     * @param  string $tableName Nombre SIN prefijo (ej: 'gestion_programa')
     * @return bool
     */
    private function _tableExists($tableName)
    {
        $fullName = MAIN_DB_PREFIX . $tableName;
        $sql = "SHOW TABLES LIKE '" . $this->db->escape($fullName) . "'";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $this->db->free($resql);
            return true;
        }
        return false;
    }

    /**
     * Verifica si una columna existe en una tabla
     *
     * @param  string $tableName  Nombre SIN prefijo
     * @param  string $columnName Nombre de la columna
     * @return bool
     */
    private function _columnExists($tableName, $columnName)
    {
        $fullName = MAIN_DB_PREFIX . $tableName;
        $sql = "SHOW COLUMNS FROM `" . $this->db->escape($fullName) . "` LIKE '" . $this->db->escape($columnName) . "'";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $this->db->free($resql);
            return true;
        }
        return false;
    }

    /**
     * Verifica si un índice existe en una tabla
     *
     * @param  string $tableName Nombre SIN prefijo
     * @param  string $indexName Nombre del índice
     * @return bool
     */
    private function _indexExists($tableName, $indexName)
    {
        $fullName = MAIN_DB_PREFIX . $tableName;
        $sql = "SHOW INDEX FROM `" . $this->db->escape($fullName) . "` WHERE Key_name = '" . $this->db->escape($indexName) . "'";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $this->db->free($resql);
            return true;
        }
        return false;
    }

    /**
     * Verifica si un foreign key existe
     *
     * @param  string $tableName      Nombre SIN prefijo
     * @param  string $constraintName Nombre del constraint
     * @return bool
     */
    private function _foreignKeyExists($tableName, $constraintName)
    {
        $fullName = MAIN_DB_PREFIX . $tableName;
        $sql  = "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS";
        $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
        $sql .= " AND TABLE_NAME = '" . $this->db->escape($fullName) . "'";
        $sql .= " AND CONSTRAINT_NAME = '" . $this->db->escape($constraintName) . "'";
        $sql .= " AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $this->db->free($resql);
            return true;
        }
        return false;
    }

    /**
     * Ejecuta SQL con logging
     *
     * @param  string $sql         Consulta SQL
     * @param  string $description Descripción para el log
     * @return bool
     */
    private function _execSQL($sql, $description = '')
    {
        dol_syslog(get_class($this) . "::init " . ($description ?: 'SQL'), LOG_DEBUG);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            dol_syslog(get_class($this) . "::init ERROR " . $description . ": " . $this->error, LOG_ERR);
            return false;
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════
    //  Paso 1: Crear tablas que no existan
    // ═══════════════════════════════════════════════════════

    /**
     * Crea las tablas base del módulo si no existen
     *
     * @return int 1=OK, -1=error
     */
    private function _createTables()
    {
        $error = 0;
        $p = MAIN_DB_PREFIX;

        // ── llx_gestion_programa ──
        if (!$this->_tableExists('gestion_programa')) {
            $sql = "CREATE TABLE {$p}gestion_programa (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                datec DATETIME,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                entity INTEGER DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_programa')) $error++;
        }

        // ── llx_gestion_diagnostico ──
        if (!$this->_tableExists('gestion_diagnostico')) {
            $sql = "CREATE TABLE {$p}gestion_diagnostico (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(50) NOT NULL,
                label VARCHAR(255) NOT NULL,
                description TEXT,
                datec DATETIME,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                entity INTEGER DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_diagnostico')) $error++;
        }

        // ── llx_gestion_eps ──
        if (!$this->_tableExists('gestion_eps')) {
            $sql = "CREATE TABLE {$p}gestion_eps (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(50),
                descripcion VARCHAR(255),
                datec DATETIME,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                entity INTEGER DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_eps')) $error++;
        }

        // ── llx_gestion_medicamento ──
        if (!$this->_tableExists('gestion_medicamento')) {
            $sql = "CREATE TABLE {$p}gestion_medicamento (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                ref VARCHAR(128) NOT NULL,
                etiqueta VARCHAR(255),
                estado TINYINT DEFAULT 1,
                datec DATETIME,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                entity INTEGER DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_medicamento')) $error++;
        }

        // ── llx_gestion_medicamento_det ──
        if (!$this->_tableExists('gestion_medicamento_det')) {
            $sql = "CREATE TABLE {$p}gestion_medicamento_det (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                fk_medicamento INTEGER NOT NULL,
                concentracion VARCHAR(100),
                unidad VARCHAR(50),
                concentracion_display VARCHAR(155) GENERATED ALWAYS AS (
                    CASE
                        WHEN concentracion IS NOT NULL AND unidad IS NOT NULL AND concentracion != '' AND unidad != ''
                            THEN CONCAT(concentracion, ' ', unidad)
                        WHEN concentracion IS NOT NULL AND concentracion != ''
                            THEN concentracion
                        ELSE NULL
                    END
                ) STORED,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_fk_med (fk_medicamento),
                CONSTRAINT fk_gestion_med_det FOREIGN KEY (fk_medicamento)
                    REFERENCES {$p}gestion_medicamento(rowid) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_medicamento_det')) $error++;
        }

        // ── llx_gestion_medico ──
        if (!$this->_tableExists('gestion_medico')) {
            $sql = "CREATE TABLE {$p}gestion_medico (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                ref VARCHAR(50),
                nombre VARCHAR(255) NOT NULL,
                tipo_doc VARCHAR(10),
                numero_identificacion VARCHAR(50),
                tarjeta_profesional VARCHAR(50),
                ciudades TEXT COMMENT 'JSON array de ciudades',
                departamentos TEXT COMMENT 'JSON array de departamentos',
                especialidades TEXT COMMENT 'JSON array de especialidades',
                datec DATETIME,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                entity INTEGER DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_medico')) $error++;
        }

        // ── llx_gestion_medico_eps (pivote) ──
        if (!$this->_tableExists('gestion_medico_eps')) {
            $sql = "CREATE TABLE {$p}gestion_medico_eps (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                fk_medico INTEGER NOT NULL,
                fk_eps INTEGER NOT NULL,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_medico_eps_medico (fk_medico),
                INDEX idx_medico_eps_eps (fk_eps),
                UNIQUE KEY uk_medico_eps (fk_medico, fk_eps),
                CONSTRAINT fk_gestion_me_medico FOREIGN KEY (fk_medico)
                    REFERENCES {$p}gestion_medico(rowid) ON DELETE CASCADE,
                CONSTRAINT fk_gestion_me_eps FOREIGN KEY (fk_eps)
                    REFERENCES {$p}gestion_eps(rowid) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_medico_eps')) $error++;
        }

        // ── llx_gestion_operador ──
        if (!$this->_tableExists('gestion_operador')) {
            $sql = "CREATE TABLE {$p}gestion_operador (
                rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                datec DATETIME,
                tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                entity INTEGER DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!$this->_execSQL($sql, 'Create gestion_operador')) $error++;
        }

        return $error ? -1 : 1;
    }

    // ═══════════════════════════════════════════════════════
    //  Paso 2: Migraciones condicionales
    // ═══════════════════════════════════════════════════════

    /**
     * Aplica migraciones solo cuando detecta estructura antigua.
     * Seguro para ejecutar en cualquier estado de la BD.
     *
     * @return int 1=OK, -1=error
     */
    private function _applyMigrations()
    {
        $error = 0;
        $p = MAIN_DB_PREFIX;

        // ════════════════════════════════════════════
        // MIGRACIÓN: llx_gestion_medico
        // Estructura antigua: ciudad, departamento, especialidad (singulares), fk_eps
        // Estructura nueva:   ciudades, departamentos, especialidades (JSON), tabla pivote
        // ════════════════════════════════════════════

        if ($this->_tableExists('gestion_medico')) {

            // ── fk_eps → tabla pivote ──
            if ($this->_columnExists('gestion_medico', 'fk_eps')) {
                dol_syslog(get_class($this) . "::init Migrando fk_eps a tabla pivote", LOG_INFO);

                if ($this->_tableExists('gestion_medico_eps')) {
                    $this->_execSQL(
                        "INSERT IGNORE INTO {$p}gestion_medico_eps (fk_medico, fk_eps)
                         SELECT rowid, fk_eps FROM {$p}gestion_medico
                         WHERE fk_eps IS NOT NULL AND fk_eps > 0",
                        'Migrate fk_eps data to pivot'
                    );
                }
                if (!$this->_execSQL("ALTER TABLE {$p}gestion_medico DROP COLUMN fk_eps", 'Drop fk_eps')) $error++;
            }

            // ── ciudad → ciudades (JSON) ──
            $this->_migrateColumnToJson('gestion_medico', 'ciudad', 'ciudades', 'JSON array de ciudades', $error);

            // ── departamento → departamentos (JSON) ──
            $this->_migrateColumnToJson('gestion_medico', 'departamento', 'departamentos', 'JSON array de departamentos', $error);

            // ── especialidad → especialidades (JSON) ──
            $this->_migrateColumnToJson('gestion_medico', 'especialidad', 'especialidades', 'JSON array de especialidades', $error);
        }

        // ════════════════════════════════════════════
        // MIGRACIÓN: llx_gestion_medicamento_det
        // Asegurar columna calculada y FK
        // ════════════════════════════════════════════

        if ($this->_tableExists('gestion_medicamento_det')) {
            if (!$this->_columnExists('gestion_medicamento_det', 'concentracion_display')) {
                $sql = "ALTER TABLE {$p}gestion_medicamento_det
                    ADD COLUMN concentracion_display VARCHAR(155) GENERATED ALWAYS AS (
                        CASE
                            WHEN concentracion IS NOT NULL AND unidad IS NOT NULL AND concentracion != '' AND unidad != ''
                                THEN CONCAT(concentracion, ' ', unidad)
                            WHEN concentracion IS NOT NULL AND concentracion != ''
                                THEN concentracion
                            ELSE NULL
                        END
                    ) STORED";
                if (!$this->_execSQL($sql, 'Add concentracion_display')) $error++;
            }

            if (!$this->_foreignKeyExists('gestion_medicamento_det', 'fk_gestion_med_det')) {
                $addIdx = !$this->_indexExists('gestion_medicamento_det', 'idx_fk_med')
                    ? "ADD INDEX idx_fk_med (fk_medicamento)," : "";
                $sql = "ALTER TABLE {$p}gestion_medicamento_det
                        {$addIdx}
                        ADD CONSTRAINT fk_gestion_med_det FOREIGN KEY (fk_medicamento)
                        REFERENCES {$p}gestion_medicamento(rowid) ON DELETE CASCADE";
                $this->_execSQL($sql, 'Add FK on medicamento_det');
            }
        }

        // ════════════════════════════════════════════
        // Asegurar FKs en tabla pivote
        // ════════════════════════════════════════════

        if ($this->_tableExists('gestion_medico_eps')) {
            if (!$this->_foreignKeyExists('gestion_medico_eps', 'fk_gestion_me_medico')) {
                $this->_execSQL(
                    "ALTER TABLE {$p}gestion_medico_eps
                     ADD CONSTRAINT fk_gestion_me_medico FOREIGN KEY (fk_medico)
                     REFERENCES {$p}gestion_medico(rowid) ON DELETE CASCADE",
                    'Add FK medico on pivot'
                );
            }
            if (!$this->_foreignKeyExists('gestion_medico_eps', 'fk_gestion_me_eps')) {
                $this->_execSQL(
                    "ALTER TABLE {$p}gestion_medico_eps
                     ADD CONSTRAINT fk_gestion_me_eps FOREIGN KEY (fk_eps)
                     REFERENCES {$p}gestion_eps(rowid) ON DELETE CASCADE",
                    'Add FK eps on pivot'
                );
            }
        }

        return $error ? -1 : 1;
    }

    /**
     * Helper: Migra una columna singular a su versión plural JSON.
     * Si la columna antigua existe: crea la nueva, migra datos, elimina la antigua.
     * Si ninguna existe: crea la nueva.
     * Si la nueva ya existe: no hace nada.
     *
     * @param string $table     Nombre tabla SIN prefijo
     * @param string $oldCol    Nombre columna antigua (singular)
     * @param string $newCol    Nombre columna nueva (plural, JSON)
     * @param string $comment   Comentario SQL
     * @param int    &$error    Contador de errores por referencia
     */
    private function _migrateColumnToJson($table, $oldCol, $newCol, $comment, &$error)
    {
        $p = MAIN_DB_PREFIX;

        if ($this->_columnExists($table, $oldCol)) {
            // Columna antigua existe → migrar
            if (!$this->_columnExists($table, $newCol)) {
                if (!$this->_execSQL(
                    "ALTER TABLE {$p}{$table} ADD COLUMN {$newCol} TEXT COMMENT '{$comment}'",
                    "Add column {$newCol}"
                )) { $error++; return; }
            }

            $this->_execSQL(
                "UPDATE {$p}{$table} SET {$newCol} = CONCAT('[\"', {$oldCol}, '\"]')
                 WHERE {$oldCol} IS NOT NULL AND {$oldCol} != ''
                 AND ({$newCol} IS NULL OR {$newCol} = '')",
                "Migrate {$oldCol} -> {$newCol} data"
            );

            if (!$this->_execSQL(
                "ALTER TABLE {$p}{$table} DROP COLUMN {$oldCol}",
                "Drop old column {$oldCol}"
            )) $error++;

        } elseif (!$this->_columnExists($table, $newCol)) {
            // Ninguna existe → crear la nueva
            if (!$this->_execSQL(
                "ALTER TABLE {$p}{$table} ADD COLUMN {$newCol} TEXT COMMENT '{$comment}'",
                "Add missing column {$newCol}"
            )) $error++;
        }
        // Si solo $newCol existe → no hacer nada (ya está bien)
    }

    // ═══════════════════════════════════════════════════════
    //  INIT / REMOVE
    // ═══════════════════════════════════════════════════════

    /**
     * Inicialización del módulo.
     * Crea tablas si no existen y aplica migraciones condicionales.
     *
     * @param  string $options Opciones de Dolibarr
     * @return int    1=OK, <0=error
     */
    public function init($options = '')
    {
        $this->db->begin();

        // Paso 1: Crear tablas que falten
        $result = $this->_createTables();
        if ($result < 0) {
            dol_syslog(get_class($this) . "::init Error creating tables", LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        // Paso 2: Migraciones condicionales (estructura antigua → nueva)
        $result = $this->_applyMigrations();
        if ($result < 0) {
            dol_syslog(get_class($this) . "::init Error applying migrations", LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();

        // Paso 3: Registrar permisos, menús, constantes, etc.
        return $this->_init(array(), $options);
    }

    /**
     * Desinstalación del módulo.
     * NO elimina tablas para preservar datos.
     *
     * @param  string $options Opciones
     * @return int
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
