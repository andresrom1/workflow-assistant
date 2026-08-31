# Calibrar las respuestas de cobertura

Qué decide el código, qué solo observa, y con qué se mide.

---

## 1. El criterio: decidir sólo lo que es binario sin criterio

| | Ejemplo | Qué se hace |
|---|---|---|
| **Determinista** | ¿el tag está en la lista de 33? ¿hay campo de cita, sí o no? | **decide** |
| **No determinista** | ¿la frase que citó coincide con el texto? | **registra** |

Un chequeo binario colgado de una salida no determinista descarta respuestas correctas: para una
misma respuesta buena hay muchas citas válidas —la oración entera, media, la fila de tabla, las
mismas palabras en otro orden— y cuál elige el modelo no se puede predecir.

### Lo que decide hoy

| Chequeo | Por qué es binario |
|---|---|
| `veredicto fuera del vocabulario` | tres valores posibles, sin juicio |
| `afirmo sin cita` | vacío contra no vacío |
| `nego por ausencia sin enumeracion` | la lista vino o no vino |

El tercero no puede saltar: un plan sin `features_tags` no es ofrecible
(`QuoteAlternative::hasFeatureTags()`). Queda como último respaldo si esa condición se afloja.

### Lo que sólo observa

`la cita no aparece en el material` → `Log::warning` con la cita rechazada, el plan y la
aseguradora. La respuesta sigue su camino.

Saltaba en 22 de 161 corridas cuando descartaba. **No se sabe cuántas de esas eran frases
compuestas por el modelo y cuántas eran respuestas correctas redactadas distinto** — leer los
registros reales es lo que va a decidir si vuelve a decidir, si se reemplaza por un agente que
juzgue el fundamento, o si se borra.

---

## 2. Los tres chequeos que se borraron, y por qué

**`el plan no figura en la documentacion`.** Exigía que el título comercial de Visred apareciera
en el manual. El manual nombra el plan por su código (`## C1`, `## C2 FULL`) o directamente no lo
nombra, porque mezcla secciones por plan con secciones generales que valen para toda la compañía
(`## Zona de Aplicación`, `### Pautas de Inspección (Todas las coberturas)`).

Medido sobre producción: **20 de 80 planes cotizados figuran en la documentación.** Tiraba tres de
cada cuatro respuestas buenas, y satisfacerlo del todo exigía reescribir los manuales.

**`cita demasiado corta`.** Un piso de 25 caracteres elegido a ojo. Ya había rechazado
`Responsabilidad Civil` (20 caracteres útiles).

**Las métricas de `coverage:export`.** `pipes/1k` no distingue "se perdieron las tablas" de "este
manual no usa tablas": Triunfo daba 0,7 con sus secciones completas escritas en prosa y su única
tabla intacta. Ninguna aportaba nada frente a abrir el `.md` y mirarlo, que hay que hacer igual
para curarlo.

---

## 3. Curar los manuales

El texto se cura **a mano, no con un LLM**. Una transcripción automática puede comerse o inventar
un número en silencio, y ese texto es la fuente de verdad de lo que el agente le promete al
cliente. La frecuencia lo permite: siete compañías, un manual nuevo por año cada una.

```
php artisan coverage:export          # saca los .md
   -> se corrigen en un editor contra el PDF
php artisan coverage:import <dir>    # los devuelve a la base y re-indexa
```

`coverage:export` escribe un `.md` por documento en `storage/app/coverage-md/` (fuera de git) con
un encabezado YAML que dice a qué documento vuelve. `coverage:import` empareja por `company_name`
+ `document_type` y **crea el documento si no existe** — el caso de producción, donde el PDF
original nunca se subió. El PDF no hace falta en runtime: el agente sólo lee `extracted_content`.

**Curar es corregir lo que la extracción perdió.** Ni nombres de plan, ni tablas de equivalencia,
ni marcas de sección: si algo de eso hiciera falta, el chequeo que lo exige está mal.

---

## 4. La medición

`ai:probe-coverage-qa` corre preguntas con respuesta conocida y las clasifica en tres celdas que
no valen lo mismo:

| Celda | Qué es | Qué cuesta |
|---|---|---|
| acierto | contestó lo esperado | — |
| **grave** | afirmó algo que no puede sostener | una promesa que el PAS hace y la compañía nunca validó |
| **venta perdida** | se abstuvo pudiendo contestar | un mensaje de seguimiento |

**No se promedian.** Aflojar siempre baja `grave` y sube `venta perdida`; apretar hace lo inverso.

### El banco actual no está validado

Sus 11 preguntas y sus respuestas esperadas las escribió el agente, y **dos resultaron mal**:

- `gnc` — esperaba abstención. El equipo de GNC declarado, en un plan con cobertura de robo, **sí
  está cubierto**. El agente contestaba bien y se contaba como error.
- `triunfo-tope-granizo` — esperaba abstención sobre el supuesto de que `C2 FUll` no figuraba en
  el manual de Triunfo. Figura.

**Está desacoplado del camino de decisión hasta que las preguntas las escriba quien conoce el
dominio.** Cada caso necesita su `por_que`: sin él no se puede revisar si la expectativa estaba
bien, y es lo único que permitió detectar las dos equivocadas.

### El piso de ruido

Con 11 casos × 3 corridas = 33 muestras y una oscilación observada de ±2 celdas entre corridas
idénticas, **el banco no resuelve diferencias menores a ~6-9 puntos porcentuales**. Un cambio que
mueve `grave` de 12% a 9% es ruido.

`--runs=10` da 110 muestras y baja el piso a ~3 puntos.

---

## 5. El próximo escalón

**Un agente que juzgue si la respuesta está sostenida por el material.** Una segunda llamada que
recibe el material y la respuesta ya escrita, y devuelve `sostiene / no_sostiene /
no_puedo_determinar`.

Tolera la paráfrasis, que es lo que rompía la comparación de texto, y tiene una tarea más chica
que el agente que responde: verificar una afirmación en vez de encontrarla entre 120.000
caracteres.

**No entra hasta que exista una medición validada con qué compararlo.** Agregar un componente que
suena razonable sin poder medirlo es el patrón que produjo los cuatro chequeos que este documento
explica por qué se borraron.
