# v3/04 — Ingesta local de documentos de póliza (lado servidor)

> **Estado: IMPLEMENTADO (Fases 1–3), suite verde.** El lado cliente (script Python) está
> hecho y andando: ver `ingestor/` (repo aparte, `ingestor/README.md`, `ingestor/app/v5/USO.md`
> y `ingestor/app/v5/SERVIDOR.md` — la contraparte server documentada para el cliente).
> Este doc describe el **endpoint y la lógica de alta**, ya construidos en `workflow-assistant`.
> El contrato JSON (§2) se definió acá y es la fuente de verdad; el ingestor lo cumple. El
> transporte HTTP (§2-bis) documenta cómo lo envía el cliente. El **mapa de archivos** de la
> implementación y el estado de las preguntas abiertas están en §6 y §7.
>
> Encaja en v3 (post-emisión / mantenimiento de cartera). Es el productor automático que
> alimenta la cola de Pendientes descrita en [`03-entrega-documentacion-app.md`](03-entrega-documentacion-app.md).
>
> **Garantías que ya da el cliente (no las re-implementes, pero contás con ellas):**
> - **Solo manda pólizas del corpus.** Si no detecta una aseguradora conocida (por CUIT del
>   emisor o alias), el PDF **no se sube** — facturas, resúmenes, etc. nunca llegan al endpoint.
> - **Dedup local por hash.** El cliente lleva su propio `state.json` y no reenvía un PDF ya
>   subido. Aun así el server **debe** dedup por `hash_sha256` (el cliente puede reinstalarse o
>   reintentar tras un 5xx) — ver §4.
> - **Ventana temporal.** Solo procesa PDFs de la carpeta Descargas creados hace ≤ 90 días.
> - **Reintentos.** Ante error de red/5xx reintenta hasta 3 veces con backoff y, si igual falla,
>   reintenta el archivo en la corrida del día siguiente → **el endpoint tiene que ser idempotente.**

---

## 1. Qué resuelve

Dar de alta y documentar pólizas que **no** pasaron por el flujo de WhatsApp (renovaciones,
endosos, pólizas viejas, cambios de compañía). Un script local detecta los PDFs en la PC del
productor, extrae datos de forma determinística (sin LLM) y los manda acá. El server:

1. da de alta lo que falta (`Customer → Risk → Poliza → PolicyDocument`),
2. adjunta el PDF,
3. deja todo en **Pendientes** para **confirmación manual** (modo de arranque).

**Reparto de responsabilidades:** el parseo vive en el cliente (local, gratis). El server
**no re-extrae**; recibe el contrato + el PDF, valida, hace find-or-create y encola.
Ver el principio de desacople en `workflow-assistant/CLAUDE.md` y `docs/v2/10`.

---

## 2. El contrato de entrada

`POST` **multipart/form-data**, autenticado con token Sanctum:
- campo `metadata`: string JSON (shape v1, abajo)
- campo `file`: el PDF original (byte por byte)

```json
{
  "schema_version": 1,
  "documento": { "kind": "poliza", "compania": "Sancor Seguros",
                 "numero_poliza": "000031184413", "endoso_numero": null },
  "tomador":   { "tipo_persona": "fisica", "first_name": "SICOT LEONARDO",
                 "last_name": "FABIO", "razon_social": null,
                 "documento_tipo": "DNI", "documento_numero": "21407965" },
  "riesgo":    { "tipo": "vehicle", "patente": "AB235OR", "marca": null,
                 "modelo": null, "version": null, "year": "2017",
                 "combustible": null, "uso": null, "codigo_postal": null },
  "fechas":    { "emision": null, "vigencia_desde": "2026-02-19",
                 "vigencia_hasta": "2027-02-19" },
  "archivo":   { "nombre_original": "Caratula Anual (5).pdf",
                 "hash_sha256": "…", "detectado_en": "2026-06-24T08:00:00-03:00" },
  "extraccion":{ "parser": "policy_parser_v5", "campos_no_extraidos": ["marca","modelo","emision"] }
}
```

