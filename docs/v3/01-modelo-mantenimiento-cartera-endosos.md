# 01 — Modelo de dominio: mantenimiento de cartera y endosos (v3)

> **Estado: diseño (gate), no implementado.** Documento model-first: es el entregable a revisar
> antes de tocar código, igual que `docs/v2/10` fue el gate de la cotización/emisión.
> El detalle de ejecución vivirá en [`../../ROADMAP.md`](../../ROADMAP.md).
>
> **Alcance v3:** todo lo **post-emisión** de una póliza — renovación, refacturación,
> modificaciones y terminales (anulación/rescisión) — y cómo se mantiene la cartera al día
> **sin** señal automática de la compañía. El extractor de documentos (cómo se pueblan estos
> hechos sin carga manual) vive en doc aparte: [`02-extractor-documentos-poliza.md`](02-extractor-documentos-poliza.md).

---

## 1. Contexto y por qué v3

`docs/v2/10` modeló la **emisión** asumiendo que la compañía (vía Visred) es el **System of
Record** y que su póliza/estado/endosos se consultan **on-demand**. Esa premisa **no se sostiene
en la práctica**: **no existe endpoint de consulta por `policy_number` ni webhook** de eventos de
póliza (confirmado). El on-demand es imposible.

Consecuencia: la cartera se mantiene **manualmente** en `workflow-assistant`, que pasa a ser la
fuente de verdad de estado/vigencia/montos para mango-mobile. Esto **invierte** la decisión de
`docs/v2/10` §4/§5 (proyección on-demand) y deja desactualizada esa parte — ver §13.

Lo que hace tratable el mantenimiento manual es que el **documento** (póliza/endoso) **sí**
contiene los hechos, y se puede **clasificar y extraer** para poblar el modelo (doc 02). El
trabajo irreducible que no se puede derivar ni empujar al cliente es la **confirmación de un
evento de la compañía** (renovó / dio de baja / refacturó, y con qué valores).

## 2. Principios heredados (no negociables)

- **Agnóstico de proveedor.** El dominio nunca importa una clase de compañía ni de Visred. Los
  nombres/códigos de cada compañía (conceptos, códigos de cobertura, numeración) **no** son
  vocabulario de dominio: se mapean a un vocabulario propio y la etiqueta cruda va como metadata.
  Ver `docs/v2/10` §2 y `~/.claude/memory/feedback_modelo_agnostico_proveedor`.
- **Hecho vs proyección.** Se **almacena** lo observado (lo que no se puede recalcular); se
  **computa en lectura** lo que es función pura de hechos que ya tenemos. No persistir valores
  derivados del tiempo (necesitar un barrido nocturno es el síntoma de violar esto).
- **Transacciones append-only e inmutables.** Una transacción no se revierte; se emite **una nueva
  con efecto contrario**. El estado vigente es siempre el plegado (fold) de la corriente.
- **Mínima fricción de carga / sin sobre-ingeniería.** El modelo se optimiza para que **cargar una
  transacción sea trivial y uniforme** (la refacturación es el evento más frecuente — §11). Menos
  entidades, menos casos especiales.
- **Sin DTO.** Una representación tipada por entidad (ver `CLAUDE.md`).

## 3. Modelo de dominio

La **Transacción (operación)** es el concepto unificador: el evento entre compañía y asegurado.
Cada transacción tiene un `tipo` y, según ese tipo, **abre un contrato** o **lo modifica**.

```
Risk 1:N Poliza             (cadena temporal — cada RENOVACIÓN abre una Poliza nueva sobre el mismo Risk)
Poliza 1:N Transacción      (la corriente — append-only, MISMA forma para todas, plegada por fecha_inicio)
Poliza 1:N Riesgo/Cert.     (FLOTA — fuera de scope; ver §12)
```

- **Risk** — identidad del bien asegurado (el vehículo: patente/chasis/marca/modelo/…). Dedup por
  `(customer_id, type, patente)`; `chasis` como identidad más fuerte. Un auto asegurado N años,
  aunque cambie de compañía, es **un** `Risk` (ver `docs/v2/10` §7).
