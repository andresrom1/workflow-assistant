# CLOSURE-PLAN — Cierre de la integración Visred (saldar D1–D6)

> Complemento canónico de [`PLAN.md`](PLAN.md). El `PLAN.md` cubre el plan original (Fases 0–6);
> este documento ordena el **cierre de la deuda** (D1–D6 del [`ROADMAP.md`](ROADMAP.md)) por
> dependencia y dueño, para llevar Visred de "maquinaria lista" a "emite y se materializa en cartera".
> Tracking de ejecución en [`ROADMAP.md`](ROADMAP.md). Modelo de dominio (gate):
> [`../10-modelo-dominio-cotizacion-emision.md`](../10-modelo-dominio-cotizacion-emision.md).
> **Rama:** `prepare-proyect-for-API-refactor`.

## Context

La integración Visred tiene la **maquinaria completa y verde** (cotización, quotability, emisión,
inspección — Fases 0–4 ✅, Fase 6 ✅, Fase 5 🚧 con suite 326/326 y PHPStan 0). La Fase 5 se
re-scopeó deliberadamente a "solo la API de Visred" (decisión del usuario 2026-06-07), dejando un
**registro de deuda** (D1–D6) que hoy impide *emitir una póliza real end-to-end*.

Este documento **no reabre lo hecho**: ordena el cierre de esas deudas. Resultado intencionado —
D4.1/D4.2 cierran el lado Visred al 100%; D1/D2 destraban el emit live; D3 materializa la póliza en
cartera (coordinando mango-mobile); D6 verifica live; D5 queda listo para activar cuando producto lo
pida.

> **Estado (2026-06-08):** **WS-A ✅** (D4.1+D4.2), **WS-B ✅** (D1+D2), **WS-C ✅** (D3 — repurpose
> `polizas` + `PolicyReferenceService`, **sin** el stash acoplado en `Quote.metadata`) y **WS-D/D6 ✅**
> (emit E2E real: pre-venta `presale_id 32092` en el sandbox, shape live = `mapResult`). Suite 340/340,
> PHPStan 0, build OK. **El plan de cierre está completo.** Pendientes residuales: **avisar a mango-mobile**
> del repurpose de `polizas`; confirmar con Visred los topes de descuento por-compañía (hoy solo
> `triunfo → 20%`); y **D5** (documento, diferido por producto). **D8 cerrado** (descuento cap-aware
> por-compañía, verificado live) y **D7 descartado** (`sin-gnc` válido; se mejoró el mapeo de combustible).
> Detalle en la bitácora del [`ROADMAP.md`](ROADMAP.md).

### Invariantes que el cierre respeta (decisiones ya tomadas)
- El dominio nunca importa una clase Visred (mapeos solo en adapters).
- `Risk`/`RiskSnapshot` sin columna de proveedor; tokens opacos en `*_provider_refs`.
- La referencia de póliza es **append post-emisión**, NO va en `risk_snapshot` (doc 10 §4/§5).
- NLU de vehículo intocable; nada de nombre de proveedor en `ai_state`.
- Compañía = system of record (sin store de endosos; referencia mínima + on-demand).
- **Separación de scopes** (2026-06-07): cada workstream toca un solo scope, sin mezclar Visred con
  checkout/cartera.

---

## Secuencia y gates

| Orden | Workstream | Depende de | Bloqueante externo | Estado |
|---|---|---|---|---|
| 0 | WS-0 (documentar) | — | — | ✅ |
| 1 | WS-A (D4.1, D4.2) | nada | — | ✅ |
| 2 | WS-B (D1, D2) | nada | — | ✅ |
| 3 | WS-C (D3) | acuerdo de modelo (ya definido, doc 10 §5b) | avisar a mango-mobile | ✅ |
| 4 | WS-D (D6) | WS-A + WS-B + WS-C | — | ✅ emit real OK |
| — | D5 | WS-D | decisión de producto | ⏸️ diferido |
| — | D7 (`fuel_type_id`) | ~~hallazgo D6~~ | — | ❌ descartado (+ mejora de mapeo) |
| — | D8 (descuento) | hallazgo D6 | — | ✅ cap-aware, verificado live |

