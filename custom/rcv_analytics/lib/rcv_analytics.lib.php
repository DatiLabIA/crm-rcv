<?php
/* Copyright (C) 2024 DatiLab
 * Funciones helper para el módulo RCV Analytics
 */

/**
 * Prepara los tabs para las páginas del módulo
 */
function rcv_analytics_prepare_head()
{
    global $langs, $conf;

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/rcv_analytics/index.php', 1);
    $head[$h][1] = $langs->trans('Dashboard');
    $head[$h][2] = 'dashboard';
    $h++;

    $head[$h][0] = dol_buildpath('/rcv_analytics/patients.php', 1);
    $head[$h][1] = $langs->trans('Pacientes');
    $head[$h][2] = 'patients';
    $h++;

    $head[$h][0] = dol_buildpath('/rcv_analytics/consultations.php', 1);
    $head[$h][1] = $langs->trans('Consultas');
    $head[$h][2] = 'consultations';
    $h++;

    $head[$h][0] = dol_buildpath('/rcv_analytics/export.php', 1);
    $head[$h][1] = $langs->trans('Exportar');
    $head[$h][2] = 'export';
    $h++;

    return $head;
}

/**
 * Inyecta estilos críticos inline (garantiza que funcionan aunque el .css no cargue)
 * Usa static para imprimir sólo una vez por request.
 */
