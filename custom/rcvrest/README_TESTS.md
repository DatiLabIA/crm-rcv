# 🧪 Scripts de Prueba - Paciente 4024

Scripts para probar las correcciones del campo `estado_del_paciente` en la API REST.

## Archivos creados

1. **test_patient_4024.php** - Interfaz web interactiva
2. **test_crud_patient.php** - Script de prueba automatizado
3. **README_TESTS.md** - Este archivo

## ⚠️ IMPORTANTE

**ELIMINAR ESTOS ARCHIVOS después de las pruebas.** Contienen lógica de testing que no debe estar en producción.

---

## 1️⃣ Prueba Interactiva (Web)

### Acceso
```
https://crm.rcvco.org/custom/rcvrest/test_patient_4024.php
```

### Funcionalidades

✅ **Visualización de datos actuales**
- Muestra toda la información del paciente 4024
- Resalta el campo `estado_del_paciente` con su ID y label

✅ **Modificar solo el estado**
- Formulario para cambiar el estado del paciente
- Selector con todos los estados disponibles
- Muestra el resultado inmediatamente

✅ **Modificar múltiples campos**
- Formulario para cambiar EPS, Programa y Estado
- Actualización selectiva (solo los campos que elijas)

✅ **Simulación de respuesta API**
- Muestra cómo la API devuelve los datos en formato JSON
- Verifica que el label se resuelve correctamente

✅ **Ejemplos de llamadas cURL**
- Código listo para copiar y probar la API REST real

---

## 2️⃣ Prueba Automatizada (Script)

### Ejecución desde navegador
```
https://crm.rcvco.org/custom/rcvrest/test_crud_patient.php
```

### O desde línea de comandos (si tienes acceso SSH)
```bash
cd /ruta/al/dolibarr/custom/rcvrest/
php test_crud_patient.php
```

### Lo que hace el script

1. **TEST 1: READ**
   - Lee todos los datos del paciente 4024
   - Muestra extrafields con sus valores
   - Resalta el campo `estado_del_paciente`

2. **TEST 2: UPDATE**
   - Cambia el estado a un valor diferente
   - Verifica que el cambio se guardó correctamente

3. **TEST 3: RESTORE**
   - Restaura el estado original
   - Verifica que se restauró correctamente

4. **TEST 4: API SIMULATION**
   - Simula la respuesta de la API
   - Verifica que el label se resuelve correctamente
   - Muestra el JSON con el formato correcto

### Salida esperada
```
===========================================
  PRUEBA CRUD - PACIENTE 4024
===========================================

TEST 1: READ - Obtener datos del paciente
-------------------------------------------
✓ Paciente cargado correctamente
  - estado_del_paciente: 3 (Activo en Tratamiento) ← CAMPO CORREGIDO

TEST 2: UPDATE - Modificar estado del paciente
------------------------------------------------
Estado original: 3 (Activo en Tratamiento)
Nuevo estado: 5 (Activo Por El Programa)
✓ Estado actualizado correctamente
✓ Verificación exitosa

TEST 3: UPDATE - Restaurar estado original
-------------------------------------------
✓ Estado restaurado correctamente

TEST 4: SIMULACIÓN API - Verificar resolución de labels
--------------------------------------------------------
{
    "estado_del_paciente": "Activo en Tratamiento",
    "estado_del_paciente_id": 3
}

✓ La API está devolviendo correctamente:
  - estado_del_paciente: "Activo en Tratamiento" (label)
  - estado_del_paciente_id: 3 (ID)

===========================================
  RESUMEN DE PRUEBAS
===========================================
✓ Todas las pruebas completadas exitosamente
```

---

## 3️⃣ Pruebas con la API REST Real

### Prerequisitos
Necesitas una **API Key** de Dolibarr. Para obtenerla:

1. Ir a **Home → Setup → Modules/Applications**
2. Buscar "API/Web services"
3. Activar el módulo si no está activo
4. Ir a **Home → Users & Groups → Users**
5. Editar tu usuario
6. En la pestaña "API Keys", generar una nueva clave

### GET - Obtener datos del paciente

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" \
  -H "DOLAPIKEY: tu_api_key_aqui"
```

**Respuesta esperada:**
```json
{
  "id": 4024,
  "nom": "Apellidos del Paciente",
  "name_alias": "Nombres del Paciente",
  "extrafields": {
    "n_documento": "1234567890",
    "eps": "EPS Sanitas",
    "eps_id": 5,
    "programa": "Programa X",
    "programa_id": 2,
    "estado_del_paciente": "Activo en Tratamiento",
    "estado_del_paciente_id": 3
  }
}
```

### PUT - Actualizar estado del paciente

```bash
curl -X PUT "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" \
  -H "DOLAPIKEY: tu_api_key_aqui" \
  -H "Content-Type: application/json" \
  -d '{
    "extrafields": {
      "estado_del_paciente": 5
    }
  }'
```

### GET - Verificar el cambio

Volver a hacer el GET para verificar que el estado cambió:

```bash
curl -X GET "https://crm.rcvco.org/api/index.php/rcvrest/patients/4024" \
  -H "DOLAPIKEY: tu_api_key_aqui"
```

Ahora debería mostrar:
```json
{
  "extrafields": {
    "estado_del_paciente": "Activo Por El Programa",
    "estado_del_paciente_id": 5
  }
}
```

---

## Estados Disponibles

| ID | Label |
|----|-------|
| 1  | En Tránsito |
| 2  | En Proceso |
| 3  | Activo en Tratamiento |
| 4  | Activo Independiente |
| 5  | Activo Por El Programa |
| 6  | Reactivado |
| 7  | Suspendido |
| 8  | No trazable |
| 9  | NAP |
| 10 | Inactivo |

---

## 📝 Qué se corrigió

### Antes (❌)
```json
{
  "extrafields": {
    "estado_del_paciente": 3
  }
}
```
Solo devolvía el ID, sin forma de saber qué significa.

### Después (✅)
```json
{
  "extrafields": {
    "estado_del_paciente": "Activo en Tratamiento",
    "estado_del_paciente_id": 3
  }
}
```
Ahora devuelve el label legible y también el ID por si se necesita.

### Archivos modificados

- `custom/rcvrest/class/api_rcvrest.class.php`
  - Nuevo método: `_resolveSelectStaticLabels()`
  - Modificado: `_resolveExtrafieldsLabels()` - ahora llama al nuevo método
  - Modificado: `listPatients()` - ahora resuelve labels correctamente

---

## 🗑️ Limpieza

Una vez verificado que todo funciona, **ELIMINAR**:

```bash
custom/rcvrest/test_patient_4024.php
custom/rcvrest/test_crud_patient.php
custom/rcvrest/README_TESTS.md
```

O desde el servidor web, simplemente borrar estos 3 archivos del directorio `custom/rcvrest/`.

---

## ✅ Checklist de Pruebas

- [ ] Acceder a test_patient_4024.php y verificar que muestra datos correctamente
- [ ] Ver que el estado muestra "texto (ID)" en lugar de solo número
- [ ] Cambiar el estado usando el formulario
- [ ] Verificar que el cambio se guardó
- [ ] Ver la "Respuesta de la API simulada" en formato JSON
- [ ] Ejecutar test_crud_patient.php y verificar que todos los tests pasan
- [ ] Probar la API REST real con cURL (GET)
- [ ] Verificar que la respuesta JSON tiene el label correcto
- [ ] Probar actualización vía API REST (PUT)
- [ ] Verificar que el cambio se guardó (GET de nuevo)
- [ ] Eliminar los archivos de prueba

---

**Fecha de creación:** 16 de abril de 2026
**Paciente de prueba:** 4024
**Campo corregido:** `estado_del_paciente`
