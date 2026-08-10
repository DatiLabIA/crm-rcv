# MedTriggers v2.2.0 - Campos Condicionales + Selects Dependientes

## Descripción
Módulo Dolibarr con dos funcionalidades para fichas de pacientes/terceros:

1. **Toggle Show/Hide**: Muestra u oculta extrafields según el valor de un checkbox.
2. **Selects Dependientes**: Al seleccionar un medicamento, carga dinámicamente las concentraciones asociadas vía AJAX.

**Dependencia:** Requiere el módulo **Gestion** activo (tablas `llx_gestion_medicamento` y `llx_gestion_medicamento_det`).

## Instalación

1. Descomprime en `htdocs/custom/medtriggers/`
2. Asegúrate de que el módulo **Gestion** esté activo
3. Activa el módulo MedTriggers en **Configuración → Módulos**

## Compatibilidad

Funciona en fichas de:
- Terceros estándar (`/societe/card.php`)
- Pacientes CabinetMed (`/cabinetmed/card.php`)
- Consultas CabinetMed

El JS es **autocontenido**: auto-detecta la URL AJAX desde su propio `<script src>`.
No depende del hook PHP para funcionar (el hook es un override opcional).

## Tablas utilizadas (del módulo Gestion)

### `llx_gestion_medicamento` (maestro)
| Campo     | Tipo         | Descripción                    |
|-----------|--------------|--------------------------------|
| rowid     | INT (PK)     | ID del medicamento             |
| ref       | VARCHAR(128) | Referencia                     |
| etiqueta  | VARCHAR(255) | Nombre del medicamento         |
| estado    | TINYINT      | 1=Activo, 0=Inactivo           |

### `llx_gestion_medicamento_det` (concentraciones)
| Campo                 | Tipo          | Descripción                              |
|-----------------------|---------------|------------------------------------------|
| rowid                 | INT (PK)      | ID de la concentración                   |
| fk_medicamento        | INT (FK)      | Referencia a gestion_medicamento.rowid   |
| concentracion         | VARCHAR(100)  | Valor de concentración                   |
| unidad                | VARCHAR(50)   | Unidad de medida                         |
| concentracion_display | VARCHAR(155)  | Columna calculada: "concentracion unidad"|

## Configuración

### Toggle Show/Hide
Edita `TOGGLE_CONFIG` en `js/medtriggers.js`:

```javascript
var TOGGLE_CONFIG = {
    'mi_checkbox': ['campo_dependiente1', 'campo_dependiente2'],
};
```

### Selects Dependientes
Edita `DEFAULT_DEPENDENT_SELECTS` en `js/medtriggers.js`:

```javascript
var DEFAULT_DEPENDENT_SELECTS = {
    'medicamento': {
        childField:  'concentracion',
        ajaxParam:   'medicamento_id',
        emptyLabel:  '-- Seleccione concentración --'
    }
};
```

Opcionalmente, el hook PHP puede sobreescribir esta config inyectando `window.MedTriggersConf`.

## Debugging

En `js/medtriggers.js`, cambia `DEBUG: false` a `DEBUG: true` y revisa la consola del navegador (F12).

## Changelog

- **v2.2.0** - JS autocontenido (auto-detecta URL AJAX, config embebida). Eliminada dependencia del hook PHP. Ampliados contextos de hook para CabinetMed. Soporte Select2 v4 mejorado.
- **v2.1.0** - Migración a tablas del módulo Gestion.
- **v2.0.0** - Selects dependientes con carga AJAX.
- **v1.0.0** - Toggle show/hide de campos condicionales.

## Versión
2.2.0 - DatiLab