Reglas del contrato (las garantiza el cliente; el server **igual revalida**):
- Todo campo siempre presente, `null` si no se extrajo.
- `kind` ∈ `PolicyDocumentKind` (`poliza`, `certificado`, `endoso`, `cupon`,
  `circulation-card`, `otro`). **No existe "renovacion"**: una renovación es una `Poliza`
  nueva que apunta a `contrato_anterior_id`.
- `documento_numero` sin puntos/guiones. Fechas ISO `YYYY-MM-DD`.

---

## 2-bis. Transporte HTTP (cómo viaja el contrato de §2)

El **contrato JSON es el de §2, definido acá** (fuente de verdad). El ingestor fue construido
para cumplirlo: su `parser.py::_build` produce exactamente esa forma, verificado campo por
campo. Esta sección no redefine el contrato — solo fija el **transporte** a nivel cable (cómo
lo envuelve y manda el `Uploader` del cliente), que es lo que el endpoint tiene que aceptar.

**Request:**

```
POST <endpoint.url>                         # configurable en ingestor/app/v5/config.yaml
Content-Type: multipart/form-data
Authorization: Bearer <token Sanctum>       # header, esquema Bearer
Accept: application/json
```

Cuerpo `multipart/form-data` con **exactamente dos partes**:

| Parte | Tipo | Contenido |
|---|---|---|
| `metadata` | campo de texto | el JSON del contrato (§2) serializado como **string** (`json.dumps`, UTF-8, `ensure_ascii=false`) |
| `file` | archivo | el PDF original byte-por-byte; filename = `archivo.nombre_original`; mime `application/pdf` |

> Ojo: `metadata` viaja como **string JSON dentro de un form field**, no como body JSON.
> En Laravel: `json_decode($request->string('metadata'), true)` + `$request->file('file')`.

**Auth:** token Sanctum en `Authorization: Bearer …`. El cliente lo resuelve desde la variable
de entorno `SANCTUM_TOKEN`. Hay que emitir un token (o un guard dedicado de ingesta) para esto.

**Timeout / reintentos del cliente:** timeout 60 s; hasta 3 intentos con backoff exponencial
(2 s, 4 s) ante `RequestException`. El cliente marca el archivo como subido **solo** si la
respuesta es 2xx (`raise_for_status`). Implicancias para el server:

**Response esperada por el cliente:**
- **Éxito**: cualquier **2xx**. Si hay body, debe ser **JSON** (el cliente hace
  `response.json()` y lo loguea); si no hay body, también vale. Recomendado: `200`/`201` con
  `{"status": "...", "poliza_id": ..., "policy_document_id": ..., "pendiente": true|false}`.
- **Duplicado** (mismo `hash_sha256` ya recibido): responder **2xx idempotente** (no 4xx), sin
  duplicar nada. Un 4xx haría que el cliente lo cuente como error y lo reintente al otro día.
- **Error de validación del contrato**: 422 con detalle. El cliente lo cuenta como error y
  reintentará el archivo en la próxima corrida (no es bloqueante, pero conviene que el PDF +
  metadata cruda igual se persista para Pendientes en vez de rechazarse — ver §4).
- **Auth inválida**: 401/403. El cliente reintentará; revisar token.

**Valores de `kind` que realmente emite el parser v5** (subconjunto del enum del server):
`poliza`, `certificado`, `circulation-card`, `cupon`, y `otro` (cuando no pudo extraer/clasificar).
Nunca emite `endoso` hoy (una renovación llega como `poliza` nueva). El campo
`extraccion.razon` aparece solo en los casos degradados (`pdf_sin_texto`,
`compania_no_detectada`) — pero esos **no se suben**, así que en la práctica no llegan.

---

## 3. Mapeo a entidades (cadena de creación)

**La póliza NO cuelga del cliente directo.** La cadena real es de cuatro niveles:

```
Customer ──< Risk (bien asegurado) ──< Poliza ──< PolicyDocument
```

