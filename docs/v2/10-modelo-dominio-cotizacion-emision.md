# 10 — Modelo de dominio: cotización y emisión (agnóstico de proveedor)

> **Estado:** Fase 0 de la integración Visred — **documento-gate**. Se escribe y se aprueba *antes* de cualquier código (ver `ROADMAP.md`, Cirugía v2).
> **Ámbito:** el modelo de dominio de MANGO para cotizar y emitir, **sin acoplarse a Visred**. El contrato Visred verificado vive en [`08-visred-quote-adapter.md`](08-visred-quote-adapter.md); acá definimos el lado MANGO y el seam.
> **Principio rector:** el dominio **nunca importa una clase de Visred**. Visred es *un* Adapter detrás de un puerto; mañana puede entrar un 2º proveedor sin tocar el dominio.

---

## 0. Por qué este documento

La cotización/emisión hoy es mock ([`QuotingEngine`](../../app/Services/QuotingEngine.php), [`PolizaEmisionService`](../../app/Services/PolizaEmisionService.php)). Al enchufar Visred, el riesgo no es técnico sino de **modelado**: si calcamos el dominio sobre la API de Visred, nos acoplamos a un proveedor que puede cambiar o multiplicarse. Por eso modelamos primero en términos de MANGO y dejamos Visred del lado de afuera del puerto.

---

## 1. Límites de contexto (System of Record vs System of Engagement)

La decisión estructural que ordena todo el modelo:

| | **Compañía (vía Visred)** — System of Record | **MANGO (PAS)** — System of Engagement |
|---|---|---|
| **Es dueño de** | La póliza, sus **endosos**, su estado, los documentos oficiales | El lead/cliente, la cotización, el checkout, el *acto* de emitir, las comisiones |
| **Verdad** | Autoritativa y viva. Al consultar/descargar entrega **siempre la última versión** | Comercial. Historia propia = cadena de `risk_snapshots` |
| **Historia de cambios del contrato** | Sí (endosos) — **su** dominio | No la replicamos |
| **Acceso** | **On-demand** desde el backend (invariante túnel: solo `workflow-assistant` habla con Visred) | Persistencia propia (Postgres) |

**Consecuencias:**
- **No construimos un store autoritativo de endosos** ni una proyección de póliza "viva". Sería reimplementar el core de la compañía y garantiza *drift*. (Espíritu de [ADR-001](../v1/adr/001-quote-provider-refs.md).)
- Una **"solicitud de cambio"** del cliente (p.ej. "cambié de CP") es una acción comercial **nuestra** que se forwardea; el **endoso** resultante es de la compañía.

> **Corrección v3 (2026-06-20).** El acceso **on-demand** a la compañía (esta fila y la consecuencia "no construimos store de endosos / estado vivo") quedó **invertido** para el **post-emisión**: no existe endpoint de consulta por `policy_number` ni webhook de eventos de póliza, así que el on-demand es imposible. El estado/endosos/renovación se mantienen **manualmente** en MANGO (fuente de verdad de cartera), asistidos por extracción de documentos. Detalle en §5 y en [`../v3/`](../v3/01-modelo-mantenimiento-cartera-endosos.md). Lo de esta sección sigue valiendo como intención de diseño para **cotización/emisión**.

---

## 2. Los puertos (provider-agnostic)

Dos puertos estables; el dominio depende de ellos, no de Visred.

```
QuotationProvider   ── quote(QuotationRequest)  : QuotationResult[]      (o el array-shape actual; ver §3)
EmissionProvider    ── emit(EmissionRequest)    : EmissionResult
```

Implementaciones, elegidas por config (mismo patrón que el seam `WhatsAppDispatcher`):

```
QuotationProvider ─┬─ QuotingEngine            (mock, lo de hoy)
                   └─ VisredQuotationProvider  (real → VisredClient)
EmissionProvider  ─┬─ MockEmissionProvider     (mock)
                   └─ VisredEmissionAdapter    (real → VisredClient)
```
```php
// config/visred.php
'quotation_provider' => env('QUOTATION_PROVIDER', 'mock'), // 'mock' | 'visred'
'emission_provider'  => env('EMISSION_PROVIDER',  'mock'), // 'mock' | 'visred'
```

