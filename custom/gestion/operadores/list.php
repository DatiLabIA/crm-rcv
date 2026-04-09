<?php
require '../../../main.inc.php';
if (!$user->hasRight('gestion', 'read')) accessforbidden();

$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'o.nombre';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST('page', 'int');
$limit = GETPOST('limit', 'int') ?: $conf->liste_limit;
if (empty($page) || $page < 0) $page = 0;

$search_nombre = GETPOST('search_nombre', 'alpha');
$sql = "SELECT o.rowid, o.nombre, o.datec FROM ".MAIN_DB_PREFIX."gestion_operador as o WHERE o.entity = ".$conf->entity;
if ($search_nombre) $sql .= natural_search('o.nombre', $search_nombre);

$sqlcount = preg_replace('/SELECT.*FROM/', 'SELECT COUNT(*) as total FROM', $sql);
$totalcount = ($rc = $db->query($sqlcount)) ? $db->fetch_object($rc)->total : 0;
$sql .= $db->order($sortfield, $sortorder).$db->plimit($limit + 1, $limit * $page);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;

llxHeader('', 'Operadores', '');
$param = $search_nombre ? '&search_nombre='.urlencode($search_nombre) : '';
$newbtn = $user->hasRight('gestion', 'write') ? '<a class="butActionNew" href="card.php?action=create"><span class="fa fa-plus-circle"></span></a>' : '';

print load_fiche_titre('Operadores', $newbtn, 'generic');
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'">';
print '<div class="div-table-responsive-no-min"><table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter"><td class="liste_titre"><input class="flat maxwidth200" type="text" name="search_nombre" value="'.dol_escape_htmltag($search_nombre).'"></td><td class="liste_titre"></td><td class="liste_titre center"><input type="image" src="'.img_picto('','search.png','',0,1).'" name="button_search"></td></tr>';
print '<tr class="liste_titre">';
print_liste_field_titre("Nombre", $_SERVER["PHP_SELF"], "o.nombre", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "o.datec", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("", $_SERVER["PHP_SELF"], "", "", $param, 'class="center"', $sortfield, $sortorder);
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);
    print '<tr class="oddeven"><td><a href="card.php?id='.$obj->rowid.'">'.dol_escape_htmltag($obj->nombre).'</a></td><td>'.dol_print_date($db->jdate($obj->datec), 'day').'</td><td class="center nowraponall">';
    if ($user->hasRight('gestion', 'write')) print '<a class="paddingright" href="card.php?id='.$obj->rowid.'&action=edit">'.img_edit().'</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a href="card.php?id='.$obj->rowid.'&action=delete&token='.newToken().'">'.img_delete().'</a>';
    print '</td></tr>'; $i++;
}
if ($num == 0) print '<tr class="oddeven"><td colspan="3" class="opacitymedium">No hay registros</td></tr>';
print '</table></div>';
print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $totalcount, '', 0, '', '', $limit);
print '</form>';
llxFooter(); $db->close();
