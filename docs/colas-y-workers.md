# Colas y workers — workflow-assistant

> Cómo se reparte el trabajo asíncrono: **conexiones**, **colas**, **workers**, los invariantes que
> mantienen todo correcto, y por qué la topología es la que es.
>
> **Este documento explica.** Las reglas que hay que respetar *al editar* están en
> [`../CLAUDE.md`](../CLAUDE.md) § *Colas y workers*, y las verifica
> `tests/Feature/Queue/WorkerConfigTest.php`. La historia de cada cambio vive en la bitácora de
> [`../ROADMAP.md`](../ROADMAP.md).
>
> Reemplaza a [`v2/09-queues-architecture.md`](v2/09-queues-architecture.md), que quedó como registro
> histórico del diseño anterior (un worker por cola).

Driver: `database` — la tabla `jobs` en PostgreSQL. No hay broker, ni Redis, ni servidor de colas.

---

## 1. Modelo mental — tres conceptos que no son lo mismo

| Concepto | Dónde se define | Qué controla |
|---|---|---|
| **Conexión** | `config/queue.php` → `connections.*` | El backend (driver, tabla) y el **`retry_after`**. |
| **Cola** (`queue`) | `$this->onQueue('x')` en el constructor del job | La etiqueta por la que un worker filtra qué saca. |
| **Worker** | un `[program:...]` en `.docker/start.sh` | Un proceso que bindea **una** conexión y escucha **una o más** colas. |

**La cola es una fila en una tabla.** Cuando el código quiere hacer algo que tarda, en vez de hacerlo
inserta una fila en `jobs` con el nombre de la clase, sus argumentos y una etiqueta en la columna
`queue`. El worker es un proceso PHP en bucle: `SELECT ... FOR UPDATE SKIP LOCKED` filtrando por esa
etiqueta, ejecuta, borra la fila; si no hay nada, duerme y vuelve.

**Por qué existe todo esto:** el webhook de WhatsApp tiene que devolverle `200` a Meta en pocos
segundos o Meta reintenta y duplica el mensaje. Un turno del LLM tarda 4-95 s y una cotización contra
Visred hasta 174 s. Nada de eso entra en un request.

**Clave del driver `database`:** las cuatro conexiones escriben en la **misma tabla `jobs` de la misma
base**. No aíslan nada — lo único que las distingue es el `retry_after`. Para *sacar* un job lo que
importa es la columna `queue`, **no** la conexión con la que se insertó.

---

## 2. Los dos invariantes

### 2.1 `retry_after` de la conexión del worker > el `$timeout` más largo que puede correr

- **`retry_after`** (conexión): cuánto espera el sistema antes de dar por muerto un job reservado y
  volver a ofrecerlo.
- **`$timeout`** (job): cuánto lo deja correr antes de matarlo por colgado.

Si `retry_after` ≤ `timeout`, un job que **todavía está corriendo legítimamente** es dado por muerto y
entregado a otro worker → **doble ejecución**. Para un job de LLM eso es doble llamada al modelo (doble
costo) y doble respuesta al cliente.

**Y `retry_after` no viaja con el job:** lo aplica la conexión del worker que hace el `pop`, no la del
código que despachó. Por eso **cada worker recibe su conexión como primer argumento**:

```sh
# ✅
php artisan queue:work database_ai --queue=whatsapp-ai --timeout=180
# ❌ cae a queue.default y usa el retry_after de `database`
php artisan queue:work --queue=whatsapp-ai --timeout=180
```

> **Este invariante se rompió dos veces, y la segunda estuvo tres meses sin que nadie lo notara.**
>
> **2026-06-05 (ROADMAP H1):** el worker de IA corría `queue:listen --queue=whatsapp-ai,...` sin
> argumento de conexión, así que usaba `database` (`retry_after=90`) y anulaba `database_ai`
> (`retry_after=200`), que existe justo para esto. Todo job de IA de 90-180 s era reclamado mientras
> corría.
>
> **2026-08-22:** el mismo bug, en los seis workers a la vez. El fix del 06-05 se había aplicado a
> `run.md` (dev) pero `.docker/start.sh` (prod) nunca recibió el argumento de conexión: los cuatro
> `retry_after` finos de `config/queue.php` estaban escritos y **no los aplicaba nadie**. No mordía
> sólo porque había exactamente un worker por cola y nadie más podía re-reservar — pero era lo que
> bloqueaba subir `numprocs`.
>
> `WithoutOverlapping` **no** protege contra esto: serializa el despacho de jobs distintos, no el
> reclaim del driver sobre un job ya reservado.