WS-A y WS-B son independientes entre sí (cualquier orden / paralelo). WS-C arranca con el acuerdo de
mango-mobile. WS-D cierra. **WS-0 ✅** (este documento + actualización del ROADMAP).

---

## WS-A · Cierre del wiring Visred (D4.1 + D4.2) — *sin dependencias*

Deja la maquinaria Visred 100% cerrada de nuestro lado. Fase 5 pasa de 🚧 a ✅ en su scope.

### Pieza F — D4.1: inspección before-emisión

**Puntos load-bearing (verificados contra el código 2026-06-08):**
- `VisredEmissionProvider::buildRequest` (líneas 99-102) **ya consume** `inspections[]` si vienen en
  el request neutro.
- `VisredEmissionProvider::buildHolder` (115-130) **ya mapea** todos los campos del titular.
- `VisredInspectionService::buildInspections(string $companyId, string $productId, iterable $photos)`
  **ya existe** y devuelve el array neutro `list<array{type_id, image_base64}>`.

**El cabo real:** `parsed_alternatives` no propaga el slug `company_id`. Existe en
`VisredQuotationProvider::flatten()` (línea 214) solo para resolver el nombre legible, pero **no se
emite por-alternativa ni se persiste**.

Orden:
1. **Emitir `company_id` por-alternativa** en `app/Services/Visred/VisredQuotationProvider.php`:
   `mapCover()` (líneas 258-275) arma el array con `external_quote_id`/`external_code`/
   `requires_inspection_before_emission` pero **sin** `company_id`.
   - **PRECISIÓN 1:** `mapCover()` hoy recibe `($coverResult, $companyName)` — **no** `$companyId`.
     Hay que **threadear `$companyId` como parámetro** a `mapCover` (disponible en `flatten` línea
     214) además de agregar `'company_id' => $companyId` al array retornado. Es un paso más que
     "solo agregar la key".
2. **Persistir el slug** en `app/Repositories/QuoteRepository.php::saveResults` (líneas 39-91):
   - Añadir `company_id` a los campos stripeados del dominio (`unset`, línea 58, junto a
     `external_quote_id`/`external_code`).
   - Pasarlo al `providerRef()->create([...])` (líneas 75-78).
3. **Migración + modelo:** agregar columna `company_id` (nullable string) a
   `quote_alternative_provider_refs` (nueva migración) y al `$fillable` de
   `app/Models/QuoteAlternativeProviderRef.php`.
4. **Armar before-emisión** en `app/Services/PolizaEmisionService.php::buildRequest` (línea 131):
   cuando `$alternative->providerRef->requires_inspection_before_emission === true`, leer
   `providerRef->company_id`, obtener las `InspectionPhoto` confirmadas del quote y llamar a
   `VisredInspectionService::buildInspections($companyId, $productId, $photos)`; meter el array
   neutro `inspections[]` en el request.
   - **PRECISIÓN 2:** el 2º parámetro de `buildInspections` es **`$productId`**, no un literal
     `'auto'`. Pasar el product correcto desde el service.
   - `emitir()` debe pasar el `$alternative`/ref a `buildRequest` — hoy solo pasa el
     `$quotationResultRef` (línea 52, extraído en línea 46). Ampliar la firma interna.

**Tests** (herméticos, `Http::fake` + `Storage::fake('r2')`, reusar `PolizaEmisionServiceTest` y
`QuoteRepositoryTest`):
- alt con flag `true` → el request que recibe el spy del provider incluye `inspections`.
- alt con flag `false` → no las incluye.
- `company_id` persistido por `saveResults` en la ref por-alternativa.

### Pieza G — D4.2: idempotencia de emisión

**Problema (verificado):** `emitir()` (línea 43) **no tiene guard**. `EmitirPoliza` reintenta ante
excepción → un re-run llamaría `emit()` de nuevo → **doble pre-venta real**.

Orden:
1. Guard al inicio de `PolizaEmisionService::emitir()`: si `$quote->status === 'poliza_emitida'`
   (o ya hay `metadata.emission.presale_id`), salir devolviendo el resultado persistido **sin**
   re-emitir.
2. Documentar en un TODO la ventana de carrera restante entre `emit()` y `persistEmission()` (un
   crash entremedio dejaría sin guard); mínimo viable = guard por estado.

