<?php
/* Copyright (C) 2024 DatiLab
 * Module RCV Analytics - Advanced patient & consultation reporting
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor for RCV Analytics
 */
class modRcvAnalytics extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // 502300 (antes 502200). El 502200 lo usaba también custom/rcvrest, y como los
        // ids de permisos se derivan de $numero, sus filas 502201..502204 bloqueaban la
        // inserción de los permisos de este módulo en llx_rights_def.
        $this->numero = 502300;
        // OJO: rights_class debe coincidir con la clave de $conf->modules ('rcvanalytics'),
        // porque User::hasRight() hace un isModEnabled($module) previo. Con guion bajo
        // ('rcv_analytics') isModEnabled() siempre devuelve false y ningún permiso funciona.
        $this->rights_class = 'rcvanalytics';
        $this->family = "crm";
        $this->module_position = '95';

        // name → strtolower del segmento tras MAIN_MODULE_ → clave en $conf->modules
        // 'RcvAnalytics' → const MAIN_MODULE_RCVANALYTICS → $conf->modules['rcvanalytics']
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        $this->description = "Analíticas avanzadas de pacientes y consultas";
        $this->descriptionlong = "Módulo de reportes e inteligencia de negocios sobre pacientes, consultas extendidas, adherencias, medicamentos, EPS, operadores logísticos y más.";
        $this->version = '1.1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name); // MAIN_MODULE_RCVANALYTICS
        $this->picto = 'stats';
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://www.datilab.com';

        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(17, 0);
        $this->langfiles = array("rcv_analytics@rcv_analytics");

        $this->const = array();
        $this->dictionaries = array();
        $this->boxes = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Ver reportes de analíticas';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Exportar reportes de analíticas (datos agregados)';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'export';

        // Permiso separado a propósito: el listado de pacientes incluye nombre,
        // documento, email y teléfono junto al diagnóstico. Tener 'export' NO
        // basta para descargarlo.
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Exportar datos identificados de pacientes (nombre, documento, contacto)';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'exportpii';

        // Menus
        // isModEnabled($x) → $conf->modules[strtolower($x)]
        // $this->name = 'RcvAnalytics' → MAIN_MODULE_RCVANALYTICS → $conf->modules['rcvanalytics']
        // Por tanto: isModEnabled("rcvanalytics")  ← todo minúsculas, sin guion bajo
        $this->menu = array();
        $r = 0;

        $this->menu[$r] = array(
            'fk_menu'  => 0,
            'type'     => 'top',
            'titre'    => 'Analíticas RCV',
            'mainmenu' => 'rcv_analytics',
            'url'      => '/custom/rcv_analytics/index.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 85,
            'enabled'  => 'isModEnabled("rcvanalytics")',
            'perms'    => '$user->hasRight("rcvanalytics", "read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Dashboard',
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_dashboard',
            'url'      => '/custom/rcv_analytics/index.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 10,
            'enabled'  => 'isModEnabled("rcvanalytics")',
            'perms'    => '$user->hasRight("rcvanalytics", "read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Pacientes',
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_patients',
            'url'      => '/custom/rcv_analytics/patients.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 20,
            'enabled'  => 'isModEnabled("rcvanalytics")',
            'perms'    => '$user->hasRight("rcvanalytics", "read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Consultas',
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_consultations',
            'url'      => '/custom/rcv_analytics/consultations.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 30,
            'enabled'  => 'isModEnabled("rcvanalytics")',
            'perms'    => '$user->hasRight("rcvanalytics", "read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Exportar datos',
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_export',
            'url'      => '/custom/rcv_analytics/export.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 50,
            'enabled'  => 'isModEnabled("rcvanalytics")',
            'perms'    => '$user->hasRight("rcvanalytics", "export")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Roles y permisos',
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_roles',
            'url'      => '/custom/rcv_analytics/admin/roles.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 60,
            'enabled'  => 'isModEnabled("rcvanalytics")',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        );
    }

    /**
     * Borra los registros dejados por el rights_class antiguo ('rcv_analytics').
     *
     * Hace falta porque delete_menus() y delete_permissions() borran por el
     * rights_class ACTUAL, así que al renombrarlo las filas viejas quedan huérfanas.
     * Las de llx_menu además rompen la activación: Menubase::create() detecta el
     * duplicado por menu_handler+fk_menu+position+url (sin mirar 'module') e
     * insert_menus() aborta con "Menu entry (all,85,...) already exists".
     *
     * Se restringe a menu_handler='all' (lo que inserta la activación); los menús
     * creados a mano desde Inicio > Configuración > Menús llevan el handler del
     * gestor activo y no se tocan.
     *
     * @return void
     */
    private function cleanupLegacyRegistration()
    {
        global $conf;

        $entity = (int) $conf->entity;
        $legacy = 'rcv_analytics'; // rights_class anterior

        // Asignaciones a usuarios y grupos de los permisos antiguos
        $ids = array();
        $resql = $this->db->query("SELECT id FROM ".MAIN_DB_PREFIX."rights_def"
            ." WHERE module = '".$this->db->escape($legacy)."' AND entity IN (0, ".$entity.")");
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $ids[] = (int) $obj->id;
            }
        }
        if (count($ids)) {
            $in = implode(',', $ids);
            $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."usergroup_rights WHERE fk_id IN (".$in.") AND entity IN (0, ".$entity.")");
            $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."user_rights WHERE fk_id IN (".$in.") AND entity IN (0, ".$entity.")");
            $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."rights_def WHERE module = '".$this->db->escape($legacy)."' AND entity IN (0, ".$entity.")");
        }

        // Entradas de menú generadas por activaciones anteriores (nombre viejo o nuevo)
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."menu";
        $sql .= " WHERE menu_handler = 'all'";
        $sql .= " AND entity IN (0, ".$entity.")";
        $sql .= " AND (module IN ('".$this->db->escape($legacy)."', '".$this->db->escape($this->rights_class)."')";
        $sql .= " OR url LIKE '%/custom/rcv_analytics/%')";
        $this->db->query($sql);
    }

    public function init($options = '')
    {
        // Se ejecuta fuera de la transacción de _init() a propósito: si _init()
        // fallara y revirtiera, la limpieza debe permanecer hecha para que el
        // siguiente intento de activación no vuelva a chocar.
        $this->cleanupLegacyRegistration();

        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        $res = $this->_remove(array(), $options);

        // _remove() sólo borra por el rights_class actual; barremos los restos viejos
        $this->cleanupLegacyRegistration();

        return $res;
    }
}