### 2.2 La política del job vive en el job

Todo job declara `public int $timeout`, `public int $tries` y su cola con `$this->onQueue(...)` en el
constructor. El `--timeout`/`--tries` del worker es sólo un **techo de seguridad**: Laravel prioriza el
del job (`Worker::timeoutForJob()`, aplicado con `pcntl_alarm` por job).

Heredar del worker "funciona" mientras cada cola tenga proceso propio y **se rompe en cuanto se
comparte**, porque un proceso tiene un solo `--timeout`. Antes del 2026-08-22, `EmitirPoliza` corría
con los 60 s del worker `default` —un valor elegido para otra cola— y `SendPolicyDocumentsToClient`,
que descarga PDFs y los sube a Meta, con los 30 s pensados para un POST de texto.

**Corolario:** del lado del despacho **no se usa `onConnection()`**. Da una falsa sensación de
aislamiento (§1) y es de donde salió el bug de 2.1.

---

## 3. Topología — tres procesos residentes, no uno por cola

> **Agregar una cola es gratis; agregar un worker cuesta ~50 MB para siempre.**

El nombre de la cola es una etiqueta de texto. El worker es un proceso PHP con el framework y el AI SDK
cargados de forma permanente. Hasta el 2026-08-22 había **seis workers residentes** —uno por cola,
agregados de a uno con cada feature— para un flujo conversacional de bajo volumen.

| Worker | Colas (prioridad) | Conexión (`retry_after`) | `--timeout` | Qué corre |
|---|---|---|---|---|
| `worker-ai` | `whatsapp-ai` | `database_ai` (200) | 180 | El turno del LLM, 4-95 s. **Aislado**: es el hot path y el cuello de botella. |
| `worker-realtime` | `default`, `whatsapp-outbound`, `media` | `database` (200) | 60 | Ingesta + acuse azul, envíos a Meta, transcripción de notas de voz. |
| `worker-quotes` | `quotes` | `database_quotes` (420) | 360 | `ResolveQuote`. **Aislado**: retiene el proceso 30-360 s. |
| *(sin residente)* | `background`, `documents` | `database_long` (360) | 300 | Emisión, facturación, extracción de PDF, análisis semántico, limpieza. |

Más el `scheduler` (`schedule:work`). **Cuatro procesos PHP residentes en total.**

`--max-time` va escalonado (3600 / 3900 / 4200) a propósito: con el mismo valor los tres arrancan
juntos y reinician juntos, con un pico de arranque en frío sincronizado cada hora.

### 3.1 Qué se puede fusionar y qué no

El criterio **no es la frecuencia, es a quién bloquea**. Un job largo bloquea a los cortos que
comparten proceso.

- **`whatsapp-ai` va solo.** Un turno retiene el proceso hasta 95 s (medido: `CheckoutAgent` 69 s
  encadenado a `QuoteAgent` 14,5 s). Compartirlo haría esperar todo lo demás.
- **`quotes` va sola.** El polling contra Visred llegó a 174 s medidos.
- **`default` + `whatsapp-outbound` + `media` se fusionan.** Todo ≤60 s, todo de cara al cliente. El
  más largo es `ProcessMediaAttachment` (STT de una nota de voz, techo 60 s): el peor caso es que un
  audio patológico demore un minuto un envío saliente.

### 3.2 La cola `background` no tiene worker residente

La levanta el scheduler cada minuto (`routes/console.php`):

```php
Schedule::command('queue:work database_long --queue=background,documents,semantic-analysis --stop-when-empty --max-time=55 --tries=3 --timeout=300')
    ->everyMinute()->runInBackground()->withoutOverlapping(10);
```

`--stop-when-empty` hace que el proceso **termine** cuando la cola se vacía. En un minuto sin trabajo
—el 99,9 % de los minutos— arranca, hace un poll, ve la tabla vacía y muere: **cero RAM residente**.
`--max-time=55` corta **entre** jobs, nunca mata uno en curso, así que una extracción de PDF de 300 s
termina igual. Latencia peor caso: 60 s desde el dispatch.

**Cuánto cuesta arrancar:** 0,15 s por invocación, medido dentro de la imagen. Son ~2.880 arranques
por día (el `schedule:run` del scheduler más este) ≈ **7 min de CPU al día**. Ese número depende de
`opcache.file_cache` — ver §7.

