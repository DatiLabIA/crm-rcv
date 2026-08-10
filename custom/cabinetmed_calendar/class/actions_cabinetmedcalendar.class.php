<?php
/* Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed
 * Clase de hooks para integración con la ficha de paciente
 */

/**
 * \file    class/actions_cabinetmedcalendar.class.php
 * \brief   Clase de hooks del módulo cabinetmed_calendar.
 *          Añade un enlace al calendario desde la ficha de paciente (societe/thirdparty).
 */

/**
 * Class ActionsCabinetMedCalendar
 */
class ActionsCabinetMedCalendar
{
    /** @var DoliDB Base de datos */
    public $db;

    /** @var string Último error */
    public $error = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Base de datos
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook: formObjectOptions
     * Se ejecuta en la ficha de terceros (paciente) para añadir el botón "Ver en Calendario".
     * Contextos: 'societecard', 'thirdpartycard'
     *
     * @param  array  $parameters  Parámetros del hook
     * @param  object $object      Objeto sobre el que se ejecuta el hook
     * @param  string $action      Acción actual
     * @param  object $hookmanager Gestor de hooks
     * @return int    0 si OK, <0 si error
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs, $user;

        // Solo en los contextos de ficha de tercero/paciente
        $contexts = explode(':', $parameters['currentcontext']);
        if (!in_array('societecard', $contexts) && !in_array('thirdpartycard', $contexts)) {
            return 0;
        }

        // Solo si el módulo está activo
        if (empty($conf->cabinetmedcalendar->enabled)) {
            return 0;
        }

        // Solo si el usuario tiene permisos de lectura propios del módulo
        $perm_read = !empty($user->rights->cabinetmed_calendar->read);
        if (!$perm_read) {
            return 0;
        }

        // Solo si el tercero es un paciente (canvas cabinetmed)
        if (empty($object->canvas) || $object->canvas !== 'patient@cabinetmed') {
            return 0;
        }

        $langs->load('cabinetmed_calendar');

        // Construir URL del calendario filtrado por este paciente
        // (el calendario no tiene filtro de paciente directo, pero podemos enlazar con el ID)
        $calendar_url = DOL_URL_ROOT . '/custom/cabinetmed_calendar/calendar.php'
            . '?patient_id=' . (int)$object->id;

        // Mostrar botón/enlace
        $hookmanager->resPrint .= '<div class="fichehalfright" style="margin-top:8px;">';
        $hookmanager->resPrint .= '<a href="' . $calendar_url . '" class="butAction" '
            . 'title="' . dol_escape_htmltag($langs->trans('CalendarTitle')) . '">';
        $hookmanager->resPrint .= '<span class="fa fa-calendar-alt"></span> ';
        $hookmanager->resPrint .= dol_escape_htmltag($langs->trans('CalendarTitle'));
        $hookmanager->resPrint .= '</a>';
        $hookmanager->resPrint .= '</div>';

        return 0;
    }

    /**
     * Hook: addMoreActionsButtons
     * Añade botón en la barra de acciones de la ficha de paciente.
     *
     * @param  array  $parameters  Parámetros del hook
     * @param  object $object      Objeto
     * @param  string $action      Acción actual
     * @param  object $hookmanager Gestor de hooks
     * @return int    0 si OK
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs, $user;

        $contexts = explode(':', $parameters['currentcontext']);
        if (!in_array('societecard', $contexts) && !in_array('thirdpartycard', $contexts)) {
            return 0;
        }

        if (empty($conf->cabinetmedcalendar->enabled)) {
            return 0;
        }

        $perm_read = (!empty($user->rights->cabinetmed_calendar->read)
            || (!empty($user->rights->cabinetmed_extcons->read)));
        if (!$perm_read) {
            return 0;
        }

        if (empty($object->canvas) || $object->canvas !== 'patient@cabinetmed') {
            return 0;
        }

        $langs->load('cabinetmed_calendar');

        $calendar_url = DOL_URL_ROOT . '/custom/cabinetmed_calendar/calendar.php'
            . '?patient_id=' . (int)$object->id;

        print '<a class="butAction" href="' . $calendar_url . '">';
        print '<span class="fa fa-calendar-alt pictofixedwidth"></span>';
        print $langs->trans('CalendarTitle');
        print '</a>' . "\n";

        return 0;
    }
}
