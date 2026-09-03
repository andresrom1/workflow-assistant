# Plan de remediación del diagnóstico técnico

**Proyecto:** Workflow Assistant
**Fecha de corte del diagnóstico:** 2026-09-02
**Revisión:** r2 — 2026-09-02 (contrastada contra el código)
**Estado del documento:** Propuesta ejecutable
**Alcance:** Backend Laravel, superficie HTTP pública (`routes/api.php` **y** `routes/web.php`), integraciones, pruebas, dependencias PHP/JavaScript y deuda técnica detectada.
**Fuera de alcance:** Funcionalidad de negocio nueva, rediseño visual y cambios de dominio no necesarios para corregir los hallazgos.

## 0. Qué cambió en la revisión r2

La r1 mezclaba tres cosas sin distinguirlas: hallazgos verificados en el código, recomendaciones
genéricas de higiene y trabajo que el repositorio ya tenía resuelto. Eso invertía prioridades — la
fase crítica endurecía rutas sin consumidor mientras el flujo de checkout, que es el único
público activo y el que maneja fotos y datos de tarjeta, quedaba fuera del inventario.

La r2 mantiene la estructura de criterios de aceptación y rollback por entrega, y reemplaza las
tareas genéricas por la **matriz de hallazgos** de la sección 2. Cada ítem queda clasificado en
*confirmado*, *ya resuelto*, *condicional* o *descartado*, con su evidencia. Lo que no tiene
evidencia no entra al plan.

## 1. Objetivo

Reducir la superficie de ataque efectiva, recuperar una suite determinista, actualizar las
dependencias vulnerables y mejorar la resiliencia operativa sin romper consumidores vigentes.

El trabajo se considera terminado cuando:

- el flujo de checkout público tiene límite de tasa, verificación de pertenencia de sus recursos y
  no registra su propia credencial en logs;
- ningún endpoint mutante o de diagnóstico queda expuesto sin consumidor y sin control;
- las respuestas de cotización no exponen payloads internos a consumidores no autorizados;
- los valores marcados como secretos no viajan al navegador, no se pierden al guardar y no quedan
  en texto plano en ningún almacén;
- Composer y npm no reportan vulnerabilidades críticas o altas explotables en producción, con
  reporte reproducible adjunto;
- la suite completa termina sin conexiones externas, con `0 failed` y dentro de un presupuesto de
  memoria **medido**;
- PHPStan, Pint y el build frontend terminan correctamente;
- cada cambio puede desplegarse y revertirse de manera controlada.

## 2. Matriz de hallazgos

Evidencia verificada contra el árbol en `4d4c65a`. Las líneas citadas son del 2026-09-02 y pueden
desplazarse; el símbolo (método o clase) es la referencia estable.

### 2.1 Confirmado — hay defecto y hay trabajo pendiente