**Tests:** segundo `emitir()` sobre un quote ya `poliza_emitida` → spy del provider con 0
invocaciones, devuelve el resultado stasheado.

**Cierre WS-A:** suite verde + PHPStan 0 (regenerar baseline si una anotación la baja) + Pint.
Actualizar ROADMAP: D4.1/D4.2 ✅, Fase 5 ✅ en scope Visred.

---

## WS-B · Checkout completo (D1 + D2) — *cuello de botella para emit live*

Scope checkout (no Visred), pero laburo nuestro. Sin esto, Visred responde 400 y D6 no corre. D2 se
incluye ahora (decisión del usuario), no como sub-deuda.

### D1 — captura del titular (`person_holder`)

El adapter (`buildHolder`) ya mapea todo; falta capturarlo y pasarlo. Bloque de deuda en
`resources/js/pages/Checkout/Show.vue:444-456` (verificado textual; hoy captura solo
`nombre/dni/email/telefono`).

1. **Migración `checkout_sessions`:** columnas `birthdate` (date), `sex_id` (string),
   `tax_condition_id` (string), `phone_prefix` (string ≤3), `phone_number` (string ≤9),
   `first_name`, `last_name`. Agregar al `$fillable` de `app/Models/CheckoutSession.php`. (Hoy hay un
   solo `telefono` y un `nombre` — mantenerlos o derivar; decisión menor de ejecución.)
2. **Catálogo `tax_condition_id`:** `GET /v1/params/tax-conditions/` expuesto al form. **No existe
   endpoint hoy** — hay que crearlo (componente/endpoint que liste opciones `{ref,label}`; el adapter
   de catálogo es agnóstico de canal).
3. **`Checkout/Show.vue`:** inputs para fecha de nacimiento, sexo, condición fiscal, split de nombre
   (first/last) y split de teléfono (prefix ≤3 / number ≤9), donde está el comentario de deuda.
   **NO tocar el pipeline de fotos/canvas** (regla de memoria móvil del checkout).
4. **`PolizaEmisionService::buildRequest`** (líneas 137-143): completar el `holder[]` con los campos
   nuevos y quitar el comentario de deuda.

**Tests:** feature de checkout que persiste los campos nuevos; `PolizaEmisionServiceTest` con holder
completo → el request neutro lleva todos los campos del titular.

### D2 — fotos de inspección faltantes (GNC/velocímetro)

`buildVehicle` del adapter ya mapea `has_gnc`; `buildInspections` ya loggea gaps. `inspection_photo_map`
en `config/visred.php` (líneas 59-67) tiene 7 keys sin velocímetro/GNC; `has_gnc` solo existe como
default `false` en el adapter.

1. Slots de foto `velocimetro` y, **condicionados a GNC**, `tubo-gnc` / `oblea-gnc` en
   `Checkout/Show.vue` (sin alterar la lógica canvas existente; mismo patrón de captura).
2. Mapear los `photo_key` nuevos → `inspection_type_id` en `config/visred.php` (`inspection_photo_map`,
   mapa verificado live en 6 compañías).
3. Capturar `has_gnc` en checkout y propagarlo al `vehicle[]` del request neutro (`buildRequest` del
   service → ya consumido por `buildVehicle` del adapter).

**Tests:** con GNC → los slots GNC se exigen y mapean; sin GNC → no; `buildInspections` resuelve los
tipos nuevos cuando hay foto.

**Cierre WS-B:** desbloquea D6. Tests + PHPStan 0 + Pint + `npm run build` si hace falta ver el form.

---

## WS-C · Cartera / materialización (D3) — *gated por mango-mobile*

Única deuda con dependencia externa real: `polizas` es modelo compartido con mango-mobile y hoy
tiene NOT NULL en `company`, `coverage`, `sum_asegurada`, `vigencia` (migración
`2026_05_28_184511_create_polizas_table.php`, verificado), incompatible con "referencia mínima". No
existe `policy_refs`. Hoy `PolizaEmisionService::persistEmission` (líneas 190-213) stashea en
`Quote.metadata['emission']` (interino, sin pérdida de datos).

**Decisión previa a acordar (bloqueante):** repurpose de `polizas` (relajar NOT NULL + agregar
`presale_id`/`company_id`/`product_id`) **vs** tabla nueva `policy_refs` (doc 10 §5b/§9.2).

