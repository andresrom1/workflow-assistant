# 03 — Entrega de documentación de póliza a la app (contrato backend ↔ Flutter)

> **Estado: implementado (backend), pendiente la contraparte Flutter.** Documenta el feature
> **"mantenimiento de documentación de póliza"** tal como quedó construido (no el modelo de
> transacciones/fold de [`01`](01-modelo-mantenimiento-cartera-endosos.md), que se **descartó** —
> ver §0). Es el contrato que la app `mango-mobile` (Flutter) tiene que consumir.

---

## 0. Qué se construyó y qué NO

En una sesión de diseño v3 se acotó el alcance. El modelo de **transacciones / deltas / fold /
extractor** de los docs [`01`](01-modelo-mantenimiento-cartera-endosos.md) y
[`02`](02-extractor-documentos-poliza.md) quedó **descartado en la implementación**: la
reconstrucción de estado a fecha pasada / el historial por campo **no la consume nadie** en MANGO, y
la historia ya vive en Visred + el sistema de la compañía + la pila de PDFs ya guardada.

**El objetivo real:** cargar y mantener la **documentación (PDFs)** de la póliza y entregarla al
asegurado en la app. Modelo: **"Poliza liviana"** — se agregó solo `contrato_anterior_ref`; se usan
las columnas `vigencia`/`estado` existentes. **Sin** tabla de transacciones, **sin** fold, **sin**
extractor.

**Backend implementado (Fases 1–5, ROADMAP 2026-06-21):**
- **F1 — visibilidad "todo lo de la vigente":** la app recibe **todos** los documentos de la póliza
  **vigente**; ninguno de las vencidas. Se retiró el toggle `visible_to_client` por documento.
- **F2 — renovación + organización:** renovar abre una póliza nueva sobre el mismo Risk
  (`contrato_anterior_ref`), la anterior pasa a `vencida`. Alta de pólizas externas (CRUD admin).
- **F3 — cola de vencimientos** (admin, derivada de `vigencia`).
- **F4 — checklist de completitud** (admin: qué documentos esperados faltan).
- **F5 — push "documento nuevo"** al cargar un doc de una póliza vigente (este doc, §3).

La gestión admin de documentos (subir/eliminar + captura en emisión) ya existía (2026-06-15).

---

## 1. Contrato de API mobile (lo que Flutter consume)

Base: `/api/mobile/v1/` · guard `auth:mobile` (Sanctum sobre `MobileAccount`). La app **nunca**
habla con Visred: los PDFs ya están en R2 y se sirven con URL firmada.

### 1.1 `POST /auth/session` — login (cambió en F5)

Intercambia el Firebase ID Token por un Sanctum token. **Ahora el `user` incluye `id`** (lo necesita
la app para suscribirse a su topic FCM — §3):

```jsonc
// Request:  { "firebase_token": "<firebase id token>" }
// Response 200:
{
  "sanctum_token": "…",
  "user": { "id": 42, "name": "…", "email": "…", "avatar_url": "…" }
}
```

### 1.2 `GET /polizas` — Home (cambió en F1)

Devuelve `pas` + `polizas_propias[]` + `riesgos_compartidos[]` (sin cambios estructurales). **Cada
póliza propia ahora trae `tiene_documentos`** (bool) para decidir si ofrecer la descarga:

```jsonc
{
  "polizas_propias": [
    {
      "id": 7, "risk_id": 3, "label": "Volkswagen Amarok", "patente": "AG217OC",
      "numero": "1822203", "company": "Triunfo", "coverage": "C – Robo e Incendio…",
      "coverage_detail": "…", "sum_asegurada": "35000000.00", "cuota": "61849.00",
      "estado": "vigente",
      "tiene_documentos": true            // ← NUEVO (F1)
    }
  ],
  "riesgos_compartidos": [ … ]            // ver §1.4
}
```

> El resto de los campos de la card del Home **no cambió** (el display de pólizas ya funcionaba y no
> se tocó). `tiene_documentos` es aditivo — Flutter lo ignora si no lo lee.

### 1.3 `GET /polizas/{id}/documentos` — documentos de la póliza (cambió en F1)

Entrega **todos** los documentos si la póliza está **vigente**; **lista vacía** si está vencida (su
documentación vive ahora en la póliza que la renovó). URL R2 **firmada, expira ~15 min**.

```jsonc
// Response 200:
{
  "documentos": [
    { "kind": "circulation-card", "url": "https://…signed…", "captured_at": "2026-06-19T12:00:00+00:00" },
    { "kind": "poliza",           "url": "https://…signed…", "captured_at": "2026-06-19T12:00:00+00:00" }
  ]
}
// Vencida / sin docs: { "documentos": [] }
// 403 POLIZA_FORBIDDEN si la póliza no es del usuario ni compartida · 404 POLIZA_NOT_FOUND
```

