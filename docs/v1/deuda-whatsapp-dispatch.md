# Deuda — Dispatch de WhatsApp desde workflow-assistant

> **Estado (2026-06-05):** ✅ **Código completo (V2-7) + envío real verificado end-to-end.**
> Los 3 puntos de dispatch están cableados a un puerto `WhatsAppDispatcher` (seam por config)
> + Job async + `sendTemplate()`. Con `WHATSAPP_DISPATCH_DRIVER=cloud` se confirmó el flujo
> completo en vivo (template `mango_emergencia_estoy_bien` → Meta → webhooks `sent`/`delivered`).
> Falta solo dejarlo encendido en el deploy de prod (la env var vive en `.env`, no se commitea).
> El detalle de abajo queda como referencia histórica.
>
> **Racional del seam (por qué aplica solo a templates y no a las respuestas conversacionales):**
> ver `docs/v2/06-adapters.md` → "Por qué el seam vive acá y NO en `WhatsAppOutboundService`".

Este documento es el contrato de lo que falta para que MANGO avise por WhatsApp
en los tres momentos donde el producto lo promete. Cuando se integre la API, este
doc es el checklist.

---

## Por qué existe

Toda la capa de avisos al PAS y a los contactos de emergencia se construyó en la
fase mock devolviendo el destinatario resuelto (para que la app sepa a quién se
avisó) pero **sin el envío real**. El envío depende de un canal de WhatsApp
Business aprobado (número + templates aprobados por Meta), que no está cableado
en `workflow-assistant`.

Skills del repo relevantes para implementarlo: `whatsapp-laravel`,
`whatsapp-webhooks-bsuid`.

---

## Los 3 puntos de dispatch (todos hoy son TODO)

### 1. Siniestro → PAS

- **Archivo:** `app/Http/Controllers/Mobile/SiniestroController.php` (método `notify`, TODO ~línea 44).
- **Destinatario:** el PAS resuelto por prelación (tu PAS → PAS del titular del
  vehículo compartido de mayor `sum_asegurada` → PAS default `config('mango.default_pas_email')`).
- **Disparo:** el cliente confirma con el slider en la app (Fase 6). Hay lock de
  48hs del lado cliente + `throttle:5,1` en la ruta.
- **Contenido a enviar (al PAS):** que su cliente reportó un siniestro + nombre/
  contacto del cliente para que el PAS lo llame. **No** incluye ubicación.
- **Hoy devuelve:** `{ pas: { name, phone }, notified_at }` — la app muestra a
  quién se avisó, pero el PAS no recibe nada.

### 2. Necesito Ayuda — Estado 1 ("estoy bien") → contactos

- **Archivo:** `app/Http/Controllers/Mobile/EmergencyController.php` (método `notify`, `estado === 1`, TODO línea 42).
- **Destinatarios:** los `EmergencyContact` de la cuenta (máx 3; `name` + `phone` E.164).
- **Contenido:** aviso de que la persona está bien + **ubicación estática** (lat/lon
  recibidos, p. ej. un link de mapa). No crea tracking.
- **Hoy devuelve:** `{ ok: true, estado: 1 }`.

### 3. Necesito Ayuda — Estado 2 ("necesito que vengas") → contactos

- **Archivo:** `app/Http/Controllers/Mobile/EmergencyController.php` (método `notify`, Estado 2, TODO línea 52).
- **Destinatarios:** los mismos `EmergencyContact` (máx 3).
- **Contenido:** aviso de emergencia + el **`tracking_url`** público (mapa en
  tiempo real, `GET /track/{token}`). **Nunca** el `update_secret` (es la llave de
  escritura, solo vive en el device — decisión de seguridad C).
- **Hoy devuelve:** `{ ok, estado: 2, token, tracking_url, update_secret, expires_at }`
  y la app comparte el `tracking_url` por el Share nativo como workaround manual.

---

## Cómo implementarlo cuando llegue la API

- **Servicio inyectable** (`WhatsAppDispatcher` o similar), no un `if (mock)` en el
  controller. El controller llama al servicio; en mock/test el servicio es un
  no-op o un fake. Esto se alinea con el seam-único del des-mockeo (ROADMAP Fase 10).
- **Async vía queue**: el envío no debe bloquear la respuesta del endpoint
  (especialmente en una emergencia). Despachar un Job (`SendWhatsAppNotification`)
  y responder al device de inmediato. El resultado del envío no condiciona el 200.
- **Templates aprobados por Meta**: los 3 mensajes son notificaciones iniciadas por
  el negocio fuera de la ventana de 24h → requieren *message templates* aprobados
  (uno por caso). Parametrizar nombre/link/ubicación.
- **Privacidad (no negociable):**
  - El GPS de Estado 1/2 se comparte **solo** con los contactos elegidos, nunca se
    persiste server-side más allá del `last_lat/lon` del tracking activo.
  - El `update_secret` jamás se envía a un contacto ni viaja en el `tracking_url`.
- **Idempotencia / anti-doble-envío**: respetar los `throttle` ya puestos
  (`siniestro 5,1`, `emergencia 3,1`) + el lock de 48hs del cliente en Siniestro.
  Considerar dedup por evento para no spamear al PAS/contactos en reintentos.
- **Observabilidad**: logear envíos (a quién, qué template, resultado) sin volcar
  PII sensible ni el GPS en logs.

---

## Definición de "deuda saldada"

- [ ] Canal de WhatsApp Business aprobado + credenciales en `.env`. _(MANGO — externo)_
- [x] `WhatsAppDispatcher` + Job async (`SendWhatsAppTemplate`). Templates: **referenciados por config**, falta aprobarlos en Meta _(MANGO)_.
- [x] Los 3 TODO reemplazados por la llamada al dispatcher.
- [x] Tests: el dispatcher se invoca con el destinatario/contenido correcto (spy del puerto, sin pegar a Meta) en los 3 casos — `tests/Feature/Mobile/EmergencyDispatchTest.php`.
- [x] El `tracking_url` llega al contacto; el `update_secret` nunca (test explícito).

### Cómo encenderlo en producción (cuando MANGO apruebe los templates)

1. Crear y aprobar en Meta los 3 templates (`es_AR`) con sus variables `{{1}}`/`{{2}}` (ver `config/whatsapp.php`).
2. Setear en `.env`: `WHATSAPP_DISPATCH_DRIVER=cloud` + `WHATSAPP_TEMPLATE_EMERGENCIA_BIEN`, `WHATSAPP_TEMPLATE_EMERGENCIA_AYUDA`, `WHATSAPP_TEMPLATE_SINIESTRO_PAS` con los nombres reales.
3. Verificar `WHATSAPP_ACCESS_TOKEN` + `WHATSAPP_PHONE_NUMBER_ID` (ya usados por el resto del canal).