**Para volver a un residente:** agregar un `[program:worker-background]` con `--sleep=20` en
`.docker/start.sh` y borrar la entrada del scheduler. Cuesta ~50 MB.

`documents` y `semantic-analysis` siguen en la lista sólo para drenar lo que haya quedado en vuelo con
los nombres viejos; los jobs ya se despachan a `background`.

---

## 4. Conexiones (`config/queue.php`)

| Conexión | `retry_after` | Cola default | Job más largo que atiende | Margen |
|---|---|---|---|---|
| `database` | **200** | `default` | `ProcessWhatsAppMessage` / `ProcessMediaAttachment` (60) | 3,3× |
| `database_ai` | **200** | `whatsapp-ai` | `ProcessConversationInbox` (180) | 1,1× |
| `database_quotes` | **420** | `quotes` | `ResolveQuote` (360) | 1,2× |
| `database_long` | **360** | `documents` | `ExtractCoverageDocumentText` (300) | 1,2× |

La conexión `database_media` **se eliminó** el 2026-08-22 (se plegó en `database` al fusionar el
worker). Las cuatro comparten la tabla `jobs`; el `retry_after` es lo único que cambia.

---

## 5. Inventario de jobs por cola

Este mapa lo verifica el test — si alguien mueve un job de cola, falla ahí.

| Cola | Jobs | Carácter |
|---|---|---|
| **`whatsapp-ai`** | `ProcessConversationInbox` (180 s) · `NotifyClientQuoteReady` (180 s) · `NotifyClientCheckoutCompleted` (180 s) | El turno conversacional. |
| **`default`** | `ProcessWhatsAppMessage` (60 s) · `UpdateMessageStatus` (30 s) · `HandleUserIdUpdate` (30 s) · `AnalyzeConversationHealthJob` (30 s) | Ingesta y acuse. **Camino caliente.** |
| **`whatsapp-outbound`** | `SendWhatsAppMessage` (30 s) · `SendWhatsAppTemplate` (30 s) · `NotifyClientQuoteFailed` (30 s) · `NotifyClientEmissionFailed` (30 s) | Envíos a la Cloud API. |
| **`media`** | `ProcessMediaAttachment` (60 s) | Descarga + STT de notas de voz. |
| **`quotes`** | `ResolveQuote` (360 s) | Cotización contra Visred. |
| **`background`** | `EmitirPoliza` (120 s) · `CapturePendingPolicyDocuments` (60 s) · `SendPolicyDocumentsToClient` (120 s) · `EmitInvoice` (60 s) · `CloseInvoiceBatch` (60 s) · `PublishDocumentAvailable` (30 s) · `DeleteOrphanPhoto` (30 s) · `ExtractCoverageDocumentText` (300 s) · `ExtractIngestedDocument` (120 s) · `AnalyzeConversationSemanticsJob` (120 s) | Lento y sin nadie esperando. |

**Qué va en `background` y qué no.** No es "lo poco frecuente", es **lo que no bloquea a nadie**.
`default` es el camino caliente —`ProcessWhatsAppMessage` manda el acuse de lectura y el
"escribiendo…"— y un job lento ahí adelante deja al cliente mirando un bot colgado. **Ese bug
existió:** `EmitirPoliza` (hasta 2 min contra Visred) vivía en `default`.

> **`Bus::chain()`:** la cadena fija el `chainQueue` con el que se despacha cada eslabón siguiente, así
> que **la cadena tiene que declarar su cola** aunque cada job ya la declare en su constructor. Ver
> `InvoiceBatchController::despacharCadena()`.

---

## 6. Pipeline de inbox WhatsApp — tres etapas

```
Webhook → ProcessWhatsAppMessage          (default → worker-realtime)
              ↓ persiste el mensaje con processed_at=null
              ↓ manda el acuse de lectura + "escribiendo…"
              ↓ dispatch con delay = whatsapp.inbox_quiet_seconds (3 s)
          ProcessConversationInbox        (whatsapp-ai → worker-ai)
              ↓ ¿el cliente sigue escribiendo? → release() y salir
              ↓ marca processed_at ANTES de llamar al AI
              ↓ agrupa los pendientes → una sola llamada
              ↓ ¿llegaron mensajes mientras generaba? → descartar y rehacer
          SendWhatsAppMessage             (whatsapp-outbound → worker-realtime)
```

