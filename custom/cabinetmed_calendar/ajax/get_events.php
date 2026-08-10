<?php
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/main.inc.php')) { $res = @include $dir . '/main.inc.php'; break; }
}
if (!$res) { http_response_code(500); die(json_encode(array('error' => 'Cannot load main.inc.php'))); }

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/cabinetmed_calendar/lib/cabinetmed_calendar.lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── Debug mode: añade ?debug=1 a la URL para ver diagnóstico completo ──────
$debug = (GETPOST('debug', 'int') == 1 && !empty($user->admin));
$diag  = array();

// ── Control de acceso ──────────────────────────────────────────────────────
if (!isModEnabled('cabinetmedcalendar')) {
    http_response_code(403);
    echo json_encode(array('error' => 'Module cabinetmed_calendar not enabled'));
    exit;
}
$perm_read = !empty($user->rights->cabinetmed_calendar->read);
if (!$perm_read) {
    http_response_code(403);
    echo json_encode(array('error' => 'Access denied'));
    exit;
}

// ── CSRF ───────────────────────────────────────────────────────────────────
$token = GETPOST('token', 'alpha');
$session_token = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '';
if (!$debug) { // En debug saltamos CSRF para poder abrir la URL directamente
    if (empty($token) || empty($session_token) || $token !== $session_token) {
        http_response_code(403);
        echo json_encode(array('error' => 'Invalid CSRF token', 'got' => $token, 'expected_len' => strlen($session_token)));
        exit;
    }
}

// ── Parámetros de fecha ────────────────────────────────────────────────────
$start_iso   = GETPOST('start',         'alpha');
$end_iso     = GETPOST('end',           'alpha');
$status_raw  = GETPOST('status',        'array');
$tipo_raw    = GETPOST('tipo_atencion', 'array');
$fk_user_raw = GETPOST('fk_user',       'array');

// FullCalendar envía fechas ISO como "2026-03-01T00:00:00+05:00"
// strtotime() las parsea correctamente a UTC
$ts_start = !empty($start_iso) ? strtotime($start_iso) : mktime(0, 0, 0, date('n') - 1, 1);
$ts_end   = !empty($end_iso)   ? strtotime($end_iso)   : mktime(0, 0, 0, date('n') + 1, 1);

if (!$ts_start || !$ts_end) {
    $ts_start = mktime(0, 0, 0, date('n'), 1);
    $ts_end   = mktime(23, 59, 59, date('n'), date('t'));
}

// Convertir a formato MySQL DATETIME directamente (sin pasar por idate que puede tener bugs)
$date_start_sql = date('Y-m-d H:i:s', $ts_start);
$date_end_sql   = date('Y-m-d H:i:s', $ts_end);

if ($debug) {
    $diag['start_iso']     = $start_iso;
    $diag['end_iso']       = $end_iso;
    $diag['ts_start']      = $ts_start;
    $diag['ts_end']        = $ts_end;
    $diag['date_start_sql']= $date_start_sql;
    $diag['date_end_sql']  = $date_end_sql;
    $diag['entity']        = getEntity('consultation');
    $diag['user_id']       = $user->id;
}

// Sanitizar filtros
$filter_status = array();
if (!empty($status_raw) && is_array($status_raw)) {
    foreach ($status_raw as $s) { $val = (int)$s; if (in_array($val, array(0,1,2), true)) $filter_status[] = $val; }
}
$filter_tipo = array();
if (!empty($tipo_raw) && is_array($tipo_raw)) {
    foreach ($tipo_raw as $t) $filter_tipo[] = $db->escape(trim((string)$t));
}
$filter_user = array();
if (!empty($fk_user_raw) && is_array($fk_user_raw)) {
    foreach ($fk_user_raw as $u) { $uid = (int)$u; if ($uid > 0) $filter_user[] = $uid; }
}

// ── Verificar que la tabla existe y tiene datos ────────────────────────────
if ($debug) {
    $sql_count = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons";
    $res_count = $db->query($sql_count);
    if ($res_count) {
        $row_count = $db->fetch_object($res_count);
        $diag['total_rows_in_table'] = (int)$row_count->total;
        $db->free($res_count);
    } else {
        $diag['table_error'] = $db->lasterror();
    }

    // Muestra una consulta de ejemplo sin filtros de fecha
    $sql_sample = "SELECT rowid, date_start, date_end, tipo_atencion, status, fk_soc, entity"
                . " FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons LIMIT 3";
    $res_sample = $db->query($sql_sample);
    $diag['sample_rows'] = array();
    if ($res_sample) {
        while ($s = $db->fetch_object($res_sample)) $diag['sample_rows'][] = (array)$s;
        $db->free($res_sample);
    }
}

