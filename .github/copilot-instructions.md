# Dolibarr CRM-RCV — Instrucciones para Copilot

## Descripción del proyecto

Este es un sistema CRM médico basado en **Dolibarr 21.x** con el módulo **Dolimed (cabinetmed)** para gestión de pacientes y consultas médicas. El proyecto se despliega en `crm.rcvco.org` y usa MySQL/MariaDB con prefijo `llx_`.

## Entorno de desarrollo

- **No hay entorno local.** El proyecto NO corre localmente, no hay servidor PHP ni base de datos en la máquina de desarrollo.
- Los cambios se suben manualmente por **FTP** al servidor de producción para probarlos.
- **No ejecutar comandos PHP, Composer, ni scripts de servidor** en la terminal — no funcionarán.
- **No se usa Git.** El proyecto no tiene repositorio git. Para comparar archivos usar `fc` o `Compare-Object` en PowerShell, o leer ambos archivos directamente con las herramientas de edición.
- Las tareas válidas en terminal se limitan a: operaciones de archivos (copiar, mover, renombrar) y herramientas de edición.
- Para probar SQL, se debe hacer directamente en el servidor (phpMyAdmin u otra herramienta remota).

## Zona de trabajo

**IMPORTANTE: Todo el desarrollo se realiza dentro de `custom/`.** No se deben modificar archivos del core de Dolibarr ni de Dolimed (`cabinetmed/`) directamente. Si se necesita corregir comportamiento del core o de Dolimed, se hace a través de módulos propios dentro de `custom/`.

### Módulos propios (se pueden modificar libremente)

| Módulo | Propósito |
|--------|-----------|
| `custom/cabinetmedfix/` | Correcciones y parches a Dolimed (cabinetmed) |
| `custom/cabinetmed_extcons/` | Extensión de consultas de Dolimed |
| `custom/cabinetmed_calendar/` | Calendario para Dolimed |
| `custom/gestion/` | Gestión de EPS, medicamentos, médicos, operadores, programas |
| `custom/whatsappdati/` | Integración con WhatsApp (chatbot, conversaciones, templates, webhooks) |
| `custom/rcv_analytics/` | Dashboard de analíticas y reportes |
| `custom/medtriggers/` | Triggers personalizados para el módulo médico |
| `custom/importfill/` | Importación y llenado de datos |

### Archivos que NO se deben modificar

- **Core de Dolibarr** (`htdocs/` raíz): Ningún archivo fuera de `custom/` debe tocarse.
- **Dolimed original** (`custom/cabinetmed/`): Es un módulo de terceros. Los fixes se hacen en `custom/cabinetmedfix/`.
- **`conf/conf.php`**: Contiene credenciales y configuración del servidor. No modificar.
- **Archivos `.zip`** en `custom/`: Son backups, ignorarlos.

## Convenciones de código

### Estructura de un módulo Dolibarr

```
custom/mi_modulo/
├── class/          # Clases PHP del módulo
├── core/
│   ├── modules/    # Descriptor del módulo (modMiModulo.class.php)
│   ├── triggers/   # Triggers (interface_99_modMiModulo_MiModuloTriggers.class.php)
│   └── tpl/        # Templates
├── admin/          # Páginas de configuración del módulo
├── css/            # Estilos
├── js/             # JavaScript
├── langs/          # Traducciones (es_ES/, en_US/)
├── lib/            # Funciones helper
├── sql/            # Scripts SQL de instalación/actualización
└── img/            # Imágenes
```

### Patrones PHP en Dolibarr

- Incluir `main.inc.php` al inicio de cada página con el patrón de resolución de rutas:
  ```php
  $res = 0;
  if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
  if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
  if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
  if (!$res) die("Include of main fails");
  ```
- Usar `dol_include_once()` para incluir archivos de módulos custom.
- Usar `DOL_DOCUMENT_ROOT` para incluir archivos del core de Dolibarr.
- Usar las funciones de Dolibarr para entrada de datos: `GETPOST()`, `GETPOSTINT()`, `GETPOSTISSET()`.
- Usar `$langs->load()` o `$langs->loadLangs()` para traducciones.
- Usar `$user->hasRight()` para verificar permisos.
- Las clases PHP siguen el patrón de Dolibarr extendiéndo `CommonObject`.
- Prefijo de tablas en SQL: `llx_`.
- Base de datos: MySQL/MariaDB, charset `utf8`.

### Idioma

- El código (variables, funciones, clases) se escribe en **inglés**.
- Los comentarios y textos de usuario pueden estar en **español**.
- Los archivos de traducción van en `langs/es_ES/` y `langs/en_US/`.

## Base de datos

- Motor: MySQL/MariaDB
- Prefijo de tablas: `llx_`
- Collation: `utf8_unicode_ci`
- Las migraciones SQL van en `sql/` de cada módulo con archivos `llx_nombre_tabla.sql` y `llx_nombre_tabla.key.sql`

## Seguridad

- Nunca exponer credenciales ni datos sensibles de `conf/conf.php`.
- Siempre sanitizar input con las funciones de Dolibarr (`GETPOST`, `GETPOSTINT`, etc.).
- Verificar permisos con `$user->hasRight()` o `accessforbidden()`.
- No usar `$_GET`, `$_POST`, `$_REQUEST` directamente.
