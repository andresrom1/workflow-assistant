# Auditoría de seguridad — workflow-assistant

**Fecha:** 2026-07-04
**Alcance:** proyecto completo (`workflow-assistant/`), con foco en superficie de ataque HTTP (rutas web/api/mobile), autenticación/autorización, exposición de datos y el diff sin commitear de la rama `main`.
**Metodología:** revisión de rutas y su middleware, controladores expuestos, canales de broadcasting, configuración de red y trazado de flujo de datos desde entradas no confiables hasta sinks sensibles.

> **El diff sin commitear de la rama (import de reporte de cartera + ingesta) fue revisado aparte y no introduce vulnerabilidades explotables.** Los hallazgos de abajo son de la superficie preexistente del proyecto. Se documentan igual porque el pedido fue auditar el proyecto y dejar registradas las acciones correctivas.

---

## Resumen de hallazgos

| # | Severidad | Categoría | Ubicación |
|---|-----------|-----------|-----------|
| 1 | **CRÍTICA** | Falta de autenticación / destrucción de datos | `routes/api.php:60-76` (`/api/dev/*`) |
| 2 | **ALTA** | IDOR + falta de auth / exposición de PII | `routes/api.php:44` (`quotes/{quote}/raw`) |
| 3 | **ALTA** | Falta de auth + mass assignment | `routes/api.php:27` (`v1/quotes`) |
| 4 | **ALTA** | Falta de auth / exposición de PII | `routes/api.php:36-42` (`web-chat/v1/tools/*`) |
| 5 | **MEDIA** | Autorización rota en canal de broadcast | `routes/channels.php:9-11` |
| 6 | **MEDIA** | Confianza total en proxies (spoof de IP/Host) | `bootstrap/app.php:36` |
| 7 | **BAJA** | Endpoint de debug que refleja el request | `routes/api.php:16-25` (`tools/test`) |

---

## Vuln 1 — CRÍTICA: rutas `/api/dev/*` sin autenticación

**Ubicación:** `routes/api.php:60-76` → `app/Http/Controllers/TestingController.php`

**Descripción:** El grupo `Route::prefix('dev')` no tiene ningún middleware (`auth`, `admin`, ni gate por entorno). Cualquiera con acceso de red al backend puede invocar:

- `POST /api/dev/clean-database` → `TestingController::cleanDatabase()` hace `truncate()` de **todas** las tablas de la base (`TestingController.php:92-112`). **Borrado total de datos sin autenticación.**
- `GET /api/dev/run-tests` → ejecuta `Artisan::call('test', ...)` con `--filter`/`--testsuite` desde el request y devuelve la salida (`TestingController.php:16-44`). Ejecución de comandos internos + fuga de información.
- `GET /api/dev/database-status` → enumera nombres de tablas, conteos de filas y ruta del archivo de base (`TestingController.php:69-87`).
- `GET /api/dev/system-info` → filtra `app.env`, `app.debug`, drivers de cache/queue/DB (`TestingController.php:117-129`).
- `POST /api/dev/fresh-migrations` → protegido por `config('app.allow_fresh_migrations')`, pero sigue sin auth.

**Escenario de explotación:** Un atacante que alcance el host (misma LAN, o Internet si está publicado) hace `POST /api/dev/clean-database` y destruye toda la cartera, conversaciones, pólizas y usuarios. No requiere credenciales.

**Acción correctiva:**
1. Eliminar el grupo `dev` de `routes/api.php` en producción, o envolverlo en `if (app()->environment('local'))`.
2. Como red de seguridad adicional, agregar `->middleware(['auth:sanctum', 'admin'])` al grupo mientras exista.
3. Nunca desplegar `clean-database` / `fresh-migrations` accesibles sin auth+entorno.

---

## Vuln 2 — ALTA: `quotes/{quote}/raw` sin auth (IDOR + PII)

**Ubicación:** `routes/api.php:44` → `QuoteController::showRaw()` (`QuoteController.php:99-102`)

**Descripción:** La ruta está fuera de todo grupo de middleware. `showRaw` recibe la `Quote` por binding con id entero secuencial y devuelve `getRaw($quote)` — el payload crudo de la cotización, que incluye datos del titular y del vehículo (DNI, teléfono, marca/modelo, código postal — ver campos en `QuoteController::index`).

**Escenario de explotación:** Un atacante itera `GET /api/quotes/1/raw`, `/2/raw`, … y extrae la PII de todas las cotizaciones sin autenticarse.

