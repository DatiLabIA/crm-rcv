# Plan de Desarrollo: Módulo Calendario de Consultas (cabinetmed_calendar)

## 1. Resumen del Módulo

Nuevo módulo Dolibarr (`cabinetmed_calendar`) que proporciona una vista de calendario interactiva para las consultas extendidas de pacientes del módulo DoliMed (`llx_cabinetmed_extcons`), con drag & drop para reorganizar fechas, etiquetas de color, y filtros avanzados por gestor, tipo de atención y estado.

---

## 2. Arquitectura Técnica

### 2.1 Datos origen (ya existentes, sin modificar)

| Tabla | Descripción |
|-------|-------------|
| `llx_cabinetmed_extcons` | Consultas con `date_start`, `date_end`, `fk_soc`, `fk_user`, `tipo_atencion`, `status` |
| `llx_cabinetmed_extcons_types` | Tipos de atención (código + label) |
| `llx_cabinetmed_extcons_users` | Múltiples gestores asignados por consulta |
| `llx_societe` | Pacientes (terceros con canvas `patient@cabinetmed`) |

### 2.2 Tabla nueva (para etiquetas de color por usuario)

```sql
CREATE TABLE llx_cabinetmed_calendar_colors (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_extcons      INTEGER NOT NULL,
    fk_user         INTEGER NOT NULL,   -- Cada usuario puede poner su propio color
    color           VARCHAR(7) NOT NULL, -- Hex color (#FF5733)
    datec           DATETIME,
    UNIQUE KEY uk_calendar_color (fk_extcons, fk_user)
) ENGINE=innodb;
```

### 2.3 Librería Frontend

- **FullCalendar v6** (CDN o local en `/custom/cabinetmed_calendar/js/`)
  - Vista mes, semana, día
  - Drag & drop nativo para mover eventos
  - Resize para cambiar duración
  - Soporte de multi-día (`date_start` → `date_end`)

### 2.4 Estructura del módulo

```
custom/cabinetmed_calendar/
├── core/
│   └── modules/
│       └── modCabinetMedCalendar.class.php    # Descriptor del módulo
├── class/
│   └── actions_cabinetmedcalendar.class.php   # Hooks
├── sql/
│   ├── llx_cabinetmed_calendar_colors.sql
│   └── llx_cabinetmed_calendar_colors.key.sql
├── langs/
│   └── es_ES/
│       └── cabinetmed_calendar.lang
├── css/
│   └── calendar.css
├── js/
│   ├── fullcalendar/                          # FullCalendar 6 (bundle)
│   └── calendar-app.js                        # Lógica del calendario
├── lib/
│   └── cabinetmed_calendar.lib.php
├── admin/
│   └── setup.php                              # Configuración del módulo
├── calendar.php                               # Página principal del calendario
├── ajax/
│   ├── get_events.php                         # API JSON: obtener consultas
│   ├── update_event.php                       # API JSON: mover/redimensionar
│   └── update_color.php                       # API JSON: cambiar color
└── README.md
```

---

## 3. Funcionalidades Detalladas

### 3.1 Vista de Calendario (`calendar.php`)

- **Vistas disponibles**: Mes (`dayGridMonth`), Semana (`timeGridWeek`), Día (`timeGridDay`)
- **Navegación**: Botones Anterior/Siguiente, botón "Hoy"
- **Eventos**: Cada consulta se muestra como bloque desde `date_start` hasta `date_end`
- **Click en evento**: Redirige a `consultation_card.php?id=X` para ver la consulta completa
- **Multi-día**: Los eventos que abarcan varios días se muestran como barras continuas

### 3.2 Drag & Drop

- **Mover evento**: Arrastra a otra fecha/hora → actualiza `date_start` y `date_end` manteniendo la duración original
- **Redimensionar evento**: Arrastra el borde inferior/derecho → cambia solo `date_end`
- **Endpoint AJAX**: `ajax/update_event.php` con token CSRF
- **Validación de permisos**: Solo el gestor asignado o un administrador puede mover/redimensionar consultas
- **Feedback visual**: Confirmación de éxito o error con notificación

### 3.3 Etiquetas de Color

- **Color por defecto según tipo de atención**: Cada `tipo_atencion` tiene un color predefinido configurable desde admin
- **Color personalizado por usuario**: Click derecho o botón de color sobre el evento → selector de color
- **Prioridad de colores**: Color personalizado del usuario > Color del tipo de atención > Color del estado
- **Persistencia**: Tabla `llx_cabinetmed_calendar_colors`

