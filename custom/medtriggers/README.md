# MedTriggers - Campos Condicionales para Dolibarr

## Descripción
Muestra u oculta campos extrafields según el valor de un checkbox.

## Instalación
1. Descomprime en `htdocs/custom/medtriggers/`
2. Activa el módulo en Configuración → Módulos

## Configuración
Edita `js/medtriggers.js` y modifica `CONFIG`:
```javascript
var CONFIG = {
    'mi_checkbox': ['campo1', 'campo2'],
};
```

## Debugging
Cambia `DEBUG: false` a `DEBUG: true` y revisa la consola del navegador.

## Versión
1.0.2 - DatiLab
