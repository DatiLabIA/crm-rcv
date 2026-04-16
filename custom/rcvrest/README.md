# API REST - Dolibarr CRM RCV

Documentación completa de la API REST del módulo `rcvrest` para el CRM médico de RCV.

## 🔗 Enlaces Rápidos

- **[Documentación de Pacientes →](README_PATIENTS.md)** - GET y PUT de pacientes
- **[Documentación de Consultas →](README_CONSULTATIONS.md)** - GET, POST, PUT de consultas médicas

---

## 📋 Resumen

Este módulo proporciona una API REST para:
- ✅ Consultar información de pacientes
- ✅ Actualizar datos de pacientes
- ✅ Crear consultas médicas
- ✅ Actualizar consultas médicas
- ✅ Listar consultas por paciente
- ✅ Asignar múltiples usuarios a consultas
- ✅ Auditoría automática de cambios

---

## 🚀 Inicio Rápido

### URL Base
```
https://crm.rcvco.org/api/index.php/rcvrest
```

### Autenticación

Todas las peticiones requieren el header:
```http
DOLAPIKEY: l7l73HZ4fy76WJT68ihanAAs0EIU9dAl
```

**Usuario:** chatbot (ID: 7)

**Ejemplo:**

```bash
curl -s -X GET \
  "https://crm.rcvco.org/api/index.php/rcvrest/patients?limit=10&nom=garcia" \
  -H "DOLAPIKEY: tu_api_key"
```

**Respuesta:**

```json
[
  {
    "id": 142,
    "nom": "GARCIA",
    "firstname": "MARIA",
    "name_alias": "",
    "address": "Calle 45 #12-34",
    "zip": "110111",
    "town": "Bogotá",
    "state_id": 1025,
    "country_id": 70,
    "phone": "3001234567",
    "fax": "",
    "email": "maria@example.com",
    "datec": "2025-01-15 10:30:00",
    "tms": "2025-06-01 14:22:00",
    "status": 1,
    "extrafields": {
      "n_documento": "1234567890",
      "eps": "5",
      "programa": "3",
      "medicamento": "12",
      "operador_logistico": "2",
      "medico_tratante": "8"
    }
  }
]
```

---

### Obtener un paciente

```
GET /rcvrest/patients/{id}
```

**Ejemplo:**

```bash
curl -s -X GET \
  "https://crm.rcvco.org/api/index.php/rcvrest/patients/142" \
  -H "DOLAPIKEY: tu_api_key"
```

**Respuesta:** Retorna el objeto completo incluyendo `extrafields`, `medical` (datos de `cabinetmed_patient`) y notas.

```json
{
  "id": 142,
  "nom": "GARCIA",
  "firstname": "MARIA",
  "name_alias": "",
  "address": "Calle 45 #12-34",
  "zip": "110111",
  "town": "Bogotá",
  "state_id": 1025,
  "country_id": 70,
  "phone": "3001234567",
  "fax": "",
  "email": "maria@example.com",
  "note_public": "",
  "note_private": "",
  "datec": "2025-01-15 10:30:00",
  "tms": "2025-06-01 14:22:00",
  "status": 1,
  "canvas": "patient@cabinetmed",
  "extrafields": {
    "n_documento": "1234567890",
    "eps": "5",
    "programa": "3",
    "medicamento": "12",
    "operador_logistico": "2",
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
    "alert_traitclass": 0,
    "alert_traitallergie": 1,
    "alert_traitintol": 0,
    "alert_traitspec": 0,
    "alert_note": 0
  }
}
```

---

### Crear paciente

```
POST /rcvrest/patients
```

**Body (JSON):**

```json
{
  "nom": "RODRIGUEZ",
  "firstname": "JUAN",
  "address": "Carrera 10 #20-30",
  "zip": "110111",
  "town": "Bogotá",
  "state_id": 1025,
  "country_id": 70,
  "phone": "3109876543",
  "email": "juan@example.com",
  "extrafields": {
    "n_documento": "9876543210",
    "eps": "5",
    "programa": "3",
    "medicamento": "12",
    "operador_logistico": "2",
    "medico_tratante": "8"
  },
  "medical": {
    "note_antemed": "Diabetes tipo 2",
    "note_traitclass": "Metformina 850mg",
    "alert_antemed": 1
  }
}
```