### 3.4 Sistema de Filtros

| Filtro | Tipo de control | Fuente de datos |
|--------|----------------|-----------------|
| Por día | DatePicker / Navegación | Navegación integrada de FullCalendar |
| Por semana | Botón de vista | FullCalendar built-in (`timeGridWeek`) |
| Por mes | Botón de vista | FullCalendar built-in (`dayGridMonth`) |
| Por estado | Multi-select con checkboxes | `ExtConsultation::getStatusArray()` → 0=En progreso, 1=Completada, 2=Cancelada |
| Por tipo de atención | Multi-select desplegable | `llx_cabinetmed_extcons_types` (tabla de tipos dinámicos) |
| Por gestor | Multi-select con avatar | `llx_user` filtrado por gestores que aparecen en `llx_cabinetmed_extcons_users` |

> Los filtros se aplican vía AJAX sin recargar la página. Se envían como parámetros GET al endpoint `ajax/get_events.php`, y al cambiar cualquier filtro se hace `calendar.refetchEvents()`.

---

## 4. Endpoints AJAX

### 4.1 `GET ajax/get_events.php`

Obtiene las consultas en formato JSON compatible con FullCalendar.

**Parámetros de entrada:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `start` | date ISO | Sí | Inicio del rango visible (enviado automáticamente por FullCalendar) |
| `end` | date ISO | Sí | Fin del rango visible |
| `status[]` | int[] | No | Filtrar por estados (0, 1, 2) |
| `tipo_atencion[]` | string[] | No | Filtrar por códigos de tipo de atención |
| `fk_user[]` | int[] | No | Filtrar por IDs de gestores asignados |
| `token` | string | Sí | Token CSRF de Dolibarr |

**Respuesta JSON:**

```json
[
  {
    "id": 123,
    "title": "Juan Pérez - Adherencia",
    "start": "2026-03-13T09:00:00",
    "end": "2026-03-13T10:00:00",
    "url": "/custom/cabinetmed_extcons/consultation_card.php?id=123",
    "color": "#4CAF50",
    "borderColor": "#388E3C",
    "textColor": "#ffffff",
    "extendedProps": {
      "patient_id": 456,
      "patient_name": "Juan Pérez",
      "tipo_atencion": "adherencia",
      "tipo_atencion_label": "Adherencia",
      "status": 0,
      "status_label": "En progreso",
      "gestores": ["Dr. García", "Enf. López"],
      "fk_user": 7
    }
  }
]
```

**Lógica SQL principal:**

```sql
SELECT c.rowid, c.date_start, c.date_end, c.tipo_atencion, c.status, c.fk_soc, c.fk_user,
       s.nom as patient_name,
       t.label as tipo_label
FROM llx_cabinetmed_extcons c
LEFT JOIN llx_societe s ON s.rowid = c.fk_soc
LEFT JOIN llx_cabinetmed_extcons_types t ON t.code = c.tipo_atencion AND t.entity IN (...)
WHERE c.entity IN (getEntity())
  AND c.date_start < :end
  AND (c.date_end > :start OR (c.date_end IS NULL AND c.date_start >= :start))
  -- Filtros opcionales:
  AND c.status IN (:status_list)
  AND c.tipo_atencion IN (:tipo_list)
  -- Filtro por gestor (subconsulta a tabla de usuarios asignados):
  AND EXISTS (
    SELECT 1 FROM llx_cabinetmed_extcons_users u
    WHERE u.fk_extcons = c.rowid AND u.fk_user IN (:user_list)
  )
ORDER BY c.date_start ASC
```

### 4.2 `POST ajax/update_event.php`

Actualiza las fechas de una consulta después de un drag & drop o resize.

**Parámetros de entrada:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `id` | int | Sí | ID de la consulta (`llx_cabinetmed_extcons.rowid`) |
| `date_start` | datetime ISO | Sí | Nueva fecha/hora de inicio |
| `date_end` | datetime ISO | Sí | Nueva fecha/hora de fin |
| `token` | string | Sí | Token CSRF de Dolibarr |

**Respuesta JSON:**

```json
// Éxito:
{"success": true, "message": "Consulta actualizada correctamente"}

// Error:
{"success": false, "error": "No tiene permisos para modificar esta consulta"}
```

