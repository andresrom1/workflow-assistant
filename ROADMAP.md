# workflow-assistant — Roadmap de implementación

> **Fuente de verdad de "dónde estamos"** en el backend. Trackea ejecución, no decisiones de producto.
> El detalle de arquitectura vive en [`docs/v2/`](docs/README.md). La app cliente tiene su propio roadmap en `mango-mobile/ROADMAP.md`.
>
> **Protocolo de mantenimiento (Claude):** cada vez que hagamos un cambio, actualizar este archivo en el mismo turno —
> mover el estado de la fase, completar fecha + commit/PR, y agregar una línea a la **Bitácora**. Si un cambio
> abre o salda deuda, reflejarlo en la tabla de deuda.

**Leyenda:** ⬜ Sin empezar · 🚧 En progreso · ✅ Completa · ⏸️ Bloqueada · ❌ Descartada

---

## Estado actual (snapshot)

### Base ya construida (v1 → operativa, mayormente con mocks)

| Área | Estado | Nota |
|------|--------|------|
| Flujo de cotización (chat web ChatKit) | ✅ | identify customer/vehicle → coverage → quote → checkout. Adapter `OpenAI\AgentToolAdapter`. |
| Flujo de cotización (WhatsApp) | ✅ | Orquestador + sub-agentes, pipeline de inbox de 3 etapas. Adapter `AIProviders\WhatsAppAdapter`. |
| Integración WhatsApp Cloud API | ✅ | Webhooks in, `WhatsAppOutboundService`, media STT/TTS, idempotencia por `wamid`. |
| Motor de cotización | 🚧 | `QuotingEngine` **es mock** (`sleep(30)` + catálogo simulado). Seam para Visred. |
| RAG coberturas (pgvector) | ✅ | Dos agentes (frontal + experto), `ChunkAndEmbedService`, `SearchCompanyDocumentationTool`. |
| Checkout web + fotos R2 | ✅ | Token opaco, 7 fotos inspección, tarjeta cifrada, auditoría admin. |
| API Mobile (mango-mobile) | ✅ | auth, pólizas, siniestro, emergencia, shared-risks. Guard `auth:mobile`. |
| Panel admin / observabilidad | ✅ | Conversaciones, prompts versionados, analytics funnel, Studio, settings, usuarios. |
| Aislamiento de datos de proveedor | ✅ | ADR-001 — `quote_provider_refs`. Vigente. |
| Documentación v2 | ✅ | `docs/v2/` canónico + índice `docs/README.md` (2026-06-02). |

---

## Cirugía v2 — forward roadmap

> Objetivo: reemplazar el rol de `pas_mobile`/`pas-web` (cotización manual + fallback) por **Visred**
> (cotiza y emite sin intervención humana), y retirar lo legacy. Diseño base en
> [`docs/v2/08-visred-quote-adapter.md`](docs/v2/08-visred-quote-adapter.md).

| Fase | Tema | Estado | Detalle |
|------|------|--------|---------|
| V2-0 | Reorden + doc de arquitectura | ✅ | docs/v2 + ROADMAP (2026-06-02). |
| V2-1 | Extraer `QuotationPort` | ⬜ | Sacar interface de la firma actual del quote; `QuotingEngine` mock → `MockQuotationAdapter implements QuotationPort`. Sin cambiar comportamiento. Tests existentes verdes. |
| V2-2 | `VisredClient` (HTTP + JWT) | ⬜ | login/refresh token, Bearer, refresh-on-401, normalización del envelope de error Visred. Bloqueado por credenciales sandbox. |
| V2-3 | `VisredQuotationAdapter` | ⬜ | Traducción entrada/salida + polling de tasks. Fixtures del schema (`TaskList`, `APIBaseQuotationResultDTO`). |
| V2-4 | Config seam (`MANGO_QUOTATION_PROVIDER`) | ⬜ | Bind condicional `mock`/`visred` en service provider. Flip por env. |
| V2-5 | Emisión Visred | ⬜ | `PolizaEmisionService` (hoy skeleton) → emisión real (`emitir/` → polling → `presale_id`/`policy_number`) + descarga de documento. |
| V2-6 | Retiro de legacy PAS | ✅ | **pas_mobile extirpado** (rama `prepare-proyect-for-API-refactor`): `MobileAppQuoteResolution`, `QuoteOfferedToPas`, `FallbackToApiListener`, `QuoteWebhookController` + ruta `/webhooks/quote-update`, `MobileSyncLog` + columnas `mobile_*` de `quotes`, settings/config `mobile_app.*`, badges `offered_pas`/`rejected_pas`, y stub `n8n-whatsapp/`. `QuoteService` colapsado a estrategia única (`api`). Puente WhatsApp real = `WhatsAppWebhookController` (Cloud API nativa, sin n8n). |
| V2-7 | Deuda WhatsApp dispatch | ⬜ | Cablear los 3 TODO (siniestro + emergencia 1/2) al dispatcher async + templates Meta. Ver deuda abajo. |

