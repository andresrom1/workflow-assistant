# ROADMAP — Integración Visred (cotización + emisión)

> Tracking de ejecución **específico de este plan**. El plan canónico está al lado en [`PLAN.md`](PLAN.md).
> Para el estado global del backend ver [`../../../ROADMAP.md`](../../../ROADMAP.md) (filas V2-M, V2-1…V2-5).
> Modelo de dominio (gate): [`../10-modelo-dominio-cotizacion-emision.md`](../10-modelo-dominio-cotizacion-emision.md). Contrato Visred: [`../08-visred-quote-adapter.md`](../08-visred-quote-adapter.md).
> **Rama:** `prepare-proyect-for-API-refactor`.

**Leyenda:** ⬜ Sin empezar · 🚧 En progreso · ✅ Completa · ⏸️ Bloqueada

---

## Hechos verificados (sandbox)

| Hecho | Valor | Estado |
|---|---|---|
| Base URL sandbox | `https://sandbox-api.visred.com.ar` (endpoints en `/v1/...`, **sin** `/api`) | ✅ verificado live |
| Auth | `POST /v1/accounts/token/` `{username,password}` → `200 {access, refresh}` | ✅ smoke-test OK (2026-06-07) |
| Refresh | `POST /v1/accounts/token/refresh/` `{refresh}` → `200 {access}` | ✅ smoke-test del `VisredClient` OK (2026-06-07) |
| Credenciales | en `workflow-assistant/.env` (`VISRED_BASE_URL`/`VISRED_USERNAME`/`VISRED_PASSWORD`) — **no commiteadas** | ✅ |
| Egress de red | OK (visred.com.ar → 200) | ✅ |

---

## Estado por fase

| Fase | Tema | Estado | Nota |
|------|------|--------|------|
| 0 | Modelo de dominio agnóstico (gate) | ✅ | `docs/v2/10`. Aprobado por el usuario (2026-06-06). |
| 1 | Extraer puerto `QuotationProvider` | ✅ | Refactor sin cambio de comportamiento. PHPStan 0 · suite 274/274 (2026-06-06). |
| — | Smoke-test auth real | ✅ | Login contra sandbox → 200 con access+refresh (2026-06-07). |
| 2 | `VisredClient` (HTTP + JWT) | ✅ | login/refresh cacheado (Cache server-side), refresh-on-401 (reintenta 1 vez; si falla → re-login), Bearer + Accept, `VisredApiException` (status/code/field_errors), `X-Mock-Scenario` solo sandbox, `config/visred.php`. Tests `Http::fake` (14) + smoke-test live. PHPStan 0 · suite 288/288 (2026-06-07). |
| 3 | `VisredQuotationProvider` + polling | ✅ | Lee el token de `risk_provider_refs`; `RiskSnapshot`→request; `cotizar/`→TaskList; polling acotado (`Sleep`, tolerante a parcial/budget); aplanado company→covers→`parsed_alternatives`; `cover`→`normalized_grade` (solo en el adapter). Firma intacta. Tests herméticos (5). **`quotation_result_id` por-alternativa diferido a Fase 5** (cada alt lo lleva en `external_quote_id`; `saveResults` intacto). |
| 4 | Bind directo Visred + E2E cotización | ✅ | Mock (`QuotingEngine`) **eliminado**; `QuotationProvider`→`VisredQuotationProvider` bind directo (real-always, sin selector); `flatten`/`mapCover` reconciliados al shape real (`result.company_id`+`quotation_results[]`), nombre de compañía vía `/discovery/companies/` (en vivo, sin cache); filtro de coberturas placeholder/inactivas. Smoke E2E real OK (13 compañías, 13.8s). Suite 304/304, PHPStan 0. |
| 5 | `EmissionProvider` + emisión real | ⬜ | find-or-create `Risk` + referencia mínima; inspección; documentos on-demand. **Gap `person_holder`.** Acá se persiste el `quotation_result_id` por-alternativa (lo necesita emitir la elegida). |
| 6 | `VehicleCatalogResolver` (gate de quotability) | ✅ | Implementado **aplanado**: puerto agnóstico `Quotability` (la única abstracción que ve el canal), `VisredQuotabilityResolver` (árbol de 5 GETs + desambiguación Tier 1 léxico / Tier 2 `DisambiguationAgent` LLM, beam por groups), tri-estado `QuotabilityResult` (Quotable/NeedsFact/NotQuotable). Refina `Vehicle.version` + token → `risk_provider_refs`. Tests herméticos (6 resolver + 3 gate). |

---

## Decisiones abiertas (model §9)