**Validaciones:**
1. Token CSRF válido
2. Usuario tiene permiso `cabinetmed_extcons->write`
3. La consulta existe y pertenece a la misma entity
4. `date_end >= date_start`
5. (Opcional) El usuario es gestor asignado o es admin

### 4.3 `POST ajax/update_color.php`

Asigna o actualiza el color personalizado de una consulta para el usuario actual.

**Parámetros de entrada:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `id` | int | Sí | ID de la consulta |
| `color` | string | Sí | Código hexadecimal del color (`#FF5733`) |
| `token` | string | Sí | Token CSRF |

**Respuesta JSON:**

```json
{"success": true, "color": "#FF5733"}
```

---

## 5. Fases de Desarrollo

### Fase 1: Estructura base del módulo

| # | Tarea | Archivo(s) |
|---|-------|------------|
| 1 | Crear descriptor del módulo | `core/modules/modCabinetMedCalendar.class.php` |
| 2 | Crear SQL tabla de colores | `sql/llx_cabinetmed_calendar_colors.sql`, `.key.sql` |
| 3 | Crear archivos de idioma | `langs/es_ES/cabinetmed_calendar.lang` |
| 4 | Crear funciones helper | `lib/cabinetmed_calendar.lib.php` |
| 5 | Integrar FullCalendar v6 (JS/CSS) | `js/fullcalendar/`, CDN fallback |

### Fase 2: Calendario básico (lectura)

| # | Tarea | Archivo(s) |
|---|-------|------------|
| 6 | Crear página principal con layout Dolibarr | `calendar.php` |
| 7 | Crear endpoint API para obtener eventos | `ajax/get_events.php` |
| 8 | Implementar inicialización FullCalendar | `js/calendar-app.js` |
| 9 | Renderizar consultas como eventos (título: "Paciente - Tipo") | `js/calendar-app.js` |
| 10 | Click en evento → navegar a `consultation_card.php` | `js/calendar-app.js` |

### Fase 3: Drag & Drop

| # | Tarea | Archivo(s) |
|---|-------|------------|
| 11 | Crear endpoint para actualizar fechas con validación | `ajax/update_event.php` |
| 12 | Habilitar `editable: true` en configuración FullCalendar | `js/calendar-app.js` |
| 13 | Implementar handler `eventDrop` (mover fecha manteniendo duración) | `js/calendar-app.js` |
| 14 | Implementar handler `eventResize` (cambiar fecha fin) | `js/calendar-app.js` |
| 15 | Feedback visual: notificación de éxito/error + revert en caso de fallo | `js/calendar-app.js` |

### Fase 4: Filtros avanzados

| # | Tarea | Archivo(s) |
|---|-------|------------|
| 16 | Panel de filtros en la parte superior de la página | `calendar.php` |
| 17 | Multi-select de gestores (carga desde BD) | `calendar.php`, `js/calendar-app.js` |
| 18 | Multi-select de tipos de atención (carga desde `extcons_types`) | `calendar.php`, `js/calendar-app.js` |
| 19 | Multi-select/checkboxes de estados | `calendar.php`, `js/calendar-app.js` |
| 20 | Refetch automático (`calendar.refetchEvents()`) al cambiar filtros | `js/calendar-app.js` |

### Fase 5: Colores y etiquetas

| # | Tarea | Archivo(s) |
|---|-------|------------|
| 21 | Crear endpoint para guardar color personalizado | `ajax/update_color.php` |
| 22 | Asignar colores por defecto según `tipo_atencion` (configurable) | `ajax/get_events.php`, `admin/setup.php` |
| 23 | Color picker en popover/tooltip al hacer clic derecho sobre evento | `js/calendar-app.js`, `css/calendar.css` |
| 24 | Cargar y aplicar colores personalizados del usuario desde BD | `ajax/get_events.php` |

### Fase 6: Integración, menú y configuración

| # | Tarea | Archivo(s) |
|---|-------|------------|
| 25 | Añadir entrada de menú principal y lateral en el descriptor | `core/modules/modCabinetMedCalendar.class.php` |
| 26 | Hook para añadir enlace al calendario desde la ficha de paciente | `class/actions_cabinetmedcalendar.class.php` |
| 27 | Página de configuración admin (colores por defecto, vista inicial, horarios) | `admin/setup.php` |
| 28 | Testing completo, ajustes responsive y documentación | Todos los archivos |

