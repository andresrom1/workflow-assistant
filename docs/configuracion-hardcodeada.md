# Configuración hardcodeada — mapa de referencia

Registro único de valores de configuración: dónde viven hoy (código, config+env, o
`system_settings`) y **a dónde deberían ir**. Es el insumo para extender la **vista de
configuración** (`/admin/settings`) — el triaje (qué pasa a la vista) se decide con este
mapa, ítem por ítem.

> **Estado de `SystemSetting`:** la infra ya existe y está en uso (`SettingsService` +
> vista admin + seed por migración de feature). Ya no es "futura". Los grupos activos
> hoy: `checkout`, `facturacion`, `followup`.

## Destinos posibles (taxonomía)

| Cat | Criterio | Destino |
|---|---|---|
| **A — Operativo de negocio** | ¿Se cambiaría sin deploy? | `system_settings` (vista admin, efecto inmediato) |
| **B — Infra por entorno** | ¿Cambia entre dev/prod, o es credencial? | `config/*.php` + `.env` (queda como está; a lo sumo exponer el env que falte) |
| **C — Invariante de dominio** | ¿Cambiarlo sin tests rompe un invariante o es catálogo verificado contra el proveedor? | Queda en código (`const`) |
| **P — Perfil por-PAS** | ¿Es dato de un usuario, no del sistema? | Vista de perfil de usuario (cada PAS carga los suyos) |

**Precedencia establecida** (referencia `App\Services\Facturacion\Emisor`):
`SettingsService::get(key)` → `config(key)` → default en código.

## Cómo encontrarlos

Cada valor nuevo que se fije en código lleva un comentario-flag inline. Listarlos con:

```bash
grep -rn "opcion-de-configuracion" workflow-assistant/
```

- Se marca con `// opcion-de-configuracion: <qué es>` el punto donde el valor está fijado.
- Este registro se **amplía** cuando aparece un hardcodeo nuevo: flag inline + fila acá.

---

## Registro

### 1. Operador / PAS por default

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Email del operador / PAS por default | `config/mango.php` (`default_pas_email`) y fallback en `App\Models\User::defaultPas()` (`AdminUserSeeder::EMAIL`) | env `MANGO_DEFAULT_PAS_EMAIL` / `MANGO_DEV_PAS_EMAIL`; fallback `andresrom@gmail.com` | **A** — `mango.default_pas_email` |
| Nombre del operador | `database/seeders/AdminUserSeeder.php` | env `MANGO_DEV_PAS_NAME`; default `Andrés Romero` | **A** — `mango.operator_name` |
| Matrícula del PAS | `database/seeders/AdminUserSeeder.php` (`metadata`) | env `MANGO_DEV_PAS_MATRICULA`; default `97072` | **P** |
| Teléfono del PAS | `database/seeders/AdminUserSeeder.php` (`metadata`) | env `MANGO_DEV_PAS_PHONE`; default `+5493516280778` | **P** |
| Avatar del PAS | `database/seeders/AdminUserSeeder.php` (`metadata`) | env `MANGO_DEV_PAS_AVATAR_URL`; default `null` | **P** |
| Password inicial del admin sembrado | `database/seeders/AdminUserSeeder.php` | `changeme-2026` | Solo documentar (no va a vista) |
| Match laxo de customer por email (fase mock) | `config/mango.php` (`mock_customer_matching`) | env `MANGO_MOCK_CUSTOMER_MATCHING`; default `true` | **B** — switch de des-mockeo, muere con el mock |

