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

**`EmissionResult`**: `policy_number`, `proposal_number`, `presale_id`, `company`, `product`, `status`, `requires_inspection_after_emission`, `pending_tasks[]` (p.ej. `download-poliza`).

---

## 4. Modelo temporal de datos

```
risk_snapshot        Risk                       referencia de póliza            [ Visred = SoR ]
(inmutable,     →    (identidad estable    →    (mínima: presale_id,       ⇢   estado, cobertura,
 por cotización)     + estado actual)            policy_number, company,        endosos, documentos
                                                 product)                       (on-demand)
```

| Entidad | Mutabilidad | Quién la posee | Qué guarda |
|---|---|---|---|
| `risk_snapshot` | **Inmutable** (una por cotización) | MANGO | Foto del riesgo al cotizar. **Nuestra historia comercial** vive en esta cadena. |
| `Risk` | Identidad estable + **estado actual** | MANGO | Bien asegurable vivo (ancla de cartera y re-cotización). `customer_id`, `type`, llave natural, estado actual. |
| Referencia de póliza | Append (una por emisión) | MANGO | **Solo identificadores** para consultar a Visred. |
| `Poliza` / `endoso` / estado / documentos | Vive en la compañía | **Visred** | On-demand, siempre la última versión. **No los modelamos como autoritativos.** |

### Decisión (a) — el `Risk` **no** lleva estados versionados

> *Reabre la idea de "estados inmutables versionados" que charlamos: queda descartada por las decisiones de límite de contexto + referencia mínima. Confirmar en §9.*

**Resolución propuesta:** `Risk` = identidad + **estado actual** (un `metadata`/columnas, mutable in-place). **No** se agrega tabla de versiones de atributos.

**Por qué:** la historia que importa es (1) **la del contrato**, que es de la compañía (on-demand, no nuestra), y (2) **la comercial nuestra**, que ya está en la cadena inmutable de `risk_snapshots` (cada cotización congela el estado). Una tabla de versiones del `Risk` duplicaría esa historia → dos fuentes para "cuándo cambió", que pueden divergir. Si más adelante hace falta auditar "quién tocó qué campo", se agrega un **audit log genérico** (observabilidad), no modelo de dominio.

**Gobernanza (lo que sí importa):** toda mutación del `Risk` pasa por una acción de dominio que clasifica *corrección comercial* (actualiza estado) vs *cambio contractual* (→ solicitud de endoso a la compañía).

---

## 5. Referencia de póliza + cartera on-demand

**Qué persistimos al emitir:** `presale_id`, `policy_number`, `company_id`, `product_id`, ligados a `Risk` + `Quote` (`Quote.status='poliza_emitida'`). Nada más del contrato.

**Cartera (lectura frecuente) = on-demand con cache-aside de TTL corto:**
- El **backend** (único que habla con Visred) cachea el estado/resumen de póliza por `presale_id` (Laravel `Cache`, TTL ~**2h** configurable).
- App abre → sirve de cache; TTL vence → refresca desde Visred; se **invalida** en pull-to-refresh o tras una acción que cambie la póliza.
- Es **cache de performance, no proyección autoritativa** (la fuente sigue siendo Visred; dentro del TTL se tolera leve desactualización a cambio de no martillar la API).
- **Documentos (PDF):** NO se cachean — se traen frescos al descargar (`POST /v1/documents/`), siempre la última versión.

### Decisión (b) — fate de la tabla `polizas` (modelo compartido con mango-mobile)

> La tabla `polizas` actual tiene columnas **NOT NULL ricas** (`estado`, `company`, `coverage`, `sum_asegurada`, `vigencia`) pensadas para una cartera autocontenida (seed/mock) que **lee mango-mobile**. "Referencia mínima" choca con eso. Confirmar en §9.

**Resolución propuesta:** **repurposear `polizas` como tabla de referencia** — relajar a `nullable` las columnas de tarifa/estado y agregar `presale_id`/`product_id`/`company_id`/`last_synced_at`. mango-mobile **sigue leyendo `polizas`**, pero los campos de display los compone el endpoint de cartera del backend desde el fetch on-demand cacheado. Menos disruptivo que una tabla nueva. **Coordinar la migración con mango-mobile** (es modelo compartido).
*Alternativas: (b2) nueva tabla liviana `policy_refs` + rediseño de cartera; (b3) traer-una-vez al emitir para llenar las columnas (≈ proyección — descartada por la decisión de §4/§1).*

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
2. **(b) Repurposear `polizas`** a tabla de referencia (relajar NOT NULL + agregar `presale_id`/…), coordinado con mango-mobile (§5) — vs. tabla nueva.
3. **TTL de cache de cartera** (§5) — ~2h propuesto; confirmar valor y eventos de invalidación con mango-mobile.
4. **Dedup del `Risk`** (§7) — patente como llave; ¿exigimos chasis cuando esté?
5. **`person_holder`** (§8) — capturar en checkout vs defaults.
6. **Backing store del `VehicleCatalogResolver`** (§8, nuevo) — **A** `risk_provider_refs(risk_snapshot_id, provider, external_vehicle_ref)` (guarda *la decisión* por cotización) vs **B** `provider_vehicle_versions(provider, marca, modelo, version, year → ref)` (cachea *el catálogo* por clave natural). No son excluyentes: A = la decisión, B = el cache. Lean actual del usuario: **C+A** (sin cerrar). Detalle en `visred-integration/RESOLVER-DESIGN.md`.

---

*Documento de modelado (Fase 0). Aprobado esto, se implementa detrás de los puertos (Fases 1+). Contrato Visred: [08](08-visred-quote-adapter.md). Schema vivo: [01](01-database-schema.md).*
