# Setup del Servicio AI

## Instalación Local (para desarrollo y autocompletado en el editor)

### Prerrequisitos
- Python 3.11
- Poetry (instalar con: `curl -sSL https://install.python-poetry.org | python3 -`)

### Pasos para setup local

1. **Instalar Poetry** (si no lo tienes):
```bash
curl -sSL https://install.python-poetry.org | python3 -
```

2. **Navegar al directorio del servicio**:
```bash
cd ai-service
```

3. **Instalar dependencias con Poetry**:
```bash
poetry install
```

Esto creará un entorno virtual y instalará todas las dependencias (incluyendo dev dependencies).

4. **Activar el entorno virtual**:
```bash
poetry shell
```

O ejecutar comandos con:
```bash
poetry run <comando>
```

5. **Configurar tu IDE/Editor**:
   - **VS Code**: Selecciona el intérprete de Python del entorno virtual de Poetry
     - `Cmd+Shift+P` (Mac) o `Ctrl+Shift+P` (Windows/Linux)
     - Busca "Python: Select Interpreter"
     - Selecciona el intérprete en `.venv/bin/python` (si Poetry creó el venv en el proyecto)
     - O el intérprete que Poetry muestra con `poetry env info --path`
   
   - **PyCharm**: 
     - Settings → Project → Python Interpreter
     - Add Interpreter → Poetry Environment
     - Selecciona el proyecto

6. **Verificar que funciona**:
```bash
poetry run python -c "from app.config import settings; print(settings.APP_NAME)"
```

### Comandos útiles de Poetry

```bash
# Instalar dependencias
poetry install

# Agregar una nueva dependencia
poetry add <paquete>

# Agregar una dependencia de desarrollo
poetry add --group dev <paquete>

# Actualizar dependencias
poetry update

# Ver información del entorno
poetry env info

# Activar el shell del entorno virtual
poetry shell

# Ejecutar un comando en el entorno virtual
poetry run <comando>

# Generar/actualizar poetry.lock
poetry lock
```

## Desarrollo con Docker

### Construir la imagen
```bash
docker build -t ai-service ./ai-service
```

### Ejecutar el contenedor
```bash
docker run -p 8000:8000 ai-service
```

### Con Docker Compose
```bash
docker-compose up ai-service
```

## Solución de problemas

### El editor no encuentra las importaciones

1. **Asegúrate de que Poetry instaló las dependencias**:
```bash
poetry install
```

2. **Verifica que el intérprete correcto esté seleccionado en tu IDE**

3. **Si usas VS Code, reinicia el servidor de Python**:
   - `Cmd+Shift+P` → "Python: Restart Language Server"

4. **Verifica que el entorno virtual existe**:
```bash
poetry env info
```

### Error al instalar dependencias

Si hay conflictos de dependencias:
```bash
poetry lock --no-update  # Solo actualiza el lock sin actualizar dependencias
poetry install
```

### Limpiar y reinstalar

```bash
poetry env remove python  # Elimina el entorno virtual
poetry install  # Reinstala todo
```

