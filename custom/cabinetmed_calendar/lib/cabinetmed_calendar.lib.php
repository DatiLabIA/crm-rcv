<?php
/* Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed
 * Funciones helper para el módulo cabinetmed_calendar
 */

/**
 * \file    lib/cabinetmed_calendar.lib.php
 * \brief   Funciones auxiliares del módulo calendario: colores, cabeceras HTML, utilidades.
 */

/**
 * Prepara el array de cabeceras para las páginas del módulo calendario.
 * Incluye los enlaces de navegación del breadcrumb Dolibarr.
 *
 * @param  string $pagename  Nombre de la página actual (para el título)
 * @return array             Array de elementos del head
 */
function cabinetmed_calendar_prepare_head($pagename = '')
{
    global $langs, $conf, $user;

    $langs->load('cabinetmed_calendar');

    $h = 0;
    $head = array();

    $head[$h][0] = DOL_URL_ROOT . '/custom/cabinetmed_calendar/calendar.php';
    $head[$h][1] = $langs->trans('CalendarTitle');
    $head[$h][2] = 'calendar';
    $h++;

    if ($user->admin) {
        $head[$h][0] = DOL_URL_ROOT . '/custom/cabinetmed_calendar/admin/setup.php';
        $head[$h][1] = $langs->trans('Setup');
        $head[$h][2] = 'setup';
        $h++;
    }

    return $head;
}

/**
 * Obtiene el color correspondiente a un tipo de atención.
 * Prioridad: color personalizado del usuario > constante de configuración > color por defecto.
 *
 * @param  string $tipo_atencion   Código del tipo de atención (ej: 'adherencia')
 * @param  int    $fk_extcons      ID de la consulta
 * @param  int    $fk_user         ID del usuario actual
 * @param  DoliDB $db              Objeto de base de datos
 * @return string                  Código hex del color (#RRGGBB)
 */
function cabinetmed_calendar_get_event_color($tipo_atencion, $fk_extcons, $fk_user, $db)
{
    global $conf;

    // 1. Prioridad máxima: color personalizado del usuario para esta consulta
    $sql  = "SELECT color FROM " . MAIN_DB_PREFIX . "cabinetmed_calendar_colors";
    $sql .= " WHERE fk_extcons = " . (int)$fk_extcons;
    $sql .= "   AND fk_user = " . (int)$fk_user;
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $row = $db->fetch_object($resql);
        return $row->color;
    }

    // 2. Color configurado para el tipo de atención (con lazy-assign si es nuevo)
    return cabinetmed_calendar_get_type_color($tipo_atencion, $db);
}

/**
 * Devuelve la paleta de colores automáticos para tipos de atención.
 * Colores Material Design 700–800, suficientemente oscuros para texto blanco.
 *
 * @return array  Array indexado de códigos hex
 */
function cabinetmed_calendar_get_auto_palette()
{
    return array(
        '#1565C0', // azul
        '#2E7D32', // verde
        '#E65100', // naranja
        '#6A1B9A', // morado
        '#B71C1C', // rojo
        '#00695C', // teal
        '#4527A0', // índigo
        '#AD1457', // rosa
        '#558B2F', // lima
        '#0277BD', // azul claro
        '#4E342E', // marrón
        '#37474F', // gris azul
    );
}

/**
 * Asigna automáticamente colores a todos los tipos de atención que aún no
 * tienen una constante de color definida.
 * Los colores se eligen de la paleta rotando por orden de creación (rowid ASC).
 * Se llama desde modCabinetMedCalendar::init() para cubrir tipos existentes.
 *
 * @param  DoliDB $db      Base de datos
 * @param  int    $entity  Entidad Dolibarr (0 = todas)
 * @return void
 */
function cabinetmed_calendar_assign_auto_colors($db, $entity = 0)
{
    global $conf;

    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

    $palette = cabinetmed_calendar_get_auto_palette();
    $n       = count($palette);

    // Obtener todos los tipos activos ordenados por rowid para asignación estable
    $sql  = "SELECT rowid, code FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons_types";
    $sql .= " WHERE active = 1";
    if ($entity > 0) {
        $sql .= " AND entity = " . (int)$entity;
    }
    $sql .= " ORDER BY rowid ASC";

    $resql = $db->query($sql);
    if (!$resql) {
        return;
    }

    $target_entity = ($entity > 0) ? $entity : (isset($conf->entity) ? (int)$conf->entity : 1);
    $idx = 0;

    while ($obj = $db->fetch_object($resql)) {
        $tipo_upper = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $obj->code));
        $const_key  = 'CABINETMED_CALENDAR_COLOR_' . $tipo_upper;

        // Solo asignar si aún no existe
        if (empty($conf->global->$const_key)) {
            $color = $palette[$idx % $n];
            dolibarr_set_const($db, $const_key, $color, 'chaine', 0, '', $target_entity);
            $conf->global->$const_key = $color; // actualizar caché en memoria
        }
        $idx++;
    }

    $db->free($resql);
}

