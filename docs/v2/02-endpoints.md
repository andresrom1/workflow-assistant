# Endpoints — workflow-assistant (v2)

> Cada endpoint indica **a quién sirve** (canal consumidor).
> Canales: `openai_chatkit`, `pas_mobile` (legacy, extirpado), `pas-web` (legacy, extirpado), `mango-mobile`, `workflow-assistant`.
>
> `pas_mobile` y `pas-web` fueron **extirpados** del proyecto: sus endpoints quedan marcados **legacy** (a reemplazar por el flujo Visred, que cotiza y emite sin intervención humana).

---

## API de herramientas del chat (`routes/api.php`)

### Web chat tools — prefijo `/api/web-chat/v1/tools` → **`openai_chatkit`**
La UI de ChatKit (Next.js) invoca estas tools HTTP; el controller delega al `AgentToolAdapter`.

| Método | Ruta | Acción |
|---|---|---|
| POST | `/identify-customer` | `ToolsController@identifyCustomer` |
| POST | `/identify-vehicle` | `ToolsController@identifyVehicle` |
| POST | `/coverage-preference` | `ToolsController@coveragePreference` |
| POST | `/get-quote` | `ToolsController@getQuote` |
| POST | `/checkout` | `ToolsController@checkout` |

### Cotización pública / auditoría
| Método | Ruta | Acción | Canal |
|---|---|---|---|
| POST | `/api/v1/quotes/` | `QuoteController@store` | `openai_chatkit` |
| GET | `/api/quotes/{quote}/raw` | `QuoteController@showRaw` | `workflow-assistant` (debug) |

### Tools sin implementar (andamiaje) → **`openai_chatkit`**
`save-vehicle-data`, `create-pending-quote`, `show-data-form`, `show-vehicle-photos-form`, `show-payment-form`, `finalize-policy` (`ToolsController`). Stubs del flujo de emisión asistida — a converger con Visred.

### Webhooks
| Método | Ruta | Acción | Canal |
|---|---|---|---|
| POST | `/api/webhooks/quote-update` | `QuoteWebhookController@handle` | **`pas_mobile` (legacy, extirpado)** — ruta y controller removidos en V2-6. La app PAS devolvía cotizaciones manuales; lo reemplaza la resolución síncrona Visred. |
| GET | `/api/webhooks/whatsapp` | `WhatsAppWebhookController@verify` | `workflow-assistant` (verificación Meta) |
| POST | `/api/webhooks/whatsapp` | `WhatsAppWebhookController@handleIncoming` | `workflow-assistant` (entrada WhatsApp Cloud API) |

### Dev / testing → **`workflow-assistant`**
`/api/dev/run-tests`, `/database-status`, `/clean-database`, `/system-info`, `/fresh-migrations` (`TestingController`). Solo entorno de desarrollo.

`POST /api/tools/test` y `GET /api/user` (Sanctum) — utilitarios.

---

## API Mobile — prefijo `/api/mobile/v1` (`routes/mobile.php`) → **`mango-mobile`**

Guard `auth:mobile` (Sanctum sobre `MobileAccount`), salvo donde se indica.

### Auth
| Método | Ruta | Acción | Auth |
|---|---|---|---|
| POST | `/auth/session` | `AuthController@session` | pública (intercambia Firebase ID Token → Sanctum) |
| POST | `/auth/logout` | `AuthController@logout` | `auth:mobile` |

### Pólizas / cartera
| Método | Ruta | Acción |
|---|---|---|
| GET | `/polizas` | `PolizasController@index` (PAS + propias + compartidas) |
| GET | `/polizas/{id}` | `PolizasController@show` |

### Siniestro
| POST | `/siniestro` | `SiniestroController@notify` (`throttle:5,1`) — aviso al PAS. |

### Contactos de emergencia (máx 3)
| GET/POST | `/contactos-emergencia` | `EmergencyContactsController@index`/`store` |
| PUT/DELETE | `/contactos-emergencia/{id}` | `@update`/`@destroy` |

