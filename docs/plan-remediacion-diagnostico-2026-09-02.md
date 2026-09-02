# Plan de remediación del diagnóstico técnico

**Proyecto:** Workflow Assistant / PAS Mobile  
**Fecha de corte:** 2026-09-02  
**Estado del documento:** Propuesta ejecutable  
**Alcance:** Backend Laravel, API, integraciones, pruebas, dependencias PHP/JavaScript, build frontend y deuda técnica detectada.  
**Fuera de alcance:** Nuevas funcionalidades de negocio, rediseño visual y cambios de dominio no necesarios para corregir los hallazgos.

## 1. Objetivo

Reducir la superficie de ataque, recuperar una suite de pruebas determinista, actualizar las dependencias vulnerables y mejorar la resiliencia operativa sin interrumpir los consumidores actuales del sistema.

El trabajo se considera terminado cuando:

- ningún endpoint mutante o de diagnóstico queda expuesto sin un mecanismo de confianza explícito;
- las respuestas de cotizaciones no exponen payloads internos a consumidores no autorizados;
- no se registran requests completos que puedan contener PII, tokens o datos de pago;
- Composer y npm no reportan vulnerabilidades críticas o altas explotables en producción;
- la suite completa termina sin conexiones externas, con `0 failed` y dentro de un límite de memoria documentado;
- PHPStan, Pint, Rector y el build frontend terminan correctamente;
- los secretos configurables quedan cifrados en reposo y no se serializan;
- los clientes HTTP tienen timeouts, política de reintentos y pruebas de error consistentes;
- cada cambio puede desplegarse y revertirse de manera controlada.

## 2. Estado inicial verificado

### Plataforma

- PHP 8.4.10.
- Laravel 13.3.0.
- PostgreSQL.
- Inertia Laravel v3, Vue 3 e Inertia Vue v2.
- Tailwind CSS v4 y Vite 7.
- 164 rutas registradas.
- Todas las migraciones locales estaban aplicadas al momento del diagnóstico.

### Verificaciones iniciales

| Verificación | Resultado inicial |
|---|---|
| `composer validate --no-check-publish` | Correcto |
| PHPStan sobre 253 archivos | Correcto, sin errores |
| Suite Pest completa | 1036 aprobadas, 4 fallidas, 3308 assertions |
| Suite con comando normal | Agota el límite de 128 MB |
| Pest con 512 MB | Completa en ~140 s; fallan 4 casos de siniestros |
| Pint | Un archivo fuera de formato |
| Rector dry-run | Propone cambios en 39 archivos |
| Vite build | Correcto con advertencias |
| Bundle JS principal | ~1,10 MB; ~287 KB gzip |
| Composer audit | 44 advisories en 13 paquetes; al menos uno crítico |
| npm audit | 22 paquetes afectados: 12 altos y 10 moderados |

### Restricción de trabajo

El repositorio ya contenía cambios sin confirmar en `run.md`, `vite.config.js` y artefactos de `public/build`. Cada fase debe comenzar con `git status --short --branch`; no se deben mezclar ni sobrescribir esos cambios.

## 3. Principios de ejecución

1. **Seguridad antes que refactorización.** Cerrar exposición y actualizar componentes vulnerables antes de aplicar cambios cosméticos de Rector.
2. **Compatibilidad explícita.** Antes de proteger una ruta, inventariar su consumidor, credencial disponible y contrato de error esperado.
3. **Cambios pequeños y reversibles.** Una rama y una unidad de despliegue por fase; evitar un único PR con seguridad, dependencias y refactors mezclados.
4. **Pruebas sin efectos externos.** Ninguna prueba puede depender de Internet o enviar mensajes, correo, archivos o solicitudes reales.
5. **Validación en el borde.** Usar Form Requests y `$request->validated()`; no transportar `$request->all()` hacia servicios.
6. **Secretos por diseño.** No registrar, serializar ni devolver secretos; cifrarlos en reposo.
7. **Observabilidad sin PII.** Registrar identificadores técnicos, resultado, latencia y código de error, no payloads completos.
8. **Sin modificar migraciones ejecutadas.** Cualquier corrección de esquema o datos se hará mediante una migración nueva.