---

## Deuda técnica abierta

| Deuda | Ubicación | Estado |
|-------|-----------|--------|
| Dispatch WhatsApp — Siniestro → PAS | `Mobile/SiniestroController.php:47` | ⬜ TODO (infra outbound ya existe, falta cablear + template) |
| Dispatch WhatsApp — Emergencia Estado 1 | `Mobile/EmergencyController.php:42` | ⬜ TODO |
| Dispatch WhatsApp — Emergencia Estado 2 | `Mobile/EmergencyController.php:52` | ⬜ TODO |
| `QuotingEngine` mockeado (`sleep(30)`) | `Services/QuotingEngine.php` | 🚧 se resuelve en V2-1→V2-4 |
| `PolizaEmisionService` skeleton | `Services/PolizaEmisionService.php` | ⬜ se resuelve en V2-5 |
| Credenciales sandbox Visred | — externo | ⏸️ bloquea V2-2/3/5 (test E2E) |

---

## Bitácora

> Entrada por cada cambio relevante. Formato: `fecha — qué — commit/PR`.

- **2026-06-02** — Reorganización de documentación: creado `docs/v2/` canónico (8 docs: schema, endpoints, services, repos, controllers, adapters, consolidado v1, Visred adapter) + índice `docs/README.md`. `docs/v1/` pasa a archivo histórico. Creado este ROADMAP. _(sin commit aún)_
- **2026-06-02** — Rama `cirugia-v2` creada desde `main` (será la futura `main`). **Extirpación de `pas_mobile`** (V2-6 parcial): eliminados `MobileAppQuoteResolution`, `QuoteOfferedToPas`, `FallbackToApiListener`, `QuoteWebhookController` + ruta, `MobileSyncLog`, `OpportunityTestDataSeeder`; nueva migración dropea tabla `mobile_sync_logs` y columnas `mobile_*`/`sent_to_mobile_at`/`expected_resolution_at` de `quotes`; limpiados config/services `mobile_app`, settings seeder, `SettingsController`, vistas (badges PAS, card endpoint). `QuoteService` colapsado a estrategia única `api`; adapters llaman `resolveQuote()` sin estrategia. Verificado n8n = stub muerto (puente real = `WhatsAppWebhookController`). Pint ✅ · PHPStan 0 ✅ · suite idéntica a baseline (24 fallos pre-existentes ajenos). _(commit `1d45c7c`; docs reorg en `d9fbe8e`)_
- **2026-06-02** — Limpieza residual: `STYLE_GUIDE.md` y skill `frontend-style-guide` → v2.3, removidos los badges `offered_pas`/`rejected_pas` del snippet `statusClass`.
- **2026-06-02** — Borrado stub `app/Adapters/n8n-whatsapp/` (dead, lanzaba excepción) + todas sus referencias en `CLAUDE.md`, `README.md` (×2, reescritas a Cloud API nativa) y `docs/v2/06-adapters.md` (×2). **V2-6 completa.**
