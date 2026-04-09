# Plan de Gestión de Dispensaciones y Recepciones de Medicamentos
**CRM-RCV — CabinetMedFix**
*Última actualización: 2026-03-16*

---

## Contexto del negocio

La fundación gestiona medicamentos que llegan por dos vías y se dispensan a pacientes. Todo el medicamento es **precio cero** tanto en entrada como en salida. El inventario existe solo para trazabilidad y control de lotes/seriales.

---

## Los dos flujos de entrada de medicamento

### Flujo 1 — Donación

El laboratorio dona medicamento directamente a la fundación.

```
Laboratorio (Proveedor en Dolibarr)
  │
  ▼
Orden de Compra (precio 0)
  → Proveedor = Laboratorio
  → Cada línea: medicamento + serial en campo "serial_batch"
  │
  ▼  [Trigger automático: ORDER_SUPPLIER_VALIDATE]
Recepción creada y validada automáticamente
  → Stock +N en almacén
  → Serial registrado en inventario
```

### Flujo 2 — Recolección

Un gestor de la fundación va al operador logístico a recolectar el medicamento del paciente.

```
Paciente (marcado también como Proveedor en Dolibarr)
  │
  ▼
Orden de Compra (precio 0)
  → Proveedor = EL MISMO PACIENTE
  → Cada línea: medicamento + serial en campo "serial_batch"
  │
  ▼  [Trigger automático: ORDER_SUPPLIER_VALIDATE]
Recepción creada y validada automáticamente
  → Serial queda vinculado a ese paciente en el inventario
```

---

## El flujo de dispensación (salida de medicamento)

Aplica igual para ambos orígenes. La diferencia es el campo **Origen** de la orden.

```
Paciente (como Cliente en Dolibarr)
  │
  ▼
Orden de Dispensación (Orden de Venta)
  → Origen: "Donación" o "Recolección"
  → Medicamento(s) y cantidad(es)
  │
  ▼  [Trigger: ORDER_VALIDATE]
  → Si Origen = Recolección:
      ✅ Verifica que el paciente tiene recepción del medicamento
      ✅ Crea Envío en BORRADOR con el serial disponible pre-seleccionado
  → Si Origen = Donación:
      ✅ Verifica que hay stock disponible
      ✅ Crea Envío en BORRADOR con serial disponible
  │
  ▼  Usuario revisa el Envío en borrador
     (puede ver/cambiar el lote/serial asignado)
  │
  ▼  Usuario valida el Envío manualmente
  │
  ▼  [Trigger: SHIPPING_VALIDATE]
  → Si Origen = Recolección:
      ✅ Verifica que el serial pertenece a una recepción del paciente
  → Descuenta stock
  → Acta de entrega lista para imprimir
  │
  ▼  Firma del paciente (papel o digital)
```

---

## Caso especial — Dispensación desde intervención Dolimed

Las intervenciones extcons de tipo Dolimed **pueden** (no siempre) generar una dispensación. El vínculo es **opcional** en ambas direcciones.

```
Intervención Dolimed (ExtConsultation)
  │
  ├── Sin dispensación → solo queda registrada la intervención
  │
  └── Con dispensación →
        Se enlaza la Orden de Dispensación a la intervención
        via el sistema de objetos enlazados de Dolibarr (llx_element_element)
        
        Opciones para crear el vínculo:
        → Desde la intervención: botón "Enlazar dispensación"
        → Desde la dispensación: aparece la intervención en objetos enlazados
```

---

## A futuro — Compra de medicamento con valor

Actualmente no implementado. Cuando aplique:
- La orden de compra tendrá precio > 0
- La orden de venta (dispensación) también tendrá precio
- El flujo técnico es el mismo, solo con valores monetarios reales
- Requiere revisar impuestos y facturación

---

## Estado de implementación