**`kind` (enum, [`PolicyDocumentKind`](../../app/Enums/PolicyDocumentKind.php)):** `poliza`
(Póliza), `certificado` (Certificado de cobertura), `endoso` (Endoso), `cupon` (Cupón de pago),
`circulation-card` (Cédula de circulación), `otro` (Otro). Orden: por `kind`. Como las URLs expiran,
**pedir este endpoint al momento de abrir/descargar**, no cachear la URL.

### 1.4 Riesgos compartidos (nota)

`riesgos_compartidos[]` trae `id` = id de póliza (puede ser null). El endpoint `documentos` ya
autoriza por riesgo compartido (mismo `canAccessRisk`), así que **un vehículo compartido también
puede descargar**. Pero `tiene_documentos` **solo** está hoy en `polizas_propias`; para los
compartidos la app debe pedir `documentos` y mostrar la descarga si la lista no viene vacía
(o se agrega el flag al payload compartido como follow-up).

---

## 2. Estado actual de la app (lo que ya existe)

- El Home (`features/home/`) ya consume `GET /polizas` y renderiza `PolicyCard` con la acción
  "Compartir vehículo". El modelo `Poliza.fromJson` exige los campos de display **no-null** — no se
  rompe nada al agregar `tiene_documentos` (campos extra se ignoran).
- La pantalla **"Documentación"** (`features/documents/`) es **otra cosa**: son **slots offline**
  (archivos locales del cliente, 4 por vehículo). **No** son los PDFs de la póliza. No confundir.
- Infra de push: hoy solo el topic **global** `acp-ar` (alertas de clima, data-only silencioso) —
  `features/weather/` (`weather_messaging`, `acp_local_notifier`). **No** hay token por dispositivo
  ni suscripción por cuenta todavía.

---

## 3. Contrato del push FCM "documento nuevo" (F5)

Al cargar el admin un documento de una póliza **vigente**, el backend publica un push **data-only**
al **topic por cuenta** `account-{user.id}` (el `id` de `/auth/session`):

```jsonc
// FCM data message (todos los values son string):
{
  "type": "policy_document",
  "poliza_id": "7",
  "kind": "endoso"
}
```

- **La app debe suscribirse** a `account-{user.id}` al autenticarse (`FirebaseMessaging.subscribeToTopic`),
  y **desuscribirse** al cerrar sesión.
- **Es data-only:** no trae el documento. La app, al recibirlo, dispara una notificación local
  ("Tenés un documento nuevo en tu póliza") y/o refresca el Home; la descarga real va por
  `GET /polizas/{poliza_id}/documentos` (§1.3, autenticado).
- **Solo se publica** si la póliza está vigente y el titular tiene `MobileAccount` (match por email).

> **Nota de seguridad (a tener presente):** el topic `account-{id}` es **adivinable** (los topics FCM
> no autentican la suscripción). El riesgo es **bajo y acotado**: el push es data-only y solo filtra
> "la póliza X recibió un documento de tipo Y" — **no** el documento, que sigue detrás de la API
> autenticada con control de acceso. Si en el futuro el push llevara contenido sensible, **endurecer**
> usando un **token opaco por cuenta** (columna aleatoria en `MobileAccount`, expuesta en
> `/auth/session` y usada como nombre de topic) en vez del `id`.

Implementación backend: [`PolicyDocumentNotifier`](../../app/Services/PolicyDocumentNotifier.php) →
[`PublishDocumentAvailable`](../../app/Jobs/PublishDocumentAvailable.php) →
[`DocumentAvailablePublisher`](../../app/Services/DocumentAvailablePublisher.php) (espeja
[`FcmTopicPublisher`](../../app/Services/Smn/FcmTopicPublisher.php)). Best-effort: un fallo de FCM no
rompe la carga.

---

## 4. Qué falta construir en Flutter (resumen)

1. **Descarga de documentación en el Home:** acción "Descargar documentación" en `PolicyCard`
   (condicional a `tiene_documentos`) → llama a `GET /polizas/{id}/documentos` → lista los PDFs por
   `kind` → abre/descarga (URL firmada, pedir al momento). No confundir con la pantalla
   "Documentación" (slots offline).
2. **Push de documento nuevo:** suscribir/desuscribir el topic `account-{user.id}` en login/logout;
   manejar el mensaje `type=policy_document` → notificación local + refresco del Home (reusar el
   patrón de `weather_messaging`/`acp_local_notifier`).

El backend para ambos ya está listo (§1, §3).
