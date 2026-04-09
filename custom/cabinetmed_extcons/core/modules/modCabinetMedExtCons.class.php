<?php
/* Copyright (C) 2024 DatiLab
 * Module for Extended Consultations with Dynamic Custom Fields
 * 
 * v1.1.0 - Múltiples encargados y sistema de favoritos
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modCabinetMedExtCons extends DolibarrModules
{
    /**
     * Versión de la estructura de base de datos
     * Incrementar cada vez que se hagan cambios en la BD
     */
    const DB_VERSION = 130; // 1.3.0 = 130
    
    public function __construct($db)
    {
        global $langs, $conf;
        
        $this->db = $db;
        $this->config_page_url = array('setup.php@cabinetmed_extcons');
        
        $this->numero = 502123;
        $this->rights_class = 'cabinetmed_extcons';
        $this->family = "crm";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Formularios de consulta extendidos con campos personalizados dinámicos";
        $this->descriptionlong = "Este módulo extiende las consultas de CabinetMed con formularios personalizables por tipo de atención, soporte para múltiples encargados, sistema de favoritos y consultas recurrentes";
        $this->version = '1.4.3';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'generic';
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://www.datilab.com';
        
        $this->depends = array('modCabinetMed');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(21, 0);
        $this->langfiles = array("cabinetmed_extcons@cabinetmed_extcons");
        
        $this->const = array(
            0 => array(
                'CABINETMED_EXTCONS_ENABLE_NOTES',
                'chaine',
                '1',
                'Enable notes in consultations',
                0,
                'current',
                1
            ),
            1 => array(
                'CABINETMED_EXTCONS_DEFAULT_DURATION',
                'chaine',
                '30',
                'Default duration in minutes',
                0,
                'current',
                1
            )
        );
        
        $this->tabs = array(
            'thirdparty:-tabconsultations',
            'thirdparty:+tabconsultations:Atenciones:cabinetmed_extcons@cabinetmed_extcons:$user->rights->cabinetmed->read:/cabinetmed_extcons/consultations.php?socid=__ID__'
        );
        
        $this->dictionaries = array();
        $this->boxes = array();
        
        $this->rights = array();
        $r = 0;
        
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Read extended consultations';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Create/modify extended consultations';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Delete extended consultations';
        $this->rights[$r][2] = 'd';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'delete';
        
        $this->menu = array();
        
        $this->module_parts = array(
            'hooks' => array(
                'data' => array(
                    'thirdpartycard',
                    'consultationcard'
                ),
                'entity' => '0'
            )
        );
    }
    
    /**
     * Function called when module is enabled.
     * Creates all tables, runs migrations, and inserts initial data.
     */
    public function init($options = '')
    {
        global $conf;
        
        // 1. Cargar tablas base (CREATE TABLE IF NOT EXISTS)
        $result = $this->_load_tables('/cabinetmed_extcons/sql/');
        if ($result < 0) {
            dol_syslog("modCabinetMedExtCons::init - Error loading tables", LOG_ERR);
            return -1;
        }
        
        // 2. Ejecutar migraciones automáticas si es necesario
        $this->runMigrations();
        
        // 3. Insertar tipos de consulta por defecto si la tabla está vacía
        $this->insertDefaultTypes();
        
        // 4. Crear directorios necesarios
        $this->createDirectories();
        
        return $this->_init(array(), $options);
    }
    
    /**
     * Run database migrations if needed
     * Compares installed DB version with current module DB version
     */
    private function runMigrations()
    {
        global $conf;
        
        // Obtener versión de BD instalada
        $installed_version = $this->getInstalledDbVersion();
        $current_version = self::DB_VERSION;
        
        dol_syslog("modCabinetMedExtCons::runMigrations - Installed DB version: $installed_version, Current: $current_version", LOG_INFO);
        
        // Si la versión instalada es menor que la actual, ejecutar migraciones
        if ($installed_version < $current_version) {
            
            // Migración a v1.1.0 (DB_VERSION = 110)
            if ($installed_version < 110) {
                $this->migrateTo110();
            }
            
            // Migración a v1.3.0 (DB_VERSION = 130)
            if ($installed_version < 130) {
                $this->migrateTo130();
            }
            
            // Actualizar versión de BD instalada
            $this->setInstalledDbVersion($current_version);
            
            dol_syslog("modCabinetMedExtCons::runMigrations - Migration completed to version $current_version", LOG_INFO);
        }
    }
    
    /**
     * Get installed database version from constants table
     * 
     * @return int Installed DB version (0 if not found or new installation)
     */
    private function getInstalledDbVersion()
    {
        global $conf;
        
        $sql = "SELECT value FROM ".MAIN_DB_PREFIX."const";
        $sql .= " WHERE name = 'CABINETMED_EXTCONS_DB_VERSION'";
        $sql .= " AND entity IN (0, ".$conf->entity.")";
        $sql .= " ORDER BY entity DESC";
        $sql .= " LIMIT 1";
        
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return (int) $obj->value;
            }
        }
        
        // Si no existe la constante pero existen las tablas base, asumir v1.0.x
        if ($this->tableExists('cabinetmed_extcons')) {
            // Verificar si las nuevas tablas ya existen
            if ($this->tableExists('cabinetmed_extcons_users') && $this->tableExists('cabinetmed_extcons_favorites')) {
                return 110; // Ya está actualizado
            }
            return 100; // Versión 1.0.x
        }
        
        return 0; // Instalación nueva
    }
    
    /**
     * Set installed database version in constants table
     * 
     * @param int $version Version to set
     */
    private function setInstalledDbVersion($version)
    {
        global $conf;
        
        // Primero intentar actualizar
        $sql = "UPDATE ".MAIN_DB_PREFIX."const SET value = '".$this->db->escape($version)."'";
        $sql .= " WHERE name = 'CABINETMED_EXTCONS_DB_VERSION'";
        $sql .= " AND entity = ".$conf->entity;
        
        $resql = $this->db->query($sql);
        
        // Si no actualizó ningún registro, insertar
        if ($this->db->affected_rows($resql) == 0) {
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."const (name, value, type, entity, visible)";
            $sql .= " VALUES ('CABINETMED_EXTCONS_DB_VERSION', '".$this->db->escape($version)."', 'chaine', ".$conf->entity.", 0)";
            $this->db->query($sql);
        }
        
        dol_syslog("modCabinetMedExtCons::setInstalledDbVersion - Set to $version", LOG_INFO);
    }
    
    /**
     * Check if a table exists
     * 
     * @param string $tablename Table name without prefix
     * @return bool True if exists
     */
    private function tableExists($tablename)
    {
        $sql = "SHOW TABLES LIKE '".MAIN_DB_PREFIX.$tablename."'";
        $resql = $this->db->query($sql);
        if ($resql) {
            return ($this->db->num_rows($resql) > 0);
        }
        return false;
    }
    
    /**
     * Migration to version 1.1.0
     * - Creates users and favorites tables (if not exist via _load_tables)
     * - Migrates existing fk_user data to new users table
     */
    private function migrateTo110()
    {
        dol_syslog("modCabinetMedExtCons::migrateTo110 - Starting migration", LOG_INFO);
        
        $error = 0;
        
        // Las tablas ya deberían estar creadas por _load_tables()
        // Pero verificamos y creamos manualmente si es necesario
        
        // Crear tabla de usuarios asignados si no existe
        if (!$this->tableExists('cabinetmed_extcons_users')) {
            $sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."cabinetmed_extcons_users (
                rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
                fk_extcons      INTEGER NOT NULL,
                fk_user         INTEGER NOT NULL,
                role            VARCHAR(64) DEFAULT 'assigned',
                datec           DATETIME,
                fk_user_creat   INTEGER,
                UNIQUE KEY uk_extcons_user (fk_extcons, fk_user),
                INDEX idx_extcons_users_extcons (fk_extcons),
                INDEX idx_extcons_users_user (fk_user)
            ) ENGINE=innodb";
            
            if (!$this->db->query($sql)) {
                $error++;
                dol_syslog("modCabinetMedExtCons::migrateTo110 - Error creating users table: ".$this->db->lasterror(), LOG_ERR);
            }
        }
        
        // Crear tabla de favoritos si no existe
        if (!$this->tableExists('cabinetmed_extcons_favorites')) {
            $sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites (
                rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
                fk_extcons      INTEGER NOT NULL,
                fk_user         INTEGER NOT NULL,
                datec           DATETIME,
                UNIQUE KEY uk_extcons_favorite (fk_extcons, fk_user),
                INDEX idx_favorites_extcons (fk_extcons),
                INDEX idx_favorites_user (fk_user)
            ) ENGINE=innodb";
            
            if (!$this->db->query($sql)) {
                $error++;
                dol_syslog("modCabinetMedExtCons::migrateTo110 - Error creating favorites table: ".$this->db->lasterror(), LOG_ERR);
            }
        }
        
        // Migrar datos existentes: copiar fk_user a la tabla de usuarios asignados
        if (!$error) {
            $sql = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_users (fk_extcons, fk_user, role, datec, fk_user_creat)
                    SELECT rowid, fk_user, 'assigned', NOW(), fk_user_creat
                    FROM ".MAIN_DB_PREFIX."cabinetmed_extcons
                    WHERE fk_user IS NOT NULL AND fk_user > 0";
            
            $resql = $this->db->query($sql);
            if ($resql) {
                $migrated = $this->db->affected_rows($resql);
                dol_syslog("modCabinetMedExtCons::migrateTo110 - Migrated $migrated user assignments", LOG_INFO);
            } else {
                // No es crítico si falla (puede que ya existan los registros)
                dol_syslog("modCabinetMedExtCons::migrateTo110 - Note: ".$this->db->lasterror(), LOG_WARNING);
            }
        }
        
        // Intentar agregar foreign keys (ignorar errores si ya existen)
        $this->addForeignKeysIfNotExist();
        
        if ($error) {
            dol_syslog("modCabinetMedExtCons::migrateTo110 - Migration completed with errors", LOG_WARNING);
        } else {
            dol_syslog("modCabinetMedExtCons::migrateTo110 - Migration completed successfully", LOG_INFO);
        }
        
        return $error ? -1 : 1;
    }

    private function migrateTo130()
    {
        dol_syslog("modCabinetMedExtCons::migrateTo130 - Starting migration", LOG_INFO);
        
        $error = 0;
        
        // Ampliar custom_data a MEDIUMTEXT para soportar imágenes base64
        $sql = "ALTER TABLE ".MAIN_DB_PREFIX."cabinetmed_extcons MODIFY COLUMN custom_data MEDIUMTEXT";
        if (!$this->db->query($sql)) {
            $error++;
            dol_syslog("modCabinetMedExtCons::migrateTo130 - Error altering custom_data: ".$this->db->lasterror(), LOG_ERR);
        } else {
            dol_syslog("modCabinetMedExtCons::migrateTo130 - custom_data expanded to MEDIUMTEXT", LOG_INFO);
        }
        
        if ($error) {
            dol_syslog("modCabinetMedExtCons::migrateTo130 - Migration completed with errors", LOG_WARNING);
        } else {
            dol_syslog("modCabinetMedExtCons::migrateTo130 - Migration completed successfully", LOG_INFO);
        }
        
        return $error ? -1 : 1;
    }
    
    /**
     * Add foreign keys if they don't exist
     * Silently ignores errors (keys may already exist)
     */
    private function addForeignKeysIfNotExist()
    {
        // Foreign keys para tabla de usuarios
        $fks = array(
            array(
                'table' => 'cabinetmed_extcons_users',
                'name' => 'fk_extcons_users_extcons',
                'sql' => "ALTER TABLE ".MAIN_DB_PREFIX."cabinetmed_extcons_users 
                          ADD CONSTRAINT fk_extcons_users_extcons 
                          FOREIGN KEY (fk_extcons) REFERENCES ".MAIN_DB_PREFIX."cabinetmed_extcons(rowid) ON DELETE CASCADE"
            ),
            array(
                'table' => 'cabinetmed_extcons_users',
                'name' => 'fk_extcons_users_user',
                'sql' => "ALTER TABLE ".MAIN_DB_PREFIX."cabinetmed_extcons_users 
                          ADD CONSTRAINT fk_extcons_users_user 
                          FOREIGN KEY (fk_user) REFERENCES ".MAIN_DB_PREFIX."user(rowid) ON DELETE CASCADE"
            ),
            array(
                'table' => 'cabinetmed_extcons_favorites',
                'name' => 'fk_favorites_extcons',
                'sql' => "ALTER TABLE ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites 
                          ADD CONSTRAINT fk_favorites_extcons 
                          FOREIGN KEY (fk_extcons) REFERENCES ".MAIN_DB_PREFIX."cabinetmed_extcons(rowid) ON DELETE CASCADE"
            ),
            array(
                'table' => 'cabinetmed_extcons_favorites',
                'name' => 'fk_favorites_user',
                'sql' => "ALTER TABLE ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites 
                          ADD CONSTRAINT fk_favorites_user 
                          FOREIGN KEY (fk_user) REFERENCES ".MAIN_DB_PREFIX."user(rowid) ON DELETE CASCADE"
            )
        );
        
        foreach ($fks as $fk) {
            // Verificar si la FK ya existe
            if (!$this->foreignKeyExists($fk['table'], $fk['name'])) {
                $this->db->query($fk['sql']);
                // Ignoramos errores silenciosamente
            }
        }
    }
    
    /**
     * Check if a foreign key exists
     * 
     * @param string $tablename Table name without prefix
     * @param string $fkname Foreign key name
     * @return bool True if exists
     */
    private function foreignKeyExists($tablename, $fkname)
    {
        $sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = '".MAIN_DB_PREFIX.$tablename."' 
                AND CONSTRAINT_NAME = '".$this->db->escape($fkname)."'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
        
        $resql = $this->db->query($sql);
        if ($resql) {
            return ($this->db->num_rows($resql) > 0);
        }
        return false;
    }
    
    /**
     * Insert default consultation types if none exist
     */
    private function insertDefaultTypes()
    {
        global $conf;
        
        // Check if types table has data
        $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types WHERE entity = ".$conf->entity;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj->nb == 0) {
                // Insert default types
                $defaultTypes = array(
                    array('code' => 'adherencia', 'label' => 'Adherencia/Dispensación', 'position' => 10),
                    array('code' => 'control', 'label' => 'Control Médico', 'position' => 20),
                    array('code' => 'enfermeria', 'label' => 'Enfermería', 'position' => 30),
                    array('code' => 'farmacia', 'label' => 'Farmacia', 'position' => 40),
                    array('code' => 'general', 'label' => 'Consulta General', 'position' => 50),
                    array('code' => 'psicologia', 'label' => 'Psicología', 'position' => 60),
                    array('code' => 'nutricion', 'label' => 'Nutrición', 'position' => 70),
                    array('code' => 'trabajo_social', 'label' => 'Trabajo Social', 'position' => 80)
                );
                
                foreach ($defaultTypes as $type) {
                    $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
                    $sql .= " (entity, code, label, active, position, datec)";
                    $sql .= " VALUES (".$conf->entity;
                    $sql .= ", '".$this->db->escape($type['code'])."'";
                    $sql .= ", '".$this->db->escape($type['label'])."'";
                    $sql .= ", 1";
                    $sql .= ", ".$type['position'];
                    $sql .= ", '".$this->db->idate(dol_now())."')";
                    
                    $this->db->query($sql);
                }
                
                dol_syslog("modCabinetMedExtCons::init - Inserted default consultation types", LOG_INFO);
            }
        }
    }
    
    /**
     * Create necessary directories for the module
     */
    private function createDirectories()
    {
        global $conf;
        
        $dirs = array(
            $conf->cabinetmed_extcons->dir_output ?? DOL_DATA_ROOT.'/cabinetmed_extcons',
            ($conf->cabinetmed_extcons->dir_output ?? DOL_DATA_ROOT.'/cabinetmed_extcons').'/temp'
        );
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Function called when module is disabled.
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
