# Plan — Integración Visred API (cotización + emisión real)

> **Estado:** aprobado por el usuario (2026-06-06). Copia canónica del plan en el repo
> (el original de plan-mode vive fuera del repo). Tracking de ejecución en
> [`ROADMAP.md`](ROADMAP.md) (este mismo directorio). Modelo de dominio: [`../10-modelo-dominio-cotizacion-emision.md`](../10-modelo-dominio-cotizacion-emision.md). Contrato Visred: [`../08-visred-quote-adapter.md`](../08-visred-quote-adapter.md).
> **Rama:** `prepare-proyect-for-API-refactor`.

## Context

Hoy el backend **simula** cotización y emisión:
- `app/Services/QuotingEngine.php` hace `sleep(30)` + un catálogo determinístico (4 compañías hardcodeadas).
- `app/Services/PolizaEmisionService.php` es un skeleton que devuelve `['status' => 'pending_api_implementation']`.

El objetivo de la cirugía v2 es reemplazar ese rol con **Visred** (cotiza y emite sin intervención humana). Hay credenciales de sandbox disponibles, así que el plan apunta a **tocar los endpoints reales** de Visred, no a un mock perpetuo. El mock no se borra: queda como una implementación más detrás del puerto, elegible por env (mismo patrón que el seam `WhatsAppDispatcher`).

**Decisiones tomadas (sesión de plan):**
1. Credenciales sandbox: **disponibles** → verificación E2E real en scope.
2. Puente vehículo→`version_id`: **fuera del happy path** → Fase 6 documentada aparte.
3. Polling: **sincrónico acotado**, bien documentado.
4. **Límite de contexto:** la **compañía (vía Visred) es el system of record** de la póliza, sus endosos, estado y documentos — **on-demand, última versión**. MANGO = gestión; no modela endosos ni cachea proyección autoritativa.
5. Persistencia de emisión: **referencia mínima + on-demand** (`presale_id`, `policy_number`, `company_id`, `product_id` ligados a `Quote`+`Risk`).
6. **Modelar primero, implementar después — modelo AGNÓSTICO de proveedor.** El dominio no importa Visred; es un Adapter detrás de un puerto. Doc de modelado = gate (Fase 0), ya aprobado.

---

## Contrato Visred (verificado contra el schema + smoke-test live)

**Base URL sandbox (verificado live 2026-06-06):** `https://sandbox-api.visred.com.ar` — endpoints en `/v1/...` (sin prefijo `/api`).

**Auth (identidad de servicio):**
- `POST /v1/accounts/token/` `{username,password}` → `200 {access, refresh}` (JWT). **✅ Verificado live.**
- `POST /v1/accounts/token/refresh/` `{refresh}` → `{access}`.
- Header `Authorization: Bearer <access>` en cada request.

**Cotización vehículos (asíncrona):**
- `POST /v1/patrimoniales/vehicles/cotizar/` body `QuotationVehicleRequest` (`product_id`, `address.zip_code`, `person_holder.document_number`, `vehicle.{version_id, year, zero_kilometers, fuel_type_id, insured_amount_fuel}`) → `200 TaskList { tasks_list: [TaskItem{task_id, company_id, product_id, ...}] }` (**una task por compañía**).
- Poll `GET /v1/tasks/{task_id}/` → `TaskDTO { status: PENDING|SUCCESS|FAILURE, ready, result }`. En SUCCESS, `result.quotation_results[]` = varias `APIBaseQuotationResultDTO` (una por cobertura): `{quotation_result_id, cover{id,name,description}, fee, installments, franchise, insured_amount, payment_method_id, features[], require_inspection_before_emission}`.

**Emisión vehículos (asíncrona):**
- `POST /v1/patrimoniales/vehicles/emitir/` body `PreSaleVehicleRequest` (`quotation_result_id` + `person_holder`/`address`/`vehicle`/`payment` + opc. `discount_id`, `inspections`) → `200 TaskItem`.
- Poll → `result` = `APIBasePreSaleResultDTO { presale_id, proposal_number, policy_number, status, require_inspection_after_emission, tasks_list[] }`.
- Inspección post-emisión: `POST /v1/patrimoniales/vehicles/emitir/{presale_id}/inspeccion/`.

**Descarga documento (síncrona):** `POST /v1/documents/` `{presale_id, product_id, task_type_id:"download-poliza"}` → `TaskDTO { result.url }` (URL pre-firmada).

**Envelope de error:** `{ success:false, error:{ message, code, field_errors } }` — `validation_error`(400) · `not_authenticated`(401) · `permission_denied`(403) · `not_found`(404) · `conflict`(409) · `external_service_unavailable`(503).

**Gotchas:** rutas de params en **singular** (`/params/task-type/`). `X-Mock-Scenario` (`success|error_400|error_500`) **solo sandbox**; en prod → 403.

---

## Arquitectura — el seam (espeja `WhatsAppDispatcher`)

```
coverage_preference → tryResolveQuoteById → QuoteService::resolveQuote
   → ApiQuoteResolution::resolve → QuotationProvider (interface)
                                      ├─ QuotingEngine            (mock)
                                      └─ VisredQuotationProvider  (real → VisredClient)
   → QuoteRepository::saveResults → quote_alternatives + quote_provider_refs
```
- Puerto = firma neutra (array-shape actual de `generateAlternatives`).
- **Simetría en emisión:** puerto `EmissionProvider` (`emit(EmissionRequest): EmissionResult`), `VisredEmissionAdapter` detrás.
- **Principio:** el dominio nunca importa una clase Visred; mapeos solo en adapters. Refuerza ADR-001.
- **System of Record vs Engagement:** la compañía (Visred) es dueña de póliza/endosos/estado/documentos (on-demand); MANGO guarda referencia mínima.
- Selección por config (`config/visred.php`): `quotation_provider`/`emission_provider` = `mock|visred`, bind en `AppServiceProvider`.

