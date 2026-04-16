# RCV API — Guía de uso

API REST para gestionar pacientes y consultas médicas desde servicios externos.

**Base URL:** `https://crm.rcvco.org/api/index.php`

---

## Autenticación

Todas las peticiones requieren el header `DOLAPIKEY` con el token del usuario:

```
DOLAPIKEY: tu_token_aquí
```

### Obtener el token

1. En Dolibarr: **Inicio > Usuarios y Grupos > [usuario]**
2. En la ficha, buscar **"Clave API"** y hacer clic en **"Generar"** si está vacío
3. Copiar el valor generado

### Permisos requeridos

El usuario debe tener activados los permisos del módulo **RcvApi**:

| Permiso | Descripción |
|---------|-------------|
| `rcvapi > patient > read` | Leer pacientes |
| `rcvapi > patient > write` | Crear/modificar pacientes |
| `rcvapi > consultation > read` | Leer consultas |
| `rcvapi > consultation > write` | Crear/modificar consultas |

---

## Pacientes

**Endpoint base:** `/rcvpatients`

### Listar pacientes

```
GET /api/index.php/rcvpatients
```

**Parámetros de consulta (query):**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `nom` | string | Filtrar por nombre (búsqueda parcial, LIKE) |
| `n_documento` | string | Filtrar por número de documento (exacto) |
| `eps` | string | Filtrar por ID de EPS |
| `programa` | string | Filtrar por ID de programa |
| `datec_from` | string | Fecha de creación desde (`YYYY-MM-DD`) |
| `datec_to` | string | Fecha de creación hasta (`YYYY-MM-DD`) |
| `sortfield` | string | Campo para ordenar (default: `s.rowid`) |
| `sortorder` | string | Dirección: `ASC` o `DESC` (default: `ASC`) |
| `limit` | int | Máximo de resultados (default: 100, max: 500) |
| `offset` | int | Saltar N resultados (default: 0) |

**Ejemplo:**

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvpatients?nom=garcia&programa=3&limit=20" \
  -H "DOLAPIKEY: abc123xyz456"
```

**Respuesta:**

```json
[
  {
    "id": 142,
    "nom": "GARCIA LOPEZ",
    "firstname": "MARIA",
    "name_alias": "",
    "address": "Calle 45 #12-30",
    "zip": "110111",
    "town": "BOGOTA",
    "state_id": 2877,
    "country_id": 70,
    "phone": "3001234567",
    "fax": "",
    "email": "maria.garcia@email.com",
    "datec": "2025-03-15 10:30:00",
    "tms": "2025-04-01 14:22:00",
    "status": 1,
    "extrafields": {
      "n_documento": "1234567890",
      "eps": "5",
      "programa": "3",
      "medicamento": "2",
      "operador_logistico": "1",
      "medico_tratante": "8"
    }
  }
]
```

---

### Obtener un paciente

```
GET /api/index.php/rcvpatients/{id}
```

**Ejemplo:**

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvpatients/142" \
  -H "DOLAPIKEY: abc123xyz456"
```

**Respuesta:**

```json
{
  "id": 142,
  "nom": "GARCIA LOPEZ",
  "firstname": "MARIA",
  "name_alias": "",
  "address": "Calle 45 #12-30",
  "zip": "110111",
  "town": "BOGOTA",
  "state_id": 2877,
  "country_id": 70,
  "phone": "3001234567",
  "fax": "",
  "email": "maria.garcia@email.com",
  "note_public": "",
  "note_private": "",
  "datec": "2025-03-15 10:30:00",
  "tms": "2025-04-01 14:22:00",
  "status": 1,
  "canvas": "patient@cabinetmed",
  "extrafields": {
    "n_documento": "1234567890",
    "eps": "5",
    "programa": "3",
    "medicamento": "2",
    "operador_logistico": "1",
    "medico_tratante": "8"
  },
  "medical": {
    "note_antemed": "Hipertensión arterial",
    "note_antechirgen": "",
    "note_antechirortho": "",
    "note_anterhum": "",
    "note_other": "",
    "note_traitclass": "Losartán 50mg",
    "note_traitallergie": "Penicilina",
    "note_traitintol": "",
    "note_traitspec": "",
    "alert_antemed": 1,
    "alert_antechirgen": 0,
    "alert_antechirortho": 0,
    "alert_anterhum": 0,
    "alert_other": 0,
    "alert_traitclass": 1,
    "alert_traitallergie": 1,
    "alert_traitintol": 0,
    "alert_traitspec": 0,
    "alert_note": 0
  }
}
```