- **Mapeos Visred↔dominio viven SOLO en los adapters.** El dominio (QuoteService, PolizaEmisionService) habla en tipos MANGO.
- **Multi-proveedor / routing futuro:** el bind puede evolucionar de un flag global a una resolución por compañía (qué proveedor cotiza/emite cada `company_id`) sin tocar el dominio — solo cambia la fábrica del puerto.
- **"Sin DTO" (CLAUDE.md):** los tipos neutros NO son DTOs de serialización de una entidad; son **contratos de puerto** (value objects de transferencia entre dominio y adapter). No duplican una entidad de negocio.

---

## 3. Tipos de dominio neutros

Conceptuales (nombres MANGO). Implementación: en Fase 1 se conserva el **array-shape actual** de `generateAlternatives` para no romper `saveResults`; se puede promover a value objects `readonly` más adelante. Lo importante es que **ninguno menciona campos de Visred**.

**`QuotationRequest`** (desde `RiskSnapshot`): `document_number`, `zip_code`, `year`, `fuel` (`sin-gnc`|`gnc`), `zero_kilometers`, `vehicle_ref` (la llave que el proveedor entienda — para Visred, `version_id`; **resuelta en Fase 6**, ver §8).

**`QuotationResult`** (una por compañía × cobertura — ver §4 "aplanado"): `company`, `cover` (slug/nombre/detalle), `grade` (`normalized_grade`), `premium` (`precio`), `installments`, `franchise`, `sum_insured`, `payment_method`, `features[]`, `requires_inspection_before_emission`, **+ `provider_result_ref`** (opaco — el `quotation_result_id` de Visred, **no** se expone al agente; vive en `quote_provider_refs`, ADR-001).

**`EmissionRequest`**: `selected_provider_result_ref` (la cobertura elegida), `holder` (datos del titular), `address`, `vehicle` (patente/motor/chasis), `payment`, `inspection_photos[]`.

**`EmissionResult`**: `policy_number`, `proposal_number`, `company`, `product`, `status`, `requires_inspection_after_emission`, `documents[]` (blobs neutros capturados en la emisión: `{kind, filename, mime, contents}`). **No expone `presale_id`** — es un dato de Visred que se usa y muere **dentro** del adapter (inspección post-emisión + captura de documentos).

---

## 4. Modelo temporal de datos

```
risk_snapshot        Risk                       referencia de póliza            [ Visred = SoR ]
(inmutable,     →    (identidad estable    →    (mínima: policy_number,    ⇢   estado, cobertura,
 por cotización)     + estado actual)            company_id, product;           endosos, documentos
                                                 sin presale_id)                (on-demand)
```

| Entidad | Mutabilidad | Quién la posee | Qué guarda |
|---|---|---|---|
| `risk_snapshot` | **Inmutable** (una por cotización) | MANGO | Foto del riesgo al cotizar. **Nuestra historia comercial** vive en esta cadena. |
| `Risk` | Identidad estable + **estado actual** | MANGO | Bien asegurable vivo (ancla de cartera y re-cotización). `customer_id`, `type`, llave natural, estado actual. |
| Referencia de póliza | Append (una por emisión) | MANGO | **Solo identificadores** para consultar a Visred. |
| `Poliza` / `endoso` / estado / documentos | Vive en la compañía | **Visred** | On-demand, siempre la última versión. **No los modelamos como autoritativos.** |

> **Corrección v3 (2026-06-20).** La columna "on-demand" de la última fila **no aplica** al post-emisión: sin endpoint de consulta ni webhook, MANGO **sí** modela el estado/endosos de la cartera (como hechos materializados y mantenidos a mano, no como proyección autoritativa de la compañía). Modelo en [`../v3/01-modelo-mantenimiento-cartera-endosos.md`](../v3/01-modelo-mantenimiento-cartera-endosos.md). Ver §5.

### Decisión (a) — el `Risk` **no** lleva estados versionados

> *Reabre la idea de "estados inmutables versionados" que charlamos: queda descartada por las decisiones de límite de contexto + referencia mínima. Confirmar en §9.*