| # | Hallazgo | Evidencia | Entrega |
|---|---|---|---|
| C-01 | Las tres rutas públicas de escritura del checkout no tienen límite de tasa. La ruta vecina `cotizaciones.checkout` sí lo tiene (`throttle:10,1`), así que la inconsistencia es dentro del mismo archivo | `routes/web.php:168-175` | E3 |
| C-02 | `submit()` construye las URLs de las fotos desde los `photo_ids` que manda el cliente, validados solo como `string\|max:255`, sin comprobar que pertenezcan a esa cotización. El conteo de fotos sí se hace contra `InspectionPhoto` propias | `CheckoutController::submit()`, línea 307 | E3 |
| C-03 | `photo_key` se valida como `string\|max:50` sin lista de valores permitidos y se interpola en el path de almacenamiento (`checkout/{id}/photos/photo_{key}.jpg`) | `CheckoutController::uploadPhoto()`, líneas 105 y 127 | E3 |
| C-04 | El `catch` de `submit()` registra el `checkout_token` —que es la credencial del flujo— junto al `trace` completo | `CheckoutController::submit()`, línea 387 | E3 |
| C-05 | No hay idempotencia ni control de envío simultáneo en `submit()` | `CheckoutController::submit()` | E3 |
| C-06 | El valor de los settings marcados como secretos viaja al navegador en el payload de Inertia; el enmascarado es solo visual en el front | `SettingsController::index()`, línea 32 | E5 |
| C-07 | **"Vacío conserva el secreto" no funciona.** El `continue` corta la iteración de validación pero no saca la key de `$incoming`, que se entrega entero a `saveGroup()`. Guardar el formulario con el campo secreto vacío borra el secreto | `SettingsController::updateGroup()`, línea 61 + `SettingsService::saveGroup()`, línea 30 | E5 |
| C-08 | `saveGroup()` escribe con `where()->update()` de query builder: no pasa por casts ni mutators. Cualquier diseño de cifrado basado en asignación de atributo no tendría efecto por ese camino | `SettingsService::saveGroup()`, línea 30 | E5 |
| C-09 | `loadAll()` cachea todos los valores —incluidos los secretos— durante una hora, y `CACHE_STORE=database`. El cifrado en reposo tiene una segunda copia que atender | `SettingsService::loadAll()`, línea 55 | E5 |
| C-10 | Los cuatro casos de éxito de `SiniestroTest` no tienen ningún fake: salen a la red real | `tests/Feature/Mobile/SiniestroTest.php` (0 ocurrencias de `Http::fake`/`Queue::fake` en 184 líneas) | E2 |
| C-11 | No hay `Http::preventStrayRequests()` global. `tests/Pest.php` solo ata `TestCase` a `Feature`, así que el alcance sobre `tests/Unit` hay que decidirlo explícitamente | `tests/Pest.php:5` | E2 |
| C-12 | El script `composer test` no fija presupuesto de memoria: depende del `php.ini` de cada máquina | `composer.json`, script `test` | E2 |
| C-13 | `POST /api/tools/test` es público y registra el request completo | `routes/api.php:16` | E4 |
| C-14 | `GET /api/quotes/{quote}/raw` devuelve `raw_response` del proveedor sin autenticación, con ID enumerable como única barrera | `routes/api.php:44` → `QuoteService::getRaw()`, línea 174 | E4 |
| C-15 | Seis rutas `POST /api/tools/*` marcadas "sin implementar" siguen registradas y son públicas | `routes/api.php:47-52` | E4 |
| C-16 | `QuoteController::store()` pasa `$request->all()` al servicio sin validar | `QuoteController::store()`, línea 101 | E4 o E7 según destino de la ruta |
| C-17 | Un archivo fuera de formato: `tests/Feature/ProcessMediaAttachmentTest.php` (`fully_qualified_strict_types`, `ordered_imports`). Re-verificado en r2 | `vendor/bin/pint --test` | E7 |
| C-18 | Advisories abiertos en ambos lockfiles al 2026-09-02 | Ver 3.2 | E6 |

### 2.2 Ya resuelto — no rehacer; proteger de regresión

| # | Ya existe | Evidencia |
|---|---|---|
| R-01 | Presupuestos de cola: `timeout` de job < `retry_after` de la conexión, y el presupuesto de espera del lock. **Verificado por test**, no solo por convención | `tests/Feature/Queue/WorkerConfigTest.php` |
| R-02 | El upload de fotos ya valida tipo real y tamaño (`mimes:jpeg,jpg,png\|max:10240`) | `CheckoutController::uploadPhoto()`, línea 105 |
| R-03 | El token es la credencial del flujo de checkout y está verificado en las dos entradas, con guard de estado (`409` si no está en `checkout_pending`) | `CheckoutController::uploadPhoto()` y `::submit()` |
| R-04 | Los datos de tarjeta se guardan cifrados y con `$hidden` | `CheckoutSession` (`cc_pan_encrypted`, `cc_expiry_encrypted`, `cc_holder_name_encrypted`, `cc_holder_dni_encrypted`) |
| R-05 | La vista pública de cotizaciones usa token opaco de 16 caracteres con restricción de formato, `noindex` y throttle en el CTA de escritura | `routes/web.php:140-157` |
| R-06 | `tests/TestCase.php` ya bindea stubs sin red para `Quotability`, `QuotationProvider` y `EmissionProvider`. Es el punto de anclaje natural para los fakes globales de E2 | `tests/TestCase.php:22-29` |
| R-07 | `tests/Unit` está declarado en `phpunit.xml` (no corría hasta el 2026-08-03) | `phpunit.xml` |
| R-08 | `public/build` dejó de versionarse | commit `4d4c65a` |

**Regla:** ninguna entrega de este plan puede debilitar R-01 a R-08. Si un cambio toca colas,
`WorkerConfigTest` tiene que seguir en verde sin editarlo para acomodar el cambio.

