# Módulo Gestión - Dolibarr

## Descripción
Módulo de Gestión para PSP (Programas de Soporte a Pacientes) en Dolibarr. Permite gestionar EPS, Médicos, Programas, Diagnósticos, Medicamentos y Operadores.

## Requisitos
- Dolibarr 16+ (con soporte Select2 nativo)
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+

## Instalación

### Nueva instalación
1. Copiar la carpeta `gestion` al directorio `htdocs/custom/` de Dolibarr
2. Activar el módulo desde **Configuración > Módulos**
3. Ejecutar el script SQL: `sql/llx_gestion.sql`

### Migración desde versión anterior
Si ya tiene la versión anterior del módulo instalada, ejecute el script de migración **antes** de reemplazar los archivos:

```sql
-- Ejecutar: sql/llx_gestion_migration.sql
```

Este script:
- Crea la tabla pivote `llx_gestion_medico_eps` para la relación muchos-a-muchos
- Migra los datos de EPS existentes a la nueva tabla pivote
- Convierte los campos `ciudad`, `departamento`, `especialidad` a formato JSON en `ciudades`, `departamentos`, `especialidades`
- Elimina las columnas obsoletas

## Cambios v2.0

### Médicos - Campos multi-valor
Los siguientes campos ahora soportan **múltiples valores** con listas desplegables (Select2):
- **Departamentos**: Lista desplegable con los 33 departamentos de Colombia
- **Ciudades**: Lista desplegable con las principales ciudades de Colombia (se pueden escribir ciudades adicionales gracias a `tags: true`)
- **EPS**: Lista desplegable multi-selección alimentada desde la tabla `llx_gestion_eps`
- **Especialidades**: Lista desplegable con 48 especialidades médicas colombianas

Los datos se almacenan:
- Ciudades, departamentos y especialidades: como **JSON arrays** en campos TEXT
- EPS: en la tabla pivote `llx_gestion_medico_eps` (relación muchos-a-muchos)

### Medicamentos - Concentraciones mejoradas
- **Unidad de medida**: Ahora es un **desplegable** con 24 opciones predefinidas (mg, g, mcg, ml, UI, mg/ml, etc.)
- **Edición inline**: Las concentraciones ahora se pueden **editar** directamente sin necesidad de eliminar y crear una nueva. Cada línea tiene un botón de editar (lápiz) que abre el formulario inline.

## Estructura de archivos

```
gestion/
├── class/
│   ├── gestioncommon.class.php          # Clase base
│   ├── medico.class.php                 # Médicos (multi-valor)
│   ├── eps.class.php                    # EPS
│   ├── medicamento.class.php            # Medicamentos
│   ├── medicamento_concentracion.class.php  # Concentraciones (con edit)
│   ├── diagnostico.class.php            # Diagnósticos
│   ├── programa.class.php               # Programas
│   └── operador.class.php               # Operadores
├── medicos/
│   ├── card.php                         # Ficha médico (multiselect)
│   └── list.php                         # Lista médicos
├── medicamentos/
│   ├── card.php                         # Ficha medicamento (edit concentraciones)
│   └── list.php                         # Lista medicamentos
├── sql/
│   ├── llx_gestion.sql                  # Schema completo
│   └── llx_gestion_migration.sql        # Script de migración
└── ...
```

## Copyright
(C) 2024 DatiLab - GPL v3