**Resolución propuesta:** `Risk` = identidad + **estado actual** (un `metadata`/columnas, mutable in-place). **No** se agrega tabla de versiones de atributos.

**Por qué:** la historia que importa es (1) **la del contrato**, que es de la compañía (on-demand, no nuestra), y (2) **la comercial nuestra**, que ya está en la cadena inmutable de `risk_snapshots` (cada cotización congela el estado). Una tabla de versiones del `Risk` duplicaría esa historia → dos fuentes para "cuándo cambió", que pueden divergir. Si más adelante hace falta auditar "quién tocó qué campo", se agrega un **audit log genérico** (observabilidad), no modelo de dominio.

**Gobernanza (lo que sí importa):** toda mutación del `Risk` pasa por una acción de dominio que clasifica *corrección comercial* (actualiza estado) vs *cambio contractual* (→ solicitud de endoso a la compañía).

---

## 5. Referencia de póliza + cartera on-demand

**Qué persistimos al emitir:** `policy_number`, `company_id`, `product_id`, ligados a `Risk` + `Quote` (`Quote.status='poliza_emitida'`). En las tablas **de dominio** (`polizas`) **NO persistimos `presale_id`** — es un dato de Visred acotado al ciclo de emisión que **no sale del adapter**. Nada más del contrato. (Única excepción acotada: la captura diferida de documentos guarda el `presale_id` como **token opaco aislado** en `poliza_provider_refs`, efímero y borrado al terminar — ver §5, "Captura diferida".)

> **Corrección (2026-06-15).** Originalmente `presale_id` se eligió como la referencia durable persistida en `polizas` (decisión (b) abajo) y como clave de cache/descarga de documentos. Es un **error**: `presale_id` solo vive durante la emisión. Se revirtió — la referencia durable es `policy_number`; ver Bitácora del ROADMAP.

> **Corrección v3 (2026-06-20) — la cartera NO es on-demand.** El modelo de "cache-aside que refresca desde Visred" descrito abajo **no se materializó y no es posible**: **no existe endpoint de consulta por `policy_number` ni webhook** de eventos de póliza. En los hechos, `PolicyReferenceService::materialize` **congela** los valores en la emisión y no hay read-path de vuelta a la compañía. La cartera es por lo tanto **estática** y se mantiene **manualmente** (renovación = póliza nueva; refacturación/anulación/endosos cargados a mano), asistida por extracción de documentos. **MANGO pasa a ser la fuente de verdad del estado de cartera**, no una proyección on-demand. Diseño post-emisión en [`../v3/01-modelo-mantenimiento-cartera-endosos.md`](../v3/01-modelo-mantenimiento-cartera-endosos.md) (+ extractor en [`../v3/02-extractor-documentos-poliza.md`](../v3/02-extractor-documentos-poliza.md)). Lo de abajo se conserva como intención original de diseño.

**Cartera (lectura frecuente) = on-demand con cache-aside de TTL corto:**
- El **backend** (único que habla con Visred) cachea el estado/resumen de póliza por `policy_number` (Laravel `Cache`, TTL ~**2h** configurable).
- App abre → sirve de cache; TTL vence → refresca desde Visred; se **invalida** en pull-to-refresh o tras una acción que cambie la póliza.
- Es **cache de performance, no proyección autoritativa** (la fuente sigue siendo Visred; dentro del TTL se tolera leve desactualización a cambio de no martillar la API).
- **Documentos (PDF):** se **capturan al emitir** —dentro de `emit()`, con el `presale_id` vivo— y se **persisten en R2** (`PolicyDocument`). `POST /v1/documents/` **es asíncrono**: la compañía genera el PDF on-demand, así que recién emitida la póliza el `result.url` viene vacío. La captura hace **un intento** por llamada (no poll-ea inline). Como el `presale_id` muere con la emisión, NO hay re-descarga fresca por handle: es un **snapshot** (rompe a propósito el "siempre la última versión"). El concepto de **túnel** (pasar bytes sin persistir) queda **caduco**.
  - **Captura diferida (2026-06-19).** Lo que la compañía todavía está generando NO se pierde: la emisión lo reporta como `pending_documents` (un `token` **opaco** + los `kind` faltantes) y el dominio persiste una `poliza_provider_refs` + encola `CapturePendingPolicyDocuments`, que re-pide los PDFs **1 vez por minuto durante ~10 min** (delay/backoff de `visred.document_retry_*`, 10 tries; el job se re-encola con delay, no bloquea worker) hasta capturarlos o agotar la ventana (el `presale_id` caduca → faltantes van por carga manual). Es la **misma convención `*_provider_refs`** que `quote_alternative_provider_refs` (token opaco aislado del dominio; su valor es el `presale_id`, pero el dominio no lo interpreta — lo guarda y se lo devuelve al puerto vía `EmissionProvider::capturePendingDocuments()`). El `task_type_id → kind` está en `visred.document_task_types` (hoy `download-poliza`/`download-certificate`/`download-circulation-card`).
  - Lo post-emisión —renovaciones (ocurren en la compañía, **sin** Visred) y endosos— entra por **carga manual del admin** (`source=admin_upload`, deuda admin panel). `visible_to_client` decide qué ve mango-mobile; se sirve con URL R2 firmada y expirable.

