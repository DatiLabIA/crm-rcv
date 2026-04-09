# WhatsApp Business para Dolibarr

Módulo profesional de integración de WhatsApp Business para Dolibarr ERP/CRM. Conecta tu cuenta de Meta WhatsApp Business Cloud API con Dolibarr para gestionar todas las comunicaciones con clientes desde una única plataforma.

## Características

### Conversaciones en tiempo real
- Chat con interfaz de burbujas conversacionales al estilo WhatsApp
- Actualización en tiempo real vía Server-Sent Events (SSE) con fallback a polling
- Indicadores de estado de mensajes (enviado, entregado, leído)
- Búsqueda y filtrado de conversaciones
- Recepción automática de mensajes entrantes vía webhook

### Plantillas de WhatsApp
- Sincronización automática de plantillas desde Meta Business
- Vista previa en tiempo real con variables dinámicas
- Soporte para plantillas con parámetros, cabeceras, cuerpo y botones
- Uso obligatorio para iniciar conversaciones o cuando la ventana de 24 horas ha expirado

### Envío masivo (Bulk Send)
- Selección de destinatarios desde terceros de Dolibarr con filtros avanzados
- Envío por lotes con cola de procesamiento en segundo plano
- Barra de progreso y estadísticas de envío en tiempo real
- Límite configurable de velocidad para respetar los rate-limits de Meta

### Chatbot / Respuestas automáticas
- Creación de reglas basadas en palabras clave o patrones (texto exacto, contiene, regex)
- Respuestas automáticas con texto libre o plantillas
- Priorización de reglas y activación/desactivación individual
- Procesamiento automático de mensajes entrantes antes de la intervención humana

### Mensajes programados
- Programación de mensajes únicos o recurrentes (diario, semanal, mensual)
- Selección de plantilla y destinatario con fecha/hora específica
- Panel de gestión con estados (pendiente, procesado, fallido)
- Procesamiento automático mediante cron job de Dolibarr

### Etiquetas y organización
- Sistema de etiquetas (tags) con colores para clasificar conversaciones
- Asignación múltiple de etiquetas por conversación
- Filtrado rápido de conversaciones por etiqueta

### Respuestas rápidas
- Biblioteca de respuestas predefinidas con atajos de teclado
- Inserción rápida desde el chat usando el carácter `/`
- Gestión completa (crear, editar, eliminar)

### Multimedia
- Envío y recepción de imágenes, vídeos, audio y documentos
- Subida de archivos desde el cliente con vista previa
- Descarga y proxy de medios desde Meta API

### Integración CRM
- Vinculación de conversaciones con terceros (clientes/proveedores) de Dolibarr
- Creación automática de leads/oportunidades desde conversaciones
- Botón de WhatsApp en fichas de terceros y contactos (hooks en thirdpartycard y contactcard)
- Registro de eventos y trazabilidad completa

### Asignación de agentes
- Asignación manual de conversaciones a usuarios de Dolibarr
- Visualización del agente asignado en la lista de conversaciones

### Multi-entidad
- Soporte completo para entornos Dolibarr multi-entidad
- Aislamiento de datos por entidad en todas las tablas y consultas

### Idiomas
- Español (es_ES)
- Inglés (en_US)

## Requisitos

| Componente | Versión mínima |
|---|---|
| Dolibarr | 20.0 o superior |
| PHP | 8.1 o superior |
| MySQL / MariaDB | 8.0+ / 10.6+ |
| Extensión PHP cURL | Habilitada |
| SSL | Certificado válido (requerido para webhooks) |

> **Nota:** PostgreSQL no está soportado. El módulo utiliza sintaxis específica de MySQL/MariaDB.

Además necesitas:

- **Cuenta de Meta WhatsApp Business** con acceso a la Cloud API
- **System User Token** permanente generado desde Meta Business Suite
- **Número de teléfono** registrado y verificado en WhatsApp Business

## Instalación

### 1. Copiar el módulo

Copiar la carpeta del módulo en el directorio `custom` de Dolibarr:

```bash
cp -r whatsappdati /ruta/a/dolibarr/htdocs/custom/whatsappdati
```

O si usas git:

```bash
cd /ruta/a/dolibarr/htdocs/custom
git clone <repositorio> whatsappdati
```

### 2. Activar el módulo

1. Ir a **Inicio → Configuración → Módulos/Aplicaciones**
2. Buscar "WhatsApp Business"
3. Hacer clic en **Activar**

Las tablas de base de datos se crean automáticamente al activar el módulo.

### 3. Configurar credenciales de Meta

#### Obtener credenciales

