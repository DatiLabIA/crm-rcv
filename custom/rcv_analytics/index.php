<?php
/* Copyright (C) 2024 DatiLab
 * Dashboard principal de analíticas RCV
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

$form = new Form($db);
$engine = new RcvAnalyticsEngine($db);

// Leer filtros del formulario
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
    $filters['tipo_atencion']      = GETPOST('filter_tipo_atencion', 'alpha');
    $filters['programa']           = GETPOST('filter_programa', 'alpha');
    $filters['diagnostico']        = GETPOST('filter_diagnostico', 'alpha');
    $filters['ips_primaria']       = GETPOST('filter_ips_primaria', 'alpha');
    $filters['estado_del_paciente']= GETPOST('filter_estado_del_paciente', 'alpha');
    $filters['groupby']            = GETPOST('filter_groupby', 'alpha') ?: 'month';
}

$engine->setFilters(array_filter($filters, function ($v) { return $v !== '' && $v !== null; }));

$groupBy = $filters['groupby'] ?? 'month';

// Obtener datos
$kpis              = $engine->getKpis();
$patientsOverTime  = $engine->getPatientsOverTime($groupBy);
$consByTipo        = $engine->getConsultationsByTipoAtencion();
$consOverTime      = $engine->getConsultationsOverTime($groupBy);
$adherencia        = $engine->getAdherenciaDistribution();
$epsDist           = $engine->getPatientsByEps();
$medDist           = $engine->getPatientsByMedicamento();
$opDist            = $engine->getPatientsByOperador();

// Poblado de selects de filtros
$optMedicamentos    = $engine->getUniqueFieldValues('medicamento');
$optEps             = $engine->getUniqueFieldValues('eps');
$optOperadores      = $engine->getUniqueFieldValues('operador_logistico');
$optTipoPoblacion   = $engine->getUniqueFieldValues('tipo_de_poblacion');
$optProgramas       = $engine->getUniqueFieldValues('programa');
$optDiagnosticos    = $engine->getUniqueFieldValues('diagnostico');
$optIps             = $engine->getUniqueFieldValues('ips_primaria');
$optEstados         = $engine->getUniqueFieldValues('estado_del_paciente');
$optTiposAtencion   = $engine->getUniqueTiposAtencion();

// Head
llxHeader('', $langs->trans('RcvAnalyticsDashboard'), '', '', 0, 0, array('/includes/nnnick/chartjs/dist/chart.min.js', '/rcv_analytics/js/charts.min.js'), array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'dashboard', $langs->trans('Analiticas'), -1, 'stats');
rcv_print_inline_styles();

// ─── Filtros responsive ─────────────────────────────────────────────────
print '<form method="GET" action="'.dol_buildpath('/rcv_analytics/index.php', 1).'" id="rcv-filter-form">';
print '<div class="rcv-filters">';
print '<div class="rcv-filter-dates">';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaDesde').'</label>'
    .$form->selectDate($_date_start_ts ?: -1, 'filter_date_start', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaHasta').'</label>'
    .$form->selectDate($_date_end_ts ?: -1, 'filter_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('AgruparPor').'</label>';
print '<select name="filter_groupby" class="flat">';
foreach (array('month' => 'Mes', 'week' => 'Semana', 'year' => 'Año') as $val => $label) {
    $sel = ($groupBy === $val) ? ' selected' : '';
    print '<option value="'.$val.'"'.$sel.'>'.$label.'</option>';
}
print '</select></div></div>';
print '<div class="rcv-filter-grid">';
rcv_print_filter_select('filter_eps',                $langs->trans('EPS'),               $optEps,          $filters['eps'] ?? '');
rcv_print_filter_select('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos, $filters['medicamento'] ?? '');
rcv_print_filter_select('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,   $filters['operador_logistico'] ?? '');
rcv_print_filter_select('filter_programa',           $langs->trans('Programa'),          $optProgramas,    $filters['programa'] ?? '');
rcv_print_filter_select('filter_tipo_de_poblacion',  $langs->trans('TipoPoblacion'),     $optTipoPoblacion,$filters['tipo_de_poblacion'] ?? '');
rcv_print_filter_select('filter_diagnostico',        $langs->trans('Diagnostico'),       $optDiagnosticos, $filters['diagnostico'] ?? '');
rcv_print_filter_select('filter_ips_primaria',       $langs->trans('IPSPrimaria'),       $optIps,          $filters['ips_primaria'] ?? '');
rcv_print_filter_select('filter_estado_del_paciente',$langs->trans('EstadoPaciente'),    $optEstados,      $filters['estado_del_paciente'] ?? '');
print '</div>';
print '<div class="rcv-filter-actions">';
print '<input type="submit" class="butAction" name="button_search" value="'.$langs->trans('Filtrar').'">';
print '<input type="submit" class="butActionDelete" name="button_removefilter" value="'.$langs->trans('LimpiarFiltros').'">';
print '</div></div></form>';

// ─── KPI Cards ─────────────────────────────────────────────────────────────
print '<div class="rcv-kpi-row">';
rcv_kpi_card($langs->trans('TotalPacientes'), $kpis['total_pacientes'], 'user', '#2563eb');
rcv_kpi_card($langs->trans('TotalConsultas'), $kpis['total_consultas'], 'action', '#16a34a');
rcv_kpi_card($langs->trans('PacientesConConsulta'), $kpis['pacientes_con_consulta'], 'user', '#9333ea');
rcv_kpi_card($langs->trans('TotalAdherencias'), $kpis['total_adherencias'], 'bill', '#ea580c');
rcv_kpi_card($langs->trans('PctCumplimiento'), $kpis['pct_cumplimiento'].'%', 'check', '#0891b2');
print '</div>';

// ─── Gráficas Fila 1 ───────────────────────────────────────────────────────
print '<div class="rcv-charts-row">';

// Pacientes creados en el tiempo
print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('NuevosPacientesPorPeriodo').'</h3>';
print '<canvas id="chartPatientsTime"></canvas>';
print '</div>';

// Consultas en el tiempo
print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('ConsultasPorPeriodo').'</h3>';
print '<canvas id="chartConsTime"></canvas>';
print '</div>';

print '</div>';

// ─── Gráficas Fila 2 ───────────────────────────────────────────────────────
print '<div class="rcv-charts-row">';

// Distribución por tipo de atención
print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('ConsultasPorTipoAtencion').'</h3>';
print '<canvas id="chartConsTipo"></canvas>';
print '</div>';

// Adherencia
print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('DistribucionAdherencia').'</h3>';
print '<canvas id="chartAdherencia"></canvas>';
print '</div>';

print '</div>';

// ─── Gráficas Fila 3 ───────────────────────────────────────────────────────
print '<div class="rcv-charts-row">';

print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('PacientesPorEPS').'</h3>';
print '<canvas id="chartEps"></canvas>';
print '</div>';

print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('PacientesPorMedicamento').'</h3>';
print '<canvas id="chartMed"></canvas>';
print '</div>';

print '</div>';

print '<div class="rcv-charts-row">';

print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('PacientesPorOperador').'</h3>';
print '<canvas id="chartOp"></canvas>';
print '</div>';

print '<div class="rcv-chart-box">';
print '<h3>'.$langs->trans('AccionesDeLista').'</h3>';
print '<div class="rcv-quick-links">';
$qs = (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING'] : '');
print '<a class="butAction" href="'.dol_buildpath('/rcv_analytics/patients.php', 1).$qs.'">'.$langs->trans('VerTablasPacientes').'</a> ';
print '<a class="butAction" href="'.dol_buildpath('/rcv_analytics/consultations.php', 1).$qs.'">'.$langs->trans('VerTablasConsultas').'</a> ';
print '<a class="butAction" href="'.dol_buildpath('/rcv_analytics/adherencia.php', 1).$qs.'">'.$langs->trans('AdherenciaDetallada').'</a> ';
print '<a class="butActionRefused" href="'.dol_buildpath('/rcv_analytics/export.php', 1).$qs.'">'.$langs->trans('ExportarCSV').'</a>';
print '</div>';
print '</div>';

print '</div>';

// ─── JavaScript datos para Chart.js ────────────────────────────────────────
$dataPatientsTime  = rcv_chart_data($patientsOverTime, 'periodo', 'total');
$dataConsTime      = rcv_chart_data($consOverTime, 'periodo', 'total');
$dataConsTipo      = rcv_chart_data($consByTipo, 'tipo', 'total');
$dataAdherencia    = rcv_chart_data($adherencia, 'cumplimiento', 'total');
$dataEps           = rcv_chart_data($epsDist, 'categoria', 'total');
$dataMed           = rcv_chart_data($medDist, 'categoria', 'total');
$dataOp            = rcv_chart_data($opDist, 'categoria', 'total');

print '<script>
document.addEventListener("DOMContentLoaded", function() {
    rcvRenderLineChart("chartPatientsTime", '.json_encode($dataPatientsTime).', "Nuevos Pacientes");
    rcvRenderBarChart("chartConsTime", '.json_encode($dataConsTime).', "Consultas");
    rcvRenderDoughnutChart("chartConsTipo", '.json_encode($dataConsTipo).');
    rcvRenderDoughnutChart("chartAdherencia", '.json_encode($dataAdherencia).');
    rcvRenderBarChart("chartEps", '.json_encode($dataEps).', "Pacientes");
    rcvRenderBarChart("chartMed", '.json_encode($dataMed).', "Pacientes");
    rcvRenderBarChart("chartOp", '.json_encode($dataOp).', "Pacientes");
});
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
