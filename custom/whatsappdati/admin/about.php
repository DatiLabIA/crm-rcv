<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       admin/about.php
 * \ingroup    whatsappdati
 * \brief      About page for WhatsApp module
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/whatsappdati.lib.php';

$langs->loadLangs(array("admin", "whatsappdati@whatsappdati"));

if (!$user->admin) {
	accessforbidden();
}

$page_name = "WhatsAppDatiAbout";

llxHeader('', $langs->trans($page_name));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = whatsappdatiAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans("WhatsAppDati"), -1, 'whatsappdati@whatsappdati');

print '<style>
.wa-about { max-width: 900px; margin: 0 auto; }
.wa-about-header { text-align: center; padding: 30px 20px 20px; }
.wa-about-header h3 { font-size: 22px; margin: 0 0 4px; color: var(--colortexttitle, #333); }
.wa-about-header .wa-version { display: inline-block; background: #00a884; color: #fff; padding: 3px 14px; border-radius: 12px; font-size: 12px; font-weight: 600; margin-top: 6px; }
.wa-about-header .wa-subtitle { color: var(--colortextother, #888); font-size: 13px; margin-top: 10px; }
.wa-feat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 20px 0; }
@media (max-width: 768px) { .wa-feat-grid { grid-template-columns: 1fr; } }
.wa-feat-card { border: 1px solid var(--colorbordertitle, #dee2e6); border-radius: 8px; padding: 14px 16px; transition: box-shadow 0.15s; }
.wa-feat-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }
.wa-feat-card-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: var(--colortexttitle, #333); margin-bottom: 6px; }
.wa-feat-card-title .wa-feat-icon { font-size: 18px; }
.wa-feat-card p { font-size: 12px; color: var(--colortextother, #666); margin: 0; line-height: 1.5; }
.wa-tech-list { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin: 16px 0; }
.wa-tech-tag { background: var(--colorbacklinepair, #f3f4f6); border: 1px solid var(--colorbordertitle, #e5e7eb); padding: 4px 12px; border-radius: 16px; font-size: 11px; color: var(--colortextother, #555); font-weight: 500; }
.wa-about-footer { text-align: center; padding: 20px; border-top: 1px solid var(--colorbordertitle, #eee); margin-top: 24px; }
.wa-about-footer a { color: #00a884; text-decoration: none; font-weight: 600; }
.wa-about-footer a:hover { text-decoration: underline; }
</style>';

print '<div class="wa-about">';

// ---- Header ----
print '<div class="wa-about-header">';
print '<div style="font-size:48px; margin-bottom:8px;">💬</div>';
print '<h3>WhatsApp Business para Dolibarr</h3>';
print '<span class="wa-version">v1.0.0</span>';
print '<p class="wa-subtitle">Desarrollado por <strong>DatiLab</strong> — Soluciones tecnológicas para el sector salud</p>';
print '</div>';

print '<p style="text-align:center; font-size:13px; color:var(--colortextother,#666); max-width:650px; margin:0 auto 20px; line-height:1.6;">';
print 'Módulo completo de integración con WhatsApp Business API (Cloud) para Dolibarr 20.x. ';
print 'Gestione todas sus comunicaciones de WhatsApp directamente desde su ERP.';
print '</p>';

// ---- Features Grid ----
print '<div class="wa-feat-grid">';

// 1. Multi-Line
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">📱</span> Multi-Línea</div>';
print '<p>Gestione múltiples líneas de WhatsApp Business desde una sola instalación. Cada línea con su propia configuración de API, webhook, código de país y agente por defecto.</p>';
print '</div>';

// 2. Real-time Chat
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">💬</span> Chat en Tiempo Real</div>';
print '<p>Interfaz de chat tipo WhatsApp Web con soporte para Server-Sent Events (SSE) o polling automático. Indicadores de estados (enviado, entregado, leído) y notificaciones sonoras.</p>';
print '</div>';

// 3. Media
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">📎</span> Multimedia</div>';
print '<p>Envío y recepción de imágenes, videos, audios y documentos. Subida drag & drop, previsualización inline, descarga directa de archivos recibidos con pies de foto.</p>';
print '</div>';

// 4. Templates
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">📋</span> Plantillas WhatsApp</div>';
print '<p>Sincronización automática con Meta API. Gestión de plantillas aprobadas con soporte para variables dinámicas, categorías (Marketing, Utilidad, Autenticación) y vista previa.</p>';
print '</div>';

// 5. Bulk Send
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">📤</span> Envío Masivo</div>';
print '<p>Envío de plantillas a múltiples destinatarios con selector de contactos, barra de progreso en tiempo real, historial de lotes y cancelación de pendientes. Control de rate limiting configurable.</p>';
print '</div>';

// 6. Chatbot
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🤖</span> Chatbot / Auto-respuestas</div>';
print '<p>Motor de reglas con disparadores (exacto, contiene, regex, conversación nueva, por defecto). Condiciones por horario o asignación, retraso configurable, límite de ejecuciones y consola de pruebas integrada.</p>';
print '</div>';

// 7. Scheduling
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">⏰</span> Mensajes Programados</div>';
print '<p>Programación de mensajes de texto y plantillas con recurrencia (una vez, diario, semanal, mensual). Gestión de estados (pendiente, enviado, fallido, pausado) y ejecución vía cron.</p>';
print '</div>';

// 8. Assignment
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">👥</span> Asignación Multi-Agente</div>';
print '<p>Asignación automática por Round Robin, menor carga o manual. Selección de agentes disponibles, estadísticas por agente (activas/total) y agente por defecto por línea.</p>';
print '</div>';

// 9. Transfer
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🔄</span> Transferencia de Conversaciones</div>';
print '<p>Transferencia entre agentes con nota de contexto opcional. Historial de transferencias registrado y notificación al agente receptor.</p>';
print '</div>';

// 10. Tags
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🏷️</span> Etiquetas</div>';
print '<p>Sistema de etiquetas con colores personalizables para clasificar conversaciones. Filtrado por etiqueta en la lista de conversaciones y contador de uso por etiqueta.</p>';
print '</div>';

// 11. Quick Replies
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">⚡</span> Respuestas Rápidas</div>';
print '<p>Atajos de texto reutilizables accesibles con "/" en el chat. Organizados por categorías con título, contenido y shortcut configurable.</p>';
print '</div>';

// 12. CRM Integration
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🏢</span> Integración CRM</div>';
print '<p>Vinculación de conversaciones a terceros/contactos de Dolibarr con buscador inteligente. Creación rápida de terceros y generación de leads/oportunidades directamente desde el chat.</p>';
print '</div>';

// 13. Business Hours
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🕐</span> Horario de Atención</div>';
print '<p>Configuración de horario laboral con días de la semana, zona horaria y mensaje automático fuera de horario. Control anti-spam (máximo 1 envío cada 4 horas por conversación).</p>';
print '</div>';

// 14. CSAT
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">⭐</span> Encuesta de Satisfacción (CSAT)</div>';
print '<p>Envío automático de encuesta al cerrar conversación. Calificación de 1 a 5 estrellas con mensajes personalizables, dashboard de estadísticas con tasa de respuesta y distribución.</p>';
print '</div>';

// 15. Floating Widget
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🔔</span> Widget Flotante Global</div>';
print '<p>Burbuja de chat flotante disponible en todas las páginas de Dolibarr. Notificaciones de mensajes nuevos, contador de no leídos, sonido y acceso rápido a conversaciones sin salir de la página actual.</p>';
print '</div>';

// 16. Security
print '<div class="wa-feat-card">';
print '<div class="wa-feat-card-title"><span class="wa-feat-icon">🔒</span> Seguridad</div>';
print '<p>Verificación de firma HMAC-SHA256 en webhooks, protección CSRF con tokens en todos los endpoints, sanitización de entradas, rate limiting, permisos granulares por rol de usuario.</p>';
print '</div>';

print '</div>'; // End grid

// ---- Tech Stack ----
print '<div style="text-align:center; margin-top:24px;">';
print '<div style="font-size:13px; font-weight:600; color:var(--colortexttitle,#333); margin-bottom:10px;">Stack Tecnológico</div>';
print '<div class="wa-tech-list">';
$techs = array('Dolibarr 20.x', 'PHP 8.1+', 'WhatsApp Cloud API v21.0', 'MySQL / MariaDB', 'Server-Sent Events', 'jQuery', 'REST / AJAX', 'Webhooks', 'HMAC-SHA256', 'Cron Jobs');
foreach ($techs as $t) {
	print '<span class="wa-tech-tag">'.$t.'</span>';
}
print '</div>';
print '</div>';

// ---- Footer ----
print '<div class="wa-about-footer">';
print '<p><strong>Soporte:</strong> <a href="mailto:soporte@datilab.com">soporte@datilab.com</a></p>';
print '<p><strong>Web:</strong> <a href="https://datilab.com" target="_blank">datilab.com</a></p>';
print '<br>';
print '<p class="opacitymedium" style="font-size:12px;">&copy; 2024-2026 DatiLab — Licencia GPLv3+</p>';
print '</div>';

print '</div>'; // End wa-about

print dol_get_fiche_end();

llxFooter();
$db->close();