| Componente | Estado | Módulo |
|---|---|---|
| Productos/medicamentos con serial único | ✅ Configurado | Dolibarr core (config) |
| Pacientes marcados como proveedores automáticamente | ✅ Funciona | cabinetmedfix (trigger) |
| Órdenes de compra (recepciones) | ✅ Funciona | Dolibarr core |
| Recepción automática al validar OC | ✅ Funciona | cabinetmedfix (trigger ORDER_SUPPLIER_VALIDATE) |
| Orígenes SRC_DONATION / SRC_COLLECTION | ✅ Configurado | SQL en cabinetmedfix |
| Órdenes de dispensación (OV) | ✅ Funciona | Dolibarr core |
| Validación: recepción requerida para Recolección | ✅ Funciona | cabinetmedfix (trigger ORDER_VALIDATE) |
| Envío creado en BORRADOR (no auto-validado) | ✅ Funciona | cabinetmedfix (trigger ORDER_VALIDATE) |
| Validación serial pertenece al paciente | ✅ Funciona | cabinetmedfix (trigger SHIPPING_VALIDATE) |
| Campos irrelevantes ocultos en formularios | ✅ Funciona | cabinetmedfix (hook JS — formObjectOptions) |
| Plantilla PDF para acta de entrega | ✅ Funciona | cabinetmedfix (pdf_actaentrega) |
| Bloque objetos enlazados en intervenciones extcons | ✅ Funciona | cabinetmed_extcons (consultation_card.php) |
| Firma digital | ⏳ Pendiente | Evaluar opciones |

---

## Pendientes por implementar (orden de prioridad)

### 1. Plantilla ODT para acta de entrega
**Qué es:** Un archivo `.odt` dentro de `cabinetmedfix` que sirve de modelo para el PDF que se imprime al entregar el medicamento.

**Debe mostrar:**
- Nombre del paciente
- Medicamento(s) dispensado(s)
- Serial/lote de cada medicamento
- Fecha de entrega
- Nombre del enfermero/gestor que entrega (si aplica)
- Sección de firma del paciente
- Sección de firma del responsable de la fundación

**Dónde va:** `custom/cabinetmedfix/` como módulo de documento para envíos.

---

### 2. Ocultar campos irrelevantes en formularios de órdenes
**Qué es:** Un hook en `cabinetmedfix` que, al detectar que se está en una orden de dispensación (OV) o recepción de medicamento (OC), oculta con CSS los campos que no aplican.

**Campos a ocultar en Orden de Dispensación:**
- Condiciones de pago
- Modo de envío
- Descuento global
- Proyecto
- Representante comercial
- Cuenta bancaria
- Referencia cliente
- Impuestos (las líneas siempre son precio 0 sin IVA)

**Campos a ocultar en Orden de Compra:**
- Condiciones de pago
- Proyecto
- Descuento global
- Representante comercial

**Dónde va:** Hook `formObjectOptions` o `printCommonFooter` en `actions_cabinetmedfix.class.php`.

---

### 3. Vínculo intervención Dolimed ↔ dispensación (opcional)
**Qué es:** En la ficha de una intervención extcons, mostrar el bloque de objetos enlazados de Dolibarr y permitir enlazar una orden de dispensación. No es un campo obligatorio — solo se usa cuando en esa intervención se administró medicamento del inventario.

**Implementación:**
- Mostrar `linkedObjectsBloc()` en la vista de `consultation_card.php`
- Usar `llx_element_element` para guardar los vínculos (ya existe en Dolibarr)
- No requiere cambios de esquema

**Dónde va:** `cabinetmed_extcons/consultation_card.php`

---

### 4. Firma digital (evaluación pendiente)

**Opción A — Firma en papel (ya disponible)**
Imprimir el PDF del acta de entrega y el paciente firma físicamente. Simple, sin desarrollo adicional. La copia firmada puede escanearse y subirse como adjunto al envío.

**Opción B — Canvas de firma web**
Una página en `cabinetmedfix` que muestra el acta en pantalla + un canvas HTML5 donde el paciente firma con el dedo (tablet/móvil). Al confirmar, guarda la imagen de la firma como adjunto al envío en Dolibarr.

**Opción C — Servicio externo**
Integración con DocuSign, Signaturit u similar. Mayor costo y complejidad. Solo evaluar si B no es suficiente.

**Recomendación:** Empezar con A mientras se implementan las otras prioridades. Luego implementar B.

---

## Principios de desarrollo

1. **Todo cambio va en `cabinetmedfix`** — Nunca modificar código de `cabinetmed`, `cabinetmed_extcons` ni el core de Dolibarr. Así sobrevivimos actualizaciones.
2. **Sin precio, sin impuestos** — Todas las órdenes (OC y OV) son precio 0. Las validaciones del trigger no deben incluir lógica de precios.
3. **El serial es obligatorio** — Los productos de medicamento tienen `status_batch = 2` (serial único). Sin serial no hay stock, sin stock no hay dispensación.
4. **El paciente es cliente Y proveedor** — En donación actúa solo como cliente. En recolección actúa como proveedor (OC) y luego como cliente (OV).
5. **No auto-validar envíos** — El envío (Expedition) siempre se crea en borrador para que el usuario pueda revisar el lote asignado antes de confirmar la salida del stock.
