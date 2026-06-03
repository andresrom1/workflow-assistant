# Repositories — workflow-assistant (v2)

> Capa de **acceso a datos**, agnóstica del canal (Adapter → Service → **Repo**).
> Reciben/retornan modelos de dominio. Cada repo indica a qué canal sirve.
> Canales: `openai_chatkit`, `pas_mobile` (legacy, extirpado), `pas-web` (legacy, extirpado), `mango-mobile`, `workflow-assistant`.

---

## Flujo de cotización

Sirven a `openai_chatkit` y `workflow-assistant` (canal WhatsApp) por igual — son la base del dominio de cotización.

| Repository | Tabla(s) | Responsabilidad |
|---|---|---|
| `CustomerRepository` | `customers` | Buscar/crear/actualizar clientes (leads). |
| `VehicleRepository` | `vehicles`, `conversation_vehicle` | Persistir vehículos y asociarlos a la conversación. |
| `RiskSnapshotRepository` | `risk_snapshots` | Crear el snapshot inmutable del riesgo; inyectar `coverage_preference`. |
| `QuoteRepository` | `quotes`, `quote_alternatives`, `quote_provider_refs` | Crear quotes, `saveResults()` (separa datos de proveedor a `quote_provider_refs` — ver ADR-001). |
| `CoverageRepository` | `coverage_preferences` | Persistir/leer preferencias de cobertura. |
| `ConversationRepository` | `conversations`, `messages` | `findOrCreateByExternalId`, estado del orquestador (`metadata.ai_state`), persistencia de mensajes. Compartido entre chat web y WhatsApp. |

---

## Administración / analytics

Canal: `workflow-assistant`.

| Repository | Tabla(s) | Responsabilidad |
|---|---|---|
| `AnalyticsRepository` | `agent_execution_logs`, `conversations` | Agregaciones para el funnel/heatmap de steps del panel admin. |

---

## Resumen por canal

| Canal | Repositories |
|---|---|
| `openai_chatkit` + `workflow-assistant` | `CustomerRepository`, `VehicleRepository`, `RiskSnapshotRepository`, `QuoteRepository`, `CoverageRepository`, `ConversationRepository` |
| `workflow-assistant` | `AnalyticsRepository` |
| `mango-mobile` | — (los controllers mobile usan modelos Eloquent directos: `MobileAccount`, `Risk`, `Poliza`, `EmergencyContact`, `SharedRisk`, `EmergencyTrackingToken`) |
| `pas_mobile` / `pas-web` (legacy, extirpado) | — |

> **Nota:** El lado `mango-mobile` no introdujo repositories dedicados; sus controllers operan sobre los modelos Eloquent directamente (patrón aceptado en este monorepo para CRUD simple de cartera/emergencias).