- **Poliza** — la **identidad del contrato** (liviana): número, Risk, compañía, producto,
  `contrato_anterior_ref`, término de cobertura. **No lleva montos ni atributos versionados** — esos
  viven en la corriente de transacciones. Una sola Poliza `Vigente` por Risk a la vez.
- **Transacción (operación)** — **una fila por documento/operación, todas con la misma forma**
  (`tipo`, período, prima/premio, deltas, documento). Append-only e inmutable. El estado y los
  términos vigentes del contrato son el **fold** de su corriente de transacciones (§7).

### Transacción → consecuencia (acto vs artefacto)

La transacción es el **acto**; su **consecuencia** depende del `tipo`:

| `tipo` de transacción | Consecuencia |
|---|---|
| **emisión** / **renovación** | **Abre una Poliza** (crea/identifica el contrato + su primer período). Renovación abre una Poliza *nueva* sobre el mismo Risk. |
| **refacturación** / **modificación** / **anulación** / **rescisión** / … | Es un **endoso**: modifica o cierra la Poliza existente. No crea una nueva. |

Lo que esto fija:
- **"Endoso" es una clase de `tipo`s** (los que modifican/cierran), **no una entidad/tabla aparte**.
  Cargar un endoso = agregar una fila a la corriente con su `tipo`. **Cero decisión de esquema por carga.**
- **La Poliza no es un endoso ni una transacción** — es la identidad del contrato que la transacción
  de emisión/renovación abre. (Se mantiene tu regla "póliza no es endoso".)
- **No hay "endoso 0".** La primera transacción de la corriente es de `tipo = emisión` (o `renovación`).
- **El monto inicial del contrato es el de la transacción de emisión** (la primera de la corriente);
  la Poliza-identidad no lleva monto propio. Reconcilia "la póliza tiene monto" + "no hay
  refacturación 0": el monto está, en la emisión, que **no** es una refacturación.
- **Calza 1:1 con los documentos de origen:** las compañías ya exponen una tabla de
  transacciones/movimientos (fila 0 = emisión/renovación, filas 1..N = endosos) — el extractor
  (doc 02) mapea fila→fila con mínima traducción.

## 4. Jerarquía temporal (tres niveles)

Validada contra cuatro compañías (§8). No confundir los niveles:

```
1. TÉRMINO DE COBERTURA  (la Poliza)   → duración VARIABLE: anual | semestral
      rige Vigente/Vencida · al vencer → renovación (nueva Poliza, back-ref al número anterior)
2. ESTRUCTURA DE FACTURACIÓN (dentro del término) → uno de dos modelos:
      (a) refacturación: el término se parte en sub-períodos RE-TARIFADOS, cada uno una transacción
          (endoso) con su prima
      (b) premio único: un solo premio para todo el término (sin re-tarifa interna)
3. PLAN DE PAGO (cronograma de débitos)
      (a) ~1 débito por sub-período   (b) N cuotas del premio único
      → es COBRANZA: fuera de scope (se captura el dato si está en el documento; no se trackea)
```

- Nivel 1 → **estado** (Vigente/Vencida).
- Nivel 2 → de dónde sale el **premio vigente**.
- Nivel 3 → cobranza, **fuera de scope**.

## 5. Ejes de estado (ortogonales)

| Eje | Valores | Origen |
|---|---|---|
| **A — Estado del contrato** | Emitida · Vigente · Vencida · Anulada · Rescindida | Emitida/Vigente/Vencida = **computados** (fechas del término); Anulada/Rescindida = **hechos terminales** (transacción de anulación/rescisión, por `fecha_inicio`) |
| **B — Estado de cobertura** | Activa · **Suspendida** | Cláusula automática por falta de pago. **Fuera de scope** (sin señal de cobranza) — ver §12 |

Una póliza puede estar **Vigente y Suspendida** a la vez: son dimensiones independientes. El eje B
queda **declarado pero no implementado** (no lo mezclamos con el enum del eje A).

