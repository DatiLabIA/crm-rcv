# Plan de Acción: Gestión de Inventario de Medicamentos

## Contexto Técnico Clave

1. **Patient = Societe (Tercero)**: En CabinetMed, un `Patient` extiende `Societe` (tercero/thirdparty). Esto significa que cada paciente **ya es un tercero** en Dolibarr, lo cual es perfecto porque tanto las recepciones como las órdenes de venta trabajan con terceros (`fk_soc`).

2. **Lotes/Series**: Dolibarr ya soporta lotes y números de serie únicos:
   - `status_batch = 1` → Gestión por lotes (batch)
   - `status_batch = 2` → **Número de serie único** (lo que necesitas)
   - Existe `batch_mask` para definir una máscara de numeración automática

3. **Origen de la Orden** (`fk_input_reason`): Las órdenes ya tienen un campo "Origen/Razón" que viene de la tabla `llx_c_input_reason`. Aquí se pueden agregar tus opciones personalizadas.

---

## FASE 1: Configuración de Productos (Sin desarrollo)

| Paso | Acción | Detalle |
|------|--------|---------|
| 1.1 | **Configurar productos como "Número de serie único"** | En cada producto/medicamento, activar `status_batch = 2`. Esto fuerza a que cada unidad tenga un serial único al entrar al stock. |
| 1.2 | **Definir máscara de lote** | Configurar `batch_mask` en cada producto para auto-generar seriales. Ejemplo: `MED-{YYYY}{MM}-{00000}` generaría `MED-202602-00001`. Esto se configura en **Configuración > Módulos > Productos > Lotes/Series**. |
| 1.3 | **Crear almacenes** | Configurar tus diferentes almacenes en **Stock > Almacenes**. |
| 1.4 | **Activar fechas obligatorias** | Si manejas vencimiento de medicamentos, activar `sellby` (fecha de venta) y `eatby` (fecha de consumo) en la configuración de lotes. |

---

## FASE 2: Configuración de Proveedores y Pacientes (Sin desarrollo)

| Paso | Acción | Detalle |
|------|--------|---------|
| 2.1 | **Crear laboratorios como Proveedores** | Cada laboratorio que dona se registra como tercero tipo "Proveedor". |
| 2.2 | **Marcar pacientes también como Proveedores** | Como el paciente ya es un `Societe`, basta con activarle la casilla "Es proveedor" (`fournisseur = 1`). Se puede automatizar con un hook. Para las recolecciones, el paciente actúa como proveedor del medicamento. |

---

## FASE 3: Agregar Orígenes Personalizados (Desarrollo leve - SQL)

Agregar dos nuevos registros a la tabla `llx_c_input_reason`:

```sql
INSERT INTO llx_c_input_reason (rowid, code, label, active) 
VALUES (100, 'SRC_DONATION', 'Donación', 1);

INSERT INTO llx_c_input_reason (rowid, code, label, active) 
VALUES (101, 'SRC_COLLECTION', 'Recolección', 1);
```

Esto hará que en las órdenes de venta (dispensaciones), aparezcan **"Donación"** y **"Recolección"** como opciones en el campo **Origen**.

---

## FASE 4: Flujo de Recepción (Entrada de Medicamentos)

### Flujo Donación

```
Laboratorio (Proveedor)
    │
    ▼
Orden de Compra a Proveedor (precio = 0)
    │
    ▼
Recepción → Se genera Serial Único por unidad
    │
    ▼
Stock en Almacén X
```

### Flujo Recolección

```
Paciente (marcado como Proveedor)
    │
    ▼
Orden de Compra a Proveedor = Paciente (precio = 0)
    │
    ▼
Recepción → Se genera Serial Único por unidad
    │
    ▼
Stock en Almacén X (temporal)
```

---

## FASE 5: Flujo de Dispensación (Salida de Medicamentos)

```
Paciente (como Cliente)
    │
    ▼
Orden de Venta (Origen = Donación / Recolección)
    │
    ▼
Envío/Expedición → Seleccionar Serial del lote
    │
    ▼
Sale del Stock del Almacén
```

---

## FASE 6: Validación Recolección ↔ Dispensación (Desarrollo - Módulo Custom)

Este es el desarrollo principal. Crear un **hook/trigger** en el módulo `cabinetmedfix` que realice una **doble validación** al momento de despachar:

### Validación 1: Existencia de Recepción del Paciente

Al crear/validar una **Orden de Venta** con origen = "Recolección" (`SRC_COLLECTION`):
- Verificar que existe al menos una **Recepción** donde `fk_soc` (proveedor) = el mismo paciente (`fk_soc` de la orden de venta)
- Verificar que esa recepción incluye el mismo producto
- Si no existe → **Bloquear** y mostrar error: *"No existe una recepción/recolección registrada para este paciente con este medicamento"*

### Validación 2: Número de Serie Específico Disponible

Al crear el **Envío/Expedición** (momento donde se selecciona el serial):
- Verificar que el **número de serie** seleccionado para despacho es **exactamente uno** que entró al sistema a través de una recepción donde el proveedor es el mismo paciente
- Verificar que ese serial **aún está disponible en stock** (no fue despachado previamente en otra orden)
- Si el serial no corresponde al paciente → **Bloquear**: *"El serial XXX no fue recolectado para este paciente"*
- Si el serial ya fue despachado → **Bloquear**: *"El serial XXX ya fue dispensado anteriormente en la orden ORD-XXX"*

### ¿Por qué doble validación?

