# Arquitectura de Queues — workflow-assistant (v2)

> Cómo se reparte el trabajo asíncrono: **conexiones**, **colas**, **workers** y el invariante
> `retry_after > timeout` que mantiene todo correcto. Driver: `database` (tabla `jobs` en PostgreSQL).
> El estado de ejecución y la deuda viven en [`../../ROADMAP.md`](../../ROADMAP.md).

---

## Modelo mental — tres conceptos que no son lo mismo

| Concepto | Dónde se define | Qué controla |
|---|---|---|
| **Conexión** | `config/queue.php` → `connections.*` | El backend (driver, tabla) y el `retry_after`. |
| **Cola** (`queue`) | `->onQueue('x')` o la `queue` default de la conexión | La etiqueta por la que un worker filtra qué jobs saca. |
| **Worker** | `queue:work`/`queue:listen` (en `run.md`) | Un proceso que bindea **una** conexión y escucha **una o más** colas. |

**Clave del driver `database`:** todas las conexiones (`database`, `database_ai`, `database_media`,
`database_long`) escriben en la **misma tabla `jobs`**. Lo único que las distingue es el `retry_after`
y la cola por defecto. Para *sacar* un job lo que importa es la columna `queue`, **no** la conexión con
la que se insertó. Por eso un worker bindea una conexión concreta: el `retry_after` que se aplica al
reclamar un job es **el de la conexión con la que el worker hace `pop`**, no el de la conexión con la que
se despachó.

### El invariante de oro: `retry_after` > `--timeout`

- **`retry_after`** (conexión): cuánto espera el sistema antes de asumir que un job reservado murió y volverlo a poner disponible.
- **`--timeout`** (worker): cuánto deja correr el worker a un job antes de matarlo por colgado.

Si `retry_after` **≤** `--timeout`, un job que todavía corre legítimamente es dado por muerto y ofrecido a
otro worker → **doble ejecución**. Para jobs de LLM esto significa doble llamada al modelo (doble costo) y
doble respuesta al cliente. Cada conexión de este proyecto existe para respetar este invariante con un
`--timeout` distinto por tipo de carga.

> **Incidente que originó este diseño (2026-06-05, ROADMAP H1):** el worker de IA corría
> `queue:listen --queue=whatsapp-ai,... --timeout=180` **sin argumento de conexión** → usaba la default
> `database` (`retry_after=90`), anulando la conexión `database_ai` (`retry_after=200`) que existe justo
> para esto. Todo job de IA que tardaba 90–180s era reclamado mientras corría. El fix fue apuntar el
> worker a `database_ai`. `WithoutOverlapping` **no** protege contra esto: serializa el despacho de jobs
> distintos, no el reclaim del driver sobre un mismo job ya reservado.

---

## Conexiones (`config/queue.php`)

| Conexión | `retry_after` | Cola default | Worker `--timeout` | Para qué |
|---|---|---|---|---|
| `database` | **90** (`DB_QUEUE_RETRY_AFTER`) | `default` | 60 | Jobs livianos y rápidos (outbound WhatsApp, status, emisión, cleanup, Tier 1). |
| `database_ai` | **200** | `whatsapp-ai` | 180 | Jobs de LLM (orquestador, notify quote). 200 > 180. |
| `database_media` | **150** | `media` | 120 | Descarga de media + STT. 150 > 120. |
| `database_long` | **360** | `documents` | 300 | Extracción de PDF + chunking/embeddings RAG. 360 > 300. |

Las cuatro comparten tabla `jobs`; el `retry_after` es lo que cambia. Cada una se consume con un worker
propio (ver topología abajo) porque **un worker no puede bindear dos conexiones a la vez**.

---

## Inventario de jobs

| Job | Conexión | Cola | Worker | Disparado por |
|---|---|---|---|---|
| `ProcessWhatsAppMessage` | `database` | `default` | liviano | Webhook inbound (`WhatsAppWebhookController`). |
| `ProcessConversationInbox` | `database_ai` | `whatsapp-ai` | IA | `ProcessWhatsAppMessage` (delay 2s) / `ProcessMediaAttachment`. |
| `NotifyClientQuoteReady` | `database_ai` | `whatsapp-ai` | IA | `ApiQuoteResolution` cuando la cotización resuelve. |
| `SendWhatsAppMessage` | `database` | `whatsapp-outbound` | liviano | `ProcessConversationInbox` / `NotifyClientQuoteReady`. |
| `ProcessMediaAttachment` | `database_media` | `media` | media | `ProcessWhatsAppMessage` si el mensaje trae audio/imagen. |
| `ExtractCoverageDocumentText` | `database_long` | `documents` | documentos | Admin sube/actualiza un manual en `/coverage-documents`. |
| `AnalyzeConversationHealthJob` | `database` | `default` | liviano | `ProcessConversationInbox` tras cada respuesta (Tier 1, sin IA). |
| `AnalyzeConversationSemanticsJob` | `database` | `semantic-analysis` | **⚠️ ninguno** | `ProcessConversationInbox` / panel admin (Tier 2, con IA). Ver deuda. |
| `UpdateMessageStatus` | `database` | `default` | liviano | Webhook de estados de entrega (`sent/delivered/read`). |
| `HandleUserIdUpdate` | `database` | `default` | liviano | Webhook `user_id_update` (transición de identidad WhatsApp). |
| `EmitirPoliza` | `database` | `default` | liviano | `CheckoutController` al confirmar el checkout. |
| `DeleteOrphanPhoto` | `database` | `default` | liviano | Checkout (reemplazo de foto) / `CleanupTempPhotos`. |

