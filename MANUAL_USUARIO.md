# Manual de Usuario - SAM (Samsara Alert Monitor)

## Tabla de Contenidos

1. [Introducción y Bienvenida](#1-introducción-y-bienvenida)
2. [Primeros Pasos](#2-primeros-pasos)
3. [Navegación del Sistema](#3-navegación-del-sistema)
4. [Dashboard Principal](#4-dashboard-principal)
5. [Gestión de Alertas](#5-gestión-de-alertas)
6. [Gestión de Incidentes](#6-gestión-de-incidentes)
7. [Copilot (Asistente de Flota)](#7-copilot-asistente-de-flota)
8. [Reportes de Flota](#8-reportes-de-flota)
9. [Gestión de Conductores](#9-gestión-de-conductores)
10. [Gestión de Contactos](#10-gestión-de-contactos)
11. [Configuración Personal](#11-configuración-personal)
12. [Configuración de Empresa](#12-configuración-de-empresa-solo-administradores)
13. [Gestión de Usuarios](#13-gestión-de-usuarios-solo-administradores-y-gerentes)
14. [Preguntas Frecuentes](#14-preguntas-frecuentes-faq)
15. [Glosario](#15-glosario)

---

## 1. Introducción y Bienvenida

### ¿Qué es SAM?

SAM (Samsara Alert Monitor) es un sistema inteligente de monitoreo y gestión de alertas para flotas de vehículos. El sistema utiliza inteligencia artificial para analizar automáticamente las alertas que recibes de Samsara, ayudándote a identificar cuáles son realmente importantes y requieren tu atención inmediata.

[FOTO: Pantalla de bienvenida o logo del sistema]

### ¿Para qué sirve?

- **Monitoreo automático**: El sistema analiza todas las alertas de tus vehículos automáticamente
- **Detección inteligente**: La inteligencia artificial identifica alertas críticas y reduce falsas alarmas
- **Notificaciones**: Recibe alertas importantes por SMS, WhatsApp o llamadas telefónicas
- **Consulta rápida**: Pregunta al Copilot sobre el estado de tu flota en lenguaje natural
- **Visualización clara**: Ve todas tus alertas organizadas en un tablero visual (Kanban)

### Requisitos Básicos

Para usar SAM necesitas:

- Un navegador web moderno (Chrome, Firefox, Safari o Edge)
- Conexión a internet
- Una cuenta de usuario proporcionada por tu administrador
- Acceso a tu correo electrónico para verificación

---

## 2. Primeros Pasos

### Acceder al Sistema

Para acceder a SAM, abre tu navegador y visita la dirección web que te proporcionó tu administrador. Serás redirigido automáticamente a la pantalla de inicio de sesión.

[FOTO: Pantalla de login]

### Iniciar Sesión

1. Ingresa tu **correo electrónico** en el campo correspondiente
2. Ingresa tu **contraseña**
3. Haz clic en el botón **"Iniciar sesión"**

Si es la primera vez que inicias sesión, es posible que necesites verificar tu correo electrónico antes de poder acceder completamente al sistema.

### Recuperar Contraseña

Si olvidaste tu contraseña:

1. En la pantalla de login, haz clic en **"¿Olvidaste tu contraseña?"**
2. Ingresa tu correo electrónico
3. Revisa tu bandeja de entrada (y la carpeta de spam) para encontrar el enlace de recuperación
4. Haz clic en el enlace y sigue las instrucciones para crear una nueva contraseña

### Verificación de Email

La primera vez que inicias sesión, el sistema puede pedirte que verifiques tu correo electrónico:

1. Revisa tu bandeja de entrada
2. Busca un correo de SAM con el asunto "Verifica tu dirección de correo"
3. Haz clic en el botón de verificación dentro del correo
4. Serás redirigido de vuelta al sistema

### Autenticación de Dos Factores (2FA)

Para mayor seguridad, puedes activar la autenticación de dos factores. Esto significa que además de tu contraseña, necesitarás un código de verificación que se genera en tu teléfono.

**Para activar 2FA:**

1. Ve a **Configuración** → **Autenticación de Dos Factores**
2. Escanea el código QR con una aplicación de autenticación (como Google Authenticator o Authy)
3. Ingresa el código de verificación que aparece en tu aplicación
4. Guarda los códigos de respaldo en un lugar seguro

**Consejo**: Los códigos de respaldo te permitirán acceder a tu cuenta si pierdes acceso a tu teléfono.

### Tu Primera Vez en el Dashboard

Después de iniciar sesión, verás el **Dashboard Principal**, que es tu punto de partida en el sistema. Aquí encontrarás un resumen de toda la información importante de tu flota.

[FOTO: Dashboard inicial después del login]

---

## 3. Navegación del Sistema

### Menú Lateral (Sidebar)

El menú lateral está siempre visible en el lado izquierdo de la pantalla. Desde aquí puedes acceder a todas las secciones principales del sistema:

**General:**
- **Dashboard**: Tu página principal con resumen de información

**Centro de Control:**
- **Alertas**: Todas las alertas de tus vehículos
- **Incidentes**: Alertas agrupadas por incidentes relacionados
- **Copilot**: Tu asistente inteligente para consultas sobre la flota

**Flota:**
- **Vehículos**: Reportes y estado de tus vehículos
- **Conductores**: Información de conductores
- **Contactos**: Personas que reciben notificaciones

**Administración** (solo para administradores y gerentes):
- **Usuarios**: Gestión de usuarios del sistema
- **Empresa**: Configuración de la empresa

[FOTO: Vista completa del sistema con menú desplegado]

### Header Superior

En la parte superior de la pantalla encontrarás:

- **Logo del sistema**: Haz clic para volver al Dashboard
- **Breadcrumbs** (migas de pan): Muestran dónde estás en el sistema (ej: Dashboard > Alertas > Detalle)
- **Búsqueda global**: Busca rápidamente en todo el sistema
- **Acceso rápido al Copilot**: Botón con icono de estrella para abrir el Copilot
- **Menú de usuario**: Tu foto o iniciales, desde donde puedes acceder a tu perfil y configuración

### Búsqueda Global

La barra de búsqueda en el header te permite buscar rápidamente:
- Alertas
- Vehículos
- Conductores
- Contactos

Simplemente escribe lo que buscas y presiona Enter.

### Acceso Rápido al Copilot

El botón del Copilot (icono de estrella) en el header te permite abrir rápidamente el asistente de flota desde cualquier página del sistema.

---

## 4. Dashboard Principal

El Dashboard es tu centro de control. Aquí verás un resumen de toda la información importante de tu flota en tiempo real.

[FOTO: Dashboard completo]

### Tarjetas de Estadísticas

En la parte superior del Dashboard encontrarás varias tarjetas con números importantes:

**Estadísticas de Alertas:**
- **Total**: Número total de alertas registradas
- **Hoy**: Alertas recibidas hoy
- **Esta Semana**: Alertas de los últimos 7 días
- **Críticas**: Alertas que requieren atención inmediata
- **Pendientes**: Alertas esperando procesamiento
- **En Procesamiento**: Alertas siendo analizadas por la IA
- **En Investigación**: Alertas que requieren monitoreo continuo
- **Completadas**: Alertas ya procesadas
- **Requieren Atención**: Alertas que necesitan revisión humana

**Estadísticas de Vehículos:**
- **Total de Vehículos**: Cantidad de vehículos en tu flota

**Estadísticas de Contactos:**
- **Total**: Número de contactos configurados
- **Activos**: Contactos que pueden recibir notificaciones
- **Por Defecto**: Contactos marcados como predeterminados

**Estadísticas de Usuarios:**
- **Total**: Usuarios del sistema
- **Activos**: Usuarios con acceso activo
- **Administradores**: Número de administradores

**Estadísticas de Conversaciones:**
- **Total**: Conversaciones con el Copilot
- **Hoy**: Conversaciones iniciadas hoy
- **Esta Semana**: Conversaciones de los últimos 7 días

[FOTO: Detalle de una tarjeta de estadísticas]

### Gráficos y Tendencias

El Dashboard incluye gráficos que te ayudan a visualizar:

- **Alertas por Severidad**: Cuántas alertas hay de cada tipo (Crítica, Advertencia, Informativa)
- **Alertas por Estado**: Distribución de alertas según su estado de procesamiento
- **Alertas por Día**: Tendencia de alertas a lo largo del tiempo
- **Alertas por Tipo**: Qué tipos de alertas son más comunes

### Eventos Recientes

En esta sección verás las alertas más recientes de tu flota, mostrando:
- Tipo de alerta
- Vehículo involucrado
- Conductor
- Fecha y hora
- Severidad
- Estado

Haz clic en cualquier alerta para ver más detalles.

### Eventos Críticos

Aquí se muestran las alertas que requieren atención inmediata. Estas son las alertas más importantes que debes revisar primero.

### Conversaciones Recientes del Copilot

Si has usado el Copilot recientemente, verás un resumen de tus últimas conversaciones aquí.

---

## 5. Gestión de Alertas

Las alertas son notificaciones que recibes cuando algo sucede con tus vehículos (por ejemplo, un botón de pánico presionado, un evento de seguridad, etc.). SAM procesa estas alertas automáticamente usando inteligencia artificial.

### Vista Kanban de Alertas

La vista Kanban organiza tus alertas en columnas, similar a un tablero de tareas. Esto te permite ver fácilmente en qué etapa está cada alerta.

[FOTO: Vista Kanban de alertas]

**Las columnas son:**

1. **Pendientes**: Alertas nuevas que aún no han sido procesadas
2. **En Procesamiento**: Alertas siendo analizadas por la inteligencia artificial
3. **En Investigación**: Alertas que requieren monitoreo continuo (la IA las revisará periódicamente)
4. **Completadas**: Alertas que ya fueron procesadas y evaluadas

Puedes arrastrar alertas entre columnas para cambiar manualmente su estado, o simplemente hacer clic en una alerta para ver más detalles.

### Filtros de Alertas

En la parte superior de la página de alertas encontrarás varios filtros que te permiten encontrar exactamente lo que buscas:

[FOTO: Filtros de alertas]

**Filtros disponibles:**

- **Tipo de Alerta**: Filtra por el tipo de evento (Botón de Pánico, Evento de Seguridad, etc.)
- **Severidad**: Crítica, Advertencia, Informativa
- **Estado**: Pendiente, En Procesamiento, En Investigación, Completada, Fallida
- **Vehículo**: Busca alertas de un vehículo específico
- **Conductor**: Filtra por conductor
- **Fecha**: Selecciona un rango de fechas
- **Búsqueda de texto**: Busca palabras clave en las descripciones de alertas

**Consejo**: Puedes combinar múltiples filtros para hacer búsquedas muy específicas.

### Búsqueda de Alertas

Además de los filtros, puedes usar la barra de búsqueda para buscar alertas por:
- Nombre del vehículo
- Nombre del conductor
- Descripción del evento
- ID de la alerta

### Vista Rápida de Alerta (Modal)

Cuando haces clic en una alerta en el tablero Kanban, se abre una ventana rápida (modal) que muestra:

- Información básica de la alerta
- Veredicto de la IA
- Mensaje generado por la IA
- Acciones rápidas (marcar como revisada, ver detalles completos)

Esta vista rápida te permite revisar alertas sin salir del tablero principal.

### Vista Detallada de Alerta

Para ver toda la información de una alerta, haz clic en **"Ver Detalles"** o en el título de la alerta. La vista detallada incluye:

[FOTO: Vista detallada de una alerta]

**Información General:**
- Tipo y descripción del evento
- Vehículo y conductor involucrados
- Fecha y hora exacta del evento
- Severidad
- Estado actual

**Análisis de la Inteligencia Artificial:**
- **Veredicto**: La conclusión de la IA (por ejemplo: "Alerta válida", "Falsa alarma", "Requiere monitoreo")
- **Probabilidad**: Qué tan segura está la IA de su evaluación
- **Razonamiento**: Explicación detallada de por qué la IA llegó a esa conclusión
- **Mensaje para Operadores**: Mensaje en español fácil de entender que resume la situación

**Imágenes de Dashcam:**
Si el vehículo tiene cámaras, verás las imágenes capturadas en el momento del evento. Esto te ayuda a entender mejor qué sucedió.

**Timeline de Procesamiento:**
Verás un historial paso a paso de cómo la IA procesó la alerta:
1. Clasificación inicial (Triage)
2. Investigación y análisis
3. Generación del mensaje final
4. Decisión de notificaciones

**Notificaciones Enviadas:**
Información sobre qué notificaciones se enviaron (SMS, WhatsApp, llamadas) y a quién.

### Revisión Humana de Alertas

Aunque la IA procesa las alertas automáticamente, tú puedes revisarlas y tomar decisiones adicionales.

[FOTO: Panel de revisión humana]

**Estados que puedes asignar:**

- **Pendiente**: La alerta aún no ha sido revisada
- **Revisada**: Ya la revisaste y estás de acuerdo con la evaluación
- **Marcada**: La alerta es importante y quieres destacarla
- **Resuelta**: El problema ya fue atendido
- **Falso Positivo**: La alerta no era realmente importante

**Panel de Revisión:**

En el panel lateral derecho de la vista detallada puedes:
- Cambiar el estado de la alerta
- Agregar comentarios
- Ver el historial de actividades
- Ver quién más ha revisado la alerta

**Consejo**: Marca las alertas como "Falso Positivo" cuando la IA se equivoca. Esto ayuda a mejorar el sistema.

---

## 6. Gestión de Incidentes

Los incidentes son grupos de alertas relacionadas. Por ejemplo, si un vehículo tiene múltiples eventos de seguridad en un corto período, el sistema puede agruparlos en un solo incidente para que sea más fácil de gestionar.

### ¿Qué son los Incidentes?

Un incidente es una colección de alertas que están relacionadas entre sí. Esto te permite ver el panorama completo de una situación en lugar de revisar cada alerta individualmente.

### Lista de Incidentes

En la página de Incidentes verás una lista de todos los incidentes activos y resueltos.

[FOTO: Lista de incidentes]

Cada incidente muestra:
- **Título**: Descripción del incidente
- **Vehículo**: Vehículo involucrado
- **Conductor**: Conductor asignado
- **Estado**: Activo, En Investigación, Resuelto
- **Número de Alertas**: Cuántas alertas están agrupadas en este incidente
- **Fecha**: Cuándo comenzó el incidente

### Vista Detallada de Incidente

Haz clic en un incidente para ver todos los detalles:

[FOTO: Vista detallada de incidente]

**Información del Incidente:**
- Resumen del incidente
- Todas las alertas relacionadas
- Timeline de eventos
- Estado actual

**Alertas Relacionadas:**
Verás todas las alertas que forman parte de este incidente, organizadas cronológicamente. Puedes hacer clic en cualquier alerta para ver sus detalles completos.

### Cambiar Estado de Incidente

Puedes cambiar el estado de un incidente para reflejar su progreso:

1. Ve a la vista detallada del incidente
2. En la parte superior, verás el estado actual
3. Haz clic en el botón de cambio de estado
4. Selecciona el nuevo estado:
   - **Activo**: El incidente está en curso
   - **En Investigación**: Estás investigando el incidente
   - **Resuelto**: El incidente ya fue atendido

**Consejo**: Mantén los estados actualizados para que tu equipo sepa qué incidentes requieren atención.

---

## 7. Copilot (Asistente de Flota)

El Copilot es tu asistente inteligente que puede responder preguntas sobre tu flota en lenguaje natural. Es como tener un experto en flotas disponible 24/7.

### ¿Qué es el Copilot?

El Copilot es un asistente de inteligencia artificial que entiende preguntas en español y puede consultar información de tu flota en tiempo real. Puedes preguntarle cosas como:

- "¿Dónde está el vehículo T-012021?"
- "Muéstrame las estadísticas del vehículo ABC-123"
- "¿Cuál es el estado de toda mi flota?"
- "¿Qué eventos de seguridad tuvo el conductor Juan Pérez esta semana?"

### Cómo Iniciar una Conversación

1. Haz clic en **"Copilot"** en el menú lateral o en el botón de estrella del header
2. Verás la interfaz de chat
3. Escribe tu pregunta en el cuadro de texto en la parte inferior
4. Presiona Enter o haz clic en el botón de enviar

[FOTO: Interfaz del Copilot]

### Tipos de Consultas que Puedes Hacer

El Copilot puede responder muchos tipos de preguntas:

**Ubicación:**
- "¿Dónde está el vehículo [nombre]?"
- "Muéstrame la ubicación de todos los vehículos activos"

**Estadísticas:**
- "¿Cuál es la velocidad actual del vehículo [nombre]?"
- "¿Está encendido el motor del vehículo [nombre]?"
- "Muéstrame el odómetro del vehículo [nombre]"

**Estado de la Flota:**
- "¿Cuál es el estado general de mi flota?"
- "¿Cuántos vehículos están en movimiento?"
- "¿Cuántos vehículos tienen el motor encendido?"

**Eventos de Seguridad:**
- "¿Qué eventos de seguridad tuvo el vehículo [nombre] hoy?"
- "Muéstrame los eventos de seguridad de la última semana"

**Viajes:**
- "¿Qué viajes hizo el vehículo [nombre] hoy?"
- "Muéstrame los viajes recientes"

**Imágenes de Dashcam:**
- "Muéstrame las imágenes de dashcam del vehículo [nombre] de hoy"

### Rich Cards (Tarjetas Visuales)

Cuando el Copilot encuentra información visual, la muestra en tarjetas especiales llamadas "Rich Cards". Estas tarjetas hacen que la información sea más fácil de entender.

[FOTO: Ejemplo de consulta y respuesta con Rich Cards]

**Tipos de Rich Cards:**

1. **Tarjeta de Ubicación**: Muestra un mapa con la ubicación del vehículo
   - Coordenadas GPS
   - Enlace a Google Maps
   - Dirección aproximada

2. **Tarjeta de Estadísticas de Vehículo**: Muestra información en tiempo real
   - Velocidad actual
   - Estado del motor (Encendido/Apagado)
   - Estado de movimiento
   - Odómetro
   - Última actualización

3. **Tarjeta de Estado de Flota**: Resumen general
   - Total de vehículos
   - Vehículos activos
   - Vehículos en movimiento
   - Vehículos con motor encendido

4. **Tarjeta de Eventos de Seguridad**: Lista de eventos
   - Tipo de evento
   - Fecha y hora
   - Severidad
   - Descripción

5. **Tarjeta de Viajes**: Información de viajes recientes
   - Origen y destino
   - Distancia
   - Duración
   - Fecha

6. **Tarjeta de Imágenes de Dashcam**: Galería de imágenes
   - Imágenes capturadas por las cámaras
   - Fecha y hora de captura
   - Cámara que capturó la imagen

### Historial de Conversaciones

Todas tus conversaciones con el Copilot se guardan automáticamente. Puedes:

- Ver conversaciones anteriores en el panel lateral izquierdo
- Continuar conversaciones anteriores
- Eliminar conversaciones que ya no necesites

[FOTO: Historial de conversaciones]

**Para ver una conversación anterior:**
1. En el panel lateral izquierdo, busca la conversación
2. Haz clic en ella
3. Verás todo el historial de mensajes

**Para eliminar una conversación:**
1. Haz clic en el menú (tres puntos) junto a la conversación
2. Selecciona "Eliminar"
3. Confirma la eliminación

**Consejo**: El Copilot recuerda el contexto de la conversación actual, así que puedes hacer preguntas de seguimiento sin repetir información.

---

## 8. Reportes de Flota

La sección de Reportes de Flota te permite ver el estado actual de todos tus vehículos en un solo lugar.

### Vista General de Vehículos

En la página de Reportes de Flota verás una tabla con todos tus vehículos y su información actualizada.

[FOTO: Vista de reportes de flota]

### Información de Cada Vehículo

Para cada vehículo verás:

**Información Básica:**
- **Nombre/Placa**: Identificación del vehículo
- **Marca y Modelo**: Información del vehículo
- **Año**: Año del vehículo
- **Serial**: Número de serie

**Estado Actual:**
- **Estado del Motor**: Encendido (🟢) o Apagado (🔴)
- **Velocidad Actual**: En km/h
- **Estado de Movimiento**: Si el vehículo está en movimiento o detenido
- **Ubicación**: Dirección o coordenadas GPS
- **Odómetro**: Kilometraje total del vehículo
- **Última Actualización**: Cuándo se recibió la última información

**Ubicación GPS:**
- Haz clic en el enlace de ubicación para abrir Google Maps y ver exactamente dónde está el vehículo
- Verás si el vehículo está dentro o fuera de una geocerca (zona delimitada)

[FOTO: Detalle de un vehículo]

### Filtros

Puedes filtrar los vehículos para encontrar los que necesitas:

**Por Tags:**
- Filtra vehículos que pertenecen a grupos específicos (tags)
- Por ejemplo: "Vehículos de Reparto", "Vehículos de Emergencia"

**Por Búsqueda:**
- Busca por nombre, placa, marca o modelo
- Escribe en el cuadro de búsqueda y presiona Enter

**Por Estado:**
- **Activos**: Solo vehículos activos
- **Con Motor Encendido**: Solo vehículos con motor funcionando
- **En Movimiento**: Solo vehículos que están en movimiento
- **Detenidos**: Solo vehículos detenidos

[FOTO: Filtros y exportación]

### Exportar Reporte a PDF

Puedes exportar el reporte completo a PDF para guardarlo o compartirlo:

1. Aplica los filtros que necesites (opcional)
2. Haz clic en el botón **"Exportar a PDF"**
3. El sistema generará un documento PDF con toda la información
4. Descarga el archivo cuando esté listo

**Consejo**: Los reportes exportados incluyen la fecha y hora de generación, así que siempre sabrás cuándo se creó el reporte.

---

## 9. Gestión de Conductores

En esta sección puedes ver y editar la información de los conductores de tu flota.

### Lista de Conductores

La página de Conductores muestra una lista de todos los conductores registrados en tu sistema.

[FOTO: Lista de conductores]

Para cada conductor verás:
- **Nombre**: Nombre completo del conductor
- **ID de Samsara**: Identificador en el sistema Samsara
- **Teléfono**: Número de teléfono
- **Código de País**: Código del país del teléfono (ej: +52 para México)

### Editar Información de Conductor

Para editar la información de un conductor:

1. Haz clic en el conductor que quieres editar
2. Se abrirá el formulario de edición
3. Modifica los campos que necesites:
   - **Número de Teléfono**: Actualiza el teléfono si cambió
   - **Código de País**: Selecciona el código correcto del país
4. Haz clic en **"Guardar"**

[FOTO: Formulario de edición]

**Importante**: El número de teléfono y código de país son importantes porque se usan para enviar notificaciones al conductor cuando sea necesario.

### Actualización Masiva de Códigos de País

Si necesitas actualizar el código de país de múltiples conductores a la vez:

1. En la lista de conductores, selecciona los conductores que quieres actualizar (usa las casillas de verificación)
2. Haz clic en **"Actualizar Código de País"**
3. Selecciona el nuevo código de país
4. Confirma la actualización

**Consejo**: Esto es útil cuando cambias de operador telefónico o cuando necesitas corregir códigos de país incorrectos en lote.

---

## 10. Gestión de Contactos

Los contactos son las personas que pueden recibir notificaciones cuando ocurre una alerta importante. Puedes configurar diferentes contactos para diferentes tipos de situaciones.

### ¿Qué son los Contactos?

Los contactos son personas (supervisores, equipo de monitoreo, personal de emergencia, etc.) que deben ser notificados cuando ocurre algo importante con tus vehículos. El sistema puede enviarles notificaciones por:
- SMS
- WhatsApp
- Llamadas telefónicas

### Tipos de Contactos

Hay cuatro tipos de contactos que puedes crear:

1. **Equipo de Monitoreo**: Personal que monitorea las alertas durante el día
2. **Supervisor**: Supervisores que deben ser informados de situaciones importantes
3. **Emergencia**: Contactos para situaciones de emergencia (bomberos, policía, etc.)
4. **Despacho**: Personal de despacho que coordina los vehículos

### Lista de Contactos

En la página de Contactos verás todos los contactos configurados.

[FOTO: Lista de contactos]

Para cada contacto verás:
- **Nombre**: Nombre del contacto
- **Rol**: Función o cargo
- **Tipo**: Tipo de contacto (Equipo de Monitoreo, Supervisor, etc.)
- **Teléfono**: Número de teléfono
- **Email**: Correo electrónico
- **Estado**: Activo o Inactivo
- **Por Defecto**: Si está marcado como contacto predeterminado

### Crear Nuevo Contacto

Para agregar un nuevo contacto:

1. Haz clic en el botón **"Nuevo Contacto"**
2. Completa el formulario:

[FOTO: Formulario de creación/edición]

**Información Básica:**
- **Nombre** (requerido): Nombre completo del contacto
- **Rol**: Función o cargo (opcional)
- **Tipo** (requerido): Selecciona el tipo de contacto
- **Teléfono**: Número de teléfono para SMS y llamadas
- **Teléfono WhatsApp**: Número para WhatsApp (si es diferente)
- **Email**: Correo electrónico (opcional)

**Asociación (Opcional):**
- **Entidad**: Puedes asociar el contacto a un vehículo o conductor específico
  - Si seleccionas "Vehículo", elige el vehículo
  - Si seleccionas "Conductor", elige el conductor
  - Si dejas "Global", el contacto recibirá notificaciones de todos los vehículos

**Configuración:**
- **Marcar como Por Defecto**: Si activas esto, este contacto será usado cuando no haya un contacto específico para una situación
- **Prioridad**: Número del 0 al 100 (mayor número = mayor prioridad)
- **Activo**: Si el contacto puede recibir notificaciones

**Notas:**
- Campo de texto libre para agregar información adicional sobre el contacto

3. Haz clic en **"Crear Contacto"**

### Editar Contacto

Para modificar un contacto existente:

1. Haz clic en el contacto que quieres editar
2. Modifica los campos necesarios
3. Haz clic en **"Guardar Cambios"**

### Activar/Desactivar Contacto

Puedes activar o desactivar un contacto sin eliminarlo:

1. En la lista de contactos, encuentra el contacto
2. Haz clic en el botón de activar/desactivar (toggle)
3. El estado cambiará inmediatamente

**Consejo**: Desactivar un contacto es útil cuando alguien está de vacaciones o no disponible temporalmente.

### Marcar como Contacto por Defecto

Los contactos por defecto se usan cuando no hay un contacto específico configurado para una situación:

1. En la lista de contactos, haz clic en el menú (tres puntos) del contacto
2. Selecciona **"Marcar como Por Defecto"**
3. El contacto quedará marcado como predeterminado

**Nota**: Solo puede haber un contacto por defecto de cada tipo.

### Prioridad de Contactos

La prioridad determina el orden en que se contacta a las personas cuando hay múltiples contactos del mismo tipo. Un número mayor significa mayor prioridad.

Por ejemplo, si tienes dos supervisores:
- Supervisor A con prioridad 80
- Supervisor B con prioridad 50

El sistema contactará primero al Supervisor A.

[FOTO: Opciones de contacto]

---

## 11. Configuración Personal

En esta sección puedes personalizar tu cuenta y preferencias del sistema.

### Acceder a Configuración

Para acceder a tu configuración personal:
1. Haz clic en tu foto o iniciales en la esquina superior derecha
2. Selecciona **"Configuración"**
3. O ve directamente a **Configuración** en el menú lateral

### Perfil de Usuario

En la pestaña **"Perfil"** puedes editar:

[FOTO: Página de perfil]

- **Nombre**: Tu nombre completo
- **Email**: Tu dirección de correo electrónico

**Para actualizar:**
1. Modifica los campos que quieras cambiar
2. Haz clic en **"Guardar"**
3. Si cambias el email, es posible que necesites verificarlo nuevamente

### Cambio de Contraseña

Para cambiar tu contraseña:

1. Ve a **Configuración** → **Contraseña**
2. Ingresa tu **contraseña actual**
3. Ingresa tu **nueva contraseña** (debe tener al menos 8 caracteres)
4. Confirma tu nueva contraseña
5. Haz clic en **"Actualizar Contraseña"**

**Consejos de Seguridad:**
- Usa una contraseña única que no uses en otros sitios
- Combina letras, números y símbolos
- No compartas tu contraseña con nadie

### Apariencia

Puedes personalizar cómo se ve el sistema:

[FOTO: Configuración de apariencia]

**Tema:**
- **Claro**: Fondo blanco, texto oscuro (mejor para el día)
- **Oscuro**: Fondo oscuro, texto claro (mejor para la noche, reduce fatiga visual)
- **Automático**: El sistema usa el tema según la hora del día

**Para cambiar:**
1. Ve a **Configuración** → **Apariencia**
2. Selecciona tu tema preferido
3. El cambio se aplica inmediatamente

**Consejo**: El tema oscuro es más cómodo para trabajar en ambientes con poca luz.

### Autenticación de Dos Factores (2FA)

La autenticación de dos factores añade una capa extra de seguridad a tu cuenta.

[FOTO: Configuración de 2FA]

**Para activar 2FA:**

1. Ve a **Configuración** → **Autenticación de Dos Factores**
2. Haz clic en **"Activar"**
3. Escanea el código QR con una aplicación de autenticación:
   - **Google Authenticator** (iOS/Android)
   - **Authy** (iOS/Android)
   - Cualquier otra app compatible con TOTP
4. Ingresa el código de 6 dígitos que aparece en tu aplicación
5. Guarda los **códigos de respaldo** en un lugar seguro

**Códigos de Respaldo:**
Los códigos de respaldo te permiten acceder a tu cuenta si pierdes acceso a tu teléfono. Guárdalos en un lugar seguro (como un administrador de contraseñas o un documento encriptado).

**Para desactivar 2FA:**
1. Ve a la configuración de 2FA
2. Haz clic en **"Desactivar"**
3. Confirma la desactivación
4. Ingresa tu contraseña para confirmar

**Importante**: Una vez activado el 2FA, necesitarás el código de tu aplicación cada vez que inicies sesión desde un dispositivo nuevo.

---

## 12. Configuración de Empresa (Solo Administradores)

Esta sección solo está disponible para usuarios con rol de Administrador. Aquí puedes configurar aspectos importantes de cómo funciona el sistema para tu empresa.

### Acceder a Configuración de Empresa

1. Ve a **Administración** → **Empresa** en el menú lateral
2. O haz clic en tu perfil → **"Configuración de Empresa"**

### Información de la Empresa

En la pestaña **"Información"** puedes editar:

[FOTO: Configuración de empresa]

- **Nombre de la Empresa**: Nombre oficial
- **Email de Contacto**: Email principal de la empresa
- **Teléfono**: Teléfono de contacto
- **Dirección**: Dirección física (opcional)

### Configuración de API de Samsara

Para que el sistema funcione correctamente, necesita conectarse a tu cuenta de Samsara mediante una clave de API.

**Agregar Clave de API:**
1. Ve a **Configuración de Empresa** → **API de Samsara**
2. Ingresa tu **Clave de API de Samsara**
3. Haz clic en **"Guardar"**

**Eliminar Clave de API:**
Si necesitas cambiar la clave o eliminarla:
1. Haz clic en **"Eliminar Clave"**
2. Confirma la eliminación

**Importante**: Sin una clave de API válida, el sistema no podrá recibir alertas de Samsara ni consultar información de vehículos.

### Configuración de IA

La configuración de IA te permite ajustar cómo el sistema procesa las alertas.

[FOTO: Configuración de IA]

**Modelos de IA:**
- **Modelo Estándar**: Se usa para la mayoría de las consultas (más rápido, menor costo)
- **Modelo Avanzado**: Se usa para consultas complejas (más preciso, mayor costo)

**Umbrales de Severidad:**
Puedes ajustar cuándo el sistema considera una alerta como crítica:
- **Umbral de Probabilidad**: Qué tan segura debe estar la IA para marcar una alerta como crítica
- **Umbral de Urgencia**: Qué tan urgente debe ser una situación

**Configuración de Notificaciones:**
- **Activar Notificaciones Automáticas**: Si el sistema debe enviar notificaciones automáticamente
- **Canales Habilitados**: Qué canales usar (SMS, WhatsApp, Llamadas)
- **Horarios de Notificación**: Cuándo enviar notificaciones (24/7 o solo en horario laboral)

**Restablecer Configuración:**
Si necesitas volver a los valores predeterminados:
1. Haz clic en **"Restablecer a Valores Predeterminados"**
2. Confirma la acción

**Consejo**: Si no estás seguro de qué valores usar, deja la configuración predeterminada. El sistema está optimizado para funcionar bien con los valores por defecto.

---

## 13. Gestión de Usuarios (Solo Administradores y Gerentes)

Esta sección te permite gestionar los usuarios que tienen acceso al sistema. Solo los Administradores y Gerentes pueden acceder a esta funcionalidad.

### Lista de Usuarios

En la página de Usuarios verás todos los usuarios registrados en tu empresa.

[FOTO: Lista de usuarios]

Para cada usuario verás:
- **Nombre**: Nombre completo
- **Email**: Dirección de correo
- **Rol**: Administrador, Gerente o Usuario
- **Estado**: Activo o Inactivo
- **Último Acceso**: Cuándo fue la última vez que inició sesión

### Roles de Usuario

Hay tres tipos de roles en el sistema:

1. **Administrador**: Acceso completo al sistema, incluyendo configuración de empresa y gestión de usuarios
2. **Gerente**: Puede gestionar usuarios y ver toda la información, pero no puede cambiar la configuración de empresa
3. **Usuario**: Acceso estándar para ver alertas, usar el Copilot y gestionar contactos

### Crear Nuevo Usuario

Para agregar un nuevo usuario al sistema:

1. Haz clic en el botón **"Nuevo Usuario"**
2. Completa el formulario:

[FOTO: Formulario de creación/edición]

**Información Requerida:**
- **Nombre**: Nombre completo del usuario
- **Email**: Dirección de correo electrónico (debe ser única)
- **Rol**: Selecciona el rol apropiado
- **Contraseña**: Contraseña temporal (el usuario deberá cambiarla en su primer acceso)
- **Estado**: Activo o Inactivo

3. Haz clic en **"Crear Usuario"**

**Importante**: El nuevo usuario recibirá un correo con instrucciones para acceder al sistema. Deberá verificar su email y cambiar su contraseña en el primer acceso.

### Editar Usuario

Para modificar la información de un usuario:

1. Haz clic en el usuario que quieres editar
2. Modifica los campos necesarios:
   - Nombre
   - Email
   - Rol
   - Estado (Activo/Inactivo)
3. Haz clic en **"Guardar Cambios"**

**Nota**: No puedes cambiar la contraseña de otro usuario desde aquí. El usuario debe hacerlo desde su configuración personal o usar la función de recuperación de contraseña.

### Activar/Desactivar Usuario

Puedes desactivar un usuario sin eliminarlo del sistema:

1. En la lista de usuarios, encuentra el usuario
2. Haz clic en el botón de activar/desactivar (toggle)
3. El estado cambiará inmediatamente

**Cuándo desactivar un usuario:**
- Cuando un empleado deja la empresa
- Cuando alguien está de vacaciones prolongadas
- Cuando necesitas revocar acceso temporalmente

**Consejo**: Desactivar es mejor que eliminar porque mantiene el historial de actividades del usuario.

---

## 14. Preguntas Frecuentes (FAQ)

### Sobre Alertas

**P: ¿Por qué algunas alertas aparecen como "Falso Positivo"?**
R: La inteligencia artificial analiza cada alerta y a veces determina que no es realmente importante. Por ejemplo, un botón de pánico presionado accidentalmente. Puedes revisar la explicación de la IA en la vista detallada de la alerta.

**P: ¿Puedo desactivar las notificaciones automáticas?**
R: Sí, los administradores pueden configurar esto en Configuración de Empresa → Configuración de IA. Puedes desactivar las notificaciones automáticas o ajustar los horarios.

**P: ¿Qué significa "En Investigación"?**
R: Significa que la IA está monitoreando la alerta continuamente. El sistema la revisará periódicamente para ver si la situación cambia o se resuelve.

**P: ¿Puedo eliminar una alerta?**
R: No puedes eliminar alertas, pero puedes marcarlas como "Falso Positivo" o "Resuelta" para que no aparezcan en tus vistas principales.

### Sobre Notificaciones

**P: ¿Cómo sé si se envió una notificación?**
R: En la vista detallada de una alerta, verás una sección "Notificaciones Enviadas" que muestra qué notificaciones se enviaron y a quién.

**P: ¿Puedo cambiar los contactos que reciben notificaciones?**
R: Sí, puedes editar los contactos en la sección de Gestión de Contactos. También puedes asociar contactos específicos a vehículos o conductores.

**P: ¿Por qué no recibí una notificación de una alerta crítica?**
R: Verifica que:
- El contacto esté activo
- El número de teléfono sea correcto
- La configuración de notificaciones esté activada
- El contacto tenga el tipo correcto para ese tipo de alerta

### Sobre el Copilot

**P: ¿El Copilot puede hacer cambios en el sistema?**
R: No, el Copilot solo puede consultar información. No puede modificar configuraciones, crear alertas o cambiar estados.

**P: ¿Por qué el Copilot a veces tarda en responder?**
R: Depende de la complejidad de tu consulta. Consultas simples son rápidas, pero consultas que requieren analizar mucha información pueden tardar más.

**P: ¿Puedo usar el Copilot desde mi teléfono?**
R: Sí, el sistema funciona en dispositivos móviles. Puedes acceder desde cualquier navegador en tu teléfono.

**P: ¿El Copilot guarda mis conversaciones?**
R: Sí, todas las conversaciones se guardan automáticamente. Puedes verlas en el historial y eliminarlas si lo deseas.

### Sobre Configuración

**P: ¿Puedo cambiar mi rol de usuario?**
R: No, solo un Administrador o Gerente puede cambiar tu rol. Contacta a tu administrador si necesitas un cambio.

**P: ¿Qué pasa si olvido mi contraseña?**
R: Usa la función "¿Olvidaste tu contraseña?" en la pantalla de login. Recibirás un correo con instrucciones para crear una nueva contraseña.

**P: ¿Puedo tener múltiples cuentas?**
R: Cada usuario debe tener un email único. Si necesitas acceso desde múltiples cuentas, contacta a tu administrador.

### Problemas Comunes

**P: La página no carga o está muy lenta**
R: Intenta:
- Refrescar la página (F5 o Cmd+R)
- Cerrar otras pestañas del navegador
- Limpiar la caché del navegador
- Verificar tu conexión a internet

**P: No veo algunas alertas que debería ver**
R: Verifica los filtros activos. Puede que tengas filtros aplicados que están ocultando algunas alertas. Haz clic en "Limpiar Filtros" para ver todas.

**P: El mapa no se muestra correctamente**
R: Verifica que tu navegador permita el acceso a tu ubicación. También asegúrate de tener una conexión a internet estable.

**P: No puedo iniciar sesión**
R: Verifica que:
- Tu email y contraseña sean correctos
- Tu cuenta esté activa (contacta a tu administrador)
- Hayas verificado tu email si es la primera vez
- No tengas problemas de conexión

---

## 15. Glosario

### Términos Técnicos

**Alerta**: Notificación que recibes cuando algo sucede con uno de tus vehículos (por ejemplo, botón de pánico, evento de seguridad).

**Dashboard**: Página principal del sistema que muestra un resumen de toda la información importante.

**Geocerca**: Zona geográfica delimitada. El sistema puede detectar cuando un vehículo entra o sale de una geocerca.

**IA (Inteligencia Artificial)**: Sistema automatizado que analiza las alertas y determina su importancia sin intervención humana.

**Incidente**: Grupo de alertas relacionadas que se agrupan para facilitar su gestión.

**Kanban**: Vista de tablero que organiza las alertas en columnas según su estado (similar a un tablero de tareas).

**Notificación**: Mensaje enviado por SMS, WhatsApp o llamada telefónica cuando ocurre una alerta importante.

**Rich Card**: Tarjeta visual especial que muestra información de forma gráfica (mapas, estadísticas, etc.) en las respuestas del Copilot.

**Severidad**: Nivel de importancia de una alerta:
- **Crítica**: Requiere atención inmediata
- **Advertencia**: Importante pero no urgente
- **Informativa**: Solo para conocimiento, no requiere acción

**Tag**: Etiqueta o grupo que se asigna a vehículos para organizarlos (por ejemplo: "Vehículos de Reparto", "Vehículos de Emergencia").

**Veredicto**: Conclusión de la inteligencia artificial sobre una alerta (por ejemplo: "Alerta válida", "Falsa alarma").

### Estados de Alertas

**Pendiente**: Alerta nueva que aún no ha sido procesada por la IA.

**En Procesamiento**: La IA está analizando la alerta actualmente.

**En Investigación**: La alerta requiere monitoreo continuo. La IA la revisará periódicamente.

**Completada**: La alerta fue procesada y evaluada completamente.

**Fallida**: Hubo un error al procesar la alerta. Se reintentará automáticamente.

### Estados de Revisión Humana

**Pendiente**: Aún no ha sido revisada por un humano.

**Revisada**: Ya fue revisada y estás de acuerdo con la evaluación de la IA.

**Marcada**: La alerta es importante y quieres destacarla.

**Resuelta**: El problema ya fue atendido.

**Falso Positivo**: La alerta no era realmente importante.

### Roles de Usuario

**Administrador**: Acceso completo al sistema, puede configurar la empresa y gestionar usuarios.

**Gerente**: Puede gestionar usuarios y ver toda la información, pero no puede cambiar la configuración de empresa.

**Usuario**: Acceso estándar para ver alertas, usar el Copilot y gestionar contactos.

---

## Contacto y Soporte

Si tienes preguntas o necesitas ayuda que no está cubierta en este manual:

1. Contacta a tu administrador del sistema
2. Revisa la sección de Preguntas Frecuentes
3. Consulta el Glosario para entender términos técnicos

---

**Última actualización**: [Fecha de actualización del manual]

**Versión del Manual**: 1.0

---

*Este manual fue creado para ayudarte a usar SAM de manera efectiva. Si encuentras errores o tienes sugerencias para mejorarlo, por favor contacta a tu administrador.*
