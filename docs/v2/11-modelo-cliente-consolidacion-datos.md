# 11 · Modelo del Cliente — consolidación de datos (Customer = fuente de verdad)

> **Estado:** implementado (CRUD de Clientes + consolidación). Este documento es el análisis
> de dominio detrás del feature: por qué el `Customer` es el registro canónico, cómo se
> consolidan los datos que llegan de varios canales, y cómo se mapea el domicilio al
> proveedor sin acoplar el dominio.

---

## 1. El problema: captura multi-fuente, registro vacío

Los datos de un mismo cliente se capturan en **dos lugares distintos** y, hasta ahora,
quedaban desconectados:

1. **Chat (`identifyCustomer`)** — `CustomerIdentificationService::findOrCreate()` crea el
   `Customer` con **solo email o teléfono**, anónimo, **sin nombre**.
2. **Checkout (`CheckoutController::submit`)** — captura el `person_holder` completo
   (nombre + apellido, DNI, nacimiento, sexo, condición fiscal, teléfono, domicilio) y lo
   guarda en `checkout_sessions`. De ahí lo **lee** `PolizaEmisionService` para armar el
   request neutro hacia Visred; `PolicyReferenceService` materializa `Risk`+`Poliza`
   **sin copiar** el dato personal de vuelta al `Customer`.

**Resultado (la deuda D1/D2 que el propio código marcaba):** el dato más confiable —la
"declaración jurada" del checkout— quedaba **atrapado en `checkout_sessions`, por-quote**
(se duplica si el cliente compra dos veces), y el `Customer` canónico quedaba casi vacío.

```
chat  ─┐
       ├─►  (antes)  cada fuente escribía su silo, sin consolidar
checkout ┘            Customer ≈ vacío   |   checkout_sessions ≈ dato real atrapado
```

---

## 2. La decisión: el `Customer` es el registro canónico

El `Customer` pasa a ser la **fuente de verdad** consolidada. El checkout y el chat
**sincronizan de vuelta** hacia él. Los campos canónicos se alinean con el `person_holder`
que exige la emisión Visred (referencia verificada, ver `08` y `VisredEmissionProvider::buildHolder`):

`first_name`, `last_name`, `dni` (=`document_number`), `document_type_id` (def `dni`),
`person_type_id` (def `fisica`), `email`, `tax_condition_id`, `birthdate`, `sex_id`, `phone`
**+ domicilio del tomador** (`domicilio_calle/numero/cp/provincia/localidad`).

El nombre se parte en `first_name` + `last_name` (la columna `name` se mantiene sincronizada
para compat con búsqueda/Avatar/mail vía `Customer::syncName()`).

```
chat  ─┐
       ├─►  CustomerConsolidationService  ─►  Customer (canónico)  ◄─ edición admin
checkout ┘        (agnóstico de canal)              │
                                                    └─► audit log + provenance por campo
```

---

## 3. Modelo de pesos por fuente (integridad)

Implementado en `CustomerConsolidationService::apply(Customer, array $incoming, string $source, ?int $userId)`.
La provenance por campo vive en `metadata['field_sources'][campo] = {source, at}`.

| Fuente | Peso | Regla al aplicar |
|---|---|---|
| **admin** | alto | Edición manual deliberada → **siempre aplica** y queda en el audit log. |
| **checkout** | alto | "Declaración jurada": rellena vacíos y **pisa** valores de origen chat / checkout previo. Si choca con un valor curado por **admin** → **no pisa**, registra **divergencia** para resolución manual. |
| **chat** | bajo | **Solo rellena campos vacíos**; nunca pisa un valor existente. |

- `checkout` ≈ `admin` (mismo peso): por eso el checkout no clobberea silenciosamente lo
  que un humano curó; lo marca como divergencia y el productor decide.
- La edición admin es la vía de resolución de divergencias (botón "usar este" en el detalle
  → `customers.resolve-divergence` → `apply(..., 'admin')`, auditado).

**Audit log:** tabla `customer_audits` (`customer_id`, `user_id` nullable, `source`, `field`,
`old_value`, `new_value`, `created_at`). Una fila por cambio efectivo. Visible en el detalle.

---

## 4. Domicilio: tomador vs riesgo, y el mapeo al proveedor