### Decisión (b) — fate de la tabla `polizas` (modelo compartido con mango-mobile)

> La tabla `polizas` actual tiene columnas **NOT NULL ricas** (`estado`, `company`, `coverage`, `sum_asegurada`, `vigencia`) pensadas para una cartera autocontenida (seed/mock) que **lee mango-mobile**. "Referencia mínima" choca con eso. Confirmar en §9.

**Resolución — ✅ IMPLEMENTADA (2026-06-08, WS-C):** **repurposear `polizas` como tabla de referencia** — relajadas a `nullable` las columnas de tarifa/estado (`estado`/`company`/`coverage`/`sum_asegurada`/`vigencia`) y agregadas `quote_id`/`presale_id`/`product_id`/`company_id`/`last_synced_at` (migración `visred-integration/.../2026_06_09_001617_repurpose_polizas_as_policy_reference.php`). mango-mobile **sigue leyendo `polizas`**, pero los campos de display los compone el endpoint de cartera del backend desde el fetch on-demand cacheado. La materialización vive en `PolicyReferenceService` (dominio cartera, agnóstico). **Pendiente operativo: avisar a mango-mobile** que la migración corrió (es modelo compartido).
*Alternativas descartadas: (b2) nueva tabla liviana `policy_refs` + rediseño de cartera; (b3) traer-una-vez al emitir para llenar las columnas (≈ proyección — descartada por la decisión de §4/§1).*

---

## 6. Ciclo de vida

```
cotización ──> checkout ──> emisión ──> find-or-create Risk + referencia de póliza
   (Quote +      (Checkout    (puerto       (Quote.status = poliza_emitida)
    snapshots)    Session)     Emission)
                                  │
                                  └─ inspección (fotos R2): before (en el payload) / after (endpoint)

post-emisión:
  • ver póliza/estado/documentos ── on-demand a Visred (cache-aside / descarga fresca)
  • solicitud de cambio del cliente ── acción comercial nuestra ──> (forward) ──> endoso = compañía
  • renovación ── nueva Poliza sobre el MISMO Risk (1:N temporal) — diseño futuro
  • cancelación/baja ── acción contra la compañía — diseño futuro
```

**Inputs de la emisión = checkout válido + inspección + `Risk`** (todos alcanzables desde el `Quote`: `checkout_sessions.quote_id`, `quotes.risk_snapshot_id`, `inspection_photos` por quote).

---

## 7. Dedup del `Risk`

Un auto asegurado dos años seguidos (aunque cambie de compañía) es **un** `Risk`. **Resolución propuesta:** dedup por (`customer_id`, `type`, **patente**) para vehículos; `chasis` como identidad más fuerte si está disponible. Evita duplicar la cartera en re-emisión/renovación/reintento.

---

## 8. Scope y fuera-de-scope

