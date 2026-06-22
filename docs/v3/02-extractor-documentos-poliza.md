# 02 — Extractor de documentos de póliza → hechos de dominio (v3, "punto 6")

> **Estado: diseño (gate), no implementado.** Complementa
> [`01-modelo-mantenimiento-cartera-endosos.md`](01-modelo-mantenimiento-cartera-endosos.md):
> el doc 01 define **qué** hechos hay en una póliza/endoso; éste define **cómo** se pueblan **sin
> carga manual de campos**, a partir del documento que la compañía ya emite.

---

## 1. Propósito

El documento (frente de póliza, endoso, constancia, cancelación) **es el artefacto autoritativo**:
la compañía es el System of Record y ese PDF es lo que firma el contrato. La idea es **invertir el
trabajo**: en vez de que un operador tipee vigencia, prima, suma, estado, etc., **se carga el
documento y el backend lo clasifica y extrae los hechos de dominio**, dejándolos pre-cargados para
que el operador **solo confirme**.

Esto ataca el costo manual identificado en el doc 01 §1/§10: la **detección** ya es derivable y la
**confirmación** es irreducible, pero la **actualización** (tipeo) se reduce casi a cero si el
documento la pre-llena.

## 2. Principio de desacople (dónde vive y dónde NO)

El extractor es un **componente separado, agnóstico de canal**. Opera sobre el **documento** (un
artefacto de dominio) y produce **hechos de dominio** (los del doc 01). Reglas duras:

- **NO** habla con el orquestador de WhatsApp, los agentes (`*Agent`), las tools (`*Tool`) ni con
  `conversations.metadata.ai_state`.
- **NO** habla con Visred ni conoce ninguna API de compañía. Lee el PDF, no consulta a nadie.
- Dependencia: `Extractor → dominio ← consumidores`. Nunca una arista directa a la capa de canal.

Es la misma regla general de `CLAUDE.md` ("Principio de desacople") que aplica al resolver de
catálogo y a los services/repos.

## 3. Pipeline de dos pasos

La evidencia de Sancor (tres tipos de documento de la misma póliza) muestra que **el tipo de
documento es un eje en sí mismo**. Por eso el extractor tiene **dos pasos**, no uno:

```
Documento (PDF)
   │
   ├─ Paso 1 — CLASIFICAR: ¿qué compañía? ¿qué tipo de documento?
   │             (frente/carátula · endoso refacturación · endoso modificación ·
   │              cancelación/rescisión · constancia/tarjeta)
   │
   └─ Paso 2 — EXTRAER: aplicar el mapper de (compañía × tipo de documento)
                 → produce una TRANSACCIÓN de dominio (abre Poliza | endoso | terminal), doc 01 §3
                 → mapea etiquetas crudas → `tipo` de dominio (doc 01 §6/§8)
                 → guarda la etiqueta literal como metadata
   │
   └─ Paso 3 — CONFIRMAR (humano en el loop): los hechos quedan como BORRADOR pre-llenado;
                 el operador valida/corrige; recién entonces se asientan.
```

## 4. Tipos de documento (de los casos testigo)

| Tipo de documento | Transacción que produce | Visto en |
|---|---|---|
| Frente / carátula de póliza | **`emisión`/`renovación`** → **abre Poliza** + 1ª transacción (término, 1er período, prima/premio, suma, cobertura, forma de pago, riesgo, back-ref) | San Cristóbal, Triunfo, Río Uruguay, Sancor |
| Endoso de refacturación | **`refacturación`**: nuevo período tarifario + prima/premio | San Cristóbal (-1), Triunfo |
| Endoso de modificación | **`modificación`**: deltas (suma, cobertura, datos), `fecha_inicio` | Sancor (endoso 1) |
| Cancelación / rescisión | **`anulación`/`rescisión`** (terminal): estado + fecha de efecto + devolución (montos negativos) | San Cristóbal (31967204) |
| Constancia / tarjeta de circulación | No es transacción: **snapshot/proyección** del estado vigente / prueba de cobertura (alimenta la **entrega de documentación**) | Sancor (constancia), San Cristóbal (tarjeta) |

