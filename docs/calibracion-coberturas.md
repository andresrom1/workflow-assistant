# Calibrar las respuestas de cobertura

Cómo decidir cuán estricto tiene que ser el agente al responder preguntas de cobertura, con qué
medir, y qué se puede aflojar.

> Instrumento: `php artisan ai:probe-coverage-qa --runs=3`
> Última medición: 2026-08-26, 161 corridas acumuladas.

---

## 1. El criterio: dos números, nunca uno

Un sistema que puede abstenerse **no se mide por exactitud**. Se mide por un par:

| Celda | Qué es | Qué cuesta |
|---|---|---|
| **grave** | afirmó algo que no puede sostener | una promesa que el PAS hace y la compañía nunca validó |
| **venta perdida** | se abstuvo pudiendo contestar | un mensaje de seguimiento, o una venta que se enfría |

**No son comparables**, y por eso no se promedian ni se resumen en un solo porcentaje. Cualquier
cambio se juzga mirando los dos: aflojar siempre baja `grave` y sube `venta perdida`, y apretar
hace lo inverso. Un cambio que mueve uno solo es el único que es gratis.

**Objetivo actual:** `grave ≤ 5%`, aceptando la `venta perdida` que eso cueste. Hoy `grave`
oscila entre 6% y 12% según la corrida, así que no estamos ahí.

---

## 2. Qué se puede aflojar, y qué no

### Los seis chequeos de `verificarFundamento()`

Medidos sobre 161 corridas:

| Chequeo | Disparos | ¿Se puede aflojar? |
|---|---|---|
| `la cita no aparece en el material` | 22 (13,7%) | sí, con cuidado — es el que más agarra |
| `el plan no figura en la documentacion` | 7 (4,3%) | **re-medir después de curar los manuales** |
| `cita demasiado corta` | 4 (2,5%) | sí — es el parámetro más arbitrario que queda |
| `afirmo sin cita` | 0 | no tiene sentido tocarlo |
| `nego por ausencia sin enumeracion` | 0 | no tiene sentido tocarlo |
| `veredicto fuera del vocabulario` | 0 | no tiene sentido tocarlo |

### Los tres que nunca dispararon NO se sacan

Es tentador leer "0 disparos" como "está de más". Es al revés:

- Son comparaciones de strings: **cuestan cero** en tiempo y en dinero.
- Su valor es que **no pueden** dispararse, no que se disparen.
- Hoy están tapados por el chequeo de cita, que agarra esos casos antes. **Si algún día se
  afloja ese chequeo, vuelven a ser load-bearing.**

Sacar un guard porque nunca saltó es sacar el airbag porque nunca chocaste.

### Candidatos reales a aflojar, en orden

1. **El piso de 25 caracteres para citas del manual.** Es el único número arbitrario que queda,
   y ya se equivocó una vez: exigirlo sobre el bloque de producto degradaba 3 de 3 corridas de
   *"¿si me roban el auto?"* en el plan de RC, cuya cita legítima es `Responsabilidad Civil`
   (20 caracteres útiles). Ya se acotó a las citas del manual; bajarlo a 15 es lo próximo
   medible.

2. **La exactitud de la comparación de cita.** Ya se aflojó una vez —de literal a
   alfanumérico— porque el modelo reformatea las filas de tabla. El próximo escalón sería
   tolerar elisiones (`…`) al citar fragmentos largos. **Riesgo alto**: una elisión puede dar
   vuelta el sentido de una cláusula.

3. **El chequeo de presencia del plan.** El más nuevo, y el que más depende de que los manuales
   estén bien extraídos. Cuando la Fase 5 cure los documentos, los nombres de plan van a
   aparecer mejor y va a disparar menos **solo**. Conviene re-medirlo **después** de curar, no
   antes: hoy su tasa mezcla "el plan no está" con "la extracción se lo comió".

   La columna `planes` de `coverage:export` separa las dos cosas **antes** de gastar una corrida
   del banco: dice cuántos de los títulos que la compañía efectivamente cotiza aparecen en el
   texto. Un plan que falta ahí es una consulta que el agente no va a poder contestar aunque la
   respuesta esté en el manual.

---

## 2 bis. Curar los manuales

El texto de los manuales **se cura a mano, no con un LLM**. Una transcripción automática puede
comerse o inventar un número en silencio, y ese texto es la fuente de verdad de lo que el agente
le promete al cliente: es exactamente el modo de falla que todo lo demás intenta evitar. La
frecuencia lo permite — seis compañías, un manual nuevo por año cada una.

```
php artisan coverage:export          # saca los .md y mide
   -> se editan en un editor de texto
php artisan coverage:import <dir>    # los devuelve a la base y re-indexa
```

