# Comparador de coberturas — qué falta

> Punto de entrada para retomar la feature en una sesión nueva. Rama: **`feat/comparador-coberturas`**.
> El detalle de por qué cada cosa está como está vive en los mensajes de commit; acá va lo mínimo
> para arrancar sin recontexto.

## Qué existe hoy

Una vista pública que el cliente abre desde WhatsApp para ver el detalle de las cotizaciones de su
auto: las dos que le recomendó el asistente marcadas y con la razón, el resto agrupado por
compañía, y un diff entre las dos.

`GET /cotizaciones/{token16}` → `Cotizaciones/Comparador` (Inertia). Sin autenticación: el token es
la credencial.

| Pieza | Dónde |
|---|---|
| Controller | [PublicQuoteController.php](../app/Http/Controllers/PublicQuoteController.php) |
| Dominio (glosario, dedupe, agrupación, diff) | [QuoteComparisonService.php](../app/Services/Quote/QuoteComparisonService.php) |
| Franquicia parseada del título | [Franquicia.php](../app/Support/Franquicia.php) |
| Vigencia y token | helpers en [Quote.php](../app/Models/Quote.php) |
| noindex | [NoIndex.php](../app/Http/Middleware/NoIndex.php) + meta condicional en `app.blade.php` |
| UI | `resources/js/pages/Cotizaciones/` + `resources/js/components/Cotizaciones/` |
| Persistencia de la recomendación | [PresentQuoteOptionsTool.php](../app/AI/Tools/PresentQuoteOptionsTool.php) |

Cinco commits, de `6ad6e6f` a `c38a2c8`. Suite completa en verde (673 tests).

### Para verlo andando

```bash
php artisan tinker --execute '$q = App\Models\Quote::has("alternatives")->latest("id")->first(); echo $q->ensurePublicToken();'
```

Y abrir `/cotizaciones/{token}` en el preview, a 390px y a 1280px (el corte de layout es 900px).

---

## Pendientes, por orden de importancia

### 1. Nadie le manda el link al cliente 🔴

**La feature es inalcanzable en producción.** El token se genera y se persiste al presentar
opciones, pero ninguna parte del flujo se lo envía. Quedó explícitamente fuera de scope, y es lo
único que falta para que la vista sirva de algo.

El `tool_output` de `PresentQuoteOptionsTool` **evita mencionar la URL a propósito**: si la
nombrara, el LLM la pegaría en el chat sin control de formato. Cualquier solución tiene que
decidir dónde se arma la URL y quién la escribe.

A resolver: si el link va adosado al mensaje de presentación, si va como un botón interactivo más
(hoy los 3 slots de Meta están ocupados: 2 opciones + "Tengo una pregunta"), o si va en un mensaje
aparte. Y qué pasa con el link cuando el cliente vuelve días después.

### 2. El bloqueo de la emisión es cosmético 🔴

Decisión tomada a conciencia, pero conviene tenerla presente: **vencida la cotización, solo se
apaga el botón en la vista**. El cliente igual puede escribirle al asistente y avanzar, y
[CheckoutController](../app/Http/Controllers/CheckoutController.php) sigue guardando únicamente por
`status` — nunca compara `expires_at` contra `now()`.

Si se quiere bloqueo real, los puntos son `show()`, `submit()`, `uploadPhoto()` y `deletePhoto()`.
Ojo: `show()` hoy sirve la página también con `status = checkout_submitted`.

### 3. La etiqueta de variante no tiene origen 🟡

En producción, el proveedor devuelve el mismo producto dos veces con precios distintos y nada en el
dominio que lo explique:

```
Triunfo  D3 - Todo Riesgo Franq 10%   $70.447,20  y  $71.799,20
Galicia  Todo Riesgo Franquicia 4%    $90.317,04  y  $116.461,65
```

Misma suma asegurada, mismos `features_tags`, mismo título. Lo único que cambia es el
`external_quote_id` de Visred. **Hoy nos quedamos con la más barata** (`soloLaMasBarata` en el
service), que es lo correcto para el cliente, pero se descarta algo sin saber qué.

Hay que averiguar con Visred qué distingue esas variantes — periodicidad de pago, plan de cuotas,
descuento — y modelarlo. Mientras tanto la regla se sostiene sola.

### 4. Canario de tags nuevos 🟡

El vocabulario de coberturas del proveedor es cerrado y estable (22 tags, una única descripción por
tag en toda la base), y es lo que permite que el diff sea una diferencia de conjuntos sin
diccionario de sinónimos. **Pero se mueve:** `Caída de árboles` apareció mientras se implementaba
esto, cuando entró la cotización 6 con Galicia.

Falta un aviso cuando aparece un tag fuera del conjunto conocido. No es un mapeo a mantener — es
monitoreo. El caso que hay que agarrar es un tag compuesto tipo `Cristales y Cerraduras` (2
apariciones, solo Triunfo), que funde dos conceptos que el resto trae separados y hace que el diff
reporte diferencias falsas.