### 2. WhatsApp / mensajería

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Ventana de silencio del inbox (deslizante) | `config/whatsapp.php` (`inbox_quiet_seconds`); consumido en `ProcessWhatsAppMessage`, `ProcessMediaAttachment` y `ProcessConversationInbox` | env `WHATSAPP_INBOX_QUIET_SECONDS`; default **3s** | **A** — afina UX vs costo LLM; se tunó en prod (bitácoras 2026-07-07 y 2026-08-10) |
| Tope duro de espera del inbox | `config/whatsapp.php` (`inbox_max_wait_seconds`) | env `WHATSAPP_INBOX_MAX_WAIT_SECONDS`; default **15s** | **A** — techo de la ventana deslizante |
| Intercepciones máximas por turno | `config/whatsapp.php` (`inbox_max_intercepts`) | env `WHATSAPP_INBOX_MAX_INTERCEPTS`; default **2** | **A** — cuántas respuestas ya generadas se pueden descartar por seguidilla |
| Typing indicator on/off | `config/whatsapp.php` (`typing_indicator_enabled`) | env `WHATSAPP_TYPING_INDICATOR_ENABLED`; default **true** | **A** |
| Aviso de espera de la cotización | `config/whatsapp.php` (`quote_wait_notice`) | env `WHATSAPP_QUOTE_WAIT_NOTICE` | **A** — copy de cara al cliente; hoy requiere deploy |
| Número público wa.me (landing) | `config/whatsapp.php` (`public_number`) | env `WHATSAPP_PUBLIC_NUMBER` | **A** — marketing editable; hoy requiere deploy |
| URL de descarga de la app (landing) | `config/whatsapp.php` (`app_download_url`) | env `MANGO_APP_DOWNLOAD_URL` | **A** — idem |
| Nombres de templates Meta (3) | `config/whatsapp.php` (`templates.*`) | env `WHATSAPP_TEMPLATE_*`; defaults placeholder | **B** — atados a aprobación de Meta |
| Idioma de templates | `config/whatsapp.php` (`templates.*.language`) | env `WHATSAPP_TEMPLATE_LANGUAGE`; default `es_AR` | **B** |
| Versión de la Cloud API | `config/services.php` (`whatsapp.api_version`); 2 consumidores con default inline `v21.0` | env `WHATSAPP_API_VERSION` | **B** |
| Timeout HTTP de subida de media | `WhatsAppOutboundService:140` | **hardcodeado `->timeout(30)`** (no pasa por config) | **B** — mover a `services.whatsapp.timeout` |
| Código de spam de Meta (131048) | `WhatsAppOutboundService:334`, `WhatsAppSpamLimitException` | hardcodeado | **C** — invariante del proveedor |
| Umbrales de modalidad texto/audio (8 consts) | `MessageModalityDecider:15-39` (`MIN_WORDS`, `WINDOW_SIZE`, `TARGET_RATIO`, `BAND_FLOOR/CEILING`, `BASE_PROBABILITY`, `K`, …) | hardcodeados | **A?** — heurística tunable (TTS vs texto); si se quiere experimentar sin deploy. Requiere tipo `float` en la vista |
| Mensajes fijos de nudge por etapa (4) | `FollowUpStalledConversations::NUDGES` | hardcodeados (copy fija, no pasa por LLM) | **A?** — copy editable; hoy grupo `followup` solo tiene `enabled` |
| Ventana de "conversación estancada" (1h–26h) | `FollowUpStalledConversations:51` (`subHours(26)`/`subHour()`) | hardcodeado (amarrado a la ventana 24h de Meta) | **C** — deriva del invariante de Meta |
| Horario comercial del follow-up (8–20 ART) | `routes/console.php:22-26` + `FollowUpStalledConversations:42-44` | hardcodeado | **A?** — horario de contacto es decisión de negocio |

### 3. Visred (cotización / emisión)

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Monto asegurado GNC default | `config/visred.php` (`default_gnc_amount`); consumido en `VisredQuotationProvider:152` | env `VISRED_DEFAULT_GNC_AMOUNT`; default **1.500.000** | **A** — `visred.gnc_default_amount` (valor de negocio, se ajusta con el mercado) |
| Tope de descuento por compañía | `config/visred.php` (`max_discount_percent`) — mapa `company_id → %` + default | env solo para el default; `triunfo => 20.0` hardcodeado (verificado live: >20% → 400) | **A** — `visred.max_discount_percent`; requiere tipo **json/mapa** en la vista. Los topes deberían confirmarse con Visred (D8) |
| Budget/intervalo de polling de cotización | `config/visred.php` (`poll_budget` 120s, `poll_interval` 4s); 2 consumidores | env `VISRED_POLL_*` | **B** — invariante delicado: budget + overhead LLM < timeout del worker (180s). NO exponer en la vista sin validación |
| Timeout HTTP Visred | `config/visred.php` (`timeout` 30s) | env `VISRED_TIMEOUT` | **B** |
| Reintentos de descarga de documentos | `config/visred.php` (`document_retry_delay`/`_backoff`, 60s/60s) + `CapturePendingPolicyDocuments::$tries = 10` | env `VISRED_DOCUMENT_RETRY_*`; tries hardcodeado | **B** — ventana acotada a propósito (el `presale_id` caduca) |
| TTL caché catálogo condiciones fiscales | `config/visred.php` (`tax_conditions_ttl` 86400) | env | **B** |
| TTL caché catálogo marcas de tarjeta | `config/visred.php` (`credit_cards_ttl` 86400); una entrada por compañía + la del global | env | **B** |
| TTLs de tokens Visred (3300/72000s) y retry transitorio (3 × 200ms) | `VisredClient:39-45` | hardcodeados | **C** — derivados del proveedor |
| Mapa de fotos de inspección (10 keys) | `config/visred.php` (`inspection_photo_map`) | verificado contra sandbox (2026-06-07) | **C** — catálogo del proveedor |
| Mapa `task_type → kind` de documentos | `config/visred.php` (`document_task_types`) | verificado live (Triunfo, 2026-06-19) | **C** |
| `FUEL_MAP` (binario sin-gnc/gnc) | `VisredQuotationProvider:53` | verificado live (2026-07-20, bitácora) | **C** — Galicia/RUS validan río abajo |
| `BRAND_ALIASES`, `PROVIDER`, `PRODUCT` | `VisredQuotabilityResolver:25-30` | hardcodeados | **C** — léxico del resolver |
| Paths de API (`/v1/...`) | consts en `Visred{Quotation,Emission,Document,Inspection}*` | verificados contra sandbox | **C** |
| Sandbox flag (`X-Mock-Scenario`) | `config/visred.php` (`sandbox`) | env `VISRED_SANDBOX` | **B** — en prod debe quedar `false` (403 si se manda a la API real) |