Aceptadas en sus valores recomendados vía "dale"; **revisitar al llegar a Fase 3/5**:
1. `Risk` sin versionar (identidad + estado actual; historia comercial = cadena `risk_snapshots`).
2. Repurpose de `polizas` a tabla de referencia — **coordinar con mango-mobile**.
3. TTL cache de cartera ~2h.
4. Dedup `Risk` por patente (¿exigir chasis cuando esté?).
5. `person_holder`: capturar en checkout vs defaults (bloquea Fase 5).

---

## Riesgos abiertos
- ~~**Budget de polling vs timeout del job de IA** (120s vs 180s) — puede forzar async.~~ **Resuelto (2026-06-07):** smoke real midió **13.8s** para que **13 compañías** llegaran a terminal (7 SUCCESS), holgado bajo el budget de 120s y el timeout de 180s. No fuerza async. Muestra única (auto año 1997); revisar con un smoke periódico si cambian los tiempos del proveedor.
- **`polizas` rica vs referencia mínima** — cambio de modelo compartido con mango-mobile.
- **`version_id`** — token opaco del catálogo, resuelto por el `VehicleCatalogResolver` en identify-vehicle y guardado en backing store A (NO en columna de dominio). Diseño cerrado, ver [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md) §7–§10.
- **Catálogo Visred = dato vivo** — `version_id` opacos pueden rotar; consulta en vivo + cache TTL, sin bakear valores como fixtures. Drift se cubre con smoke/contract test contra sandbox.
- **Catálogo origin-aware** — depende del usuario autenticado; confirmar usuario de servicio vs personal.

---

## Bitácora

- **2026-06-07** — **Fase 4 implementada (bind directo a Visred + E2E real). ✅** Suite **304/304**, PHPStan **0**, Pint.
  - **Mock eliminado (reversión de decisión).** Se borró `app/Services/QuotingEngine.php` (700 líneas, catálogo hardcodeado). Esto **revierte** "el mock no se borra" de [`PLAN.md`](PLAN.md): el mock era andamiaje hasta tener la API; ya está la API → mantenerlo era un punto débil de cara a producción (decisión del usuario). `QuotationProvider`→`VisredQuotationProvider` **bind directo** en `AppServiceProvider` (real-always, mismo criterio que `Quotability`; **sin** selector por config). En tests, `TestCase` bindea `StubQuotationProvider` (espejo de `StubQuotability`).
  - **Sin selector de config.** Se eliminaron `quotation_provider` y `emission_provider` de `config/visred.php` + `.env.example` (config muerta: nadie las leía). Visred es **EL** proveedor, implícito. **Futuro:** el día que entre un 2º proveedor → una sola clave `INSURANCE_PROVIDER` (array), gobernando cotización **y** emisión juntas (no se pueden mezclar). YAGNI hasta entonces.
  - **Shape real confirmado contra el sandbox** (smoke live): `GET /v1/tasks/{id}/` → `result.company_id` (slug) + `result.quotation_results[]` (NO `company`/`covers` como se había asumido). `flatten`/`mapCover` reescritos. El **nombre de compañía** se resuelve por `company_id` vía `GET /v1/discovery/companies/` (`rus`→"Rio Uruguay") — **consultado en vivo en cada cotización, sin cache** (el listado cambia poco y una llamada extra es despreciable vs cotizar+polling; sin el refresco "al pedo" de un TTL fijo). La traducción id→nombre vive solo en el adapter.
  - **Filtro de coberturas:** Visred devuelve filas placeholder (`cover.id`/`name` vacíos) e ítems discontinuados (`active=false`). `mapCover` descarta lo no presentable: solo `cover.name` presente **y** `active===true`.
  - **Limitación conocida:** `normalized_grade` es léxico (sobre `cover.id`+`name`); códigos de compañía como `Sigma`/`C1-80`/`B80` caen a `basic` por default. Best-effort en Fase 4; mejora (mapa por `cover.id`) es su propio tema.
  - **Pendiente río abajo (no Fase 4):** `company_id` de Visred (`rus`) ≠ slug de `coverage_documents` (`rio-uruguay`) → el RAG de coberturas no encontraría docs. Revisar al tocar el coverage-check.
  - **Smoke E2E real** (tinker, sin secretos): catálogo→version_id→cotizar→polling de 13 compañías a terminal en **13.8s** (7 SUCCESS), `company_id=triunfo` resuelto a "Triunfo", 4 presentables. Confirma el chain live extremo a extremo.
  - **Tests:** fixtures de los 5 tests herméticos reabiertos al shape real (+ `companiesResponse()`); +1 test de filtro placeholder/inactivas; +1 E2E hermético (`ApiQuoteResolution`→`saveResults`→alternativas + `quote_provider_refs`). `quotation_result_id` por-alternativa sigue **diferido a Fase 5** (cada alt en `external_quote_id`).