---

## 6. Permisos y Seguridad

| Acción | Permiso requerido |
|--------|-------------------|
| Ver calendario | `cabinetmed->read` **O** `cabinetmed_extcons->read` |
| Mover/redimensionar consulta | `cabinetmed_extcons->write` + (ser gestor asignado **O** admin) |
| Cambiar color de etiqueta | `cabinetmed_extcons->read` (solo afecta la vista del propio usuario) |
| Ver consultas de otros gestores | `cabinetmed_extcons->read` (todos los gestores pueden ver; se respeta `entity`) |
| Configurar módulo | `$user->admin` |

### Medidas de seguridad implementadas:

- **CSRF**: Todos los endpoints AJAX validan el token CSRF de Dolibarr (`newToken()` / `verifyCond()`)
- **Multi-empresa**: Todas las consultas SQL filtran por `entity IN (getEntity())`
- **Sanitización de input**: Uso de `GETPOST()` con tipos específicos (`int`, `alpha`, `array`) y `$db->escape()`
- **Inyección SQL**: Sin concatenación directa de parámetros; uso de escape y casting
- **XSS**: Output siempre escapado con `dol_escape_htmltag()` en PHP y escaping nativo en FullCalendar
- **Control de acceso**: `restrictedArea()` y verificación de `$user->rights` en cada endpoint

---

## 7. Dependencias

| Componente | Versión | Uso | Notas |
|-----------|---------|-----|-------|
| **FullCalendar** | 6.x | Calendario interactivo, drag & drop, vistas | Se incluirá como bundle JS local |
| **Dolibarr** | ≥ 21.0 | Framework base, autenticación, layout | Requerido |
| **modCabinetMed** | Activo | Módulo médico base (pacientes, canvas) | Dependencia obligatoria |
| **modCabinetMedExtCons** | Activo | Consultas extendidas (datos origen del calendario) | Dependencia obligatoria |
| **jQuery** | (Dolibarr built-in) | AJAX, manipulación DOM | Ya incluido en Dolibarr |
| **jQuery UI** | (Dolibarr built-in) | Componentes de UI complementarios | Ya incluido en Dolibarr |

---

## 8. Configuraciones Admin (`admin/setup.php`)

| Clave de constante | Tipo | Valor por defecto | Descripción |
|-------------------|------|-------------------|-------------|
| `CABINETMED_CALENDAR_DEFAULT_VIEW` | select | `dayGridMonth` | Vista inicial del calendario (mes/semana/día) |
| `CABINETMED_CALENDAR_SLOT_DURATION` | string | `00:30:00` | Duración de los slots en vistas semana/día |
| `CABINETMED_CALENDAR_BUSINESS_HOURS_START` | string | `08:00` | Hora inicio jornada laboral |
| `CABINETMED_CALENDAR_BUSINESS_HOURS_END` | string | `18:00` | Hora fin jornada laboral |
| `CABINETMED_CALENDAR_FIRST_DAY` | int | `1` | Primer día de la semana (0=Domingo, 1=Lunes) |
| `CABINETMED_CALENDAR_COLOR_ADHERENCIA` | color | `#4CAF50` | Color por defecto: Adherencia |
| `CABINETMED_CALENDAR_COLOR_CONTROL` | color | `#2196F3` | Color por defecto: Control médico |
| `CABINETMED_CALENDAR_COLOR_ENFERMERIA` | color | `#FF9800` | Color por defecto: Enfermería |
| `CABINETMED_CALENDAR_COLOR_FARMACIA` | color | `#9C27B0` | Color por defecto: Farmacia |
| `CABINETMED_CALENDAR_COLOR_DEFAULT` | color | `#607D8B` | Color por defecto: Otros tipos |

---

## 9. Mockup Visual

### 9.1 Vista mensual

