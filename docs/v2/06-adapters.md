# Adapters — workflow-assistant (v2)

> El Adapter es la **única capa que conoce el canal**: traduce tool-calls del proveedor de IA
> a llamadas de Service, normaliza payloads y maneja errores del canal (Adapter → Service → Repo).
> Canales: `openai_chatkit`, `pas_mobile` (legacy), `pas-web` (legacy), `mango-mobile`, `workflow-assistant`.

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

Selección por config (`MANGO_QUOTATION_PROVIDER=mock|visred`). El dominio nunca importa clases de Visred.

---

## Resumen por canal

| Canal | Adapters |
|---|---|
| `openai_chatkit` | `OpenAI\AgentToolAdapter` |
| `workflow-assistant` (WhatsApp) | `AIProviders\WhatsAppAdapter` |
| `workflow-assistant` (cotización Visred) | `VisredQuotationAdapter` / `MockQuotationAdapter` (**pendiente**) |