function rcv_print_inline_styles()
{
    static $printed = false;
    if ($printed) return;
    $printed = true;
    print '<style>
/* ── RCV Analytics – estilos inline v2 ──────────────────────────── */

/* Anclar la columna de contenido de Dolibarr al viewport */
#id-right{max-width:calc(100vw - 290px)!important;overflow-x:hidden!important}

/* Contenedor raíz */
.rcv-wrap{width:100%;max-width:100%;box-sizing:border-box;min-width:0;overflow-x:hidden}

/* ── Filtros ── */
.rcv-filters{margin:0 0 14px;background:#f8fafc;border:1px solid #dde8ef;border-radius:6px;padding:10px 14px;box-sizing:border-box;width:100%;overflow:hidden}
.rcv-filter-dates{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:6px 12px;margin-bottom:8px;width:100%;overflow:hidden}
.rcv-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:6px 12px;margin-bottom:8px;width:100%;overflow:hidden}
.rcv-filter-item{display:flex;flex-direction:column;gap:2px;min-width:0;overflow:hidden}
.rcv-filter-item label{font-size:.75em;font-weight:600;color:#4a6070;text-transform:uppercase;letter-spacing:.04em;margin-bottom:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rcv-filter-item select,.rcv-filter-item input[type="text"]{width:100%;box-sizing:border-box;min-width:0}
.rcv-filter-actions{display:flex;gap:8px;flex-wrap:wrap;padding-top:6px;border-top:1px solid #dde8ef;margin-top:4px}

/* ── KPIs – grid ── */
.rcv-kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:10px 0;width:100%;overflow:hidden}
.rcv-kpi-card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:12px 16px;min-width:0;display:flex;flex-direction:column;overflow:hidden}
.rcv-kpi-value{font-size:1.75em;font-weight:700;color:#1e293b;line-height:1.1}
.rcv-kpi-label{font-size:.78em;color:#64748b;margin-top:4px}

/* ── Gráficas – 2 columnas desktop ── */
.rcv-charts-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:10px 0;width:100%}
.rcv-chart-box{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:14px 16px;min-width:0;overflow:hidden;display:flex;flex-direction:column;height:320px;box-sizing:border-box}
.rcv-chart-box.rcv-chart-wide{grid-column:1/-1;height:340px}
.rcv-chart-box h3{margin:0 0 6px;font-size:.88em;font-weight:600;color:#1e293b;border-bottom:1px solid #e2e8f0;padding-bottom:6px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rcv-chart-box canvas{display:block;flex:1;min-height:0;width:100%!important;max-width:100%!important}

/* ── Tablas – scrollable, compactas, legibles ── */
.rcv-table-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:12px 0;width:100%;max-width:100%;border:1px solid #e5e7eb;border-radius:6px;background:#fff;box-sizing:border-box}
.rcv-table-wrapper table{width:100%;min-width:0;border-collapse:collapse;font-size:.85em}
.rcv-table-wrapper table th{position:sticky;top:0;background:#f1f5f9;font-weight:600;font-size:.82em;color:#374151;text-transform:uppercase;letter-spacing:.02em;padding:8px 10px;white-space:nowrap;border-bottom:2px solid #d1d5db;text-align:left}
.rcv-table-wrapper table td{padding:6px 10px;color:#1e293b;border-bottom:1px solid #f0f0f0;white-space:nowrap}
.rcv-table-wrapper table th[style*="text-align:center"],.rcv-table-wrapper table td[style*="text-align:center"]{text-align:center}
.rcv-table-wrapper table tr:hover td{background:#f8fafc}
.rcv-table-wrapper table tr.liste_titre th,.rcv-table-wrapper table tr.liste_titre td{background:#f1f5f9;font-weight:600}

/* Tabla densa – para tablas cruzadas con muchas columnas */
.rcv-table-dense table{font-size:.78em}
.rcv-table-dense table th,.rcv-table-dense table td{padding:5px 7px;white-space:normal}

/* Encabezados de sección de tablas */
.rcv-section-title{margin:20px 0 4px;font-size:.95em;font-weight:600;color:#1e293b;border-left:4px solid #2563eb;padding-left:10px}

/* ── Misc ── */
.rcv-quick-links{display:flex;flex-direction:column;gap:8px;padding-top:8px}
.rcv-quick-links a{display:block;text-align:center}
.rcv-pagination{margin:8px 0;text-align:right;font-size:.9em}

/* ── Presets de fecha rápida ── */
.rcv-date-presets{display:flex;flex-wrap:wrap;gap:5px;align-items:center;padding:8px 10px;margin:6px 0 8px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px}
.rcv-date-presets .rcv-preset-lbl{font-size:.73em;font-weight:600;color:#64748b;white-space:nowrap;margin-right:4px}
.rcv-date-presets button{font-size:.75em;padding:4px 11px;cursor:pointer;border:1px solid #93c5fd;border-radius:12px;background:#fff;color:#1d4ed8;line-height:1.6;white-space:nowrap;transition:background .12s,border-color .12s;box-shadow:0 1px 2px rgba(0,0,0,.05)}
.rcv-date-presets button:hover{background:#dbeafe;border-color:#3b82f6}
.rcv-date-presets button.rcv-preset-active{background:#2563eb;border-color:#1d4ed8;color:#fff;box-shadow:0 1px 4px rgba(37,99,235,.35);font-weight:600}

/* ── multisel custom dropdown ── */
.rcv-ms{position:relative;width:100%;min-width:0}
.rcv-ms-btn{width:100%;text-align:left;background:#fff;border:1px solid #c4c4c4;border-radius:3px;padding:5px 26px 5px 8px;cursor:pointer;font-size:.86em;position:relative;box-sizing:border-box;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#333;line-height:1.5}
.rcv-ms-btn:hover,.rcv-ms.open>.rcv-ms-btn{border-color:#2563eb}
.rcv-ms-btn.rcv-ms-active{border-color:#2563eb;color:#1e3a8a;background:#eff6ff}
.rcv-ms-arrow{position:absolute;right:7px;top:50%;transform:translateY(-50%);pointer-events:none;font-size:.65em;color:#777;transition:transform .15s}
.rcv-ms.open .rcv-ms-arrow{transform:translateY(-50%) rotate(180deg)}
.rcv-ms-panel{display:none;position:fixed;z-index:9500;background:#fff;border:1px solid #2563eb;border-radius:4px;box-shadow:0 6px 20px rgba(0,0,0,.13);min-width:180px;width:max-content;max-width:min(300px,calc(100vw - 24px))}
.rcv-ms.open .rcv-ms-panel{display:block}
.rcv-ms-search{padding:6px 8px 5px}
.rcv-ms-search input{width:100%;border:1px solid #ddd;border-radius:3px;padding:3px 7px;font-size:.82em;box-sizing:border-box;outline:none}
.rcv-ms-search input:focus{border-color:#2563eb}
.rcv-ms-actions{display:flex;gap:4px;padding:3px 8px 5px;border-bottom:1px solid #e5e7eb}
.rcv-ms-actions button{font-size:.74em;padding:2px 9px;cursor:pointer;border:1px solid #dde0e4;border-radius:3px;background:#f1f5f9;color:#374151;line-height:1.6}
.rcv-ms-actions button:hover{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
.rcv-ms-list{max-height:190px;overflow-y:auto;padding:3px 0}
.rcv-ms-list::-webkit-scrollbar{width:5px}.rcv-ms-list::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
.rcv-ms-opt{display:flex;align-items:center;gap:7px;padding:5px 10px;cursor:pointer;font-size:.86em;color:#374151;user-select:none}
.rcv-ms-opt:hover{background:#eff6ff}
.rcv-ms-opt input[type="checkbox"]{margin:0;cursor:pointer;flex-shrink:0;accent-color:#2563eb}

/* ── responsive ── */
@media(max-width:1100px){.rcv-charts-row{grid-template-columns:repeat(2,minmax(0,1fr))}.rcv-chart-box{height:280px}}
@media(max-width:900px){.rcv-chart-box{height:260px}}
@media(max-width:768px){.rcv-filter-grid,.rcv-filter-dates{grid-template-columns:1fr 1fr}.rcv-kpi-row{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.rcv-charts-row{grid-template-columns:1fr}.rcv-chart-box,.rcv-chart-box.rcv-chart-wide{height:250px}}
@media(max-width:480px){.rcv-filter-grid,.rcv-filter-dates,.rcv-kpi-row{grid-template-columns:1fr}}
</style>';
}

/**
 * Imprime una tarjeta KPI
 */
function rcv_kpi_card($label, $value, $picto = 'generic', $color = '#2563eb')
{
    print '<div class="rcv-kpi-card" style="border-top:4px solid '.$color.'">';
    print '<div class="rcv-kpi-icon">'.img_picto('', $picto).'</div>';
    print '<div class="rcv-kpi-value">'.dol_escape_htmltag((string) $value).'</div>';
    print '<div class="rcv-kpi-label">'.dol_escape_htmltag($label).'</div>';
    print '</div>';
}

/**
 * Imprime un ítem de filtro select dentro del grid responsive
 * Acepta $options como array plano ['val','val',...] O array asociativo [id => label]
 */
function rcv_print_filter_select($name, $label, $options, $selected = '')
{
    global $langs;
    print '<div class="rcv-filter-item">';
    print '<label for="'.dol_escape_htmltag($name).'">'.dol_escape_htmltag($label).'</label>';
    print '<select id="'.dol_escape_htmltag($name).'" name="'.dol_escape_htmltag($name).'" class="flat">';
    print '<option value="">-- '.$langs->trans('Todos').' --</option>';
    foreach ($options as $key => $val) {
        // Siempre se usa la clave como valor del option (rowid para sellist/select, texto para varchar)
        $optVal   = $key;
        $optLabel = $val;
        $sel = ((string)$selected === (string)$optVal) ? ' selected' : '';
        print '<option value="'.dol_escape_htmltag($optVal).'"'.$sel.'>'.dol_escape_htmltag($optLabel).'</option>';
    }
    print '</select>';
    print '</div>';
}

/**
 * Dropdown multiselección con checkboxes, búsqueda y botones Todos/Ninguno.
 * Funciona con rcv_print_multisel_js() que debe llamarse una vez en la página.
 * $selected debe ser un array de valores seleccionados (string o int).
 */
function rcv_print_filter_multisel($name, $label, $options, $selected = array())
{
    global $langs;
    if (!is_array($selected)) {
        $selected = ($selected !== '' && $selected !== null) ? array($selected) : array();
    }
    $selected = array_map('strval', $selected);
    $uid = 'rcv-ms-'.preg_replace('/[^a-z0-9]/i', '-', $name);

    print '<div class="rcv-filter-item">';
    print '<label>'.dol_escape_htmltag($label).'</label>';
    print '<div class="rcv-ms" id="'.dol_escape_htmltag($uid).'">';
    print '<button type="button" class="rcv-ms-btn"><span class="rcv-ms-text">-- '.$langs->trans('Todos').' --</span><span class="rcv-ms-arrow">&#9660;</span></button>';
    print '<div class="rcv-ms-panel">';
    if (count($options) > 5) {
        print '<div class="rcv-ms-search"><input type="text" placeholder="'.$langs->trans('Buscar').'&hellip;" autocomplete="off"></div>';
    }
    print '<div class="rcv-ms-actions">';
    print '<button type="button" class="rcv-ms-all">'.$langs->trans('Todos').'</button>';
    print '<button type="button" class="rcv-ms-none">Ninguno</button>';
    print '</div>';
    print '<div class="rcv-ms-list">';
    foreach ($options as $key => $val) {
        $checked = in_array((string)$key, $selected, true) ? ' checked' : '';
        print '<label class="rcv-ms-opt">';
        print '<input type="checkbox" name="'.dol_escape_htmltag($name).'[]" value="'.dol_escape_htmltag($key).'"'.$checked.'>';
        print '<span>'.dol_escape_htmltag($val).'</span>';
        print '</label>';
    }
    print '</div></div></div></div>';
}

/**
 * Emite (una sola vez) el JS que da vida a todos los .rcv-ms de la página.
 */
function rcv_print_multisel_js()
{
    static $done = false;
    if ($done) return;
    $done = true;
    print '<script>
(function(){
function rcvMsLabel(ms){
  var chk=ms.querySelectorAll("input[type=checkbox]:checked"),btn=ms.querySelector(".rcv-ms-btn"),txt=btn.querySelector(".rcv-ms-text");
  if(!chk.length){txt.textContent=btn.dataset.placeholder||"-- Todos --";btn.classList.remove("rcv-ms-active");return;}
  btn.classList.add("rcv-ms-active");
  if(chk.length===1){var sp=chk[0].parentNode.querySelector("span");txt.textContent=sp?sp.textContent.trim():chk[0].value;}
  else txt.textContent=chk.length+" seleccionados";
}
function rcvMsFilter(ms,q){
  var lq=q.toLowerCase();
  ms.querySelectorAll(".rcv-ms-opt").forEach(function(o){
    o.style.display=(!lq||o.textContent.toLowerCase().indexOf(lq)>=0)?"":"none";
  });
}
function rcvMsPosPanel(ms){
  var btn=ms.querySelector(".rcv-ms-btn"),panel=ms.querySelector(".rcv-ms-panel");
  if(!btn||!panel) return;
  var r=btn.getBoundingClientRect();
  panel.style.top=(r.bottom+2)+"px";
  panel.style.left=r.left+"px";
  panel.style.minWidth=r.width+"px";
}
function rcvMsClose(){
  document.querySelectorAll(".rcv-ms.open").forEach(function(el){
    el.classList.remove("open");
    var p=el.querySelector(".rcv-ms-panel");
    if(p) p.style.top=p.style.left=p.style.minWidth="";
  });
}
function rcvMsInit(ms){
  var btn=ms.querySelector(".rcv-ms-btn"),srch=ms.querySelector(".rcv-ms-search input");
  var btnAll=ms.querySelector(".rcv-ms-all"),btnNone=ms.querySelector(".rcv-ms-none");
  btn.dataset.placeholder=btn.querySelector(".rcv-ms-text").textContent;
  btn.addEventListener("click",function(e){
    e.stopPropagation();
    var was=ms.classList.contains("open");
    rcvMsClose();
    if(!was){
      rcvMsPosPanel(ms);
      ms.classList.add("open");
      if(srch){srch.value="";rcvMsFilter(ms,"");setTimeout(function(){srch.focus();},30);}
    }
  });
  if(srch){
    srch.addEventListener("input",function(){rcvMsFilter(ms,this.value);});
    srch.addEventListener("click",function(e){e.stopPropagation();});
    srch.addEventListener("keydown",function(e){e.stopPropagation();});
  }
  function chkAll(state){ms.querySelectorAll("input[type=checkbox]").forEach(function(cb){cb.checked=state;});rcvMsLabel(ms);}
  if(btnAll)btnAll.addEventListener("click",function(e){e.stopPropagation();chkAll(true);});
  if(btnNone)btnNone.addEventListener("click",function(e){e.stopPropagation();chkAll(false);});
  ms.querySelectorAll(".rcv-ms-opt").forEach(function(opt){
    opt.addEventListener("click",function(e){
      e.stopPropagation();
      var cb=opt.querySelector("input[type=checkbox]");
      if(e.target!==cb)cb.checked=!cb.checked;
      rcvMsLabel(ms);
    });
  });
  ms.querySelectorAll("input[type=checkbox]").forEach(function(cb){
    cb.addEventListener("click",function(e){e.stopPropagation();});
    cb.addEventListener("change",function(){rcvMsLabel(ms);});
  });
  rcvMsLabel(ms);
}
document.addEventListener("click",rcvMsClose);
document.addEventListener("keydown",function(e){if(e.key==="Escape")rcvMsClose();});
window.addEventListener("scroll",function(e){
  if(!e.target.closest||!e.target.closest(".rcv-ms-panel"))rcvMsClose();
},true);
window.addEventListener("resize",rcvMsClose);
document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".rcv-ms").forEach(rcvMsInit);});
})();
</script>';
}

/**
 * (deprecated – usar rcv_print_filter_multisel) Alias de compatibilidad.
 */
function rcv_print_filter_multiselect($name, $label, $options, $selected = array())
{
    rcv_print_filter_multisel($name, $label, $options, $selected);
}

/**
 * Convierte array de rows a formato {labels:[], data:[]} para Chart.js
 */
function rcv_chart_data(array $rows, $labelField, $valueField)
{
    $labels = array();
    $data   = array();
    foreach ($rows as $row) {
        $labels[] = isset($row[$labelField]) ? $row[$labelField] : '';
        $data[]   = isset($row[$valueField]) ? (int) $row[$valueField] : 0;
    }
    return array('labels' => $labels, 'data' => $data);
}

/**
 * Construye el bloque de filtros ocultos para pasar filtros actuales a otra página
 */
function rcv_hidden_filters(array $filters)
{
    $allowed = array(
        'filter_date_start', 'filter_date_end', 'filter_medicamento', 'filter_eps',
        'filter_operador_logistico', 'filter_tipo_de_poblacion', 'filter_tipo_atencion',
        'filter_programa', 'filter_diagnostico', 'filter_ips_primaria',
        'filter_estado_del_paciente', 'filter_groupby',
    );
    $out = '';
    foreach ($allowed as $key) {
        if (!empty($filters[$key])) {
            $out .= '<input type="hidden" name="'.dol_escape_htmltag($key).'" value="'.dol_escape_htmltag($filters[$key]).'">';
        }
    }
    return $out;
}

/**
 * Construye query string con los filtros actuales para links entre páginas
 */
function rcv_filter_querystring(array $filters)
{
    $params = array();
    $map = array(
        'date_start'          => 'filter_date_start',
        'date_end'            => 'filter_date_end',
        'medicamento'         => 'filter_medicamento',
        'eps'                 => 'filter_eps',
        'operador_logistico'  => 'filter_operador_logistico',
        'tipo_de_poblacion'   => 'filter_tipo_de_poblacion',
        'tipo_atencion'       => 'filter_tipo_atencion',
        'programa'            => 'filter_programa',
        'diagnostico'         => 'filter_diagnostico',
        'ips_primaria'        => 'filter_ips_primaria',
        'estado_del_paciente' => 'filter_estado_del_paciente',
        'regimen'             => 'filter_regimen',
        'medico_tratante'     => 'filter_medico_tratante',
        'departamento'        => 'filter_departamento',
        'ciudad'              => 'filter_ciudad',
        'groupby'             => 'filter_groupby',
    );
    foreach ($map as $fkey => $qkey) {
        if (empty($filters[$fkey])) continue;
        $val = $filters[$fkey];
        if (is_array($val)) {
            foreach ($val as $v) {
                if ((string)$v !== '') {
                    $params[] = urlencode($qkey.'[]').'='.urlencode($v);
                }
            }
        } else {
            $params[] = urlencode($qkey).'='.urlencode($val);
        }
    }
    return $params ? '?'.implode('&', $params) : '';
}

/**
 * Imprime botones de período rápido que rellenan los campos de fecha y envían el formulario.
 * Debe colocarse dentro del <form> de filtros, después del bloque de fechas.
 */
function rcv_print_date_presets()
{
    static $jsEmitted = false;
    print '<div class="rcv-date-presets">';
    print '<span class="rcv-preset-lbl">Período rápido:</span>';
    print '<button type="button" data-preset="year0"  onclick="rcvPresetYear(this,0)">Año actual</button>';
    print '<button type="button" data-preset="year-1" onclick="rcvPresetYear(this,-1)">Año anterior</button>';
    print '<button type="button" data-preset="q1"     onclick="rcvPresetQuarter(this,1)">T1 (ene-mar)</button>';
    print '<button type="button" data-preset="q2"     onclick="rcvPresetQuarter(this,2)">T2 (abr-jun)</button>';
    print '<button type="button" data-preset="q3"     onclick="rcvPresetQuarter(this,3)">T3 (jul-sep)</button>';
    print '<button type="button" data-preset="q4"     onclick="rcvPresetQuarter(this,4)">T4 (oct-dic)</button>';
    print '<button type="button" data-preset="last12" onclick="rcvPresetLast12(this)">Últimos 12 meses</button>';
    print '</div>';
    if (!$jsEmitted) {
        $jsEmitted = true;
        print '<script>
function rcvApplyPreset(btn,y1,m1,d1,y2,m2,d2){
  var f=btn.closest("form");
  function sv(n,v){var e=f.querySelector("[name=\'"+n+"\']");if(e)e.value=v;}
  sv("filter_date_startday",d1);sv("filter_date_startmonth",m1);sv("filter_date_startyear",y1);
  sv("filter_date_endday",d2);sv("filter_date_endmonth",m2);sv("filter_date_endyear",y2);
  f.submit();
}
function rcvPresetYear(btn,offset){
  var y=new Date().getFullYear()+(offset||0);
  rcvApplyPreset(btn,y,1,1,y,12,31);
}
function rcvPresetQuarter(btn,q){
  var y=new Date().getFullYear();
  var qm=[[1,3],[4,6],[7,9],[10,12]][q-1];
  rcvApplyPreset(btn,y,qm[0],1,y,qm[1],new Date(y,qm[1],0).getDate());
}
function rcvPresetLast12(btn){
  var e=new Date(),s=new Date(e);
  s.setFullYear(s.getFullYear()-1);s.setDate(s.getDate()+1);
  rcvApplyPreset(btn,s.getFullYear(),s.getMonth()+1,s.getDate(),e.getFullYear(),e.getMonth()+1,e.getDate());
}
function rcvDetectActivePreset(){
  document.querySelectorAll(".rcv-date-presets").forEach(function(row){
    var f=row.closest("form");if(!f)return;
    function gv(n){var e=f.querySelector("[name=\'"+n+"\']");return e?parseInt(e.value)||0:0;}
    var sy=gv("filter_date_startyear"),sm=gv("filter_date_startmonth"),sd=gv("filter_date_startday");
    var ey=gv("filter_date_endyear"),  em=gv("filter_date_endmonth"),  ed=gv("filter_date_endday");
    if(!sy||!ey)return;
    var cy=new Date().getFullYear(), match=null;
    if(sy===cy   &&sm===1 &&sd===1&&ey===cy   &&em===12&&ed===31) match="year0";
    else if(sy===cy-1&&sm===1&&sd===1&&ey===cy-1&&em===12&&ed===31) match="year-1";
    else{
      var qm=[[1,3],[4,6],[7,9],[10,12]];
      for(var q=0;q<4;q++){
        var ld=new Date(cy,qm[q][1],0).getDate();
        if(sy===cy&&sm===qm[q][0]&&sd===1&&ey===cy&&em===qm[q][1]&&ed===ld){match="q"+(q+1);break;}
      }
    }
    if(!match){
      var now=new Date(),s12=new Date(now);
      s12.setFullYear(s12.getFullYear()-1);s12.setDate(s12.getDate()+1);
      if(sy===s12.getFullYear()&&sm===s12.getMonth()+1&&sd===s12.getDate()&&
         ey===now.getFullYear()&&em===now.getMonth()+1&&ed===now.getDate()) match="last12";
    }
    if(match){
      var btn=row.querySelector("[data-preset=\'"+match+"\']");
      if(btn)btn.classList.add("rcv-preset-active");
    }
  });
}
document.addEventListener("DOMContentLoaded",rcvDetectActivePreset);
</script>';
    }
}
