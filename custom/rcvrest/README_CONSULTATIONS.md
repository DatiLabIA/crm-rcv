# API REST - Consultas Médicas

Documentación completa del endpoint de consultas del módulo `rcvrest`.

## URL Base

```
https://crm.rcvco.org/api/index.php/rcvrest
```

## Autenticación

Todas las peticiones requieren el header:

```
DOLAPIKEY: tu_api_key_aqui
```

**Usuario actual:** chatbot (ID: 7)  
**API Key:** `l7l73HZ4fy76WJT68ihanAAs0EIU9dAl`

---

## Endpoints Disponibles

### 1. Listar Consultas

```http
GET /consultations
```

**Query Parameters (opcionales):**
- `fk_soc` (int): Filtrar por paciente
- `tipo_atencion` (string): Filtrar por tipo de atención
- `status` (int): Filtrar por estado (0=En progreso, 1=Completada, 2=Cancelada)
- `date_start_from` (string): Fecha desde (formato: YYYY-MM-DD)
- `date_start_to` (string): Fecha hasta (formato: YYYY-MM-DD)

**Ejemplo:**
```bash
GET /consultations?fk_soc=4024&status=0
```

**Respuesta:** Array de objetos consulta

---

### 2. Obtener Consulta por ID

```http
GET /consultations/{id}
```

**Ejemplo:**
```bash
GET /consultations/11796
```

**Respuesta:** Objeto consulta con todos los campos

---

### 3. Crear Consulta

```http
POST /consultations
```

#### Campos del Request

##### **Campos REQUERIDOS:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `fk_soc` | int | **ID del paciente (REQUERIDO)** |

##### **Campos OPCIONALES pero IMPORTANTES:**

| Campo | Tipo | Descripción | Valores |
|-------|------|-------------|---------|
| `tipo_atencion` | string | Tipo de atención/consulta | Ver tabla abajo |
| `status` | int | Estado de la consulta | `0`=En progreso<br>`1`=Completada<br>`2`=Cancelada |
| `date_start` | string | Fecha/hora inicio | Formato: `YYYY-MM-DD HH:MM:SS` |
| `date_end` | string | Fecha/hora fin | Formato: `YYYY-MM-DD HH:MM:SS` |
| `fk_user` | int | Usuario responsable principal | ID de usuario |
| `assigned_users` | array[int] | Usuarios adicionales asignados | Array de IDs de usuario |

##### **Campos de Contenido Clínico:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `motivo` | string | Motivo de consulta |
| `diagnostico` | string | Diagnóstico |
| `procedimiento` | string | Procedimiento realizado |
| `medicamentos` | string | Medicamentos prescritos |
| `observaciones` | string | Observaciones generales |
| `cumplimiento` | string | Nivel de cumplimiento |
| `razon_inc` | string | Razón de incumplimiento |
| `mes_actual` | string | Información mes actual |
| `proximo_mes` | string | Información próximo mes |
| `dificultad` | int | Nivel de dificultad (0-10) |
| `insumos_enf` | string | Insumos de enfermería |
| `rx_num` | string | Número de receta |

##### **Campos de Notas:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `note_private` | string | Nota privada (solo staff) |
| `note_public` | string | Nota pública (visible para paciente) |

##### **Campos de Recurrencia:**

| Campo | Tipo | Descripción | Valores |
|-------|------|-------------|---------|
| `recurrence_enabled` | int | ¿Habilitar recurrencia? | `0`=No, `1`=Sí |
| `recurrence_interval` | int | Intervalo de recurrencia | Número entero |
| `recurrence_unit` | string | Unidad de tiempo | `days`, `weeks`, `months` |
| `recurrence_end_type` | string | Tipo de fin | `forever`, `count`, `until` |
| `recurrence_end_date` | string | Fecha fin (si end_type=until) | Formato: `YYYY-MM-DD HH:MM:SS` |

##### **Campos de Custom Data:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `custom_data` | object | Datos personalizados en JSON | Cualquier estructura JSON |

---

#### Tipos de Atención Disponibles

Los siguientes tipos de atención están configurados en el sistema:

| Código | Descripción |
|--------|-------------|
| `gestion_whatsapp` | Gestión WhatsApp |
| `gestion_llamada_telefonica` | Gestión Llamada Telefónica |
| `gestion_coordinacion_enfermeria` | Gestión Coordinación Enfermería |
| `gestion_recepcion_documentos` | Gestión Recepción Documentos |
| `gestion_entrega_documentos` | Gestión Entrega Documentos |
| `gestion_visita_domiciliaria` | Gestión Visita Domiciliaria |
| `gestion_visita_entidad` | Gestión Visita Entidad |
| `gestion_sesion_virtual` | Gestión Sesión Virtual |
| `gestion_eps` | Gestión EPS |
| `seguimiento_iyc` | Seguimiento IyC |
| `sesion_virtual_enfermeria` | Sesión Virtual Enfermería |
| `visita_domiciliaria_enfermeria` | Visita Domiciliaria Enfermería |
| `sesion_presencial_enfermeria` | Sesión Presencial Enfermería |
| `sesion_presencial_programa_hepatitis` | Sesión Presencial Programa Hepatitis |
| `sesion_no_presencial_programa_hepatitis` | Sesión No Presencial Programa Hepatitis |
| `programa_prevencion` | Programa Prevención |

