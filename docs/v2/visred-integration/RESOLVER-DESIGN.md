# Diseño — `VehicleCatalogResolver` (traducción auto → catálogo del proveedor)

> **Estado:** diseño **mayormente cerrado** (sesión 2026-06-07). Cerrados: hogar del resolver y trigger (§7), gate por *quotability* agnóstica (§8), cascada de 3 tiers (§9), catálogo Visred verificado live (§10), backing store C+A (§4). Abiertos para la ventana de implementación (Fase 6): prompt de desambiguación + umbral, trim base, año borde (§5 lista). No hay código todavía.
> **Relacionado:** [`PLAN.md`](PLAN.md) (Fase 3/6), [`ROADMAP.md`](ROADMAP.md), modelo de dominio [`../10-modelo-dominio-cotizacion-emision.md`](../10-modelo-dominio-cotizacion-emision.md) §8, contrato Visred [`../08-visred-quote-adapter.md`](../08-visred-quote-adapter.md). Regla de desacople: `CLAUDE.md` §"Principio de desacople".

---

## 0. El problema

El cliente dice en el chat *"tengo un Peugeot 2008 2017 Allure"*. El catálogo del proveedor (Visred) no acepta texto: pide un **`version_id` opaco** (token tipo `"AATkvcDW"`). Pero "Allure" es **ambiguo**: el catálogo real (grupo 2008, año 2017) tiene `1.6 ALLURE` y `1.6 ALLURE TIPTRONIC` → manual vs automática.

El PAS razona: *"TIPTRONIC es automática, me lo hubiera dicho → es la `1.6 ALLURE` manual"*. Si no está seguro, repregunta: *"¿Es automática?"*. **Eso** es lo que tiene que hacer el resolver. Se llama "version" pero en realidad resuelve **modelo + versión** (el modelo también puede ser ambiguo).

> **Nota (datos reales, §10):** el caso "Active VTI/THP/CVT" que se usó al abrir el diseño **no existe** en este catálogo. El eje real de ambigüedad acá es **transmisión** (`TIPTRONIC`) y a veces el motor — y se razona **parseando el string** `version_name`, porque las versiones **no traen atributos estructurados**.

---

## 1. Frontera dura (NO negociable)

La captura de intención del cliente en el chat —`VehicleIdentifierAgent`, `IdentifyVehicleTool`, `VehicleIdentificationService`— es **solo NLU, INTOCABLE**. Su única salida son **hechos de dominio del auto** (marca/modelo/version/year tal como el cliente los dijo), escritos en el modelo.

El resolver es un **componente nuevo y separado**, **agnóstico de canal**:
- Depende **solo del modelo de dominio**. NUNCA de las clases del chat, del orquestador ni de `ai_state`.
- Dependencia: **`VehicleIdentifier* → dominio ← resolver`**. Jamás `VehicleIdentifier* → resolver`.
- **No le habla al cliente.** Devuelve un resultado tri-estado; llevar una ambigüedad a la conversación lo decide la **capa de canal**, no el resolver.

> Esta frontera se rompió dos veces en la sesión de diseño (colgar el resolver del stack del chat / del orquestador). Por eso quedó como **regla general** en `CLAUDE.md`. Releerla antes de proponer nada.

---

## 2. Separar dos responsabilidades (no fusionarlas)

| Responsabilidad | Naturaleza | Dónde |
|---|---|---|
| **Consultar el catálogo** — "dame las versiones de Peugeot 2008 2017" → lista de candidatos | Determinística, **por proveedor** (cadena de params de Visred) | Adapter/infra del proveedor |
| **Desambiguar** — "el cliente dijo Active a secas → es VTI / o repregunto" | Razonamiento (LLM), **agnóstico de proveedor** (razona sobre candidatos neutros) | Componente de desambiguación, reusable entre proveedores |

**Por qué separarlas:** si el razonamiento vive dentro del resolver de Visred, se **duplica** al entrar un 2º proveedor. Regla: **un desambiguador, N adaptadores de catálogo.** Los candidatos se normalizan a una forma neutra (`{ref opaco, label, atributos}`) y el desambiguador razona sobre eso, no sobre internals de Visred.

**Desambiguar una vez, resolver N veces:** el cliente confirma su auto real ("Active VTI") **una sola vez** (agnóstico); después cada catálogo busca *su* token para "Peugeot 2008 2017 Active VTI". La versión real desambiguada es dominio (`risk_snapshots.version`); el token opaco es per-proveedor (aislado).

---

## 3. Contrato (borrador — a cerrar en la ventana nueva)