- **2026-06-07** — **Fases 3 + 6 implementadas (resolver de quotability + adapter de cotización Visred). ✅** Suite **302/302**, PHPStan **0**, Pint. Diseño **aplanado** respecto del handoff (a pedido del usuario: "exceso de ingeniería, más llano"):
  - **El canal NO conoce el catálogo.** `identify_vehicle` depende de un puerto agnóstico **`Quotability::check(Vehicle): QuotabilityResult`** y solo lee el tri-estado. Se descartó el puerto separado `VehicleCatalogResolver` + VOs (`VehicleQuery`/`CandidateVersion`): catálogo + desambiguación viven **adentro** de `VisredQuotabilityResolver` (un solo proveedor, YAGNI; el Tier 2 `DisambiguationAgent` queda extraíble si entra un 2º).
  - **Tri-estado** `QuotabilityResult`: `Quotable(resolvedVersion, provider, externalRef)` / `NeedsFact(missingFact, options)` / `NotQuotable`. El `provider`/`externalRef` (token) es opaco: el adapter lo usa SOLO para persistir; **nunca** entra a `ai_state` ni a un mensaje (test lo verifica).
  - **`VisredQuotabilityResolver`**: árbol de 5 GETs (rutas singulares, shapes heterogéneos normalizados a {ref,label}), cascada Tier 1a igualdad exacta (candado del re-cotizar) → Tier 1b subconjunto léxico → Tier 2 LLM (`DisambiguationAgent`, parsea `version_name`, fakeable). Beam por groups (`matchAllRefs`). Error de Visred → rama honesta `NotQuotable`. Refina `Vehicle.version` (dominio) en Resolved.
  - **Gate en `WhatsAppAdapter::identifyVehicle`** (capa de canal, sirve WhatsApp + web-chat): `Quotable`→crea Quote + persiste token en `risk_provider_refs`(snapshot, provider, ref); `NeedsFact`→pide el hecho de dominio, sin prometer; `NotQuotable`→rama honesta. `vehicle_identified` (identidad) sigue siendo NLU, separado de quotability.
  - **`risk_provider_refs`** (migración nueva, genérica: `risk_snapshot_id`+`provider`+`external_vehicle_ref`, unique). Relación genérica `RiskSnapshot::providerRefs()`. **Sin** columna de proveedor en el dominio.
  - **`VisredQuotationProvider`**: lee el token, `cotizar/`→polling acotado (`Sleep`, tolerante a FAILURE parcial y budget agotado), aplana company→covers, `normalized_grade` mapeado solo acá. `generateAlternatives(RiskSnapshot): array` **sin cambio de firma**.
  - **Decisión: "resolver real siempre" en producción** (bind `Quotability→VisredQuotabilityResolver` en `AppServiceProvider`, independiente del seam de cotización que sigue en `QuotingEngine`). En tests, `TestCase` bindea por defecto `StubQuotability` (quotable, sin red); los tests de quotability usan el resolver real con `Http::fake` + `DisambiguationAgent::fake`.
  - **Diferido a Fase 5:** persistencia del `quotation_result_id` por-alternativa (lo consume la emisión). En Fase 3 cada alternativa lo lleva en `external_quote_id`; `saveResults`/`quote_provider_refs` intactos.
  - **NLU intocable** (no se tocó `VehicleIdentifierAgent`/`IdentifyVehicleTool`/`VehicleIdentificationService`). **Seam de cotización NO flipeado** (eso es Fase 4).
