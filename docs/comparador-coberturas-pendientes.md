# Comparador de coberturas — estado

> Rama: **`feat/comparador-coberturas`**. El detalle de por qué cada cosa está como está vive en los
> mensajes de commit; acá va lo mínimo para retomar sin recontexto.

## Qué existe hoy

Una vista pública que el cliente abre desde WhatsApp para ver el detalle de las cotizaciones de su
auto: las dos que le recomendó el asistente marcadas y con la razón, el resto agrupado por
compañía, y un diff entre las dos. Desde ahí puede contratar.

`GET /cotizaciones/{token16}` → `Cotizaciones/Comparador` (Inertia). Sin autenticación: el token es
la credencial.

| Pieza | Dónde |
|---|---|
| Controller | [PublicQuoteController.php](../app/Http/Controllers/PublicQuoteController.php) |
| Dominio (glosario, dedupe, agrupación, diff) | [QuoteComparisonService.php](../app/Services/Quote/QuoteComparisonService.php) |
| Apertura del checkout (punto único) | `QuoteService::crearCheckout()` + `QuoteRepository::marcarCheckoutPendiente()` |
| Franquicia parseada del título | [Franquicia.php](../app/Support/Franquicia.php) |
| Vigencia y token | helpers en [Quote.php](../app/Models/Quote.php) |
| noindex | [NoIndex.php](../app/Http/Middleware/NoIndex.php) + meta condicional en `app.blade.php` |
| UI | `resources/js/pages/Cotizaciones/` + `resources/js/components/Cotizaciones/` |
| Persistencia de la recomendación | [PresentQuoteOptionsTool.php](../app/AI/Tools/PresentQuoteOptionsTool.php) |
| Envío del link al cliente | `DespachaRespuestaDelAgente` (trait), usado por `ProcessConversationInbox` y `NotifyClientQuoteReady` |
| Canario del vocabulario | `QuoteService::auditarVocabulario()` + `config/quotes.php` |

Suite completa en verde (695 tests).

### El flujo, punta a punta

1. El agente presenta 2 opciones → `PresentQuoteOptionsTool` guarda la recomendación, mintea el
   token y deja el link en `metadata.pending_public_link`.
2. Sale el mensaje de presentación con los 3 botones de Meta y, **encadenado detrás**, un segundo
   mensaje con el link al comparador. El LLM sabe que el mensaje sale pero nunca ve la URL.
3. El cliente abre el comparador y toca **"La quiero"** → `POST /cotizaciones/{token}/checkout`.
   - Desde el **celular**: redirige a `/checkout/{checkout_token}`.
   - Desde **escritorio**: el link del checkout le llega por WhatsApp y un modal se lo avisa. El
     formulario necesita la cámara para las 7 fotos de inspección.

### Para verlo andando

```bash
php artisan tinker --execute '$q = App\Models\Quote::has("alternatives")->latest("id")->first(); echo $q->ensurePublicToken();'
```

Y abrir `/cotizaciones/{token}` en el preview, a 390px y a 1280px (el corte de layout es 900px).

---

## Lo que sigue abierto

Todo lo que quedaba en este doc está cerrado o convertido en issue:

| Issue | Qué |
|---|---|
| [#7](https://github.com/andresrom1/workflow-assistant/issues/7) | Vigencia obligatoria en el `CheckoutController` — la creación ya la valida, falta el link ya emitido |
| [#8](https://github.com/andresrom1/workflow-assistant/issues/8) | Modelar el método de pago de las variantes duplicadas |
| [#9](https://github.com/andresrom1/workflow-assistant/issues/9) | Retención de `agent_execution_logs` |
| [#10](https://github.com/andresrom1/workflow-assistant/issues/10) | Revisar la patente expuesta en el checkout |

**Las razones son doble fuente de verdad.** `recommended_reason` / `alternative_reason` dicen lo
mismo que el texto que el agente escribe en el chat, y nada las sincroniza. Ya no quedan viejas al
recotizar (`saveResults` las invalida), pero siguen siendo dos escrituras del mismo contenido.

---

## Decisiones ya tomadas — no re-litigar

- **La cotización vale por el día calendario argentino.** A las 00:01 del día siguiente hay que
  recotizar: la tarifa puede haber cambiado. No va ningún piso de horas. Documentado en
  configuracion-hardcodeada.md §3b.
- **La recomendación se persiste en columnas de `quotes`**, escritas desde la tool. No se lee de
  `agent_execution_logs`.
- **El diff es diferencia de conjuntos, sin diccionario ni LLM.** Se evaluó un catálogo canónico de
  coberturas con tabla de mapeo y se descartó: el proveedor ya normaliza. El canario del vocabulario
  es lo que sostiene ese supuesto.
- **La vista no muestra patente, DNI, nombre ni teléfono.** Los campos se enumeran a mano en el
  controller y hay un test que busca los tres valores en el HTML crudo.
- **Una cotización vencida renderiza igual**, con `vigente: false`. No es 404: el cliente que abre
  el link al día siguiente merece ver la página, con el CTA de contratar apagado.
- **El link va en un mensaje de WhatsApp aparte**, no pegado al de presentación: los 3 slots de
  botones de Meta ya están ocupados y un `cta_url` no se puede mezclar con reply buttons.
- **`checkout_done` lo escribe `QuoteService`, no `CheckoutTool`.** El checkout también se abre
  desde la web, donde no corre ninguna tool. Es la única excepción a "el estado lo escribe la tool".
- **El endpoint del CTA va sin CSRF.** La página no tiene autenticación por cookie, así que no hay
  autoridad ambiente que proteger; con el guard puesto, un link abierto días después revienta con
  419 por sesión vencida.

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

**El vocabulario de coberturas no son 22 tags.** En la base hay 35, y varios son el mismo concepto
escrito distinto (`Destrucción Total por accidente` vs `...por Accidente`) o compuestos
(`Robo Total y Parcial`). El diff los cuenta como coberturas distintas. `config/quotes.php` toma la
variante mayoritaria de cada par a propósito, para que la otra salte como aviso.

**Detección de dispositivo ≠ corte de layout.** `esDispositivoMovil()` (user-agent, compartido con
el checkout) decide si el CTA redirige o manda el link por WhatsApp. El `esMovil` del comparador es
un `matchMedia` de 899px: una ventana angosta en escritorio es "mobile" para el layout y escritorio
para el CTA.

**El runtime no lee los prompts `.md`**, lee la tabla `agent_prompts` con `rememberForever`. Editar
desde `/admin/agent-prompts/{key}`, que crea versión nueva e invalida la caché sola.

---

## Consultar producción

La base de prod está en GCP y no hay conexión configurada localmente. Para leerla:

```bash
gcloud compute ssh mango-prod --zone=us-central1-a --project=project-1abe2eb8-c736-448d-bd8 --quiet --command="docker exec workflow-assistant-postgres-1 sh -c 'psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -A -F\"|\" -t -c \"SELECT id, status FROM quotes ORDER BY id\"'"
```

Para consultas con acentos, escribirlas a un archivo con heredoc y usar `psql -f`: el heredoc a
través de `plink` rompe la codificación de los literales UTF-8 en el SQL.