## 5. Humano en el loop (no auto-commit)

Las fechas, montos y estados extraídos son **datos de plata y legales** (vigencia, prima, fecha de
anulación). Extraerlos con LLM y **asentarlos sin revisión es riesgoso**. El flujo correcto:

```
documento → extractor pre-llena un BORRADOR → el operador confirma/corrige → se asienta el hecho
```

Esto elimina el grueso del tipeo (que es lo que se busca) **sin** renunciar a la corrección en lo
que importa. La confianza de la extracción puede mostrarse por campo para guiar la revisión.

## 6. Seed computado vs hecho extraído (capas)

El doc 01 computa fechas/estado. El extractor las **sobrescribe con el hecho real** cuando hay
documento:

```
Capa 1 — seed:      término/fechas computados de la cadencia conocida (default barato, disponible al emitir)
Capa 2 — extraído:  el documento ingerido y CONFIRMADO OVERRIDEA el seed con la fecha/monto/estado real
```

La fórmula deja de ser "la verdad" y pasa a ser el valor inicial mientras no haya documento
procesado. Cuando el documento entra, manda el documento (es el SoR de la compañía). Esto resuelve
la divergencia "fórmula vs fecha real" señalada en el modelado.

## 7. Unificación de orígenes

Dos orígenes de documento, **un solo extractor**:

- **Captura en la emisión Visred** (automática, `docs/v2/hallazgos-visred-task-type.md`): el PDF
  capturado al emitir entra al mismo pipeline.
- **Carga manual del admin** (renovaciones/endosos que ocurren en la compañía sin Visred): el
  operador sube el PDF y entra al mismo pipeline.

Ambos producen los mismos hechos de dominio. Ver `~/.claude/memory/project_documentos_poliza_fuentes`.

## 8. Mapeo por compañía

Cada compañía es un **mapper** que traduce su representación (la columna VARIABLE del doc 01 §8) al
vocabulario de dominio. El mapper resuelve, por compañía × tipo de documento:

- dónde vive el **término de cobertura** (campo dedicado `PRORROGABLE HASTA` / `Prórroga
  automática hasta` vs la vigencia de la emisión vs semestral),
- el **modelo de facturación** (refacturación vs premio único en cuotas),
- las **etiquetas** de operación/concepto → vocabulario de dominio,
- la **numeración** de transacción y las capas de identidad,
- la representación de **cobertura** (lista de límites vs código tipo `B4`/`Sigma`),
- **prenda** (campo vs cláusula), **hora de corte**, etc.

El dominio expone los hechos normalizados; la etiqueta cruda de la compañía va como **metadata**,
nunca como vocabulario de dominio (regla agnóstica, doc 01 §2).

## 9. Relación con la infraestructura existente

Existe un precedente de "PDF → LLM" en el proyecto: `ExtractCoverageDocumentText` +
`ChunkAndEmbedService` hacen **transcripción literal para RAG** de coberturas. **No es lo mismo**:
acá hace falta **clasificación + extracción estructurada de campos** (tipo de documento, fechas,
montos, deltas). Es un **uso nuevo del mismo proveedor/patrón**, no el mismo job, y **no se mezcla**
con el RAG de coberturas.

## 10. Fuera de scope / abierto

- **Cobranza** (cuotas, vencimientos de pago): se captura el dato si está en el documento, **no se
  trackea** ni se usa para computar suspensión (eje B, doc 01 §12).
- **Entrega de documentación de póliza al cliente**: feature aparte, scope sin definir; el extractor
  la habilita (constancia/tarjeta), pero la decisión de qué se entrega y cómo es otra discusión. No
  confundir con los **slots offline libres** (ver `~/.claude/memory/project_dos_features_documentacion`).
- **Umbral de confianza / auto-confirmación** — ✅ **cerrado: default conservador.** En v3 toda
  extracción pasa por confirmación del operador (humano en el loop, §5); **no hay auto-confirmación**.
  **Condición de reingreso:** métricas de precisión por (compañía × tipo de documento × campo) que
  justifiquen auto-confirmar campos de baja criticidad.