| JSON | Entidad / columna |
|---|---|
| `tomador.*` | `customers` (find-or-create por `documento_numero`, **vía dedup**) |
| `riesgo.patente` | clave del `risks` (find-or-create); resto → `risks.metadata` |
| `documento.compania` | `polizas.company` |
| `documento.numero_poliza` | `polizas.numero` |
| `fechas.emision` | `polizas.emitida_en` |
| `fechas.vigencia_hasta` | `polizas.vigencia` (vencimiento) |
| `fechas.vigencia_desde` | `polizas.metadata` (no hay columna propia) |
| `documento.kind` | `policy_documents.kind` |
| `archivo` (PDF) | `policy_documents` (sube a R2) + dedup por `hash_sha256` |
| `extraccion.campos_no_extraidos` | decide gate Pendientes vs alta automática |

Modelos: `app/Models/{Customer,Risk,Poliza,PolicyDocument}.php`. Enums:
`app/Enums/{PolicyDocumentKind,PolicyDocumentSource,PolizaEstado}.php`.

---

## 4. Flujo del endpoint (find-or-create)

```
recibe (metadata json + file pdf) + token Sanctum
  │
  ├─ revalidar contrato (kind en enum, fechas ISO, doc 8/11 dígitos, hash presente)
  ├─ dedup de archivo: ¿hash_sha256 ya existe en policy_documents? → 200 idempotente, no duplica
  │
  ├─ ¿claves mínimas presentes y válidas? (compania + numero_poliza + documento_numero)
  │     NO → encolar a Pendientes SIN crear nada (solo persistir el PDF + metadata cruda)
  │     SÍ ↓
  │
  ├─ cliente = find por documento_numero  →  si no existe, crear VÍA dedup/merge
  │            (NUNCA crear `customers` directo — usar la lógica de docs/v2/12)
  ├─ risk    = find por patente (dentro del cliente)  →  si no existe, crear (metadata del riesgo)
  ├─ poliza  = find por (company + numero)  →  si no existe, crear liviana
  │            estado inicial a definir; origen marcado (ver §6)
  └─ adjuntar PolicyDocument (kind, source=local_ingesta, R2 storage_path/url, original_filename)
  →  TODO el alta queda en Pendientes hasta tu confirmación (modo de arranque, §5)
```

### Agrupación de documentos del mismo contrato

Un contrato llega en **varios PDFs** (frente + tarjeta + cupón + anexo). Se unen por
**`numero_poliza`** y se acumulan como `PolicyDocument` distintos de la misma `Poliza`
(los documentos NO se reemplazan, conviven — ver enum `PolicyDocumentSource`).

**Fallback obligatorio por `patente`:** algunos documentos no traen número
(Experta no extrae número; la tarjeta de Mercantil tampoco), pero **sí traen la patente**
—que muchas veces NO está en el frente—. Sin el fallback por patente, esos PDFs quedan
sueltos. Regla: unir por `numero_poliza`; si falta, intentar por `patente` (= Risk).

---

## 5. Modo de arranque: confirmación manual

**Nada se crea en firme sin confirmación humana.** Todo cae en la cola de **Pendientes**
(`resources/js/pages/PolicyDocuments/Pendientes.vue` + `PolicyDocumentController`), donde el
admin revisa el alta sugerida (cliente/risk/póliza pre-llenados desde el contrato) y confirma
o corrige. Recién ahí se materializan/confirman los registros.

Cuando el parser demuestre acierto consistente en producción, se afloja a **alta automática**
cuando las claves vienen completas y válidas. Es reversible solo en ese sentido
(manual → automático), por eso se arranca conservador.

---

## 6. Cambios en el código — IMPLEMENTADO

Mapa de la implementación (Fases 1–3). Sin DTOs: el controller arma `array<string,mixed>`.

**Fase 1 — endpoint + validación**
- Ruta `POST /api/ingesta/documentos` con `auth:sanctum` — `routes/api.php`.
- `App\Http\Controllers\PolicyDocumentIngestaController` — valida el multipart, decodifica el
  contrato, minimiza 422 (degrada a Pendientes lo incompleto), coerce `kind` desconocido → `otro`.