## 4. Estrategia de ramas y entregas

Crear las ramas desde una base limpia y actualizada, conservando los cambios actuales fuera de los PR de remediación.

| Entrega | Rama sugerida | Contenido |
|---|---|---|
| E1 | `codex/security-api-boundaries` | Rutas, autenticación, autorización, throttling y respuestas públicas |
| E2 | `codex/request-validation-redaction` | Form Requests, DTOs/payloads validados y logging seguro |
| E3 | `codex/test-isolation` | Fakes globales, tests de siniestro, memoria y CI |
| E4 | `codex/dependency-security` | Actualización Composer/npm y regresión |
| E5 | `codex/encrypted-settings` | Cifrado y rotación de secretos |
| E6 | `codex/http-resilience` | Timeouts, retry, errores y métricas |
| E7 | `codex/quality-performance` | Pint, Rector seleccionado, N+1 y bundle frontend |
| E8 | `codex/migration-governance` | Reglas futuras para DDL/DML y despliegues |

No avanzar a E4 mientras E3 no garantice una suite confiable. No desplegar E5 sin backup y procedimiento de rollback probado.

## 5. Fase 0 — Preparación y línea base

**Prioridad:** inmediata  
**Duración estimada:** 0,5–1 día  
**Dependencias:** ninguna

### Tareas

- Capturar `git status`, commit base, versiones de PHP/Node/Composer/npm y hashes de ambos lockfiles.
- Identificar propietarios y consumidores de cada ruta de `routes/api.php`:
  - frontend web/Inertia;
  - chat web;
  - agentes o tools internos;
  - aplicación móvil;
  - integraciones externas;
  - endpoints obsoletos o de prueba.
- Confirmar entornos y dominios donde se encuentran accesibles `/api/tools/test`, `/api/dev/*`, `/api/v1/quotes`, `/api/web-chat/v1/tools/*`, `/api/tools/*` y `/api/quotes/{quote}/raw`.
- Recolectar métricas de uso de esas rutas sin almacenar payloads: cantidad, caller conocido, status y última utilización.
- Definir para cada consumidor uno de estos mecanismos:
  - sesión/autenticación web;
  - token Sanctum con abilities;
  - firma HMAC con timestamp y protección contra replay;
  - red interna/mTLS en infraestructura;
  - eliminación si no tiene consumidor vigente.
- Ejecutar y conservar la línea base de las verificaciones de la sección 12.

### Decisión obligatoria

No asumir que todas las tools pueden usar el mismo guard. El cierre de rutas exige aprobar una matriz **ruta → consumidor → identidad → permiso → rate limit**. Si un consumidor no puede autenticarse todavía, aplicar temporalmente una firma HMAC o deshabilitar la ruta; no dejarla pública como compatibilidad implícita.

### Criterios de aceptación

- Existe una matriz aprobada para todas las rutas señaladas.
- Se conoce qué endpoints pueden eliminarse.
- Hay un resultado reproducible de pruebas, audits y build asociado al commit base.

### Rollback

No aplica: esta fase es de inventario y no cambia comportamiento.

## 6. Fase 1 — Cerrar la superficie pública de API

**Prioridad:** crítica  
**Duración estimada:** 2–4 días  
**Dependencias:** Fase 0

### 6.1 Rutas de cotización y tools

Archivos principales:

- `routes/api.php`.
- `app/Http/Controllers/QuoteController.php`.
- `app/Http/Controllers/ToolsController.php`.
- `app/Services/QuoteService.php`.
- `bootstrap/app.php` si hace falta registrar middleware o normalizar errores.

Acciones:

1. Agrupar `/api/v1/quotes`, `/api/web-chat/v1/tools/*` y `/api/tools/*` bajo el middleware aprobado en la matriz.
2. Definir abilities de Sanctum por capacidad, por ejemplo `quotes:create`, `tools:execute` y `quotes:raw:read`, evitando tokens con `*` para integraciones.
3. Agregar rate limiters nombrados por identidad autenticada y fallback por IP. Separar límites de lectura, cotización costosa y mutaciones.
4. Mantener el webhook de WhatsApp fuera de Sanctum, pero conservar la verificación HMAC existente y agregar tests de firma inválida, timestamp/replay si el proveedor lo permite y límites de tamaño.
5. Retirar o deshabilitar las tools marcadas como “sin implementar” si no tienen consumidor vigente. Una respuesta `404` es preferible a un stub mutante público.
6. Establecer respuestas JSON uniformes para `401`, `403`, `404`, `422` y `429`, sin trazas ni detalles internos.

### 6.2 Respuesta raw de cotizaciones

El endpoint `GET /api/quotes/{quote}/raw` no debe usar un ID enumerable como única barrera.

Acciones:

- Preferencia: convertirlo en endpoint exclusivamente administrativo/interno con autorización explícita.
- Si debe existir una vista pública, exponer un recurso específico mediante token aleatorio no enumerable y expiración; no reutilizar el payload raw.
- Crear un `QuoteResource`/recurso dedicado con allowlist de campos.
- Excluir siempre `raw_response`, referencias del proveedor, tokens internos, errores con stack trace y metadata no contractual.
- Evitar serializar relaciones completas con `toArray()`.

### 6.3 Endpoints de prueba y desarrollo

- Eliminar `/api/tools/test` o limitarlo a testing/local más autenticación administrativa.
- Reemplazar el chequeo negativo `! app()->isProduction()` de `/api/dev/*` por una allowlist positiva de entornos (`local`, y solo si se aprueba `testing`).
- Agregar una segunda barrera para operaciones destructivas: autorización administrativa y feature flag desactivado por defecto.
- En producción, comprobar mediante test que ninguna ruta `api/dev/*` se registra.

### Pruebas Pest mínimas

- Cada ruta sensible rechaza requests anónimos.
- Cada ability incorrecta devuelve `403`.
- Los límites devuelven `429` sin ejecutar el servicio.
- El recurso público de quote no contiene campos internos.
- Un usuario/token no puede leer una quote ajena.
- Los endpoints de desarrollo no existen bajo `APP_ENV=production`.
- El webhook válido funciona y una firma inválida falla sin procesar el mensaje.

### Criterios de aceptación

- No quedan tools mutantes accesibles anónimamente.
- No se puede obtener `raw_response` usando IDs consecutivos.
- El inventario de rutas coincide con la matriz aprobada.
- Logs y métricas no revelan credenciales durante pruebas de autorización.

### Despliegue y rollback

- Emitir credenciales/abilities antes de activar la protección.
- Desplegar consumidores compatibles antes o junto con el backend.
- Monitorear `401`, `403` y `429` por ruta durante 24–48 horas.
- Rollback preferido: feature flag temporal que acepte ambos mecanismos durante una ventana corta y registrada. No reabrir indefinidamente el acceso anónimo.

## 7. Fase 2 — Validación, autorización y logging seguro

**Prioridad:** alta  
**Duración estimada:** 3–5 días  
**Dependencias:** Fase 1

### 7.1 Form Requests y payloads

- Crear Form Requests mediante `php artisan make:request --no-interaction` para cada contrato distinto de Quote y Tools.
- Definir `authorize()` coherente con abilities/policies de la Fase 1.
- Usar reglas en arrays, límites máximos, enums/allowlists y validación de arrays anidados.
- Reemplazar todos los `$request->all()` por `$request->validated()`.
- Pasar a servicios arrays con shape documentado o DTOs existentes; no pasar objetos Request a la capa de dominio.
- Validar especialmente URLs, IDs externos, texto libre, archivos, MIME real, tamaño y cantidad de elementos.
- Migrar gradualmente las validaciones inline detectadas en:
  - `CoverageDocumentController`;
  - `PolizaController`;
  - `CheckoutController`;
  - `Mobile/EmergencyController`;
  - `Admin/StudioController`.

### 7.2 Autorización por recurso