// ── SQL principal ──────────────────────────────────────────────────────────
$sql  = "SELECT c.rowid, c.date_start, c.date_end, c.tipo_atencion, c.status,";
$sql .= "       c.fk_soc, c.fk_user,";
$sql .= "       s.nom AS patient_name,";
$sql .= "       t.label AS tipo_label,";
$sql .= "       cal.color AS custom_color";
$sql .= " FROM "     . MAIN_DB_PREFIX . "cabinetmed_extcons c";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = c.fk_soc";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "cabinetmed_extcons_types t";
$sql .= "         ON t.code = c.tipo_atencion";
$sql .= "        AND t.entity IN (" . getEntity('consultation') . ")";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "cabinetmed_calendar_colors cal";
$sql .= "         ON cal.fk_extcons = c.rowid AND cal.fk_user = " . (int)$user->id;
$sql .= " WHERE c.entity IN (" . getEntity('consultation') . ")";
$sql .= "   AND c.date_start < '"  . $db->escape($date_end_sql)   . "'";
$sql .= "   AND (";
$sql .= "         c.date_end   > '" . $db->escape($date_start_sql) . "'";
$sql .= "         OR (c.date_end IS NULL AND c.date_start >= '" . $db->escape($date_start_sql) . "')";
$sql .= "       )";

if (!empty($filter_status)) $sql .= " AND c.status IN (" . implode(',', $filter_status) . ")";
if (!empty($filter_tipo))   $sql .= " AND c.tipo_atencion IN ('" . implode("','", $filter_tipo) . "')";
if (!empty($filter_user)) {
    $sql .= " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons_users cu"
          . " WHERE cu.fk_extcons = c.rowid AND cu.fk_user IN (" . implode(',', $filter_user) . "))";
}
$sql .= " ORDER BY c.date_start ASC";

if ($debug) $diag['sql'] = $sql;

$resql = $db->query($sql);
if (!$resql) {
    $err = array('error' => 'Database error: ' . $db->lasterror());
    if ($debug) $err['diag'] = $diag;
    http_response_code(500);
    echo json_encode($err);
    exit;
}

// Recolectar filas
$cons_ids  = array();
$cons_rows = array();
while ($obj = $db->fetch_object($resql)) {
    $cons_ids[]  = (int)$obj->rowid;
    $cons_rows[] = $obj;
}
$db->free($resql);

if ($debug) $diag['rows_found'] = count($cons_rows);

// ── Gestores en batch ──────────────────────────────────────────────────────
$gestores_map = array();
if (!empty($cons_ids)) {
    $sql_g  = "SELECT cu.fk_extcons, u.firstname, u.lastname";
    $sql_g .= " FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons_users cu";
    $sql_g .= " INNER JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = cu.fk_user";
    $sql_g .= " WHERE cu.fk_extcons IN (" . implode(',', $cons_ids) . ")";
    $resql_g = $db->query($sql_g);
    if ($resql_g) {
        while ($g = $db->fetch_object($resql_g)) {
            $key = (int)$g->fk_extcons;
            if (!isset($gestores_map[$key])) $gestores_map[$key] = array();
            $gestores_map[$key][] = trim($g->firstname . ' ' . $g->lastname);
        }
        $db->free($resql_g);
    }
}

// ── Construir respuesta JSON ───────────────────────────────────────────────
$status_labels = array(0 => 'En progreso', 1 => 'Completada', 2 => 'Cancelada');
$base_url      = DOL_URL_ROOT . '/custom/cabinetmed_extcons/consultation_card.php';
$events        = array();

