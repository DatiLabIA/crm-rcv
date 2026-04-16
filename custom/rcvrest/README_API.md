# 📘 API REST - CRM RCV - Guía Completa

Índice principal de la documentación de la API REST del módulo `rcvrest`.

---

## 📑 **Documentación por Tema**

### 🔹 [**Consultas Médicas**](README_CONSULTATIONS.md)
Documentación completa para gestionar consultas médicas:
- Crear consultas (POST)
- Actualizar consultas (PUT)
- Listar consultas (GET)
- Estados de consultas (En progreso, Completada, Cancelada)
- Tipos de atención (16 tipos disponibles)
- Asignación múltiple de usuarios
- Recurrencia de consultas
- Custom data en JSON

### 🔹 [**Pacientes**](README_PATIENTS.md)
Documentación completa para gestionar pacientes:
- Obtener datos del paciente (GET)
- Actualizar paciente (PUT)
- Campos básicos y extrafields
- Campos médicos (antecedentes, alergias)
- Resolución de IDs a nombres (EPS, medicamentos, etc.)
- Formateo automático de fechas
- Auditoría de cambios

---

## 🚀 **Acceso Rápido**

### URL Base
```
https://crm.rcvco.org/api/index.php/rcvrest
```

### Autenticación
```http
DOLAPIKEY: l7l73HZ4fy76WJT68ihanAAs0EIU9dAl
```

**Usuario API:** chatbot (ID: 7)

---

## 📋 **Endpoints Disponibles**