### 2.3 Condicional — requiere una confirmación acotada antes de ejecutar

| # | Ítem | Qué falta confirmar | Cómo |
|---|---|---|---|
| K-01 | Retirar `/api/tools/test`, `/api/tools/*`, `/api/v1/quotes` y `/api/quotes/{quote}/raw` | La búsqueda en `pas_mobile`, `openai-chatkit-starter-app` y `resources/js` no encontró llamadas, pero eso no prueba ausencia de consumidores externos | Revisión acotada de accesos en producción sobre una ventana definida (30 días), contando por ruta. No hace falta construir telemetría nueva |
| K-02 | Continuidad de ChatKit | `openai-chatkit-starter-app/lib/backendTools.ts` es el único consumidor de `/api/web-chat/v1/tools/*`, y la app está declarada deprecada con sunset de Agent Builder ≤ 2026-11-30 | Decisión de producto, separada de este plan. Hasta que exista, esas cinco rutas no se tocan |
| K-03 | Backfill de cifrado de settings | La migración `2026_07_20_000001_drop_poliza_api_settings` borró la única key `is_secret = true` conocida, y todo lo sembrado después es `false`. Eso no prueba el contenido de cada base desplegada | Conteo por entorno de filas con `is_secret = true`, sin leer ni mostrar valores. El backfill corre solo si el conteo es mayor que cero |
| K-04 | Versiones objetivo de dependencias | Los umbrales de la r1 salieron de un audit real pero fechado | Re-derivar con `composer audit --locked` y `npm audit` al empezar E6, adjuntando el reporte |

### 2.4 Descartado — sale del plan, con motivo

| # | Estaba en la r1 | Por qué sale |
|---|---|---|
| D-01 | Incluir `DeepSeekProbe` en la política de timeouts y reintentos, y "moverlo a una cola para no retener requests web" | Es una sonda de consola (`ai:probe-*`), nunca corre en un request web. Pega con `Http` crudo y un solo intento **a propósito**: agregarle reintentos corrompería lo que mide (latencia y tokens de un turno real) |
| D-02 | Pasar DTOs a los servicios | El `CLAUDE.md` raíz prohíbe el patrón DTO en todo el monorepo. Se pasan arrays con campos permitidos |
| D-03 | Crear un `QuoteResource` | El `CLAUDE.md` raíz excluye `JsonResource` salvo pedido explícito. La allowlist de campos se arma en el controller |
| D-04 | "Reemplazar **todos** los `$request->all()`" | De 36 ocurrencias, 20 están en `app/AI/Tools/*` donde `$request` es `Laravel\Ai\Tools\Request` —otra clase, sin `validated()`— y el `CLAUDE.md` **obliga** a entrar por `handleToolCall($request->all(), ...)`. La instrucción vale solo para `Illuminate\Http\Request` en controllers |
| D-05 | Objetivo de 256 MB para la suite | Nunca se midió. El presupuesto sale de medir, no de elegir un número |
| D-06 | Ventana dual con feature flag y monitoreo de 24-48 h para las rutas de `api.php` | Diseñado para consumidores que hay que no romper. Si K-01 confirma que no existen, la ruta se retira y no hay compatibilidad que sostener |
| D-07 | Rediseñar la política de colas | R-01: ya está implementada y verificada por test |

## 3. Estado inicial — fotografía del 2026-09-02

Esta sección es **histórica**. Documenta la línea base del diagnóstico, no el estado vigente.

### 3.1 Plataforma y verificaciones

- PHP 8.4.10, Laravel 13.3.0, PostgreSQL, Inertia v3 + Vue 3, Tailwind v4, Vite 7. 164 rutas.

| Verificación | Resultado del 2026-09-02 |
|---|---|
| `composer validate --no-check-publish` | Correcto |
| PHPStan sobre 253 archivos | Correcto, sin errores |
| Suite Pest completa | 1036 aprobadas, 4 fallidas, 3308 assertions |
| Suite con el comando por defecto | Agota 128 MB |
| Pest con 512 MB | ~140 s; fallan los 4 casos de siniestros |
| Pint | Un archivo fuera de formato *(re-verificado en r2: sigue igual)* |
| Rector dry-run | Cambios propuestos en 39 archivos |
| Vite build | Correcto con advertencias |
| Bundle JS principal | ~1,10 MB; ~287 KB gzip |
| Composer audit | 44 advisories en 13 paquetes, al menos uno crítico |
| npm audit | 22 paquetes afectados: 12 altos, 10 moderados |