**Por qué tres etapas:** ingesta rápida sin IA → agrupación de la seguidilla del cliente en una sola
respuesta coherente → envío con retry independiente del AI (si Meta falla, no se re-procesa el LLM).

**El acuse va en la etapa 1, no en la 2.** `whatsapp-ai` es un solo worker; mientras corre un turno
largo, el siguiente ni arranca. Con el acuse sólo en la etapa 2, los mensajes quedaban en gris todo
ese rato y el cliente escribía "¿estás bloqueado?" (ROADMAP 2026-08-11). La etapa 1 vive en
`worker-realtime`, que no espera a nadie — **ésa es la razón por la que `default` no puede tener jobs
lentos**.

**Serialización por conversación:** `ProcessConversationInbox` y `NotifyClientQuoteReady` comparten el
lock `WithoutOverlapping("inbox:{conversationId}")`. El presupuesto de espera (`tries` × `releaseAfter`)
tiene que superar el máximo que el otro job puede retener el lock — el detalle en `CLAUDE.md`.

**Idempotencia:** `processed_at` se setea **antes** de llamar al AI; si el job reintenta encuentra el
inbox vacío y sale limpio. A nivel webhook, además, se deduplica por `wamid` con TTL 24 h.

Si el mensaje trae audio, `ProcessWhatsAppMessage` despacha primero `ProcessMediaAttachment` (cola
`media`), que tras transcribir re-despacha `ProcessConversationInbox`.

**La cotización corre en paralelo, fuera del turno:** `ResolveQuote` se despacha al identificar el
vehículo, no al elegir cobertura, así que los 30-174 s de las compañías transcurren mientras el agente
indaga. El job **no** lleva el lock `inbox:{id}` — es el punto.

---

## 7. El costo, medido

Medido en `mango-prod` (GCP `t2a-standard-2`, ARM) el 2026-08-22, antes y después de la consolidación.
**En reposo.**

| | 6 workers | 3 workers + background bajo demanda |
|---|---|---|
| Procesos PHP residentes | 7 | **4** |
| RSS por queue worker | 47-48 MB | 51-52 MB |
| Contenedor `app` | 299,1 MB | **190,6 MB** (−36 %) |
| Host (`free -m`) | 1.104 MB | **920 MB** (−17 %) |
| CPU del contenedor en reposo | — | 0,10 % |

Dos cosas que salieron de medir y no de estimar:

- **Un worker pesa ~50 MB, no ~90.** El ahorro por worker eliminado es la mitad de lo que parecía.
- **`opcache.enable_cli = 1` sin `file_cache` es peor que no tener OPcache en CLI.** La memoria
  compartida de OPcache en CLI es **por proceso**: nace y muere con cada `php artisan`, así que cada
  invocación paga compilar *y* guardar en una SHM que después tira. Medido (arranque de
  `artisan inspire`, 5 corridas): **0,33-0,38 s** con `enable_cli` solo · **0,18-0,19 s** con OPcache
  CLI apagado · **0,14-0,15 s** con `file_cache`. Importa por los ~2.880 arranques diarios de §3.2.
  Ver `docker/prod/php.ini`.

---

## 8. Observabilidad de conversación (Tier 1 / Tier 2)

Ambos corren **después** de que el agente respondió, hacen `array_merge` sobre `conversations.flags`
(conviven sin pisarse) y su único consumidor es el **panel admin**. **No** afectan el chat en vivo: el
orquestador no lee estas banderas.

| | Tier 1 — `AnalyzeConversationHealthJob` | Tier 2 — `AnalyzeConversationSemanticsJob` |
|---|---|---|
| Costo | Heurísticas PHP, **sin IA** | Llamada a LLM (`#[UseSmartestModel]`, el caro) |
| Cola | `default` | `background` |
| Disparo | Siempre, tras cada respuesta | Gated por `AI_SEMANTIC_ANALYSIS_ENABLED` + cada N turnos |
| Banderas | `loops` (hash de outbound), `stuck` (≥5 turns), `tool_errors`, `abandoned` (24 h), `long` | `user_frustrated`, `agent_confused`, `semantic_loop`, `context_loss`, `hallucination`, `incorrect_answer` |
| Estado | ✅ corre | ⏸️ **apagado por flag**, pero la cola ya tiene lector |