/**
 * Obtiene el color configurado para un tipo de atención (sin color personalizado de usuario).
 * Busca la constante CABINETMED_CALENDAR_COLOR_{TIPO_UPPER}.
 * Si no existe, auto-asigna un color de la paleta y lo guarda como constante (lazy assignment).
 *
 * @param  string      $tipo_atencion  Código del tipo de atención
 * @param  DoliDB|null $db             Base de datos (necesario para lazy assignment)
 * @return string                      Código hex del color
 */
function cabinetmed_calendar_get_type_color($tipo_atencion, $db = null)
{
    global $conf;

    $tipo_upper = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $tipo_atencion));
    $const_key  = 'CABINETMED_CALENDAR_COLOR_' . $tipo_upper;

    // 1. Constante ya guardada → devolver directamente
    if (!empty($conf->global->$const_key)) {
        return $conf->global->$const_key;
    }

    // 2. Auto-asignar: elegir color de la paleta basado en hash del código
    //    (determinístico sin DB, siempre el mismo color para el mismo código)
    $palette = cabinetmed_calendar_get_auto_palette();
    $n       = count($palette);
    $idx     = abs(crc32($tipo_atencion)) % $n;
    $color   = $palette[$idx];

    // 3. Si tenemos DB, persistir la constante para que sea visible en setup.php
    if ($db !== null) {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        $entity = isset($conf->entity) ? (int)$conf->entity : 1;
        dolibarr_set_const($db, $const_key, $color, 'chaine', 0, '', $entity);
        $conf->global->$const_key = $color;
    }

    return $color;
}

/**
 * Calcula el color de texto (blanco o negro) en función del color de fondo,
 * para garantizar contraste legible.
 *
 * @param  string $hex_color  Color de fondo en formato hex (#RRGGBB)
 * @return string             '#ffffff' o '#000000'
 */
function cabinetmed_calendar_get_text_color($hex_color)
{
    $hex = ltrim($hex_color, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // Luminancia relativa (fórmula W3C)
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.5 ? '#000000' : '#ffffff';
}

/**
 * Calcula el color de borde (versión más oscura) a partir del color de fondo.
 *
 * @param  string $hex_color  Color en formato hex (#RRGGBB)
 * @param  int    $amount     Cantidad a oscurecer (0-255)
 * @return string             Color oscurecido en hex
 */
function cabinetmed_calendar_darken_color($hex_color, $amount = 30)
{
    $hex = ltrim($hex_color, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = max(0, hexdec(substr($hex, 0, 2)) - $amount);
    $g = max(0, hexdec(substr($hex, 2, 2)) - $amount);
    $b = max(0, hexdec(substr($hex, 4, 2)) - $amount);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Valida si un string es un color hexadecimal válido.
 *
 * @param  string $color  String a validar
 * @return bool           True si es un hex válido (#RGB o #RRGGBB)
 */
function cabinetmed_calendar_is_valid_hex_color($color)
{
    return (bool)preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color);
}

/**
 * Obtiene la lista de gestores que tienen consultas asignadas en la entidad actual.
 * Usado para poblar el filtro de gestores en el calendario.
 *
 * @param  DoliDB $db  Objeto de base de datos
 * @return array       Array de objetos con rowid, firstname, lastname
 */
function cabinetmed_calendar_get_gestores($db)
{
    $sql  = "SELECT DISTINCT u.rowid, u.firstname, u.lastname, u.login";
    $sql .= " FROM " . MAIN_DB_PREFIX . "user u";
    $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "cabinetmed_extcons_users cu ON cu.fk_user = u.rowid";
    $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "cabinetmed_extcons c ON c.rowid = cu.fk_extcons";
    $sql .= " WHERE c.entity IN (" . getEntity('consultation') . ")";
    $sql .= "   AND u.statut = 1";
    $sql .= " ORDER BY u.lastname ASC, u.firstname ASC";

    $gestores = array();
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $gestores[] = $obj;
        }
        $db->free($resql);
    }
    return $gestores;
}

/**
 * Obtiene los tipos de atención configurados en la entidad actual.
 * Usado para poblar el filtro de tipos en el calendario.
 *
 * @param  DoliDB $db  Objeto de base de datos
 * @return array       Array de objetos con code, label, color
 */
function cabinetmed_calendar_get_tipos_atencion($db)
{
    $sql  = "SELECT t.rowid, t.code, t.label";
    $sql .= " FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons_types t";
    $sql .= " WHERE t.entity IN (" . getEntity('consultation') . ")";
    $sql .= "   AND t.active = 1";
    $sql .= " ORDER BY t.label ASC";

    $tipos = array();
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $tipos[] = $obj;
        }
        $db->free($resql);
    }
    return $tipos;
}

