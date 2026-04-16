# API REST - Pacientes

Documentación completa del endpoint de pacientes del módulo `rcvrest`.

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

### 1. Obtener Paciente por ID

```http
GET /patients/{id}
```

**Parámetro:**
- `id` (int): ID del paciente (fk_soc / rowid de llx_societe)

**Ejemplo:**
```bash
GET /patients/4024
```

**Respuesta:**
```json
{
    "id": 4024,
    "nom": "prueba 2026",
    "name_alias": "",
    "address": "",
    "zip": "",
    "town": "",
    "state_id": 273,
    "state": "Bogotá",
    "country_id": 70,
    "country": "Colombie",
    "phone": "",
    "email": "",
    "url": "",
    "fk_prospectlevel": "",
    "client": 1,
    "fournisseur": 0,
    "barcode": "",
    "default_lang": "",
    "outstanding_limit": 0,
    "code_client": "CU2604-0159",
    "typent_id": 0,
    "effectif_id": 0,
    "forme_juridique_code": 0,
    "status": 1,
    "tva_intra": "",
    "note_public": "",
    "note_private": "",
    "datec": "16/04/2026 12:41 PM",
    "tms": "16/04/2026 12:41 PM",
    "array_options": {
        "options_eps": "NUEVA EPS",
        "options_eps_id": 1,
        "options_programa": "PROGRAMA HEPATITIS B",
        "options_programa_id": 1,
        "options_medicamento": "VIREAD 300 MG",
        "options_medicamento_id": 1,
        "options_concentracion": "25 mg",
        "options_concentracion_id": 22,
        "options_operador_logistico": "OPERADOR 1",
        "options_operador_logistico_id": 1,
        "options_medico_tratante": "Dr. García",
        "options_medico_tratante_id": 1,
        "options_posologia": "1 tableta cada 8 horas",
        "options_birthdate": "16/04/2000",
        "options_fecha_entregado_guardian": "15/03/2026",
        "options_fecha_cambio_guardian": "20/03/2026",
        // ... más campos extrafields
    },
    "medical": {
        "note_antemed": "",
        "note_antechirgen": "",
        "note_antechirortho": "",
        "note_anterhum": "",
        // ... más campos médicos
    }
}
```

---

### 2. Actualizar Paciente

```http
PUT /patients/{id}
```

**Parámetro:**
- `id` (int): ID del paciente

**Body:** JSON con los campos a actualizar

---

## Campos del Paciente

### Campos Básicos (tabla `llx_societe`)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `nom` | string | Nombre completo del paciente |
| `name_alias` | string | Alias o nombre alternativo |
| `address` | string | Dirección |
| `zip` | string | Código postal |
| `town` | string | Ciudad |
| `state_id` | int | ID del departamento |
| `state` | string | Nombre del departamento (solo lectura) |
| `country_id` | int | ID del país |
| `country` | string | Nombre del país (solo lectura) |
| `phone` | string | Teléfono |
| `email` | string | Correo electrónico |
| `status` | int | Estado (0=Inactivo, 1=Activo) |

**Nota:** Los campos `state` y `country` son solo lectura. Se calculan automáticamente desde `state_id` y `country_id`.

---

### Extrafields (campos personalizados)

Estos campos están en `array_options` y utilizan el prefijo `options_`.

#### Campos Relacionales (se devuelven con ID y Label)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `options_eps` | string | Nombre de la EPS |
| `options_eps_id` | int | ID de la EPS en tabla gestion_eps |
| `options_programa` | string | Nombre del programa |
| `options_programa_id` | int | ID en tabla gestion_programa |
| `options_medicamento` | string | Nombre del medicamento |
| `options_medicamento_id` | int | ID en tabla gestion_medicamento |
| `options_concentracion` | string | Concentración (ej: "25 mg") |
| `options_concentracion_id` | int | ID en tabla gestion_medicamento_det |
| `options_operador_logistico` | string | Nombre del operador |
| `options_operador_logistico_id` | int | ID en tabla gestion_operador |
| `options_medico_tratante` | string | Nombre del médico |
| `options_medico_tratante_id` | int | ID en tabla gestion_medico |

