# Documentación — workflow-assistant

> Punto de entrada de la documentación del backend. **`docs/v2/` es la fuente canónica**
> (refleja el estado actual: salida de `pas_mobile`/`pas-web`, entrada de `mango-mobile`,
> y la cotización/emisión vía **Visred API**). `docs/v1/` es **archivo histórico**.

---

> **¿Dónde estamos?** El estado de ejecución vive en [`../ROADMAP.md`](../ROADMAP.md) — fuente de verdad del avance y la deuda abierta.

## Contexto de la arquitectura v2

Cambios estructurales respecto de v1:

1. **`pas_mobile` y `pas-web` salieron del proyecto.** Lo que dependía de ellos queda marcado *legacy* en cada doc.
2. **Visred API** entra en juego: cotiza y emite pólizas **sin intervención humana**, reemplazando el rol que cumplían `pas_mobile`/`pas-web` (cotización manual del PAS + fallback).
3. **`mango-mobile`** (app Flutter) entra: cartera, siniestro, contactos de emergencia, tracking en vivo y Cuenta Compartida.

Canales consumidores que se usan como etiqueta en toda la doc:
`openai_chatkit` · `mango-mobile` · `workflow-assistant` · `pas_mobile` *(legacy)* · `pas-web` *(legacy)*.

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

**Orden de lectura sugerido para la cirugía:** 01 (datos) → 05/03/04 (capas) → 02 (superficie HTTP) → 06 + 08 (el seam de Visred, donde más va a doler el cambio) → 07 (historia y deltas).

---

## Capas de la arquitectura (recordatorio)

```
Proveedor de IA (ChatKit / WhatsApp)
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