/**
 * Calcula los festivos colombianos para un año dado.
 * Incluye festivos fijos, festivos de "puente" (trasladados al lunes) y
 * los festivos móviles basados en Semana Santa (algoritmo de Meeus/Jones/Butcher).
 *
 * @param  int   $year  Año (ej: 2026)
 * @return array        Array de strings 'YYYY-MM-DD'
 */
function cabinetmed_calendar_get_holidays($year)
{
    $y = (int)$year;

    // ── Calcular Pascua (Domingo de Resurrección) ──────────────────────────
    // Algoritmo Meeus/Jones/Butcher — funciona para cualquier año gregoriano
    $a = $y % 19;
    $b = intdiv($y, 100);
    $c = $y % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month  = intdiv($h + $l - 7 * $m + 114, 31);
    $day    = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easter = mktime(0, 0, 0, $month, $day, $y); // Domingo de Pascua

    // ── Helper: próximo lunes a partir de una fecha ────────────────────────
    // "Puente festivo": si el festivo NO cae en lunes, se traslada al siguiente lunes
    $next_monday = function ($ts) {
        $dow = (int)date('N', $ts); // 1=lun … 7=dom
        if ($dow === 1) return $ts;
        return $ts + (8 - $dow) * 86400;
    };

    // ── Helper: crear timestamp ────────────────────────────────────────────
    $d = function ($m, $day) use ($y) {
        return mktime(0, 0, 0, $m, $day, $y);
    };

    $holidays = array();
    $fmt = function ($ts) { return date('Y-m-d', $ts); };

    // ── Festivos fijos (nunca se trasladan) ────────────────────────────────
    $holidays[] = $fmt($d(1,  1));   // Año Nuevo
    $holidays[] = $fmt($d(5,  1));   // Día del Trabajo
    $holidays[] = $fmt($d(7, 20));   // Independencia de Colombia
    $holidays[] = $fmt($d(8,  7));   // Batalla de Boyacá
    $holidays[] = $fmt($d(12, 8));   // Inmaculada Concepción
    $holidays[] = $fmt($d(12, 25));  // Navidad

    // ── Festivos de puente (se trasladan al próximo lunes) ─────────────────
    $holidays[] = $fmt($next_monday($d(1,  6)));   // Reyes Magos
    $holidays[] = $fmt($next_monday($d(3, 19)));   // San José
    $holidays[] = $fmt($next_monday($d(6, 29)));   // San Pedro y San Pablo
    $holidays[] = $fmt($next_monday($d(8, 15)));   // Asunción de la Virgen
    $holidays[] = $fmt($next_monday($d(10, 12)));  // Día de la Raza
    $holidays[] = $fmt($next_monday($d(11,  1)));  // Todos los Santos
    $holidays[] = $fmt($next_monday($d(11, 11)));  // Independencia de Cartagena

    // ── Festivos basados en Pascua ─────────────────────────────────────────
    $holidays[] = $fmt($easter - 3 * 86400);                     // Jueves Santo
    $holidays[] = $fmt($easter - 2 * 86400);                     // Viernes Santo
    $holidays[] = $fmt($next_monday($easter + 39 * 86400));      // Ascensión del Señor
    $holidays[] = $fmt($next_monday($easter + 60 * 86400));      // Corpus Christi
    $holidays[] = $fmt($next_monday($easter + 68 * 86400));      // Sagrado Corazón

    sort($holidays);
    return array_values(array_unique($holidays));
}

/**
 * Genera un color de fondo pastel determinístico para un usuario dado su ID.
 * Siempre retorna el mismo color para el mismo usuario.
 *
 * @param  int    $fk_user  ID del usuario
 * @return string           Color hex en formato #RRGGBB
 */
function cabinetmed_calendar_get_user_color($fk_user)
{
    if ($fk_user <= 0) return '#F5F5F5';

    // Paleta de colores pastel suaves — legibles con texto oscuro
    $palette = array(
        '#E3F2FD', // azul hielo
        '#FCE4EC', // rosa suave
        '#E8F5E9', // verde menta
        '#FFF8E1', // amarillo crema
        '#F3E5F5', // lavanda
        '#E0F7FA', // cian suave
        '#FBE9E7', // salmón suave
        '#F1F8E9', // lima suave
        '#EDE7F6', // violeta suave
        '#E0F2F1', // teal suave
        '#FFF3E0', // naranja suave
        '#FAFAFA', // gris muy claro
    );

    return $palette[$fk_user % count($palette)];
}

/**
 * Retorna el array de estados de consulta disponibles.
 *
 * @return array  Asociativo [código => label]
 */
function cabinetmed_calendar_get_status_array()
{
    global $langs;
    $langs->load('cabinetmed_calendar');
    return array(
        0 => $langs->trans('StatusInProgress'),
        1 => $langs->trans('StatusCompleted'),
        2 => $langs->trans('StatusCancelled'),
    );
}