- **En scope (happy path):** `type=vehicle` (auto/moto) — lo que Visred cotiza/emite en `patrimoniales/vehicles`. Cotización + emisión + descarga de documento on-demand.
- **`version_id` (Fase 6, aparte):** el `vehicle_ref` de §3 para Visred es un `version_id` **opaco de su catálogo** — propiedad de `(auto, año, proveedor)`, **no** un atributo del riesgo (a diferencia de patente/chasis, que sí son del vehículo, §7). Por eso **NO vive en una columna del dominio** y **menos** con nombre de proveedor (`risk_snapshots.visred_version_id` queda **descartado** — acoplaría `RiskSnapshot` a Visred, violando §2/§5). Es un concern del **adapter**, necesario **solo en quote-time** (la emisión usa `quotation_result_id`, ya aislado en `quote_provider_refs`). Se resuelve detrás de un puerto agnóstico **`VehicleCatalogResolver`** (ver `visred-integration/RESOLVER-DESIGN.md`): traduce el auto (hechos de dominio capturados por el chat) → token del catálogo, con desambiguación. En el happy path de Fase 3 el resolver lee un token pre-seteado de su propio backing store (decisión A/B abierta, §9); Fase 6 reemplaza su tripa por la cadena de params + desambiguación, sin tocar el dominio.
  - **Frontera dura:** la captura de intención del cliente (`VehicleIdentifierAgent`/`IdentifyVehicleTool`/`VehicleIdentificationService`) es **solo NLU, intocable**; produce hechos de dominio del auto. El resolver es un **componente separado, agnóstico de canal**, que lee del modelo de dominio — nunca de esas clases ni del orquestador. Dependencia: `VehicleIdentifier* → dominio ← resolver` (ver regla general en `CLAUDE.md` §"Principio de desacople").
  - **La versión real desambiguada** ("Active VTI") **sí es dominio** (es un hecho del auto del cliente, agnóstico): puede vivir en `risk_snapshots.version`. Lo único que se aísla es el **token opaco por proveedor**.
- **Endosos:** dominio de la compañía — no se implementan acá.
- **Otros `type` (hogar/AP/vida):** mismo patrón de puertos, fuera de scope ahora.
- **Gap `person_holder`:** la emisión Visred pide `birthdate`, `sex_id`, `tax_condition_id`, `person_type_id`, `document_type_id` que `checkout_sessions` no captura hoy — capturar en checkout vs defaults (bloquea Fase 5).

---

## 9. Decisiones abiertas para revisión

1. **(a) `Risk` sin versionado de estado** (§4) — confirma que descartamos la tabla de versiones a favor de "identidad + estado actual" + cadena de `risk_snapshots` como historia comercial.
2. **(b) Repurposear `polizas`** a tabla de referencia — **✅ IMPLEMENTADO (2026-06-08, WS-C)** (relajado NOT NULL + agregados `quote_id`/`presale_id`/`company_id`/`product_id`/`last_synced_at`; `PolicyReferenceService` materializa). Resta **avisar a mango-mobile** (modelo compartido).
3. **TTL de cache de cartera** (§5) — ~2h propuesto; confirmar valor y eventos de invalidación con mango-mobile.
4. **Dedup del `Risk`** (§7) — **✅ IMPLEMENTADO (2026-06-08, WS-C)** por (`customer_id`, `type=vehicle`, patente vía `Risk.metadata->patente`) en `PolicyReferenceService`. Abierto aún: exigir `chasis` como identidad más fuerte cuando esté disponible.
5. **`person_holder`** (§8) — capturar en checkout vs defaults.
6. **Backing store del `VehicleCatalogResolver`** (§8, nuevo) — **A** `risk_provider_refs(risk_snapshot_id, provider, external_vehicle_ref)` (guarda *la decisión* por cotización) vs **B** `provider_vehicle_versions(provider, marca, modelo, version, year → ref)` (cachea *el catálogo* por clave natural). No son excluyentes: A = la decisión, B = el cache. Lean actual del usuario: **C+A** (sin cerrar). Detalle en `visred-integration/RESOLVER-DESIGN.md`.

---

*Documento de modelado (Fase 0). Aprobado esto, se implementa detrás de los puertos (Fases 1+). Contrato Visred: [08](08-visred-quote-adapter.md). Schema vivo: [01](01-database-schema.md).*
