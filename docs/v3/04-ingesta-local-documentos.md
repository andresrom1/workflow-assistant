# v3/04 — Ingesta local de documentos de póliza (lado servidor)

> **Estado: v1 IMPLEMENTADO (Fases 1–3, suite verde) — v2 IMPLEMENTADO en rama, en
> evaluación (Fase 4, 2026-07).** El lado cliente (script Python) vive en `ingestor/` (repo
> aparte, sin git — versionado por carpeta: `app/v5/` es la versión v1 del contrato, hoy
> **retirada de uso** pero conservada intacta como rollback del cliente; `app/v6/` es la
> versión vigente, ver §9). Este doc describe el **endpoint y la lógica de alta**, en
> `workflow-assistant`. El **mapa de archivos** de v1 y el estado de sus preguntas abiertas
> están en §6 y §7; el diseño de v2 (contrato, pipeline LLM, costo) está en §9.
>
> Encaja en v3 (post-emisión / mantenimiento de cartera). Es el productor automático que
> alimenta la cola de Pendientes descrita en [`03-entrega-documentacion-app.md`](03-entrega-documentacion-app.md).
>
> **Garantías del cliente v1/v5 (históricas — ver §9 para lo que cambia en v2):**
> - **Solo manda pólizas del corpus.** Si no detecta una aseguradora conocida (por CUIT del
>   emisor o alias), el PDF **no se sube** — facturas, resúmenes, etc. nunca llegan al endpoint.
>   ⚠️ **Esto cambia en v2:** el cliente deja de clasificar: sube todo lo que pasa el filtro de
>   tamaño y el server descarta la basura solo (§9).
> - **Dedup local por hash.** El cliente lleva su propio `state.json` y no reenvía un PDF ya
>   subido. Aun así el server **debe** dedup por `hash_sha256` (el cliente puede reinstalarse o
>   reintentar tras un 5xx) — ver §4. **Sigue vigente en v2, sin cambios.**
> - **Ventana temporal.** Solo procesa PDFs de la carpeta Descargas creados hace ≤ 90 días.
>   **Sigue vigente en v2.**
> - **Reintentos.** Ante error de red/5xx reintenta hasta 3 veces con backoff y, si igual falla,
>   reintenta el archivo en la corrida del día siguiente → **el endpoint tiene que ser idempotente.**
>   **Sigue vigente en v2.**

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

- Cliente v1 (histórico): `ingestor/README.md` (parser v5, estado por compañía, contrato) y
  `ingestor/app/v5/USO.md` (guía operativa: config, flujo, `state.json`, automatización).
  Código del uploader: `ingestor/app/v5/uploader.py`; armado del contrato: `parser.py::_build`.
- Cliente v2 (vigente): `ingestor/app/v6/README.md` — ver §9 de este doc.
- Modelo de documentación de póliza implementado: [`03-entrega-documentacion-app.md`](03-entrega-documentacion-app.md).
- Dedup/merge de clientes: [`../v2/12-deduplicacion-merge-clientes.md`](../v2/12-deduplicacion-merge-clientes.md).
- Modelo cliente como fuente de verdad: [`../v2/11-modelo-cliente-consolidacion-datos.md`](../v2/11-modelo-cliente-consolidacion-datos.md).

---

## 9. v2 (2026-07) — extracción server-side con LLM

> **Motivación (por qué se reemplazó el parser v1/v5).** Medido sobre datos reales de
> producción: de 290 PDFs escaneados en Descargas, el cliente subió 161 (el filtro de
> "detectó CUIT de aseguradora ⇒ es póliza" es falso — el CUIT del emisor aparece también en
> facturas, recibos, cotizaciones, denuncias, manuales comerciales). De los subidos, **145
> quedaron pendientes y solo 6 se confirmaron**: 112/145 sin DNI del tomador (San Cristóbal
> casi nunca lo extraía en la práctica, aunque el README de v5 lo daba por ✅), 46
> totalmente ciegos, Experta con el texto roto ("necesita OCR"). Endosos/cancelaciones
> llegaban clasificados como `poliza` (el parser nunca emitía `endoso`).
>
> **Decisión:** el cliente deja de extraer campos. El servidor clasifica y extrae con un
> LLM del tier barato, texto-first (nunca el PDF como archivo — ver nota de costo abajo),
> validando cada campo determinísticamente ("validar-o-null", la misma filosofía de v1
> corriendo del otro lado). **Validado con un smoke test real** contra el corpus de
> `ingestor/docs/` (15 PDFs, 7 compañías) antes de implementar: extracción igual o mejor que
> el parser v5 en las 7 compañías (estrictamente mejor en 4 — Experta completa desde el
> texto roto, San Cristóbal con DNI, Mercantil con patente en la tarjeta, Triunfo con la
> tarjeta verde bien atribuida), clasificación de basura 3/3, costo medido **$0.0002/doc**
> (98% cache-hit del prompt fijo sobre DeepSeek).