foreach ($cons_rows as $obj) {
    $cons_id   = (int)$obj->rowid;
    $tipo_code = (string)$obj->tipo_atencion;

    $bg_color = (!empty($obj->custom_color) && cabinetmed_calendar_is_valid_hex_color($obj->custom_color))
        ? $obj->custom_color
        : cabinetmed_calendar_get_type_color($tipo_code);

    // Convertir DATETIME de MySQL a timestamp
    $ts_ev_start = !empty($obj->date_start) ? strtotime($obj->date_start) : null;
    $ts_ev_end   = !empty($obj->date_end)   ? strtotime($obj->date_end)   : null;

    // ── Detección de allDay ────────────────────────────────────────────────
    // Un evento es "all-day" si:
    //   a) No tiene date_end (consulta puntual de un solo día), o
    //   b) date_end <= date_start (dato incoherente, tratar como día completo), o
    //   c) Ambas fechas tienen componente horario 00:00:00 (fueron guardadas como
    //      date-only). Esto ocurre después de un resize en dayGridMonth: el JS
    //      envía "2024-03-15" y "2024-03-18", MySQL los guarda como
    //      "2024-03-15 00:00:00" y "2024-03-18 00:00:00".
    //
    // Sin la condición (c), un evento multi-día resizeado sería devuelto como
    // timed (allDay=false) y FullCalendar le quitaría el resize handle en
    // dayGridMonth, impidiendo futuros resizes.
    $start_midnight = ($ts_ev_start !== null && date('H:i:s', $ts_ev_start) === '00:00:00');
    $end_midnight   = ($ts_ev_end   !== null && date('H:i:s', $ts_ev_end)   === '00:00:00');

    $is_all_day = ($ts_ev_end === null
                || $ts_ev_end <= $ts_ev_start
                || ($start_midnight && $end_midnight));

    if ($is_all_day) {
        // Para allDay, FullCalendar espera solo la fecha (sin hora)
        $dt_start = $ts_ev_start ? date('Y-m-d', $ts_ev_start) : null;
        // Para eventos multi-día allDay, enviar date_end para que
        // FullCalendar muestre la barra hasta ese día (exclusivo)
        $dt_end   = ($ts_ev_end !== null && $ts_ev_end > $ts_ev_start)
                  ? date('Y-m-d', $ts_ev_end)
                  : null;
    } else {
        $dt_start = date('Y-m-d\TH:i:s', $ts_ev_start);
        $dt_end   = date('Y-m-d\TH:i:s', $ts_ev_end);
    }

    $patient_name = !empty($obj->patient_name) ? $obj->patient_name : 'Paciente #' . $obj->fk_soc;
    $tipo_label   = !empty($obj->tipo_label)   ? $obj->tipo_label   : $tipo_code;
    $status_int   = (int)$obj->status;

    // ── Tres colores independientes ────────────────────────────────────────
    // 1. Franja superior: color del TIPO DE ATENCIÓN
    $tipo_color = $bg_color; // ya calculado arriba (personalizado o por tipo)

    // 2. Fondo del contenido: color del GESTOR PRINCIPAL (fk_user)
    //    Generamos un color pastel determinístico a partir del ID de usuario
    $manager_color = cabinetmed_calendar_get_user_color((int)$obj->fk_user);

    // 3. Franja derecha: color del ESTADO
    $status_colors = array(
        0 => '#FFC107', // En progreso → amarillo
        1 => '#4CAF50', // Completada  → verde
        2 => '#F44336', // Cancelada   → rojo
    );
    $status_color = isset($status_colors[$status_int]) ? $status_colors[$status_int] : '#9E9E9E';

    $events[] = array(
        'id'          => $cons_id,
        'title'       => $patient_name . ($tipo_label ? ' · ' . $tipo_label : ''),
        'start'       => $dt_start,
        'end'         => $dt_end,
        'allDay'      => $is_all_day,
        'url'         => $base_url . '?id=' . $cons_id,
        // Color base neutro — el diseño real lo pinta eventContent en JS
        'color'       => '#ffffff',
        'borderColor' => '#cccccc',
        'textColor'   => '#333333',
        'classNames'  => array('cal-event-status-' . $status_int, 'cal-event-card'),
        'extendedProps' => array(
            'patient_id'          => (int)$obj->fk_soc,
            'patient_name'        => $patient_name,
            'tipo_atencion'       => $tipo_code,
            'tipo_atencion_label' => $tipo_label,
            'status'              => $status_int,
            'status_label'        => isset($status_labels[$status_int]) ? $status_labels[$status_int] : '',
            'gestores'            => isset($gestores_map[$cons_id]) ? $gestores_map[$cons_id] : array(),
            'fk_user'             => (int)$obj->fk_user,
            // Tres colores para la tarjeta
            'tipo_color'          => $tipo_color,
            'manager_color'       => $manager_color,
            'status_color'        => $status_color,
        ),
    );
}

if ($debug) {
    echo json_encode(array('_debug' => $diag, 'events' => $events), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
exit;