### 3.2 Deuda de evidencia

Los conteos de audit de arriba son salidas reales, pero se citaron sin reporte reproducible. **La
primera tarea de E6 es adjuntar** commit, hash de ambos lockfiles, fecha e identificadores de
advisory por paquete. Sin eso no se puede saber después si un advisory se cerró o cambió de
severidad.

Versiones instaladas al corte, para poder comparar: `laravel/framework v13.3.0` (2026-04-01),
`dompdf/dompdf v3.1.5`, `mtdowling/jmespath.php 2.8.0`, `guzzlehttp/guzzle 7.10.0`.

### 3.3 Estado del árbol

El diagnóstico se hizo con cambios sin confirmar en `run.md`, `vite.config.js` y `public/build`.
**Ya no aplica:** el árbol está limpio, `main` está a la par de `origin/main` y `4d4c65a` dejó de
versionar `public/build`. Se conserva el dato porque explica por qué la r1 pedía preservar esos
archivos en cada fase.

## 4. Principios de ejecución

1. **La evidencia manda.** Un ítem entra al plan con archivo y símbolo. Lo que no se verificó va a
   *condicional*, no a *confirmado*.
2. **Retirar antes que endurecer.** Para un endpoint sin consumidor, la respuesta es borrarlo. No
   se diseñan abilities, compatibilidad dual ni monitoreo para algo que no llama nadie.
3. **Endurecer lo que existe, no reemplazarlo.** Ser público sin login no es por sí solo un
   defecto si el token es la credencial del flujo. La tarea es cerrar las brechas medidas.
4. **Las convenciones del repositorio ganan.** Sin DTOs, sin `JsonResource`, sin tocar el stack de
   identificación de vehículo, sin `window.confirm()`. Ver `CLAUDE.md` raíz y del sub-proyecto.
5. **Cambios pequeños y reversibles.** Una rama y una unidad de despliegue por entrega.
6. **Pruebas sin efectos externos.** Ninguna prueba depende de Internet ni envía mensajes, correo,
   archivos o requests reales.
7. **Validación en el borde.** Form Requests y `validated()` para entradas HTTP; a los servicios se
   les pasan arrays con campos permitidos.
8. **Observabilidad sin PII ni credenciales.** Identificadores técnicos, resultado, latencia y
   código de error. Nunca el token del flujo ni el payload completo.
9. **Sin modificar migraciones ejecutadas.** Cualquier corrección de esquema o datos va en una
   migración nueva, y el backfill va separado del DDL.

## 5. Entregas y orden

| # | Entrega | Rama | Contenido | Depende de |
|---|---|---|---|---|
| E1 | Alcance y reglas | `remediacion/alcance-evidencia` | Esta revisión, índice, roadmap, reporte de evidencia | — |
| E2 | Aislamiento de pruebas | `remediacion/test-isolation` | Fakes, `preventStrayRequests`, presupuesto de memoria medido | E1 |
| E3 | Endurecer checkout | `remediacion/checkout-hardening` | C-01 a C-05 | E2 |
| E4 | Retirar rutas huérfanas | `remediacion/retirar-rutas-huerfanas` | C-13 a C-16, sujeto a K-01/K-02 | E2, K-01 |
| E5 | Settings | `remediacion/settings-secretos` | C-06 a C-09, sujeto a K-03 | E2 |
| E6 | Dependencias | `remediacion/dependency-security` | C-18, sujeto a K-04 | E2 |
| E7 | Brechas HTTP pendientes | `remediacion/http-gaps` | Lo que quede de C-16/C-17 y timeouts sin cubrir | E2 |
| — | CI | entrega propia | Ver sección 12 | E2 |
| — | N+1 | entrega propia | Ver sección 12 | E2 |
| — | Frontend | entrega propia | Ver sección 12 | — |

E2 va primero de todo el trabajo de código: sin una suite que no salga a la red, ninguna otra
entrega se puede verificar con seguridad.

## 6. E1 — Alcance, evidencia y reglas

**Prioridad:** inmediata · **Duración:** 0,5 día