**Nota:** Si no especificas `tipo_atencion`, se usará el valor por defecto del sistema.

---

#### Asignación de Usuarios

##### ⭐ **IMPORTANTE: Asignación Automática**

El usuario de la API key (chatbot, ID: 7) **siempre se asigna automáticamente** a todas las consultas creadas desde la API.

##### Asignar Usuarios Adicionales

Puedes asignar usuarios adicionales usando el campo `assigned_users`:

```json
{
    "fk_soc": 4024,
    "tipo_atencion": "gestion_whatsapp",
    "assigned_users": [1, 5]
}
```

**Resultado:** La consulta tendrá asignados a los usuarios: **7 (chatbot), 1, 5**

##### Usuario Responsable Principal (`fk_user`)

- Si **NO especificas** `fk_user`, se usa el usuario de la API (7)
- Si **SÍ especificas** `fk_user`, ese será el responsable principal pero el usuario de la API (7) seguirá asignado en `assigned_users`

---

#### Ejemplo Completo: Crear Consulta

**Request:**
```json
POST /consultations
Content-Type: application/json
DOLAPIKEY: l7l73HZ4fy76WJT68ihanAAs0EIU9dAl

{
    "fk_soc": 4024,
    "tipo_atencion": "gestion_whatsapp",
    "status": 0,
    "date_start": "2026-04-17 10:00:00",
    "date_end": "2026-04-17 10:30:00",
    "motivo": "Seguimiento tratamiento",
    "diagnostico": "Paciente estable",
    "observaciones": "El paciente reporta mejora en síntomas",
    "assigned_users": [1, 5],
    "custom_data": {
        "origen": "chatbot",
        "conversacion_id": "12345",
        "canal": "whatsapp"
    }
}
```

**Respuesta:**
```json
11796
```
(ID de la consulta creada)

**Para verificar, hacer GET:**
```bash
GET /consultations/11796
```

---

### 4. Actualizar Consulta

```http
PUT /consultations/{id}
```

#### Campos Actualizables

Puedes actualizar cualquiera de los campos mencionados en la creación **EXCEPTO**:
- `fk_soc` (no se puede cambiar el paciente)
- `datec` (fecha de creación)
- `fk_user_creat` (usuario creador)

#### Actualizar Usuarios Asignados

Al actualizar `assigned_users`, el usuario de la API (chatbot, ID: 7) **siempre se mantiene** automáticamente.

**Ejemplo:**
```json
PUT /consultations/11796

{
    "status": 1,
    "observaciones": "Consulta completada. Paciente asistió y recibió medicación.",
    "assigned_users": [1, 5, 8],
    "custom_data": {
        "completado_en": "2026-04-17 10:45:00",
        "satisfaccion": 5
    }
}
```

**Resultado:** Los usuarios asignados serán: **7 (chatbot), 1, 5, 8**

---

## Campos de Respuesta

Cuando obtienes una consulta (GET), recibes estos campos:

```json
{
    "id": 11796,
    "fk_soc": 4024,
    "fk_user": 7,
    "assigned_users": [1, 7],  // Array de IDs de usuarios asignados
    "patient_nom": "prueba 2026",  // Solo en listados
    "date_start": "17/04/2026 03:00 PM",
    "date_end": "17/04/2026 03:30 PM",
    "tipo_atencion": "gestion_whatsapp",
    "cumplimiento": "",
    "razon_inc": "",
    "mes_actual": "",
    "proximo_mes": "",
    "dificultad": 0,
    "motivo": "",
    "diagnostico": "",
    "procedimiento": "",
    "insumos_enf": "",
    "rx_num": "",
    "medicamentos": "",
    "observaciones": "Test multi-user: chatbot (7) + usuario 1",
    "status": 0,
    "custom_data": null,  // o objeto JSON si se guardó
    "recurrence_enabled": 0,
    "recurrence_interval": 1,
    "recurrence_unit": "weeks",
    "recurrence_end_type": "forever",
    "recurrence_end_date": null,
    "recurrence_parent_id": null,
    "note_private": "",
    "note_public": "",
    "datec": "16/04/2026 06:35 PM",  // Fecha de creación
    "tms": "16/04/2026 06:35 PM",    // Última modificación
    "fk_user_creat": 7,
    "fk_user_modif": null
}
```

---

## Estados de Consulta

| Valor | Estado | Descripción | Color |
|-------|--------|-------------|-------|
| **0** | En progreso | Consulta activa, pendiente de completar | Azul |
| **1** | Completada | Consulta finalizada exitosamente | Verde |
| **2** | Cancelada | Consulta cancelada | Rojo |

