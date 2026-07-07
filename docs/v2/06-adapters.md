# Adapters — workflow-assistant (v2)

> El Adapter es la **única capa que conoce el canal**: traduce tool-calls del proveedor de IA
> a llamadas de Service, normaliza payloads y maneja errores del canal (Adapter → Service → Repo).
> Canales: `openai_chatkit` (deprecado, sunset 30-nov-2026), `pas_mobile` (legacy, extirpado), `pas-web` (legacy, extirpado), `mango-mobile`, `workflow-assistant`.

---

## Adapters de proveedor de IA

Todos implementan `App\Contracts\AIProviderAdapterInterface` (`handleToolCall(array $payload, string $toolName): array`). Comparten los mismos services: `CustomerIdentificationService`, `VehicleIdentificationService`, `ConversationRepository`, `QuoteService`, `CoveragePreferenceService`, `PlateNormalizerService`.

| Adapter | Canal | Responsabilidad |
|---|---|---|
| `OpenAI\AgentToolAdapter` | `openai_chatkit` | Entrada del chat web (ChatKit/OpenAI). Valida campos comunes, normaliza el payload, resuelve la conversación por `external_conversation_id` + `channel`, delega al handler de cada tool. Es la **única puerta de salida** de datos hacia el agente (mapeo explícito sin IDs de proveedor — ver ADR-001). |
| `AIProviders\WhatsAppAdapter` | `workflow-assistant` (canal WhatsApp) | Variante para el flujo WhatsApp: los sub-agentes del orquestador llaman a sus métodos directamente (`identifyCustomer`, `identifyVehicle`, `coveragePreference`, `getQuote`, `checkout`). Resuelve la conversación forzando `channel='whatsapp'`. |

La instanciación se centraliza vía `App\Factories\ToolAdapterFactory` (detección de proveedor desde el request en `ToolsController`).

---

## Adapter pendiente — Visred (quote/emisión)

> Documentado en detalle en `docs/v2/08-visred-quote-adapter.md` y el consolidado.
> **Aún no existe en código** (andamiaje). Es la pieza que reemplaza a `pas_mobile`/`pas-web`.

Diseño propuesto: insertar un puerto `QuotationPort` en el punto donde hoy `QuotingEngine` resuelve con mock, con dos implementaciones detrás del mismo contrato:

- `MockQuotationAdapter` (lo actual, `QuotingEngine` mock).
- `VisredQuotationAdapter` (cotiza contra Visred: `POST .../cotizar/` → polling de tasks → traduce resultados; futuro: emisión).
- `VisredClient` (concern HTTP separado: login/refresh JWT, Bearer, normalización del envelope de error Visred).

Selección por config (`QUOTATION_PROVIDER=mock|visred`). El dominio nunca importa clases de Visred.

---

## Puerto de salida — `WhatsAppDispatcher` (avisos por template)

> Puerto de **dominio** para los avisos iniciados por el negocio (siniestro→PAS, emergencia→contactos).
> `App\Contracts\WhatsAppDispatcher`, dos implementaciones, selección por config `whatsapp.dispatch_driver`.

| Driver (`WHATSAPP_DISPATCH_DRIVER`) | Implementación | Qué hace |
|---|---|---|
| `cloud` | `CloudApiWhatsAppDispatcher` | Arma el template y **encola** `SendWhatsAppTemplate` en `whatsapp-outbound`. Envío real vía Cloud API. |
| `log` (default) | `LogWhatsAppDispatcher` | No-op: solo loguea a quién se *habría* avisado. Default en local/testing. |

Binding en `AppServiceProvider` (`config('whatsapp.dispatch_driver') === 'cloud' ? Cloud : Log`). Async por diseño: el envío no bloquea la respuesta del endpoint (crítico en una emergencia); el 200 no depende del resultado del envío.

### Por qué el seam vive acá y NO en `WhatsAppOutboundService::sendMessage()`

Hay **dos familias de envíos de WhatsApp** y el seam `log`/`cloud` aplica **solo** a los avisos por template. No es asimetría arbitraria ni conveniencia de dev — son **dos superficies de API distintas con reglas de consentimiento opuestas** (ventana de 24h de la WhatsApp Business Platform):

| | Respuesta conversacional | Aviso por template |
|---|---|---|
| Tipo API | `type: text` (session/free-form) | `type: template` |
| Permitido | **solo** dentro de 24h del último inbound del user | **única** forma de iniciar contacto **fuera** de la ventana |
| Precondición | el destinatario **te escribió recién** (conversación activa que él inició) | el destinatario puede **no haber interactuado nunca** |
| Aprobación Meta | no requiere | template pre-aprobado + categoría |
| Costo | gratis (en ventana) | **se cobra** por conversación iniciada; suma al quality rating |
| Camino en el código | `ProcessConversationInbox` → `SendWhatsAppMessage` → `WhatsAppOutboundService::sendMessage()` | `WhatsAppDispatcher` → `SendWhatsAppTemplate` → `WhatsAppOutboundService::sendTemplate()` |
| Pasa por `dispatch_driver` | ❌ no | ✅ sí |

**Principio unificador (consistente, aunque la colocación sea asimétrica):**

> El kill-switch va en la superficie que puede alcanzar a un destinatario **no-consentido**.

Solo los templates pueden: son el único canal que llega a alguien sin relación conversacional previa, son la única superficie con costo por envío y riesgo de quality-rating/compliance (opt-out, spam 131048), y se disparan desde un endpoint REST que se ejercita **constantemente** durante el dev de la mobile app. Una respuesta de sesión, por construcción, solo responde a quien ya te escribió (en ventana, gratis, auto-limitante): gatear ahí no protege a nadie y solo agrega indirección.

Además es una diferencia de **capa**: `WhatsAppDispatcher` es un puerto de **política de dominio** (¿este entorno emite esta notificación de negocio?); `WhatsAppOutboundService` es **transporte** (¿cómo llega el byte a Meta?). El seam de consentimiento pertenece a la primera.

**Cuándo SÍ unificar:** si en el futuro se quiere un corte global "este entorno no habla con Meta para nada" (staging/demo), ese va en `WhatsAppOutboundService` (cuello de botella común de **ambas** familias), porque ahí el criterio es de transporte, no de consentimiento. Es ortogonal a este seam, no lo reemplaza.

---

## Resumen por canal

| Canal | Adapters |
|---|---|
| `openai_chatkit` | `OpenAI\AgentToolAdapter` |
| `workflow-assistant` (WhatsApp) | `AIProviders\WhatsAppAdapter` |
| `workflow-assistant` (cotización Visred) | `VisredQuotationAdapter` / `MockQuotationAdapter` (**pendiente**) |
| `workflow-assistant` (avisos por template) | `WhatsAppDispatcher` → `CloudApiWhatsAppDispatcher` / `LogWhatsAppDispatcher` |