| Método | Endpoint | Descripción | Documentación |
|--------|----------|-------------|---------------|
| GET | `/patients/{id}` | Obtener datos de paciente | [Ver →](README_PATIENTS.md#2-obtener-paciente-por-id) |
| PUT | `/patients/{id}` | Actualizar paciente | [Ver →](README_PATIENTS.md#3-actualizar-paciente) |
| GET | `/consultations` | Listar consultas | [Ver →](README_CONSULTATIONS.md#1-listar-consultas) |
| GET | `/consultations/{id}` | Obtener consulta | [Ver →](README_CONSULTATIONS.md#2-obtener-consulta-por-id) |
| POST | `/consultations` | Crear consulta | [Ver →](README_CONSULTATIONS.md#3-crear-consulta) |
| PUT | `/consultations/{id}` | Actualizar consulta | [Ver →](README_CONSULTATIONS.md#4-actualizar-consulta) |

---

## 💡 **Ejemplos Rápidos**

### Obtener Paciente (PowerShell)
```powershell
$headers = @{ "DOLAPIKEY" = "l7l73HZ4fy76WJT68ihanAAs0EIU9dAl" }

$paciente = Invoke-RestMethod `
    -Uri "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" `
    -Method Get `
    -Headers $headers

Write-Host "Paciente: $($paciente.nom)"
Write-Host "EPS: $($paciente.array_options.options_eps)"
```

### Crear Consulta (PowerShell)
```powershell
$headers = @{
    "DOLAPIKEY" = "l7l73HZ4fy76WJT68ihanAAs0EIU9dAl"
    "Content-Type" = "application/json"
}

$consulta = @{
    fk_soc = 4024
    tipo_atencion = "gestion_whatsapp"
    status = 0
    date_start = "2026-04-17 10:00:00"
    observaciones = "Consulta desde API"
    assigned_users = @(1, 5)  # Usuarios adicionales
} | ConvertTo-Json

$id = Invoke-RestMethod `
    -Uri "https://crm.rcvco.org/api/index.php/rcvrest/consultations" `
    -Method Post `
    -Headers $headers `
    -Body $consulta

Write-Host "Consulta creada: $id"
```

### Actualizar Estado de Consulta (PowerShell)
```powershell
$update = @{
    status = 1  # Completada
    observaciones = "Consulta completada exitosamente"
} | ConvertTo-Json

Invoke-RestMethod `
    -Uri "https://crm.rcvco.org/api/index.php/rcvrest/consultations/11796" `
    -Method Put `
    -Headers $headers `
    -Body $update
```

---

## 🌟 **Características Destacadas**

### ✅ Asignación Automática de Usuarios
El usuario de la API key (chatbot) se asigna automáticamente a todas las consultas.

```json
{
    "fk_soc": 4024,
    "assigned_users": [1, 5]  // El chatbot (7) se agrega automáticamente
}
```
**Resultado:** assigned_users = [7, 1, 5]

### ✅ Auditoría Automática
Todos los cambios en pacientes se registran automáticamente:
- Qué cambió
- Valor anterior y nuevo
- Quién lo cambió
- Cuándo ocurrió

### ✅ Resolución de IDs
La API convierte automáticamente IDs en nombres legibles:

**Envías:**
```json
{ "array_options": { "options_eps": 1 } }
```

**Recibes:**
```json
{ 
    "array_options": {
        "options_eps": "NUEVA EPS",
        "options_eps_id": 1
    }
}
```

### ✅ Custom Data en JSON
Guarda datos adicionales en formato JSON:

```json
{
    "custom_data": {
        "origen": "chatbot",
        "conversacion_id": "12345",
        "satisfaccion": 5
    }
}
```

---

## 📊 **Estados de Consulta**

| Valor | Estado | Color |
|-------|--------|-------|
| 0 | En progreso | Azul |
| 1 | Completada | Verde |
| 2 | Cancelada | Rojo |

---

## 🧪 **Scripts de Prueba Disponibles**

| Script | Descripción |
|--------|-------------|
| `test_get_patient.ps1` | Obtener datos del paciente 4024 |
| `test_create_consultation.ps1` | Crear consulta de prueba |
| `test_list_consultations.ps1` | Listar consultas de un paciente |
| `test_list_consultation_types.ps1` | Ver tipos de atención |
| `test_multi_user_chatbot.ps1` | Test de asignación multi-usuario |
| `test_raw_response.ps1` | Ver respuesta cruda de la API |

**Ejecutar:**
```powershell
.\test_get_patient.ps1
```

---

## 🎯 **Casos de Uso Comunes**

### 1. Crear Consulta de WhatsApp con Usuarios Múltiples
[Ver ejemplo completo →](README_CONSULTATIONS.md#ejemplo-completo-crear-consulta)

### 2. Actualizar Datos del Paciente (Teléfono, Email)
[Ver ejemplo completo →](README_PATIENTS.md#actualizar-datos-básicos)

### 3. Cambiar Estado de Consulta a Completada
[Ver ejemplo completo →](README_CONSULTATIONS.md#actualizar-estado-a-completada)

### 4. Listar Consultas de un Paciente Filtradas por Estado
[Ver ejemplo completo →](README_CONSULTATIONS.md#listar-consultas-de-un-paciente)

### 5. Actualizar EPS y Medicamento del Paciente
[Ver ejemplo completo →](README_PATIENTS.md#actualizar-eps-y-medicamento)

---

## 🔒 **Permisos Necesarios**

El usuario API debe tener estos permisos configurados:

| Recurso | Acción | Permiso |
|---------|--------|---------|
| Pacientes | Lectura | `rcvrest->patient->read` |
| Pacientes | Escritura | `rcvrest->patient->write` |
| Consultas | Lectura | `rcvrest->consultation->read` |
| Consultas | Escritura | `rcvrest->consultation->write` |

✅ El usuario **chatbot** (ID: 7) ya tiene todos estos permisos configurados.

---

## ⚠️ **Códigos de Error Comunes**

| Código | Mensaje | Solución |
|--------|---------|----------|
| 400 | Field "fk_soc" required | Incluir campo obligatorio en request |
| 403 | Not allowed | Verificar permisos del usuario |
| 404 | Not found | Verificar que el ID existe |
| 500 | Internal server error | Revisar logs, campo inválido |

---

## 🗂️ **Estructura de Tablas**

### Tablas Principales
- `llx_societe` - Pacientes (client=1)
- `llx_societe_extrafields` - Campos extra de pacientes
- `llx_cabinetmed_patient` - Datos médicos de pacientes
- `llx_cabinetmed_extcons` - Consultas médicas
- `llx_cabinetmed_extcons_users` - Usuarios asignados a consultas
- `llx_cabinetmedfix_changelog` - Auditoría de cambios

### Tablas de Referencia (Catálogos)
- `llx_gestion_eps` - Entidades Prestadoras de Salud
- `llx_gestion_programa` - Programas médicos
- `llx_gestion_medicamento` - Medicamentos
- `llx_gestion_medicamento_det` - Concentraciones de medicamentos
- `llx_gestion_operador` - Operadores logísticos
- `llx_gestion_medico` - Médicos tratantes
- `llx_c_departements` - Departamentos (estados)
- `llx_c_country` - Países

---

## 📝 **Changelog de la API**

### Versión 2.0 - Abril 2026
- ✅ **NUEVO:** Campo `assigned_users` en respuestas
- ✅ **NUEVO:** Asignación automática del usuario API
- ✅ **NUEVO:** Soporte multi-usuario en consultas
- ✅ **MEJORA:** Resolución state/country a nombres
- ✅ **MEJORA:** Formateo automático de fechas
- ✅ **MEJORA:** Resolución de concentración a formato legible
- ✅ **FIX:** Auditoría funciona desde API

### Versión 1.0 - Inicial
- Endpoints básicos
- Tipos de atención
- Estados de consulta
- Custom data JSON
- Recurrencia

---

## 📞 **Soporte**

### Logs del Servidor
- Ver errores HTTP en la respuesta de la API
- Logs del servidor en `/var/log/dolibarr/`

### Reportar Problemas
Para dudas o problemas:
1. Revisar esta documentación
2. Ver scripts de prueba en la raíz del proyecto
3. Contactar al equipo de desarrollo

---

## 🌐 **Información del Proyecto**

**URL Producción:** https://crm.rcvco.org  
**API Endpoint:** https://crm.rcvco.org/api/index.php/rcvrest  
**Versión API:** 2.0  
**Dolibarr:** 19.x  
**Módulo:** rcvrest  
**Última actualización:** Abril 16, 2026

---

## 📚 **Documentación Completa**

Para información detallada sobre cada endpoint, consulta:

- **[→ README_CONSULTATIONS.md](README_CONSULTATIONS.md)** - Consultas médicas completo
- **[→ README_PATIENTS.md](README_PATIENTS.md)** - Pacientes completo

---

**¡Listo para usar!** 🚀
