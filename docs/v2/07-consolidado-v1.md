# Consolidado de la documentación v1

> Reúne en un solo lugar los documentos de `docs/v1/`. Refleja el diseño **anterior a v2**
> (cotización con prioridad PAS vía app móvil + fallback API). Varias piezas marcadas aquí
> como **PAS/app móvil** corresponden a `pas_mobile`/`pas-web`, hoy **fuera del proyecto** y
> en vías de ser reemplazadas por el flujo **Visred** (cotiza y emite sin intervención humana).
>
> Documentos originales consolidados:
> `database_erd.md`, `quote-resolution-architecture.md`, `quote-resolution-walkthrough.md`,
> `quote-timeout-architecture.md`, `mobile-onboarding-ux.md`, `deuda-whatsapp-dispatch.md`,
> `adr/001-quote-provider-refs.md`. Para el schema vivo y actual ver `docs/v2/01-database-schema.md`.

---

## 1. Modelo de datos (ERD v1)

Núcleo: `CUSTOMERS` posee `VEHICLES` y participa en `CONVERSATIONS`; ambos se vinculan por la pivote `CONVERSATION_VEHICLE`. Una `CONVERSATION` tiene `QUOTES`, basadas en un `RISK_SNAPSHOT` (foto inmutable de vehículo+cliente+cobertura). Cada `QUOTE` contiene `QUOTE_ALTERNATIVES`. Las `COVERAGE_PREFERENCES` se asocian a la combinación conversación+vehículo.

Cambios clave de v1:
- Se eliminó `is_primary` de la pivote: el contexto se resuelve por la **patente** enviada en las tools.
- Se agregó la entidad `COVERAGE_PREFERENCES`.
- Se agregó `coverage_preference` al `RISK_SNAPSHOT`.

> Estado v2: el schema creció bastante (WhatsApp media, RAG/pgvector, cartera mobile, emergencias, observabilidad de agentes, checkout). Ver `01-database-schema.md`.

---

## 2. Arquitectura de resolución de cotizaciones

### Componentes
- **Orquestador (`AgentToolAdapter`)** — punto de entrada del chat. Detecta eventos y dispara acciones:
  - `identify_vehicle` → crea la quote `pending` (arranca el timer de fallback cuanto antes).
  - `coverage_preference` → actualiza el snapshot y pide la resolución activa (prioridad mobile).
- **Cerebro (`QuoteService`)** — ciclo de vida y reglas globales: `createPendingQuote` (snapshot + programa `CheckQuoteAcceptance`), `resolveQuote` (elige estrategia), `updateSnapshotPreference`.
- **Ejecutores (`Strategies`)**:
  - `MobileAppQuoteResolution` → enviaba la oportunidad a la **app PAS** (`offered_pas`), resolución **diferida** por webhook. **(legacy `pas_mobile`)**.
  - `ApiQuoteResolution` → consulta motores externos, persiste resultados, resolución **síncrona**.

### Estados de la `Quote`
`pending` → `offered_pas` → (`rejected_pas` por timeout) → `processed` | `failed`.

### Flujo (resumen)
1. `identify_vehicle`: crea quote `pending` + agenda `CheckQuoteAcceptance` (delay 30m).
2. `coverage_preference`: actualiza snapshot + `resolveQuote(mobile)` → envía lead completo al PAS → `offered_pas`.
3a. **PAS responde** vía webhook `POST /api/webhooks/quote-update` → persiste alternativas → `processed`.
3b. **Timeout (30m)**: `CheckQuoteAcceptance` → `rejected_pas` → fallback `ApiQuoteResolution` → `processed`.

---

## 3. Gestión de tiempos (el Core como fuente de verdad)

Principios: garantía de SLA (el cliente siempre recibe cotización aunque la app PAS falle), propiedad del estado (la `Quote` vive en este backend, él transiciona sus estados), y mitigación del abandono (el timer arranca al identificar el vehículo, no al llegar al PAS).

Configuración única en `.env`: `MOBILE_APP_RESOLUTION_TIMEOUT=30` → mapeada en `config/services.php`, consumida por el timer y por el payload enviado a la app (`expires_at`).

> **Evolución v2:** la fuente de verdad del timeout sigue siendo el Core, pero el destino de la resolución deja de ser la app PAS y pasa a **Visred** (cotización y emisión automáticas). Ver `docs/v2/08-visred-quote-adapter.md` para el patrón Adapter contra Visred (puerto `QuotationPort`, `VisredClient`, flip por `MANGO_QUOTATION_PROVIDER`).

---

## 4. ADR-001 — `quote_provider_refs` (datos de proveedor aislados)