- Inventariar mutaciones administrativas y de objetos de usuario.
- Crear o completar Policies para Quote, Customer, Poliza, documentos y recursos compartidos.
- Aplicar autorización antes de cargar datos sensibles o ejecutar side effects.
- Añadir tests de acceso cruzado entre dos usuarios/PAS y tests por rol.

### 7.3 Redacción de logs

- Sustituir el log del request completo por un contexto allowlisted: request/correlation ID, nombre de operación, actor, entidad, duración y resultado.
- Redactar claves con nombres como `token`, `authorization`, `secret`, `password`, `card`, `cbu`, `documento`, `phone`, `email` y equivalentes anidados.
- No registrar cuerpos crudos de proveedor salvo en un almacén cifrado, de acceso restringido, con TTL y finalidad aprobada.
- Definir retención y acceso para logs que contengan identificadores de cliente.

### Pruebas Pest mínimas

- Dataset por campo inválido y límite de tamaño.
- Campos no declarados son ignorados y no llegan al servicio.
- Payloads anidados malformados retornan `422`.
- Policies rechazan acceso cruzado.
- Logs de errores no contienen valores secretos de un payload centinela.

### Criterios de aceptación

- No hay `$request->all()` en los controllers de Quote/Tools.
- Toda mutación tiene validación y autorización explícitas.
- No aparecen PII ni tokens centinela al inspeccionar logs de pruebas.

### Rollback

- Los Form Requests pueden revertirse por endpoint sin tocar persistencia.
- Mantener temporalmente compatibilidad de nombres mediante normalización previa validada, con fecha de eliminación; no aceptar campos arbitrarios.

## 8. Fase 3 — Aislamiento y confiabilidad de pruebas

**Prioridad:** alta y bloqueante  
**Duración estimada:** 2–4 días  
**Dependencias:** puede comenzar junto con Fase 1; debe terminar antes de actualizar dependencias

### 8.1 Evitar tráfico externo

Archivos principales:

- `tests/Pest.php`.
- `tests/Feature/Mobile/SiniestroTest.php`.
- `app/Services/WhatsApp/CloudApiWhatsAppDispatcher.php`.
- `app/Jobs/SendWhatsAppTemplate.php`.
- tests de otras integraciones HTTP.

Acciones:

- En los cuatro casos de éxito de `SiniestroTest`, usar `Queue::fake()` o sustituir el dispatcher por un fake del contrato y afirmar que se encoló el mensaje correcto.
- No probar el transporte de Meta en el test HTTP del controller; cubrir `WhatsAppOutboundService` por separado con `Http::fake()`.
- Agregar `Http::preventStrayRequests()` globalmente para tests, habilitando únicamente URLs falsificadas de forma explícita.
- Aplicar equivalentes para Storage, Mail, Notifications, Events y colas donde exista side effect.
- Verificar que los fakes se activen después de crear fixtures cuando listeners de modelo sean parte del setup.

### 8.2 Memoria y base de datos

- Perfilar la suite para identificar crecimiento de contenedor, listeners, mocks y datasets retenidos.
- Ejecutar Unit y Feature secuencialmente contra la misma base, o asignar bases aisladas por proceso si se habilita paralelismo.
- No ejecutar suites concurrentes contra `workflow-assistant-test`: las migraciones simultáneas producen tablas/tipos duplicados en PostgreSQL.
- Evaluar `LazilyRefreshDatabase` donde respete el aislamiento existente.
- Definir un límite explícito de memoria para CI. Objetivo: completar con 256 MB; aceptación inicial: 512 MB documentados mientras se corrige el crecimiento.
- Añadir un script Composer estable, por ejemplo uno que invoque Pest con la configuración de memoria acordada, sin depender del `php.ini` local.

### 8.3 CI

Pipeline mínimo:

1. instalar dependencias desde lockfiles;
2. validar Composer;
3. preparar PostgreSQL de testing aislado;
4. ejecutar PHPStan;
5. ejecutar Pint en modo test;
6. ejecutar Pest con stray requests bloqueados;
7. ejecutar audit de dependencias;
8. ejecutar build frontend;
9. conservar reportes y tiempos.

