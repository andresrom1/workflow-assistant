# Documentación — workflow-assistant

> Punto de entrada de la documentación del backend. **`docs/v2/` es la fuente canónica**
> (refleja el estado actual: salida de `pas_mobile`/`pas-web`, entrada de `mango-mobile`,
> y la cotización/emisión vía **Visred API**). `docs/v1/` es **archivo histórico**.

> **Deprecación del chat web (`openai_chatkit`) — 2026-07-07.** OpenAI discontinúa **Agent Builder**
> a más tardar el **30-nov-2026**, así que el frontend web de ChatKit deja de tener continuidad.
> El asistente opera **solo por WhatsApp** por el momento. Consecuencias ya aplicadas: la landing
> pública de marca migró a este proyecto y **Laravel Reverb fue retirado** (sin cliente web no hay
> broadcasts; la entrega asíncrona de cotizaciones va por el pipeline de jobs de WhatsApp). Las
> referencias a `openai_chatkit` en esta doc quedan como **canal deprecado** — se conservan por ahora
> como registro del rol que cumplía cada capa.

---

> **¿Dónde estamos?** El estado de ejecución vive en [`../ROADMAP.md`](../ROADMAP.md) — fuente de verdad del avance y la deuda abierta.

## Operación

| Documento | Qué cubre |
|---|---|
| [despliegue.md](despliegue.md) | El despliegue actual en GCP paso a paso: VM, DNS/TLS, `.env.production`, secretos fuera de git, presupuesto y el script de reintento de Oracle. |
| [migracion-tier.md](migracion-tier.md) | **Planificado, sin ejecutar.** Bajar de tier de VM para cobrar el ahorro del refactor de colas: datos medidos (reposo), qué falta medir (el pico), opciones con el eje de latencia geográfica, procedimiento de corte y verificación. |
| [colas-y-workers.md](colas-y-workers.md) | **Fuente viva** de la arquitectura de colas: conexiones, los dos invariantes, la topología de 3 workers residentes + `background` bajo demanda, inventario de jobs, el pipeline de inbox de WhatsApp y el costo medido. Supera a `v2/09`. |
| [configuracion-hardcodeada.md](configuracion-hardcodeada.md) | Registro de la configuración marcada con `// opcion-de-configuracion`. |

## Contexto de la arquitectura v2

Cambios estructurales respecto de v1:

1. **`pas_mobile` y `pas-web` fueron extirpados del proyecto.** Quedan documentados como *legacy, extirpado* en cada doc (sin huella en código de `workflow-assistant`); lo que dependía de ellos queda marcado en cada doc.
2. **Visred API** entra en juego: cotiza y emite pólizas **sin intervención humana**, reemplazando el rol que cumplían `pas_mobile`/`pas-web` (cotización manual del PAS + fallback).
3. **`mango-mobile`** (app Flutter) entra: cartera, siniestro, contactos de emergencia, tracking en vivo y Cuenta Compartida.

Canales consumidores que se usan como etiqueta en toda la doc:
`workflow-assistant` (WhatsApp, **canal activo**) · `mango-mobile` · `openai_chatkit` *(deprecado — sunset Agent Builder 30-nov-2026)* · `pas_mobile` *(legacy, extirpado)* · `pas-web` *(legacy, extirpado)*.

---

## Índice canónico (`docs/v2/`)