### Necesito Ayuda (emergencia + tracking)
| Método | Ruta | Acción | Nota |
|---|---|---|---|
| POST | `/emergencia/notificar` | `EmergencyController@notify` | Estado 1/2 (`throttle:3,1`) |
| PATCH | `/emergencia/tracking/{token}/posicion` | `EmergencyController@updatePosition` | **sin `auth:mobile`** — autoriza el `update_secret`; `throttle:30,1` |
| DELETE | `/emergencia/tracking/{token}` | `EmergencyController@revokeTracking` | |

### Cuenta Compartida (shared_risk)
| GET | `/shared-risks/{polizaId}` | `SharedRisksController@index` |
| POST | `/shared-risks/invitar` | `SharedRisksController@invite` |
| DELETE | `/shared-risks/{polizaId}/{conductorId}` | `@revoke` (titular) |
| DELETE | `/shared-risks/mias/{riskId}` | `@revokeMine` (invitado se auto-quita) |

---

## Web (`routes/web.php`)

### Checkout público (token opaco) — **`workflow-assistant`** (página servida al cliente final)
El link se genera en el flujo de chat (`openai_chatkit` / WhatsApp) y se envía al cliente.

| Método | Ruta | Acción |
|---|---|---|
| GET | `/checkout/{token}` | `CheckoutController@show` (sin auth, el token es la credencial) |
| POST | `/checkout/upload-photo` | `CheckoutController@uploadPhoto` |
| DELETE | `/checkout/photo` | `CheckoutController@deletePhoto` |
| POST | `/checkout/submit` | `CheckoutController@submit` |
| GET | `/checkout/{quote}/success` | `CheckoutController@success` |

### Tracking público de emergencia — **`mango-mobile`** (lo abren los contactos)
| GET | `/track/{token}` | `TrackingController@show` (sin auth; el token autoriza) |

### Panel admin/operador (`auth`) → **`workflow-assistant`**
- Perfil: `/profile` (`ProfileController`).
- Chat interno de pruebas: `/chat` (`ChatController`).
- Conversaciones (registros de cliente): `/conversations`, `/conversations/{customer}` (`ConversationController`).
- Cotizaciones: `resource quotes` (index/show, `QuoteController`).
- Documentos de cobertura (RAG): `resource coverage-documents` (`CoverageDocumentController`).

### Solo Admin — prefijo `/admin` (`auth`+`admin`) → **`workflow-assistant`**
- Auditoría de checkout: `CheckoutAuditController` (index/show/mark-processed/clear-card-data).
- Settings: `SettingsController` (index/update-group).
- Conversaciones: `ConversationController` (index/show/reset/analyze-semantics).
- Logs de ejecución: `AgentExecutionLogAnnotationController` (store/destroy).
- Prompts de agentes + draft flow: `AgentPromptController` (index/view/show/store/activate + drafts create/update/promote/take-control/discard).
- Analytics funnel: `AnalyticsController@funnel`.
- Studio (reevaluación de turn): `StudioController` (show/reevaluate).
- Usuarios: `UserController` (create/store/reset-password/destroy).

### Auth scaffolding (`routes/auth.php`) → **`workflow-assistant`**
Login, forgot/reset password, verificación de email, logout (`Auth/*Controller`).

---

## Resumen por canal

| Canal | Endpoints |
|---|---|
| `openai_chatkit` | `web-chat/v1/tools/*`, `POST /v1/quotes`, tools stub |
| `mango-mobile` | todo `/api/mobile/v1/*`, `GET /track/{token}` |
| `workflow-assistant` | checkout web, panel admin, auth, webhooks WhatsApp, dev |
| `pas_mobile` (legacy, extirpado) | webhook `quote-update` removido (V2-6); reemplazado por Visred |
| `pas-web` (legacy, extirpado) | nunca tuvo endpoints propios vivos en este backend |
