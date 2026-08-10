/**
 * calendar-app.js
 * Lógica principal del calendario interactivo cabinetmed_calendar.
 * Depende de FullCalendar v6 (cargado antes de este script) y de CALENDAR_CONFIG (inyectado en PHP).
 *
 * Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed
 */

/* jshint esversion: 6 */
/* global FullCalendar, CALENDAR_CONFIG, $ */

(function () {
    'use strict';

    // =========================================================
    // Configuración global (inyectada por calendar.php)
    // =========================================================
    const CFG = window.CALENDAR_CONFIG || {};
    const LANG = CFG.lang || {};
    const AJAX = CFG.ajaxBase || '';
    const TOKEN = CFG.token || '';

    // =========================================================
    // Estado de filtros
    // =========================================================
    let activeFilters = {
        status: [],   // vacío = todos
        tipo_atencion: [],   // vacío = todos
        fk_user: [],   // vacío = todos
    };

    // Referencia al calendario de FullCalendar
    let calendar = null;

    // ID de la consulta sobre la que está abierto el popover/color picker
    let activeEventId = null;

    // Elementos DOM
    const $calContainer = document.getElementById('calendarMain');
    const $toastContainer = document.getElementById('calToastContainer');

    // =========================================================
    // Inicializar FullCalendar al cargar el DOM
    // =========================================================
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof FullCalendar === 'undefined') {
            showToast('error', 'FullCalendar no está cargado. Revisa la consola del navegador.');
            return;
        }

        initCalendar();
        initFilters();
        initColorPicker();
        initCloseDropdowns();
    });

    // =========================================================
    // Inicialización de FullCalendar
    // =========================================================
    function initCalendar() {
        calendar = new FullCalendar.Calendar($calContainer, {
            // --- Apariencia y localización ---
            locale: 'es',
            initialView: CFG.defaultView || 'dayGridMonth',
            firstDay: CFG.firstDay !== undefined ? CFG.firstDay : 1,
            height: 'auto',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            },
            buttonText: {
                today: 'Hoy',
                month: 'Mes',
                week: 'Semana',
                day: 'Día',
                list: 'Lista',
            },

            // --- Slots (vista semana/día) ---
            slotDuration: CFG.slotDuration || '00:30:00',
            slotMinTime: CFG.bizStart || '07:00',
            slotMaxTime: CFG.bizEnd || '20:00',
            businessHours: {
                daysOfWeek: [1, 2, 3, 4, 5],
                startTime: CFG.bizStart || '08:00',
                endTime: CFG.bizEnd || '18:00',
            },

            // --- Interactividad ---
            editable: CFG.canWrite === 1,
            eventResizableFromStart: false,
            selectable: false,
            nowIndicator: true,
            dayMaxEvents: 2,
            moreLinkText: function (n) { return '+' + n + ' más'; },

            // --- Bloqueo de domingos y festivos ---
            eventAllow: function (dropInfo) {
                var date = dropInfo.start;
                // 0 = domingo en JS
                if (date.getDay() === 0) {
                    showToast('error', LANG.blockedSunday || 'No se pueden programar consultas los domingos');
                    return false;
                }
                // Verificar festivos colombianos
                var dateStr = date.getFullYear() + '-'
                    + ('0' + (date.getMonth() + 1)).slice(-2) + '-'
                    + ('0' + date.getDate()).slice(-2);
                if (CFG.holidays && CFG.holidays.indexOf(dateStr) !== -1) {
                    showToast('error', LANG.blockedHoliday || 'No se pueden programar consultas en días festivos');
                    return false;
                }
                return true;
            },

            // --- Fuente de eventos AJAX ---
            events: fetchEvents,

            // --- Renderizado personalizado: tarjeta de 3 zonas ---
            eventContent: renderEventCard,

            // --- Handlers ---
            eventDidMount: onEventDidMount,
            eventClick: onEventClick,
            eventDrop: onEventDrop,
            eventResize: onEventResize,
            eventMouseEnter: onEventMouseEnter,
            eventMouseLeave: onEventMouseLeave,
        });

        calendar.render();
    }

    // =========================================================
    // renderEventCard — tarjeta personalizada para cada evento
    //
    // Estructura visual:
    //  ┌─────────────────────────────────┬───┐
    //  │▓▓▓▓ franja tipo de atención ▓▓▓│ S │
    //  ├─────────────────────────────────┤ T │
    //  │ bg color del gestor             │ A │
    //  │ Nombre Paciente · Tipo          │ T │
    //  │ Gestores                        │   │
    //  └─────────────────────────────────┴───┘
    // =========================================================
    function renderEventCard(info) {
        var p = info.event.extendedProps;
        var isAllDay = info.event.allDay;

        var tipoColor = p.tipo_color || '#607D8B';
        var managerColor = p.manager_color || '#F5F5F5';
        var statusColor = p.status_color || '#FFC107';

        // Texto del gestor principal (primero de la lista)
        var gestorText = (p.gestores && p.gestores.length > 0)
            ? p.gestores[0] + (p.gestores.length > 1 ? ' +' + (p.gestores.length - 1) : '')
            : '';

        // Contenedor principal
        var wrap = document.createElement('div');
        wrap.className = 'cal-card-wrap';
        wrap.style.cssText = 'background:' + managerColor + ';';

        // Franja superior: color del tipo
        var topStrip = document.createElement('div');
        topStrip.className = 'cal-card-top-strip';
        topStrip.style.background = tipoColor;
        topStrip.textContent = p.tipo_atencion_label || '';

        // Cuerpo: nombre del paciente y gestor
        var body = document.createElement('div');
        body.className = 'cal-card-body';

        var titleEl = document.createElement('div');
        titleEl.className = 'cal-card-title';
        titleEl.textContent = p.patient_name || info.event.title;

        body.appendChild(titleEl);

        if (gestorText) {
            var gestorEl = document.createElement('div');
            gestorEl.className = 'cal-card-gestor';
            gestorEl.textContent = gestorText;
            body.appendChild(gestorEl);
        }

        // Franja derecha: color del estado
        var rightStrip = document.createElement('div');
        rightStrip.className = 'cal-card-status-strip';
        rightStrip.style.background = statusColor;
        rightStrip.title = p.status_label || '';

        wrap.appendChild(topStrip);
        wrap.appendChild(body);
        wrap.appendChild(rightStrip);

        return { domNodes: [wrap] };
    }


    function fetchEvents(info, successCallback, failureCallback) {
        const params = new URLSearchParams();
        params.append('start', info.startStr);
        params.append('end', info.endStr);
        params.append('token', TOKEN);

        // Añadir filtros activos
        activeFilters.status.forEach(s => params.append('status[]', s));
        activeFilters.tipo_atencion.forEach(t => params.append('tipo_atencion[]', t));
        activeFilters.fk_user.forEach(u => params.append('fk_user[]', u));

        fetch(AJAX + 'get_events.php?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',   // envía cookie de sesión → $_SESSION disponible en PHP
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (Array.isArray(data)) {
                    successCallback(data);
                } else {
                    failureCallback(data.error || 'Error desconocido');
                    showToast('error', 'Error al cargar eventos: ' + (data.error || 'desconocido'));
                }
            })
            .catch(function (err) {
                failureCallback(err);
                showToast('error', LANG.networkError || 'Error de red al cargar el calendario.');
                console.error('fetchEvents:', err);
            });
    }

    // =========================================================
    // eventDidMount: añade clases de estado y opacidad en canceladas
    // =========================================================
    function onEventDidMount(info) {
        // El resaltado de estado (tachado, opacidad, borde) lo maneja CSS via classNames.
        // Solo añadimos tooltip nativo accesible.
        const p = info.event.extendedProps;
        if (p && p.status_label) {
            info.el.setAttribute('data-status', p.status_label);
        }
    }

    // =========================================================
    // eventClick: abre el popover (no navega directamente para
    // permitir elegir entre "Ver" y "Color")
    // =========================================================
    function onEventClick(info) {
        info.jsEvent.preventDefault();
        info.jsEvent.stopPropagation();

        closeAllPopovers();
        showEventPopover(info.event, info.el);
    }

    // =========================================================
    // eventMouseEnter / Leave: mini tooltip nativo con atributo title
    // (el popover completo se abre con click)
    // =========================================================
    function onEventMouseEnter(info) {
        const p = info.event.extendedProps;
        const start = formatDateDisplay(info.event.start);
        const end = info.event.end ? formatDateDisplay(info.event.end) : 'N/A';
        info.el.title = p.patient_name + '\n' + p.tipo_atencion_label + '\n' + start + ' → ' + end;
    }

    function onEventMouseLeave(info) {
        info.el.title = '';
    }

    // =========================================================
    // eventDrop: drag & drop para mover una consulta
    // =========================================================
    function onEventDrop(info) {
        var event = info.event;
        var revert = info.revert;

        updateEventDates(
            event.id,
            event.start,
            event.end,
            event.allDay,
            function () {
                showToast('success', LANG.eventMoved || 'Consulta reprogramada correctamente');
            },
            function (errMsg) {
                showToast('error', LANG.eventMovedError || 'Error al reprogramar la consulta');
                console.error('eventDrop error:', errMsg);
                revert();
            }
        );
    }

    // =========================================================
    // eventResize: redimensionar para cambiar date_end
    // =========================================================
    function onEventResize(info) {
        var event = info.event;
        var revert = info.revert;

        updateEventDates(
            event.id,
            event.start,
            event.end,
            event.allDay,
            function () {
                showToast('success', LANG.eventResized || 'Duración actualizada correctamente');
            },
            function (errMsg) {
                showToast('error', LANG.eventResizedError || 'Error al actualizar la duración');
                console.error('eventResize error:', errMsg);
                revert();
            }
        );
    }

    // =========================================================
    // updateEventDates: llama a ajax/update_event.php
    // =========================================================
    function updateEventDates(eventId, dateStart, dateEnd, isAllDay, onSuccess, onError) {
        var body = new URLSearchParams();
        body.append('id', eventId);
        // Usar formato local (no UTC) para evitar desfase de zona horaria.
        // Para eventos all-day enviar solo fecha (YYYY-MM-DD) para que
        // FullCalendar los siga tratando como all-day al recargar.
        var fmt = isAllDay ? formatDateOnly : formatLocalISO;
        body.append('date_start', dateStart ? fmt(dateStart) : '');
        body.append('date_end', dateEnd ? fmt(dateEnd) : '');
        body.append('token', TOKEN);

        fetch(AJAX + 'update_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    onSuccess(data);
                } else {
                    onError(data.error || 'Error desconocido');
                }
            })
            .catch(function (err) {
                onError(err.toString());
            });
    }

    /**
     * Formatea un Date como YYYY-MM-DD (hora local, sin componente de hora).
     * Necesario para que FullCalendar mantenga el flag allDay al recargar.
     */
    function formatDateOnly(date) {
        var y = date.getFullYear();
        var m = ('0' + (date.getMonth() + 1)).slice(-2);
        var d = ('0' + date.getDate()).slice(-2);
        return y + '-' + m + '-' + d;
    }

    /**
     * Formatea un Date como YYYY-MM-DDTHH:mm:ss en hora LOCAL.
     * Evita el desfase de toISOString() que convierte a UTC
     * y puede mover la fecha un día atrás (ej. Colombia UTC−5).
     */
    function formatLocalISO(date) {
        var y = date.getFullYear();
        var m = ('0' + (date.getMonth() + 1)).slice(-2);
        var d = ('0' + date.getDate()).slice(-2);
        var h = ('0' + date.getHours()).slice(-2);
        var min = ('0' + date.getMinutes()).slice(-2);
        var s = ('0' + date.getSeconds()).slice(-2);
        return y + '-' + m + '-' + d + 'T' + h + ':' + min + ':' + s;
    }

    // =========================================================
    // POPOVER — muestra detalles del evento con acciones
    // =========================================================
    function showEventPopover(event, anchorEl) {
        activeEventId = event.id;
        const p = event.extendedProps;

        // Clonar template
        const tmpl = document.getElementById('calEventPopoverTemplate');
        const popover = tmpl.firstElementChild.cloneNode(true);
        popover.id = 'calEventPopoverActive';

        // Rellenar datos
        popover.querySelector('.cal-popover-title').textContent = p.patient_name || event.title;
        popover.querySelector('.cal-pop-tipo').textContent = p.tipo_atencion_label || p.tipo_atencion;
        popover.querySelector('.cal-pop-status').textContent = p.status_label || '';
        popover.querySelector('.cal-pop-start').textContent = formatDateDisplay(event.start);
        popover.querySelector('.cal-pop-end').textContent = event.end ? formatDateDisplay(event.end) : '—';
        popover.querySelector('.cal-pop-gestores').textContent = (p.gestores && p.gestores.length)
            ? p.gestores.join(', ')
            : '—';

        // Color dot en título
        const colorDot = document.createElement('span');
        colorDot.className = 'cal-popover-color-dot';
        colorDot.style.backgroundColor = event.backgroundColor || '#607D8B';
        popover.querySelector('.cal-popover-title').prepend(colorDot);

        // Botón "Ver consulta"
        const btnView = popover.querySelector('.cal-pop-btn-view');
        btnView.href = event.url || '#';

        // Botón "Color" — abre el color picker
        const btnColor = popover.querySelector('.cal-pop-btn-color');
        if (CFG.canWrite !== 1 && !CFG.canColorize) {
            btnColor.style.display = 'none';
        }
        btnColor.addEventListener('click', function (e) {
            e.stopPropagation();
            closeAllPopovers();
            showColorPicker(event, anchorEl);
        });

        // Posicionar y mostrar
        positionPopover(popover, anchorEl);
        document.body.appendChild(popover);

        // Cerrar al hacer clic fuera
        setTimeout(function () {
            document.addEventListener('click', closeAllPopoversOnOutsideClick);
        }, 50);
    }

    function closeAllPopovers() {
        document.querySelectorAll('#calEventPopoverActive, #calColorPickerActive').forEach(function (el) {
            el.remove();
        });
        document.removeEventListener('click', closeAllPopoversOnOutsideClick);
    }

    function closeAllPopoversOnOutsideClick(e) {
        if (!e.target.closest('#calEventPopoverActive') &&
            !e.target.closest('#calColorPickerActive')) {
            closeAllPopovers();
        }
    }

    function positionPopover(el, anchor) {
        const rect = anchor.getBoundingClientRect();
        el.style.position = 'fixed';
        el.style.zIndex = '9999';
        el.style.top = Math.max(10, rect.bottom + window.scrollY + 5) + 'px';
        el.style.left = Math.min(
            rect.left + window.scrollX,
            window.innerWidth - 320
        ) + 'px';
    }

    // =========================================================
    // COLOR PICKER
    // =========================================================
    function showColorPicker(event, anchorEl) {
        activeEventId = event.id;

        const tmpl = document.getElementById('calColorPickerTemplate');
        const picker = tmpl.firstElementChild.cloneNode(true);
        picker.id = 'calColorPickerActive';

        // Colores preset
        picker.querySelectorAll('.cal-color-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const color = btn.dataset.color;
                saveColor(event.id, color, function (savedColor) {
                    updateEventColor(event, savedColor);
                    closeAllPopovers();
                });
            });
        });

        // Color personalizado
        picker.querySelector('.cal-color-apply-custom').addEventListener('click', function () {
            const colorInput = picker.querySelector('.cal-color-custom-input');
            const color = colorInput.value;
            saveColor(event.id, color, function (savedColor) {
                updateEventColor(event, savedColor);
                closeAllPopovers();
            });
        });

        // Quitar color
        picker.querySelector('.cal-color-remove').addEventListener('click', function () {
            removeColor(event.id, function () {
                // Restablecer color según tipo de atención
                const defaultColor = (CFG.typeColors && CFG.typeColors[event.extendedProps.tipo_atencion])
                    ? CFG.typeColors[event.extendedProps.tipo_atencion]
                    : '#607D8B';
                updateEventColor(event, defaultColor);
                closeAllPopovers();
            });
        });

        positionPopover(picker, anchorEl);
        document.body.appendChild(picker);

        setTimeout(function () {
            document.addEventListener('click', closeAllPopoversOnOutsideClick);
        }, 50);
    }

    function saveColor(eventId, color, onSuccess) {
        const body = new URLSearchParams();
        body.append('id', eventId);
        body.append('color', color);
        body.append('token', TOKEN);

        fetch(AJAX + 'update_color.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    showToast('success', LANG.colorSaved || 'Color guardado');
                    onSuccess(data.color);
                } else {
                    showToast('error', LANG.colorError || 'Error al guardar el color');
                }
            })
            .catch(function () {
                showToast('error', LANG.networkError || 'Error de red al guardar el color.');
            });
    }

    function removeColor(eventId, onSuccess) {
        const body = new URLSearchParams();
        body.append('id', eventId);
        body.append('remove', '1');
        body.append('token', TOKEN);

        fetch(AJAX + 'update_color.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    showToast('success', LANG.colorRemoved || 'Color de etiqueta eliminado');
                    onSuccess();
                } else {
                    showToast('error', 'Error al eliminar el color');
                }
            })
            .catch(function () {
                showToast('error', LANG.networkError || 'Error de red al eliminar el color.');
            });
    }

    function updateEventColor(fcEvent, newColor) {
        fcEvent.setProp('color', newColor);
        fcEvent.setProp('borderColor', darkenColor(newColor, 30));
        fcEvent.setProp('textColor', getLuminance(newColor) > 0.5 ? '#000000' : '#ffffff');
    }

    // =========================================================
    // FILTROS
    // =========================================================
    function initFilters() {
        // Toggle de dropdowns de filtro
        document.querySelectorAll('.filter-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const targetId = btn.dataset.target;
                const drop = document.getElementById(targetId);
                if (!drop) return;
                const isOpen = drop.classList.contains('open');
                // Cerrar todos
                document.querySelectorAll('.filter-dropdown.open').forEach(function (d) {
                    d.classList.remove('open');
                });
                if (!isOpen) {
                    drop.classList.add('open');
                }
            });
        });

        // "Todos" en cada grupo
        document.querySelectorAll('.filter-check-all').forEach(function (chkAll) {
            chkAll.addEventListener('change', function () {
                const group = chkAll.dataset.group;
                document.querySelectorAll('.filter-check-' + group).forEach(function (chk) {
                    chk.checked = chkAll.checked;
                });
                updateFiltersAndRefetch();
                updateFilterBadge(group);
            });
        });

        // Checkboxes individuales
        ['manager', 'tipo', 'status'].forEach(function (group) {
            document.querySelectorAll('.filter-check-' + group).forEach(function (chk) {
                chk.addEventListener('change', function () {
                    syncAllCheck(group);
                    updateFiltersAndRefetch();
                    updateFilterBadge(group);
                });
            });
        });

        // Botón limpiar filtros
        const btnClear = document.getElementById('btnClearFilters');
        if (btnClear) {
            btnClear.addEventListener('click', function () {
                document.querySelectorAll('.filter-check-all, .filter-check-manager, .filter-check-tipo, .filter-check-status')
                    .forEach(function (chk) { chk.checked = true; });
                activeFilters = { status: [], tipo_atencion: [], fk_user: [] };
                if (calendar) calendar.refetchEvents();
                updateAllFilterBadges();
            });
        }
    }

    function syncAllCheck(group) {
        const allChks = document.querySelectorAll('.filter-check-' + group);
        const allChecked = Array.from(allChks).every(function (c) { return c.checked; });
        const allCheck = document.querySelector('.filter-check-all[data-group="' + group + '"]');
        if (allCheck) allCheck.checked = allChecked;
    }

    function updateFiltersAndRefetch() {
        // ── Gestores ──
        activeFilters.fk_user = [];
        document.querySelectorAll('.filter-check-manager:checked').forEach(function (chk) {
            var val = parseInt(chk.value, 10);
            if (!isNaN(val)) activeFilters.fk_user.push(val);
        });
        var totalManagers = document.querySelectorAll('.filter-check-manager').length;
        if (activeFilters.fk_user.length === totalManagers) {
            activeFilters.fk_user = []; // vacío = todos (sin filtro)
        } else if (totalManagers > 0 && activeFilters.fk_user.length === 0) {
            activeFilters.fk_user = [-1]; // ninguno seleccionado → sentinel, no hay coincidencias
        }

        // ── Tipos ──
        activeFilters.tipo_atencion = [];
        document.querySelectorAll('.filter-check-tipo:checked').forEach(function (chk) {
            if (chk.value) activeFilters.tipo_atencion.push(chk.value);
        });
        var totalTipos = document.querySelectorAll('.filter-check-tipo').length;
        if (activeFilters.tipo_atencion.length === totalTipos) {
            activeFilters.tipo_atencion = []; // vacío = todos
        } else if (totalTipos > 0 && activeFilters.tipo_atencion.length === 0) {
            activeFilters.tipo_atencion = ['__none__']; // sentinel para tipos
        }

        // ── Estados ──
        activeFilters.status = [];
        document.querySelectorAll('.filter-check-status:checked').forEach(function (chk) {
            var val = parseInt(chk.value, 10);
            if (!isNaN(val)) activeFilters.status.push(val);
        });
        var totalStatus = document.querySelectorAll('.filter-check-status').length;
        if (activeFilters.status.length === totalStatus) {
            activeFilters.status = []; // vacío = todos
        } else if (totalStatus > 0 && activeFilters.status.length === 0) {
            activeFilters.status = [-1]; // sentinel para estados
        }

        if (calendar) {
            calendar.refetchEvents();
        }
    }

    function updateFilterBadge(group) {
        // Muestra el número de elementos seleccionados en el botón del dropdown
        const groupMap = { manager: 'filterManagers', tipo: 'filterTypes', status: 'filterStatus' };
        const groupEl = document.getElementById(groupMap[group]);
        if (!groupEl) return;
        const checkedCount = groupEl.querySelectorAll('.filter-check-' + group + ':checked').length;
        const totalCount = groupEl.querySelectorAll('.filter-check-' + group).length;
        const textEl = groupEl.querySelector('.filter-selected-text');
        if (!textEl) return;
        if (checkedCount === 0) {
            textEl.textContent = 'Ninguno';
        } else if (checkedCount === totalCount) {
            textEl.textContent = 'Todos';
        } else {
            textEl.textContent = checkedCount + ' seleccionados';
        }
    }

    function updateAllFilterBadges() {
        ['manager', 'tipo', 'status'].forEach(updateFilterBadge);
    }

    function initCloseDropdowns() {
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.filter-multiselect')) {
                document.querySelectorAll('.filter-dropdown.open').forEach(function (d) {
                    d.classList.remove('open');
                });
            }
        });
    }

    // =========================================================
    // NOTIFICACIONES TOAST
    // =========================================================
    function showToast(type, message) {
        if (!$toastContainer) return;
        const toast = document.createElement('div');
        toast.className = 'cal-toast cal-toast-' + type;
        toast.innerHTML = '<span class="cal-toast-icon">' + (type === 'success' ? '✓' : '✗') + '</span>'
            + '<span class="cal-toast-msg">' + escapeHtml(message) + '</span>';
        $toastContainer.appendChild(toast);
        // Animar entrada
        requestAnimationFrame(function () {
            toast.classList.add('visible');
        });
        // Auto-remover tras 4s
        setTimeout(function () {
            toast.classList.remove('visible');
            setTimeout(function () { toast.remove(); }, 400);
        }, 4000);
    }

    // =========================================================
    // COLOR PICKER — Helpers de color
    // =========================================================
    function initColorPicker() {
        // El color picker se inicializa dinámicamente en showColorPicker()
        // Aquí no hay nada estático que inicializar.
    }

    function darkenColor(hex, amount) {
        hex = hex.replace('#', '');
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        const r = Math.max(0, parseInt(hex.substring(0, 2), 16) - amount);
        const g = Math.max(0, parseInt(hex.substring(2, 4), 16) - amount);
        const b = Math.max(0, parseInt(hex.substring(4, 6), 16) - amount);
        return '#' + ('0' + r.toString(16)).slice(-2)
            + ('0' + g.toString(16)).slice(-2)
            + ('0' + b.toString(16)).slice(-2);
    }

    function getLuminance(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        const r = parseInt(hex.substring(0, 2), 16);
        const g = parseInt(hex.substring(2, 4), 16);
        const b = parseInt(hex.substring(4, 6), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    }

    // =========================================================
    // Utilidades
    // =========================================================
    function formatDateDisplay(date) {
        if (!date) return '—';
        const d = new Date(date);
        return d.toLocaleDateString('es-CO', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})();