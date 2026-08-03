<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/ai (AI) - v0
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- tightenco/ziggy (ZIGGY) - v2
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- @inertiajs/vue3 (INERTIA_VUE) - v2
- vue (VUE) - v3
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code.
- `inertia-vue-development` — Develops Inertia.js v2 Vue client-side applications. Activates when creating Vue pages, forms, or navigation; using <Link>, <Form>, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions Vue with Inertia, Vue pages, Vue forms, or Vue navigation.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.
- `ai-sdk-development` — TRIGGER when working with ai-sdk which is Laravel official first-party AI SDK. Activate when building, editing AI agents, chatbots, text generation, image generation, audio/TTS, transcription/STT, embeddings, RAG, vector stores, reranking, structured output, streaming, conversation memory, tools, queueing, broadcasting, and provider failover across OpenAI, Anthropic, Gemini, Azure, Groq, xAI, DeepSeek, Mistral, Ollama, ElevenLabs, Cohere, Jina, and VoyageAI. Invoke when the user references ai-sdk, the `Laravel\Ai\` namespace, or this project's AI features — not for Prism PHP or other AI packages used directly.
- `whatsapp-laravel` — TRIGGER when working on the WhatsApp integration. Covers webhook security (HMAC), Meta Cloud API, conversation state, templates, interactive messages, and compliance.
- `whatsapp-webhooks-bsuid` — Activar cuando el código involucre BSUIDs, `user_id`/`from_user_id`, `wa_id` con valores no numéricos, la transición de identidad 2026, resolución dual-key de contactos, REQUEST_CONTACT_INFO, Portfolio Pacing, o el evento `user_id_update`.
## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.
## WhatsApp + Laravel AI SDK — Patterns

### Orchestrator-Workers pattern (app/AI/)
The insurance flow uses an orchestrator that reads state from `conversations.metadata.ai_state`
and delegates to a specialized sub-agent per step:

| State field | false → active agent | true → moves to |
|---|---|---|
| `customer_identified` | `CustomerIdentifierAgent` | vehicle step |
| `vehicle_identified` | `VehicleIdentifierAgent` | coverage step |
| `coverage_set` | `CoveragePreferenceAgent` | quote step |
| `quote_ready` | `QuoteAgent` | checkout step |
| all true | `CheckoutAgent` | — |

State is updated inside each Tool's `handle()` method upon success (not in the orchestrator).

**Excepción: `checkout_done`.** Lo escribe `QuoteService::crearCheckout()`, no `CheckoutTool`. El
checkout también se abre desde el CTA de la vista pública de cotizaciones, donde no corre ninguna
tool; si el flag viviera en la tool, el agente no se enteraría de un checkout iniciado desde la web
y seguiría vendiendo. Ese método es el punto único de apertura de checkout — lo llaman
`WhatsAppAdapter`, `AgentToolAdapter` y `PublicQuoteController`.

### Editar prompts de agentes — REQUIERE SYNC MANUAL

Los prompts viven en `resources/prompts/agents/*.md` pero el runtime los carga de la tabla `agent_prompts` (ver `AgentPrompt::activeFor($key)`). El archivo `.md` es solo fallback. Cada agente cachea el resultado con `Cache::rememberForever("agent_prompt:{$agentKey}")`.

**Editar el `.md` NO basta.** Hay que:
1. Actualizar el row activo en `agent_prompts` con el nuevo contenido (`content = file_get_contents(...)`).
2. Invalidar la caché: `Cache::forget("agent_prompt:{$agentKey}")`.

Sin estos dos pasos, el LLM sigue sirviendo el prompt viejo y el fix no se nota aunque el `.md` esté actualizado en el repo.

Agent keys actuales: `coverage_check`, `customer_identifier`, `vehicle_identifier`, `coverage_preference`, `checkout_closer`. (QuoteAgent no tiene row en DB — usa el `.md` directo.)

Snippet rápido para sincronizar todos los prompts desde los archivos:
```php
foreach (['coverage_check', 'customer_identifier', 'vehicle_identifier', 'coverage_preference', 'checkout_closer'] as $key) {
    $file = match ($key) {
        'coverage_check' => 'CoverageCheckAgent.md',
        'customer_identifier' => 'CustomerIdentifierAgent.md',
        'vehicle_identifier' => 'VehicleIdentifierAgent.md',
        'coverage_preference' => 'CoveragePreferenceAgent.md',
        'checkout_closer' => 'CheckoutAgent.md',
    };
    AgentPrompt::where('agent_key', $key)->where('is_active', true)
        ->update(['content' => file_get_contents(resource_path("prompts/agents/{$file}"))]);
    Cache::forget("agent_prompt:{$key}");
}
```

### Agent anatomy (laravel/ai v0.4.2)
```php
class MyAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): string { return 'System prompt here.'; }
    public function tools(): iterable { return [$this->tool]; }
}
```
- `instructions()` — not `system()`
- `messages()` — provided by `RemembersConversations`, do NOT override unless needed
- Memory key: `continueLastConversation((object)['id' => $userId])` — the `id` is a string

### Tool anatomy (laravel/ai v0.4.2)
```php
class MyTool implements Tool
{
    public function description(): string { ... }
    public function schema(JsonSchema $schema): array { return ['field' => $schema->string()]; }
    public function handle(Request $request): string { return json_encode($result); }
    // Request class: Laravel\Ai\Tools\Request
}
```

### Las tools SIEMPRE entran por `handleToolCall()` — nunca al handler directo

```php
// ✅
$result = $this->adapter->handleToolCall($request->all(), 'identify_customer', $this->conversation);

// ❌ saltea el try/catch y deja el error invisible
$result = $this->adapter->identifyCustomer($request->all(), $this->conversation);
```

`WhatsAppAdapter::handleToolCall()` es el único lugar con `try/catch` + logging. Si una tool llama al
handler directo, la excepción escapa al SDK: Prism traduce cualquier `TypeError` o
`InvalidArgumentException` a `"Invalid parameters for tool : X"`, lo captura en
`CallsTools::executeToolCall()` y lo devuelve como resultado de tool **sin loguear nada**, tirando el
mensaje original. Resultado: el modelo recibe un texto opaco, contesta "tuve un inconveniente técnico"
y en `laravel.log` no queda ni una línea del turno. Pasó en producción — ver ROADMAP, bitácora
2026-07-25.

El catch es sobre `\Throwable`, no `\Exception`: un `TypeError` (argumento con el tipo equivocado desde
el modelo) es `Error` y con `\Exception` se escapaba.

### Directory conventions
- `app/AI/Agents/` — sub-agents (one per workflow step)
- `app/AI/Tools/` — tool classes (one per adapter operation)
- `app/AI/InsuranceOrchestrator.php` — orchestrator
- `app/Adapters/AIProviders/` — new adapters (WhatsAppAdapter, etc.)

### WhatsApp webhook flow — pipeline de 3 etapas

```
Webhook → ProcessWhatsAppMessage (default)
               ↓ persiste mensaje con processed_at=null
               ↓ dispatch con delay = config('whatsapp.inbox_debounce_seconds') (8s)
          ProcessConversationInbox (whatsapp-ai)
               ↓ agrupa todos los mensajes pendientes
               ↓ concatena con \n → una sola llamada al AI
               ↓ marca processed_at ANTES de llamar al AI
          InsuranceOrchestrator::handle($combinedBody)
               ↓
          SendWhatsAppMessage (whatsapp-outbound)
               ↓
          WhatsAppOutboundService::sendMessage()
```

**Por qué 3 etapas:**
- **Ingesta** (`ProcessWhatsAppMessage`): rápida, sin AI, solo persiste y despacha
- **Inbox** (`ProcessConversationInbox`): agrupa mensajes rápidos del usuario en una sola llamada al AI → una sola respuesta coherente. Usa `WithoutOverlapping("inbox:{$conversationId}")` para serialización por conversación.
- **Outbound** (`SendWhatsAppMessage`): retry independiente del AI. Si Meta API falla, no se re-procesa el LLM.

**Regla crítica:** `processed_at` se setea ANTES de llamar al AI. Si el job falla y reintenta, encuentra el inbox vacío y sale limpiamente — evita doble llamada al LLM con los mismos mensajes.

**Debounce:** El inbox processor se despacha con `->delay(now()->addSeconds(config('whatsapp.inbox_debounce_seconds')))` (8s por defecto, tuneable por `WHATSAPP_INBOX_DEBOUNCE_SECONDS`). Si llegan varios mensajes dentro de la ventana, los jobs de ingesta terminan antes de que el primer inbox processor corra, y este los agrupa todos. Muy corto → dos mensajes tipeados con pocos segundos de diferencia disparan dos llamadas al LLM y respuestas que se pisan.

### Idempotencia
Use the `wamid` (WhatsApp message ID) as cache key: `processed_wamid_{wamid}`.
TTL: 24 hours. Meta may resend the same webhook if the 200 response is delayed.

### Arquitectura por capas: Adapter → Service → Repo

```
AI Agent Tool
     ↓
WhatsAppAdapter          ← conoce WhatsApp, normaliza payloads, maneja errores del canal
     ↓
CustomerIdentificationService / VehicleIdentificationService / QuoteService ...
     ↓                   ← lógica de negocio pura, agnóstica del canal
CustomerRepository / VehicleRepository / QuoteRepository ...
                         ← acceso a datos, agnóstico del canal
```

**Regla:** Los Services y Repositories no saben que están siendo llamados desde WhatsApp. Reciben y retornan modelos de dominio (`Customer`, `Vehicle`, `Quote`). El Adapter es el único que conoce el canal.

**Por qué importa:** permite reutilizar la misma lógica de negocio desde distintos canales (WhatsApp, REST API, web UI) sin duplicar código. Al agregar un nuevo canal, solo se escribe un nuevo Adapter.

**Ubicaciones:**
- `app/Adapters/AIProviders/WhatsAppAdapter.php` — traduce tool calls del AI a llamadas de servicio
- `app/Services/` — lógica de negocio (agnóstica)
- `app/Repositories/` — acceso a datos (agnóstico)

### Toda puerta identifica con `CustomerIdentificationService`

Cualquier lugar donde entra un cliente **busca primero por el servicio de identificación** y recién crea si no encontró a nadie. Nunca `CustomerRepository::create()` a secas, y nunca una consulta propia por documento/teléfono/email.

```php
// ✅ el servicio es la única búsqueda de identidad
$customer = $identification->findCustomer('ext_user_id', $bsuid)
    ?? $identification->findCustomer('phone', $waId)
    ?? $customerRepo->create([...]);

// ❌ crear sin identificar → cliente duplicado
$customer = $customerRepo->create(['phone' => $waId]);
```

`findCustomer($type, $value)` acepta `ext_user_id` (el BSUID de WhatsApp, que se resuelve por `conversations.ext_user_id` porque **no** se guarda en `customers`), `phone`, `email` y `dni`. Para `dni` compara la **identidad derivada**, no el número crudo: DNI y CUIL/CUIT de la misma persona física resuelven a la misma fila.

Las puertas hoy: ingesta de WhatsApp (`ProcessWhatsAppMessage`), chat (`resolveForConversation`), ingesta local de pólizas y reporte de cartera (`PolicyChainResolver`), alta manual del admin (`CustomerController`, `ConversationController`) y checkout (`CheckoutController`).

Historia, para que no se repita: hasta el 2026-07-24 el orquestador hacía esta búsqueda en cada turno (`tryAutoIdentifyByPhone`); el refactor a BSUID la eliminó sin reemplazarla y cada conversación nueva empezó a acuñar un cliente duplicado. Ver ROADMAP, bitácora 2026-07-26. Para limpiar duplicados viejos: `php artisan customers:dedupe` (en seco; `--apply` para ejecutar).

**Servicio ≠ helper.** `App\Support\DocumentoIdentidad` es una utilidad pura que cualquier clase puede llamar para normalizar un documento o derivar la identidad (DNI si es persona física, CUIT completo si es jurídica). El modelo `Customer` la usa en su hook `saving` para llenar `documento_key`. Por el **servicio** se pasa cuando lo que se está haciendo es *identificar a un cliente*; el helper se llama directo cuando solo hace falta formatear o comparar un número.

### Principio de desacople (REGLA GENERAL — no es opcional)

Al agregar una responsabilidad nueva, **NO la cuelgues de un componente existente de otra capa "porque está cerca en el flujo". Creá un componente nuevo que dependa solo del modelo de dominio.** Esta regla es general; los ejemplos de abajo son instancias, no el límite.

1. **Agnóstico de canal.** Una preocupación **estable** (dominio, integración con un proveedor externo, traducción a un catálogo de un tercero) **no debe depender** de la capa **conversacional/de canal**: el orquestador de WhatsApp, los agentes (`*Agent`), las tools (`*Tool`), ni `conversations.metadata.ai_state`. Es la misma regla que "los Services/Repositories no saben que se los llama desde WhatsApp", elevada a principio: vale para adapters de proveedor, resolvers, y cualquier servicio de dominio.

2. **Comunicación vía el modelo de dominio, no metiéndose entre componentes.** Si A produce hechos de dominio y B los consume, la dependencia es **`A → dominio ← B`**, nunca `A → B`. No agregar una arista directa entre componentes de capas/propósitos distintos.

3. **Una responsabilidad por componente.** Un componente construido para un propósito (p. ej. NLU de la intención del cliente) **no absorbe** otro propósito (p. ej. traducir el vehículo al catálogo de un proveedor). Responsabilidad nueva = componente nuevo. Antes de extender/editar algo existente, preguntá: *¿esta responsabilidad pertenece a esta capa?* Si no, es un componente nuevo.

**Instancia concreta (ejemplo, no la regla):** `VehicleIdentifierAgent` / `IdentifyVehicleTool` / `VehicleIdentificationService` son **solo NLU de intención del cliente en el chat** — intocables. La resolución/traducción contra el catálogo de un proveedor (p. ej. desambiguar "Active" → "Active VTI" → `version_id` de Visred) es un **componente separado y agnóstico de canal**: lee del modelo de dominio, **nunca** de esas clases ni del orquestador; devuelve un resultado (`resuelto | ambiguo | no-encontrado`) y **no le habla al cliente** — llevar una ambigüedad a la conversación lo decide la capa de canal, no el componente de traducción.

## Checkout — Fotos de inspección en mobile (arquitectura de memoria)

El formulario de checkout captura 7 fotos desde la cámara del celular. Los navegadores móviles (iOS Safari, Android Chrome) tienen un límite estricto de RAM (~100-200MB). Si se excede, el OS mata el tab y el formulario se resetea perdiendo todo el estado.

### Reglas OBLIGATORIAS — NO modificar sin entender el impacto

1. **Canvas manual con destrucción agresiva** (`processPhoto` en `Checkout/Show.vue`):
   - `img.src = ''` + `img.onload = null` + `img.onerror = null` después de dibujar al canvas
   - `URL.revokeObjectURL()` inmediatamente en `onload` / `onerror`
   - `canvas.width = 0; canvas.height = 0` después de generar el blob
   - **NO reemplazar con librerías** como `browser-image-compression` — no ofrecen control granular de destrucción de bitmap y crashean en la 3ra-4ta foto en mobile

2. **Micro-thumbnails para preview** (64x64px data URL):
   - El `<img>` de preview en cada card usa un data URL de 64px generado desde el canvas (antes de destruirlo)
   - **NUNCA usar la URL completa de R2/cloud** como `src` del preview — cada imagen de 1024px decodifica a ~4MB de bitmap en el DOM; con 3+ fotos = OOM crash
   - 7 thumbnails × 16KB bitmap = 112KB total vs 7 × 4MB = 28MB con URLs completas

3. **Processing lock** (`processingLock`):
   - Serializa las capturas para que nunca haya dos bitmaps de cámara decodificándose simultáneamente
   - Se setea en `true` al inicio de `onPhotoCapture` y en `false` en el `finally`

4. **Prevención de reseteo por Inertia** (`stopPopState` + `stopVisibilityChange`):
   - Android dispara `popstate` al volver de la cámara; Inertia lo intercepta y destruye el estado de Vue
   - Ambos handlers deben registrarse con `true` (fase capture) en `onMounted` y removerse en `onUnmounted`
   - **El handler de `visibilitychange` debe ser la función nombrada, NO una arrow anónima** — si se registra una anónima, `removeEventListener` no la encuentra y el evento llega a Inertia

### Storage: Cloudflare R2

- Disk `r2` en `config/filesystems.php` (driver S3, endpoint R2, `use_path_style_endpoint: true`)
- Fotos se suben incrementalmente durante el checkout, no al final
- `InspectionPhoto` model: `storage_path` (path en R2) + `storage_url` (URL pública)
- `CheckoutSession.photo_paths`: almacena URLs públicas de R2 (resueltas en `submit()`)
- `DeleteOrphanPhoto` job: `Storage::disk('r2')->delete($path)` con 3 reintentos
- `CleanupTempPhotos` command: borra fotos temp > 24h

## RAG — Documentacion de Coberturas (pgvector)

Sistema de dos agentes para responder consultas de coberturas con precision:
- **Frontal** (los 5 agentes del orquestador) verifica `full_details` primero, luego delega al Experto via `CheckCoverageRuleTool`.
- **Experto** (`AnonymousAgent` con `SearchCompanyDocumentationTool`) busca en pgvector los chunks relevantes de la documentacion de la compania.

### Pipeline: PDF → texto → chunks → embeddings → pgvector
1. Admin sube PDF en `/coverage-documents` (CRUD Inertia)
2. Extraccion: `ExtractCoverageDocumentText` job (queue default) envia el PDF al LLM para transcripcion literal. Modo alternativo: el admin pega el texto manualmente.
3. El admin revisa/edita el texto extraido en la vista Show.
4. Al guardar: `ChunkAndEmbedService` corre **sincrono** — chunking por headers markdown + `Embeddings::for()->dimensions(1536)->generate()` + insert en `coverage_chunks`.
5. Query-time: `SearchCompanyDocumentationTool` genera embedding de la query, busca con `nearestNeighbors()` (cosine distance) filtrado por `company_slug`.

### Archivos clave
- `app/Models/CoverageDocument.php` / `CoverageChunk.php` — pgvector con `HasNeighbors` trait
- `app/Services/ChunkAndEmbedService.php` — chunking + embeddings (sincrono)
- `app/Jobs/ExtractCoverageDocumentText.php` — extraccion AI (async, queue default)
- `app/AI/Tools/SearchCompanyDocumentationTool.php` — busqueda RAG en pgvector
- `app/AI/Tools/CheckCoverageRuleTool.php` — Expert Agent con `full_details` + RAG
- `resources/prompts/agents/CoverageCheckAgent.md` — prompt del agente experto

### Requisitos de despliegue
- **PostgreSQL** con extension `pgvector` (`CREATE EXTENSION vector;`)
- **php.ini**: `upload_max_filesize = 50M` y `post_max_size = 50M` (los PDFs de manuales de aseguradoras pueden superar 20MB)
- Si se usa Nginx: `client_max_body_size 50m;` en el bloque server
- La validacion Laravel permite hasta 50MB (`max:51200` en `CoverageDocumentController@store`)

## Modales y Confirmaciones

- `window.confirm()` y `window.alert()` están **prohibidos**. Usar siempre modales inline.
- Patrón estándar: `fixed inset-0 bg-black/50 z-50` con `<Transition name="fade">` y variables CSS del design system (`--bg-card`, `--border`, `--shadow-card`). Ver `resources/js/pages/Admin/Conversations/Index.vue` como referencia.
- No existe un componente `Modal.vue` compartido — implementar directamente en el componente que lo necesita.
## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