**Campos obligatorios:** `nom`

**Campos auto-asignados:** `canvas = 'patient@cabinetmed'`, `client = 1`, `particulier = 1`, `code_client = -1` (autogenerado).

**Ejemplo:**

```bash
curl -s -X POST \
  "https://crm.rcvco.org/api/index.php/rcvrest/patients" \
  -H "DOLAPIKEY: tu_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "nom": "RODRIGUEZ",
    "firstname": "JUAN",
    "phone": "3109876543",
    "extrafields": {
      "n_documento": "9876543210",
      "eps": "5"
    }
  }'
```

**Respuesta:** ID del paciente creado.

```json
143
```

---

### Actualizar paciente

```
PUT /rcvrest/patients/{id}
```

Solo enviar los campos que se desean modificar. Los campos no incluidos conservan su valor actual.

**Ejemplo:**

```bash
curl -s -X PUT \
  "https://crm.rcvco.org/api/index.php/rcvrest/patients/143" \
  -H "DOLAPIKEY: tu_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "3201112233",
    "extrafields": {
      "eps": "8"
    },
    "medical": {
      "note_traitallergie": "Sulfonamidas",
      "alert_traitallergie": 1
    }
  }'
```

**Respuesta:** ID del paciente actualizado.

```json
143
```

---

## Endpoints de Consultas

### Listar consultas

```
GET /rcvrest/consultations
```

**Parámetros query:**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `sortfield` | string | Campo de ordenamiento (default: `t.rowid`) |
| `sortorder` | string | `ASC` o `DESC` (default: `DESC`) |
| `limit` | int | Máximo de resultados, tope 500 (default: 100) |
| `offset` | int | Desplazamiento para paginación (default: 0) |
| `fk_soc` | int | Filtrar por ID de paciente |
| `fk_user` | int | Filtrar por ID de usuario asignado |
| `status` | int | Filtrar por estado (-1 = todos) |
| `tipo_atencion` | string | Filtrar por tipo de atención |
| `date_start_from` | string | Fecha inicio desde (`YYYY-MM-DD`) |
| `date_start_to` | string | Fecha inicio hasta (`YYYY-MM-DD`) |

**Ejemplo:**

```bash
curl -s -X GET \
  "https://crm.rcvco.org/api/index.php/rcvrest/consultations?fk_soc=142&limit=5" \
  -H "DOLAPIKEY: tu_api_key"
```

**Respuesta:**

```json
[
  {
    "id": 85,
    "fk_soc": 142,
    "fk_user": 3,
    "patient_nom": "GARCIA",
    "patient_firstname": "MARIA",
    "date_start": "2025-06-01 09:00:00",
    "date_end": "2025-06-01 09:30:00",
    "tipo_atencion": "seguimiento",
    "cumplimiento": "si",
    "razon_inc": "",
    "mes_actual": "junio",
    "proximo_mes": "julio",
    "dificultad": 0,
    "motivo": "Control mensual",
    "diagnostico": "Hipertensión controlada",
    "procedimiento": "",
    "insumos_enf": "",
    "rx_num": "",
    "medicamentos": "Losartán 50mg",
    "observaciones": "Paciente estable",
    "status": 1,
    "custom_data": {
      "presion_arterial": "120/80",
      "peso": "72"
    },
    "recurrence_enabled": 0,
    "recurrence_interval": 0,
    "recurrence_unit": null,
    "recurrence_end_type": null,
    "recurrence_end_date": null,
    "recurrence_parent_id": null,
    "note_private": "",
    "note_public": "",
    "datec": "2025-06-01 08:45:00",
    "tms": "2025-06-01 09:35:00",
    "fk_user_creat": 3,
    "fk_user_modif": null
  }
]
```

---

### Obtener una consulta

