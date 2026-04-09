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
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Analíticas avanzadas de pacientes y consultas";
        $this->descriptionlong = "Módulo de reportes e inteligencia de negocios sobre pacientes, consultas extendidas, adherencias, medicamentos, EPS, operadores logísticos y más.";
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'stats';
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://www.datilab.com';

        $this->depends = array('modCabinetMed', 'modCabinetMedExtCons');
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
        $this->menu = array();
        $r = 0;

        // ── Entrada TOP en barra de menú principal ──────────────────────────
        $this->menu[$r] = array(
            'fk_menu'  => 0,
            'type'     => 'top',
            'titre'    => 'Analíticas RCV',
            'prefix'   => img_picto('', 'stats', 'class="pictofixedwidth"'),
            'mainmenu' => 'rcv_analytics',
            'url'      => '/custom/rcv_analytics/index.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 85,
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->rights->rcv_analytics->read',
            'target'   => '',
            'user'     => 2,
        );
        $r++;

        // ── Submenú izquierdo ────────────────────────────────────────────────
        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Dashboard',
            'prefix'   => img_picto('', 'stats', 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_dashboard',
            'url'      => '/custom/rcv_analytics/index.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 10,
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->rights->rcv_analytics->read',
            'target'   => '',
            'user'     => 2,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Pacientes',
            'prefix'   => img_picto('', 'user', 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_patients',
            'url'      => '/custom/rcv_analytics/patients.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 20,
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->rights->rcv_analytics->read',
            'target'   => '',
            'user'     => 2,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Consultas',
            'prefix'   => img_picto('', 'action', 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_consultations',
            'url'      => '/custom/rcv_analytics/consultations.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 30,
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->rights->rcv_analytics->read',
            'target'   => '',
            'user'     => 2,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=rcv_analytics',
            'type'     => 'left',
            'titre'    => 'Exportar datos',
            'prefix'   => img_picto('', 'export', 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'rcv_analytics',
            'leftmenu' => 'rcv_analytics_export',
            'url'      => '/custom/rcv_analytics/export.php',
            'langs'    => 'rcv_analytics@rcv_analytics',
            'position' => 50,
            'enabled'  => '$conf->rcvanalytics->enabled',
            'perms'    => '$user->rights->rcv_analytics->export',
            'target'   => '',
            'user'     => 2,
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
