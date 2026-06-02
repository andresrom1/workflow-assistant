# Quote flow — Adapter pattern para enchufar workflow-assistant a Visred

**Ámbito:** solo el flujo de **cotización** (`quote`). Emisión y descarga de documento quedan fuera de este documento (se referencian como pasos siguientes, no se especifican acá).
**Estado de la fuente:** el lado **Visred está verificado contra el schema publicado** (`/v1/schema/`, OpenAPI 3.0.3, 52 endpoints). El lado **workflow-assistant está como andamiaje** — todo lo marcado `⚠️ PENDIENTE` debe confirmarse contra el código real antes de implementar; no está inventado, está vacío a propósito.
**Repos involucrados:** `workflow-assistant` (backend). `mango-mobile` no participa: el celular nunca habla con Visred (ver doc de identidad/arquitectura).

---

## 0. Objetivo

Insertar un **Adapter** en el punto donde hoy el backend resuelve una cotización con datos mock, de modo que la misma lógica de negocio pase a cotizar contra Visred **sin reescribir el dominio**. El mock no se borra: se vuelve una implementación más detrás del mismo puerto, y se elige por config (mismo criterio que el seam `resolveCustomer()` de Fase 10).

Principio rector: **el dominio de MANGO no debe saber que atrás hay Visred.** Habla con un puerto en términos propios; el Adapter traduce.

---

## 1. Estado actual (mock) — ⚠️ PENDIENTE de verificar contra código

> Esta sección es andamiaje. Lo de abajo es lo que se infiere del `ROADMAP.md` y de la conversación, **no** lectura del código. Completar/corregir contra `workflow-assistant` antes de tomarlo como verdad.

**Lo que se sabe (del ROADMAP, no verificado en código):**

- Existe un flujo de cotización en el **chat** de workflow-assistant (no en la app mobile). El ROADMAP menciona `App\Models\Vehicle` como "chat de cotización, intocado en su dominio", distinto de `Risk` (el bien asegurado del lado mobile).
- Hay un refactor pendiente "Customer → Lead" (~50 referencias en el chat de cotización) — señal de que la cotización vive acoplada al modelo `Customer`/Lead.
- El mock de pólizas (`/polizas`) se sirve de seeders (`MobileRiskSeeder`), no de cotización real. Eso es **cartera**, no **quote** — no es este flujo.

**Lo que hay que documentar leyendo el código (huecos a llenar):**

```
⚠️ PENDIENTE — completar con el código real:
1. ¿Cómo se llama hoy el servicio/clase que resuelve la cotización?
   (el pedido lo nombra "QuotationService" — confirmar nombre y namespace)
2. ¿Cuál es su firma pública? (método de entrada, tipos de entrada/salida)
3. ¿Qué modelo de dominio representa una cotización y un resultado de
   cotización hoy? (campos, tipos)
4. ¿En qué punto del flujo del chat se invoca el quote? (controller/job/
   action que llama al servicio)
5. ¿El mock hoy devuelve sincrónico (resultado directo) o ya simula algo async?
6. ¿Hay ya alguna abstracción/interfaz, o el mock está llamado directo
   (hardcode) desde el punto de invocación?
```

> El punto 6 es el que decide el tamaño del refactor: si hoy se llama una clase concreta directo, el primer paso del Adapter es **extraer el puerto** (interface) de esa firma actual y hacer que el mock la implemente, sin cambiar comportamiento. Recién ahí se suma el Adapter de Visred.

---

## 2. El contrato Visred (lado firme — verificado)

### 2.1 Forma del flujo de quote

Para **Vehículos** (auto/moto), la cotización es **asíncrona**:

```
POST /v1/patrimoniales/vehicles/cotizar/   (body: QuotationVehicleRequest)
      │
      ▼  200 → TaskList { tasks_list: [ TaskItem{ task_id, company_id, product_id, ... }, ... ] }
      │        (una task por compañía cotizada)
      │
      ▼  por cada task_id:  GET /v1/tasks/{task_id}/   (polling)
                                  │
                                  ▼ TaskDTO { status, ready, result }
                                     status: PENDING → (reintentar) | SUCCESS | FAILURE
                                     result (cuando SUCCESS): APIBaseQuotationResultDTO
```

