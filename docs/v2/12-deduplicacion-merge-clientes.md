# 12 · Deduplicación y merge de Clientes (filas duplicadas → una sola)

> **Estado:** parcialmente implementado (existe `CustomerMergeService` + integración en checkout);
> faltan correcciones y los puntos de integración restantes. Este documento es autocontenido:
> describe el bug que lo motiva, la causa raíz, el data-fix ya aplicado, y el plan de
> implementación pendiente. Complementa al `11` (ese reconcilia **campos dentro de una fila**;
> este reconcilia **dos filas en una**).

---

## 1. El bug que lo motiva (caso real, checkout #4)

La misma persona quedó como **dos filas distintas** en `customers`:

| id | nació por | identificador que tenía | dni | email | datos extra |
|----|-----------|--------------------------|-----|-------|-------------|
| **#1** | WhatsApp | solo **teléfono** | — | — | todas las conversaciones, el risk/póliza del checkout |
| **#4** | app móvil (Google) | solo **email** | 30123727 | andresrom@gmail.com | la `mobile_account` Firebase |

Ambas con el mismo teléfono `+5493516280778`.

**Qué falló:** la conversación de WhatsApp (y por ende el checkout, el `risk_snapshot`, el
`risk` y la **póliza 1822203**) quedó atada a **#1**. Pero la app móvil identifica al titular
**por email** (`MobileAccount::resolveCustomer()` → `customers.email`, que es `unique`), que
está en **#4**. La app busca las pólizas de #4 → no encuentra ninguna. La póliza existía, pero
colgada de la fila que la app no ve.

**El log que lo delató** (al cerrar el checkout):
```
CustomerConsolidation: clave única en conflicto, campo no aplicado
  {"field":"dni","value":"30123727","customer_id":1,"source":"checkout","owned_by_customer_id":4}
CustomerConsolidation: clave única en conflicto, campo no aplicado
  {"field":"email","value":"andresrom@gmail.com","customer_id":1,"source":"checkout","owned_by_customer_id":4}
```
El checkout intentó escribir el DNI/email en #1, el índice único lo rechazó (ya estaban en #4),
y el sistema **logueó y siguió de largo** — dejando la identidad partida para siempre.

---

## 2. Causa raíz

**Cada puerta de entrada crea un `Customer` con el único identificador que tiene en ese
momento, y nunca reconcilia contra filas creadas por otras puertas.**

- WhatsApp solo tiene el **teléfono** → `CustomerIdentificationService::findOrCreate('phone', …)`
  crea una fila por teléfono.
- La app móvil solo tiene el **email** → crea otra fila por email.

No se cruzan **hasta el checkout**, que es el único momento donde la persona aporta
DNI + email + teléfono **juntos**. Ahí el sistema *podría* darse cuenta de que las dos filas
son la misma persona — pero hoy no hace nada con esa información (el guard de único solo
loguea y saltea, ver `CustomerConsolidationService::apply()`).

Dato estructural que define todo el diseño:
- `customers.dni` y `customers.email` son **`unique`** (claves de identidad **fuerte**).
- `customers.phone` **no es único** (clave **débil**): por eso #1 y #4 convivieron con el
  mismo teléfono. Un teléfono puede ser compartido (familia, celular reusado).

---

## 3. El data-fix ya aplicado (caso #4)

Se fusionó **#1 → #4** a mano (sobrevive #4, que tiene el email/`mobile_account`):
- se enriqueció #4 con los campos que el checkout había escrito en #1 (domicilio, nacimiento,
  sexo, condición fiscal, `field_sources`);
- se repuntaron las FKs `conversations`, `customer_audits`, `risk_snapshots`, `risks`,
  `vehicles` de #1 → #4;
- se borró #1.

Verificado: la póliza 1822203 quedó bajo #4 y `MobileAccount::resolveCustomer()` la levanta.
**Este caso puntual está resuelto en datos; falta cerrar la causa raíz en código.**

---

## 4. La solución — el "qué"

> En el momento en que el sistema descubre que dos filas son la misma persona (porque alguien
> aporta un identificador que ya pertenece a otra fila), **fusionarlas en una sola** en vez de
> dejarlas partidas.

### Por qué dos servicios (no se pisan, se componen)

| | qué reconcilia | toca FKs / borra filas | existe por |
|---|---|---|---|
| **`CustomerConsolidationService`** | campos dentro de **1 fila** | no | el panel admin (provenance: admin no se pisa con chat). Ya usado en `CustomerController` y `CheckoutController`, independiente de este bug |
| **`CustomerMergeService`** | **2 filas → 1** | sí | este bug de duplicados |

`Consolidation` decide "qué valor gana para este campo" (prioridad admin > checkout > chat,
con `field_sources`). `Merge` hace el trabajo estructural (mover FKs, borrar la perdedora) y
al final **reusa** consolidation para rellenar los vacíos del sobreviviente con los datos del
absorbido. Una línea: `CustomerMergeService::merge()` → `$this->consolidation->apply(survivor, loserData, 'merge')`.

---

## 5. Decisión de diseño clave — reconciliar SOLO por claves fuertes