### Tareas

- Reemplazar la r1 por esta revisión *(hecho)*.
- Indexar el documento en `docs/README.md` y registrarlo en `ROADMAP.md`.
- Levantar el reporte de evidencia de 3.2: commit, hashes de lockfiles, fecha, advisories por
  paquete, y la salida de la matriz de verificación de la sección 9 asociada al commit base.
- Abrir la confirmación operativa de K-01 (conteo de accesos por ruta, ventana de 30 días).

### Criterios de aceptación

- El documento está indexado y en el roadmap.
- Existe un reporte reproducible asociado a un commit concreto.
- K-01 tiene una fecha de respuesta.

### Rollback

No aplica: no cambia comportamiento.

## 7. E2 — Aislamiento y presupuesto de pruebas

**Prioridad:** alta, bloqueante · **Duración:** 2-4 días

### Tareas

- En los cuatro casos de éxito de `SiniestroTest`, usar `Queue::fake()` o sustituir el dispatcher
  por un fake del contrato, y afirmar que se encoló el mensaje correcto. El transporte de Meta se
  cubre aparte, en el test de `WhatsAppOutboundService` con `Http::fake()`.
- Agregar `Http::preventStrayRequests()` para toda la suite. Decidir y documentar el alcance sobre
  `tests/Unit`, que hoy no está atado a `TestCase` (C-11). Colgar los fakes globales de
  `tests/TestCase.php`, junto a los stubs que ya viven ahí (R-06).
- Extender el criterio a Storage, Mail, Notifications y Events donde haya efecto externo.
- **Medir** el pico de memoria de la suite y fijar el presupuesto a partir de esa medición, con
  margen. Agregar un script Composer que lo aplique sin depender del `php.ini` local (C-12).
  No se fija un objetivo previo a la medición.
- No ejecutar suites concurrentes contra `workflow-assistant-test`: las migraciones simultáneas
  duplican tablas y tipos en PostgreSQL.

### Criterios de aceptación

- `SiniestroTest`: 6 aprobadas, 0 fallidas.
- Suite total: 0 fallos y 0 requests externos no declarados.
- Dos ejecuciones consecutivas dan el mismo resultado.
- Un test deliberado con un request no falsificado falla por `preventStrayRequests()`.
- El comando oficial termina dentro del presupuesto medido, documentado con el número medido.

### Rollback

Son cambios exclusivos de testing. Si `preventStrayRequests()` descubre demasiadas dependencias,
se activa primero por suite y se eleva a global cuando estén cubiertas; no se elimina.

## 8. E3 — Endurecer el checkout

**Prioridad:** crítica · **Duración:** 2-3 días

Es la única superficie pública con tráfico real: WhatsApp manda el link, el cliente sube siete
fotos y carga datos de tarjeta. Los controles de R-02 a R-04 ya existen; esto cierra las brechas
medidas, no los reemplaza.

### Tareas

- **C-01** Throttle en `checkout.upload-photo`, `checkout.delete-photo` y `checkout.submit`. Los
  límites se eligen por perfil de uso: subir siete fotos es una ráfaga legítima, enviar el
  formulario no.
- **C-02** Verificar que cada `photo_id` recibido pertenezca a la cotización antes de construir su
  URL. La fuente de verdad son las filas de `InspectionPhoto` de esa quote, no el string del
  cliente.
- **C-03** Lista de valores permitidos para `photo_key`, derivada de las claves de inspección que
  el flujo define. Deja de ser texto libre interpolado en un path.
- **C-04** Sacar `checkout_token` del contexto de log del `catch`. Se registra `quote_id`, clase de
  excepción, mensaje y ubicación. El `trace` va con criterio, sin el token.
- **C-05** Idempotencia de `submit()` por cotización: un segundo envío concurrente no debe duplicar
  `CheckoutSession`, correo ni transición de fotos.
- Revisar que ninguna otra ruta del flujo registre el token.

### Pruebas Pest mínimas

- Un `photo_id` de otra cotización es rechazado y no llega a `photo_paths`.
- Un `photo_key` fuera de la lista permitida devuelve `422`.
- La ráfaga supera el límite y devuelve `429` sin ejecutar el servicio.
- Dos `submit()` concurrentes producen una sola sesión y un solo correo.
- El log de un `submit()` fallido no contiene el token centinela.
- Se conserva lo que ya funciona: token inválido, estado no `checkout_pending` (`409`), MIME
  rechazado y archivo de más de 10 MB.