> **Nota de generalización:** no todos los ramos son async. **Caución es síncrono** (`cotizar/` → `201` con `quotation_results` directos, sin polling); Vehículos/Hogar/AP son async (`TaskList` → polling). El puerto debe abstraer esto: el dominio pide "cotizá" y recibe resultados; si atrás hubo polling o no, es problema del Adapter.

### 2.2 Request — `QuotationVehicleRequest` (lo mínimo para autos)

```jsonc
{
  "product_id": "auto",            // "auto" | "moto"
  "address": { "zip_code": 5000 }, // QuotationAddressRequest (zip 1000–9999)
  "person_holder": {               // nullable en cotización
    "document_number": "36356190"
  },
  "vehicle": {
    "version_id": "AALCVEeg",      // token opaco de /params/.../versions/
    "year": 2017,
    "zero_kilometers": false,
    "fuel_type_id": "sin-gnc",     // opcional; relevantes: "sin-gnc" | "gnc"
    "insured_amount_fuel": 500000  // requerido solo si fuel_type_id="gnc"
  }
}
```

Para construir ese `version_id` hay una cadena de params (todos GET, todos auth):
`/params/{vehicle_type}/brands/` → `/params/{vehicle_type}/{brand_id}/years/` → `/params/{vehicle_type}/{brand_id}/{year_id}/groups/` (solo auto) → `/params/{vehicle_type}/{brand_id}/{year_id}/versions/?group_id=...`.

### 2.3 Resultado — `APIBaseQuotationResultDTO` (shape canónico de cada cobertura cotizada)

```jsonc
{
  "quotation_result_id": 7386,     // ← se referencia luego al EMITIR
  "cover": {                       // APICoverDTO
    "id": "todo-riesgo-c",
    "name": "Todo Riesgo C",
    "description": "...",
    "static_fee": null,
    "active": true
  },
  "validity_id": null,
  "fee": 78450.0,                  // PRIMA (number/double, nullable)
  "installments": 12,
  "franchise": 0.0,
  "insured_amount": 14200000,
  "payment_method_id": "cbu",
  "features": [ { "id": "...", "name": "...", "description": "..." } ],
  "require_inspection_before_emission": false
}
```

### 2.4 Auth

- Login: `POST /v1/accounts/token/` con `{username, password}` → `{access, refresh}` (JWT, HS256, access ~1h).
- Refresh: `POST /v1/accounts/token/refresh/` con `{refresh}` → `{access}`.
- En cada request: header `Authorization: Bearer <access>`.
- **Identidad de servicio, no de usuario final:** workflow-assistant tiene **un** set de credenciales Visred (las del productor MANGO). El JWT es del productor, no del cliente. ⚠️ PENDIENTE: confirmar con Visred si dan usuario de servicio o de prueba — `discovery/companies` es *origin-aware* (filtra por permisos del productor), así que el catálogo depende de con qué usuario te autentiques.

### 2.5 Envelope de error Visred (a traducir)

```jsonc
{ "success": false, "error": { "message": "...", "code": "validation_error", "field_errors": { "campo": ["..."] } } }
```

Códigos: `validation_error` (400), `not_authenticated` (401), `permission_denied` (403), `not_found` (404), `conflict` (409), `external_service_unavailable` (503). **No coincide** con el envelope de MANGO (`{message, code, errors}`, códigos `SCREAMING_SNAKE`). El Adapter (o una capa anticorrupción) traduce Visred → MANGO.

### 2.6 Gotchas verificados

- **Singular/plural sistemático:** las descripciones del schema apuntan a rutas plurales (`/params/task-types/`, `/params/document-types/`, etc.) que **no existen**. Las rutas reales son **singulares** (`/params/task-type/`, `/params/document-type/`, …). Cablear contra los `paths` reales, no contra la prosa.
- **`X-Mock-Scenario`** (header, solo sandbox): `success` | `error_400` | `error_500`. En producción, enviarlo → 403. El Adapter NO debe mandarlo en prod.
- **Pendientes de sandbox (bloqueados por credenciales):** shape 200 de `sales/*` y `policy/stats` (igual ya sabemos que son reporting agregado, no cartera). No afectan el flujo de quote.

