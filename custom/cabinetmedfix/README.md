# CabinetMedFix Module

## Descripción

Módulo independiente para corregir automáticamente problemas en CabinetMed sin modificar el código original. Además, incluye un sistema completo de auditoría de cambios para terceros/pacientes.

## Problemas que corrige

### 1. URLs incorrectas
- **Problema:** Los enlaces en la lista de pacientes apuntan a `/societe/card.php` (core de Dolibarr) en lugar de `/custom/cabinetmed/card.php` (módulo CabinetMed)
- **Solución:** Intercepta y corrige automáticamente todas las URLs mediante hooks de PHP y JavaScript

### 2. Campo client se pone en 0
- **Problema:** Al editar un paciente, el campo `client` cambia de 3 a 0, causando que el paciente desaparezca de las listas
- **Causa:** Las configuraciones `SOCIETE_DISABLE_CUSTOMERS` y `SOCIETE_DISABLE_PROSPECTS` ocultan los checkboxes en el formulario estándar
- **Solución:** Fuerza automáticamente el valor `client=3` para pacientes al guardar

### 3. Tablas no responsive
- **Problema:** En móviles y tablets, la tabla de pacientes se desborda mucho y es muy incómodo de usar
- **Solución:** Mejoras responsive automáticas

### 4. Historial de cambios / Auditoría (NUEVO v2.0 ✨)
- **Problema:** No se guardaba un registro detallado de qué campos se modificaban al editar un tercero/paciente
- **Solución:** Sistema completo de auditoría que:
  - 📝 Registra **cada campo modificado** individualmente (nombre, dirección, teléfono, etc.)
  - 🏷️ Incluye **campos personalizados (extrafields)** con sus nombres legibles
  - 📊 Guarda **valor anterior y nuevo** para cada cambio
  - 👤 Registra **quién** hizo el cambio, **cuándo** y desde qué **IP**
  - 📋 Pestaña **"Historial cambios"** en la ficha de cada tercero/paciente
  - 📅 Evento en la **agenda** de Dolibarr con resumen de cambios
  - 📥 **Exportación CSV** del historial completo
  - 🔍 **Filtros** por fecha, campo y usuario

## Instalación

1. El módulo ya está instalado en `/custom/cabinetmedfix/`
2. Ve a: **Inicio → Configuración → Módulos/Aplicaciones**
3. Busca: **CabinetMed Fix**
4. Haz clic en **Activar**

## Requisitos

- Dolibarr 17.0 o superior
- Módulo CabinetMed activo

## Ventajas

✅ **No modifica código original** - Usa solo hooks y JavaScript  
✅ **Sobrevive a actualizaciones** - Completamente independiente  
✅ **Fácil de activar/desactivar** - Solo un clic  
✅ **Sin mantenimiento** - Funciona automáticamente  
✅ **Sin efectos secundarios** - Solo afecta a pacientes de CabinetMed  

## Cómo funciona

### Corrección de URLs (3 capas de protección)

1. **Hook PHP `getNomUrl`**: Intercepta llamadas al método `getNomUrl()` de la clase `Societe` y genera URLs correctas para pacientes
2. **JavaScript preventivo**: Corrige URLs en el HTML antes de hacer clic
3. **JavaScript reactivo**: Intercepta clics y redirige a la URL correcta

### Corrección del campo client

1. **Hook PHP `doActions`**: Antes de guardar un paciente, verifica que `client` no sea 0 y lo corrige a 3
2. **JavaScript en formulario**: Añade/corrige campo oculto `client=3` en formularios de edición

### Mejoras responsive (CSS + JavaScript)

1. **CSS adaptativo**:
   - Desktop (>1024px): Sticky header, primera columna fija
   - Tablet (768-1024px): Oculta columnas secundarias, optimiza espacios
   - Móvil (<768px): Tabla compacta con scroll horizontal
   - Móvil pequeño (<480px): Opción de vista cards

2. **JavaScript dinámico**:
   - Aplica clases automáticamente a tablas de pacientes
   - Botón toggle tabla/cards en móvil
   - Re-optimiza al redimensionar ventana
   - Oculta columnas según ancho de pantalla

## Verificación

Después de activar el módulo:

### URLs corregidas
1. Abre la consola del navegador (F12)
2. Deberías ver: `✓ CabinetMedFix module loaded successfully`
3. Ve a la lista de pacientes
4. Haz clic en un paciente
5. La URL debe ser: `/custom/cabinetmed/card.php?socid=XXX&canvas=patient@cabinetmed`

### Responsive funcionando
- **Desktop**: Encabezado fijo al hacer scroll, primera columna pegada
- **Tablet** (simula con F12 → Device toolbar a 768px): Columnas menos importantes ocultas
- **Móvil** (<480px): Aparece botón "📱 Vista Cards" para cambiar a vista de tarjetas

## Desactivar temporalmente

Si necesitas desactivar el módulo:

1. Ve a: **Inicio → Configuración → Módulos/Aplicaciones**
2. Busca: **CabinetMed Fix**
3. Haz clic en **Desactivar**

El módulo permanecerá instalado pero no realizará ninguna corrección.

## Desinstalar completamente

Si deseas eliminar el módulo por completo:

1. Desactívalo primero (ver arriba)
2. Elimina la carpeta: `/custom/cabinetmedfix/`

## Soporte

Este módulo fue creado específicamente para CRM-RCV.  
Para cualquier problema o pregunta, contacta al equipo de soporte.

## Licencia

GPL v3 o superior

## Changelog

### Versión 1.0 (2026-01-19)
- Versión inicial
- Corrección de URLs de pacientes
- Corrección de campo client
- Soporte completo para CabinetMed 17.0
