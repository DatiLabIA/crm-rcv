<?php
/* Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed
 * Página principal del calendario interactivo de consultas DoliMed
 */

/**
 * \file    calendar.php
 * \brief   Vista de calendario (mes/semana/día) con filtros y drag & drop
 */

// Carga del entorno Dolibarr — búsqueda robusta subiendo niveles de directorio
$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/main.inc.php')) {
        $res = @include $dir . '/main.inc.php';
        break;
    }
}
if (!$res) {
    die("Error: No se pudo encontrar main.inc.php");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/cabinetmed_calendar/lib/cabinetmed_calendar.lib.php';

// Carga de idiomas
$langs->loadLangs(array('cabinetmed_calendar', 'cabinetmed', 'companies'));

// Control de acceso: el módulo tiene sus propios permisos independientes de cabinetmed
if (empty($conf->cabinetmedcalendar->enabled)) {
    accessforbidden('Module cabinetmed_calendar not enabled');
}

$perm_read  = !empty($user->rights->cabinetmed_calendar->read);
$perm_write = !empty($user->rights->cabinetmed_calendar->write);

if (!$perm_read) {
    accessforbidden();
}

// Obtener datos para el panel de filtros
$gestores     = cabinetmed_calendar_get_gestores($db);
$tipos        = cabinetmed_calendar_get_tipos_atencion($db);
$status_list  = cabinetmed_calendar_get_status_array();

// Vista inicial desde configuración
$default_view = !empty($conf->global->CABINETMED_CALENDAR_DEFAULT_VIEW)  ? $conf->global->CABINETMED_CALENDAR_DEFAULT_VIEW  : 'dayGridMonth';
$first_day    = (int)(!empty($conf->global->CABINETMED_CALENDAR_FIRST_DAY)           ? $conf->global->CABINETMED_CALENDAR_FIRST_DAY           : 1);
$slot_dur     = !empty($conf->global->CABINETMED_CALENDAR_SLOT_DURATION)             ? $conf->global->CABINETMED_CALENDAR_SLOT_DURATION             : '00:30:00';
$biz_start    = !empty($conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_START)      ? $conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_START      : '08:00';
$biz_end      = !empty($conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_END)        ? $conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_END        : '18:00';

// Construir mapa de colores por tipo (para leyenda y JS)
$type_colors = array();
foreach ($tipos as $t) {
    $type_colors[$t->code] = cabinetmed_calendar_get_type_color($t->code, $db);
}

// URL base para los endpoints AJAX
$ajax_base = DOL_URL_ROOT . '/custom/cabinetmed_calendar/ajax/';

// ============================================================
// Cabecera HTML — sin pasar scripts a llxHeader (poco fiable
// con URLs externas en algunas versiones de Dolibarr)
// ============================================================
$page_name = $langs->trans('CalendarTitle');
llxHeader(
    '',
    $page_name,
    '',
    '',
    0,
    0,
    array(), // sin JS extra aquí
    array(
        DOL_URL_ROOT . '/custom/cabinetmed_calendar/css/calendar.css',
    )
);

// Inyectar FullCalendar directamente con <script> para garantizar la carga
print '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>' . "\n";
print '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales/es.global.min.js"></script>' . "\n";
print '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">' . "\n";

// Breadcrumb
$head = cabinetmed_calendar_prepare_head('calendar');
print dol_get_fiche_head($head, 'calendar', $langs->trans('CalendarTitle'), -1, 'calendar');

?>
<div class="fichecenter cabinetmed-calendar-wrap">

    <!-- ===== PANEL DE FILTROS ===== -->
    <div class="calendar-filters-bar" id="calFiltersBar">
        <div class="calendar-filters-inner">

            <!-- Filtro: Gestores -->
            <div class="filter-group" id="filterGroupManagers">
                <label class="filter-label">
                    <span class="fa fa-user-md"></span>
                    <?php echo $langs->trans('FilterByManager'); ?>
                </label>
                <div class="filter-multiselect" id="filterManagers">
                    <button type="button" class="filter-toggle-btn" data-target="dropManagers">
                        <span class="filter-selected-text"><?php echo $langs->trans('FilterAll'); ?></span>
                        <span class="fa fa-chevron-down"></span>
                    </button>
                    <div class="filter-dropdown" id="dropManagers">
                        <label class="filter-option">
                            <input type="checkbox" class="filter-check-all" data-group="manager" checked>
                            <span><?php echo $langs->trans('FilterAll'); ?></span>
                        </label>
                        <?php foreach ($gestores as $gestor): ?>
                        <label class="filter-option">
                            <input type="checkbox" class="filter-check-manager"
                                   value="<?php echo (int)$gestor->rowid; ?>"
                                   data-group="manager" checked>
                            <span class="filter-avatar"><?php echo strtoupper(substr($gestor->firstname, 0, 1) . substr($gestor->lastname, 0, 1)); ?></span>
                            <span><?php echo dol_escape_htmltag($gestor->firstname . ' ' . $gestor->lastname); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Filtro: Tipo de Atención -->
            <div class="filter-group" id="filterGroupTypes">
                <label class="filter-label">
                    <span class="fa fa-clipboard-list"></span>
                    <?php echo $langs->trans('FilterByType'); ?>
                </label>
                <div class="filter-multiselect" id="filterTypes">
                    <button type="button" class="filter-toggle-btn" data-target="dropTypes">
                        <span class="filter-selected-text"><?php echo $langs->trans('FilterAll'); ?></span>
                        <span class="fa fa-chevron-down"></span>
                    </button>
                    <div class="filter-dropdown" id="dropTypes">
                        <label class="filter-option">
                            <input type="checkbox" class="filter-check-all" data-group="tipo" checked>
                            <span><?php echo $langs->trans('FilterAll'); ?></span>
                        </label>
                        <?php foreach ($tipos as $tipo): ?>
                        <?php $color = cabinetmed_calendar_get_type_color($tipo->code, $db); ?>
                        <label class="filter-option">
                            <input type="checkbox" class="filter-check-tipo"
                                   value="<?php echo dol_escape_htmltag($tipo->code); ?>"
                                   data-group="tipo" checked>
                            <span class="filter-color-dot" style="background-color:<?php echo $color; ?>"></span>
                            <span><?php echo dol_escape_htmltag($tipo->label); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Filtro: Estado -->
            <div class="filter-group" id="filterGroupStatus">
                <label class="filter-label">
                    <span class="fa fa-chart-bar"></span>
                    <?php echo $langs->trans('FilterByStatus'); ?>
                </label>
                <div class="filter-multiselect" id="filterStatus">
                    <button type="button" class="filter-toggle-btn" data-target="dropStatus">
                        <span class="filter-selected-text"><?php echo $langs->trans('FilterAll'); ?></span>
                        <span class="fa fa-chevron-down"></span>
                    </button>
                    <div class="filter-dropdown" id="dropStatus">
                        <label class="filter-option">
                            <input type="checkbox" class="filter-check-all" data-group="status" checked>
                            <span><?php echo $langs->trans('FilterAll'); ?></span>
                        </label>
                        <?php foreach ($status_list as $code => $label): ?>
                        <label class="filter-option">
                            <input type="checkbox" class="filter-check-status"
                                   value="<?php echo (int)$code; ?>"
                                   data-group="status" checked>
                            <span class="status-badge status-<?php echo (int)$code; ?>"></span>
                            <span><?php echo dol_escape_htmltag($label); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Botón limpiar filtros -->
            <div class="filter-group filter-group-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearFilters">
                    <span class="fa fa-times"></span>
                    <?php echo $langs->trans('FilterClearAll'); ?>
                </button>
            </div>

        </div><!-- /calendar-filters-inner -->
    </div><!-- /calendar-filters-bar -->

    <!-- ===== CALENDARIO ===== -->
    <div id="calendarContainer">
        <div id="calendarMain"></div>
    </div>


</div><!-- /fichecenter -->

<!-- ===== POPOVER de evento (hidden, se clona en JS) ===== -->
<div id="calEventPopoverTemplate" style="display:none;">
    <div class="cal-popover">
        <div class="cal-popover-title"></div>
        <div class="cal-popover-body">
            <div class="cal-popover-row"><span class="cal-popover-label"><?php echo $langs->trans('AttentionType'); ?>:</span> <span class="cal-pop-tipo"></span></div>
            <div class="cal-popover-row"><span class="cal-popover-label"><?php echo $langs->trans('Status'); ?>:</span> <span class="cal-pop-status"></span></div>
            <div class="cal-popover-row"><span class="cal-popover-label"><?php echo $langs->trans('StartDate'); ?>:</span> <span class="cal-pop-start"></span></div>
            <div class="cal-popover-row"><span class="cal-popover-label"><?php echo $langs->trans('EndDate'); ?>:</span> <span class="cal-pop-end"></span></div>
            <div class="cal-popover-row"><span class="cal-popover-label"><?php echo $langs->trans('Managers'); ?>:</span> <span class="cal-pop-gestores"></span></div>
        </div>
        <div class="cal-popover-actions">
            <button class="btn btn-sm btn-secondary cal-pop-btn-color" title="<?php echo $langs->trans('EditColor'); ?>">
                <span class="fa fa-palette"></span> <?php echo $langs->trans('EditColor'); ?>
            </button>
            <a class="btn btn-sm btn-primary cal-pop-btn-view" href="#">
                <span class="fa fa-eye"></span> <?php echo $langs->trans('ViewConsultation'); ?>
            </a>
        </div>
    </div>
</div>

<!-- ===== SELECTOR DE COLOR (hidden, se clona en JS) ===== -->
<div id="calColorPickerTemplate" style="display:none;">
    <div class="cal-color-picker">
        <div class="cal-color-picker-title"><?php echo $langs->trans('ColorLabel'); ?></div>
        <div class="cal-color-presets">
            <button class="cal-color-preset" data-color="#F44336" style="background:#F44336" title="Rojo"></button>
            <button class="cal-color-preset" data-color="#FF9800" style="background:#FF9800" title="Naranja"></button>
            <button class="cal-color-preset" data-color="#FFEB3B" style="background:#FFEB3B" title="Amarillo"></button>
            <button class="cal-color-preset" data-color="#4CAF50" style="background:#4CAF50" title="Verde"></button>
            <button class="cal-color-preset" data-color="#2196F3" style="background:#2196F3" title="Azul"></button>
            <button class="cal-color-preset" data-color="#9C27B0" style="background:#9C27B0" title="Morado"></button>
            <button class="cal-color-preset" data-color="#607D8B" style="background:#607D8B" title="Gris"></button>
            <button class="cal-color-preset" data-color="#000000" style="background:#000000" title="Negro"></button>
        </div>
        <div class="cal-color-custom-wrap">
            <label><?php echo $langs->trans('ColorCustom'); ?>:</label>
            <input type="color" class="cal-color-custom-input" value="#607D8B">
            <button class="btn btn-sm btn-primary cal-color-apply-custom"><?php echo $langs->trans('FilterApply'); ?></button>
        </div>
        <div class="cal-color-remove-wrap">
            <button class="btn btn-sm btn-outline-danger cal-color-remove">
                <span class="fa fa-times"></span> <?php echo $langs->trans('ColorRemove'); ?>
            </button>
        </div>
    </div>
</div>

<!-- ===== NOTIFICACIONES TOAST ===== -->
<div id="calToastContainer" class="cal-toast-container"></div>

<?php
// Calcular festivos para el año actual y los dos siguientes (cubre cualquier rango visible)
$current_year = (int)date('Y');
$holidays_flat = array_merge(
    cabinetmed_calendar_get_holidays($current_year - 1),
    cabinetmed_calendar_get_holidays($current_year),
    cabinetmed_calendar_get_holidays($current_year + 1),
    cabinetmed_calendar_get_holidays($current_year + 2)
);

// Pasar variables PHP a JavaScript
$js_config = array(
    'ajaxBase'      => $ajax_base,
    'token'         => newToken(),
    'defaultView'   => $default_view,
    'firstDay'      => (int)$first_day,
    'slotDuration'  => $slot_dur,
    'bizStart'      => $biz_start,
    'bizEnd'        => $biz_end,
    'canWrite'      => (int)$perm_write,
    'currentUserId' => (int)$user->id,
    'typeColors'    => $type_colors,
    'holidays'      => array_values(array_unique($holidays_flat)),
    'lang'          => array(
        'eventMoved'         => $langs->trans('EventMoved'),
        'eventMovedError'    => $langs->trans('EventMovedError'),
        'eventResized'       => $langs->trans('EventResized'),
        'eventResizedError'  => $langs->trans('EventResizedError'),
        'colorSaved'         => $langs->trans('ColorSaved'),
        'colorError'         => $langs->trans('ColorError'),
        'noPermission'       => $langs->trans('NoPermissionMove'),
        'blockedSunday'      => $langs->trans('BlockedSunday'),
        'blockedHoliday'     => $langs->trans('BlockedHoliday'),
        'colorRemoved'       => $langs->trans('ColorRemoved'),
        'networkError'       => $langs->trans('NetworkError'),
        'dbError'            => $langs->trans('DBError'),
        'statusLabels'       => $status_list,
    ),
);
?>

<script type="text/javascript">
var CALENDAR_CONFIG = <?php echo json_encode($js_config); ?>;
</script>

<!-- Carga del script principal del calendario -->
<script type="text/javascript" src="<?php echo DOL_URL_ROOT; ?>/custom/cabinetmed_calendar/js/calendar-app.js"></script>

<?php
print dol_get_fiche_end();

// Pie de página Dolibarr
llxFooter();
$db->close();
?>
