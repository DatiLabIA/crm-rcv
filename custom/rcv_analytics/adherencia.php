<?php
/* Copyright (C) 2024 DatiLab
 * Página: analíticas de adherencia
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/rcv_analytics/class/rcvanalyticsengine.class.php');
dol_include_once('/rcv_analytics/lib/rcv_analytics.lib.php');

$langs->loadLangs(array("companies", "rcv_analytics@rcv_analytics"));

if (!$user->admin && !$user->hasRight('rcv_analytics', 'read')) accessforbidden();

$form   = new Form($db);
$engine = new RcvAnalyticsEngine($db);

$button_removefilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha');

$_date_start_ts = $button_removefilter ? 0 : dol_mktime(0, 0, 0, GETPOSTINT('filter_date_startmonth'), GETPOSTINT('filter_date_startday'), GETPOSTINT('filter_date_startyear'));
$_date_end_ts   = $button_removefilter ? 0 : dol_mktime(23, 59, 59, GETPOSTINT('filter_date_endmonth'), GETPOSTINT('filter_date_endday'), GETPOSTINT('filter_date_endyear'));

$filters = array();
if (!$button_removefilter) {
    $filters['date_start']         = $_date_start_ts ? dol_print_date($_date_start_ts, 'dayrfc') : '';
    $filters['date_end']           = $_date_end_ts   ? dol_print_date($_date_end_ts,   'dayrfc') : '';
    $filters['medicamento']        = GETPOST('filter_medicamento', 'alpha');
    $filters['eps']                = GETPOST('filter_eps', 'alpha');
    $filters['operador_logistico'] = GETPOST('filter_operador_logistico', 'alpha');
    $filters['tipo_de_poblacion']  = GETPOST('filter_tipo_de_poblacion', 'alpha');
    $filters['programa']           = GETPOST('filter_programa', 'alpha');
    $filters['diagnostico']        = GETPOST('filter_diagnostico', 'alpha');
    $filters['ips_primaria']       = GETPOST('filter_ips_primaria', 'alpha');
    $filters['estado_del_paciente']= GETPOST('filter_estado_del_paciente', 'alpha');
    $filters['groupby']            = GETPOST('filter_groupby', 'alpha') ?: 'month';
}
$groupBy = $filters['groupby'] ?? 'month';

$cleanFilters = array_filter($filters, function ($v) { return $v !== '' && $v !== null; });
// Forzar tipo_atencion = adherencia para esta página
$cleanFilters['tipo_atencion'] = 'adherencia';
$engine->setFilters($cleanFilters);

$adherenciaDist    = $engine->getAdherenciaDistribution();
$razonesInc        = $engine->getRazonIncumplimiento();
$adherenciaTime    = $engine->getConsultationsOverTime($groupBy);
$crossEps          = $engine->getConsultationsCrossTable('eps', $groupBy);

// Cross table: cumplimiento × eps
$cleanFilters2 = $cleanFilters;
$engine->setFilters($cleanFilters2);
$crossCumplEps = $engine->getConsultationsCrossTable('eps', 'month');

$kpis = $engine->getKpis();

$optMedicamentos = $engine->getUniqueFieldValues('medicamento');
$optEps          = $engine->getUniqueFieldValues('eps');
$optOperadores   = $engine->getUniqueFieldValues('operador_logistico');
$optProgramas    = $engine->getUniqueFieldValues('programa');

llxHeader('', $langs->trans('AdherenciaAnaliticas'), '', '', 0, 0, array('/includes/nnnick/chartjs/dist/chart.min.js', '/rcv_analytics/js/charts.min.js'), array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'adherencia', $langs->trans('Analiticas'), -1, 'bill');
rcv_print_inline_styles();

// ─── Filtros responsive ─────────────────────────────────────────────────
print '<form method="GET" action="'.dol_buildpath('/rcv_analytics/adherencia.php', 1).'">';
print '<div class="rcv-filters">';
print '<div class="rcv-filter-dates">';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaDesde').'</label>'
    .$form->selectDate($_date_start_ts ?: -1, 'filter_date_start', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaHasta').'</label>'
    .$form->selectDate($_date_end_ts ?: -1, 'filter_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('AgruparPor').'</label>';
print '<select name="filter_groupby" class="flat">';
foreach (array('month' => 'Mes', 'week' => 'Semana', 'year' => 'Año') as $val => $lbl) {
    $sel = ($groupBy === $val) ? ' selected' : '';
    print '<option value="'.$val.'"'.$sel.'>'.$lbl.'</option>';
}
print '</select></div>';
print '</div>';
print '<div class="rcv-filter-grid">';
rcv_print_filter_select('filter_eps',                $langs->trans('EPS'),               $optEps,          $filters['eps'] ?? '');
rcv_print_filter_select('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos, $filters['medicamento'] ?? '');
rcv_print_filter_select('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,   $filters['operador_logistico'] ?? '');
rcv_print_filter_select('filter_programa',           $langs->trans('Programa'),          $optProgramas,    $filters['programa'] ?? '');
print '</div>';
print '<div class="rcv-filter-actions">';
print '<input type="submit" class="butAction" name="button_search" value="'.$langs->trans('Filtrar').'">';
print '<input type="submit" class="butActionDelete" name="button_removefilter" value="'.$langs->trans('LimpiarFiltros').'">';
print '</div></div></form>';

// ─── KPIs ──────────────────────────────────────────────────────────────────
print '<div class="rcv-kpi-row">';
rcv_kpi_card($langs->trans('TotalAdherencias'), $kpis['total_adherencias'], 'action', '#ea580c');
rcv_kpi_card($langs->trans('PacientesConConsulta'), $kpis['pacientes_con_consulta'], 'user', '#2563eb');
rcv_kpi_card($langs->trans('PctCumplimiento'), $kpis['pct_cumplimiento'].'%', 'check', '#16a34a');
print '</div>';

// ─── Gráficas ──────────────────────────────────────────────────────────────
print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('DistribucionAdherencia').'</h3><canvas id="chartAdh"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('AdherenciaEnElTiempo').'</h3><canvas id="chartAdhTime"></canvas></div>';
print '</div>';

print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('RazonesIncumplimiento').'</h3><canvas id="chartRazon"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('AdherenciaPorEPS').'</h3>';

// Tabla cruzada cumplimiento × EPS
if (!empty($crossEps)) {
    // Construir matrix
    $epsSet     = array();
    $periodoSet = array();
    $matrix     = array();
    foreach ($crossEps as $row) {
        $epsSet[$row['categoria']] = true;
        $periodoSet[$row['periodo']] = true;
        $matrix[$row['periodo']][$row['categoria']] = (int) $row['total'];
    }
    $epsList     = array_keys($epsSet);
    $periodoList = array_keys($periodoSet);

    print '<div style="overflow-x:auto"><table class="noborder" style="font-size:.82em">';
    print '<tr class="liste_titre"><th>Período</th>';
    foreach ($epsList as $ep) {
        print '<th>'.dol_escape_htmltag($ep).'</th>';
    }
    print '</tr>';
    foreach ($periodoList as $periodo) {
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($periodo).'</td>';
        foreach ($epsList as $ep) {
            $val = isset($matrix[$periodo][$ep]) ? $matrix[$periodo][$ep] : 0;
            print '<td style="text-align:center">'.$val.'</td>';
        }
        print '</tr>';
    }
    print '</table></div>';
}
print '</div>';
print '</div>';

// ─── Tabla de razones de incumplimiento ───────────────────────────────────
if (!empty($razonesInc)) {
    print '<h4>'.$langs->trans('RazonesIncumplimientoDetalle').'</h4>';
    print '<table class="noborder centpercent liste">';
    print '<tr class="liste_titre"><th>'.$langs->trans('Razon').'</th><th style="text-align:right">'.$langs->trans('Total').'</th></tr>';
    foreach ($razonesInc as $row) {
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($row['razon']).'</td>';
        print '<td style="text-align:right">'.(int) $row['total'].'</td>';
        print '</tr>';
    }
    print '</table>';
}

// Charts
$dataAdh     = rcv_chart_data($adherenciaDist, 'cumplimiento', 'total');
$dataAdhTime = rcv_chart_data($adherenciaTime, 'periodo', 'total');
$dataRazon   = rcv_chart_data($razonesInc, 'razon', 'total');

print '<script>
document.addEventListener("DOMContentLoaded", function() {
    rcvRenderDoughnutChart("chartAdh", '.json_encode($dataAdh).');
    rcvRenderLineChart("chartAdhTime", '.json_encode($dataAdhTime).', "Adherencias");
    rcvRenderBarChart("chartRazon", '.json_encode($dataRazon).', "Razones");
});
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