### 9.1 Contrato v2 (reemplaza a v1, §2)

El cliente ya no manda `documento`/`tomador`/`riesgo`/`fechas`/`extraccion` — solo el
archivo y su texto plano:

```json
{
  "schema_version": 2,
  "archivo": { "nombre_original": "Caratula Anual (5).pdf", "hash_sha256": "…",
               "detectado_en": "2026-07-13T08:00:00-03:00" },
  "texto": "SANCOR SEGUROS - Póliza N° 000031184413 - ... (o null si el PDF no tiene texto)"
}
```

`texto`: primeras **10** páginas extraídas con pdfplumber (`ingestor/app/v6/extract_text.py`),
capado a 20000 chars en el cliente y re-capado a `config('ingesta.max_text_chars')` (16000)
en el servidor — el cap server-side le pone techo al costo por documento
independientemente de lo que mande el cliente. `schema_version` distinto de 2 → 422 (v1
retirado, un solo cliente controlado).

> **Por qué 10 páginas y no 3 (corrección 2026-07-13, primera corrida real).** Las
> compañías que empaquetan la póliza completa en un solo PDF (Galicia: 22–29 páginas)
> ponen carátulas administrativas adelante (constancia de recepción, carta de bienvenida)
> y el frente con los datos (tomador/DNI/patente/vigencias) recién arranca en la página
> 4–6. Con el recorte original de 3 páginas el LLM clasificaba bien (la carátula dice
> "Póliza") pero extraía vacío — nunca veía los datos. Verificado con el corpus: mismo
> PDF de Galicia pasó de DNI/patente/vigencias = null a extraerlos todos al subir el cap.
> Costo por documento con el cap nuevo: ~$0.0017 (medido) — sigue despreciable.

### 9.2 Pipeline server-side

```
POST /api/ingesta/documentos (contrato v2)
  ├─ valida envoltorio + hash (igual que v1)
  ├─ dedup por hash (igual que v1)
  ├─ PDF a R2 (igual que v1)
  ├─ crea IngestedDocument status=en_extraccion (payload = {schema_version, archivo, texto})
  └─ dispatch ExtractIngestedDocument (cola `documents`, conexión `database_long`, igual
     carril que ExtractCoverageDocumentText)
        ├─ IngestaExtractorAgent (tier barato) clasifica + extrae
        ├─ valida CADA campo determinísticamente (patente/DNI/fechas/número/compañía) —
        │  el LLM nunca es la última palabra
        ├─ clase del corpus (poliza/certificado/endoso/cupon/tarjeta_circulacion)
        │  → status=pendiente, kind mapeado
        ├─ clase no-corpus (factura/recibo/cotizacion/denuncia_siniestro/
        │  resumen_cuenta/manual_comercial/otro_no_poliza) → status=descartado_auto
        │  (el PDF queda en R2 para auditoría, nada se materializa)
        └─ IngestaDocumentoService::applyExtraction() reescribe `payload` con el shape
           del contrato v1 (documento/tomador/riesgo/fechas) — por eso
           IngestaPendientesController/IngestaConfirmacionService siguen funcionando
           sin cambios
```

Degradación (nunca se pierde nada, el PDF ya está en R2 desde `stage()`):
- Sin texto (`texto=null`, ~3% medido — PDFs escaneados/Experta con texto roto) → no
  llama al LLM, cae directo a `pendiente` con campos null (igual que un doc "ciego" en v1).
- LLM caído / respuesta no-JSON tras agotar reintentos → `failed()` degrada a `pendiente`.

Estados nuevos en `IngestaStatus` (columna `status` sigue siendo `string`, sin migración):
`en_extraccion` (esperando/corriendo el job) y `descartado_auto` (clasificado como
no-póliza). `descartado_auto` **no tiene UI propia** — solo consultable por query directa
(decisión: agregar una pestaña "Descartados" más adelante si se desconfía del
clasificador).

### 9.3 Por qué esto NO repite el costo de `pas-web`