```
┌──────────────────────────────────────────────────────────────────────┐
│  📅 Calendario de Consultas                           [⚙ Configurar]│
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Filtros:                                                             │
│ [👤 Gestores       ▼]  [📋 Tipo Atención  ▼]  [📊 Estado       ▼] │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│   ◀   Marzo 2026   ▶        [Hoy]      [ Mes | Semana | Día ]      │
│                                                                      │
│   Lun      Mar      Mié      Jue      Vie      Sáb      Dom        │
│  ┌────────┬────────┬────────┬────────┬────────┬────────┬────────┐   │
│  │        │        │        │        │        │        │   1    │   │
│  ├────────┼────────┼────────┼────────┼────────┼────────┼────────┤   │
│  │   2    │   3    │   4    │   5    │   6    │   7    │   8    │   │
│  │        │▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│        │        │        │   │
│  │        │ J.Pérez - Adherencia ━━━>│        │        │        │   │
│  ├────────┼────────┼────────┼────────┼────────┼────────┼────────┤   │
│  │   9    │  10    │  11    │  12    │ *[13]* │  14    │  15    │   │
│  │        │▓▓▓▓▓▓▓▓│        │▓▓▓▓▓▓▓▓│▓▓▓▓▓▓▓▓│        │        │   │
│  │        │M.Rdz   │        │A.López │L.García│        │        │   │
│  │        │Adherenc│        │Control │Enfermer│        │        │   │
│  ├────────┼────────┼────────┼────────┼────────┼────────┼────────┤   │
│  │  16    │  17    │  18    │  19    │  20    │  21    │  22    │   │
│  │▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│        │        │        │   │
│  │ R.Soto - Farmacia ━━━━━━━━━━━━━━>│        │        │        │   │
│  ├────────┼────────┼────────┼────────┼────────┼────────┼────────┤   │
│  │  23    │  24    │  25    │  26    │  27    │  28    │  29    │   │
│  │        │        │        │▓▓▓▓▓▓▓▓│        │        │        │   │
│  │        │        │        │P.Díaz  │        │        │        │   │
│  ├────────┼────────┼────────┼────────┼────────┼────────┼────────┤   │
│  │  30    │  31    │        │        │        │        │        │   │
│  └────────┴────────┴────────┴────────┴────────┴────────┴────────┘   │
│                                                                      │
│  Leyenda:                                                            │
│  🟢 Adherencia   🔵 Control Médico   🟠 Enfermería   🟣 Farmacia  │
│  ── En progreso   ── Completada   ── Cancelada                      │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

### 9.2 Popover al hacer hover sobre un evento

```
┌──────────────────────────────────┐
│  Juan Pérez                      │
│  Tipo: Adherencia                │
│  Estado: 🟢 En progreso         │
│  Inicio: 13/03/2026 09:00       │
│  Fin:    13/03/2026 10:30       │
│  Gestores: Dr. García, Enf. López│
│                                  │
│  [🎨 Color]  [👁 Ver consulta]  │
└──────────────────────────────────┘
```

### 9.3 Selector de color al clic derecho

```
┌─────────────────────────┐
│  Etiquetar consulta:    │
│                         │
│  🔴 🟠 🟡 🟢 🔵 🟣 ⚫│
│  #F44 #FF9 #FFC #4CA    │
│                         │
│  [ Color personalizado ]│
│  [     Quitar color    ]│
└─────────────────────────┘
```

---

## 10. Flujo de Interacción del Usuario

### 10.1 Flujo principal: Ver calendario

```
1. Gestor accede a: Menú → Calendario Consultas
2. Se carga calendar.php con layout Dolibarr
3. FullCalendar se inicializa con la vista por defecto (mes)
4. Se llama a ajax/get_events.php con rango del mes visible
5. Las consultas asignadas al gestor se muestran como bloques de color
6. Las consultas multi-día se muestran como barras continuas
```

### 10.2 Flujo: Cambiar fecha con drag & drop

```
1. Gestor arrastra una consulta de Lunes a Miércoles
2. FullCalendar dispara evento `eventDrop`
3. JavaScript calcula nueva date_start y date_end (mantiene duración)
4. Se envía POST a ajax/update_event.php con nuevas fechas + CSRF token
5. Backend valida permisos y actualiza la BD
6. Si éxito → evento queda en nueva posición + notificación verde
7. Si error → evento vuelve a posición original + notificación roja
```

### 10.3 Flujo: Filtrar por gestor

```
1. Gestor abre el multi-select "Gestores" en el panel de filtros
2. Selecciona "Dr. García" y "Enf. López"
3. JavaScript llama calendar.refetchEvents()
4. ajax/get_events.php recibe fk_user[]=5&fk_user[]=8
5. SQL filtra consultas donde exista asignación a esos usuarios
6. Calendario se actualiza mostrando solo esas consultas
```

---

## 11. Inventario de Archivos a Crear

| # | Archivo | Descripción | Líneas est. |
|---|---------|-------------|-------------|
| 1 | `core/modules/modCabinetMedCalendar.class.php` | Descriptor del módulo, menús, permisos, constantes | ~250 |
| 2 | `sql/llx_cabinetmed_calendar_colors.sql` | CREATE TABLE para colores personalizados | ~15 |
| 3 | `sql/llx_cabinetmed_calendar_colors.key.sql` | Índices y constraints | ~10 |
| 4 | `langs/es_ES/cabinetmed_calendar.lang` | Traducciones al español | ~50 |
| 5 | `lib/cabinetmed_calendar.lib.php` | Funciones helper (colores, preparar head, etc.) | ~80 |
| 6 | `calendar.php` | Página principal del calendario con filtros | ~350 |
| 7 | `ajax/get_events.php` | API JSON para obtener consultas como eventos | ~180 |
| 8 | `ajax/update_event.php` | API JSON para mover/redimensionar consultas | ~120 |
| 9 | `ajax/update_color.php` | API JSON para cambiar color de etiqueta | ~90 |
| 10 | `js/calendar-app.js` | Lógica JavaScript de FullCalendar + filtros + colores | ~300 |
| 11 | `css/calendar.css` | Estilos personalizados del calendario | ~120 |
| 12 | `admin/setup.php` | Página de configuración del administrador | ~250 |
| 13 | `class/actions_cabinetmedcalendar.class.php` | Hooks para integración con ficha de paciente | ~60 |

**Total estimado: ~1,875 líneas de código**

---

## 12. Consideraciones Técnicas Adicionales

### 12.1 Rendimiento (Performance)

- Las consultas SQL solo traen eventos del rango visible (FullCalendar envía `start` y `end`)
- Se usa `LIMIT` implícito por rango de fechas; no se cargan todas las consultas de la BD
- Los colores personalizados se traen en un solo JOIN en la consulta principal
- El refetch de eventos al filtrar es ligero porque solo se consulta el rango visible

### 12.2 Responsive / Móvil

- FullCalendar v6 es responsive por defecto
- En pantallas pequeñas se usa `listWeek` como vista alternativa
- Los filtros se colapsan en un panel expandible en móvil
- El drag & drop se desactiva en dispositivos táctiles (o se usa `longPressDelay`)

### 12.3 Timezone

- Las fechas se almacenan en la BD en timezone del servidor Dolibarr
- FullCalendar se configura con `timeZone: 'local'` para mostrar en la zona del usuario
- La conversión se hace en el endpoint `get_events.php` usando `dol_print_date()` con formato ISO

### 12.4 Carga de FullCalendar

Se recomienda incluir FullCalendar como archivos locales para evitar dependencia de CDN:

```
js/fullcalendar/
├── index.global.min.js        # Core + plugins (bundle completo)
└── locales/es.global.min.js   # Localización español
```

Alternativa CDN (fallback):
```html
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
```

---

## 13. Criterios de Aceptación (Testing)

| # | Criterio | Verificación |
|---|---------|-------------|
| 1 | El calendario muestra las consultas del gestor logueado por defecto | Visual |
| 2 | Se pueden ver vistas de mes, semana y día | Click en botones |
| 3 | Las consultas multi-día se muestran como barras continuas | Visual |
| 4 | Drag & drop cambia `date_start` y `date_end` correctamente en BD | Verificar BD |
| 5 | Resize cambia solo `date_end` correctamente | Verificar BD |
| 6 | Al hacer clic en un evento, se abre `consultation_card.php` | Navegación |
| 7 | Filtro por gestor muestra solo consultas de los seleccionados | Visual + SQL |
| 8 | Filtro por tipo de atención funciona correctamente | Visual + SQL |
| 9 | Filtro por estado funciona correctamente | Visual + SQL |
| 10 | Los colores se asignan según tipo de atención por defecto | Visual |
| 11 | Se puede asignar un color personalizado a una consulta | Click derecho + verificar BD |
| 12 | Un usuario sin permiso `write` no puede mover consultas | Error 403 en AJAX |
| 13 | El token CSRF se valida en todos los endpoints POST | Probar sin token |
| 14 | El calendario funciona correctamente en móvil | Test responsive |
| 15 | El módulo se activa/desactiva sin errores | Configuración módulos |
