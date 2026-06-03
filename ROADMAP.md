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
| V2-6 | Retiro de legacy PAS | ✅ | **pas_mobile extirpado** (rama `prepare-proyect-for-API-refactor`): `MobileAppQuoteResolution`, `QuoteOfferedToPas`, `FallbackToApiListener`, `QuoteWebhookController` + ruta `/webhooks/quote-update`, `MobileSyncLog` + columnas `mobile_*` de `quotes`, settings/config `mobile_app.*`, badges `offered_pas`/`rejected_pas`, y stub `n8n-whatsapp/`. `QuoteService` colapsado a estrategia única (`api`). Puente WhatsApp real = `WhatsAppWebhookController` (Cloud API nativa, sin n8n). **pas-web también extirpado:** auditado cero huella en código/config/`.env` de `workflow-assistant` (ya estaba desacoplado de facto); queda solo en docs marcado `legacy, extirpado`. ADR-001 (docs/v1, histórico) se conserva intacta — el invariante `quote_provider_refs` sigue vigente para Visred. **Residuo final retirado (2026-06-03):** job `CheckQuoteAcceptance` (watchdog de timeout que originalmente esperaba la aceptación del PAS) + setting `pas.opportunity_timeout_minutes`. La resolución sincrónica vía `coveragePreference → resolveQuote` se conserva. |
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
| 24 tests rojos pre-existentes | `ProcessConversationInboxTest`, `ProcessMediaAttachmentTest`, `IdentifyVehicleTest`, `AI/CheckoutAgentPromptTest`, `Admin/ConversationShowTest`, `Jobs/SendWhatsAppMessageTest`, `SendWhatsAppMessageTest` | ✅ resuelto 2026-06-03 — suite 264/264 (ver Bitácora). Causa real ≠ "AI sin mock": eran mocks incompletos + dato UUID inválido + test pollution + orden no-determinista |
| **Escritura de prompts `.md` desde request HTTP** (clobber de archivos versionados) | `AgentPromptController::writePromptFile()` (`promoteDraft`/`store`) | ✅ resuelto 2026-06-03 — **Solución A**: eliminado `writePromptFile()` y sus 2 llamadas; la DB (`agent_prompts`, cacheada) queda como única fuente de verdad, el `.md` es seed/fallback. Test sin blindaje (ya no escribe disco); historia documentada en el header de `AgentPromptDraftFlowTest`. Ver Bitácora 2026-06-03 |

---

## Bitácora

> Entrada por cada cambio relevante. Formato: `fecha — qué — commit/PR`.