---

## Ejemplos PowerShell

### Crear Consulta Básica

```powershell
$headers = @{
    "DOLAPIKEY" = "l7l73HZ4fy76WJT68ihanAAs0EIU9dAl"
    "Content-Type" = "application/json"
}

$data = @{
    fk_soc = 4024
    tipo_atencion = "gestion_whatsapp"
    status = 0
    observaciones = "Consulta creada desde PowerShell"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/consultations" `
    -Method Post `
    -Headers $headers `
    -Body $data

Write-Host "Consulta creada: ID $response"
```

### Crear Consulta con Usuarios Adicionales

```powershell
$data = @{
    fk_soc = 4024
    tipo_atencion = "sesion_virtual_enfermeria"
    status = 0
    date_start = "2026-04-18 14:00:00"
    date_end = "2026-04-18 14:30:00"
    assigned_users = @(1, 5)  # Usuarios adicionales al chatbot (7)
    motivo = "Control mensual"
    observaciones = "Sesión virtual programada"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/consultations" `
    -Method Post `
    -Headers $headers `
    -Body $data
```

### Actualizar Estado a Completada

```powershell
$consultaId = 11796

$data = @{
    status = 1
    observaciones = "Consulta completada. Paciente en buen estado."
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/consultations/$consultaId" `
    -Method Put `
    -Headers $headers `
    -Body $data
```

### Listar Consultas de un Paciente

```powershell
$pacienteId = 4024

$consultas = Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/consultations?fk_soc=$pacienteId" `
    -Method Get `
    -Headers $headers

foreach ($consulta in $consultas) {
    Write-Host "ID: $($consulta.id) - Estado: $($consulta.status) - Fecha: $($consulta.date_start)"
}
```

---

## Buenas Prácticas

### 1. **Status de Consultas**
- Crea consultas con `status: 0` (En progreso)
- Actualiza a `status: 1` cuando se complete
- Usa `status: 2` solo si se cancela

### 2. **Fechas**
- Usa formato: `YYYY-MM-DD HH:MM:SS`
- `date_start` es la fecha/hora de inicio de la consulta
- `date_end` es opcional, úsala para consultas programadas

### 3. **Custom Data**
- Usa `custom_data` para guardar información adicional en formato JSON
- Ideal para: IDs de conversaciones, metadatos, referencias externas
- Se devuelve como objeto en las respuestas

### 4. **Asignación de Usuarios**
- **No incluyas** el ID 7 (chatbot) en `assigned_users` - se agrega automáticamente
- Asigna solo usuarios adicionales que necesiten acceso a la consulta
- Los usuarios asignados recibirán notificaciones en el sistema

### 5. **Tipos de Atención**
- Usa el tipo correcto según el canal de origen
- Para WhatsApp: `gestion_whatsapp`
- Para llamadas: `gestion_llamada_telefonica`
- Para enfermería virtual: `sesion_virtual_enfermeria`

### 6. **Observaciones**
- Usa el campo `observaciones` para información importante
- Es de texto libre, sin límite estricto
- Visible para todos los usuarios asignados

---

## Códigos de Error Comunes

| Código | Mensaje | Solución |
|--------|---------|----------|
| 400 | Field "fk_soc" (patient id) is required | Incluir `fk_soc` en el request |
| 403 | Not allowed | Verificar permisos del usuario API |
| 404 | Consultation not found | Verificar que el ID de consulta existe |
| 500 | Error creating consultation | Revisar logs del servidor, campo inválido |

---

## Notas Técnicas

### Base de Datos
- Tabla principal: `llx_cabinetmed_extcons`
- Tabla de usuarios asignados: `llx_cabinetmed_extcons_users`
- Los eventos se sincronizan con la agenda de Dolibarr

### Permisos
- Requiere permiso: `rcvrest->consultation->read` para GET
- Requiere permiso: `rcvrest->consultation->write` para POST/PUT

### Límites
- No hay límite en la cantidad de usuarios asignados
- `custom_data` se guarda como TEXT en la BD (sin límite práctico)
- Los campos de texto libre tienen límite según configuración de MySQL

---

## Changelog de la API

### Versión 2.0 (Abril 2026)
- ✅ Agregado campo `assigned_users` en respuestas
- ✅ Asignación automática del usuario de la API key
- ✅ Soporte para múltiples usuarios asignados
- ✅ Actualización de usuarios asignados en PUT

### Versión 1.0 (Inicial)
- Base de endpoints GET/POST/PUT
- Soporte para tipos de atención
- Estados de consulta
- Custom data en JSON
- Recurrencia de consultas

---

## Soporte

Para dudas o problemas con la API:
- Revisar logs del servidor en `/custom/rcvrest/`
- Verificar configuración de permisos del usuario API
- Consultar ejemplos en `/test_*.ps1`