### 3b. Cotizaciones (vigencia y vocabulario)

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Vigencia de una cotización | `Quote::endOfBusinessDay()`, aplicado en `QuoteRepository::saveResults` | fin del día calendario **argentino**, expresado en UTC | **C** — es una regla de negocio, no un parámetro: los precios de las compañías valen por el día en que se cotizó. A las 00:01 hay que recotizar porque la tarifa puede haber cambiado. Una cotización generada 23:50 ART vence en 10 minutos, y es el comportamiento pedido |
| Vocabulario de coberturas conocido | `config/quotes.php` (`tags_conocidos`) | snapshot del vocabulario observado del proveedor | **A?** — no es un mapeo (nadie traduce contra él): es el canario que avisa cuando el proveedor manda un tag nuevo y el diff de la vista pública empieza a reportar diferencias falsas. Se actualiza a mano cuando el aviso salta. Ver `QuoteService::auditarVocabulario()` |

### 4. Checkout / emergencia

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Fotos requeridas (7), TTL fotos temp (24h), email notificaciones | `system_settings` grupo `checkout` | **ya en la vista** ✅ (seed: migración 2026-07-20) | **A ✓** |
| TTL del token de tracking de emergencia | `EmergencyTrackingToken::DEFAULT_TTL_HOURS = 4` | hardcodeado | **A?** — ventana de seguridad del link público |
| Offset de replay del tracking | `TrackingController::REPLAY_OFFSET_SECONDS = 70` | hardcodeado | **C** — detalle de implementación del player |
| Template de URL de Google Maps | `Mobile/EmergencyController:51` | hardcodeado | **C** |