```php
// Puerto agnóstico — consulta de catálogo, por proveedor. SIN tabla por sí mismo.
interface VehicleCatalogResolver {
    public function candidates(VehicleQuery $q): array; // CandidateVersion[] neutros
}

// VehicleQuery (VO en memoria): marca, modelo, year, versionText, hints
// CandidateVersion (VO): ref opaco, label ("Active VTI"), atributos ({motor, transmision, ...})

// Resultado del paso de desambiguación (lo maneja el componente agnóstico, NO el chat):
//   Resolved(version, ref)            → seguí
//   Ambiguous(candidates, suggested)  → la capa de canal decide si confirmar/repreguntar
//   NotFound                          → faltan datos
```

`generateAlternatives(RiskSnapshot)` (Fase 3) NO cambia su firma: el `VisredQuotationProvider` obtiene el `version_id` **ya resuelto** leyéndolo del backing store del resolver. El resolver no participa del quote job (sin cliente en el loop); resuelve **antes**, en su propio paso/servicio.

---

## 4. Backing store — decisión ABIERTA (A vs B)

El resolver necesita dónde leer (Fase 3, stopgap manual) y dónde cachear (Fase 6). **No son excluyentes — responden cosas distintas:**

- **A — `risk_provider_refs(risk_snapshot_id, provider, external_vehicle_ref)`** — guarda **la decisión** por cotización ("para *este* snapshot, en Visred, el token fue X"). Bueno para auditar/reproducir el juicio. Clave = snapshot.
- **B — `provider_vehicle_versions(provider, marca, modelo, version, year → external_vehicle_ref)`** — cachea **el catálogo** por clave natural del auto. Reusa entre snapshots del mismo auto; es el cache que evita "martillar" la cadena de params. Clave = (auto, año, proveedor).

Ambas son tablas genéricas (columna `provider`, `external_*`) — **cero nombres de proveedor en el dominio**. El modelo de dominio expone a lo sumo una relación genérica `providerRefs()` (como `Quote::providerRef()`, ADR-001).

**Lean actual del usuario:** **C+A** (resolver port + store por snapshot), sin cerrar. Insight de la sesión: A = la decisión, B = el cache; se puede arrancar con A (o con la cadena directa sin cache) y sumar B como optimización cuando la performance lo pida.

---

## 5. Sub-temas — estado

**Cerrados en la sesión 2026-06-07:**
1. ✅ **Dónde vive el desambiguador / quién lo dispara** → §7.
2. ✅ **Ambigüedad y repregunta al cliente** → §7 + §9 (Tier 3).
6. ✅ **A vs B del backing store** → §4 (C+A; B opcional como cache).

**Abiertos — se cierran en la ventana de implementación (Fase 6), son tripa del Tier 2:**
3. **Prompt de la "sustracción por atributos"** + umbral de confianza. Insumo nuevo (§10): se razona **parseando el `version_name`** (motor / trim / transmisión), no sobre campos.
4. **"Trim base" cuando no se aclara:** ¿lo decide el LLM o el catálogo marca la base (prior determinístico que reduce alucinación)?
5. **Año borde:** el año es **estructural** en el catálogo (nodo del árbol, §10), no un filtro → modelo-año vs año de fabricación; ¿exacto o tolerancia ±1?
7. **Firma final** de `VehicleCatalogResolver` / `VehicleQuery` / `CandidateVersion` y el tipo de resultado tri-estado (esqueleto en §3).

---

## 6. Lo que NO cambia (para que Fase 3 arranque limpia después)

- `RiskSnapshot` **no gana** ninguna columna ni relación de proveedor.
- `generateAlternatives(RiskSnapshot): array` (puerto `QuotationProvider`) **conserva su firma** y el array-shape que consume `QuoteRepository::saveResults` (ver PLAN §"Fase 3").
- El seam de selección (bind en `AppServiceProvider` → `QuotingEngine`) **no se toca** (eso es Fase 4).

---

## 7. Hogar del resolver y quién lo dispara (cierra sub-temas #1/#2)

La resolución **corre en la etapa identify-vehicle, con el cliente presente** (es la única ventana donde se puede desambiguar sin promesas rotas). Pero el **NLU no llama al resolver** (frontera dura, §1): lo **dispara la capa de canal** (el adapter/orquestador), que es quien ya orquesta el flujo.

```
VehicleIdentifierAgent → IdentifyVehicleTool → WhatsAppAdapter   [capa de canal]
  1. VehicleIdentificationService.findOrCreate → Vehicle (hechos NLU)   [INTOCABLE]
  2. ResolveVehicleCatalogRef (app action, AGNÓSTICA de canal):
       VehicleCatalogResolver.candidates(VehicleQuery)  → navega el catálogo (§10)
       Disambiguator.disambiguate(candidates, textoCliente) → tri-estado
  3. el canal decide según el tri-estado (§9)
  4. on Resolved: Vehicle.version = label refinado (DOMINIO) ; token → store A
  5. createPendingQuote → el snapshot copia la versión refinada
```

