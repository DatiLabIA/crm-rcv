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

        $this->numero = 502200;
        $this->rights_class = 'rcv_analytics';
        $this->family = "crm";
        $this->module_position = '95';

        // name → strtolower del segmento tras MAIN_MODULE_ → clave en $conf->modules
        // 'RcvAnalytics' → const MAIN_MODULE_RCVANALYTICS → $conf->modules['rcvanalytics']
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        $this->description = "Analíticas avanzadas de pacientes y consultas";
        $this->descriptionlong = "Módulo de reportes e inteligencia de negocios sobre pacientes, consultas extendidas, adherencias, medicamentos, EPS, operadores logísticos y más.";
        $this->version = '1.0.0';
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
        $this->rights[$r][1] = 'Exportar reportes de analíticas';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'export';

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
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->hasRight("rcv_analytics", "read")',
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
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->hasRight("rcv_analytics", "read")',
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
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->hasRight("rcv_analytics", "read")',
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
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->hasRight("rcv_analytics", "read")',
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
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->hasRight("rcv_analytics", "export")',
            'target'   => '',
            'user'     => 0,
        );
    }

    public function init($options = '')
    {
        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