### 5. IA / agentes / RAG

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Modelo de texto por default | `config/ai.php` (`providers.deepseek.models.text.default`) | env `DEEPSEEK_MODEL`; default `deepseek-v4-flash` | **B ✓** — lo lee `DeepSeekProvider::defaultTextModel()`; aplica a los 4 agentes anónimos (`CheckCoverageRuleTool`, `PromptReevaluationService`, `ExtractCoverageDocumentText`, `ContentClassifier`) y a cualquier agente sin atributo de tier |
| Modelo del tier barato | `config/ai.php` (`providers.deepseek.models.text.cheapest`) | env `DEEPSEEK_MODEL_CHEAP`; default `deepseek-v4-flash` | **B ✓** — lo eligen con `#[UseCheapestModel]` 6 agentes: `CustomerIdentifier`, `VehicleIdentifier`, `CoveragePreference`, `Quote`, `Disambiguation`, `IngestaExtractor` |
| Modelo del tier smart | `config/ai.php` (`providers.deepseek.models.text.smartest`) | env `DEEPSEEK_MODEL_SMART`; default `deepseek-v4-pro` | **B ✓** — lo eligen con `#[UseSmartestModel]` 2 agentes: `CheckoutAgent`, `ConversationAnalyzerAgent` |
| Modelo por agente | atributo de **tier** (`#[UseCheapestModel]` / `#[UseSmartestModel]`) en las 8 clases de `app/AI/Agents/` | ningún nombre de modelo vive en `app/` | **C** — el tier es la decisión de dominio (¿necesita razonar?); el modelo concreto de cada tier es **B**, arriba. Migrado 2026-07-25 desde `#[Model('deepseek-chat'\|'deepseek-reasoner')]` |
| Feature flag análisis semántico | `config/ai.php` (`semantic_analysis.enabled`) | env `AI_SEMANTIC_ANALYSIS_ENABLED`; default `false` | **A?** — hoy apagado por decisión (ver deuda ROADMAP); el encendido exige revisar arquitectura primero |
| Params análisis semántico (window 6, throttle 5m, trigger cada 3) | `config/ai.php` (`semantic_analysis.*`); 3 consumidores con default inline | env `AI_SEMANTIC_ANALYSIS_*` | **B** |
| Chunking RAG (600/50/40 palabras) | `ChunkAndEmbedService:14-24` | hardcodeado | **C** — recalibrar exige re-embed del corpus |
| Dimensiones de embedding (1536) | `ChunkAndEmbedService:62` | hardcodeado | **C** — amarrado al schema de la columna pgvector |
| Cap de texto al LLM (16.000 chars) | `config/ingesta.php` (`max_text_chars`) | **hardcodeado, sin env** (techo de costo; <16k rompe extracción en pólizas empaquetadas — hallazgo 2026-07-13) | **B** — exponer env `INGESTA_MAX_TEXT_CHARS` |
| CUITs de aseguradoras (5) y otros emisores (1) | `config/ingesta.php` (`company_cuits`, `other_issuer_cuits`) | hardcodeados (portados del parser v5, verificados contra corpus real) | **C** — registro canónico; si crece, tabla `companies` |
| Alias de compañía → nombre canónico (7) | `config/ingesta.php` (`company_aliases`) | hardcodeado | **C** |
| `AI_STATE_DEFAULTS`, `FLAG_KEYS`, maps de archivos de prompts | `Conversation:93`, `AnalyzeConversationSemanticsJob:25`, `SyncAgentPrompts`, `AgentPromptController` | hardcodeados | **C** — dominio |

### 6. SMN (alertas meteorológicas)

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| URL del feed RSS CAP | `SmnAcpFetcher::RSS_FEED_URL` | hardcodeado | **B** — exponer env |
| Timeouts (3s connect / 8s total) y retry (2 × 500ms) | `SmnAcpFetcher:28-34` | hardcodeados | **B** |
| Cadencia estacional (12 min oct–mar / 30 min abr–sep) | `SmnPollAcp:153` | hardcodeado (self-check del tick; scheduler cada 5 min) | **B** |
| Severidades publicables (`Severe`, `Extreme`) | `SmnPollAcp::SEVERIDADES_PUBLICABLES` | hardcodeado | **B?** — decisión de producto (ruido vs cobertura) |
| Retención de alertas (7 días) | `SmnPollAcp::RETENCION_DIAS` | hardcodeado | **B** |
| Topic FCM (`acp-ar`) | `FcmTopicPublisher::TOPIC` | hardcodeado; los clientes mobile se suscriben a este nombre | **B** — cambiarlo exige release de la app |
| Tipos de mensaje CAP permitidos | `CapFeedParser::ALLOWED_MSG_TYPES` | spec CAP | **C** |
| Polígonos de prueba | `SmnTestPush::POLIGONOS` | comando de test | **C** |

### 7. Facturación (AFIP)

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| Datos del emisor (8 keys) | `system_settings` grupo `facturacion` | **ya en la vista** ✅ | **A ✓** |
| Flag homologación/producción | `config/afip.php` (`homologacion`) | env `AFIP_HOMOLOGACION` | **B** — flip por entorno con cambio de certificado |
| Tipo comprobante (11) / concepto (2) | `config/afip.php` | hardcodeado | **C** — invariante fiscal del módulo |
| URLs WSAA/WSFE (homo + prod) | `config/afip.php` (`urls`) | hardcodeadas | **C** — endpoints de gobierno, seleccionados por el flag |
| Mapa condición IVA receptor (RG 5616) | `config/afip.php` | hardcodeado | **C** |
| TTL del ticket de acceso (12h) | `config/afip.php` (`ta_cache_ttl`) | env | **C** — derivado de AFIP |
| Namespace SOAP WSFE, URL del QR (RG 4892) | `AfipSoapService:23,167` | hardcodeados | **C** |