```
GET /rcvrest/consultations/{id}
```

**Ejemplo:**

```bash
curl -s -X GET \
  "https://crm.rcvco.org/api/index.php/rcvrest/consultations/85" \
  -H "DOLAPIKEY: tu_api_key"
```

---

### Crear consulta

```
POST /rcvrest/consultations
```

**Body (JSON):**

```json
{
  "fk_soc": 142,
  "fk_user": 3,
  "date_start": "2025-07-01 09:00:00",
  "date_end": "2025-07-01 09:30:00",
  "tipo_atencion": "seguimiento",
  "cumplimiento": "si",
  "motivo": "Control mensual julio",
  "diagnostico": "Hipertensión controlada",
  "medicamentos": "Losartán 50mg",
  "observaciones": "Continuar tratamiento",
  "status": 1,
  "custom_data": {
    "presion_arterial": "118/78",
    "peso": "71.5"
  }
}
```

**Campos obligatorios:** `fk_soc`

**Fechas:** Se aceptan tanto formato ISO (`"2025-07-01 09:00:00"`) como timestamp Unix (`1751353200`).

**Ejemplo:**

```bash
curl -s -X POST \
  "https://crm.rcvco.org/api/index.php/rcvrest/consultations" \
  -H "DOLAPIKEY: tu_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "fk_soc": 142,
    "fk_user": 3,
    "date_start": "2025-07-01 09:00:00",
    "tipo_atencion": "seguimiento",
    "motivo": "Control mensual julio",
    "status": 1
  }'
```

**Respuesta:** ID de la consulta creada.

```json
86
```

---

### Actualizar consulta

```
PUT /rcvrest/consultations/{id}
```

Solo enviar los campos a modificar. El campo `custom_data` se **fusiona** (merge) con los datos existentes — no reemplaza todo el objeto.

**Ejemplo:**

```bash
curl -s -X PUT \
  "https://crm.rcvco.org/api/index.php/rcvrest/consultations/86" \
  -H "DOLAPIKEY: tu_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "diagnostico": "Hipertensión controlada, leve mejora",
    "observaciones": "Reducir dosis en próximo control",
    "custom_data": {
      "presion_arterial": "115/75"
    }
  }'
```

**Respuesta:** ID de la consulta actualizada.

```json
86
```

---

## Referencia de campos

### Campos del paciente (Societe)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `nom` | string | **Obligatorio.** Apellido(s) |
| `firstname` | string | Nombre(s) |
| `name_alias` | string | Alias o nombre conocido |
| `address` | string | Dirección |
| `zip` | string | Código postal |
| `town` | string | Ciudad |
| `state_id` | int | ID del departamento (`llx_c_departements.rowid`) |
| `country_id` | int | ID del país (`llx_c_country.rowid`). Colombia = 70 |
| `phone` | string | Teléfono principal |
| `fax` | string | Teléfono secundario |
| `email` | string | Correo electrónico |
| `note_public` | string | Nota pública |
| `note_private` | string | Nota privada |

### Extrafields del paciente

Se envían como objeto dentro de `"extrafields"`. Los nombres corresponden a los extrafields configurados en `llx_societe_extrafields`:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `n_documento` | string | Número de documento de identidad |
| `eps` | string | ID de la EPS (referencia a tabla de gestión) |
| `programa` | string | ID del programa |
| `medicamento` | string | ID del medicamento |
| `operador_logistico` | string | ID del operador logístico |
| `medico_tratante` | string | ID del médico tratante |

### Datos médicos (Cabinetmed)

