# Schema de Base de Datos — workflow-assistant (v2)

> **Motor:** PostgreSQL (`pgsql`) + extensión `pgvector` (tabla `coverage_chunks`).
> **Generado a partir del schema vivo** (`database-schema`). Refleja el estado tras la salida de `pas_mobile`/`pas-web` y la entrada de `mango-mobile` + Visred API.
>
> **Convención de canal:** cada grupo indica qué sub-proyecto escribe/lee esas tablas.
> Canales posibles: `openai_chatkit`, `pas_mobile` (legacy, extirpado), `pas-web` (legacy, extirpado), `mango-mobile`, `workflow-assistant`.

---

## 1. Identidad y cuentas

### `customers` — Cliente / Lead del flujo de cotización
Canal: `openai_chatkit`, `workflow-assistant` (WhatsApp). Núcleo del dominio.

| Columna | Tipo | Nota |
|---|---|---|
| `id` | bigint PK | |
| `dni` | varchar | Documento. |
| `email` | varchar | Llave de vinculación con `mobile_accounts` (ver doc onboarding). |
| `phone` | varchar | |
| `name` | varchar | |
| `metadata` | json | |
| `is_anonymous` | boolean | Lead aún no identificado. |
| `completed_at` | timestamp | |
| `pas_id` | bigint | PAS asignado (legacy / asignación comercial). |
| `deleted_at` | timestamp | SoftDeletes. |

### `mobile_accounts` — Cuenta de la app MANGO
Canal: `mango-mobile`. Una por usuario de la app Flutter, vinculada por OAuth (Firebase).

`id`, `firebase_uid`, `email`, `name`, `avatar_url`, `email_verified_at`, `customer_id` (FK → `customers`), timestamps.

### `users` — Operadores del panel admin
Canal: `workflow-assistant`. `id`, `name`, `email`, `password`, `role`, `metadata`, `remember_token`.

---

## 2. Conversaciones (chat web + WhatsApp)

### `conversations`
Canal: `openai_chatkit`, `workflow-assistant` (WhatsApp). Estado del orquestador AI vive en `metadata.ai_state`.

Campos relevantes: `external_conversation_id`, `ext_user_id`, `ext_username`, `customer_id`, `channel` (`whatsapp` / `web`), `status`, `metadata` (json, incluye `ai_state`), `flags` (json), `turns_in_current_step`, `last_message_at`, `ended_at`, análisis semántico (`semantic_analysis`, `last_semantic_analysis_at`, `semantic_analysis_turn_count`, `last_health_analysis_at`), SoftDeletes.

### `messages`
Canal: `openai_chatkit`, `workflow-assistant`. `conversation_id`, `direction`, `content`, `external_message_id`, `sender_name`, `sender_phone`, `processed_at` (clave del pipeline de inbox), `agent_name`, `type`, `audio_eligible`, `ai_provider`.

### `message_attachments`
Canal: `workflow-assistant` (WhatsApp media — audio/imagen). `message_id`, `attachment_type`, `external_media_id`, `mime_type`, `file_size`, `duration_seconds`, `storage_path/url`, `transcription`, `processing_status`, `processed_at`.

### `conversation_vehicle` (pivote)
Canal: `openai_chatkit`, `workflow-assistant`. Liga conversación ↔ vehículo. `is_primary` (legacy, ver ERD v1).

### `agent_conversations` / `agent_conversation_messages`
Canal: `workflow-assistant`. Memoria de conversación del SDK `laravel/ai` (`RemembersConversations`). IDs `varchar(36)` (UUID).

---

## 3. Cotización (quote flow)

### `vehicles`
Canal: `openai_chatkit`, `workflow-assistant`. Vehículo del dominio del chat. `customer_id`, `patente`, `marca`, `modelo`, `version`, `year`, `combustible`, `codigo_postal`, `uso`, `motor`, `chasis`, `is_complete`, `metadata`, SoftDeletes.

### `risk_snapshots`
Canal: `workflow-assistant`. **Foto inmutable** del riesgo al momento de cotizar (base para la `Quote`). Copia denormalizada de vehículo + cliente + `coverage_preference`.

### `quotes`
Canal: `openai_chatkit`, `workflow-assistant`. Ciclo de vida de la cotización.

Campos clave: `session_uuid`, `risk_snapshot_id`, `conversation_id`, `status` (`pending` → `offered_pas` → `processed`/`failed`…), `external_ref_id`, `metadata`, `expires_at`, `resolution_method` (`mobile`/`api`), tracking legacy hacia la app PAS (`mobile_opportunity_id`, `mobile_reference`, `sent_to_mobile_at`, `expected_resolution_at`), checkout (`checkout_token`, `checkout_alternative_id`), SoftDeletes.

### `quote_alternatives`
Canal: `openai_chatkit`, `workflow-assistant`. Cada opción cotizada (limpia de IDs de proveedor — ver ADR-001). `aseguradora`, `titulo`, `descripcion`, `normalized_grade`, `precio`, `moneda`, `marketing_title`, `sum_insured_text`, `features_tags` (json), `full_details` (json).

