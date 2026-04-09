<?php
require '../../../main.inc.php';
if (!$user->hasRight('gestion', 'read')) accessforbidden();
$langs->load("gestion@gestion");

$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'd.codigo';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST('page', 'int');
$limit = GETPOST('limit', 'int') ?: $conf->liste_limit;
if (empty($page) || $page < 0) $page = 0;

$search_codigo = GETPOST('search_codigo', 'alpha');
$search_label = GETPOST('search_label', 'alpha');

$sql = "SELECT d.rowid, d.codigo, d.label, d.datec FROM ".MAIN_DB_PREFIX."gestion_diagnostico as d WHERE d.entity = ".$conf->entity;
if ($search_codigo) $sql .= natural_search('d.codigo', $search_codigo);
if ($search_label) $sql .= natural_search('d.label', $search_label);

$sqlcount = preg_replace('/SELECT.*FROM/', 'SELECT COUNT(*) as total FROM', $sql);
$totalcount = ($rc = $db->query($sqlcount)) ? $db->fetch_object($rc)->total : 0;
$sql .= $db->order($sortfield, $sortorder).$db->plimit($limit + 1, $limit * $page);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;

llxHeader('', 'Diagnósticos', '');
$param = ($search_codigo ? '&search_codigo='.urlencode($search_codigo) : '').($search_label ? '&search_label='.urlencode($search_label) : '');
$newbtn = $user->hasRight('gestion', 'write') ? '<a class="butActionNew" href="card.php?action=create"><span class="fa fa-plus-circle"></span></a>' : '';

print load_fiche_titre('Diagnósticos (CIE-10)', $newbtn, 'generic');
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'">';
print '<div class="div-table-responsive-no-min"><table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter"><td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_codigo" value="'.dol_escape_htmltag($search_codigo).'"></td><td class="liste_titre"><input class="flat maxwidth200" type="text" name="search_label" value="'.dol_escape_htmltag($search_label).'"></td><td class="liste_titre"></td><td class="liste_titre center"><input type="image" src="'.img_picto('','search.png','',0,1).'" name="button_search"></td></tr>';
print '<tr class="liste_titre">';
print_liste_field_titre("Código", $_SERVER["PHP_SELF"], "d.codigo", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Nombre", $_SERVER["PHP_SELF"], "d.label", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "d.datec", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("", $_SERVER["PHP_SELF"], "", "", $param, 'class="center"', $sortfield, $sortorder);
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);
    print '<tr class="oddeven"><td><a href="card.php?id='.$obj->rowid.'">'.dol_escape_htmltag($obj->codigo).'</a></td><td>'.dol_escape_htmltag($obj->label).'</td><td>'.dol_print_date($db->jdate($obj->datec), 'day').'</td><td class="center nowraponall">';
    if ($user->hasRight('gestion', 'write')) print '<a class="paddingright" href="card.php?id='.$obj->rowid.'&action=edit">'.img_edit().'</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a href="card.php?id='.$obj->rowid.'&action=delete&token='.newToken().'">'.img_delete().'</a>';
    print '</td></tr>'; $i++;
}
if ($num == 0) print '<tr class="oddeven"><td colspan="4" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
print '</table></div>';
print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $totalcount, '', 0, '', '', $limit);
print '</form>';
llxFooter(); $db->close();