- Auth: `User` usa `HasApiTokens`; comando `php artisan ingesta:token {email}`
  (`App\Console\Commands\IssueIngestaToken`) emite el PAT.

**Fase 2 — staging + dedup + R2**
- Tabla `ingested_documents` (migración `2026_06_25_000001_*`) + `App\Models\IngestedDocument`
  + `App\Enums\IngestaStatus` (`pendiente|confirmado|descartado`). `hash_sha256` **único**.
- `App\Services\IngestaDocumentoService::stage()` — dedup por hash (idempotente), sube el PDF a
  R2 (`ingesta/{hash}.pdf`), crea la fila pendiente con `payload` crudo + columnas denormalizadas.

**Fase 3 — confirmación humana + materialización**
- `App\Enums\PolicyDocumentSource::LocalIngesta = 'local_ingesta'`.
- `App\Services\IngestaConfirmacionService` — `confirm($doc, $overrides)` materializa
  `Customer→Risk→Poliza→PolicyDocument` en transacción: cliente **vía dedup**
  (`CustomerRepository::findByDni` + `CustomerMergeService::reconcile`, sin tocar `customers`
  directo), Risk por patente, **Poliza find-or-create por `company+numero`** (acumula documentos
  del mismo contrato; tarjeta sin número se adjunta por fallback de patente), marca
  `metadata.origen='ingesta_local'`, estado inferido de fechas, `PolicyDocument source=local_ingesta`
  reusando el PDF en R2; `discard()` descarta sin crear nada. `sugerirContratoAnterior()` infiere
  el vínculo de renovación por patente+compañía (sugerencia que confirma el humano).
- `App\Http\Controllers\IngestaPendientesController` (index agrupa por contrato + preview + sugerencia;
  confirm/discard) + rutas web `ingesta-pendientes.*` + página `PolicyDocuments/PendientesIngesta.vue`
  + NavItem "Ingesta".

**Tests:** `tests/Feature/PolicyDocumentIngestaTest.php` (10) + `tests/Feature/IngestaConfirmacionTest.php` (12).

---

## 7. Preguntas abiertas — RESUELTAS

- **Estado inicial de la `Poliza`**: se **infiere de las fechas** (`vigente` si la vigencia no
  pasó, `vencida` si pasó, `emitida` si no hay fecha) y es **editable por el humano** al
  confirmar. Ver `IngestaConfirmacionService::resolveEstado()`.
- **Persistencia de los Pendientes sin claves**: **tabla de staging propia** `ingested_documents`
  (guarda el PDF en R2 + el contrato crudo en `payload`), no cuelga de `PolicyDocument`. La
  cadena recién se materializa al confirmar.
- **Vínculo de renovación**: se **infiere por patente + compañía** y se **presenta como
  sugerencia** que el humano confirma en Pendientes (modo de arranque conservador). En una fase
  posterior, junto con el modo automático, podrá setearse solo.
- **Endpoint**: bajo `api` con **Sanctum** (`auth:sanctum`), no un canal dedicado.

### Deuda abierta
- **Modo automático**: quitar la confirmación manual cuando el parser demuestre acierto
  consistente (reversible solo manual → automático).
- **Cleanup de R2**: los PDFs de pendientes `descartados` quedan en R2; falta un job de limpieza
  si se decide recogerlos.

---

## 8. Referencias

- Cliente: `ingestor/README.md` (parser v5, estado por compañía, contrato) y
  `ingestor/app/v5/USO.md` (guía operativa: config, flujo, `state.json`, automatización).
  Código del uploader: `ingestor/app/v5/uploader.py`; armado del contrato: `parser.py::_build`.
- Modelo de documentación de póliza implementado: [`03-entrega-documentacion-app.md`](03-entrega-documentacion-app.md).
- Dedup/merge de clientes: [`../v2/12-deduplicacion-merge-clientes.md`](../v2/12-deduplicacion-merge-clientes.md).
- Modelo cliente como fuente de verdad: [`../v2/11-modelo-cliente-consolidacion-datos.md`](../v2/11-modelo-cliente-consolidacion-datos.md).