---

## Topología de workers (`run.md`)

Un proceso por conexión. En **dev** se usa `queue:listen` (rebootea el framework por job, recoge cambios
de código sin reiniciar). En **prod** debe ser `queue:work` bajo supervisor (ver deuda H5).

```bash
# IA pesada (LLM). retry_after=200 > timeout=180.
php artisan queue:listen database_ai --queue=whatsapp-ai --tries=3 --timeout=180

# Liviano: respuestas WhatsApp + jobs varios. retry_after=90.
php artisan queue:listen --queue=whatsapp-outbound,default --tries=3 --timeout=60

# Media (descarga + STT). retry_after=150 > timeout=120.
php artisan queue:listen database_media --tries=3 --timeout=120

# Documentos (extracción PDF + RAG). retry_after=360 > timeout=300. Ocioso casi siempre.
php artisan queue:listen database_long --tries=2 --timeout=300
```

> **Importante:** son procesos separados porque cada worker bindea una sola conexión. `whatsapp-ai`
> necesita `database_ai`, mientras `whatsapp-outbound`/`default` viven en `database`. No se pueden
> fusionar en un solo `queue:listen` sin volver a romper el invariante del Hallazgo 1.

> Nota: la cola `semantic-analysis` **no** está en ningún worker (intencional — el feature está apagado).

---

## Pipeline de inbox WhatsApp (3 etapas)

El flujo principal encadena tres jobs en tres colas distintas para separar responsabilidades:

```
Webhook → ProcessWhatsAppMessage          (database / default)
              ↓ persiste mensaje, processed_at=null
              ↓ dispatch con delay 2s (debounce)
          ProcessConversationInbox        (database_ai / whatsapp-ai)
              ↓ agrupa pendientes → 1 sola llamada al AI
              ↓ marca processed_at ANTES de llamar al AI
              ↓ InsuranceOrchestrator::handle()
          SendWhatsAppMessage             (database / whatsapp-outbound)
              ↓ WhatsAppOutboundService::sendMessage()
```

**Por qué tres etapas:** ingesta rápida sin IA → agrupación de mensajes rápidos del usuario en una sola
respuesta coherente → envío con retry independiente del AI (si Meta API falla, no se re-procesa el LLM).

**Serialización por conversación:** `ProcessConversationInbox` y `NotifyClientQuoteReady` comparten el
lock `WithoutOverlapping("inbox:{conversationId}")` (con `releaseAfter`/`expireAfter`), para que una
notificación de cotización no se entrevere con el procesamiento del inbox de la misma conversación.

**Idempotencia:** `processed_at` se setea **antes** de llamar al AI. Si el job reintenta, encuentra el
inbox vacío y sale limpio — evita doble llamada al LLM con los mismos mensajes. (A nivel webhook, además,
se deduplica por `wamid` con TTL 24h.)

Si el mensaje trae audio/imagen, `ProcessWhatsAppMessage` despacha primero `ProcessMediaAttachment`
(cola `media`), que tras transcribir re-despacha `ProcessConversationInbox`.

---

## Jobs de observabilidad de conversación (Tier 1 / Tier 2)

Ambos corren **después** de que el agente respondió, hacen `array_merge` sobre `conversations.flags`
(conviven sin pisarse) y su único consumidor es el **panel admin**. **No** afectan el chat en vivo: el
orquestador no lee estas banderas.

| | Tier 1 — `AnalyzeConversationHealthJob` | Tier 2 — `AnalyzeConversationSemanticsJob` |
|---|---|---|
| Costo | Heurísticas PHP, **sin IA** | Llamada a LLM (tier smart, `#[UseSmartestModel]`) |
| Disparo | Siempre, tras cada respuesta | Gated por flag + cada N turnos |
| Banderas | `loops` (hash de outbound), `stuck` (≥5 turns), `tool_errors`, `abandoned` (24h), `long` (≥20 msgs) | `user_frustrated`, `agent_confused`, `semantic_loop`, `context_loss`, `hallucination`, `incorrect_answer` |
| Estado | ✅ corre (cola `default`, consumida) | ⏸️ apagado, cola sin worker (ver deuda) |

---

## Deuda abierta

| Deuda | Estado |
|---|---|
| **Cola `semantic-analysis` sin consumidor** (Tier 2). Hoy apagada (`AI_SEMANTIC_ANALYSIS_ENABLED=false`), no acumula jobs. | ⏸️ latente. **⚠️ Antes de encenderla**, revisar arquitectura: (1) cablear la cola a un worker `database_ai` —sin esto los jobs caen al vacío—, (2) decidir si solo alimenta el panel (QA offline) o si se rediseña para impactar el chat en vivo (orquestador reacciona a `semantic_loop`/`context_loss`). |
| **Prod sin gestor de procesos** (H5). `run.md` es cheatsheet de dev con `queue:listen` a mano. | ⬜ En prod: `queue:work` (no `listen`) con `--max-time`/`--max-jobs`, bajo supervisor (supervisord/systemd) que reinicie procesos caídos. Modo de falla actual: un worker muere y su cola se frena en silencio. Cuatro conexiones a supervisar. |

Detalle e historia de cada ítem en [`../../ROADMAP.md`](../../ROADMAP.md) (deuda + bitácora 2026-06-05).
