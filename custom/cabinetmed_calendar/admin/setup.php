<?php
/* Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed */

// Carga del entorno Dolibarr
$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/main.inc.php')) { $res = @include $dir . '/main.inc.php'; break; }
}
if (!$res) {
    die("Error: No se pudo encontrar main.inc.php");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/cabinetmed_calendar/lib/cabinetmed_calendar.lib.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('cabinetmed_calendar', 'admin'));

$action = GETPOST('action', 'alpha');
$error  = 0;
$messages = array();

// ── Regenerar colores automáticos ─────────────────────────────────────────
if ($action === 'autocolor') {
    $token = GETPOST('token', 'alpha');
    $session_token = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '';
    if (empty($token) || empty($session_token) || $token !== $session_token) {
        setEventMessages('Token de seguridad inválido', null, 'errors');
    } else {
        cabinetmed_calendar_assign_auto_colors($db);
        setEventMessages('Colores automáticos regenerados correctamente', null, 'mesgs');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Guardar configuración ──────────────────────────────────────────────────
if ($action === 'save') {
    // CSRF compatible con todas las versiones de Dolibarr
    $token = GETPOST('token', 'alpha');
    $session_token = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '';
    if (empty($token) || empty($session_token) || $token !== $session_token) {
        setEventMessages('Token de seguridad inválido', null, 'errors');
        $error++;
    }

    if (!$error) {
        $view = GETPOST('default_view', 'alpha');
        if (!in_array($view, array('dayGridMonth','timeGridWeek','timeGridDay','listWeek'))) $view = 'dayGridMonth';
        dolibarr_set_const($db, 'CABINETMED_CALENDAR_DEFAULT_VIEW', $view, 'chaine', 0, '', $conf->entity);

        $slot_dur = GETPOST('slot_duration', 'alpha');
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $slot_dur)) $slot_dur = '00:30:00';
        dolibarr_set_const($db, 'CABINETMED_CALENDAR_SLOT_DURATION', $slot_dur, 'chaine', 0, '', $conf->entity);

        $biz_start = GETPOST('biz_start', 'alpha');
        if (!preg_match('/^\d{2}:\d{2}$/', $biz_start)) $biz_start = '08:00';
        dolibarr_set_const($db, 'CABINETMED_CALENDAR_BUSINESS_HOURS_START', $biz_start, 'chaine', 0, '', $conf->entity);

        $biz_end = GETPOST('biz_end', 'alpha');
        if (!preg_match('/^\d{2}:\d{2}$/', $biz_end)) $biz_end = '18:00';
        dolibarr_set_const($db, 'CABINETMED_CALENDAR_BUSINESS_HOURS_END', $biz_end, 'chaine', 0, '', $conf->entity);

        $first_day = GETPOST('first_day', 'int');
        $first_day = ($first_day == 0) ? 0 : 1;
        dolibarr_set_const($db, 'CABINETMED_CALENDAR_FIRST_DAY', (string)$first_day, 'chaine', 0, '', $conf->entity);

        // Colores para tipos dinámicos
        $tipos = cabinetmed_calendar_get_tipos_atencion($db);
        foreach ($tipos as $tipo) {
            $post_key   = 'color_tipo_' . preg_replace('/[^a-zA-Z0-9]/', '_', $tipo->code);
            $color_tipo = GETPOST($post_key, 'alpha');
            if ($color_tipo && $color_tipo[0] !== '#') $color_tipo = '#' . $color_tipo;
            if ($color_tipo && preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color_tipo)) {
                $const_key = 'CABINETMED_CALENDAR_COLOR_' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $tipo->code));
                dolibarr_set_const($db, $const_key, strtoupper($color_tipo), 'chaine', 0, '', $conf->entity);
            }
        }

        // Color por defecto para tipos sin configurar
        $color_default = GETPOST('color_default', 'alpha');
        if ($color_default && $color_default[0] !== '#') $color_default = '#' . $color_default;
        if ($color_default && preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color_default)) {
            dolibarr_set_const($db, 'CABINETMED_CALENDAR_COLOR_DEFAULT', strtoupper($color_default), 'chaine', 0, '', $conf->entity);
        }

        setEventMessages('Configuración guardada correctamente', null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ── Cabecera HTML ──────────────────────────────────────────────────────────
$head = cabinetmed_calendar_prepare_head('setup');
llxHeader('', 'Configuración Calendario', '');
print dol_get_fiche_head($head, 'setup', 'Calendario de Consultas', -1, 'calendar');

$current_view   = !empty($conf->global->CABINETMED_CALENDAR_DEFAULT_VIEW)             ? $conf->global->CABINETMED_CALENDAR_DEFAULT_VIEW             : 'dayGridMonth';
$current_slot   = !empty($conf->global->CABINETMED_CALENDAR_SLOT_DURATION)             ? $conf->global->CABINETMED_CALENDAR_SLOT_DURATION             : '00:30:00';
$current_bstart = !empty($conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_START)      ? $conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_START      : '08:00';
$current_bend   = !empty($conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_END)        ? $conf->global->CABINETMED_CALENDAR_BUSINESS_HOURS_END        : '18:00';
$current_fday   = (int)(!empty($conf->global->CABINETMED_CALENDAR_FIRST_DAY)           ? $conf->global->CABINETMED_CALENDAR_FIRST_DAY                : 1);
$color_default  = !empty($conf->global->CABINETMED_CALENDAR_COLOR_DEFAULT)             ? $conf->global->CABINETMED_CALENDAR_COLOR_DEFAULT             : '#607D8B';
$tipos = cabinetmed_calendar_get_tipos_atencion($db);
?>

<div class="fichecenter">
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token"  value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save">

<!-- Sección: Vista y horarios -->
<div class="div-table-responsive-no-min">
<table class="noborder centpercent">
    <thead><tr class="liste_titre"><th colspan="2">Vista y horarios</th></tr></thead>
    <tbody>
        <tr class="oddeven">
            <td style="width:45%">Vista inicial por defecto</td>
            <td>
                <select name="default_view" class="flat">
                    <option value="dayGridMonth" <?php echo $current_view==='dayGridMonth'?'selected':''; ?>>Mes</option>
                    <option value="timeGridWeek"  <?php echo $current_view==='timeGridWeek' ?'selected':''; ?>>Semana</option>
                    <option value="timeGridDay"   <?php echo $current_view==='timeGridDay'  ?'selected':''; ?>>Día</option>
                    <option value="listWeek"      <?php echo $current_view==='listWeek'     ?'selected':''; ?>>Lista</option>
                </select>
            </td>
        </tr>
        <tr class="oddeven">
            <td>Duración de slots (vista semana/día)</td>
            <td>
                <select name="slot_duration" class="flat">
                    <?php foreach (array('00:15:00'=>'15 min','00:30:00'=>'30 min','01:00:00'=>'1 hora') as $v=>$l): ?>
                    <option value="<?php echo $v; ?>" <?php echo $current_slot===$v?'selected':''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr class="oddeven">
            <td>Primer día de la semana</td>
            <td>
                <select name="first_day" class="flat">
                    <option value="1" <?php echo $current_fday==1?'selected':''; ?>>Lunes</option>
                    <option value="0" <?php echo $current_fday==0?'selected':''; ?>>Domingo</option>
                </select>
            </td>
        </tr>
        <tr class="oddeven">
            <td>Hora inicio jornada laboral</td>
            <td><input type="time" name="biz_start" value="<?php echo dol_escape_htmltag($current_bstart); ?>" class="flat" step="1800"></td>
        </tr>
        <tr class="oddeven">
            <td>Hora fin jornada laboral</td>
            <td><input type="time" name="biz_end" value="<?php echo dol_escape_htmltag($current_bend); ?>" class="flat" step="1800"></td>
        </tr>
    </tbody>
</table>
</div>

<br>

<!-- Sección: Colores por tipo de atención -->
<div class="div-table-responsive-no-min">
<table class="noborder centpercent">
    <thead><tr class="liste_titre"><th colspan="2">Colores por tipo de atención</th></tr></thead>
    <tbody>
        <tr class="oddeven">
            <td style="width:45%">Color por defecto (tipos sin color asignado)</td>
            <td>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="color" name="color_default" value="<?php echo dol_escape_htmltag($color_default); ?>"
                           style="width:46px;height:32px;padding:2px;border-radius:4px;cursor:pointer;">
                    <span style="font-family:monospace;font-size:.9em;"><?php echo dol_escape_htmltag($color_default); ?></span>
                </div>
            </td>
        </tr>
        <?php foreach ($tipos as $tipo):
            $const_key  = 'CABINETMED_CALENDAR_COLOR_' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $tipo->code));
            $color_val  = !empty($conf->global->$const_key) ? $conf->global->$const_key : cabinetmed_calendar_get_type_color($tipo->code, $db);
            $field_name = 'color_tipo_' . preg_replace('/[^a-zA-Z0-9]/', '_', $tipo->code);
        ?>
        <tr class="oddeven">
            <td>
                <?php echo dol_escape_htmltag($tipo->label); ?>
                <small style="color:#6c757d;margin-left:6px;">(<?php echo dol_escape_htmltag($tipo->code); ?>)</small>
            </td>
            <td>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="color" name="<?php echo $field_name; ?>" value="<?php echo dol_escape_htmltag($color_val); ?>"
                           style="width:46px;height:32px;padding:2px;border-radius:4px;cursor:pointer;">
                    <span style="font-family:monospace;font-size:.9em;"><?php echo dol_escape_htmltag($color_val); ?></span>
                    <span style="display:inline-block;width:18px;height:18px;border-radius:50%;
                                 background:<?php echo dol_escape_htmltag($color_val); ?>;
                                 border:1px solid rgba(0,0,0,.15);"></span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tipos)): ?>
        <tr><td colspan="2" style="color:#6c757d;font-style:italic;padding:10px;">
            No hay tipos de atención configurados. Créalos en la configuración de cabinetmed_extcons.
        </td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>

<br>
<div class="center">
    <input type="submit" class="button button-save" value="Guardar configuración">
    &nbsp;
    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?action=autocolor&token=<?php echo newToken(); ?>"
       class="button button-default"
       onclick="return confirm('¿Regenerar colores automáticos para todos los tipos sin color personalizado?');">
        <span class="fa fa-palette"></span> Regenerar colores automáticos
    </a>
</div>
</form>
</div>

<?php
print dol_get_fiche_end();
llxFooter();
$db->close();