| # | Documento | Qué cubre |
|---|---|---|
| 01 | [01-database-schema.md](v2/01-database-schema.md) | Schema vivo de PostgreSQL (+pgvector) agrupado por dominio, con canal por grupo y mapa de relaciones. |
| 02 | [02-endpoints.md](v2/02-endpoints.md) | Todos los endpoints (`api`, `mobile`, `web`, `auth`) con su canal consumidor. |
| 03 | [03-services.md](v2/03-services.md) | Lógica de negocio (capa Service) por dominio y canal. |
| 04 | [04-repositories.md](v2/04-repositories.md) | Acceso a datos (capa Repo) por canal. |
| 05 | [05-controllers.md](v2/05-controllers.md) | Controllers HTTP por canal. |
| 06 | [06-adapters.md](v2/06-adapters.md) | Adapters de proveedor de IA + el Adapter Visred pendiente. |
| 07 | [07-consolidado-v1.md](v2/07-consolidado-v1.md) | Consolidación de toda la doc v1 + tabla "qué cambió de v1 a v2". |
| 08 | [08-visred-quote-adapter.md](v2/08-visred-quote-adapter.md) | Diseño del patrón Adapter para enchufar la cotización contra Visred (lado Visred verificado contra el schema; lado workflow-assistant pendiente). |
| 09 | [09-queues-architecture.md](v2/09-queues-architecture.md) | **⚠️ Superado (2026-08-22)** — describe el diseño de *un worker por cola*. La verdad actual está en [colas-y-workers.md](colas-y-workers.md); este queda como registro histórico con la tabla de qué cambió. |
| 10 | [10-modelo-dominio-cotizacion-emision.md](v2/10-modelo-dominio-cotizacion-emision.md) | Modelo de dominio **agnóstico de proveedor** para cotización + emisión: límites de contexto (compañía = system of record), puertos `QuotationProvider`/`EmissionProvider`, modelo temporal, referencia mínima + on-demand. **Fase 0 (gate) de la integración Visred.** |
| 11 | [11-modelo-cliente-consolidacion-datos.md](v2/11-modelo-cliente-consolidacion-datos.md) | El `Customer` como **fuente de verdad** consolidada: captura multi-fuente (chat + checkout), modelo de pesos por fuente (checkout ≈ admin > chat), provenance + audit log + divergencias, y el domicilio tomador/riesgo con mapeo en el adapter (CP de guarda = el que tarifa). |
| 12 | [12-deduplicacion-merge-clientes.md](v2/12-deduplicacion-merge-clientes.md) | **Dedup/merge de filas `Customer` duplicadas** (misma persona en dos filas por canales distintos). Causa raíz, data-fix aplicado (caso checkout #4), `CustomerMergeService` vs `CustomerConsolidationService`, reconciliación solo por claves fuertes (dni/email, no teléfono) y plan de implementación pendiente. |

**Orden de lectura sugerido para la cirugía:** 01 (datos) → 05/03/04 (capas) → 02 (superficie HTTP) → 06 + 08 (el seam de Visred, donde más va a doler el cambio) → 10 (modelo agnóstico de cotización/emisión, gate de implementación) → 07 (historia y deltas).

> **Nota:** `docs/v2/10` §4/§5 asumía cartera **on-demand** (compañía consultable por `policy_number`).
> Esa premisa quedó **invertida** en v3 (no existe ese endpoint → cartera manual asistida por extracción).
> Ver `docs/v3/`.

---

## Diseño v3 (post-emisión — mantenimiento de cartera)

> **OJO — el diseño 01/02 se DESCARTÓ en implementación.** Lo construido (Fases 1–5, 2026-06-21,
> ver `ROADMAP.md`) es **mantenimiento de documentación de póliza** (doc **03**): el modelo de
> transacciones/fold/extractor de 01/02 no se implementó (sin consumidor; la historia ya vive en
> Visred + la compañía + la pila de PDFs). 01/02 quedan como **diseño histórico**.

| # | Documento | Qué cubre |
|---|---|---|
| 01 | [01-modelo-mantenimiento-cartera-endosos.md](v3/01-modelo-mantenimiento-cartera-endosos.md) | **(Histórico, descartado)** Modelo de dominio: `Risk → Poliza → Transacción (corriente)`, fold, jerarquía temporal, ejes de estado, taxonomía de endosos, frontera invariante/variable. |
| 02 | [02-extractor-documentos-poliza.md](v3/02-extractor-documentos-poliza.md) | **(Histórico, descartado)** El extractor de documentos → hechos de dominio: pipeline clasificar→extraer→confirmar, mapeo por compañía, agnóstico de canal. |
| 03 | [03-entrega-documentacion-app.md](v3/03-entrega-documentacion-app.md) | **(Implementado)** El feature real: cargar/mantener la documentación (PDFs) y entregarla a la app. Contrato backend↔Flutter — API mobile (`tiene_documentos`, `/documentos`, `user.id`), push FCM `account-{id}`, y qué falta en Flutter. |
| 04 | [04-ingesta-local-documentos.md](v3/04-ingesta-local-documentos.md) | **(Implementado, Fases 1–4).** Ingesta local: un script Python (`ingestor/`) detecta PDFs de pólizas en la PC y los manda (texto + PDF) a `POST /api/ingesta/documentos` (Sanctum). **Fase 4 (2026-07, en evaluación):** la clasificación/extracción de campos pasó de un parser regex por compañía (v5, retirado) a un LLM server-side (`deepseek-chat`, agente `IngestaExtractorAgent` + job `ExtractIngestedDocument`), con validación determinística por campo y descarte automático de no-pólizas (`descartado_auto`). El server estaciona en `ingested_documents` (dedup por hash + PDF en R2) y, con confirmación humana en `/ingesta-pendientes`, materializa `Customer→Risk→Poliza→PolicyDocument` (vía dedup), agrupando por `compañía+numero_poliza` con fallback a `patente`. |
| 05 | [05-modelo-insurable-asset.md](v3/05-modelo-insurable-asset.md) | **(Implementado, 2026-07-15).** Separa `InsurableAsset` (bien, identidad) de `Risk` (exposición), modelo ACORD simplificado. Identidad por tipo (`AssetType::naturalKey`; vehicle=patente normalizada, resto sin clave → un asset por contrato). Dedup consolidada en `PolicyChainResolver::resolveRisk`, único punto usado por ingesta/reporte/emisión/alta manual/seeder. **Pendiente:** mapeo producto/ramo → `AssetType` y materialización de filas no-vehiculares del reporte (sin cambios en este release). |

---

## Capas de la arquitectura (recordatorio)

```
Proveedor de IA (WhatsApp — ChatKit deprecado)
        ↓
   Adapter        ← único que conoce el canal
        ↓
   Service        ← lógica de negocio, agnóstica del canal
        ↓
   Repository     ← acceso a datos, agnóstico del canal
```

Visred se inserta como una implementación más detrás del puerto de cotización (`QuotationPort`),
elegida por config — el dominio no sabe que atrás hay Visred. Ver `08-visred-quote-adapter.md`.

---

## Archivo histórico (`docs/v1/`)

Diseño anterior (prioridad PAS vía app móvil + fallback API). **No usar como verdad operativa** —
todo su contenido está consolidado y actualizado en [v2/07-consolidado-v1.md](v2/07-consolidado-v1.md).
Se conserva solo como registro de decisiones (incluye `adr/001-quote-provider-refs.md`, que **sigue vigente**).

| Documento v1 | Estado |
|---|---|
| `database_erd.md` | Superado por `v2/01-database-schema.md`. |
| `quote-resolution-architecture.md` | Histórico — flujo PAS/app móvil (legacy). |
| `quote-resolution-walkthrough.md` | Histórico. |
| `quote-timeout-architecture.md` | Histórico — el timer sigue en el Core, el destino cambió a Visred. |
| `mobile-onboarding-ux.md` | Vigente (email = llave de la app). Resumido en el consolidado. |
| `deuda-whatsapp-dispatch.md` | **Deuda abierta** — 3 TODO de dispatch sin cablear (ver consolidado §6). |
| `adr/001-quote-provider-refs.md` | **Vigente** — invariante de aislamiento de datos de proveedor. |