### Criterios de aceptación

- `SiniestroTest.php`: 6 aprobadas, 0 fallidas.
- Suite total: 0 fallos y 0 requests externos no declarados.
- Dos ejecuciones consecutivas producen el mismo resultado.
- El comando oficial no agota memoria.
- Un test deliberado de request no falsificado falla inmediatamente por `preventStrayRequests()`.

### Rollback

- Los fakes son cambios exclusivos de testing.
- Si `preventStrayRequests()` descubre demasiadas dependencias, activarlo primero por suite y elevarlo a global una vez cubiertas; no eliminarlo como solución definitiva.

## 9. Fase 4 — Actualización segura de dependencias

**Prioridad:** crítica  
**Duración estimada:** 3–6 días  
**Dependencias:** Fase 3

### 9.1 PHP/Composer

Paquetes prioritarios detectados:

- `laravel/framework`;
- `mtdowling/jmespath.php`;
- `dompdf/dompdf`;
- `guzzlehttp/guzzle` y `guzzlehttp/psr7`;
- `league/commonmark`;
- componentes Symfony afectados: HttpFoundation, HttpKernel, Mailer, Mime, Routing, YAML y polyfill IDN.

Acciones:

1. Ejecutar `composer why <paquete>` y `composer prohibits <paquete> <versión-segura>` para conocer restricciones.
2. Actualizar primero parches/minors dentro de los constraints existentes usando una lista explícita y `--with-all-dependencies` solo cuando sea necesario.
3. Subir Laravel al menos a una versión no afectada por el advisory identificado (`>=13.12.0`, sujeto a confirmar el advisory vigente al implementar).
4. Actualizar JMESPath a una versión corregida (`>=2.9.1`, sujeto a confirmación del advisory).
5. Actualizar Dompdf al menos a la versión corregida por los advisories detectados (`>=3.1.6`, sujeto a confirmación).
6. Verificar generación de PDFs, HTTP clients, Markdown, correo, URLs firmadas y routing con tests específicos.
7. No silenciar advisories salvo justificación documentada con alcance, mitigación, propietario y fecha de expiración.

### 9.2 JavaScript/npm

Dependencias directas afectadas detectadas: `axios`, `vite` y `shadcn-vue`; también existen vulnerabilidades transitivas en Rollup, Hono, Undici, PostCSS, Lodash ES y otras.

Acciones:

- Confirmar si Axios sigue siendo necesario. Inertia v3 eliminó su necesidad como cliente implícito; si no hay imports reales, retirarlo.
- Si se usa, actualizarlo a una versión corregida y probar interceptores/requests.
- Actualizar Vite y su ecosistema compatible; validar especialmente el dev server en Windows.
- Ejecutar `npm audit fix --dry-run` antes de cualquier actualización automática.
- No usar `npm audit fix --force` sin revisar el cambio mayor propuesto para `shadcn-vue`.
- Resolver el árbol transitivo actualizando el paquete padre; evitar overrides permanentes salvo mitigación temporal documentada.

### Verificación obligatoria por lote

