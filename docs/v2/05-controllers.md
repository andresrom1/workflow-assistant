# Controllers — workflow-assistant (v2)

> Cada controller indica **a quién sirve** (canal consumidor).
> Canales: `openai_chatkit`, `pas_mobile` (legacy, extirpado), `pas-web` (legacy, extirpado), `mango-mobile`, `workflow-assistant`.

---

## Chat / cotización

| Controller | Canal | Responsabilidad |
|---|---|---|
| `ToolsController` | `openai_chatkit` | Recibe las tool-calls HTTP de ChatKit y delega al `AgentToolAdapter` (detecta proveedor vía `ToolAdapterFactory`). Métodos: identifyCustomer/Vehicle, coveragePreference, getQuote, checkout + stubs. |
| `QuoteController` | `openai_chatkit` (store) / `workflow-assistant` (index, show, showRaw) | `store`: alta de quote desde la API. index/show: vistas admin. `showRaw`: auditoría del payload de proveedor. |
| `ChatController` | `workflow-assistant` | Chat interno de pruebas del panel. |
| `Api\QuoteWebhookController` | **`pas_mobile` (legacy, extirpado)** | Recibía cotizaciones manuales de la app PAS (`/webhooks/quote-update`). Controller y ruta eliminados en V2-6; lo reemplaza la resolución Visred. |
| `AI\WhatsAppWebhookController` | `workflow-assistant` | Verificación + ingesta de la WhatsApp Cloud API (Meta). Despacha el pipeline de 3 etapas. |

---

## Checkout y emisión

| Controller | Canal | Responsabilidad |
|---|---|---|
| `CheckoutController` | `workflow-assistant` | Página pública de checkout (token opaco): show, uploadPhoto, deletePhoto, submit, success. Captura fotos de inspección a R2. El link se origina en el flujo de chat. |
| `CoverageDocumentController` | `workflow-assistant` | CRUD de documentos de cobertura (RAG): subida de PDF, extracción, edición, embeddings. |

---

## Panel admin / operador

Canal: `workflow-assistant`.

| Controller | Responsabilidad |
|---|---|
| `CustomerController` | Listado/detalle de clientes. |
| `ProfileController` | Perfil del operador. |
| `Admin\CheckoutAuditController` | Auditoría de checkout sessions + manejo de datos de tarjeta (mark-processed/clear). |
| `Admin\SettingsController` | Edición de `system_settings` por grupo. |
| `Admin\ConversationController` | Listado/detalle/reset/analyze-semantics de conversaciones. |
| `Admin\AgentPromptController` | Versionado y draft flow de prompts de agentes. |
| `Admin\AgentExecutionLogAnnotationController` | Anotaciones (verdict/note) sobre logs de ejecución. |
| `Admin\AnalyticsController` | Funnel/heatmap de steps. |
| `Admin\StudioController` | Reevaluación de un turn con otro prompt/estado. |
| `Admin\UserController` | Gestión de usuarios/operadores. |
| `Auth\*Controller` | Login, password reset, verificación de email, sesión (scaffolding Breeze). |
| `TestingController` | Endpoints `/dev/*` (tests, estado/limpieza de BD, system-info) — solo desarrollo. |

---

## App MANGO

Canal: `mango-mobile`. Namespace `App\Http\Controllers\Mobile\*` (guard `auth:mobile` salvo lo indicado).

| Controller | Responsabilidad |
|---|---|
| `Mobile\AuthController` | Intercambia Firebase ID Token por token Sanctum; logout. |
| `Mobile\PolizasController` | Cartera: PAS + propias + riesgos compartidos (Home) y detalle. |
| `Mobile\SiniestroController` | Aviso de siniestro al PAS (hoy resuelve destinatario; envío WhatsApp es deuda). |
| `Mobile\EmergencyContactsController` | CRUD de contactos de emergencia (máx 3). |
| `Mobile\EmergencyController` | Necesito Ayuda (Estado 1/2), updatePosition (sin auth, vía `update_secret`), revokeTracking. |
| `Mobile\SharedRisksController` | Cuenta Compartida: invite/revoke/revokeMine/index. |
| `TrackingController` | Página pública `/track/{token}` (mapa en vivo) que abren los contactos de emergencia. |

---

## Resumen por canal

| Canal | Controllers |
|---|---|
| `openai_chatkit` | `ToolsController`, `QuoteController@store` |
| `mango-mobile` | `Mobile\*`, `TrackingController` |
| `workflow-assistant` | `CheckoutController`, `CoverageDocumentController`, `CustomerController`, `ProfileController`, `ChatController`, `Admin\*`, `Auth\*`, `TestingController`, `AI\WhatsAppWebhookController`, `QuoteController` (admin) |
| `pas_mobile` (legacy, extirpado) | `Api\QuoteWebhookController` |