### Criterios de aceptación

- No se puede asociar a una cotización una foto que no es suya.
- El token no aparece en ningún log del flujo.
- Los controles preexistentes siguen probados, no solo presentes.

### Rollback

Reversible por ruta. El throttle se configura por `config()` para poder ajustarlo sin redeploy
estructural si aparecen falsos positivos.

## 9. E4 — Retirar rutas huérfanas

**Prioridad:** alta · **Duración:** 0,5-1 día · **Bloqueada por K-01**

### Tareas

- Con K-01 confirmado, retirar `POST /api/tools/test` (C-13), las seis `POST /api/tools/*` sin
  implementar (C-15), `GET /api/quotes/{quote}/raw` (C-14) y `POST /api/v1/quotes` (C-16).
- Retirar del `ToolsController` los métodos que quedan sin ruta, y su logging de request completo.
- Si K-01 devuelve tráfico real en alguna, esa ruta **no se retira**: pasa a E7 con su propio
  mecanismo de identidad, y ahí sí aplica una allowlist de campos armada en el controller (D-03).
- `/api/web-chat/v1/tools/*` **no se toca** hasta que K-02 tenga respuesta.
- Reemplazar el chequeo negativo `! app()->isProduction()` de `/api/dev/*` por una lista positiva
  de entornos, y agregar un test de que ninguna ruta `api/dev/*` se registra bajo
  `APP_ENV=production`.

### Criterios de aceptación

- El inventario de rutas coincide con la matriz de la sección 2.
- No queda ninguna tool mutante accesible de forma anónima.
- No se puede obtener `raw_response` con IDs consecutivos.
- Existe un test que falla si `api/dev/*` aparece en producción.

### Rollback

`git revert` de la entrega. No hay migración de datos ni consumidores que reconfigurar — ése es
justamente el resultado de K-01.

## 10. E5 — Settings: lectura, escritura y secretos

**Prioridad:** alta · **Duración:** 1-2 días

La r1 dimensionaba esto como una migración de datos riesgosa. No lo es: el defecto está repartido
entre lectura, escritura y caché, y el backfill es condicional (K-03). Pero es más que
presentación, y **C-07 es un bug activo que hoy borra secretos**.

### Tareas

- **C-07 primero.** `updateGroup()` tiene que sacar la key de `$incoming` —no solo saltear su
  validación— cuando el setting es secreto y llega vacío. Es una pérdida de datos silenciosa.
- **C-06** Dejar de enviar `value` de los settings secretos en el payload de Inertia. La vista
  recibe si hay valor cargado y cuándo se actualizó, no el valor. `$hidden` en el modelo se agrega
  como red, sabiendo que **no impide** un `'value' => $s->value` escrito a mano.
- **C-08** El cifrado tiene que aplicarse en el camino de escritura real. Con `where()->update()`
  de query builder no corren casts ni mutators: o el guardado pasa por instancias del modelo, o el
  cifrado se hace explícito dentro de `saveGroup()`. Elegir uno y dejarlo escrito.
- **C-09** Decidir qué pasa con la caché: o los secretos no entran a `loadAll()`, o entran
  cifrados y se descifran en el punto de uso. Hoy quedan una hora en la tabla `cache`.
- **K-03** Comando de conteo por entorno de filas con `is_secret = true`. Reporta cantidad, nunca
  valores. El backfill se ejecuta solo donde el conteo sea mayor que cero, es idempotente y va en
  un comando aparte, no dentro de una migración DDL.
- Documentar que rotar `APP_KEY` obliga a recifrar.

### Pruebas Pest mínimas

- Guardar el grupo con el campo secreto vacío **conserva** el valor anterior (test de C-07).
- Guardar el grupo con un valor nuevo lo reemplaza y la integración lo recibe utilizable.
- La respuesta de Inertia de la vista de settings no contiene el valor de ningún secreto.
- `toArray()` / JSON del modelo nunca incluye `value`.
- Un secreto guardado no queda en texto plano en la base.
- Un valor no secreto conserva su comportamiento exacto.
- El comando de conteo no emite valores.
- El backfill es idempotente.

### Criterios de aceptación