---

### Crear un paciente

```
POST /api/index.php/rcvpatients
```

**Body (JSON):**

```json
{
  "nom": "RODRIGUEZ PEREZ",
  "firstname": "CARLOS",
  "address": "Carrera 7 #45-10",
  "zip": "110111",
  "town": "BOGOTA",
  "state_id": 2877,
  "country_id": 70,
  "phone": "3109876543",
  "email": "carlos.rodriguez@email.com",
  "extrafields": {
    "n_documento": "9876543210",
    "eps": "3",
    "programa": "2",
    "medicamento": "5",
    "operador_logistico": "1",
    "medico_tratante": "4"
  },
  "medical": {
    "note_antemed": "Diabetes tipo 2",
    "note_traitclass": "Metformina 850mg",
    "alert_antemed": 1,
    "alert_traitclass": 1
  }
}
```

**Campos obligatorios:** `nom`

**Campos opcionales del body:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `nom` | string | **Requerido.** Apellido(s) |
| `firstname` | string | Nombre(s) |
| `name_alias` | string | Alias |
| `address` | string | Dirección |
| `zip` | string | Código postal |
| `town` | string | Ciudad |
| `state_id` | int | ID del departamento (tabla `llx_c_departements`) |
| `country_id` | int | ID del país (70 = Colombia) |
| `phone` | string | Teléfono |
| `fax` | string | Teléfono secundario / celular |
| `email` | string | Correo electrónico |
| `note_public` | string | Nota pública |
| `note_private` | string | Nota privada |
| `extrafields` | object | Campos extra (ver tabla abajo) |
| `medical` | object | Datos médicos de Dolimed (ver tabla abajo) |

**Extrafields disponibles:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `n_documento` | string | Número de documento de identidad |
| `eps` | string | ID de la EPS (referencia a `llx_gestion_eps`) |
| `programa` | string | ID del programa (referencia a `llx_gestion_programa`) |
| `medicamento` | string | ID del medicamento (referencia a `llx_gestion_medicamento`) |
| `operador_logistico` | string | ID del operador logístico |
| `medico_tratante` | string | ID del médico tratante |

**Campos médicos (medical):**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `note_antemed` | string | Antecedentes médicos |
| `note_antechirgen` | string | Antecedentes quirúrgicos generales |
| `note_antechirortho` | string | Antecedentes quirúrgicos ortopédicos |
| `note_anterhum` | string | Antecedentes reumatológicos |
| `note_other` | string | Otros antecedentes |
| `note_traitclass` | string | Tratamientos clásicos |
| `note_traitallergie` | string | Alergias |
| `note_traitintol` | string | Intolerancias |
| `note_traitspec` | string | Tratamientos especiales |
| `alert_antemed` | int | Alerta antecedentes médicos (0/1) |
| `alert_antechirgen` | int | Alerta quirúrgicos generales (0/1) |
| `alert_antechirortho` | int | Alerta quirúrgicos ortopédicos (0/1) |
| `alert_anterhum` | int | Alerta reumatológicos (0/1) |
| `alert_other` | int | Alerta otros (0/1) |
| `alert_traitclass` | int | Alerta tratamientos (0/1) |
| `alert_traitallergie` | int | Alerta alergias (0/1) |
| `alert_traitintol` | int | Alerta intolerancias (0/1) |
| `alert_traitspec` | int | Alerta tratamientos especiales (0/1) |
| `alert_note` | int | Alerta nota (0/1) |

**Ejemplo:**

```bash
curl -X POST "https://crm.rcvco.org/api/index.php/rcvpatients" \
  -H "DOLAPIKEY: abc123xyz456" \
  -H "Content-Type: application/json" \
  -d '{
    "nom": "RODRIGUEZ PEREZ",
    "firstname": "CARLOS",
    "phone": "3109876543",
    "country_id": 70,
    "extrafields": {
      "n_documento": "9876543210",
      "eps": "3",
      "programa": "2"
    }
  }'
```

**Respuesta exitosa:**

```json
142
```

Retorna el ID del paciente creado.

---

### Actualizar un paciente

```
PUT /api/index.php/rcvpatients/{id}
```

Solo se actualizan los campos incluidos en el body. Los campos no enviados se mantienen sin cambios.