Para actualizar estos campos, envía el **ID** con el nombre sin `_id`:
```json
{
    "array_options": {
        "options_eps": 2,  // Enviar solo el ID
        "options_medicamento": 5
    }
}
```

#### Campos de Fecha (se devuelven formateados)

| Campo | Descripción | Formato enviado | Formato recibido |
|-------|-------------|-----------------|------------------|
| `options_birthdate` | Fecha de nacimiento | Unix timestamp | DD/MM/YYYY |
| `options_fecha_entregado_guardian` | Fecha entregado al guardián | Unix timestamp | DD/MM/YYYY |
| `options_fecha_cambio_guardian` | Fecha cambio de guardián | Unix timestamp | DD/MM/YYYY |

**Ejemplo de envío:**
```json
{
    "array_options": {
        "options_birthdate": 955861200  // Unix timestamp
    }
}
```

**Ejemplo de recepción:**
```json
{
    "array_options": {
        "options_birthdate": "16/04/2000"
    }
}
```

#### Campos Select (valores predefinidos)

Estos campos tienen valores predefinidos en el sistema:

**options_estado_afiliacion:**
- 1 = Activo
- 2 = Inactivo Temporal  
- 3 = Desafiliado
- 4 = Cancelado
- 5 = Suspendido Temporal
- 6 = Suspendido Transitorio
- 7 = Suspendido Definitivo
- 8 = En trámite
- 9 = NAP
- 10 = Inactivo

**options_estado_vital:**
- 1 = Vivo
- 2 = Muerto

**options_tipo_de_status:**
- 1 = Trámite Completo
- 2 = Trámite Intermedio - Reclama
- 3 = Trámite Intermedio - Autoriza
- 4 = Independiente

**options_regimen:**
- 1 = Contributivo
- 2 = Subsidiado
- 3 = Especial
- 4 = Particular
- 5 = Por confirmar

**options_tipo_de_afiliacion:**
- 1 = Beneficiario
- 2 = Cotizante
- 3 = Cabeza de Familia
- 4 = Por Confirmar
- 5 = Otro
- 6 = NA

**options_tipo_de_poblacion:**
- 1 = Población Mestiza
- 2 = Población Afrocolombiana
- 3 = Población Indígena
- 4 = Población Blanca
- 5 = Población Raizal
- 6 = Población Palenquera
- 7 = Población Rrom o Gitana
- 8 = Población Rural
- 9 = Población Urbana
- 10 = Población Migrante

**options_tipo_de_documento:**
- 1 = Registro Civil
- 2 = Tarjeta de Identidad
- 3 = Cédula de Ciudadanía
- 4 = Cédula de Extranjería
- 8 = Permiso de Protección Temporal
- 9 = Salvo Conducto
- 10 = Sin Identificación
- 11 = NIT
- 13 = NA
- 14 = Permiso Especial de Permanencia

#### Otros Campos Extrafields

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `options_posologia` | string | Posología del medicamento |
| `options_numero_identificacion` | string | Número de documento |
| `options_lugar_nacimiento` | string | Lugar de nacimiento |
| `options_edad` | int | Edad del paciente |
| `options_sexo` | string | Sexo (M/F) |
| `options_direccion` | string | Dirección del paciente |
| `options_ciudad` | string | Ciudad |
| `options_telefono` | string | Teléfono |
| `options_celular` | string | Celular |
| `options_correo` | string | Correo electrónico |
| `options_guardian` | string | Nombre del guardián |
| `options_telefono_guardian` | string | Teléfono del guardián |
| `options_relacion_guardian` | string | Relación con el paciente |

---

### Campos Médicos (tabla `llx_cabinetmed_patient`)

Estos campos están en el objeto `medical`:

| Campo | Descripción |
|-------|-------------|
| `note_antemed` | Antecedentes médicos |
| `note_antechirgen` | Antecedentes cirugía general |
| `note_antechirortho` | Antecedentes cirugía ortopédica |
| `note_anterhum` | Antecedentes reumatológicos |
| `note_allergie` | Alergias |
| `diaglast` | Último diagnóstico |
| `note_generation` | Notas generación |
| `note_heredite` | Herencia |
| `note_tobacco` | Tabaquismo |
| `note_alcohol` | Alcoholismo |
| `alert_antemed` | Alerta antecedentes médicos (0/1) |
| `alert_antechirgen` | Alerta cirugía general (0/1) |
| `alert_antechirortho` | Alerta cirugía ortopédica (0/1) |
| `alert_anterhum` | Alerta reumatología (0/1) |
| `alert_allergie` | Alerta alergias (0/1) |

---

## Changelog/Auditoría

⚠️ **IMPORTANTE:** Todos los cambios realizados desde la API se registran automáticamente en el sistema de auditoría.

El sistema guarda:
- ✅ Qué campos se modificaron
- ✅ Valores anteriores y nuevos
- ✅ Quién hizo el cambio (usuario API)
- ✅ Fecha y hora del cambio
- ✅ Se crea un evento en la agenda del paciente

Puedes ver el historial de cambios en la interfaz web del paciente.

---

## Ejemplos de Uso

### Obtener Paciente

```powershell
$headers = @{
    "DOLAPIKEY" = "l7l73HZ4fy76WJT68ihanAAs0EIU9dAl"
}

$paciente = Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" `
    -Method Get `
    -Headers $headers

Write-Host "Paciente: $($paciente.nom)"
Write-Host "EPS: $($paciente.array_options.options_eps)"
Write-Host "Medicamento: $($paciente.array_options.options_medicamento)"
Write-Host "Concentración: $($paciente.array_options.options_concentracion)"
```

### Actualizar Datos Básicos

```powershell
$headers = @{
    "DOLAPIKEY" = "l7l73HZ4fy76WJT68ihanAAs0EIU9dAl"
    "Content-Type" = "application/json"
}

$data = @{
    phone = "+57 300 1234567"
    email = "paciente@example.com"
    address = "Calle 123 #45-67"
    town = "Bogotá"
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" `
    -Method Put `
    -Headers $headers `
    -Body $data