`pas-web` (histórico) mandaba el PDF **entero como archivo** a un modelo frontier
(`gpt-5.2`), lo que factura texto + una imagen renderizada por página + output grande →
$0.04–0.10 por documento, más caro todavía si se cuela un PDF largo. Acá: **texto plano
capado, nunca el PDF como archivo**, modelo del tier barato (`#[UseCheapestModel]`, no
razonador — extraer 12 campos planos no lo necesita; el nombre concreto sale de
`ai.providers.deepseek.models.text.cheapest`), output ~300 tokens. El job loguea tokens in/out por
documento para que el costo sea un dato observado. **Regla dura: nunca mandar el PDF como
archivo/attachment al LLM de ingesta sin re-evaluar costos.**

### 9.4 Fix de agrupación en Pendientes

La clave de agrupación de `IngestaPendientesController::index()` pasó de `num:{numero}` a
`num:{compañía}:{número normalizado a solo dígitos}` — evita que dos compañías con el
mismo número colisionen y absorbe diferencias de **separadores** (puntos/guiones) del
mismo número. **Limitación aceptada:** si el LLM deja un **prefijo de organizador** en el
número (ej. Triunfo: "458 1.912.367" en vez de "1.912.367"), la normalización a
solo-dígitos no lo distingue de un dígito real y el contrato puede partirse en 2 grupos —
el prompt le pide al LLM devolver el número limpio, pero si no lo hace, el humano confirma
cada documento por separado y `resolvePoliza()` los une por `company+numero` o por patente
al confirmar. No se sobre-ingenierizó esto.

### 9.5 Cliente v2 (`ingestor/app/v6/`)

`app/v5/` queda **intacta en disco** como referencia/rollback del cliente, pero **el
rollback no es solo-cliente**: el servidor v2 rechaza `schema_version` distinto de 2 (422),
así que volver a correr v5 requiere revertir también el servidor a antes de esta fase (el
commit de `feature/ingesta-v2-extraccion-llm` que cambia el `schema_version` esperado). No
hay convivencia v1/v2 en el servidor — es un corte, no un flag. `app/v6/` reemplaza
`parser.py` (7 extractores regex por compañía) por `extract_text.py` (~25 líneas,
pdfplumber). Único filtro que queda del lado cliente: **tamaño** (`rules.max_size_mb`,
default 15 MB — los PDFs de póliza reales están todos bajo 2 MB). Detalle completo en
`ingestor/app/v6/README.md`.

**Estado de evaluación (2026-07):** `config.yaml` de v6 apunta a un **túnel ngrok** hacia
el server local (decisión del usuario: evaluar antes de pasar a producción). Pendiente:
correr v6 contra el corpus real vía ngrok, verificar la cola de Pendientes, y recién
después re-apuntar a `https://mangobroker.com.ar/api/ingesta/documentos` y mergear la rama
`feature/ingesta-v2-extraccion-llm` a `main`.

### 9.6 Backlog de los 145 pendientes v1

No se migran ni se re-procesan automáticamente: esas filas no tienen `payload.texto` (el
server no re-extrae de PDF, solo del texto que ya viene en el payload) y el dedup por hash
haría que un reenvío del mismo PDF devuelva 200 duplicado sin volver a pasar por el job.
Se triage-an a mano desde la UI existente (confirmar o descartar por contrato); los que
sigan en Descargas dentro de la ventana de 90 días re-entrarán por v6 con extracción
nueva **solo si no fueron subidos ya** (hash distinto — no es el caso de estos 145).

### 9.7 Archivos clave (v2)

- `app/AI/Agents/IngestaExtractorAgent.php` — el agente + prompt (clasificación + extracción).
- `app/Jobs/ExtractIngestedDocument.php` — orquesta la extracción + validadores determinísticos.
- `config/ingesta.php` — modelo, cap de texto, CUITs de aseguradoras, alias de compañía.
- `app/Enums/IngestaStatus.php` — estados `en_extraccion`/`descartado_auto` nuevos.
- `app/Services/IngestaDocumentoService.php` — `stage()` v2 + `applyExtraction()`.
- `app/Http/Controllers/PolicyDocumentIngestaController.php` — contrato v2.
- `app/Http/Controllers/IngestaPendientesController.php` — fix de agrupación (§9.4).
- `app/Console/Commands/ReextraerIngesta.php` — ops: re-despachar un doc puntual o los
  colgados en `en_extraccion` (`--stuck`).
- Tests: `tests/Feature/PolicyDocumentIngestaTest.php` (contrato v2),
  `tests/Feature/ExtractIngestedDocumentTest.php` (job + validadores + E2E con fixtures
  reales del smoke test), `tests/Feature/IngestaPendientesGroupingTest.php` (agrupación).
- Cliente: `ingestor/app/v6/`.
