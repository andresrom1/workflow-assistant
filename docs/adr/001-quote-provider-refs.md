# ADR-001: Extracción de datos de proveedor a tabla `quote_provider_refs`

**Fecha:** 2026-02-24
**Estado:** Implementado
**Contexto:** workflow-assistant — capa de cotización

---

## Problema

El agente de IA (OpenAI vía ChatKit) llamaba a la herramienta `getQuote()` y recibía en la respuesta el campo `external_quote_id: "32"`. Este valor es el ID interno de pas-web para el lote de cotización — un identificador que pertenece al scope de pas-web, no al de workflow-assistant. El agente confundía este valor con el ID de cotización del sistema (`Quote.id = 10`) y lo usaba en llamadas posteriores como `checkout()`, causando errores.

---

## Análisis de causa raíz

Las alternativas de cotización que llegan de pas-web eran persistidas en **dos lugares simultáneamente**:

1. `quotes.raw_response` (columna JSON) — almacenaba el payload completo de pas-web incluyendo `external_quote_id` y `external_code` dentro del array de alternativas.
2. `quote_alternatives` (tabla relacional) — almacenaba cada alternativa como fila normalizada, también con `external_quote_id` y `external_code` como columnas propias.

Esta duplicación creaba un riesgo estructural: cualquier serialización implícita del modelo `Quote` o `QuoteAlternative` podía exponer estos identificadores de proveedor al contexto del agente de IA.

---

## Opciones evaluadas

### Opción A — `$hidden` en los modelos
Agregar `protected $hidden = ['external_quote_id', 'raw_response']` a los modelos.

**Rechazada porque:** `$hidden` es un mecanismo de seguridad diseñado para ocultar campos sensibles (ej: passwords). Usarlo para prevenir fuga de scope entre sistemas es un code smell. Oculta el síntoma sin resolver la duplicación subyacente y puede generar comportamientos inesperados en otros contextos donde los datos sí son necesarios.

### Opción B — Mapeo explícito en el Adapter (ya implementado parcialmente)
El `AgentToolAdapter::getQuote()` ya usaba un mapeo explícito de campos, excluyendo `external_quote_id`. Correcto como primera capa de defensa, pero insuficiente porque `raw_response` en `quotes` podía filtrarse por otras rutas.

### Opción C — Tabla de auditoría `quote_provider_refs` ✅ **Elegida**
Extraer todos los datos de proveedor a una tabla dedicada. Los modelos de dominio quedan limpios.

---

## Decisión

Se creó la tabla `quote_provider_refs` como repositorio exclusivo de datos de proveedor, con granularidad de una fila por Quote (no por alternativa):

```
quote_provider_refs
├── id
├── quote_id → quotes.id (FK, cascade delete)
├── external_quote_id   — ID de lote de pas-web (ej: "32")
├── raw_response (JSON) — Payload completo de pas-web (incluye alternativas con sus external_code)
├── created_at
└── updated_at
```

**Nota sobre granularidad:** `external_quote_id` es un campo de nivel lote (todos los alternatives del mismo quote comparten el mismo valor). `external_code` es por alternativa (SKU único), pero se almacena dentro de `raw_response.alternatives` en lugar de como columna propia. Para obtener el `external_code` de una alternativa seleccionada al momento de contratar, se navega `QuoteProviderRef.raw_response.alternatives` matching por campos de dominio (aseguradora, normalized_grade, etc.).

---

## Cambios implementados

| Archivo | Cambio |
|---|---|
| `database/migrations/2026_02_24_*` | Crea `quote_provider_refs`; elimina `external_quote_id` y `external_code` de `quote_alternatives`; elimina `raw_response` de `quotes` |
| `app/Models/QuoteProviderRef.php` | Nuevo modelo con relación `belongsTo(Quote)` |
| `app/Models/Quote.php` | Elimina `raw_response` de fillable/casts; agrega `providerRef(): HasOne` |
| `app/Models/QuoteAlternative.php` | Elimina `external_quote_id` y `external_code` de fillable |
| `app/Repositories/QuoteRepository.php` | `saveResults()` ahora: (1) elimina campos de proveedor antes de `createMany()`, (2) crea `QuoteProviderRef` con el payload completo |
| `app/Services/QuoteService.php` | `getRaw()` usa `providerRef` y `alternatives` en lugar de `raw_response` |
| `resources/views/quotes/show.blade.php` | Vista de auditoría usa `$quote->providerRef?->raw_response` |
| `app/Adapters/OpenAI/AgentToolAdapter.php` | Mapping explícito sin `external_code`; `tool_output` menciona explícitamente `quote_id` interno |

---

## Invariantes del diseño

- **Modelos de dominio (`Quote`, `QuoteAlternative`) nunca contienen IDs de proveedor.** El agente de IA solo ve IDs internos del sistema.
- **`quote_provider_refs` es append-only por diseño** (se recrea en reintentos, no se modifica). Es el único lugar desde donde se recupera `external_quote_id` para el proceso de emisión en pas-web.
- **El adapter es la única puerta de salida de datos hacia el agente.** El mapeo explícito en `getQuote()` es la defensa final y documenta exactamente qué ve el agente.

---

## Consecuencias

**Positivas:**
- El agente de IA recibe solo IDs internos del sistema → no puede confundir scopes
- Eliminación de duplicación de datos entre `raw_response` y `quote_alternatives`
- Separación limpia: dominio vs. auditoría de proveedor
- `QuoteProviderRef` puede enriquecerse en el futuro (ej: `created_at` del proveedor, versión de API, tenant) sin contaminar los modelos de dominio

**Compromisos:**
- Para obtener `external_code` de una alternativa específica al contratar, se requiere parsear `raw_response.alternatives` en `QuoteProviderRef`. No es una lookup directa por `quote_alternative_id`.
- Si en el futuro el proceso de emisión necesita frecuentes lookups por alternativa, se puede agregar una tabla `quote_alternative_provider_refs` adicional sin impacto en los modelos de dominio existentes.