Write-Host "Paciente actualizado"
```

### Actualizar EPS y Medicamento

```powershell
$data = @{
    array_options = @{
        options_eps = 2  # ID de nueva EPS
        options_medicamento = 5  # ID de nuevo medicamento
        options_concentracion = 23  # ID de nueva concentración
    }
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" `
    -Method Put `
    -Headers $headers `
    -Body $data
```

### Actualizar Información Médica

```powershell
$data = @{
    medical = @{
        note_allergie = "Penicilina"
        alert_allergie = 1
        diaglast = "Hepatitis B crónica"
        note_antemed = "Hipertensión controlada con medicación"
    }
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" `
    -Method Put `
    -Headers $headers `
    -Body $data
```

### Actualizar Fecha de Nacimiento

```powershell
# Convertir fecha a Unix timestamp
$fecha = Get-Date "2000-04-16"
$timestamp = [int][double]::Parse((Get-Date $fecha -UFormat %s))

$data = @{
    array_options = @{
        options_birthdate = $timestamp
    }
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" `
    -Method Put `
    -Headers $headers `
    -Body $data
```

---

## Estructura de Datos de Referencia

### EPS (Entidades Prestadoras de Salud)

Las EPS están en la tabla `llx_gestion_eps`. Para ver las disponibles:
- Consultar en la interfaz web: Gestión > EPS
- O hacer una consulta SQL directa a la tabla

### Programas

Los programas están en `llx_gestion_programa`:
- Gestión > Programas

### Medicamentos

Medicamentos en `llx_gestion_medicamento`:
- Gestión > Medicamentos

Sus concentraciones están en `llx_gestion_medicamento_det`:
- Un medicamento puede tener múltiples concentraciones

### Operadores Logísticos

En `llx_gestion_operador`:
- Gestión > Operadores

### Médicos Tratantes

En `llx_gestion_medico`:
- Gestión > Médicos

---

## Departamentos y Países

### Departamentos de Colombia

Los departamentos están en `llx_c_departements` con `fk_pays = 70` (Colombia).

Algunos departamentos comunes:
- 273 = Bogotá D.C.
- 1 = Amazonas
- 2 = Antioquia
- 3 = Arauca
- 4 = Atlántico
- 5 = Bolívar
- 6 = Boyacá
- 7 = Caldas
- 8 = Caquetá
- 9 = Casanare
- 10 = Cauca
- ... (hay 32 departamentos)

### País

- 70 = Colombia (Colombie en francés)

---

## Buenas Prácticas

### 1. **Actualización Parcial**
Puedes actualizar solo los campos que necesites. No es necesario enviar todos los campos.

```json
// Solo actualizar teléfono
{
    "phone": "+57 300 1234567"
}
```

### 2. **Campos Read-Only**
No envíes estos campos en PUT, se ignoran:
- `state` (calculado desde `state_id`)
- `country` (calculado desde `country_id`)
- `code_client` (auto-generado)
- `datec` (fecha de creación)
- Todos los campos con sufijo solo en respuesta (ej: `options_eps` cuando es string)

### 3. **Validación de IDs**
Antes de asignar EPS, medicamento, etc., verifica que el ID existe en las tablas de referencia.

### 4. **Fechas**
Para enviar fechas, usa Unix timestamp (número de segundos desde 1970-01-01).

```powershell
$timestamp = [int][double]::Parse((Get-Date "2000-04-16" -UFormat %s))
```

### 5. **Extrafields vs Medical**
- `array_options`: Datos administrativos y de tratamiento
- `medical`: Historial clínico, antecedentes, alertas médicas

### 6. **Auditoría**
Recuerda que todos los cambios quedan registrados. Usa el campo `observaciones` en las consultas para documentar el motivo de cambios importantes.

---

## Códigos de Error

| Código | Mensaje | Solución |
|--------|---------|----------|
| 403 | Not allowed | Verificar permisos del usuario API |
| 404 | Patient not found | Verificar que el ID de paciente existe |
| 500 | Error updating patient | Revisar logs, campo inválido o FK inexistente |

---

## Limitaciones Actuales

❌ **No implementado:**
- Crear pacientes desde API (solo GET y PUT)
- Eliminar pacientes
- Listar todos los pacientes (debe implementarse si se necesita)

✅ **Implementado:**
- Obtener paciente por ID
- Actualizar datos del paciente
- Auditoría de cambios
- Resolución de IDs a nombres (EPS, medicamentos, etc.)
- Formateo de fechas

---

## Notas Técnicas

### Base de Datos
- Tabla principal: `llx_societe` (con `client=1` para pacientes)
- Extrafields: `llx_societe_extrafields`
- Datos médicos: `llx_cabinetmed_patient`
- Auditoría: `llx_cabinetmedfix_changelog`

### Permisos
- Requiere permiso: `rcvrest->patient->read` para GET
- Requiere permiso: `rcvrest->patient->write` para PUT

### Trigger de Changelog
El trigger `interface_98_modCabinetMedFix_ChangelogTrigger` se ejecuta automáticamente en cada actualización desde la API, guardando:
- Campo modificado
- Valor anterior
- Valor nuevo
- Usuario (chatbot)
- Fecha/hora

---

## Ver También

- [README_CONSULTATIONS.md](README_CONSULTATIONS.md) - Documentación de consultas médicas
- `/test_get_patient.ps1` - Script de ejemplo para obtener paciente
- `/custom/rcvrest/class/api_rcvrest.class.php` - Código fuente de la API