```
Sin validación de serial:
  ❌ Paciente A tiene Recepción con Serial-001 y Serial-002
  ❌ Se despacha Serial-001 a Paciente A → OK
  ❌ Se despacha Serial-001 OTRA VEZ a Paciente A → Pasaría la validación 
     porque la recepción existe, pero el serial ya salió del inventario

Con validación de serial:
  ✅ Paciente A tiene Recepción con Serial-001 y Serial-002
  ✅ Se despacha Serial-001 a Paciente A → OK (serial existe en recepción + está en stock)
  ✅ Se intenta despachar Serial-001 otra vez → BLOQUEADO (serial ya no está en stock)
  ✅ Se intenta despachar Serial-003 a Paciente A → BLOQUEADO (serial no vino de su recepción)
```

### Implementación técnica

#### Validación 1: Trigger `ORDER_VALIDATE` — Existencia de recepción

```php
// ¿Existe recepción del paciente con este producto?
$sql = "SELECT r.rowid, r.ref FROM " . MAIN_DB_PREFIX . "reception r";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "receptiondet_batch rb ON rb.fk_element = r.rowid";
$sql .= " WHERE r.fk_soc = " . (int) $paciente_id;        // Proveedor = Paciente
$sql .= " AND rb.fk_product = " . (int) $producto_id;      // Mismo producto
$sql .= " AND r.fk_statut > 0";                             // Recepción validada
// Si no hay resultados → Error
```

#### Validación 2: Hook en Expedición `SHIPPING_VALIDATE` — Serial específico

```php
// 1. ¿El serial viene de una recepción de este paciente?
$sql = "SELECT rb.rowid FROM " . MAIN_DB_PREFIX . "receptiondet_batch rb";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "reception r ON r.rowid = rb.fk_element";
$sql .= " WHERE r.fk_soc = " . (int) $paciente_id;         // Proveedor = Paciente  
$sql .= " AND rb.batch = '" . $db->escape($serial) . "'";  // Serial exacto
$sql .= " AND r.fk_statut > 0";
// Si no hay resultados → Error: "Serial no pertenece a recepción de este paciente"

// 2. ¿El serial aún está disponible en stock?
$sql = "SELECT ps.reel as qty FROM " . MAIN_DB_PREFIX . "product_stock ps";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product_batch pb ON pb.fk_product_stock = ps.rowid";
$sql .= " WHERE pb.batch = '" . $db->escape($serial) . "'";
$sql .= " AND pb.qty > 0";                                   // Cantidad disponible > 0
// Si no hay resultados o qty = 0 → Error: "Serial ya fue dispensado"
```

### Diagrama del flujo de validación completo

```
Orden de Venta (Origen = Recolección)
    │
    ▼
VALIDACIÓN 1: ¿Paciente tiene recepción con este producto?
    │
    ├── NO → ❌ Bloquear orden
    │
    └── SÍ → Continuar
              │
              ▼
         Crear Envío/Expedición (seleccionar serial)
              │
              ▼
         VALIDACIÓN 2a: ¿El serial viene de una recepción de ESTE paciente?
              │
              ├── NO → ❌ Bloquear: "Serial no corresponde a este paciente"
              │
              └── SÍ → Continuar
                        │
                        ▼
                   VALIDACIÓN 2b: ¿El serial está disponible en stock (qty > 0)?
                        │
                        ├── NO → ❌ Bloquear: "Serial ya fue dispensado"
                        │
                        └── SÍ → ✅ Dispensación aprobada → Sale del stock
```

---

## FASE 7: Trazabilidad Completa

Con `status_batch = 2` (serial único), la trazabilidad queda así:

```
Medicamento: Acetaminofén 500mg
  │
  ├── Serial: MED-202602-00001
  │     ├── Recepción: REC-001 (Proveedor: Lab X / Donación)
  │     ├── Almacén: Almacén Central
  │     └── Dispensación: ORD-045 → Paciente: Juan Pérez
  │
  ├── Serial: MED-202602-00002  
  │     ├── Recepción: REC-002 (Proveedor: María García / Recolección)
  │     ├── Almacén: Almacén Temporal
  │     └── Dispensación: ORD-046 → Paciente: María García
  │
  └── Serial: MED-202602-00003
        ├── Recepción: REC-001 (Proveedor: Lab X / Donación)
        ├── Almacén: Almacén Central
        └── En stock (sin dispensar)
```

---

## Resumen de Desarrollo Necesario

| Componente | Tipo | Esfuerzo |
|-----------|------|----------|
| Configurar `status_batch = 2` en productos | Config UI | Bajo |
| Configurar `batch_mask` para numeración | Config UI | Bajo |
| SQL para agregar orígenes (Donación/Recolección) | SQL | Bajo |
| Hook para marcar pacientes como proveedor al crear | Hook PHP | Medio |
| Validación 1: recepción existe para paciente+producto | Trigger `ORDER_VALIDATE` | Medio |
| Validación 2: serial corresponde al paciente y está disponible | Trigger `SHIPPING_VALIDATE` | Medio-Alto |
| Reportes de trazabilidad por paciente | Vista PHP | Medio |

---

## Relación con CabinetMed

CabinetMed **no necesita modificaciones directas**. La conexión es:

- `Patient extends Societe` → El paciente ya es un tercero
- El paciente puede ser **cliente Y proveedor** simultáneamente
- Las consultas médicas (CabinetMed) y las dispensaciones (Órdenes de Venta) se vinculan a través del mismo `fk_soc` (ID del paciente/tercero)
- Se podría agregar una pestaña en la ficha del paciente de CabinetMed que muestre **"Medicamentos dispensados"** y **"Recolecciones"** usando los datos de `commande` / `reception`