---

## 3. El patrón Adapter

### 3.1 Estructura

```
        Dominio MANGO (chat de cotización)
                  │  habla en tipos propios
                  ▼
        ┌───────────────────────────┐
        │  QuotationPort (interface) │   ← contrato estable del dominio
        └───────────────────────────┘
              ▲                 ▲
   implementa │                 │ implementa
   ┌──────────────────┐   ┌─────────────────────────┐
   │ MockQuotation     │   │ VisredQuotationAdapter   │
   │ Adapter           │   │  - traduce dominio→Visred│
   │ (lo de hoy)       │   │  - cotizar + polling     │
   └──────────────────┘   │  - traduce Visred→dominio│
                          │  - traduce errores       │
                          └────────────┬─────────────┘
                                       │ usa
                          ┌────────────────────────────┐
                          │ VisredClient (HTTP + token) │
                          │  - login/refresh JWT         │
                          │  - Bearer en cada request    │
                          │  - mapea envelope de error   │
                          └────────────────────────────┘
```

El **seam** es el puerto. El punto de invocación del flujo (⚠️ el del punto 1.4) pasa a depender de `QuotationPort`, no de una clase concreta. Cuál implementación se inyecta lo decide config:

```php
// config/mango.php  (propuesto — mismo patrón que mock_customer_matching)
'quotation_provider' => env('MANGO_QUOTATION_PROVIDER', 'mock'), // 'mock' | 'visred'
```

Flip a real = cambiar la env, cero código en el dominio.

### 3.2 El puerto — PROPUESTO (no confirmado contra código)

> Esto es una **propuesta** de firma. Tiene que reconciliarse con la firma actual del servicio (punto 1.2): idealmente el puerto *es* la firma actual extraída a interface, para que el mock siga andando sin cambios.

```php
interface QuotationPort
{
    /**
     * @param  QuotationRequest $req  tipo de DOMINIO (no DTO de Visred)
     * @return QuotationResult[]      una por cobertura/compañía
     * @throws QuotationUnavailable   (traducido desde 503/timeout)
     * @throws QuotationРequestInvalid (traducido desde 400 validation_error)
     */
    public function quote(QuotationRequest $req): array;
}
```

`QuotationRequest` y `QuotationResult` son **tipos del dominio de MANGO** (los que ya existan — punto 1.3). El Adapter de Visred mapea entre esos y los DTOs de §2. El dominio no importa ninguna clase de Visred.

### 3.3 El Adapter de Visred — responsabilidades

1. **Traducir entrada:** `QuotationRequest` (dominio) → `QuotationVehicleRequest` (Visred). Acá vive la resolución del `version_id` (la cadena de params §2.2) — o se asume ya resuelto upstream; ⚠️ depende de cómo el dominio represente el vehículo hoy.
2. **Disparar cotización:** `POST .../cotizar/` → `TaskList`.
3. **Polling:** por cada `task_id`, `GET /tasks/{id}/` hasta `ready=true`. Política de polling (intervalo, timeout, max intentos) y qué hacer con `FAILURE` parcial (una compañía falla, otras OK) → decisión de diseño. **Recomendado:** resultado parcial tolerante (devolver las que resolvieron, registrar las que fallaron), no abortar todo por una compañía caída.
4. **Traducir salida:** `APIBaseQuotationResultDTO[]` → `QuotationResult[]` (dominio). Mapping en §4.
5. **Traducir errores:** envelope Visred → excepciones de dominio → (en el borde HTTP de MANGO) envelope MANGO.

> **Sync vs async detrás del puerto:** para Vehículos el `quote()` encapsula el polling. Si el polling es largo, evaluar si `quote()` es bloqueante (espera el resultado) o si el flujo del chat ya es async y conviene un Job + notificación. ⚠️ Depende de cómo es hoy el flujo del chat (punto 1.5). El puerto no cambia; cambia la implementación interna del Adapter.