1. Acceder a [Meta for Developers](https://developers.facebook.com/)
2. Crear o seleccionar una aplicación de tipo Business
3. Agregar el producto "WhatsApp" a la aplicación
4. Obtener las siguientes credenciales:
   - **Phone Number ID** — ID del número de teléfono de WhatsApp
   - **WhatsApp Business Account ID** — ID de la cuenta de negocio
   - **Access Token** — Token permanente (crear un System User en Business Settings)
   - **App Secret** — Secreto de la aplicación (para verificación de firma del webhook)

#### Ingresar en Dolibarr

1. Ir a **WhatsApp → Configuración**
2. Completar los campos con las credenciales obtenidas
3. Definir un **Webhook Verify Token** (cadena secreta de tu elección)
4. Guardar y hacer clic en **Probar Conexión**

### 4. Configurar Webhook en Meta

1. En la consola de Meta Developers, ir a la configuración de WhatsApp
2. En la sección **Webhooks**, hacer clic en **Editar**
3. Ingresar la URL:
   ```
   https://tu-dominio.com/custom/whatsappdati/webhook.php
   ```
4. Ingresar el **Verify Token** configurado en el paso anterior
5. Suscribirse a los campos:
   - `messages`
   - `message_status` (opcionalmente `message_template_status_update`)
6. Hacer clic en **Verificar y guardar**

### 5. Configurar tareas programadas (Cron)

El módulo registra automáticamente dos tareas cron al activarse:

| Tarea | Frecuencia | Descripción |
|---|---|---|
| **ProcessWhatsAppQueue** | Cada 5 minutos | Procesa la cola de envío masivo |
| **ProcessWhatsAppScheduled** | Cada 2 minutos | Ejecuta mensajes programados pendientes |

Verificar que el cron de Dolibarr esté activo. Consultar la documentación oficial:
[Configuración de cron en Dolibarr](https://wiki.dolibarr.org/index.php/Cron_setup)

## Uso

### Conversaciones

Acceder desde **WhatsApp → Conversaciones** en el menú principal. Desde aquí se pueden:

- Ver todas las conversaciones activas con último mensaje y hora
- Filtrar por etiqueta, agente asignado o texto
- Abrir una conversación para chatear en tiempo real
- Enviar mensajes de texto libre (dentro de la ventana de 24h) o plantillas
- Adjuntar archivos multimedia
- Asignar un agente o vincular con un tercero de Dolibarr

### Plantillas

Acceder desde **WhatsApp → Plantillas**:

- Sincronizar plantillas desde Meta con un clic
- Ver estado de aprobación de cada plantilla
- Previsualizar plantillas con variables de ejemplo

### Envío masivo

Acceder desde **WhatsApp → Envío Masivo**:

1. Seleccionar una plantilla aprobada
2. Filtrar y seleccionar destinatarios
3. Completar las variables de la plantilla
4. Iniciar el envío — los mensajes se encolan y procesan en segundo plano

### Chatbot

Acceder desde **WhatsApp → Chatbot**:

1. Crear reglas con condiciones (palabra clave, contiene, regex)
2. Definir la respuesta automática (texto o plantilla)
3. Ordenar por prioridad
4. Activar/desactivar reglas individualmente

### Mensajes programados

Acceder desde **WhatsApp → Programación**:

1. Seleccionar destinatario y plantilla
2. Definir fecha, hora y recurrencia (opcional)
3. El cron de Dolibarr ejecuta los envíos automáticamente

### Integración con terceros

En la ficha de cualquier **Tercero** o **Contacto** de Dolibarr aparecerá un botón para iniciar una conversación de WhatsApp directamente, siempre que el contacto tenga un número de teléfono móvil registrado.

## Permisos

El módulo define 4 permisos granulares:

| Permiso | Descripción |
|---|---|
| `conversation → read` | Ver conversaciones y mensajes |
| `message → send` | Enviar mensajes, envío masivo y programación |
| `template → write` | Gestionar y sincronizar plantillas |
| `config → write` | Acceder a la configuración del módulo y chatbot |

Asignar estos permisos desde **Configuración → Usuarios y Grupos → Permisos**.

## Seguridad

- **Tokens encriptados**: Las credenciales de API se almacenan cifradas en base de datos
- **Verificación de firma**: El webhook valida la firma HMAC-SHA256 de cada petición de Meta usando el App Secret
- **Protección CSRF**: Todos los formularios y endpoints AJAX incluyen token anti-CSRF de Dolibarr
- **Sanitización de entrada**: Todos los parámetros se validan y escapan para prevenir XSS e inyección SQL
- **Permisos por endpoint**: Cada acción verifica los permisos del usuario antes de ejecutarse
- **HTTPS obligatorio**: Todas las comunicaciones con Meta API usan TLS

## Solución de problemas

### No se reciben mensajes

1. Verificar que el webhook esté correctamente configurado y verificado en Meta
2. Revisar que el servidor tenga un certificado SSL válido
3. Comprobar que el App Secret esté configurado correctamente
4. Revisar los logs de Dolibarr: **Inicio → Configuración → Logs**

### Error al enviar mensajes

1. Verificar que las credenciales (Phone Number ID, Access Token) sean correctas
2. Comprobar que la plantilla esté aprobada por Meta
3. Verificar que el número de teléfono esté en formato internacional sin signos (ej: `573001234567`)
4. Revisar la respuesta de error en los logs

### Ventana de 24 horas expirada

WhatsApp Business requiere usar una plantilla aprobada para contactar a un cliente cuando han pasado más de 24 horas desde su último mensaje. El módulo detecta esta situación automáticamente y permite seleccionar una plantilla.

### El cron no procesa mensajes

1. Verificar que el cron de Dolibarr esté configurado y ejecutándose
2. Ir a **Inicio → Configuración → Tareas programadas** y comprobar que las tareas del módulo estén activas
3. Ejecutar manualmente la tarea para verificar si hay errores

## Documentación adicional

- [Meta WhatsApp Business Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api)
- [Desarrollo de módulos Dolibarr](https://wiki.dolibarr.org/index.php/Module_development)
- [Configuración de cron en Dolibarr](https://wiki.dolibarr.org/index.php/Cron_setup)

## Licencia

Este módulo se distribuye bajo la licencia **GNU General Public License v3.0 o posterior (GPLv3+)**.

Consulta el archivo [LICENSE](LICENSE) para más detalles.

## Soporte

- **Email**: soporte@datilab.com
- **Web**: [https://datilab.com](https://datilab.com)

---

Desarrollado por **DatiLab** — Soluciones tecnológicas