- **2026-06-07** — **Diseño del `VehicleCatalogResolver` (Fase 6) mayormente cerrado (sin código).** Sesión de arquitectura sobre [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md). Decisiones:
  - **Hogar + trigger:** la resolución corre en **identify-vehicle, con el cliente presente**, pero el **NLU no llama al resolver** — lo dispara la **capa de canal** vía una app action agnóstica `ResolveVehicleCatalogRef`. Dirección `canal → action → resolver → dominio`; la flecha prohibida `VehicleIdentifier* → resolver` se respeta. (cierra sub-temas #1/#2)
  - **Gate por *quotability* agnóstica, no por el token:** avanzar sin match contra el proveedor genera **promesa rota** ("dame un momento que cotizo…" que nunca pasa). Identify-vehicle **sí resuelve contra proveedores** antes de avanzar, pero el gate cuelga del **tri-estado agnóstico** (Resolved→avanza / Ambiguous→pide hecho de dominio / NotFound→rama honesta, sin promesa falsa). `vehicle_identified` (identity) ≠ quotability; el nombre del proveedor y el `version_id` **nunca** entran a `ai_state`. La ambigüedad se reencuadra como **hecho de dominio faltante** (p.ej. transmisión), no como token.
  - **Cascada de 3 tiers:** exacto/léxico → LLM → preguntar al cliente. Por nivel: marca/año Tier 1; modelo exacto-normalizado + LLM de fallback (**embedding proscripto como árbitro** en numéricos `208`/`2008`, pero el LLM sí los diferencia); versión es el laburo real. Modelo↔trim entrelazados → navegación **beam** (una sola pregunta al cliente).
  - **Catálogo Visred verificado live:** árbol de 5 GETs (`vehicle-types→brands→years→groups→versions`), año **estructural**. **Shapes heterogéneos** entre niveles (08 estaba mal con `BaseParam[]` para todos) → normalizar a `{ref,label}`. **Versiones SIN atributos estructurados** → parsear `version_name` (motor/trim/transmisión). Backing store **C+A** (B opcional como cache del árbol, que cambia lento).
  - **Fixtures:** se descartó bakear el catálogo como JSON (dato vivo, tokens rotables). Si hace falta, sample mínimo de shape; drift vía smoke/contract test. Se borró el dir `tests/Fixtures/Visred/catalog/` que se había empezado.
  - **Artefactos:** [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md) §7–§10 nuevas + §0/§5 actualizadas. **Cero código.** Desbloquea Fase 3 una vez implementado el resolver.
- **2026-06-07** — **Fase 3 frenada para corrección de arquitectura (sin código).** Al arrancar, la migración `risk_snapshots.visred_version_id` se rechazó: acopla el dominio (`RiskSnapshot`) a Visred (viola el modelo agnóstico / ADR-001). Decisiones de la sesión:
  - **El `version_id` NO es atributo de dominio** — es un token opaco del catálogo del proveedor, propiedad de `(auto, año, proveedor)`, necesario **solo en quote-time**. Se resuelve detrás de un puerto agnóstico **`VehicleCatalogResolver`**; el adapter de cotización lo lee **ya resuelto** del backing store del resolver. La firma `generateAlternatives(RiskSnapshot): array` no cambia. **Sin migración sobre `risk_snapshots`.** El stub de migración creado por error se borró.
  - **Frontera dura:** el stack de identificación del chat (`VehicleIdentifierAgent`/`IdentifyVehicleTool`/`VehicleIdentificationService`) es **solo NLU, intocable**; el resolver es un componente **separado, agnóstico de canal** (depende solo del dominio, nunca del chat/orquestador). Se rompió esa frontera dos veces en la discusión → se blindó con una **regla general de desacople en `CLAUDE.md`** (no solo para esas 3 clases).
  - **Resolver = 2 responsabilidades separadas:** consulta de catálogo (determinística, por proveedor) + desambiguación (LLM, agnóstica). *Un desambiguador, N adaptadores.* La versión real desambiguada ("Active VTI") **sí es dominio** (`risk_snapshots.version`); solo el token opaco se aísla.
  - **Backing store ABIERTO:** A (`risk_provider_refs`, por snapshot = la decisión) vs B (`provider_vehicle_versions`, clave natural = cache del catálogo); no excluyentes. Lean del usuario: **C+A**, sin cerrar.
  - **Artefactos:** [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md) (handoff del resolver), correcciones en [`PLAN.md`](PLAN.md) (Fase 3/6) y [`../10-…`](../10-modelo-dominio-cotizacion-emision.md) §8/§9, regla en `CLAUDE.md`. **Cero código de Fase 3 escrito.**
- **2026-06-07** — **Fase 2: `VisredClient` (HTTP + JWT) ✅.** Cliente agnóstico de proveedor que espeja el patrón de `WhatsAppOutboundService` (`Http::acceptJson()->timeout()->retry()`):
  - `app/Services/Visred/VisredClient.php` — `get()`/`post()` autenticados; login (`POST /v1/accounts/token/`) + refresh (`/refresh/`) con tokens cacheados server-side (Cache, no en estado de instancia → sirve transient); refresh-on-401 (refresca y reintenta **una** vez; si el refresh falla, re-login con credenciales de servicio); `Authorization: Bearer` + `Accept: application/json` en cada request; retry transitorio (red/5xx/429, **no** 4xx) vía `when`/`throw:false`; `X-Mock-Scenario` adjuntado **solo** si `config('visred.sandbox')`.
  - `app/Exceptions/Visred/VisredApiException.php` — normaliza el envelope `{success:false,error:{message,code,field_errors}}` a una excepción tipada (`status()`/`errorCode()`/`fieldErrors()`); capa anticorrupción (ADR-001): el dominio nunca la ve. Falla de red → `503`/`external_service_unavailable`.
  - `config/visred.php` (espeja `config/whatsapp.php`): `quotation_provider`/`emission_provider` (`mock` default), `base_url`, `username`/`password`, `timeout`, `poll_budget`/`poll_interval`, `sandbox`. Claves `VISRED_*`/`MANGO_*` agregadas a `.env.example` (placeholders, sin secretos).
  - **Tests herméticos** `tests/Feature/Services/Visred/VisredClientTest.php` (14) + fixtures `tests/Fixtures/Visred/` (login, refresh, 401→refresh→reintento, re-login, cada código 400/401/403/404/409/503, field_errors, gating de `X-Mock-Scenario`, falla de red).
  - **Smoke-test REAL** del `VisredClient` contra el sandbox (login + refresh) vía `tinker`: `login_ok access_len=232 refresh_present=yes refreshed_access_len=232` (sin imprimir secretos). Confirma que login y refresh funcionan extremo a extremo a través del cliente.
  - Cierre: Pint · PHPStan **0 errores** (level 5) · suite **288/288** (882 assertions). **NO** se tocó el seam de selección (sigue bind a `QuotingEngine`) — eso es Fase 4.
- **2026-06-07** — **Smoke-test de auth real ✅.** Tras corregir el base URL a `https://sandbox-api.visred.com.ar` (el host `sandbox.visred.com` no resolvía), `POST /v1/accounts/token/` con las credenciales de `.env` devolvió `200` con `access` + `refresh`. Confirma credenciales válidas, base URL y endpoint. Credenciales en `.env` (no commiteadas). Egress de red OK.
- **2026-06-06** — **Fase 1: puerto `QuotationProvider` extraído** (refactor sin cambio de comportamiento). `app/Contracts/QuotationProvider.php`; `QuotingEngine implements`; `ApiQuoteResolution` depende del puerto; bind `→ QuotingEngine` (mock, default) en `AppServiceProvider`. PHPStan 0 · Pint · suite 274/274 (845 assertions).
- **2026-06-06** — **Fase 0: modelo de dominio (gate) escrito y aprobado.** `docs/v2/10-modelo-dominio-cotizacion-emision.md`: modelo agnóstico de proveedor, límites de contexto (compañía = system of record), referencia mínima + on-demand, `Risk` sin versionar, repurpose de `polizas`. Aprobado por el usuario.

---

## Próximo paso

**Fase 5 — `EmissionProvider` + emisión real.**
- Puerto `EmissionProvider` + `VisredEmissionProvider` (bind directo, real-always, mismo criterio que cotización/quotability). `PolizaEmisionService` (hoy skeleton) orquesta: find-or-create `Risk` + referencia mínima (`presale_id`/`policy_number`/`company_id`/`product_id`); inspección before/after con fotos R2; documentos on-demand.
- **Acá se persiste el `quotation_result_id` por-alternativa** (lo necesita emitir la elegida). En cotización cada alt ya lo lleva en `external_quote_id`.
- **Gap abierto — `person_holder` vs checkout actual** (decisión §9.5). El checkout (`resources/js/pages/Checkout/Show.vue`) captura: step1 `nombre, dni, email, telefono, domicilio_*`; step2 `cc_*` (pago); step3 `vehiculo_uso/nro_chasis/nro_motor`. Mapeo contra lo que pide Visred:
  - **Cubierto:** `document_number` ← `dni`; `name` ← `nombre`.
  - **FALTAN, no defaulteables (hay que capturar):** `birthdate`, `sex_id`, `tax_condition_id` (condición IVA/AFIP).
  - **Defaulteables para auto/consumidor (confirmar con Visred):** `person_type_id` → persona física; `document_type_id` → DNI.
  - **Deuda anotada en código:** comentario `DEUDA (Visred emisión, Fase 5)` arriba del `form` en `Checkout/Show.vue`. Hasta capturar los 3 faltantes, la emisión real queda bloqueada.

Cotización **ya corre real** (bind directo a Visred, mock eliminado); el resolver de quotability **ya** corre real en identify-vehicle.

Ver [`RESOLVER-DESIGN.md`](RESOLVER-DESIGN.md) §7–§10, [`../08-visred-quote-adapter.md`](../08-visred-quote-adapter.md) §§2/3/4/5.