### 3.4 VisredClient (concern separado del Adapter)

Aísla HTTP + ciclo de token, para no mezclarlo con la traducción de dominio:

- Mantiene el `access`/`refresh` (cache server-side; el access dura ~1h).
- Refresh-on-401: si un request vuelve 401, refresca con `/token/refresh/` y reintenta una vez; si el refresh falla, re-login con credenciales de servicio.
- Inyecta `Authorization: Bearer`, `Accept: application/json`.
- Normaliza el envelope de error Visred a una excepción tipada (`VisredApiException` con `code`/`status`/`field_errors`) que el Adapter mapea a excepciones de dominio.

---

## 4. Mapping de campos — Visred → dominio MANGO

> El lado izquierdo (Visred) está verificado. El lado derecho (dominio MANGO) es ⚠️ PENDIENTE: completar con los nombres reales del modelo de cotización del backend (punto 1.3).

| Visred (`APIBaseQuotationResultDTO`) | Tipo | Dominio MANGO (⚠️ confirmar nombres) | Nota |
|---|---|---|---|
| `quotation_result_id` | int | id de resultado de quote | **Clave**: se referencia al emitir (`quotation_result_id`) |
| `fee` | double, nullable | prima / cuota | Es la **prima**; la "cuota mensual" depende de `installments`, no es directo |
| `insured_amount` | int, nullable | suma asegurada | |
| `installments` | int, nullable | cuotas | |
| `franchise` | double, nullable | franquicia | |
| `cover.id` / `cover.name` / `cover.description` | str | cobertura (slug / nombre / detalle) | |
| `payment_method_id` | str, nullable | medio de pago | id de param |
| `features[]` | array | adicionales/coberturas extra | |
| `require_inspection_before_emission` | bool | flag de inspección pre-emisión | Condiciona el payload de **emisión** (fuera de scope acá) |

**Huecos sin fuente en el contrato (no inventar):** `vigencia` (fecha de vencimiento) y `cuota_due` que el mock de `/polizas` expone **no existen** en el quote de vehículos (el request de autos ni siquiera tiene `validity`). Si el dominio los necesita, los deriva/almacena workflow-assistant; no salen de la cotización Visred.

---

## 5. Plan de implementación incremental (propuesto)

1. **Extraer el puerto** de la firma actual del servicio de quote, sin cambiar comportamiento. El mock pasa a `MockQuotationAdapter implements QuotationPort`. Punto de invocación depende del puerto. Tests existentes deben seguir verdes. *(No toca Visred.)*
2. **`VisredClient`** + ciclo de token + mapeo de error envelope. Test contra el sandbox (⚠️ bloqueado por credenciales).
3. **`VisredQuotationAdapter`** implementando el puerto: traducción entrada/salida + polling. Tests con fixtures del schema (`TaskList`, `APIBaseQuotationResultDTO`).
4. **Config seam** (`quotation_provider`): bind condicional en el service provider. Flip por env.
5. **Verificación E2E** contra sandbox una vez haya credenciales.

---

## 6. Bloqueantes y pendientes

- ⚠️ **Credenciales de sandbox Visred** — no entregadas aún. Bloquea los pasos 2, 3 (test) y 5. Pedir a Visred (confirmar usuario de servicio vs prueba, §2.4).
- ⚠️ **Lectura del código de workflow-assistant** — bloquea toda la sección 1 y los nombres reales de §4 y §3.2. Es lo que falta de tu lado para cerrar el doc.
- **Fuera de scope (próximos docs):** flujo de **emisión** (`emitir/` → `TaskItem` → polling → `APIBasePreSaleResultDTO` con `presale_id`/`policy_number`) y **descarga de documento** (`POST /v1/documents/` con el `presale_id` de la emisión). Ya conversados; van en su propio documento.

---

*Documento de diseño — lado Visred verificado contra el schema publicado (`/v1/schema/`). Lado workflow-assistant pendiente de reconciliar con el código. No usar las secciones ⚠️ PENDIENTE como verdad hasta confirmarlas.*