`reconcile()` recibe los identificadores (dni, email, phone) y busca la fila que posee cada uno.

- **dni / email** son únicos → un match devuelve, por construcción, **la misma identidad**.
  Reconciliar por ellos es seguro.
- **phone** no es único → `findByPhone()->first()` puede devolver la fila de **otra persona
  que comparte el número** (cónyuge, familia). Reconciliar por teléfono puede **fusionar dos
  humanos distintos**.

**Conclusión:** `reconcile()` debe reconciliar **solo por `dni` y `email`**, NO por teléfono.
Con eso:
- el bug se cierra igual (el merge se gatilla por el email/dni del checkout);
- el escenario de "conflicto de identidad fuerte" (dos filas con dni distinto) prácticamente
  desaparece. El único residuo serían emails reciclados/compartidos/mal tipeados (dato sucio,
  raro) → ahí, a lo sumo, una red de seguridad que **loguea y saltea**, no una decisión de
  negocio.

> Nota sobre los casos que se preguntaron en la discusión:
> - "dni igual, email distinto" → **no es conflicto**: mismo dni = misma persona; el merge
>   solo rellena el email faltante.
> - "dni distinto, email igual" → solo si el email está reciclado/compartido (dato sucio).
> - "teléfono compartido, gente distinta" → el único conflicto realista, y se evita **no
>   reconciliando por teléfono**.

---

## 6. Estado del código — **IMPLEMENTADO**

> Diseño cerrado y aplicado. El análisis completo del procedimiento de identificación
> (4 fuentes × órdenes temporales) y las decisiones quedaron en el plan
> `analiza-acabadamente-el-problema-lively-fern.md`. Resumen de lo aplicado abajo.

---

## 7. Implementación

### 7.1 `reconcile()` — solo claves fuertes (decisión §5) — **HECHO**
`CustomerMergeService::reconcile()` reconcilia solo por `dni`/`email` (sin brazo `phone`);
`CheckoutController::submit()` ya no pasa `phone` a `reconcile`. El teléfono se sigue
consolidando como **campo** (no como clave de reconciliación).

### 7.2 `CustomerConsolidationService` — fuente `'merge'` — **HECHO** (previo)
`apply()` acepta `'merge'` y lo trata como `chat` (solo rellena vacíos). Hoy lo usa muy poco:
el merge real pasa por `mergeFields()` (ver 7.3).

### 7.3 Survivorship por campo — **HECHO**
`CustomerConsolidationService::mergeFields()` resuelve cada campo comparando el `field_sources`
de ambas filas: gana la fuente más confiable (**admin > checkout > chat**), desempata el `at`
más reciente; **preserva la provenance ganadora** y audita cambios y descartes. `merge()` lo
invoca tras repuntar FKs y `forceDelete` del perdedor. Reemplaza al viejo "rellenar huecos +
audit de conflictos".

### 7.4 wbid → BSUID + árbol create/enrich/merge en el chat — **HECHO**
- **wbid era un mislabel de DNI.** El `wbid` real (BSUID de Meta) es un identificador **de
  conversación** (`conversations.ext_user_id`, dual-key ya implementado), no del tomador. El
  tool de identificación de cliente (`IdentifyCustomerTool`, `WhatsAppAdapter`,
  `AgentToolAdapter`) ahora ofrece `email | phone | **dni**`. Ver §6.1 del plan / doc de Meta.
- **Crash triple arreglado** en `CustomerIdentificationService` (`validateIdentifier`,
  `findCustomer`, `createCustomer` ahora manejan `dni` + `default`).
- **Árbol create/enrich/merge** en `CustomerIdentificationService::resolveForConversation()`
  (agnóstico de canal; lo llaman los dos adapters): si el identificador pertenece a otra fila
  → **merge**; si no lo posee nadie y la conversación ya tiene tomador → **enriquecer** esa
  fila (no crear). Elimina el `create+repoint` que dejaba filas huérfanas.

### 7.5 Casing del email — **HECHO**
`MobileAccount::resolveCustomer()` matchea con `LOWER(email)` (la app resuelve el titular por
email; un casing distinto del OAuth dejaba al cliente sin ver sus pólizas).

### 7.6 Tests (Pest, feature) — `tests/Feature/CustomerMergeTest.php` — **HECHO**
Reproduce el bug #4 (reconcile + `MobileAccount::resolveCustomer`), escenario chat-enrich (no
crea fila), chat-merge (colapsa filas), edge número reasignado (mismo phone, distinto dni/email
→ **no** mergea), y survivorship por campo (admin protegido + recencia desempata).

---

## 8. Fuera de alcance (YAGNI)

- UI de revisión de merges en el admin.
- Acción genérica de "fusionar dos clientes cualesquiera" desde el panel.
- Volver `phone` único (teléfonos compartidos lo hacen riesgoso; las claves fuertes alcanzan
  para deduplicar).

---

## 9. Verificación

```bash
composer analyse   # 0 errores nuevos sobre el baseline
composer test      # tests/Feature/CustomerMergeTest.php
```