- El bug de borrado silencioso tiene test de regresión.
- Ningún secreto sale hacia el navegador ni queda en texto plano en base ni en caché.
- El conteo por entorno está ejecutado y registrado antes de cualquier backfill.

### Rollback

Durante la ventana de lectura compatible, el artefacto anterior no puede desplegarse después de
cifrar si no sabe leer lo cifrado: el rollback incluye el artefacto compatible, no solo el revert
del código. Nunca se descifra en masa a texto plano como maniobra de rollback.

## 11. E6 — Actualización de dependencias

**Prioridad:** crítica · **Duración:** 3-6 días · **Depende de E2**

### Tareas

1. Adjuntar el reporte reproducible de 3.2 con el estado **al implementar**, no el del corte.
2. `composer why` y `composer prohibits` antes de tocar cada constraint.
3. Actualizar por lotes: primero parches y minors dentro de los constraints vigentes, con lista
   explícita; `--with-all-dependencies` solo cuando haga falta.
4. Paquetes bajo observación al corte: `laravel/framework`, `mtdowling/jmespath.php`,
   `dompdf/dompdf`, `guzzlehttp/guzzle` y `psr7`, `league/commonmark`, y componentes Symfony
   (HttpFoundation, HttpKernel, Mailer, Mime, Routing, YAML, polyfill IDN). Los umbrales de versión
   se re-derivan con el audit del día (K-04).
5. Verificar con tests específicos: generación de PDF, clientes HTTP, Markdown, correo, URLs
   firmadas y routing.
6. En JavaScript: confirmar si `axios` sigue teniendo imports reales —Inertia v3 lo eliminó como
   cliente implícito— y retirarlo si no. Actualizar Vite y su ecosistema; validar el dev server en
   Windows. `npm audit fix --dry-run` antes de cualquier automatismo; nunca `--force` sin revisar
   el cambio mayor de `shadcn-vue`.
7. Resolver el árbol transitivo actualizando el paquete padre. Overrides solo como mitigación
   temporal documentada.
8. No silenciar un advisory sin alcance, mitigación, responsable y fecha de expiración.

### Verificación por lote

```powershell
composer validate --no-check-publish
composer audit --locked
php artisan test --compact
composer analyse
composer lint
npm audit --omit=dev
npm run build
```

### Criterios de aceptación

- Cero vulnerabilidades críticas y altas en dependencias de producción.
- Las moderadas restantes tienen evaluación de aplicabilidad y vencimiento.
- Lockfiles reproducibles y reporte adjunto.

### Rollback

Lockfiles anteriores conservados para rollback atómico. PHP y JavaScript se separan si alguno
exige cambios de código. Redeploy del artefacto previo; nunca downgrade destructivo de base.

## 12. E7 y entregas independientes

### E7 — Brechas HTTP operativas pendientes

Solo lo que quede sin cubrir después de E3/E4: `connectTimeout` y `timeout` explícitos por
integración (Visred, WhatsApp, AFIP), reintentos únicamente sobre errores transitorios, sin
reintentar mutaciones no idempotentes sin clave de idempotencia, y redacción de tokens en el
contexto de error. **`DeepSeekProbe` queda fuera** (D-01). Los presupuestos de cola no se tocan
(R-01).

### CI — entrega propia

No existe `.github/workflows/`. Es infraestructura nueva —runner, servicio PostgreSQL, secretos—
con costo propio, y en la r1 estaba escondida dentro de la fase de tests. Pipeline mínimo:
dependencias desde lockfiles, `composer validate`, PostgreSQL aislado, PHPStan, Pint en modo test,
Pest con requests externos bloqueados, audit, build, y conservación de reportes y tiempos.

### Detección de N+1 — entrega propia

`Model::preventLazyLoading()` no está hoy en `app/Providers/`. Activarlo en no-producción convierte
cada carga perezosa en excepción sobre una suite de 1036 tests: el costo es desconocido hasta que
se prende. Se activa, se mide cuántos casos aparecen, y recién ahí se planifica. No se desactiva
globalmente ante el primer fallo.

### Frontend — entrega propia

Corregir el selector CSS inválido de `[data-theme="dark"]` combinado con `@media` y la referencia
sin resolver `...chevron...`. Objetivo: cero advertencias propias en `npm run build`. El code
splitting se decide con medición antes y después; se documenta una excepción si el chunk inicial
no baja de 500 KB minificado. Rector queda acá, por lotes de una regla, nunca los 39 archivos
juntos, y separado de los fixes funcionales para poder revertirlo solo.