**Ejemplo — cambiar teléfono y EPS:**

```bash
curl -X PUT "https://crm.rcvco.org/api/index.php/rcvpatients/142" \
  -H "DOLAPIKEY: abc123xyz456" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "3201112233",
    "extrafields": {
      "eps": "7"
    }
  }'
```

**Ejemplo — actualizar datos médicos:**

```bash
curl -X PUT "https://crm.rcvco.org/api/index.php/rcvpatients/142" \
  -H "DOLAPIKEY: abc123xyz456" \
  -H "Content-Type: application/json" \
  -d '{
    "medical": {
      "note_traitallergie": "Penicilina, Sulfa",
      "alert_traitallergie": 1
    }
  }'
```

**Respuesta exitosa:**

```json
142
```

Retorna el ID del paciente actualizado.

---

## Consultas (Atenciones)

**Endpoint base:** `/rcvconsultations`

### Listar consultas

```
GET /api/index.php/rcvconsultations
```

**Parámetros de consulta (query):**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `fk_soc` | int | Filtrar por ID del paciente |
| `fk_user` | int | Filtrar por ID del usuario encargado |
| `status` | int | Filtrar por estado (0, 1, etc.). -1 = todos |
| `tipo_atencion` | string | Filtrar por tipo de atención |
| `date_start_from` | string | Fecha inicio desde (`YYYY-MM-DD`) |
| `date_start_to` | string | Fecha inicio hasta (`YYYY-MM-DD`) |
| `sortfield` | string | Campo para ordenar (default: `t.rowid`) |
| `sortorder` | string | `ASC` o `DESC` (default: `DESC`) |
| `limit` | int | Máximo de resultados (default: 100, max: 500) |
| `offset` | int | Saltar N resultados (default: 0) |

**Ejemplo — consultas de un paciente:**

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvconsultations?fk_soc=142&limit=10" \
  -H "DOLAPIKEY: abc123xyz456"
```

**Ejemplo — consultas por rango de fecha:**

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvconsultations?date_start_from=2025-04-01&date_start_to=2025-04-30&tipo_atencion=seguimiento" \
  -H "DOLAPIKEY: abc123xyz456"
```

**Respuesta:**

```json
[
  {
    "id": 85,
    "fk_soc": 142,
    "fk_user": 3,
    "patient_nom": "GARCIA LOPEZ",
    "patient_firstname": "MARIA",
    "date_start": "2025-04-10 09:00:00",
    "date_end": "2025-04-10 09:30:00",
    "tipo_atencion": "seguimiento",
    "cumplimiento": "si",
    "razon_inc": "",
    "mes_actual": "abril",
    "proximo_mes": "mayo",
    "dificultad": 0,
    "motivo": "Control mensual",
    "diagnostico": "HTA controlada",
    "procedimiento": "Toma de TA, revisión de laboratorios",
    "insumos_enf": "",
    "rx_num": "RX-2025-001",
    "medicamentos": "Losartán 50mg",
    "observaciones": "Paciente estable",
    "status": 1,
    "custom_data": {
      "peso": "72",
      "talla": "165",
      "ta_sistolica": "120",
      "ta_diastolica": "80"
    },
    "recurrence_enabled": 1,
    "recurrence_interval": 1,
    "recurrence_unit": "months",
    "recurrence_end_type": "forever",
    "recurrence_end_date": null,
    "recurrence_parent_id": null,
    "note_private": "",
    "note_public": "",
    "datec": "2025-04-10 08:45:00",
    "tms": "2025-04-10 09:35:00",
    "fk_user_creat": 3,
    "fk_user_modif": null
  }
]
```

---

### Obtener una consulta

```
GET /api/index.php/rcvconsultations/{id}
```

**Ejemplo:**

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvconsultations/85" \
  -H "DOLAPIKEY: abc123xyz456"