Se envían como objeto dentro de `"medical"`. Corresponden a `llx_cabinetmed_patient`:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `note_antemed` | string | Antecedentes médicos |
| `note_antechirgen` | string | Antecedentes quirúrgicos generales |
| `note_antechirortho` | string | Antecedentes quirúrgicos ortopédicos |
| `note_anterhum` | string | Antecedentes reumatológicos |
| `note_other` | string | Otros antecedentes |
| `note_traitclass` | string | Tratamiento clásico |
| `note_traitallergie` | string | Alergias |
| `note_traitintol` | string | Intolerancias |
| `note_traitspec` | string | Tratamiento especializado |
| `alert_antemed` | int (0/1) | Alerta: antecedentes médicos |
| `alert_antechirgen` | int (0/1) | Alerta: antecedentes quirúrgicos generales |
| `alert_antechirortho` | int (0/1) | Alerta: antecedentes quirúrgicos ortopédicos |
| `alert_anterhum` | int (0/1) | Alerta: antecedentes reumatológicos |
| `alert_other` | int (0/1) | Alerta: otros |
| `alert_traitclass` | int (0/1) | Alerta: tratamiento clásico |
| `alert_traitallergie` | int (0/1) | Alerta: alergias |
| `alert_traitintol` | int (0/1) | Alerta: intolerancias |
| `alert_traitspec` | int (0/1) | Alerta: tratamiento especializado |
| `alert_note` | int (0/1) | Alerta: nota general |

### Campos de consulta (ExtConsultation)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `fk_soc` | int | **Obligatorio.** ID del paciente |
| `fk_user` | int | ID del usuario asignado (profesional de salud) |
| `date_start` | string/int | Fecha y hora de inicio (ISO o timestamp) |
| `date_end` | string/int | Fecha y hora de fin (ISO o timestamp) |
| `tipo_atencion` | string | Tipo de atención (ej: seguimiento, primera vez) |
| `cumplimiento` | string | Cumplimiento del paciente |
| `razon_inc` | string | Razón de incumplimiento |
| `mes_actual` | string | Mes actual del ciclo |
| `proximo_mes` | string | Próximo mes programado |
| `dificultad` | int | Nivel de dificultad |
| `motivo` | string | Motivo de la consulta |
| `diagnostico` | string | Diagnóstico |
| `procedimiento` | string | Procedimiento realizado |
| `insumos_enf` | string | Insumos de enfermería |
| `rx_num` | string | Número de receta |
| `medicamentos` | string | Medicamentos recetados |
| `observaciones` | string | Observaciones generales |
| `status` | int | Estado de la consulta |
| `custom_data` | object | Datos adicionales dinámicos (JSON). Se fusiona en actualizaciones |
| `note_private` | string | Nota privada |
| `note_public` | string | Nota pública |
| `recurrence_enabled` | int (0/1) | Recurrencia habilitada |
| `recurrence_interval` | int | Intervalo de recurrencia |
| `recurrence_unit` | string | Unidad de recurrencia |
| `recurrence_end_type` | string | Tipo de fin de recurrencia |
| `recurrence_end_date` | string | Fecha fin de recurrencia |
| `recurrence_parent_id` | int | ID de la consulta padre (recurrencia) |

---

## Códigos de respuesta

| Código | Significado |
|--------|-------------|
| `200` | Éxito |
| `400` | Datos inválidos o campo obligatorio faltante |
| `403` | Sin permisos suficientes |
| `404` | Recurso no encontrado |
| `500` | Error interno del servidor |

---

## Activación

1. Subir la carpeta `custom/rcvrest/` al servidor por FTP
2. Ir a **Inicio → Configuración → Módulos/Aplicaciones**
3. Buscar y activar el módulo **Rcvapi**
4. Ir a **Usuarios & Grupos → (usuario API) → Permisos** y asignar los 4 permisos de Rcv
5. En la pestaña **API** del usuario, generar o copiar el token (`api_key`)

---

## Notas

- Los IDs de `eps`, `programa`, `medicamento`, `operador_logistico` y `medico_tratante` corresponden a los registros del módulo **Gestión** (`custom/gestion/`).
- El campo `state_id` corresponde a `llx_c_departements.rowid`. Para Colombia los departamentos típicos están precargados.
- El campo `country_id` para Colombia es **70**.
- Las consultas usan la tabla `llx_cabinetmed_extcons` del módulo **cabinetmed_extcons**.
- El campo `custom_data` de las consultas es un JSON libre. Al actualizar, los datos nuevos se fusionan con los existentes (no se reemplazan).