### `quote_provider_refs`
Canal: `workflow-assistant` (auditoría). **Único lugar** con datos de proveedor (`external_quote_id`, `raw_response`). Ver [ADR-001](../v1/adr/001-quote-provider-refs.md) — aísla el scope del proveedor del dominio que ve el agente.

### `coverage_preferences`
Canal: `openai_chatkit`, `workflow-assistant`. Preferencia (`preference`) por combinación conversación+vehículo. `metadata` (json), SoftDeletes.

### `mobile_sync_logs`
Canal: `pas_mobile` (**legacy, extirpado**). Log de sincronización de oportunidades con la app PAS — tabla `mobile_sync_logs` dropeada en V2-6. Reemplazado por el flujo Visred.

---

## 4. Checkout y emisión de póliza

### `checkout_sessions`
Canal: `workflow-assistant` (página pública de checkout). Datos del titular + domicilio desglosado + datos de vehículo (chasis/motor/uso) + tarjeta **cifrada** (`cc_*_encrypted`) con flujo de procesado/borrado (`cc_processed_at/by`, `cc_cleared_at`), `photo_paths` (json, URLs R2), `submitted_at`, `expires_at`, SoftDeletes.

### `inspection_photos`
Canal: `workflow-assistant`. 7 fotos de inspección por quote en R2. `photo_key`, `storage_path/url`, `status`, `uploaded_by_ip`, `confirmed_at`, dimensiones y `file_size`.

### `polizas`
Canal: `mango-mobile`, `workflow-assistant`. Póliza emitida (hoy seed/mock; futuro: emisión Visred). `risk_id`, `estado`, `numero`, `company`, `coverage`, `coverage_detail`, `sum_asegurada`, `cuota`, `cuota_due`, `vigencia`, `emitida_en`, `metadata` (jsonb), SoftDeletes.

### `risks`
Canal: `mango-mobile`. Bien asegurado del lado cartera mobile (distinto de `vehicles` del chat). `customer_id`, `type`, `label`, `metadata` (jsonb), SoftDeletes.

---

## 5. App MANGO — cartera, emergencias y cuenta compartida

Canal de todo este grupo: `mango-mobile`.

### `emergency_contacts`
Máx 3 por cuenta. `mobile_account_id`, `name`, `phone` (E.164).

### `emergency_tracking_tokens`
Tracking de ubicación en vivo. `token` (público, lectura), `update_secret` (escritura, solo en el device), `last_lat/lon`, `last_updated_at`, `expires_at`, `revoked_at`.

### `shared_risks` — Cuenta Compartida
`risk_id`, `shared_with_email`, `invited_by_mobile_account_id`, `accepted_by_mobile_account_id`, `name`, `token`, `expires_at`, `accepted_at`, `revoked_at`.

### `acp_procesados`
Dedup de alertas SMN (Aviso por Cambio de Pronóstico) ya empujadas por FCM. `id` (varchar), `expires_at`, `processed_at`.

---

## 6. RAG — Documentación de coberturas

Canal: `workflow-assistant`.

### `coverage_documents`
PDF/manual por compañía. `company_slug`, `company_name`, `document_type`, `original_filename`, `storage_path/disk`, `mime_type`, `extracted_content`, `extraction_status/mode/provider`, `version`, `is_active`, `deprecated_at`.

### `coverage_chunks`
Chunks vectorizados. `coverage_document_id`, `chunk_index`, `content`, `embedding` **vector(1536)** (pgvector + `HasNeighbors`), `metadata`.

---

## 7. Observabilidad de agentes y configuración

Canal: `workflow-assistant`.

### `agent_prompts`
Versionado de prompts servido en runtime (el `.md` es fallback). `agent_key`, `content`, `version`, `is_active`, `type`, `status`, `owner_id`, `parent_version_id`, `notes`.

### `agent_execution_logs` / `agent_execution_log_annotations`
Traza por turno: `agent_name`, `step`, `state_before/after/changes` (json), `chained`, `status`, `error_message`, `duration_ms`, `inbound_message_ids`, `outbound_message_id`, tokens, `tool_calls`, `agent_prompt_id`. Las anotaciones guardan `verdict` + `note` por usuario (Studio).

### `system_settings`
Config editable desde el panel admin. `key`, `group`, `value`, `type`, `label`, `description`, `is_secret`.

---

## 8. Infraestructura Laravel

Canal: `workflow-assistant`. `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `migrations`, `password_reset_tokens`, `personal_access_tokens` (Sanctum — tokens de `mango-mobile`).

---

## Mapa de relaciones (núcleo de cotización)

```
customers ──< vehicles ──< conversation_vehicle >── conversations
    │             │                                      │
    │             └──< risk_snapshots ──< quotes ──< quote_alternatives
    │                                       │  │
    │                                       │  └── quote_provider_refs (1:1 auditoría)
    │                                       └── checkout_sessions ──< inspection_photos
    └──< mobile_accounts (app MANGO)
    └──< risks ──< polizas / shared_risks
```