**"Vigente/Vencida" es estado del CONTRATO (la Poliza), no de las transacciones individuales.** Las
transacciones no se "activan" una contra otra: se **pliegan** (§7). El constraint "una sola Vigente
por Risk" es a nivel **Poliza** (un contrato activo por vehículo).

### Cómputo del eje A (proyección a una fecha de corte)

```
¿hay transacción terminal (Anulada/Rescindida) con fecha_inicio ≤ corte?  → ese estado (precedencia)
si no, según el TÉRMINO DE COBERTURA:
        inicio_término > corte  → Emitida
        fin_término    < corte  → Vencida
        en otro caso            → Vigente
```

**Auto-transición Vigente→Vencida: se COMPUTA, no se persiste.** No hay barrido nocturno ni campo
`estado` derivado del tiempo en DB. Decisión: preferimos "mostrar de menos" antes que afirmar una
vigencia falsa. El cómputo es contra el **término de cobertura** (anual/semestral normalizado), no
contra el período tarifario. *(Hoy el código persiste `estado` y filtra por columna — migración a
cómputo es trabajo de implementación; ver §13.)*

## 6. Tipos de transacción

Una transacción **no es mono-propósito**: una sola puede traer período + prima/premio + deltas de
suma/cobertura/datos + cláusulas (verificado en San Cristóbal endoso 1 y Sancor endoso 1). Forma
común: `tipo` (dominio) + `concepto/operación` (etiqueta cruda de compañía) + `período` +
`prima/premio` + conjunto de **deltas**.

| `tipo` | Abre/Modifica | Eje A | Fechas | Montos | Origen |
|---|---|---|---|---|---|
| **emisión** | abre Poliza | inicia Vigencia | primer período | sí (prima/premio inicial) | compañía |
| **renovación** | abre Poliza **nueva** | inicia Vigencia | nuevo término | sí | compañía |
| **refacturación** | endoso | sigue Vigente | nuevo período tarifario | sí | compañía |
| **anulación** | endoso terminal | → Anulada | cierra | devuelve prima (montos negativos) | ambos |
| **rescisión** (agravación/fraude) | endoso terminal | → Rescindida | cierra | devuelve prima | compañía |
| **cambio de suma asegurada** | endoso | sigue Vigente | `fecha_inicio` | sí | asegurado/PAS |
| **cambio de cobertura** | endoso | sigue Vigente | `fecha_inicio` | sí | asegurado/PAS |
| **inclusión/exclusión** (accesorios, GPS, conductor) | endoso | sigue Vigente | `fecha_inicio` | sí | asegurado/PAS |
| **prenda / acreedor prendario** | endoso | sigue Vigente | `fecha_inicio` | no | asegurado |
| **transferencia / cambio de titular** | endoso | sigue Vigente | `fecha_inicio` | no | asegurado |
| **cambio de datos** (domicilio, CP, uso) | endoso | sigue Vigente | `fecha_inicio` | sí/no | asegurado |

**Notas:**
- `fecha_inicio` = inicio de vigencia de la transacción. **Puede ser retroactiva** (anterior a la
  emisión de la transacción) → el fold ordena por `fecha_inicio`, nunca por fecha de emisión.
- **Vocabulario:** la columna `concepto`/`operación`/`motivo` del documento es de la compañía (p. ej.
  San Cristóbal llama "Prórroga" a una refacturación; Triunfo "REFACTURACION"). Mapear a `tipo` de
  dominio; guardar la etiqueta literal como metadata.
- **Numeración no siempre secuencial** (San Cristóbal usa endoso `900` para cancelación). No asumir
  `0,1,2,…` contiguo.
- **Cancelación ≈ rescisión** en el vocabulario de las compañías (San Cristóbal: `CONCEPTO:
  Cancelación`, cuerpo "se instrumenta la rescisión"). Ambas → estado terminal.

## 7. Proyecciones (estado actual = fold de la corriente)

El estado de un contrato a una fecha de corte es la **proyección (fold)** de su corriente de
transacciones — **no** hay "una transacción vigente": muchas están en efecto a la vez y cada **campo**
resuelve a su valor más reciente en vigencia. Hay **dos semánticas de fold** según el campo:

1. **Atributos del contrato** (domicilio, suma asegurada, cobertura, uso, datos) → **la última que
   tocó el campo gana**, por `fecha_inicio ≤ corte`. Es acumulativo/permanente: el cambio queda hasta
   que otra transacción lo cambie; lo que una transacción no toca, queda como estaba.
2. **Montos por período** (prima/premio) → manda **la transacción cuyo período `[inicio, fin)`
   contiene la fecha de corte**. No es acumulativo: cada refacturación es dueña de su período (la de
   junio no pisa la de mayo).

```
Estado (eje A):    según §5 (computado del término + transacciones terminales).
Premio vigente:    la transacción cuyo período contiene el corte (uniforme — la emisión es solo la
                   primera de la corriente; sin caso especial).
Atributos vigentes: por cada campo, el valor de la transacción más reciente (fecha_inicio ≤ corte)
                   que lo tocó; si ninguna lo tocó, el de la emisión.
```

### Ejemplo — emisión + refacturación + cambio de domicilio

```
Emisión        (fecha_inicio 15/03)  domicilio: Calle A · suma: 10M · período 15/03–15/04 · premio 30k
Refacturación  (fecha_inicio 15/04)  período 15/04–15/05 · premio 32k        (no toca atributos)
Cambio domic.  (fecha_inicio 20/04)  domicilio: Calle B                      (no toca período/premio)

Estado al 25/04:
   domicilio = Calle B   (última que lo tocó)
   suma      = 10M       (solo la emisión la seteó)
   premio    = 32k       (la refacturación cuyo período contiene el 25/04)
   estado    = Vigente   (contrato dentro del término)
```

Las tres transacciones están "en efecto" y **no compiten**: cada campo toma su valor correcto.

### Casos de borde

- **Empate de `fecha_inicio` sobre el mismo campo:** desempata el **orden de secuencia** (número de
  transacción) o `fecha_emision`.
- **Retroactividad:** el fold ordena por `fecha_inicio`, no por emisión → una transacción retroactiva
  se inserta en su lugar temporal correcto (aplica desde su `fecha_inicio` aunque se haya emitido
  después).
- **Transacción mixta** (atributo + monto a la vez — p. ej. cambio de domicilio que re-tarifa, o el
  endoso de Sancor que cambia sumas y trae período): aporta a **las dos** semánticas desde la misma fila.

`prima` ≠ `premio`: **prima** = premio técnico neto (base imponible); **premio** = total facturado
(prima + IVA + percepciones + sellado + cuota social + recargo financiero si hay cuotas). El cliente
paga el **premio**. Se almacenan ambos en la transacción.

## 8. Frontera invariante (dominio) vs variable (representación)

El núcleo es **invariante** entre compañías; lo que cambia es **cómo cada una escribe los mismos
hechos**. Esto es exactamente lo que el extractor por compañía (doc 02) traduce.

**INVARIANTE (dominio):** contrato + corriente de transacciones (emisión/renovación → endosos) ·
término de cobertura · estructura de facturación · prima/premio · back-ref de renovación · riesgo
(marca/modelo/versión/año/patente/motor/chasis/uso) · suma · cobertura · cláusulas · acreedor
prendario · forma de pago (débito) · cláusula de suspensión automática (eje B).

**VARIABLE (por compañía, hay que mapear):** numeración de transacción · dónde vive el término anual ·
etiquetas de operación/concepto · duración del término · modelo de facturación · representación de
cobertura (lista de límites vs código) · prenda (campo vs cláusula) · hora de corte · capas de
identidad · tipo de documento.

### Casos testigo