### 4.1 Visred toma UN solo `address`

Verificado contra el OpenAPI live (ver `08`): el `PreSaleVehicleRequest` (emisión) tiene
`person_holder`, **`address`** y `vehicle` como bloques hermanos. **El `person_holder` NO
lleva domicilio**; el `address` es único (`zip_code`, `street_name`, `street_number`, `floor`,
`apartment`). En cotización solo se usa `address.zip_code`. No existe "domicilio del tomador"
vs "domicilio del riesgo" en Visred — es uno solo.

### 4.2 En el dominio son dos hechos distintos

- **Domicilio del tomador** (legal/facturación) → vive en el `Customer`.
- **Ubicación de guarda del riesgo** (dónde está el vehículo, lo que **tarifa**) → vive en
  `vehicle.codigo_postal` / el snapshot de cotización.

Pueden diferir (auto guardado en lo de un familiar, flota, etc.). El checkout captura un
domicilio que **siembra ambos** (cada uno con su provenance, editables por separado); no es
un valor atado a ser idéntico.

### 4.3 Regla de negocio: el CP que tarifa es el de guarda

La cotización se calcula con el **CP de guarda del vehículo** (snapshot). Como **no se
re-cotiza en checkout**, al emitir se envía a Visred **`address.zip_code` = CP de guarda
del vehículo**, independiente del CP del domicilio del tomador (de ahí salen calle/número).
Así *precio cotizado == precio cobrado*.

### 4.4 El mapeo vive en el adapter (multi-provider)

El dominio (`PolizaEmisionService`) pasa en el request neutro el domicilio del tomador y,
explícito, el CP de guarda (`address.risk_zip_code`). **El `VisredEmissionProvider` (adapter)
compone el `address` único de Visred** usando el CP de guarda como `zip_code`. Toda la lógica
de fold address→proveedor está confinada al adapter: si mañana entra otro proveedor que pida
domicilio-de-tomador y domicilio-de-riesgo por separado, **solo cambia su adapter**; el
dominio no se entera (principio de modelado agnóstico de proveedor, ver `10`).

---

## 5. Puntos de integración (dónde se engancha)

| Punto | Fuente | Qué hace |
|---|---|---|
| `CheckoutController::submit()` | `checkout` | Tras guardar la `CheckoutSession`, consolida el holder + domicilio en `$quote->conversation->customer`; siembra el CP de guarda en el vehículo solo si está vacío (no pisa el cotizado). |
| `CustomerController::store/update` | `admin` | Los campos de identidad pasan por la consolidación (provenance + audit); PAS y notas se escriben directo. |
| `CustomerController::resolveDivergence` | `admin` | Aplica el valor elegido de una divergencia como edición admin. |
| `PolizaEmisionService::buildRequest` + `VisredEmissionProvider::buildAddress` | — | Mapeo del domicilio: `zip_code` emitido = CP de guarda. |

**Intocable:** el stack de identificación de vehículo en el chat (`VehicleIdentifier*`) es solo
NLU de intención y no participa de esta consolidación.

> **Reconciliar campos ≠ reconciliar filas.** Este doc cubre la consolidación de **campos
> dentro de una fila**. Cuando la misma persona existe como **dos filas** (creadas por canales
> distintos, cada uno con una sola clave: WhatsApp por teléfono, app por email), eso lo resuelve
> el **merge** — ver [`12-deduplicacion-merge-clientes.md`](12-deduplicacion-merge-clientes.md).

---

## 6. Archivos clave

- `app/Services/CustomerConsolidationService.php` — consolidación + pesos + provenance + divergencias + audit.
- `app/Models/Customer.php` — campos canónicos, `syncName()`, `audits()`.
- `app/Models/CustomerAudit.php` + `customer_audits` — rastro de cambios.
- `app/Http/Controllers/CustomerController.php` — CRUD + detalle (cartera, vencimientos, cotizaciones, checkout read-only, divergencias, timeline).
- `app/Services/Visred/VisredEmissionProvider.php` — regla del CP de guarda (mapeo en adapter).
- Tests: `tests/Feature/CustomerConsolidationTest.php`, `tests/Feature/CustomerCrudTest.php`, y el caso de zip de guarda en `tests/Feature/Services/Visred/VisredEmissionProviderTest.php`.