Reglas que mantienen el desacople:
- El trigger va en una **app action propia y agnóstica** (`ResolveVehicleCatalogRef`), **no** inlineada en `VehicleIdentificationService`/Tool/Agent.
- `VehicleCatalogResolver` y `Disambiguator` reciben **VOs de dominio**, devuelven **tri-estado**, **no** hablan con el cliente, **no** leen `ai_state`.
- Dirección: `canal → action → resolver → dominio`. La flecha prohibida es `VehicleIdentifier* → resolver`.
- **Lo único específico del canal** ("si es Ambiguous, ¿pregunto?") vive en el adapter.

**Re-cotización colapsa a Tier 1:** como el label refinado se persiste en `Vehicle.version` (dominio), la próxima cotización entra con versión precisa → resuelve determinístico, sin LLM y sin molestar al cliente. Ese es el candado durable de UX (no el token).

---

## 8. El gate: *quotability* agnóstica, no el token (cierra el riesgo de "promesa rota")

Avanzar a coverage **sin** match contra el proveedor genera una **promesa rota**: el siguiente agente dice *"dame un momento que cotizo…"* y nunca pasa. Por eso identify-vehicle **sí resuelve contra los proveedores** antes de avanzar — pero el gate cuelga de una **abstracción agnóstica**, no del `version_id`.

Dos conceptos separados, **ambos agnósticos**:

| Concepto | Significa | ¿Acopla a Visred? |
|---|---|---|
| **vehicle identity** (`vehicle_identified`) | sabemos qué auto es (hechos de dominio) | No |
| **quotability** | ¿algún proveedor puede cotizarlo? | No — booleano sobre el tri-estado del resolver |

El gate de "puedo prometer cotización" = **quotability**, que **es** el tri-estado:

```
Resolved   → quotable           → avanzá, prometé la cotización
Ambiguous  → falta un dato      → pedí el HECHO DE DOMINIO (no el token), NO avances
NotFound   → NO quotable        → rama honesta: "no puedo cotizar ese modelo
(todos los      auto­máticamente, te derivo a un asesor / pedime X" — SIN promesa falsa
 proveedores)
error transitorio → reintentar; si persiste → tratar como NotFound
```

- El **nombre del proveedor y el token nunca** entran a `ai_state` ni al orquestador — solo el tri-estado agnóstico.
- **Identity ≠ quotability:** si Visred se cae, el bot NO dice "no pude identificar tu auto" (mentira — lo conocemos); dice "tengo tu auto, ahora no puedo cotizar". Con un 2º proveedor, quotable = "alguno resolvió", sin tocar el orquestador.
- La **ambigüedad se reencuadra como hecho de dominio faltante:** entre `1.6 ALLURE` y `1.6 ALLURE TIPTRONIC`, lo que falta es la **transmisión** (verdad del auto, agnóstica), no un token. Preguntar "¿es automática?" llena dominio; el `version_id` cae como consecuencia.
- **Modelado de estado (a cerrar en implementación):** probablemente `vehicle_identified` se mantiene como identity (NLU); la quotability se expresa como **precondición que el adapter chequea al transicionar** a coverage, surfaceando `Ambiguous`/`NotFound` como conversación — **sin** agregar un flag con olor a proveedor.

---

## 9. La cascada de 3 tiers (cierra el sub-tema de desambiguación)

Cascada de **costo/latencia ascendente** — barato → caro → humano. La meta de los Tiers 1-2 es **no molestar al cliente** (regla UX: si fue claro, confirmá y seguí).

| Tier | Qué hace | Dónde | Salida |
|---|---|---|---|
| **1 — exacto/léxico** | match normalizado contra labels de candidatos | dentro del resolver | `Resolved` solo si hay **un** hit dominante con **margen** |
| **2 — LLM** | razonamiento "sustracción por atributos" parseando `version_name` | `Disambiguator` (agnóstico) | `Resolved(suggested)` o `Ambiguous` |
| **3 — preguntar** | el agente pide un hecho de dominio | capa de canal (§8) | — |

Mapea 1:1 al tri-estado: Tiers 1+2 producen `Resolved | Ambiguous | NotFound`; Tier 3 solo se dispara con `Ambiguous`.