### 8. Colas / jobs

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| `retry_after` por conexión (90/200/150/360s) | `config/queue.php` | parcialmente env (`DB_QUEUE_RETRY_AFTER`) | **C** — invariante documentado: debe superar el `--timeout` del worker; tocarlo sin revisar workers = reclaim doble |
| `$tries`/`$backoff`/`$timeout` de 17 jobs | `app/Jobs/*` (p.ej. `EmitInvoiceBatch` 600s/3, `EmitirPoliza` 5×120s, `SendWhatsApp*` 5×10s) | hardcodeados | **C** — idem; conviven con `retry_after` |
| `SettingsService::CACHE_TTL` (3600s) | `SettingsService:13` | hardcodeado | **C** |

### 9. Mobile API

| Valor | Ubicación | Valor actual / fuente | Destino |
|---|---|---|---|
| TTL del token Sanctum mobile (60 días) | `Mobile/AuthController::TOKEN_TTL_DAYS` | hardcodeado | **B** |
| Máx. contactos de emergencia por cuenta (3) | `EmergencyContact::MAX_PER_ACCOUNT` | hardcodeado | **B?** — límite de producto |
| Máx. shares activos por risk (2) | `SharedRisksController::MAX_ACTIVE_PER_RISK` | hardcodeado | **B?** — idem |
| Regex de teléfono E.164 | `Mobile/EmergencyContactsController::PHONE_REGEX` | hardcodeado | **C** |

### 10. Keys fantasma (consumidas por `config()` sin existir en ningún config file)

El default **solo vive inline** en el consumidor: no son editables ni por env. Definirlas
en el config file que corresponda (con env) o en `system_settings`, según destino.

| Valor | Ubicación del consumidor | Default inline | Destino |
|---|---|---|---|
| `app.allow_fresh_migrations` | `TestingController:52` | `false` | **B** — tooling de dev/QA |
| `app.tracking_base_url` | `Mobile/EmergencyController:207` | `config('app.url')` | **B** — URL pública para los links de tracking (útil detrás de proxy) |
| `mail.checkout_notifications_to` | fallback en `EmitirPoliza:110`, `CheckoutController:359` | `mail.from.address` | **B** — ojo: vive colgada de `mail.from` (ubicación espuria); el setting `checkout.notifications_email` ya tiene precedencia |

### 11. Residuo eliminado (limpieza 2026-07-20)

Residuo del proyecto legacy **pas-mobile** extirpado en esta fecha (sin consumidores en
`app/`); la "API de Emisión" fue reemplazada por Visred en la cirugía v2:

- Migración `2026_07_20_000001_drop_poliza_api_settings` — grupo `poliza_api` (3 keys)
  fuera de `system_settings`.
- Bloque `poliza_api` fuera de `config/services.php` (vars `POLIZA_API_*`; quedan en el
  `.env` local, limpieza manual).
- `SystemSettingsSeeder` (huérfano: nadie lo llamaba) **eliminado** y reemplazado por la
  migración `2026_07_20_000002_seed_checkout_settings` — unifica la vía "seed por
  migración de feature" (`facturacion`, `followup`) y siembra `checkout.*` **solo si la
  key no existe** (no pisa valores editados en prod).
- `SettingsController::groupLabel` — labels muertos `pas`/`poliza_api` fuera; agregados
  `facturacion`/`followup`.
- `Admin/Settings/Index.vue` — dashboard "Estado actual del sistema" hardcodeado fuera
  (stat cards + `EndpointRow` de la API muerta), íconos actualizados.

### 12. Gaps de la vista actual (a resolver al extenderla)

- **Tipos soportados:** `string`, `integer`, `boolean`, `secret`. **Faltan:** `float`
  (topes de descuento, ratios de modalidad) y `json`/mapa (`max_discount_percent` por
  compañía).
- **Labels e íconos de grupo hardcodeados** en `SettingsController::groupLabel` y en
  `groupIcon()` del Vue — agregar un grupo exige tocar código. Mover a config o a una
  columna de metadata.
- **Sin validación por rango** (min/max) — necesaria antes de exponer valores con
  invariantes (p.ej. `poll_budget` vs timeout del worker), aunque esos se propone que
  queden en **B** justamente por eso.
- **Precedencia no uniforme:** algunos consumidores usan `SettingsService::get(k, cfg)`
  y otros `settings ?: config` (ver `Emisor` como referencia correcta).

## Notas

- El `email` del PAS es inherente al `User` (es su login), no se documenta como config aparte.
- Los valores **B** no van a la vista: se listan acá para tener el inventario completo y
  detectar los que ni siquiera tienen env expuesto (§6, §10).