### 5. Borde de vigencia 🟢

Una cotización generada 23:50 ART vence en 10 minutos. Es la semántica pedida ("válidas hasta el
final del día") y coincide con lo que dice la vista, pero conviene decidir si va un piso, tipo
`max(endOfBusinessDay(), now()->addHours(2))`, en
[QuoteRepository::saveResults()](../app/Repositories/QuoteRepository.php).

### 6. Las razones son doble fuente de verdad 🟢

`recommended_reason` / `alternative_reason` dicen lo mismo que el texto que el agente escribe en el
chat, y nada las sincroniza. Se congelan al presentar: si después se recotiza, quedan viejas y
nadie las invalida. Está documentado en el prompt de `CheckoutAgent`, pero sigue siendo deuda.

---

## Deuda preexistente que roza esta feature

**Las factories están en `database/Factories/` con mayúscula** mientras `composer.json` mapea
`Database\Factories\` → `database/factories/`. Son 17 archivos y viene de antes; en Windows no se
nota, pero en un filesystem case-sensitive (Linux, CI) el autoload no las encuentra. Las dos
factories nuevas siguen la convención existente para no mezclar.

**`vite.config.js` tiene `hmr.host` hardcodeado** a `192.168.0.102`, que ya no es la IP de la
máquina. Cuando corre `npm run dev`, `public/hot` apunta ahí y cualquier página carga en blanco. El
workaround es mover `public/hot` de lado; el arreglo es esa línea.

**El checkout sí expone la patente** ([CheckoutController.php:70](../app/Http/Controllers/CheckoutController.php)),
renderizada en `Checkout/Show.vue`. Ahí está justificado —va a un cliente ya identificado que a
continuación carga su DNI— pero ahora que hay un criterio explícito de PII para links públicos,
vale revisarlo.

**`agent_execution_logs` no tiene retención.** Crece indefinidamente; solo se limpia por cascade al
borrar una conversación.

---

## Decisiones ya tomadas — no re-litigar

- **El CTA "La quiero" es un link a WhatsApp**, no un botón que abra el checkout. Vencida la
  cotización, se deshabilita y nada más.
- **La vigencia reusa `quotes.expires_at`**, que pasó de `now()->addDays(7)` a fin del día
  argentino en UTC.
- **La recomendación se persiste en columnas de `quotes`**, escritas desde la tool. No se lee de
  `agent_execution_logs`.
- **El diff es diferencia de conjuntos, sin diccionario ni LLM.** Se evaluó un catálogo canónico de
  coberturas con tabla de mapeo y se descartó: el proveedor ya normaliza.
- **La vista no muestra patente, DNI, nombre ni teléfono.** Los campos se enumeran a mano en el
  controller y hay un test que busca los tres valores en el HTML crudo.
- **Una cotización vencida renderiza igual**, con `vigente: false`. No es 404: el cliente que abre
  el link al día siguiente merece ver la página.

---

## Trampas del código

**`quote_alternatives.precio` tiene cast `decimal:2`, o sea que Eloquent devuelve `string`.**
`"116461.65" < "90317.04"` es `true` comparando strings, y el dedupe se queda con la más barata.
Todo cálculo y todo orden necesita `(float)` explícito. Hay un test dedicado que falla si alguien
lo olvida.

**`normalized_grade` está inconsistente**: `all_risk` / `basic` / `liability` /
`third_party_complete` en producción, `'A'..'D'` en el docblock del modelo, `'C'` en tests viejos.
No hardcodear ningún grado; el service lo deriva de la alternativa recomendada.

**La franquicia se parsea del título** y solo existe en Todo Riesgo. En Terceros Básico los títulos
son `B0 - Robo Total`, `M PLUS`, etc.: `Franquicia::extraer()` devuelve `null` y el dedupe cae a la
clave `raw:<título>`, que es el comportamiento seguro.

**El runtime no lee los prompts `.md`**, lee la tabla `agent_prompts` con `rememberForever`. Después
de editar hay que correr `php artisan agent:sync-prompts` y verificar en `/admin/agent-prompts` que
no haya un draft pisando el sync.

---

## Consultar producción

La base de prod está en GCP y no hay conexión configurada localmente. Para leerla:

```bash
gcloud compute ssh mango-prod --zone=us-central1-a --project=project-1abe2eb8-c736-448d-bd8 --quiet --command="docker exec workflow-assistant-postgres-1 sh -c 'psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -A -F\"|\" -t -c \"SELECT id, status FROM quotes ORDER BY id\"'"
```

Para consultas con acentos, escribirlas a un archivo con heredoc y usar `psql -f`: el heredoc a
través de `plink` rompe la codificación de los literales UTF-8 en el SQL.
