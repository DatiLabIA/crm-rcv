# cabinetmed_calendar — Módulo Calendario de Consultas DoliMed

**Versión:** 1.0.0  
**Autor:** DatiLab (https://datilab.co)  
**Compatibilidad Dolibarr:** ≥ 16.0  
**Licencia:** GNU GPL v3

---

## Descripción

Módulo Dolibarr que proporciona una vista de calendario interactiva para las consultas extendidas de pacientes del módulo **DoliMed** (`llx_cabinetmed_extcons`).

### Características principales

- **Vista mensual, semanal y diaria** — Powered by FullCalendar v6
- **Drag & Drop** — Arrastra consultas para cambiar fecha/hora
- **Resize** — Redimensiona para cambiar la duración
- **Filtros avanzados** — Por gestor, tipo de atención y estado, aplicados vía AJAX
- **Etiquetas de color** — Color por tipo de atención configurable + color personalizado por usuario
- **Popover de evento** — Detalles de la consulta y accesos rápidos al hacer clic
- **Multi-día** — Los eventos con fecha inicio/fin en días distintos se muestran como barras continuas
- **Seguridad** — CSRF, `restrictedArea()`, filtrado por entidad, sanitización de input

---

## Requisitos

| Dependencia             | Versión mínima | Notas                                         |
|-------------------------|---------------|-----------------------------------------------|
| Dolibarr                | 16.0          | Framework base                                |
| modCabinetMed           | Activo        | Módulo médico base (pacientes, canvas)         |
| modCabinetMedExtCons    | Activo        | Consultas extendidas (datos origen)            |
| FullCalendar            | 6.x           | Cargado desde CDN (jsdelivr.net)               |
| PHP                     | 7.4+          |                                               |
| MariaDB / MySQL         | 5.7+          |                                               |

---

## Instalación

1. Copia la carpeta `cabinetmed_calendar/` a `dolibarr/custom/`
2. Accede a **Inicio → Configuración → Módulos/Aplicaciones**
3. Busca **"Calendario de Consultas"** en la categoría CRM
4. Haz clic en **Activar**
5. El módulo ejecutará automáticamente los scripts SQL para crear la tabla `llx_cabinetmed_calendar_colors`

---

## Estructura de archivos

```
custom/cabinetmed_calendar/
├── core/modules/
│   └── modCabinetMedCalendar.class.php    # Descriptor del módulo
├── class/
│   └── actions_cabinetmedcalendar.class.php # Hooks integración ficha paciente
├── sql/
│   ├── llx_cabinetmed_calendar_colors.sql  # Tabla de colores
│   └── llx_cabinetmed_calendar_colors.key.sql # Índices
├── langs/es_ES/
│   └── cabinetmed_calendar.lang            # Traducciones ES
├── lib/
│   └── cabinetmed_calendar.lib.php         # Funciones helper
├── css/
│   └── calendar.css                        # Estilos del calendario
├── js/
│   └── calendar-app.js                     # Lógica FullCalendar + filtros + colores
├── admin/
│   └── setup.php                           # Configuración administrador
├── ajax/
│   ├── get_events.php                      # API JSON eventos
│   ├── update_event.php                    # API JSON mover/redimensionar
│   └── update_color.php                    # API JSON cambiar color
├── calendar.php                            # Página principal
└── README.md
```

---

## Configuración

Accede a **Calendario → Configuración** (solo administradores) para:

| Parámetro                                    | Por defecto    | Descripción                                        |
|---------------------------------------------|---------------|----------------------------------------------------|
| `CABINETMED_CALENDAR_DEFAULT_VIEW`           | `dayGridMonth` | Vista inicial (mes/semana/día/lista)              |
| `CABINETMED_CALENDAR_SLOT_DURATION`          | `00:30:00`    | Duración de slots en vistas semana/día             |
| `CABINETMED_CALENDAR_BUSINESS_HOURS_START`   | `08:00`       | Hora inicio jornada laboral                        |
| `CABINETMED_CALENDAR_BUSINESS_HOURS_END`     | `18:00`       | Hora fin jornada laboral                           |
| `CABINETMED_CALENDAR_FIRST_DAY`              | `1` (Lunes)   | Primer día de la semana                            |
| `CABINETMED_CALENDAR_COLOR_ADHERENCIA`       | `#4CAF50`     | Color tipo Adherencia                              |
| `CABINETMED_CALENDAR_COLOR_CONTROL`          | `#2196F3`     | Color tipo Control Médico                          |
| `CABINETMED_CALENDAR_COLOR_ENFERMERIA`       | `#FF9800`     | Color tipo Enfermería                              |
| `CABINETMED_CALENDAR_COLOR_FARMACIA`         | `#9C27B0`     | Color tipo Farmacia                                |
| `CABINETMED_CALENDAR_COLOR_DEFAULT`          | `#607D8B`     | Color para tipos no configurados                   |

---

## Permisos

| Acción                        | Permiso requerido                                                        |
|-------------------------------|-------------------------------------------------------------------------|
| Ver calendario                | `cabinetmed_calendar->read` O `cabinetmed_extcons->read`               |
| Mover/redimensionar consultas | `cabinetmed_calendar->write` O `cabinetmed_extcons->write` + ser gestor|
| Cambiar color de etiqueta     | `cabinetmed_extcons->read` (afecta solo la vista del usuario)          |
| Configurar módulo             | `$user->admin`                                                          |

---

## Endpoints AJAX

| Endpoint                      | Método | Descripción                                |
|-------------------------------|-------|--------------------------------------------|
| `ajax/get_events.php`         | GET   | Devuelve eventos en formato FullCalendar   |
| `ajax/update_event.php`       | POST  | Actualiza fechas tras drag & drop / resize |
| `ajax/update_color.php`       | POST  | Guarda o elimina color personalizado       |

Todos los endpoints validan el **token CSRF** de Dolibarr y filtran por **entidad**.

---

## Tabla SQL creada

```sql
CREATE TABLE llx_cabinetmed_calendar_colors (
    rowid       INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_extcons  INTEGER NOT NULL,   -- FK a llx_cabinetmed_extcons
    fk_user     INTEGER NOT NULL,   -- FK a llx_user (por usuario)
    color       VARCHAR(7) NOT NULL,-- Hex color (#RRGGBB)
    datec       DATETIME,
    tms         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_calendar_color (fk_extcons, fk_user)
) ENGINE=innodb;
```

---

## Historial de cambios

### v1.0.0 (2024)
- Versión inicial completa
- Calendario FullCalendar v6 con vistas mes/semana/día/lista
- Drag & drop y resize con validación de permisos
- Sistema de filtros AJAX (gestor, tipo, estado)
- Etiquetas de color personalizadas por usuario
- Popover de evento con acciones rápidas
- Página de configuración admin
- Hook de integración con ficha de paciente

---

## Soporte

**DatiLab** — https://datilab.co  
Reportar issues en el repositorio interno del proyecto.