| | Término cobertura | Facturación | Back-ref | Doc types vistos |
|---|---|---|---|---|
| **San Cristóbal** | anual (`PRORROGABLE HASTA`) | refacturación mensual | `RENOVACIÓN (PÓLIZA …)` | resumen, endoso, cancelación, tarjeta |
| **Triunfo** | anual (vigencia de la emisión) | refacturación mensual | `Contrato anterior` | póliza/emisión, endoso refacturación |
| **Río Uruguay** | **semestral** | **premio único en cuotas** | `Renueva` | frente de póliza |
| **Sancor** | anual (`Prórroga automática hasta`) | **semestral** + endosos modificatorios | `Referencia N°` (constante) | carátula anual, modificación, constancia |

Los ejes variables **duración del término** {anual, semestral} y **modelo de facturación**
{refacturación, premio único, semestral} cubren las cuatro con combinaciones libres. Apéndice con
datos concretos en §A.

## 9. Datos del modelo (referencia, sujeto a implementación)

- **Poliza** (identidad del contrato, **liviana**): número, `company_id`, `product_id`, `risk_id`,
  `contrato_anterior_ref` (back-ref de renovación normalizado — §13.5), **término de cobertura**
  (`inicio`, `fin` — **timestamps**, la hora importa: 00hs/12hs varía), metadata cruda de la compañía.
  **No** lleva montos ni atributos versionados (viven en la corriente).
- **Transacción** (la corriente, **una fila por operación/documento**): `poliza_id`, `tipo` (dominio)
  + `concepto_crudo` (metadata), `secuencia`, `fecha_emision`, `fecha_inicio` (puede ser retroactiva),
  período (`inicio`/`fin`, para montos por período), `prima?`/`premio?`, **deltas** (`suma?`,
  `cobertura?`, `domicilio?`, `uso?`, datos…), `documento_id`, descripción.
- **Forma de pago:** modalidad (débito automático — no hay cupones) + entidad/medio. Hecho
  contractual, distinto de trackear si las cuotas se pagaron (cobranza, fuera de scope).
- **Forma física (`polizas` + `transacciones`):** el modelo conceptual no obliga a una forma de tablas.
  Lo natural es **dos tablas** — `polizas` (identidad) + `transacciones` (corriente). Una tabla única
  con la identidad denormalizada se descarta por consistencia. La decisión física final se toma al
  implementar.

## 10. Detección (cola de trabajo, derivable y barata)

Con el **término de cobertura** + `emitida_en` se puede listar **"qué vence en X días"**
(renovación) sin trabajo manual. La frecuencia de refacturación, cuando la declara el documento
(p. ej. Triunfo: `Frecuencia de refacturación: Mensual`), permite además listar **"qué refactura
en X días"**. La detección es la cola de trabajo del operador; la **confirmación** (renovó/baja y
con qué valores) y la **actualización** siguen siendo manuales (asistidas por el extractor, doc 02).

> **Resuelto (§13.1):** la cadencia es un **hecho por póliza** — se extrae del documento cuando lo
> declara (p. ej. Triunfo) o se deriva de los períodos contiguos de las transacciones. **No** hay tabla
> de config de cadencia por compañía como fuente primaria (supersede la idea de "config estático").

## 11. Renovación, refacturación y modificación (cómo operan)

Todas son **transacciones** sobre la corriente; difieren en `tipo` y consecuencia (§3):

- **Renovación** = transacción que **abre una Poliza nueva** (nuevo número), con `contrato_anterior_ref`
  a la anterior, que pasa a `Vencida`. Puede ser automática (cláusula de prórroga automática al fin del
  ciclo) — igual abre Poliza nueva. El constraint "una sola Vigente por Risk" lo fuerza.
- **Refacturación** = transacción (endoso) sobre la **misma** Poliza: nuevo período tarifario + nueva
  prima/premio. Es el churn sub-anual donde existe.
- **Modificación** = transacción (endoso) que aporta **deltas** (suma, cobertura, domicilio, datos),
  con su `fecha_inicio`, dentro del término.

> **Por qué existe la refacturación (contexto de dominio).** En el mercado argentino, en períodos de
> alta inflación las cláusulas de ajuste no alcanzan y la compañía no puede emitir con cualquier
> vigencia (requiere autorización de la SSN). Para **mantener la ecuación económica** del contrato
> (poder pagar siniestros, evitar infraseguro) re-tarifa en períodos cortos. Implicancia para el
> modelo: **la refacturación es la transacción más frecuente** → cargarla debe ser trivial y uniforme,
> lo que justifica la corriente de transacciones de forma única (§3).

