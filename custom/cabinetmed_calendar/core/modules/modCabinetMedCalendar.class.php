<?php
/* Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed
 * Este módulo es una extensión del módulo cabinetmed_extcons de Dolibarr
 *
 * Este programa es software libre; puede redistribuirlo y/o modificarlo
 * bajo los términos de la GNU General Public License.
 */

/**
 * \file    core/modules/modCabinetMedCalendar.class.php
 * \brief   Descriptor del módulo cabinetmed_calendar
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Class modCabinetMedCalendar
 * Módulo de calendario interactivo para consultas extendidas DoliMed.
 */
class modCabinetMedCalendar extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Base de datos
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Identificadores únicos del módulo
        $this->numero        = 680100; // Número único del módulo (rango 680000+)
        // rights_class propio: los permisos son independientes de cabinetmed
        $this->rights_class  = 'cabinetmed_calendar';
        // family propio: aparece en su propia categoría en la lista de módulos
        $this->family        = 'cabinetmed_calendar';
        $this->familylabel   = 'Calendario DoliMed';
        $this->module_position = 500;
        $this->famille       = '';
        $this->name          = preg_replace('/^mod/i', '', get_class($this));
        $this->description   = 'Calendario interactivo de consultas DoliMed con drag & drop, filtros y etiquetas de color';
        $this->descriptionlong = 'Módulo independiente que complementa cabinetmed_extcons. '
            . 'Proporciona una vista de calendario para navegar por mes/semana/día, mover consultas con drag & drop, '
            . 'filtrar por gestor/tipo/estado y personalizar colores de etiqueta. '
            . 'No es un submódulo de cabinetmed: se activa y gestiona de forma autónoma.';
        $this->editor_name   = 'DatiLab';
        $this->editor_url    = 'https://datilab.co';
        $this->version       = '1.0.0';
        $this->const_name    = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto         = 'calendar';

        // Dependencias funcionales: necesita que las tablas de extcons existan,
        // pero NO es hijo de cabinetmed — es un complemento independiente.
        // modCabinetMedExtCons se lista como dependencia para garantizar que sus
        // tablas (llx_cabinetmed_extcons, llx_cabinetmed_extcons_types, etc.) estén creadas.
        $this->depends      = array('modCabinetMedExtCons');
        $this->requiredby   = array();
        $this->conflictwith = array();
        $this->langfiles    = array('cabinetmed_calendar');
        $this->phpmin       = array(7, 4);
        $this->need_dolibarr_version = array(16, 0);

        // Configuración
        $this->config_page_url = array('setup.php@cabinetmed_calendar');

        // Constantes del módulo
        $this->const = array(
            0 => array(
                'CABINETMED_CALENDAR_DEFAULT_VIEW',
                'chaine',
                'dayGridMonth',
                'Vista inicial del calendario (dayGridMonth/timeGridWeek/timeGridDay)',
                0,
                'current',
                1
            ),
            1 => array(
                'CABINETMED_CALENDAR_SLOT_DURATION',
                'chaine',
                '00:30:00',
                'Duración de los slots en vistas semana/día',
                0,
                'current',
                1
            ),
            2 => array(
                'CABINETMED_CALENDAR_BUSINESS_HOURS_START',
                'chaine',
                '08:00',
                'Hora inicio jornada laboral',
                0,
                'current',
                1
            ),
            3 => array(
                'CABINETMED_CALENDAR_BUSINESS_HOURS_END',
                'chaine',
                '18:00',
                'Hora fin jornada laboral',
                0,
                'current',
                1
            ),
            4 => array(
                'CABINETMED_CALENDAR_FIRST_DAY',
                'chaine',
                '1',
                'Primer día de la semana (0=Domingo, 1=Lunes)',
                0,
                'current',
                1
            ),
            5 => array(
                'CABINETMED_CALENDAR_COLOR_ADHERENCIA',
                'chaine',
                '#4CAF50',
                'Color por defecto para tipo Adherencia',
                0,
                'current',
                1
            ),
            6 => array(
                'CABINETMED_CALENDAR_COLOR_CONTROL',
                'chaine',
                '#2196F3',
                'Color por defecto para tipo Control médico',
                0,
                'current',
                1
            ),
            7 => array(
                'CABINETMED_CALENDAR_COLOR_ENFERMERIA',
                'chaine',
                '#FF9800',
                'Color por defecto para tipo Enfermería',
                0,
                'current',
                1
            ),
            8 => array(
                'CABINETMED_CALENDAR_COLOR_FARMACIA',
                'chaine',
                '#9C27B0',
                'Color por defecto para tipo Farmacia',
                0,
                'current',
                1
            ),
            9 => array(
                'CABINETMED_CALENDAR_COLOR_DEFAULT',
                'chaine',
                '#607D8B',
                'Color por defecto para otros tipos de atención',
                0,
                'current',
                1
            ),
        );

        // Tablas SQL a crear durante la instalación
        $this->tabs = array();

        // Tablas a instalar
        $this->module_parts = array(
            'triggers'   => 0,
            'login'      => 0,
            'substitutions' => 0,
            'menus'      => 1,
            'theme'      => 0,
            'tpl'        => 0,
            'barcode'    => 0,
            'models'     => 0,
            'printing'   => 0,
            'css'        => array('/cabinetmed_calendar/css/calendar.css'),
            'js'         => array(),
            'hooks'      => array('data' => array('societecard', 'thirdpartycard'), 'entity' => '0'),
            'workflow'   => 0
        );

        // Permisos
        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero * 100 + $r;
        $this->rights[$r][1] = 'Ver el calendario de consultas';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero * 100 + $r;
        $this->rights[$r][1] = 'Mover y redimensionar consultas en el calendario';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $r++;

        $this->rights[$r][0] = $this->numero * 100 + $r;
        $this->rights[$r][1] = 'Cambiar colores de etiquetas en el calendario';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'colorize';
        $r++;

        // ================================================================
        // MENÚS
        // Dolibarr escribe estas entradas en llx_menu SOLO al activar
        // el módulo. Después de cualquier cambio en esta sección hay que
        // desactivar y volver a activar el módulo para que surtan efecto.
        // ================================================================
        $this->menu = array();
        $r = 0;

        // ── TOP MENU ─────────────────────────────────────────────────
        // fk_menu = 0 (entero, NO string) → entrada raíz en la barra superior
        // type    = 'top'
        // titre   → texto mostrado; Dolibarr lo traduce con el archivo langs indicado
        // IMPORTANTE: no usar img_picto() en prefix para el top menu,
        //             algunos temas de Dolibarr lo ignoran o lo rompen.
        $this->menu[$r]['fk_menu']  = 0;
        $this->menu[$r]['type']     = 'top';
        $this->menu[$r]['titre']    = 'Agenda Médica';       // texto literal como fallback
        $this->menu[$r]['mainmenu'] = 'cabinetmed_calendar';
        $this->menu[$r]['leftmenu'] = '';
        $this->menu[$r]['url']      = '/cabinetmed_calendar/calendar.php';
        $this->menu[$r]['langs']    = 'cabinetmed_calendar';
        $this->menu[$r]['position'] = 82;
        $this->menu[$r]['enabled']  = 'isModEnabled("cabinetmedcalendar")';
        $this->menu[$r]['perms']    = '$user->rights->cabinetmed_calendar->read';
        $this->menu[$r]['target']   = '';
        $this->menu[$r]['user']     = 0;
        $r++;

        // ── LEFT MENU: Calendario ────────────────────────────────────
        $this->menu[$r]['fk_menu']  = 'fk_mainmenu=cabinetmed_calendar';
        $this->menu[$r]['type']     = 'left';
        $this->menu[$r]['titre']    = 'Calendario';
        $this->menu[$r]['mainmenu'] = 'cabinetmed_calendar';
        $this->menu[$r]['leftmenu'] = 'cabinetmed_calendar_main';
        $this->menu[$r]['url']      = '/cabinetmed_calendar/calendar.php';
        $this->menu[$r]['langs']    = 'cabinetmed_calendar';
        $this->menu[$r]['position'] = 10;
        $this->menu[$r]['enabled']  = 'isModEnabled("cabinetmedcalendar")';
        $this->menu[$r]['perms']    = '$user->rights->cabinetmed_calendar->read';
        $this->menu[$r]['target']   = '';
        $this->menu[$r]['user']     = 0;
        $r++;

        // ── LEFT MENU: Configuración (solo admin) ────────────────────
        $this->menu[$r]['fk_menu']  = 'fk_mainmenu=cabinetmed_calendar';
        $this->menu[$r]['type']     = 'left';
        $this->menu[$r]['titre']    = 'Configuración';
        $this->menu[$r]['mainmenu'] = 'cabinetmed_calendar';
        $this->menu[$r]['leftmenu'] = 'cabinetmed_calendar_setup';
        $this->menu[$r]['url']      = '/cabinetmed_calendar/admin/setup.php';
        $this->menu[$r]['langs']    = 'cabinetmed_calendar';
        $this->menu[$r]['position'] = 20;
        $this->menu[$r]['enabled']  = 'isModEnabled("cabinetmedcalendar")';
        $this->menu[$r]['perms']    = '$user->admin';
        $this->menu[$r]['target']   = '';
        $this->menu[$r]['user']     = 2;
        $r++;
    }

    public function init($options = '')
    {
        // Limpiar menús anteriores para evitar duplicados en reinstalación
        $this->_cleanMenus();

        // Crear tabla (CREATE TABLE IF NOT EXISTS — seguro para reinstalaciones)
        $this->_load_tables('/cabinetmed_calendar/sql/');

        $result = $this->_init(array(), $options);

        // Auto-asignar colores a todos los tipos de atención existentes
        // que aún no tengan color configurado
        if ($result > 0) {
            require_once DOL_DOCUMENT_ROOT . '/custom/cabinetmed_calendar/lib/cabinetmed_calendar.lib.php';
            cabinetmed_calendar_assign_auto_colors($this->db);
        }

        return $result;
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }

    private function _cleanMenus()
    {
        // Limpiar por URL — más fiable que por 'module' que varía entre versiones
        $urls = array(
            '/cabinetmed_calendar/calendar.php',
            '/cabinetmed_calendar/admin/setup.php',
        );
        foreach ($urls as $url) {
            $this->db->query(
                "DELETE FROM " . MAIN_DB_PREFIX . "menu WHERE url = '" . $this->db->escape($url) . "'"
            );
        }
    }
}