```text
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
- Las moderadas restantes, si existen, tienen evaluación de aplicabilidad y ticket con vencimiento.
- Lockfiles actualizados y reproducibles.
- Suite, análisis estático y build pasan.

### Despliegue y rollback

- Separar actualización PHP y JavaScript si alguna exige cambios de código.
- Conservar lockfiles anteriores para rollback atómico.
- Desplegar a staging, ejecutar smoke tests y observar errores 5xx, colas, PDFs y clientes HTTP.
- Rollback mediante redeploy del artefacto y lockfiles previos; no ejecutar downgrade destructivo de base de datos.

## 10. Fase 5 — Cifrado de configuraciones secretas

**Prioridad:** alta  
**Duración estimada:** 2–4 días  
**Dependencias:** backup verificado y diseño de rotación

Archivos principales:

- `app/Models/SystemSetting.php`.
- `app/Services/SettingsService.php`.
- controller y página administrativa de settings.
- nueva migración/comando de backfill si existen valores secretos.

### Diseño

- Añadir `value` a `$hidden` para impedir serialización accidental.
- No aplicar un cast `encrypted` indiscriminado a filas no secretas si la tabla mezcla ambos tipos.
- Preferir un accessor/mutator o servicio de repositorio que cifre únicamente cuando `is_secret=true` y descifre en lectura autorizada.
- Mantener en la UI el patrón “vacío significa conservar el secreto actual”; nunca devolver el valor descifrado al navegador.
- Usar `APP_KEY`/encrypter de Laravel y documentar que una rotación de clave necesita recifrado.

### Migración de datos

1. Backup y prueba de restauración.
2. Detectar filas secretas y formato actual.
3. Implementar lectura compatible temporal: reconocer cifrado vs. legado plano.
4. Ejecutar un comando idempotente, por chunks, que cifre únicamente valores legados.
5. Verificar conteos y capacidad de uso de cada integración.
6. Retirar la lectura legacy en una entrega posterior.

No incluir el backfill dentro de una migración DDL de larga duración.

### Pruebas Pest mínimas

- Un secreto nuevo no aparece en texto plano en DB.
- Un valor no secreto conserva su comportamiento.
- `toArray()`/JSON nunca incluye `value`.
- Dejar el campo vacío no borra el secreto.
- La actualización reemplaza el secreto y la integración recibe el valor descifrado.
- El comando de backfill es idempotente.

### Criterios de aceptación

- Cero valores secretos nuevos en texto plano.
- Cero secretos en respuestas Inertia/API o logs.
- Todos los valores legados han sido cifrados y verificados.

### Rollback

- Mantener backup cifrado y acceso restringido.
- Durante la ventana compatible, el código anterior no debe desplegarse después de cifrar datos si no puede leerlos. El rollback debe incluir el artefacto compatible, no solo revertir código.
- Nunca descifrar masivamente a texto plano como rollback operativo.

## 11. Fase 6 — Resiliencia de clientes HTTP

**Prioridad:** media  
**Duración estimada:** 3–5 días  
**Dependencias:** suite aislada

Clientes detectados:

- VisredClient y VisredDocumentService.
- WhatsAppAdapter y WhatsAppOutboundService.
- AfipSoapService.
- DeepSeekProbe.

### Política común

- Definir `connectTimeout` corto y `timeout` total por integración/configuración.
- Reintentar solo errores transitorios: conexión, `408`, `429` y `5xx` seleccionados.
- No reintentar mutaciones no idempotentes sin idempotency key o garantía del proveedor.
- Usar backoff exponencial con jitter y respetar `Retry-After` cuando exista.
- Llamar `throw()` o mapear status a excepciones de dominio; no devolver éxitos parciales silenciosos.
- Redactar tokens y payloads en contexto de errores.
- Registrar integración, operación, intento, latencia, status y correlation ID.
- Alinear timeout de jobs y `retry_after`: el segundo debe superar al timeout del job.

### Caso especial DeepSeek

Revisar el timeout total de 400 segundos. Si es realmente necesario, mover la operación a una cola específica con límites coherentes, cancelación y alerta; no retener requests web.

### Pruebas Pest mínimas

- Respuesta exitosa.
- Timeout de conexión.
- Timeout total.
- `429` con reintento.
- `500/503` con número exacto de intentos.
- `400/401/403/422` sin reintento.
- Mutación no idempotente sin duplicación.
- Excepción final sin token ni PII.

### Criterios de aceptación

- Todo request HTTP tiene `connectTimeout` y `timeout` explícitos.
- La política de retry está probada y documentada por operación.
- Los workers no quedan retenidos más allá del presupuesto definido.

### Rollback

- Configurar timeouts y retries mediante `config()` para poder ajustar valores sin cambios estructurales.
- Revertir por integración si aumentan falsos timeouts; conservar límites máximos seguros.

## 12. Fase 7 — Calidad, rendimiento y frontend

**Prioridad:** media/baja  
**Duración estimada:** 3–6 días  
**Dependencias:** fases críticas estabilizadas

### 12.1 Pint y Rector

- Corregir el orden de imports/strict types de `tests/Feature/ProcessMediaAttachmentTest.php` ejecutando Pint.
- Ejecutar `vendor/bin/pint --dirty --format agent` después de cada lote PHP.
- Revisar los 39 cambios propuestos por Rector por categorías pequeñas:
  - tipos de retorno/parámetros;
  - código muerto;
  - early returns;
  - readonly;
  - transformaciones potencialmente semánticas.
- No aplicar los 39 cambios en bloque. Cada lote requiere tests focalizados y suite completa.
- Tratar con especial cautela transformaciones que cambian condiciones, concatenación nullable, `property_exists` o tipos concretos.

### 12.2 Detección de N+1

- Activar `Model::preventLazyLoading(! app()->isProduction())` en `AppServiceProvider`.
- Ejecutar tests y recorridos de pantallas administrativas para descubrir accesos perezosos.
- Corregir cada caso con eager loading/selects mínimos; no desactivar globalmente la protección ante el primer fallo.
- Agregar tests de conteo de consultas solo en recorridos críticos y estables.

### 12.3 Build frontend

- Localizar y corregir el selector CSS inválido asociado a la combinación de `[data-theme="dark"]` con `@media`.
- Localizar la referencia literal/no resuelta `...chevron...` y convertirla en un asset importado o URL válida.
- Analizar el bundle con una herramienta de visualización de Rollup de uso temporal o aprobada; no sumar dependencia permanente sin autorización.
- Aplicar imports dinámicos a páginas/rutas administrativas pesadas y librerías usadas en una sola pantalla.
- Evitar dividir dependencias pequeñas si aumenta requests sin reducir carga inicial materialmente.
- Mantener compatibilidad con Inertia/Vue y estados de carga cuando una página se vuelva lazy.

### Objetivos medibles

- Cero warnings propios del proyecto en `npm run build`.
- Reducir el chunk inicial por debajo de 500 KB minificado, o documentar una excepción respaldada por medición de carga.
- Mantener o mejorar el tamaño gzip actual.
- Smoke tests sin errores de JavaScript en landing, login, chat y principales páginas administrativas.

### Rollback

- Revertir individualmente lazy imports o manual chunks si empeoran navegación/caché.
- Los cambios de Rector deben estar separados para poder revertirlos sin perder fixes funcionales.

## 13. Fase 8 — Login móvil, migraciones y gobierno técnico

**Prioridad:** media/baja  
**Duración estimada:** 1–3 días  
**Dependencias:** ninguna estricta; desplegar después de las fases críticas

### Login móvil

- Agregar rate limiter nombrado a `POST /api/mobile/v1/auth/session`.
- Clave por combinación prudente de IP y fingerprint/identificador no sensible cuando esté disponible.
- Devolver `429` uniforme y observar falsos positivos.
- Registrar solo identificadores irreversibles/hashes, nunca Firebase ID tokens.
- Probar éxito, token inválido, ráfaga y recuperación después de la ventana.

### Migraciones futuras

Las migraciones históricas con DDL+DML no deben editarse si ya se ejecutaron. Adoptar desde ahora:

- una migración de esquema por preocupación;
- backfills mediante comandos/jobs idempotentes y observables;
- procesamiento por `chunkById`;
- despliegues expand/migrate/contract para cambios incompatibles;
- índices concurrentes o estrategia equivalente cuando PostgreSQL y el volumen lo exijan;
- `down()` reversible cuando sea seguro; forward-fix documentado cuando no lo sea;
- estimación de locks, volumen y tiempo antes de producción.

### Criterios de aceptación

- Login móvil tiene límite probado y monitoreable.
- El próximo cambio con backfill sigue el patrón separado y sirve como referencia.
- La checklist de PR exige evaluar DDL, DML, lock y rollback.

## 14. Matriz global de verificación

Ejecutar desde un árbol controlado y con servicios de testing aislados:

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

Para cambios PHP, ejecutar además antes de finalizar:

```powershell
vendor/bin/pint --dirty --format agent
```

El entorno de pruebas debe impedir requests externos. Los audits requieren acceso de red de solo lectura a Packagist/npm.

## 15. Smoke tests de staging

Después de cada despliegue relevante:

1. autenticación web y móvil;
2. creación de cotización con caller autorizado;
3. rechazo de caller anónimo o sin ability;
4. presentación de alternativas sin campos raw;
5. flujo de siniestro encolado, sin envío duplicado;
6. webhook WhatsApp válido e inválido;
7. emisión/generación de PDF con fixture seguro;
8. llamada controlada a Visred/AFIP en sandbox;
9. actualización de setting secreto sin exposición;
10. landing, chat y panel administrativo sin errores de consola;
11. procesamiento de colas y tareas programadas;
12. revisión de logs para confirmar ausencia de secretos y PII centinela.

## 16. Observabilidad y métricas de éxito

Medir durante al menos 48 horas después de E1, E4, E5 y E6:

- tasa de `401`, `403`, `422`, `429` y `5xx` por ruta;
- latencia p50/p95/p99 de cotización, tools e integraciones;
- reintentos y fallos finales por proveedor;
- tiempo y memoria máxima de suite;
- duración y tamaño del build;
- tamaño del chunk inicial;
- fallos y duración de jobs por cola;
- cantidad de secretos legacy pendientes de cifrado, sin registrar sus valores;
- advisories abiertos por severidad y fecha objetivo.

Alertas mínimas:

- incremento abrupto de `401/403` tras cierre de rutas;
- tasa de `429` que afecte usuarios legítimos;
- reintentos sostenidos o circuitos de proveedor degradados;
- aparición de un advisory crítico/alto en lockfiles;
- request externo durante tests;
- fallo del backfill o valores secretos sin migrar.

## 17. Riesgos y mitigaciones

| Riesgo | Probabilidad/impacto | Mitigación |
|---|---|---|
| Romper un consumidor al proteger rutas | Alta/Alta | Matriz de consumidores, ventana dual corta, métricas de 401/403 |
| Actualización amplia de dependencias | Media/Alta | Lotes pequeños, lockfiles, suite estable antes de actualizar |
| Envíos reales desde tests | Alta/Alta | `Http::preventStrayRequests`, Queue/Http fakes globales |
| Cifrado deja datos ilegibles | Media/Alta | Backup, lectura dual temporal, comando idempotente y staging |
| Retry duplica operaciones | Media/Alta | Idempotency keys y retry solo en operaciones seguras |
| Rate limit bloquea usuarios legítimos | Media/Media | Límites por identidad, observabilidad y configuración ajustable |
| Rector cambia semántica | Media/Media | PRs por regla, tests focalizados y revisión manual |
| Code splitting empeora UX | Baja/Media | Medición antes/después y rollback por chunk |

## 18. Definición de terminado

La remediación completa requiere evidencia adjunta al último PR o release:

- inventario final de rutas y controles;
- salida exitosa de Pest, PHPStan, Pint y build;
- salida de audits sin críticos/altos de producción, o excepciones temporales formalmente aceptadas;
- prueba de que los tests bloquean tráfico externo;
- reporte del backfill de secretos con conteos, no valores;
- métricas de staging y producción dentro de los umbrales;
- procedimiento de rollback probado para dependencias y secretos;
- `CHANGELOG.md` actualizado en español cuando se prepare el release.

## 19. Orden recomendado resumido

1. Preservar cambios actuales y levantar la línea base.
2. Inventariar consumidores y aprobar el contrato de confianza.
3. Cerrar rutas públicas y reducir la respuesta raw.
4. Incorporar Form Requests, Policies y logging redactado.
5. Bloquear side effects externos en Pest y estabilizar memoria/DB.
6. Actualizar dependencias vulnerables por lotes.
7. Cifrar settings secretos mediante migración compatible y backfill separado.
8. Normalizar timeouts/retries de integraciones.
9. Corregir Pint y aplicar Rector selectivamente.
10. Resolver warnings y tamaño del bundle.
11. Añadir rate limiting al login móvil y formalizar el patrón de migraciones.
12. Ejecutar verificación completa, smoke tests, observación y release.