**Por nivel del catálogo (§10) la política difiere:**
- **marca / año** → Tier 1 (exacto normalizado; marca con diccionario de alias "VW"→"Volkswagen"). Casi nunca llegan al cliente.
- **modelo (`group`)** → **Tier 1 exacto normalizado** primero (los nombres numéricos `208`/`2008`/`3008` pegan exacto y es gratis). Fallback **Tier 2 LLM** — el LLM **sí** diferencia modelos numéricos en contexto automotriz; lo proscripto es usar **embedding/fuzzy como árbitro** en este nivel (mide forma → confunde `208`≈`2008`).
- **versión (`version_id`)** → el laburo real: Tier 1 si hay match único; si hay variantes pegadas (transmisión/motor) → Tier 2; si no alcanza → Tier 3.

**Entrelazado modelo↔trim — navegación beam:** la API obliga a elegir `group_id` antes de listar versiones. Si el modelo tiene 2-3 candidatos plausibles, **traer las versiones de todos** y desambiguar **conjuntamente al fondo** → una sola decisión y **una sola** pregunta al cliente. marca/año greedy; modelo+versión en beam.

---

## 10. El catálogo Visred — verificado live (2026-06-07)

Para cotizar, `vehicle.version_id` (token opaco). Se obtiene **navegando un árbol** de GETs autenticados (no es una tabla): `vehicle-types → brands → years → groups → versions`. Es un **árbol dependiente**: el `id` de cada nivel es path-param del siguiente. El **año es estructural** (nodo antes del modelo), no un filtro.

```
GET /v1/patrimoniales/vehicles/params/vehicle-types/
GET /v1/patrimoniales/vehicles/params/auto/brands/
GET /v1/patrimoniales/vehicles/params/auto/{brand_id}/years/
GET /v1/patrimoniales/vehicles/params/auto/{brand_id}/{year_id}/groups/
GET /v1/patrimoniales/vehicles/params/auto/{brand_id}/{year_id}/versions/?group_id={group_id}
```

**Shapes reales — heterogéneos entre niveles** (el doc 08 asumía `BaseParam[] {id,description}` para todos; **es incorrecto**). El adapter debe **normalizar cada nivel** a un VO neutro `{ref, label}`:

| Nivel | Shape real | ref | label |
|---|---|---|---|
| vehicle-types | `{vehicle_type_id: "auto"}` | `vehicle_type_id` | — |
| brands | `{id: 32, description: "PEUGEOT"}` (id **int**) | `id` | `description` |
| years | `{id: "2017", description: "2017"}` (id **string**) | `id` | `description` |
| groups | `{group_id: 34, group_name: "2008"}` | `group_id` | `group_name` |
| versions | `{version_id: "AATkvg5q", version_name: "1.6 SPORT THP"}` | `version_id` | `version_name` |

**Hallazgos que mandan en el diseño del Tier 2:**
1. **Las versiones NO traen atributos estructurados** — solo `version_name` como string libre (`"1.6 ALLURE TIPTRONIC"`). El desambiguador **parsea el label**: motor (`1.6`), trim (`SPORT/FELINE/ALLURE/ACTIVE`), transmisión (`TIPTRONIC`=auto), `THP`=turbo.
2. **El eje real de ambigüedad es transmisión** (`1.6 ALLURE` vs `1.6 ALLURE TIPTRONIC`), a veces motor — no el "trim VTI/THP/CVT" hipotético.
3. **Modelos numéricos casi idénticos** (`208`/`2008`/`3008`/`5008`/`508`): match exacto normalizado, **embedding/fuzzy proscripto como árbitro** (el LLM sí los diferencia).
4. **Catálogo origin-aware:** depende del usuario autenticado (permisos del productor). ⚠️ confirmar usuario de servicio vs personal.
5. **Rutas en singular** (gotcha verificado).

**Drift:** el catálogo es **dato vivo** (los `version_id` son tokens opacos que pueden rotar). El resolver lo consulta **en vivo** + **cache con TTL** (store B, §4); **no** se bakean los valores como fixtures de test. Si hiciera falta un fixture para un test de parseo, es **un sample mínimo de shape** (3-4 entradas de `versions/`), no el catálogo. Contra drift: un **smoke/contract test** aparte contra el sandbox que avise si el shape cambia.

**Ejemplo real (Peugeot 2008 2017, brand 32 / group 34):**
```
versions: 1.6 SPORT THP · 1.6 FELINE TIPTRONIC · 1.6 FELINE ·
          1.6 ALLURE TIPTRONIC · 1.6 ALLURE · 1.6 ACTIVE
```
- Cliente "Active" → solo `1.6 ACTIVE` → **Tier 1, sin ambigüedad**.
- Cliente "Allure" → `1.6 ALLURE` vs `1.6 ALLURE TIPTRONIC` → **Tier 2** ("TIPTRONIC es auto, me lo hubiera dicho → manual") o **Tier 3** ("¿es automática?").