## 12. Fuera de scope (parqueado, no descartado)

- **Cobranza / plan de pago / cuotas** — sin tracking de pagos. Se captura el dato del documento, no se sigue.
- **Eje B (suspensión de cobertura por falta de pago)** — cláusula central del contrato, pero sin
  señal automática no se puede computar. Declarado, no implementado.
- **Rehabilitación / reactivación** — operación administrativa de la compañía, no endoso. Fuera de scope.
- **Flota (Poliza 1:N Riesgo / "Certificado N°")** — el modelo lo admite; el scope es auto individual (1 riesgo).
- **Prórroga (como tipo general)** — figura muy variable por compañía/ramo; no se modela como tipo universal.

## 13. Decisiones (cerradas 2026-06-20)

1. **Cadencia = hecho por póliza, no config por compañía.** ✅ La duración del término y la frecuencia
   de refacturación se **capturan del documento** cuando lo declara (Triunfo: `Frecuencia de
   refacturación: Mensual`; el término en la vigencia/prórroga) o se **derivan de los períodos
   contiguos** de las transacciones. **No** se mantiene una tabla de config de cadencia por compañía
   como fuente primaria. *Supersede* la elección previa "config estático por compañía": la evidencia
   mostró que el dato vive por póliza en el documento (una compañía puede tener pólizas con cadencias
   distintas), lo que es más preciso. Si el documento no lo trae ni se puede derivar, se cae a un
   default conservador (no asistir la detección de esa póliza) en vez de inventar una cadencia.
2. **Eje B (suspensión) = diferido explícito.** ✅ No se modela ni implementa el estado de cobertura
   suspendida **mientras no exista una señal de cobranza** (rechazo de débito / aviso de la compañía /
   integración de pagos). **Condición de reingreso:** que aparezca esa señal — p. ej. un webhook/endpoint
   de cobranza, o un documento de aviso de suspensión que el extractor pueda clasificar (doc 02). Hasta
   entonces el sistema asume cobertura activa dentro del término y **no** afirma suspensión.
3. **Estado computado = diseño decidido (no es un open).** ✅ El modelo v3 manda computar
   Vigente/Vencida contra el término (§5). El código actual persiste `estado` y el mobile filtra por
   columna (`Mobile/PolizasController`); pasar a cómputo (filtrar por ventana de fechas + el constraint
   "una vigente por Risk" → **no-solapamiento de vigencias**) es **delta de implementación conocido**,
   se trackea en `ROADMAP.md` al ejecutar v3. No es una decisión de diseño abierta.
4. **Reconciliación con `docs/v2/10`** — ✅ **hecha.** Correcciones v3 anotadas en `docs/v2/10` §1, §4
   y §5 (la premisa "proyección on-demand de la compañía" queda **invertida** por cartera-manual) y en
   la memoria `feedback_modelo_agnostico_proveedor`.
5. **Back-ref de renovación = `contrato_anterior_ref` (una sola arista, dos direcciones).** ✅
   La renovación es **una sola relación** entre póliza predecesora y sucesora, que el documento puede
   declarar desde cualquiera de las dos puntas: hacia atrás ("renovación de / contrato anterior [vieja]",
   en la nueva) o hacia adelante ("se renueva en [nueva]", en la vieja). Decisiones:
   - **Dirección canónica almacenada = hacia atrás:** `contrato_anterior_ref` en la `Poliza` **nueva**
     (la que abre la transacción de renovación). La etiqueta cruda de cada compañía (`Renueva`,
     `Contrato anterior`, `RENOVACIÓN (PÓLIZA …)`, `Referencia N°`, `se renueva en`) va como metadata.
   - **Forward ("se renueva en [nueva]") = derivado**, por reverse lookup; **no** se almacena
     (evita doble fuente de verdad).
   - **El extractor normaliza venga de la punta que venga** (doc 02): cualquier etiqueta/dirección
     resuelve a la misma arista canónica. Caso de borde: si solo la póliza vieja declara el forward y la
     nueva aún no está cargada, se guarda el ref crudo como metadata y la arista se cierra cuando aparece
     la sucesora.
   - **Ancla cross-compañía = el `Risk`.** El back-ref **se corta al cambiar de aseguradora** (la nueva
     no referencia el número de la vieja). Misma compañía → unidas por `contrato_anterior_ref` + Risk;
     cambio de compañía → unidas **solo** por el `Risk` (patente/chasis), y el eslabón sin back-ref marca
     el salto. La historia completa del auto = las `Poliza` del `Risk` ordenadas por término.