```

---

### Crear una consulta

```
POST /api/index.php/rcvconsultations
```

**Body (JSON):**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `fk_soc` | int | **Requerido.** ID del paciente |
| `fk_user` | int | ID del usuario encargado (default: usuario autenticado) |
| `date_start` | string/int | Fecha inicio (`YYYY-MM-DD HH:MM:SS` o timestamp Unix) |
| `date_end` | string/int | Fecha fin (`YYYY-MM-DD HH:MM:SS` o timestamp Unix) |
| `tipo_atencion` | string | Tipo de atención |
| `cumplimiento` | string | Cumplimiento (`si`, `no`) |
| `razon_inc` | string | Razón de incumplimiento |
| `mes_actual` | string | Mes actual |
| `proximo_mes` | string | Próximo mes |
| `dificultad` | int | ¿Tiene dificultad? (0/1) |
| `motivo` | string | Motivo de la consulta |
| `diagnostico` | string | Diagnóstico |
| `procedimiento` | string | Procedimiento realizado |
| `insumos_enf` | string | Insumos de enfermería |
| `rx_num` | string | Número de fórmula |
| `medicamentos` | string | Medicamentos |
| `observaciones` | string | Observaciones |
| `status` | int | Estado (default: 0) |
| `custom_data` | object | Campos dinámicos personalizados (JSON libre) |
| `note_private` | string | Nota privada |
| `note_public` | string | Nota pública |
| `recurrence_enabled` | int | Habilitar recurrencia (0/1) |
| `recurrence_interval` | int | Intervalo de recurrencia |
| `recurrence_unit` | string | Unidad: `days`, `weeks`, `months` |
| `recurrence_end_type` | string | Tipo fin: `forever`, `date`, `count` |
| `recurrence_end_date` | string | Fecha fin de recurrencia |
| `recurrence_parent_id` | int | ID de consulta padre (si es recurrente) |

**Ejemplo:**

```bash
curl -X POST "https://crm.rcvco.org/api/index.php/rcvconsultations" \
  -H "DOLAPIKEY: abc123xyz456" \
  -H "Content-Type: application/json" \
  -d '{
    "fk_soc": 142,
    "fk_user": 3,
    "date_start": "2025-05-10 09:00:00",
    "date_end": "2025-05-10 09:30:00",
    "tipo_atencion": "seguimiento",
    "cumplimiento": "si",
    "mes_actual": "mayo",
    "proximo_mes": "junio",
    "motivo": "Control mensual",
    "diagnostico": "HTA controlada",
    "procedimiento": "Toma de TA",
    "medicamentos": "Losartán 50mg",
    "observaciones": "Sin novedad",
    "status": 1,
    "custom_data": {
      "peso": "71",
      "talla": "165",
      "ta_sistolica": "118",
      "ta_diastolica": "78"
    }
  }'
```

**Respuesta exitosa:**

```json
86
```

Retorna el ID de la consulta creada.

---

### Actualizar una consulta

```
PUT /api/index.php/rcvconsultations/{id}
```

Solo se actualizan los campos enviados. Los `custom_data` se **mergean** con los existentes (no se reemplazan).

**Ejemplo — completar una consulta:**

```bash
curl -X PUT "https://crm.rcvco.org/api/index.php/rcvconsultations/85" \
  -H "DOLAPIKEY: abc123xyz456" \
  -H "Content-Type: application/json" \
  -d '{
    "status": 1,
    "diagnostico": "HTA controlada, sin cambios",
    "observaciones": "Continuar mismo esquema",
    "custom_data": {
      "imc": "26.4"
    }
  }'
```

**Respuesta exitosa:**

```json
85
```

---

## Códigos de respuesta

| Código | Significado |
|--------|-------------|
| `200` | Éxito |
| `400` | Datos faltantes o inválidos |
| `403` | Sin permisos (verificar DOLAPIKEY y permisos del usuario) |
| `404` | Registro no encontrado |
| `500` | Error del servidor |

**Formato de error:**

```json
{
  "error": {
    "code": 403,
    "message": "Not allowed"
  }
}
```

---

## Notas

- Los IDs de EPS, programa, medicamento, etc. son referencias a tablas del módulo **gestion** (`llx_gestion_eps`, `llx_gestion_programa`, etc.). Consultar esas tablas en phpMyAdmin para ver los valores disponibles.
- El campo `fax` de pacientes se usa comúnmente como **teléfono secundario/celular**.
- Las fechas en las respuestas están en formato `YYYY-MM-DD HH:MM:SS`.
- Las fechas en POST/PUT se aceptan como string ISO (`2025-04-10 09:00:00`) o como timestamp Unix.
- El `custom_data` de consultas es un campo JSON libre — se puede usar para almacenar cualquier dato adicional dinámico según el tipo de atención.
- Al actualizar `custom_data` vía PUT, los campos nuevos se agregan y los existentes se sobreescriben, pero los no mencionados se mantienen.