## 13. Matriz global de verificación

```powershell
composer validate --no-check-publish
php artisan route:list --except-vendor
php artisan migrate:status
composer analyse
composer lint
php artisan test --compact
composer refactor:dry
composer audit --locked
npm audit --omit=dev
npm run build
git status --short --branch
```

Para cambios PHP, antes de cerrar: `vendor/bin/pint --dirty --format agent`.

El entorno de pruebas debe impedir requests externos. Los audits necesitan acceso de red de solo
lectura a Packagist y npm.

## 14. Smoke tests de staging

Después de cada despliegue relevante:

1. autenticación web y móvil;
2. checkout completo: apertura por token, subida de las siete fotos, envío y correo;
3. rechazo de `photo_id` ajeno y de `photo_key` no permitido;
4. ráfaga que dispara `429` en las rutas de checkout;
5. flujo de siniestro encolado, sin envío duplicado;
6. webhook de WhatsApp válido e inválido;
7. emisión y generación de PDF con fixture seguro;
8. llamada controlada a Visred/AFIP en sandbox;
9. actualización de un setting secreto: se guarda, y guardarlo vacío no lo borra;
10. landing, chat y panel administrativo sin errores de consola;
11. procesamiento de colas y tareas programadas;
12. revisión de logs: sin secretos, sin tokens de checkout, sin PII centinela.

## 15. Observabilidad

Medir 48 horas después de E3, E4, E5 y E6:

- tasa de `401`, `403`, `422`, `429` y `5xx` por ruta, con foco en las de checkout;
- latencia p50/p95/p99 de checkout, cotización e integraciones;
- reintentos y fallos finales por proveedor;
- tiempo y memoria máxima de la suite;
- duración y tamaño del build, y tamaño del chunk inicial;
- fallos y duración de jobs por cola;
- conteo de secretos legacy pendientes, sin registrar valores;
- advisories abiertos por severidad y fecha objetivo.

Alertas mínimas: `429` que afecte clientes legítimos en el checkout; aumento de `5xx` en
`checkout.submit`; reintentos sostenidos contra un proveedor; advisory crítico o alto nuevo en los
lockfiles; request externo durante los tests; fallo del backfill.

## 16. Riesgos

| Riesgo | Probabilidad/impacto | Mitigación |
|---|---|---|
| El throttle del checkout corta una ráfaga legítima de siete fotos | Media/Alta | Límite por perfil de uso, configurable por `config()`, observado 48 h |
| Retirar una ruta con un consumidor externo desconocido | Baja/Alta | K-01 antes de E4; si aparece tráfico, la ruta pasa a E7 en vez de retirarse |
| El fix de C-07 no cubre otro camino de guardado | Media/Alta | Test de regresión sobre el comportamiento, no sobre la implementación |
| Cifrar deja settings ilegibles | Baja/Alta | K-03 primero: si el conteo es cero no hay backfill; backup y lectura compatible si no lo es |
| Actualización amplia de dependencias | Media/Alta | Lotes pequeños, lockfiles conservados, suite estable (E2) antes de empezar |
| Envíos reales desde tests | Alta/Alta | `preventStrayRequests` y fakes globales en E2, que va primero |
| `preventLazyLoading` destapa más de lo previsto | Alta/Media | Entrega propia, medir antes de planificar |
| Rector cambia semántica | Media/Media | Lotes de una regla, revisión manual, separado de fixes funcionales |

## 17. Definición de terminado

Con evidencia adjunta al último PR o release:

- matriz de la sección 2 con cada ítem cerrado o justificado;
- inventario final de rutas y controles;
- salida exitosa de Pest, PHPStan, Pint y build, con el presupuesto de memoria medido;
- reporte de audits reproducible, sin críticos ni altos de producción, o excepciones aceptadas con
  vencimiento;
- prueba de que los tests bloquean tráfico externo;
- reporte del conteo de secretos por entorno, con conteos y no valores;
- `WorkerConfigTest` en verde sin haber sido editado;
- métricas de staging y producción dentro de los umbrales;
- rollback probado para dependencias y settings;
- `CHANGELOG.md` actualizado en español al preparar el release.