`coverage:export` escribe un `.md` por documento en `storage/app/coverage-md/` (fuera de git) con
un encabezado YAML que dice a qué documento vuelve. `coverage:import` empareja por
`company_name` + `document_type`, y **crea el documento si no existe** — que es el caso de
producción, donde el PDF original nunca se subió. El PDF no hace falta en runtime: el agente solo
lee `extracted_content`.

### Las tres métricas que reporta

| Métrica | Umbral | Qué detecta |
|---|---|---|
| `chars` | — | comparar contra la versión anterior: una caída grande es texto perdido |
| `pipes/1k` | ≥ 15 en documentos con cuadro | las tablas se aplanaron y los topes quedaron ambiguos |
| `planes` | todos | planes que la compañía cotiza y no figuran en el manual |

Referencias medidas: Río Uruguay 47,6 · San Cristóbal insert 56,9 · Galicia 26,4 · Sancor 16,6 ·
Mercantil 11,8 · **Triunfo 0,7** (tablas perdidas).

`planes` es la métrica que importa, porque es la única atada a una consulta real. Las otras dos
son proxies.

**Lo que ninguna de las tres mide es si los números son correctos.** Eso se verifica leyendo el
cuadro de coberturas contra el PDF, y es la parte que no se automatiza.

---

## 3. El procedimiento

1. Correr el banco con `--runs=3` como mínimo y guardar el JSON.
2. Cambiar **un solo** parámetro.
3. Volver a correr con el mismo `--runs`.
4. Comparar `grave` y `venta perdida`:
   - `grave` igual y `venta perdida` mejor → **quedárselo**
   - `grave` sube, aunque sea una celda → **revertir**
   - los dos empeoran → revertir y revisar el supuesto
5. Si un caso cambia de celda, **leer la cita rechazada** (`cita_rechazada` en el JSON) antes de
   concluir nada. La mitad de los cambios que parecen del modelo son del chequeo.

### El piso de ruido, que limita todo lo anterior

Con 11 casos × 3 corridas = **33 muestras**, y una oscilación observada de ±2 celdas entre
corridas idénticas (`gnc` dio 1 grave en una y 3 en otra sin que cambiara nada), **el banco no
puede resolver diferencias menores a ~6-9 puntos porcentuales.**

O sea: si un cambio mueve `grave` de 12% a 9%, eso es ruido y no se puede afirmar que mejoró.

Para detectar mejoras más chicas hacen falta más muestras: `--runs=10` da 110 y baja el piso a
~3 puntos.

---

## 4. Cuándo se agranda el banco

**Cada conversación real donde el agente contestó mal es un caso nuevo.** El banco es la suite
de regresión del criterio, y su valor crece con los casos que salieron de la calle, no de la
imaginación.

Al agregar un caso hay que escribir el `por_que`. Un caso sin fundamento escrito no se puede
revisar después — y ya pasó que un caso estaba mal: `triunfo-tope-granizo` esperaba abstención
sobre el supuesto de que `C2 FUll` no figuraba en el manual de Triunfo. Figura. El supuesto
venía de una sonda vieja y lo único que permitió detectarlo fue que el `por_que` decía en qué se
apoyaba.

**Corolario:** cuando un caso da `grave`, la primera hipótesis a descartar es que la expectativa
del banco esté mal, no que el agente falle.

---

## 5. Lo que ningún chequeo va a agarrar

La **cita correcta con inferencia equivocada**. El caso `gnc` es el ejemplar: el cliente pregunta
si le cubren el robo del equipo de GNC, el modelo cita el texto real de Robo Parcial —que cubre
*"los elementos fijos que hacen al funcionamiento de la unidad"* y excluye una lista donde el GNC
no está— y concluye que sí. La cita es real, el plan es el correcto, la fuente es la correcta, y
la conclusión es un juicio que el texto no sostiene.

Ningún chequeo mecánico distingue eso de una respuesta buena. Es el piso duro de este enfoque, y
la razón por la que `grave` no va a llegar a 0%.

Hay además un hueco conocido y no cerrado: **se verifica el `veredicto` y la `cita`, no que la
prosa de `respuesta` diga lo mismo que el veredicto.** No se observó en 161 corridas, pero nada
lo impide.

---

## 6. El próximo escalón de determinismo

Hoy el modelo decide el veredicto y el código lo filtra. Para las preguntas de **pertenencia**
—*"¿tiene granizo?"*— se puede ir un paso más lejos: pedirle al modelo **una sola cosa**, a qué
tag del vocabulario cerrado corresponde la pregunta, y **calcular el veredicto en código** con
una operación de conjuntos.

El LLM queda confinado a la traducción, que es lo único que no se puede computar. Cuatro de los
once casos del banco son de ese tipo.

Para *alcance* y *cuantía* no aplica: requieren leer y entender un texto.