Tier 2 estuvo hasta el 2026-08-22 en una cola sin worker. Era un defecto **latente** —con el flag en
`false` nadie despachaba nada, así que no acumuló jobs— pero el día que se encendiera, el botón del
admin habría contestado *"encolado, refrescá en unos segundos"* y no habría corrido nunca, sin error
visible en ningún lado. **Se resolvió mudando la cola, sin tocar el feature ni encender el flag.**

Antes de encenderlo sigue abierta la decisión de diseño: si sólo alimenta el panel (QA offline) o si se
rediseña para impactar el chat en vivo.

---

## 9. Qué verifica el test

`tests/Feature/Queue/WorkerConfigTest.php` lee `.docker/start.sh` y `routes/console.php` y comprueba
cinco cosas:

1. El **mapa completo** de jobs por cola (§5). Si alguien mueve un job, se entera acá.
2. Todo `ShouldQueue` declara `$timeout`, `$tries` y cola explícita (§2.2).
3. **Toda cola nombrada por un job tiene un worker que la lee.** Una cola sin lector acumula filas en
   silencio, sin error en ningún lado.
4. `retry_after` de la conexión de cada worker > el `$timeout` más largo que puede correr (§2.1).
5. El `--timeout` declarado del worker no queda por debajo de un job que atiende — el job gana igual,
   pero el techo declarado no debe mentir.

Es la única de las fuentes de verdad de este documento que **no se puede ignorar**.

---

## 10. Deuda cerrada

| Deuda | Cerrada en |
|---|---|
| Cola `semantic-analysis` sin consumidor | 2026-08-22 — mudada a `background`. |
| Prod sin gestor de procesos (H5): `run.md` era un cheatsheet de dev con `queue:listen` a mano | Supervisor en `.docker/start.sh`, con `queue:work` y `--max-time`. |
| `retry_after` sin efecto por falta del argumento de conexión | 2026-08-22 — ver §2.1. |
| Un worker residente por cola | 2026-08-22 — ver §3. |

---

## 11. Levantarlo en local

En dev va `queue:listen` y no `queue:work` a propósito: rebootea el framework por job, así que recoge
cambios de código sin reiniciar el proceso.

**Un solo proceso, cubre todas las colas** — lo cómodo para el día a día:

```sh
php artisan queue:listen database_quotes --queue=whatsapp-ai,default,whatsapp-outbound,media,quotes,background,documents --tries=3 --timeout=360
```

Es lo que corre `composer run dev`. **La conexión no es decorativa:** `database_quotes` tiene
`retry_after = 420`, lo único por encima del job más largo (`ResolveQuote`, 360 s). Con `database` o
`database_ai` (200) se viola el invariante de §2.1 y una cotización lenta se ejecuta dos veces.

La contra de un solo proceso: una cotización de 174 s bloquea todo lo demás. En dev suele ser
aceptable —y a veces revelador—, pero si molesta, la otra opción es **espejar producción** con cuatro
terminales:

```sh
php artisan queue:listen database_ai     --queue=whatsapp-ai                     --tries=3 --timeout=180
php artisan queue:listen database        --queue=default,whatsapp-outbound,media --tries=3 --timeout=60
php artisan queue:listen database_quotes --queue=quotes                          --tries=2 --timeout=360
php artisan queue:listen database_long   --queue=background,documents            --tries=3 --timeout=300
```

> **Trampa conocida:** hasta el 2026-08-22 el script `dev` de `composer.json` no incluía `quotes`,
> `background` ni `documents`. En local no se resolvía ninguna cotización, no se emitía ninguna
> póliza y no se extraía ningún PDF — sin ningún error, porque los jobs se encolaban y nadie los
> sacaba. Si agregás una cola, **acordate de agregarla también acá**: el `WorkerConfigTest` lee
> `.docker/start.sh` y `routes/console.php`, pero **no** lee `composer.json`.

---

## Referencias

- [`../CLAUDE.md`](../CLAUDE.md) § *Colas y workers* — las reglas al editar.
- [`../ROADMAP.md`](../ROADMAP.md) — bitácoras 2026-06-05, 2026-08-10, 2026-08-11 y 2026-08-22.
- [`v2/09-queues-architecture.md`](v2/09-queues-architecture.md) — el diseño anterior (un worker por cola), como registro histórico.
- [`despliegue.md`](despliegue.md) · [`migracion-tier.md`](migracion-tier.md) — dónde corre todo esto y a dónde va.