> Lo que sigue siendo **fuera de scope** (no abierto, deliberadamente excluido) está en §12.

---

## §A — Apéndice: datos concretos de los casos testigo

> Las filas "endoso -N / Op. N / Endoso 0" son la corriente de transacciones **cruda de cada compañía**
> (lo que el extractor mapea a la corriente de dominio).

**San Cristóbal — Risk TOYOTA HIACE 2.8 FURGON L1H1 2022 · AF288BJ · chasis JTFXBGAP4N8006641**
```
Poliza 31803396  EMISIÓN (06/05/2025)     cobertura 30/04/2025→30/04/2026
   1er período 30/04→30/05/2025  prima $85.704,74  premio $117.587
   └ endosos -1..-11 (prórrogas mensuales + cambios)
Poliza 32038538  RENOVACIÓN (PÓLIZA 31803396) (20/04/2026)  cobertura 30/04/2026→30/04/2027 (= tarjeta)
   1er período 30/04→30/05/2026  prima $113.198,25  premio $155.195
   ├ endoso -1 (concepto "Prórroga", 30/05→30/06)  premio $155.289  + baja de suma + límites + cláusulas
   └ endoso -2 (30/06→30/07)  premio $154.218
Terminal (otra póliza, 31967204): concepto "Cancelación" / cuerpo "rescisión", fecha efecto 30/04/2026,
   montos NEGATIVOS (prima $-52.893,67 · premio $-70.474), endoso 900.
```

**Triunfo — NISSAN TIIDA 1.8 ACENTA 2010 · JHP332 (póliza 8.826.846)**
```
Emisión (Op. 2, "Nuevo seguro")  vigencia 15/03/2026→15/03/2027 (anual)  prima 16.830,78 · premio 33.989 mensual
   Frecuencia de refacturación: Mensual · Contrato anterior 1.589.610
Endoso (Op. 3, Endoso N°1, "REFACTURACION")  vigencia 15/04→15/05/2026  prima 15.527,20 · premio 33.989
```

**Río Uruguay — NISSAN TIIDA 1.8 VISIA 2010 · JIZ212 (póliza 00:04:13527387)**
```
Emisión (Endoso 0)  Vigencia 24/04/2026→24/10/2026 (SEMESTRAL) = Período Facturación
   Renueva 00:04:13110812 · Prima 227.717,61 + Rec.Financ 41.808,95 (TEA 26,83%) = Premio $539.103
   Plan de pago: 6 cuotas mensuales ~$89.851 débito de CBU (premio único en cuotas)
```

**Sancor — FORD ECOSPORT 1.6 SE 2014 · NWF862 (póliza 000031111672, ref 000031035038)**
```
Carátula ANUAL        Endoso 0  Vigencia 14/01/2026→14/01/2027 (marco anual)  RC 208M · Casco 14,8M
Carátula MODIFICACIÓN Endoso 1  Vigencia 14/02→14/07/2026 (*prórroga hasta 14/01/2027)
   modifica sumas: RC 208M→240M · Casco 14,8M→16,3M
Constancia COBERTURA  Endoso 6  Vigencia 14/05→14/07/2026 (*prórroga hasta 14/01/2027)  Casco 14,523M
   (3 tipos de documento de la MISMA póliza · eje "Certificado N°" = flota)
```