---

## Contrato de polling (cómo sabemos que "están todas")

`cotizar/` devuelve la lista **completa** de tasks upfront (una por compañía) — conjunto fijo; nada llega fuera de `tasks_list`. Loop sincrónico acotado: poll cada `task_id` hasta terminal (`SUCCESS`/`FAILURE`) o budget (`VISRED_POLL_BUDGET`, ~120s); tolerante a parcial. **⚠️ Invariante:** `poll_budget + overhead_LLM < timeout del job` (en WhatsApp, `ProcessConversationInbox` = 180s). Riesgo abierto: puede forzar async.

---

## Persistencia de emisión — referencia mínima + on-demand

`polizas`/`risks` ya existen (canal compartido con mango-mobile). Tras emitir: **find-or-create `Risk`** (ancla) + **referencia mínima** (`presale_id`/`policy_number`/`company_id`/`product_id`) ligada a `Risk`+`Quote` (`Quote.status='poliza_emitida'`). Estado/cobertura/documentos = on-demand desde Visred (cache-aside ~2h en backend; documentos PDF siempre frescos al descargar). **Sin store de endosos.**

**⚠️ Tensión (Fase 0/5):** `polizas` actual es rica con columnas NOT NULL pensada para cartera autocontenida que lee mango-mobile. "Referencia mínima" choca → decidir repurpose vs tabla nueva, coordinando con mango-mobile.

---

## Plan por fases (gate: Fase 0 aprobada antes de implementar)

- **Fase 0 — Modelo de dominio agnóstico (DOC, gate).** ✅ `docs/v2/10`. Tipos neutros, puertos, modelo temporal, límite de contexto, reconciliaciones.
- **Fase 1 — Extraer `QuotationProvider`** (refactor sin cambio de comportamiento). ✅ `app/Contracts/QuotationProvider.php`; `QuotingEngine implements`; `ApiQuoteResolution` depende del puerto; bind en `AppServiceProvider` (default mock).
- **Fase 2 — `VisredClient` (HTTP + JWT).** login/refresh token cacheado, refresh-on-401, Bearer, normaliza envelope error → `VisredApiException`, `X-Mock-Scenario` solo sandbox. `config/visred.php` + `.env`. Tests `Http::fake` + fixtures.
- **Fase 3 — `VisredQuotationProvider`** (cotizar + polling). Entrada `RiskSnapshot`→`QuotationVehicleRequest`; salida **aplanado company→covers**→`parsed_alternatives`; `cover`→`normalized_grade` (mapeo en adapter); `quotation_result_id` por-alternativa en `quote_provider_refs` (ADR-001).
  - **⚠️ Corrección de arquitectura (2026-06-07):** el `version_id` **NO** va en una columna del dominio. `risk_snapshots.visred_version_id` queda **descartado** (acoplaría `RiskSnapshot` a Visred). El adapter obtiene el `version_id` **ya resuelto** vía el puerto agnóstico **`VehicleCatalogResolver`** (su diseño se cierra en una ventana aparte — ver [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md)), que lee de su propio backing store (decisión A/B abierta). La firma `generateAlternatives(RiskSnapshot): array` y el array-shape para `saveResults` **no cambian**. Sin migración sobre `risk_snapshots`.
- **Fase 4 — Config seam + E2E cotización.** `QUOTATION_PROVIDER=visred`; cotización end-to-end real.
- **Fase 5 — `EmissionProvider` + emisión real.** Puerto + `VisredEmissionAdapter`; `PolizaEmisionService` orquesta (find-or-create Risk + referencia mínima); inspección before/after con fotos R2; documentos on-demand. **Gap:** `person_holder` (birthdate/sex_id/tax_condition_id/person_type_id/document_type_id) que el checkout no captura hoy.
- **Fase 6 — `VehicleCatalogResolver`** (aparte). Traduce el auto (hechos de dominio capturados por el chat) → token del catálogo del proveedor. **Dos responsabilidades separadas:** consulta de catálogo (determinística, por proveedor: cadena de params) + desambiguación (LLM, agnóstica, sobre candidatos neutros). Agnóstico de canal: depende solo del dominio, **separado e independiente** del stack de identificación del chat (intocable). Persiste el token en el backing store del resolver (A/B), **nunca** en una columna de dominio. Diseño en [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md) — se cierra en ventana aparte antes de implementar.

---

## Verificación
- Por fase: `composer test` (filtrado), `composer analyse` (PHPStan 0 nuevos), Pint (hook).
- E2E real (Fases 4/5) contra sandbox con `QUOTATION_PROVIDER=visred`.
- Tests herméticos: `Http::fake` + fixtures para todos los caminos.

## Decisiones abiertas (model §9 — recomendadas aceptadas vía "dale"; revisitar en Fase 3/5)
1. `Risk` sin versionar (identidad + estado actual; historia comercial = `risk_snapshots`).
2. Repurpose de `polizas` a referencia (coordinar mango-mobile).
3. TTL cache cartera ~2h.
4. Dedup `Risk` por patente (¿chasis cuando esté?).
5. `person_holder`: capturar en checkout vs defaults (bloquea Fase 5).
