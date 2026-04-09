<?php
require '../../../main.inc.php';
if (!$user->hasRight('gestion', 'read')) accessforbidden();

$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'm.nombre';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST('page', 'int');
$limit = GETPOST('limit', 'int') ?: $conf->liste_limit;
if (empty($page) || $page < 0) $page = 0;

$search_nombre = GETPOST('search_nombre', 'alpha');
$search_identificacion = GETPOST('search_identificacion', 'alpha');
$search_ciudad = GETPOST('search_ciudad', 'alpha');

$sql = "SELECT m.rowid, m.nombre, m.tipo_doc, m.numero_identificacion, m.tarjeta_profesional, m.ciudades, m.departamentos, m.especialidades,";
$sql .= " (SELECT GROUP_CONCAT(e.descripcion ORDER BY e.descripcion SEPARATOR ', ') FROM ".MAIN_DB_PREFIX."gestion_medico_eps me LEFT JOIN ".MAIN_DB_PREFIX."gestion_eps e ON me.fk_eps = e.rowid WHERE me.fk_medico = m.rowid) as eps_nombres";
$sql .= " FROM ".MAIN_DB_PREFIX."gestion_medico as m";
$sql .= " WHERE m.entity = ".$conf->entity;
if ($search_nombre) $sql .= natural_search('m.nombre', $search_nombre);
if ($search_identificacion) $sql .= natural_search('m.numero_identificacion', $search_identificacion);
if ($search_ciudad) $sql .= natural_search('m.ciudades', $search_ciudad);

$sqlcount = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."gestion_medico as m WHERE m.entity = ".$conf->entity;
if ($search_nombre) $sqlcount .= natural_search('m.nombre', $search_nombre);
if ($search_identificacion) $sqlcount .= natural_search('m.numero_identificacion', $search_identificacion);
if ($search_ciudad) $sqlcount .= natural_search('m.ciudades', $search_ciudad);
$totalcount = ($rc = $db->query($sqlcount)) ? $db->fetch_object($rc)->total : 0;

$sql .= $db->order($sortfield, $sortorder).$db->plimit($limit + 1, $limit * $page);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;

llxHeader('', 'Médicos', '');
$param = ($search_nombre ? '&search_nombre='.urlencode($search_nombre) : '').($search_identificacion ? '&search_identificacion='.urlencode($search_identificacion) : '').($search_ciudad ? '&search_ciudad='.urlencode($search_ciudad) : '');
$newbtn = $user->hasRight('gestion', 'write') ? '<a class="butActionNew" href="card.php?action=create"><span class="fa fa-plus-circle"></span></a>' : '';

print load_fiche_titre('Médicos', $newbtn, 'generic');
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'">';
print '<div class="div-table-responsive-no-min"><table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input class="flat maxwidth150" type="text" name="search_nombre" value="'.dol_escape_htmltag($search_nombre).'"></td>';
print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_identificacion" value="'.dol_escape_htmltag($search_identificacion).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_ciudad" value="'.dol_escape_htmltag($search_ciudad).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center"><input type="image" src="'.img_picto('','search.png','',0,1).'" name="button_search"></td>';
print '</tr>';
print '<tr class="liste_titre">';
print_liste_field_titre("Nombre", $_SERVER["PHP_SELF"], "m.nombre", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Identificación", $_SERVER["PHP_SELF"], "m.numero_identificacion", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Tarjeta Prof.", $_SERVER["PHP_SELF"], "m.tarjeta_profesional", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Ciudad(es)", $_SERVER["PHP_SELF"], "", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("EPS", $_SERVER["PHP_SELF"], "", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Especialidad(es)", $_SERVER["PHP_SELF"], "", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("", $_SERVER["PHP_SELF"], "", "", $param, 'class="center"', $sortfield, $sortorder);
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);

    // Decodificar JSON
    $ciudades = !empty($obj->ciudades) ? json_decode($obj->ciudades, true) : array();
    $especialidades = !empty($obj->especialidades) ? json_decode($obj->especialidades, true) : array();
    if (!is_array($ciudades)) $ciudades = array();
    if (!is_array($especialidades)) $especialidades = array();

    print '<tr class="oddeven">';
    print '<td><a href="card.php?id='.$obj->rowid.'">'.dol_escape_htmltag($obj->nombre).'</a></td>';
    print '<td>'.dol_escape_htmltag($obj->tipo_doc.' '.$obj->numero_identificacion).'</td>';
    print '<td>'.dol_escape_htmltag($obj->tarjeta_profesional).'</td>';
    print '<td class="tdoverflowmax200">'.dol_escape_htmltag(implode(', ', $ciudades)).'</td>';
    print '<td class="tdoverflowmax200">'.dol_escape_htmltag($obj->eps_nombres).'</td>';
    print '<td class="tdoverflowmax200">'.dol_escape_htmltag(implode(', ', $especialidades)).'</td>';
    print '<td class="center nowraponall">';
    if ($user->hasRight('gestion', 'write')) print '<a class="paddingright" href="card.php?id='.$obj->rowid.'&action=edit">'.img_edit().'</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a href="card.php?id='.$obj->rowid.'&action=delete&token='.newToken().'">'.img_delete().'</a>';
    print '</td></tr>';
    $i++;
}
if ($num == 0) print '<tr class="oddeven"><td colspan="7" class="opacitymedium">No hay registros</td></tr>';
print '</table></div>';
print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $totalcount, '', 0, '', '', $limit);
print '</form>';
llxFooter(); $db->close();
