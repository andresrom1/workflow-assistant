# Services — workflow-assistant (v2)

> Lógica de negocio **agnóstica del canal** (capa Adapter → **Service** → Repo).
> Cada service indica a qué canal(es) sirve en última instancia.
> Canales: `openai_chatkit`, `pas_mobile` (legacy), `pas-web` (legacy), `mango-mobile`, `workflow-assistant`.

---

## Flujo de cotización (chat web + WhatsApp)

Sirven a `openai_chatkit` **y** `workflow-assistant` (canal WhatsApp). Invocados desde ambos adapters (`OpenAI\AgentToolAdapter`, `AIProviders\WhatsAppAdapter`).

| Service | Responsabilidad |
|---|---|
| `CustomerIdentificationService` | Identifica/crea el `Customer` (lead) a partir de DNI/email/teléfono. |
| `VehicleIdentificationService` | Identifica/crea el `Vehicle` y lo asocia a la conversación. |
| `CoveragePreferenceService` | Persiste la preferencia de cobertura por conversación+vehículo. |
| `PlateNormalizerService` | Normaliza patentes (formatos viejo/Mercosur). |
| `QuoteService` | **Cerebro** del ciclo de vida: `createPendingQuote`, `resolveQuote` (elige estrategia), `updateSnapshotPreference`, `getRaw`. Programa el job de fallback. |

### Motor y estrategias de resolución
| Service | Canal | Nota |
|---|---|---|
| `QuotingEngine` | `workflow-assistant` | "Cerebro" que genera alternativas. **Hoy mock** (`runMockSimulation`, `sleep(30)`). Punto de inserción del **Adapter Visred** (ver doc v2 quote-timeout / consolidado). No escribe en BD. |
| `Quote\Strategies\ApiQuoteResolution` | `workflow-assistant` | Resolución síncrona vía `QuotingEngine` (futuro: Visred). |
| `Quote\Strategies\MobileAppQuoteResolution` | **`pas_mobile` (legacy)** | Enviaba la oportunidad a la app PAS (`Http::post` al endpoint configurado) y esperaba webhook. A retirar con Visred. |
| `Quote\QuoteResolutionStrategyInterface` | — | Contrato del patrón Strategy. |
| `PolizaEmisionService` | `workflow-assistant` | **Skeleton** de emisión contra API externa (futuro: emisión Visred). Construye payload quote+snapshot+checkout. |

---

## WhatsApp (canal de mensajería)

Sirven a `workflow-assistant` (integración WhatsApp Cloud API).

| Service | Responsabilidad |
|---|---|
| `WhatsApp\WhatsAppOutboundService` | Envío de mensajes salientes a Meta (`SendWhatsAppMessage` job). |
| `Message\MessageModalityDecider` | Decide si responder en texto o audio. |
| `Message\ContentClassifier` | Clasifica el contenido del mensaje entrante. |
| `Media\MediaStorageService` | Descarga/almacena media de WhatsApp (R2). |
| `Media\SpeechToTextService` | Transcribe audios entrantes (STT). |
| `Media\TextToSpeechService` | Genera audio de respuesta (TTS). |

---

## RAG — Documentación de coberturas

Canal: `workflow-assistant`.

| Service | Responsabilidad |
|---|---|
| `ChunkAndEmbedService` | Chunking por headers markdown + embeddings (1536d) + insert en `coverage_chunks`. Corre **síncrono** al guardar. |

(Extracción de texto del PDF: `Jobs\ExtractCoverageDocumentText`, async.)

---

## App MANGO — identidad y alertas

Canal: `mango-mobile`.

| Service | Responsabilidad |
|---|---|
| `Firebase\FirebaseTokenVerifier` | Contrato de verificación del Firebase ID Token. |
| `Firebase\KreaitTokenVerifier` | Implementación con el SDK Kreait. |
| `Firebase\VerifiedIdentity` | Identidad OAuth verificada (email/uid) usada para vincular `MobileAccount` ↔ `Customer`. |
| `Smn\SmnAcpFetcher` | Trae el feed SMN (Aviso por Cambio de Pronóstico). |
| `Smn\CapFeedParser` | Parsea el feed CAP del SMN. |
| `Smn\FcmTopicPublisher` | Publica push por FCM a topics (alertas meteorológicas). |

---

## Administración / observabilidad

Canal: `workflow-assistant`.

| Service | Responsabilidad |
|---|---|
| `SettingsService` | Lee/escribe `system_settings` (cacheado). Fuente de timeouts y endpoints configurables. |
| `PromptReevaluationService` | Re-ejecuta un turn con otro prompt/estado (Studio). |

---

## Resumen por canal

| Canal | Services |
|---|---|
| `openai_chatkit` + `workflow-assistant` | Customer/Vehicle/CoveragePreference/Plate Identification, `QuoteService` |
| `workflow-assistant` | `QuotingEngine`, `ApiQuoteResolution`, `PolizaEmisionService`, WhatsApp/Media/Message, `ChunkAndEmbedService`, `SettingsService`, `PromptReevaluationService` |
| `mango-mobile` | `Firebase\*`, `Smn\*` |
| `pas_mobile` (legacy) | `MobileAppQuoteResolution` |