- **2026-06-02** — Reorganización de documentación: creado `docs/v2/` canónico (8 docs: schema, endpoints, services, repos, controllers, adapters, consolidado v1, Visred adapter) + índice `docs/README.md`. `docs/v1/` pasa a archivo histórico. Creado este ROADMAP. _(sin commit aún)_
- **2026-06-02** — Rama `cirugia-v2` creada desde `main` (será la futura `main`). **Extirpación de `pas_mobile`** (V2-6 parcial): eliminados `MobileAppQuoteResolution`, `QuoteOfferedToPas`, `FallbackToApiListener`, `QuoteWebhookController` + ruta, `MobileSyncLog`, `OpportunityTestDataSeeder`; nueva migración dropea tabla `mobile_sync_logs` y columnas `mobile_*`/`sent_to_mobile_at`/`expected_resolution_at` de `quotes`; limpiados config/services `mobile_app`, settings seeder, `SettingsController`, vistas (badges PAS, card endpoint). `QuoteService` colapsado a estrategia única `api`; adapters llaman `resolveQuote()` sin estrategia. Verificado n8n = stub muerto (puente real = `WhatsAppWebhookController`). Pint ✅ · PHPStan 0 ✅ · suite idéntica a baseline (24 fallos pre-existentes ajenos). _(commit `1d45c7c`; docs reorg en `d9fbe8e`)_
- **2026-06-02** — Limpieza residual: `STYLE_GUIDE.md` y skill `frontend-style-guide` → v2.3, removidos los badges `offered_pas`/`rejected_pas` del snippet `statusClass`.
- **2026-06-02** — Borrado stub `app/Adapters/n8n-whatsapp/` (dead, lanzaba excepción) + todas sus referencias en `CLAUDE.md`, `README.md` (×2, reescritas a Cloud API nativa) y `docs/v2/06-adapters.md` (×2). **V2-6 completa.**
- **2026-06-03** — **Barrido de residuo legacy en jobs/adapters** (auditoría jobs/queues/controllers/services). Borrados 2 jobs muertos del viejo flujo async de proveedores PAS: `RequestQuotesFromProviders` (huérfano, no se despachaba; su rol —resolución async— hoy es sincrónico vía `tryResolveQuoteById → resolveQuote`) y `RecieveQuotesFromProviders` (stub vacío, andamio del receipt `offered_pas`). Reescrito copy stale "Oferta enviada a los productores" → "Cotización procesada; preparando las alternativas…" en ambos adapters (`AgentToolAdapter`, `WhatsAppAdapter`). Corregidos docblocks "resolución Mobile" → "vía API" en los 2 adapters + `AIProviderAdapterInterface`. README: la sección "Cotización Asíncrona / `RequestQuotesFromProviders`" reescrita a `ApiQuoteResolution`/`NotifyClientQuoteReady`. Auditado: **cero acoplamiento import/runtime restante** con `pas_mobile`/`pas-web` (lo que matchea —`Mobile/`, `auth:mobile`, `mobile_account_id`, "PAS"/productor, `external_quote_id`— es mango-mobile vigente + dominio de negocio + ADR-001, no legacy). PHPStan 0 ✅. _(sin commit aún)_
- **2026-06-03** — **Triage de suite**: confirmado que los 24 tests rojos son **pre-existentes y ajenos** a la limpieza legacy (verificado vía `git stash` — `IdentifyVehicleTest` da idéntico 9F/7P con y sin los cambios; total se mantiene en 24 = baseline). Causa raíz: tests de integración pegan contra AI/HTTP real sin credenciales en entorno de test. Promovidos a **deuda activa "en arreglo"** (ver tabla) — se atacan en sesión aparte fakeando el borde AI/WhatsApp. _(sin commit aún)_
- **2026-06-03** — **Retiro del residuo final del flujo PAS opportunities** (cierre de V2-6). Eliminado el job `CheckQuoteAcceptance` (`app/Jobs/`) — watchdog cuyo propósito original era forzar el fallback a API cuando ningún PAS aceptaba la oferta dentro del timeout (`offered_pas` → `rejected_pas`); tras extirpar esos estados quedó manejando solo `pending`, sin razón de ser en el flujo WhatsApp-only. Removido su dispatch en `QuoteService::createPendingQuote` y el setting `pas.opportunity_timeout_minutes` (seeder + `SettingsController` + stat card en `Settings/Index.vue`); nueva migración `drop_pas_opportunity_timeout_setting` borra el row de `system_settings`. La resolución sincrónica (`coveragePreference → tryResolveQuoteById → resolveQuote`) y `ApiQuoteResolution` se conservan intactas. Efecto: una quote que nunca alcanza el paso de cobertura queda en `pending` (no se auto-resuelve) — comportamiento **aceptado y deliberado** en el modelo WhatsApp: si el cliente no elige cobertura no hay nada que cotizar, y la resolución real ocurre vía la estrategia `api`. El ciclo de vida de estados de la quote (incluido el destino de las que quedan en `pending`) se va a **rediseñar junto con el polling de `TaskList` de Visred** (ver V2-3) — ese será el momento de definir si `pending` necesita un estado terminal o expiración. Pint ✅ · PHPStan 0 ✅ · tests Quote verdes. _(sin commit aún)_
- **2026-06-03** — **Suite a verde: 24 → 0 fallos (264/264, 809 assertions).** Los "24 rojos pre-existentes" no eran un único problema de "AI sin mock" como se creía en el triage del 2026-06-03; al diagnosticarlos uno por uno resultaron **5 causas raíz distintas**, ninguna de lógica de negocio:
  1. **`ProcessConversationInbox` `TypeError` (4 tests).** Los mocks de `InsuranceOrchestrator::handle()` devolvían `['text', 'agent']` sin la clave `execution_log_ids`; el job hace `end($reply['execution_log_ids'])` ([ProcessConversationInbox.php:71](app/Jobs/ProcessConversationInbox.php:71)) → `null` → TypeError. **No se agregó defensa `?? []`**: el orquestador real **siempre** retorna la clave (contrato `array{text, agent, execution_log_ids: int[]}`; ambos `return` la incluyen y el path de error hace `throw`), así que era mock incompleto, no bug de prod. Fix = completar los 4 mocks.
  2. **`SendWhatsAppMessage` Mockery mismatch (3 tests).** Prod pasa `config('ai.default')` (`'deepseek'`) como último arg a `sendMessage`/`sendAudioMessage`; las expectativas `->with(...)` tenían un arg de menos. Fix = agregar `config('ai.default')`.
  3. **`IdentifyVehicle` 500 (varios).** El test mandaba `sessionUuid => 'test-session-uuid-vehicle'`, no es UUID válido y `quotes.session_uuid` es columna `uuid` de Postgres → `SQLSTATE[22P02]` → `server_error`/500. Fix = `Str::uuid()` en el `beforeEach`.
  4. **`ConversationShow` orden no-determinista (1 test).** `created_at` no está en `$fillable` de `Message`, el `create([... 'created_at' => ...])` del test se ignora y ambos mensajes quedan con el mismo timestamp; `orderBy('created_at')` sin desempate devolvía orden arbitrario. Fix de prod = `->orderBy('id')` como desempate determinista en `ConversationController::show`.
  5. **`CheckoutAgentPrompt` — archivo de prod destruido (ver abajo).**
  Cambios de prod: **2** — el desempate `orderBy('id')` y la **recuperación de `CheckoutAgent.md`**. El resto, fixes de test. Pint ✅ · PHPStan 0 ✅. _(sin commit aún)_