Una vez acordado:
1. Migración (según la decisión).
2. **find-or-create `Risk`** (dedup `customer_id` + patente, §9.4).
   - **PRECISIÓN 3:** `Risk` **no tiene columna `patente`** (sus campos son
     `customer_id/type/label/metadata`; la patente vive en `Vehicle`/`RiskSnapshot.metadata`). El
     dedup "por `customer_id` + patente" debe resolver la patente **vía la relación**, no como
     columna de `Risk`. Tampoco existe hoy un find-or-create de `Risk` (sí lo hay para
     Customer/Vehicle — `CustomerIdentificationService`/`VehicleRepository::findOrCreate` sirven de
     patrón).
3. Reemplazar el stash de `metadata` por la referencia real ligada a `Risk` + `Quote`.

**Tests:** find-or-create no duplica Risk por patente; la emisión liga la ref al Risk correcto.

**Acción previa:** abrir la decisión `polizas` vs `policy_refs` con mango-mobile. Hasta entonces WS-C
queda **gated**; el stash interino aguanta.

---

## WS-D · Verificación live (D6) + documento (D5) — *al final*

### D6 — emit E2E real (bloqueado por WS-B)
- Smoke que **SÍ emite** una pre-venta real contra el sandbox, confirmando que la respuesta live
  calza con `APIBasePreSaleResultDTO` (lección de Fase 4: lo asumido difirió). Solo posible con
  titular completo (WS-B). Smoke por tinker, archivo `<?php` temporal, sin secretos, borrado al
  terminar.
- Sub-tema: `company_id` slug Visred (`rus`) ≠ slug `coverage_documents` (`rio-uruguay`) — mapear al
  tocar coverage-check.

### D5 — documento `download-poliza` (diferido por producto)
- `POST /v1/documents/` síncrono; la Pieza D ya deja el `presale_id` disponible. Se implementa cuando
  producto lo pida — no es deuda técnica. Listado para no perderlo.

---

## Verificación (por workstream)
- Tests herméticos primero: `Http::fake` + fixtures + `Storage::fake('r2')`, reusando
  `tests/Feature/Services/Visred/*`, `tests/Feature/Services/PolizaEmisionServiceTest.php`,
  `tests/Feature/Repositories/QuoteRepositoryTest.php` y los tests de checkout.
- `composer test` filtrado (Pest) por pieza; suite completa al cerrar cada workstream.
- `composer analyse` (PHPStan 0; regenerar baseline con `vendor/bin/phpstan --generate-baseline` si
  una anotación de relación la baja).
- Pint (hook automático).
- D6: smoke live read/emit por tinker contra el sandbox (sin secretos, archivo temporal borrado).
- Actualizar el ROADMAP (estado + bitácora) al cerrar cada pieza.

## Archivos críticos
- `app/Services/Visred/VisredQuotationProvider.php` — emitir `company_id` por-alternativa
  (`flatten` 214, `mapCover` 258-275).
- `app/Repositories/QuoteRepository.php` — stripear (`unset` 58) + persistir `company_id` en la ref
  (`providerRef()->create` 75-78).
- `database/migrations/2026_06_08_014829_create_quote_alternative_provider_refs_table.php` (+ nueva
  migración) + `app/Models/QuoteAlternativeProviderRef.php` — columna `company_id`.
- `app/Services/PolizaEmisionService.php` — before-emisión (`buildRequest` 131), guard de
  idempotencia (`emitir` 43), holder completo (137-143), materialización (`persistEmission` 190-213).
- `app/Services/Visred/VisredInspectionService.php` / `VisredEmissionProvider.php` — ya listos
  (consumidores).
- `app/Models/CheckoutSession.php` + migración `checkout_sessions` — campos del titular.
- `resources/js/pages/Checkout/Show.vue` — inputs del titular (444-456) + slots de foto
  GNC/velocímetro.
- `config/visred.php` — `inspection_photo_map` (59-67; tipos GNC/velocímetro).
- `database/migrations/2026_05_28_184511_create_polizas_table.php` — D3 (repurpose vs policy_refs).
- `app/Models/Risk.php` — find-or-create por `customer_id` + patente (vía relación, §9.4).
