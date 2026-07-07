# Workflow Assistant - Sistema de Cotización de Seguros

Este proyecto es un asistente inteligente diseñado para automatizar y agilizar el proceso de cotización y emisión de seguros, integrando inteligencia artificial con flujos de trabajo robustos en el backend.

> **Canal activo: WhatsApp (Meta Cloud API).** El chat web sobre OpenAI ChatKit / Agent Builder
> queda **deprecado** — OpenAI discontinúa Agent Builder a más tardar el **30-nov-2026**. Por eso
> el asistente opera **solo por WhatsApp** por el momento, la **landing pública de marca (MANGO)**
> ahora vive en este proyecto (`/` → `Landing/Index.vue`), y **Laravel Reverb fue retirado**: no hay
> cliente web que consuma broadcasts, la entrega asíncrona de cotizaciones va por el pipeline de
> jobs de WhatsApp (`NotifyClientQuoteReady` → `SendWhatsAppMessage`).

## 🚀 Funcionalidades Implementadas

### 1. Asistente Basado en IA (Agente)
- **Integración con OpenAI**: El sistema utiliza un `AgentToolAdapter` para que modelos de lenguaje (GPT) interactúen con la lógica de negocio mediante herramientas específicas.
- **Orquestación de Herramientas**: Capacidad de la IA para ejecutar acciones como identificar clientes, vehículos y registrar preferencias de forma autónoma durante la conversación.

### 2. Gestión de Clientes
- **Identificación Multi-canal**: Soporte para identificar clientes mediante Email, Teléfono o DNI/CUIT. (El BSUID de WhatsApp es un identificador de conversación, no del tomador — vive en `conversations.ext_user_id`.)
- **Persistencia y Vinculación**: Creación automática de perfiles de clientes y vinculación con la sesión de conversación actual.

### 3. Gestión de Vehículos
- **Identificación de Vehículos**: Registro detallado de vehículos (patente, marca, modelo, versión, año, combustible y código postal).
- **Normalización de Patentes**: Servicio especializado para estandarizar diferentes formatos de matrículas.
- **Transferencia de Propiedad**: Lógica para manejar vehículos que cambian de dueño dentro del sistema.

### 4. Motor de Cotización
- **Resolución de Cotización**: Al capturar la preferencia de cobertura, el sistema resuelve la cotización vía la estrategia `ApiQuoteResolution`, que delega en el puerto `QuotationProvider` (implementación real: `VisredQuotationProvider`), y notifica al cliente cuando las alternativas están listas (`NotifyClientQuoteReady`).
- **Snapshots de Riesgo**: Captura del estado del riesgo en el momento de la cotización para auditoría y consistencia.
- **Alternativas de Cobertura**: Generación automática de múltiples opciones de cobertura (Responsabilidad Civil, Terceros, Todo Riesgo, etc.).

### 5. Preferencias de Cobertura
- **Captura de Preferencias**: Permite al usuario (a través de la IA) definir qué tipo de cobertura desea, lo cual se persiste para refinar el match con las alternativas generadas.

### 6. Infraestructura Asíncrona
- **Entrega asíncrona de cotizaciones**: cuando las alternativas están listas, `NotifyClientQuoteReady` reingresa al orquestador y despacha la respuesta por WhatsApp (`SendWhatsAppMessage`). Sin WebSockets: el canal es WhatsApp, no un frontend web en vivo.
- **Arquitectura Basada en Trabajos (Jobs)**: Uso intensivo de colas para procesos pesados, garantizando una respuesta rápida al usuario.

### 7. Herramientas de Desarrollo
- **Consola de Testing**: Endpoints dedicados para ejecutar tests, limpiar la base de datos y verificar el estado del sistema en entornos de desarrollo.

---

## 🔮 Funcionalidades Futuras (Roadmap)

### 1. Completado de Datos de Riesgo
- **`save-vehicle-data`**: Implementación de formularios extendidos para capturar datos adicionales del vehículo (accesorios, estado general, etc.).

### 2. Formularios Dinámicos e Interactivos
- **`show-data-form`**: Capacidad del asistente de presentar formularios UI específicos al usuario para recolección de datos que la IA no pudo capturar por chat.

### 3. Inspección Digital (Fotos de Unidad)
- **`show-vehicle-photos-form`**: Integración de un flujo para que el cliente cargue fotos del vehículo para validación de estado antes de la emisión.

### 4. Procesamiento de Pagos
- **`show-payment-form`**: Integración con pasarelas de pago para asegurar la primera cuota o el pago total de la póliza.

### 5. Emisión Final de Póliza
- **`finalize-policy`**: Cierre del ciclo de venta con la generación y envío de la póliza digital al cliente.

### 6. Soporte Multi-canal Avanzado
- **WhatsApp**: canal activo, integrado vía Meta WhatsApp Cloud API nativa (webhook + pipeline de jobs). El chat web (OpenAI ChatKit) queda deprecado por el sunset de Agent Builder; Telegram queda como canal futuro.

---

## 🛠️ Stack Tecnológico
- **Backend**: Laravel 11.x (PHP 8.3+)
- **IA**: OpenAI API (con Assistant API o Tool Calling)
- **Base de Datos**: PostgreSQL / MySQL
- **Colas**: Redis / Database Queue
- **Integraciones**: Meta WhatsApp Cloud API (canal de mensajería)