- **2026-06-03** — **🔴 Bug de fondo descubierto: `AgentPromptController` escribe prompts `.md` versionados desde un request HTTP, y eso destruyó `CheckoutAgent.md` en el repo.**
  - **Síntoma.** `tests/Feature/AI/CheckoutAgentPromptTest` fallaba afirmando que el prompt no contenía `check_coverage_rule`, `Fallback contrastado`, etc. El archivo en disco era el string `"promoted draft content sufficiently long to pass validation"` (1 línea), y `git show HEAD:...CheckoutAgent.md` confirmó que **el stub estaba commiteado** (entró en `b2e70e9`). El prompt real (203 líneas) se recuperó de `1504224` y se verificó contra todas las assertions.
  - **Causa raíz.** [`AgentPromptController::promoteDraft()`](app/Http/Controllers/Admin/AgentPromptController.php:153) y `::store()` llaman a `writePromptFile()` ([:324](app/Http/Controllers/Admin/AgentPromptController.php:324)), que hace `file_put_contents(resource_path('prompts/agents/{File}.md'), $content)` — una escritura cruda a un archivo **bajo control de versiones** disparada por una acción HTTP autenticada. `AgentPromptDraftFlowTest` ("promotes a draft to active") ejercita el controller real promoviendo un draft de `checkout_closer` con contenido stub → pisa `CheckoutAgent.md`. Como no había restore, el stub quedó en el working tree y se commiteó. Por eso el test estuvo rojo "desde siempre".
  - **Por qué es un problema más allá del test (4 puntos):**
    1. **Duplicación de fuente de verdad.** Según `CLAUDE.md`, el runtime sirve los prompts desde la tabla `agent_prompts` (`AgentPrompt::activeFor($key)`, cacheado con `rememberForever`); el `.md` es **solo fallback/seed**. Escribir el `.md` en cada promote es un side-effect de "sync al repo" que no es atómico con el write a la DB.
    2. **Mutación de artefactos en runtime.** En producción, una acción de un admin reescribe un archivo del *deploy* — no sobrevive a un redeploy, y genera drift entre entornos y entre disco↔git.
    3. **Sin seam fakeable.** `file_put_contents` a un `resource_path` absoluto no lo intercepta `Storage::fake()`; el único blindaje posible desde el test es backup/restore manual (lo que se aplicó como mitigación interina).
    4. **Sin atomicidad ni lock.** Un write fallido deja el prompt a medio escribir; no hay bloqueo ante promotes concurrentes.
  - **Mitigación aplicada (interina).** `AgentPromptDraftFlowTest` ahora respalda el `.md` real en `beforeEach` y lo restaura en `afterEach`, para que el stub de los tests no vuelva a filtrarse al repo. Cierra la hemorragia pero **no** arregla la causa.
  - **Propuesta de fondo — dos opciones:**
    - **Solución A (mínima, recomendada): eliminar la escritura del archivo.** La DB ya es la fuente de verdad y está cacheada; el `.md` es fallback documentado. `promoteDraft`/`store` no deberían escribir el archivo: se borra `writePromptFile()`. La sincronización repo→`.md` (para devs / versionado) pasa a ser un comando artisan explícito y opt-in (p.ej. `prompts:export`, reutilizando el snippet de sync ya documentado en `CLAUDE.md`), corrido a propósito, no como efecto colateral de un HTTP. *Pros:* elimina el vector de pérdida de datos, no muta archivos trackeados en runtime, sin drift entre entornos, tests sin necesidad de blindaje. *Contras:* el `.md` del repo deja de auto-actualizarse cuando un admin promueve en prod (aceptable: ya estaba derivando y la DB es canónica).
    - **Solución B (si se quiere conservar el espejo en archivo): escribir vía `Storage` a un disco no-versionado y fakeable.** Definir un disco `prompts` (p.ej. `storage/app/prompts`, o R2 como el resto de media) y reemplazar `file_put_contents(resource_path(...))` por `Storage::disk('prompts')->put(...)`; la lectura cae a ese disco y luego al seed del repo. *Pros:* mantiene el "persistir una copia"; `Storage::fake('prompts')` hace los tests herméticos; nunca toca archivos bajo git; vive en una ubicación realmente escribible. *Contras:* más piezas; el seed `.md` y las copias vivas pueden divergir (pero esa divergencia ya es inocua y fuera de git).
  - **Recomendación:** **Solución A** — alinea con el diseño documentado ("DB = fuente de verdad, `.md` = fallback") y remueve el modo de falla de raíz con el menor código. Reservar B solo si producto quiere que el panel admin empuje cambios de prompt de vuelta a un artefacto desplegable. _(sin commit aún)_
