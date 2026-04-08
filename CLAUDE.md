<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v2
- laravel/ai (AI) - v0
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/vue3 (INERTIA_VUE) - v2
- vue (VUE) - v3
- laravel-echo (ECHO) - v2
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
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

### Directory conventions
- `app/AI/Agents/` — sub-agents (one per workflow step)
- `app/AI/Tools/` — tool classes (one per adapter operation)
- `app/AI/InsuranceOrchestrator.php` — orchestrator
- `app/Adapters/AIProviders/` — new adapters (WhatsAppAdapter, etc.)
- `app/Adapters/n8n-whatsapp/` — deprecated, do not use

### WhatsApp webhook flow — pipeline de 3 etapas

```
Webhook → ProcessWhatsAppMessage (default)
               ↓ persiste mensaje con processed_at=null
               ↓ dispatch con delay 2s
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

**Debounce:** El inbox processor se despacha con `->delay(now()->addSeconds(2))`. Si llegan 3 mensajes en 1 segundo, los 3 jobs de ingesta terminan antes de que el primer inbox processor corra, y este los agrupa todos.

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

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

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

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app\Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app\Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- Pint runs automatically via a PostToolUse hook whenever a `.php` file is edited — you do NOT need to call it manually after every edit.
- If you need to manually format: `php vendor/bin/pint --dirty`
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

# Code Quality Pipeline

This project has three automated quality tools. Run them in this order after making code changes:

| Tool | Command | When to run |
|---|---|---|
| **Pint** (formatter) | `composer lint:fix` | Runs automatically on every PHP file edit via hook |
| **Rector** (refactoring) | `composer refactor` | Before finalizing a feature branch (dry-run first with `composer refactor:dry`) |
| **PHPStan** (static analysis) | `composer analyse` | Before committing — must produce 0 errors (baseline captures pre-existing issues) |
| **Pest** (tests) | `composer test` | After any logic change |

**PHPStan baseline:** `phpstan-baseline.neon` captures 143 pre-existing errors. New code must not add new errors. When you fix a baselined error, regenerate with `vendor/bin/phpstan --generate-baseline`.

=== phpunit/core rules ===

# Pest

- This application uses Pest for testing. All tests must be written using Pest closure syntax (`it()` / `test()`). Use `php artisan make:test {name}` to create a new test.
- If you see a test using PHPUnit class syntax, convert it to Pest.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