**Acción correctiva:** Mover la ruta dentro de un grupo `auth:sanctum` (o `auth`) y verificar autorización sobre la quote. Si es consumo interno server-to-server, protegerla con token de servicio.

---

## Vuln 3 — ALTA: `v1/quotes` (store) sin auth + mass assignment

**Ubicación:** `routes/api.php:27` → `QuoteController::store()` (`QuoteController.php:92-97`)

**Descripción:** Endpoint público que hace `$this->quoteService->create($request->all())` — pasa el request completo sin validación. Sin autenticación y con asignación masiva del payload entero.

**Escenario de explotación:** Un atacante crea cotizaciones arbitrarias / setea campos no previstos vía `$request->all()`, contaminando datos o forzando estados inesperados.

**Acción correctiva:** Autenticar la ruta y reemplazar `$request->all()` por un `FormRequest` con `validated()` que liste explícitamente los campos permitidos.

---

## Vuln 4 — ALTA: `web-chat/v1/tools/*` sin auth (exposición de PII)

**Ubicación:** `routes/api.php:36-42` → `ToolsController`

**Descripción:** Los cinco endpoints (`identify-customer`, `identify-vehicle`, `coverage-preference`, `get-quote`, `checkout`) están sin middleware de auth. `identifyCustomer` recibe datos del request y ejecuta lookups de cliente; `checkout` opera el flujo de compra. Además loguea `$request->all()` (`ToolsController.php:31,33`).

**Escenario de explotación:** Un atacante consulta `identify-customer` con DNI/teléfono para confirmar existencia de clientes y extraer datos asociados, o abusa de `checkout`/`get-quote` sin credenciales.

**Acción correctiva:** Proteger el grupo con un token de servicio (`auth:sanctum` o middleware propio con secreto compartido) ya que lo consume el frontend de chat server-side. Validar entradas con FormRequests. Revisar el logging de PII (ver nota abajo).

---

## Vuln 5 — MEDIA: canal de broadcast `chat.{sessionUuid}` autoriza a cualquiera

**Ubicación:** `routes/channels.php:9-11`

**Descripción:** El callback de autorización del canal privado retorna `true` incondicionalmente. Cualquier usuario autenticado puede suscribirse a la sesión de chat de cualquier otro conociendo el `sessionUuid`, y recibir los resultados de cotización broadcasteados.

**Escenario de explotación:** Un usuario del panel se suscribe a `chat.{uuid}` de otra sesión y escucha en tiempo real las cotizaciones y datos que se emiten por ese canal. El UUID mitiga el guessing, pero la autorización no verifica pertenencia.

**Acción correctiva:** Verificar que el usuario/sesión tenga relación legítima con `sessionUuid` (p. ej. que sea el dueño de la conversación) en vez de `return true`.

---

## Vuln 6 — MEDIA: `trustProxies(at: '*')`

**Ubicación:** `bootstrap/app.php:36`

**Descripción:** Se confía en todos los proxies. Esto hace que `X-Forwarded-For` / `X-Forwarded-Host` sean tomados tal cual, permitiendo spoofear la IP cliente (usada en logs y en throttles por IP de las rutas mobile) y el Host.

**Escenario de explotación:** Un atacante falsifica `X-Forwarded-For` para evadir rate limiting por IP o para envenenar el `$request->ip()` que se registra en el log del webhook de WhatsApp (`WhatsAppWebhookController.php:54`).

**Acción correctiva:** Restringir `trustProxies` a los rangos/IP reales del balanceador o CDN (p. ej. Cloudflare/nginx) en lugar de `'*'`.

---

## Vuln 7 — BAJA: `tools/test` refleja y loguea el request

**Ubicación:** `routes/api.php:16-25`

**Descripción:** Endpoint público que hace `Log::info(..., $request->all())` y devuelve `received_data => $request->all()`. Es un endpoint de prueba; refleja input y loguea todo el body.

**Acción correctiva:** Eliminarlo del deploy productivo.

---

## Nota transversal — logging de PII

Varios controladores loguean `$request->all()` en flujos que transportan DNI/teléfono/email (`ToolsController.php:31,33`). No es la vulnerabilidad principal, pero conviene sanear el logging de datos personales para cumplir con manejo de PII. Reducir a identificadores no sensibles o enmascarar.

---

## Priorización sugerida

1. **Hoy:** Vuln 1 (quitar/gate `/api/dev/*`) — riesgo de destrucción total de datos.
2. **Esta semana:** Vulns 2, 3, 4 (autenticar endpoints `api` que exponen o mutan datos).
3. **Siguiente iteración:** Vulns 5, 6, y saneo de logging de PII.