- **2026-06-03** — **Solución A aplicada: eliminado el clobber de prompts `.md` desde HTTP.** Borrado `AgentPromptController::writePromptFile()` y sus dos llamadas (`promoteDraft` [:153], `store` [:197]). `promoteDraft`/`store` ya **no** escriben ningún archivo: la DB (`agent_prompts`, cacheada con `rememberForever`) es la única fuente de verdad en runtime y el `.md` queda como seed/fallback (lo sigue cargando `createDraft` cuando no hay versión activa, vía `resolvePromptPath`). Removida la mitigación interina del test (`beforeEach` backup + `afterEach` restore del `.md`) — ya no hay escritura a disco que blindar — y agregado un **header de HISTORIA** en `AgentPromptDraftFlowTest` que narra el incidente (clobber → stub commiteado en `b2e70e9` → recuperado de `1504224`) para que nadie reintroduzca escrituras a `resource_path()` desde un controller. La dirección `.md → DB` sigue disponible vía el comando existente `agent:sync-prompts`; **no** se agregó `prompts:export` (DB→`.md`) — opt-in para más adelante si se quiere versionar cambios hechos desde el panel. `AgentPromptDraftFlowTest` (15) + `CheckoutAgentPromptTest` (7) = 22 verdes. _(sin commit aún)_
- **2026-06-02** — **Extirpación de `pas-web`.** Auditoría exhaustiva (`pas-web`, `pas_web`, `PasWeb`, `PAS_WEB`, `external_quote_id`, `quote_provider_refs`, `poliza_api`): **cero huella en código, config y `.env`** de `workflow-assistant` — ya estaba desacoplado de facto (la estrategia `api` usa `QuotingEngine` mock, no pas-web; `quote_provider_refs`/`poliza_api` son genéricos de proveedor). Todas las menciones vivían en docs: actualizadas las convenciones de canal y narrativas en `docs/README.md` y `docs/v2/01`–`07` a `legacy, extirpado`, para `pas-web` **y** `pas_mobile` (criterio unificado: ambos permanecen documentados, marcados legacy + extirpado). `docs/v1/adr/001-quote-provider-refs.md` se deja **intacta** (archivo histórico; el bug ocurrió con pas-web y el invariante sigue vigente para Visred). _(sin commit aún)_