**Problema:** el agente de IA confundía `external_quote_id` (ID de lote del proveedor) con el `Quote.id` interno y lo usaba mal en `checkout()`.

**Decisión:** extraer **todos** los datos de proveedor a la tabla `quote_provider_refs` (1 fila por quote: `external_quote_id` + `raw_response` completo). Los modelos de dominio (`Quote`, `QuoteAlternative`) quedan limpios.

**Invariantes:**
- Los modelos de dominio nunca contienen IDs de proveedor; el agente solo ve IDs internos.
- `quote_provider_refs` es append-only (se recrea en reintentos); único origen de `external_quote_id` para emisión.
- El Adapter es la única puerta de salida hacia el agente (mapeo explícito en `getQuote()`).

`external_code` (por alternativa) vive dentro de `raw_response.alternatives`; se recupera matcheando por campos de dominio.

---

## 5. Onboarding mobile — el email del checkout como llave

Decisión cerrada (Fase 1 app MANGO, mayo 2026). El email informado en el checkout tiene **tres roles**: canal de comunicación, **llave de acceso a la app** (match OAuth por email+DNI contra `customers`), y canal de **invitaciones a Cuenta Compartida**.

Implicancias para el chat/checkout:
- Comunicar el doble rol del email; preferir el email del celular (Google/Apple) sobre el laboral.
- **Apple "Ocultar mi correo"**: los relay-emails (`@privaterelay.appleid.com`) no sirven para vincular; la app los rechaza.
- **Sin self-service** para cambiar email hoy → intervención manual del PAS en el panel.
- Pedir email **antes** del DNI; dar eco visible; validar solo formato.
- Restricción: una identidad MANGO por email (familias que comparten email → el resto entra como conductor adicional vía Cuenta Compartida).

---

## 6. Deuda — dispatch de WhatsApp (avisos a PAS y contactos)

> Estado v2 (verificado contra código, 2026-06): la integración WhatsApp **ya existe para el flujo conversacional/cotización** (webhooks de entrada + `WhatsAppOutboundService` + `SendWhatsAppMessage` + media). **Pero los 3 avisos de siniestro/emergencia siguen sin cablear a esa infraestructura** — los TODO siguen abiertos:
> - `SiniestroController@notify` — `app/Http/Controllers/Mobile/SiniestroController.php:47` ("cuando lleguen las APIs reales, dispatchar WhatsApp acá").
> - `EmergencyController@notify` Estado 1 — `app/Http/Controllers/Mobile/EmergencyController.php:42`.
> - `EmergencyController@notify` Estado 2 — `app/Http/Controllers/Mobile/EmergencyController.php:52`.
>
> O sea: la deuda dejó de estar bloqueada por infraestructura (ya hay un servicio outbound reutilizable); lo que falta es **conectar los 3 controllers al dispatcher async + templates aprobados por Meta**.

Los 3 puntos de dispatch (originalmente TODO, devolvían destinatario sin enviar):
1. **Siniestro → PAS** (`SiniestroController@notify`): avisa al PAS resuelto por prelación; sin ubicación.
2. **Necesito Ayuda Estado 1 ("estoy bien") → contactos** (`EmergencyController@notify`): aviso + ubicación estática.
3. **Necesito Ayuda Estado 2 ("vengan") → contactos**: aviso + `tracking_url` público. **Nunca** el `update_secret`.

Requisitos de implementación: servicio inyectable (no `if(mock)`), envío **async** por queue (no bloquear la respuesta en una emergencia), **templates aprobados por Meta** (mensajes fuera de la ventana de 24h), privacidad no negociable (GPS solo a contactos elegidos; `update_secret` jamás sale del device), idempotencia (respetar throttles + lock de 48hs) y observabilidad sin volcar PII.

---

## Qué cambió de v1 a v2 (mapa rápido)

| Tema | v1 | v2 |
|---|---|---|
| Resolución de quote | Prioridad app PAS (`pas_mobile`) + fallback API | **Visred** (cotiza + emite, sin humano) detrás de `QuotationPort` |
| Apps cliente | `pas_mobile`, `pas-web` | **`mango-mobile`** (cartera, emergencias, cuenta compartida) |
| Webhook `quote-update` | Entrada de cotizaciones manuales del PAS | **Legacy** — reemplazado por resolución síncrona Visred |
| WhatsApp dispatch | Deuda (sin API) | Integración WhatsApp Cloud API presente; re-verificar los 3 avisos |
| RAG coberturas | — | pgvector + agente experto (`SearchCompanyDocumentationTool`) |
