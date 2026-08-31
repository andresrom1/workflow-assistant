<?php

namespace App\AI\Tools;

use App\AI\Concerns\HasRealReplay;
use App\Models\AgentPrompt;
use App\Models\CoverageDocument;
use App\Models\QuoteAlternative;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\Ai\Tools\Request;

class CheckCoverageRuleTool implements Tool
{
    use ConditionalLogger;
    use HasRealReplay;

    public function description(): string
    {
        return <<<'TXT'
Consulta las reglas de la poliza para saber si un evento o siniestro especifico esta cubierto.

CUANDO USAR: cada vez que el cliente pregunta "esto cubre X?", "incluye grua?", "me cubre si me pasa Y?", "que pasa si...?", o cualquier variante donde necesites confirmar si un evento especifico esta dentro de una cobertura. Tambien cuando tu propia informacion en memoria o en el payload (features_tags, glosario) no alcanza para responder con certeza.

REGLA ABSOLUTA: nunca respondas sobre coberturas de memoria. Si el cliente pregunta, llama esta tool. Si dudas, llama esta tool. El resultado de la tool ES la fuente de verdad, INCLUIDO cuando dice que no lo tiene verificado.

COMO LLAMARLA:
- NO avises que vas a consultar. NO pidas permiso. NO digas "dejame verificar". Simplemente ejecuta la tool y responde con el resultado.
- Parametros obligatorios: evento (el evento exacto que menciono el cliente), aseguradora (nombre de la compania, o "no especificada"), quote_alternative_id (ID de la alternativa en contexto, o "0"), antiguedad_vehiculo (anios del vehiculo, o "desconocida").
- **quote_alternative_id es el parametro que importa.** Identifica el plan exacto con SUS coberturas. Pasa el id de la alternativa de la que habla el cliente; si hay varias en juego, la ultima que menciono. Con "0" el experto se queda sin el producto y solo puede responder generalidades de la compania.
- NO mandes el grado ni la letra de cobertura. Dos planes con la misma letra cubren cosas distintas: en San Cristobal "C - Auto Plus" NO trae granizo y "Auto Plus +" SI, y los dos son "C".

QUE DEVUELVE: un JSON con `veredicto`, `respuesta`, `fuente`, `cita` y `verificado`.
- `respuesta` es el texto que le pasas al cliente (reformulalo con tu tono, no lo pegues crudo).
- `veredicto` es `cubierto`, `no_cubierto`, `no_especificado` o `sin_verificar`.
- `cita` es la frase textual del material que sostiene la respuesta. NO se la muestres al
  cliente salvo que aporte (un monto, un tope); esta para que quede registrada.

COMO RESPONDER AL CLIENTE tras la tool:

1. `cubierto` / `no_cubierto` — RESPUESTA FUNDADA: directo, sin hedges.
- MAL: "No te lo puedo confirmar de memoria, si queres te lo verifico..."
- MAL: "Queres que te lo verifique?"
- BIEN: "La grua no esta incluida en esa cobertura."
- BIEN: "Si, esa cobertura incluye grua hasta 100 km."

2. `no_especificado` / `sin_verificar` — NO LO TIENE VERIFICADO: transmitilo en tus palabras y
   ofrece averiguarlo. NO lo completes, NO lo deduzcas del nombre del plan, NO uses lo que
   sepas del mercado. Es un resultado valido y frecuente: los manuales no cubren todo, y
   una respuesta inventada compromete a la agencia con algo que la compania no valido.
- MAL: inventar un numero, un plazo o un tope que la tool no devolvio.
- MAL: contestar igual "en general es asi".
- BIEN: "Ese dato puntual no lo tengo confirmado, dejame chequearlo y te digo."
TXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'evento' => $schema->string()
                ->description('El evento o siniestro que pregunta el cliente. Ej: "robo de espejos", "granizo", "choque propio".')
                ->required(),
            'aseguradora' => $schema->string()
                ->description('Nombre de la aseguradora. Ej: "San Cristobal", "Triunfo". Usar "no especificada" si no se conoce.')
                ->required(),
            'quote_alternative_id' => $schema->string()
                ->description('ID de la alternativa de cotizacion en contexto. Usar "0" si no hay.')
                ->required(),
            'antiguedad_vehiculo' => $schema->string()
                ->description('Antiguedad del vehiculo en anios. Usar "desconocida" si no se sabe.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->logToolCall($request->all());

        $all = $request->all();
        $aseguradora = $all['aseguradora'] ?? 'no especificada';
        $altId = (int) ($all['quote_alternative_id'] ?? 0);
        $antiguedad = $all['antiguedad_vehiculo'] ?? 'desconocida';

        // 1. Load Expert Agent instructions (shared blocks + specific)
        $instructions = AgentPrompt::compose('coverage_check', ['shared_style', 'shared_grounding']);

        if ($instructions === '') {
            $instructions = (string) file_get_contents(resource_path('prompts/agents/CoverageCheckAgent.md'));
        }

        // 2. Producto: el plan exacto que se esta cotizando.
        $plan = 'no identificado';
        $alt = $altId > 0 ? QuoteAlternative::find($altId) : null;
        $producto = '';

        if ($alt instanceof QuoteAlternative) {
            $producto = $this->productBlock($alt);
            $plan = (string) $alt->titulo;
        }

        // 3. Documentacion de la compania: entera si entra, busqueda solo como salida.
        $docs = CoverageDocument::activeForCompany($aseguradora);
        $documentacion = $this->documentationBlock($docs);

        // Lo citable: es contra esto que se verifica la cita del experto.
        $material = $producto."\n".$documentacion;
        $instructions .= $material;

        $prompt = 'Evento consultado: '.($all['evento'] ?? '')
            ."\nPlan cotizado: {$plan}"
            ."\nAseguradora: {$aseguradora}"
            ."\nCompany slug: ".Str::slug($aseguradora)
            .($antiguedad !== 'desconocida' ? "\nAntiguedad vehiculo: {$antiguedad} anios" : '');

        // 4a. Camino de busqueda: salida estructurada y tools son excluyentes en DeepSeek
        // (`Providers/DeepSeek/Handlers/Structured.php` no mapea tools), asi que aca se
        // conserva la prosa y el resultado se marca como NO verificado, en vez de fingir
        // una garantia que no se dio. Hoy no lo toma ninguna compania.
        if ($this->needsSearchFallback($docs)) {
            $texto = (new AnonymousAgent($instructions, [], [new SearchCompanyDocumentationTool]))
                ->prompt($prompt)->text;

            return $this->encode([
                'veredicto' => 'sin_verificar',
                'respuesta' => $texto,
                'fuente' => 'busqueda',
                'cita' => '',
                'verificado' => false,
            ]);
        }

        // 4b. Camino normal: salida estructurada, para poder chequear el fundamento.
        $agent = new StructuredAnonymousAgent(
            instructions: $instructions,
            messages: [],
            tools: [],
            schema: fn (JsonSchema $s): array => [
                'veredicto' => $s->string()
                    ->description('cubierto | no_cubierto | no_especificado. Usa no_especificado si no podes respaldar la respuesta con una frase textual del material.')
                    ->required(),
                'respuesta' => $s->string()
                    ->description('La respuesta para el cliente, 4-5 lineas, directa y sin hedges.')
                    ->required(),
                'fuente' => $s->string()
                    ->description('enumeracion (lista de coberturas del plan) | alcance (descripcion de una cobertura) | documentacion (manual de la compania) | ninguna.')
                    ->required(),
                'cita' => $s->string()
                    ->description('La frase TEXTUAL del material en la que se apoya la respuesta, copiada tal cual. Vacia solo si veredicto es no_especificado.')
                    ->required(),
            ],
        );

        $respuestaAgente = $agent->prompt($prompt);

        // Si por lo que sea no vino estructurada, el array vacio degrada a `no_especificado`
        // en `verificarFundamento()`. Falla hacia el silencio, no hacia una afirmacion.
        $salida = $respuestaAgente instanceof StructuredAgentResponse
            ? $respuestaAgente->structured
            : [];

        return $this->encode($this->verificarFundamento($salida, $alt, $material));
    }

    /**
     * Degrada a `no_especificado` toda respuesta que no pueda sostener su propia cita.
     *
     * **Decide solo lo que es binario sin criterio**: que el veredicto este en el vocabulario, y
     * que haya campo de cita. Lo que depende de como el modelo eligio redactar —si la frase que
     * cito coincide con el texto— se REGISTRA y no descarta, ver {@see self::citaEnElMaterial()}.
     *
     * Un chequeo binario colgado de una salida no determinista descarta respuestas correctas: para
     * una misma respuesta buena hay muchas citas validas (la oracion entera, media, la fila de
     * tabla, las mismas palabras en otro orden), y cual elige el modelo no se puede predecir.
     *
     * @param  array<string, mixed>  $salida
     * @param  string  $material  todo lo citable (producto + documentacion)
     * @return array<string, mixed>
     */
    private function verificarFundamento(array $salida, ?QuoteAlternative $alt, string $material): array
    {
        $veredicto = is_string($salida['veredicto'] ?? null) ? $salida['veredicto'] : 'no_especificado';
        $cita = is_string($salida['cita'] ?? null) ? trim($salida['cita']) : '';
        $fuente = is_string($salida['fuente'] ?? null) ? $salida['fuente'] : 'ninguna';
        $respuesta = is_string($salida['respuesta'] ?? null) ? $salida['respuesta'] : '';

        // La cita rechazada se CONSERVA: sin ella no se puede diagnosticar por que se degrado,
        // y calibrar el chequeo a ciegas es como no tenerlo.
        $degradar = fn (string $motivo): array => [
            'veredicto' => 'no_especificado',
            'respuesta' => $respuesta,
            'fuente' => 'ninguna',
            'cita' => '',
            'verificado' => true,
            'degradado_por' => $motivo,
            'cita_rechazada' => $cita,
            'fuente_rechazada' => $fuente,
        ];

        if (! in_array($veredicto, ['cubierto', 'no_cubierto', 'no_especificado'], true)) {
            return $degradar('veredicto fuera del vocabulario');
        }

        if ($veredicto === 'no_especificado') {
            return ['veredicto' => $veredicto, 'respuesta' => $respuesta, 'fuente' => 'ninguna', 'cita' => '', 'verificado' => true];
        }

        if ($cita === '') {
            return $degradar('afirmo sin cita');
        }

        $this->citaEnElMaterial($cita, $material, $alt);

        // Negar por ausencia exige que la enumeracion haya venido. Hoy no puede saltar —una
        // alternativa sin `features_tags` no es ofrecible y no llega hasta aca, ver
        // {@see QuoteAlternative::hasFeatureTags()}— y se queda como ultimo respaldo por si esa
        // condicion se afloja.
        if ($veredicto === 'no_cubierto' && $fuente === 'enumeracion'
            && ($alt === null || ! $alt->hasFeatureTags())) {
            return $degradar('nego por ausencia sin enumeracion');
        }

        return ['veredicto' => $veredicto, 'respuesta' => $respuesta, 'fuente' => $fuente, 'cita' => $cita, 'verificado' => true];
    }

    /**
     * Registra —sin descartar— cuando la frase que el experto dice citar no aparece en el material.
     *
     * Fue un chequeo que degradaba la respuesta, y saltaba en 22 de 161 corridas. El problema es
     * que no mide si la respuesta es correcta: mide si el modelo copio y pego. Se lo deja como
     * observacion para poder leer los casos reales y decidir con evidencia si vuelve a decidir,
     * si se reemplaza por un agente que juzgue el fundamento, o si se borra.
     *
     * La comparacion ignora mayusculas, acentos y puntuacion porque el modelo reformatea las
     * filas de tabla: el material trae `| **Rotura de Cerraduras (3 acontecimientos)** | $300.000 |`
     * y el modelo cita `Rotura de Cerraduras (3 acontecimientos): $300.000`.
     */
    private function citaEnElMaterial(string $cita, string $material, ?QuoteAlternative $alt): void
    {
        if (str_contains($this->normalizar($material), $this->normalizar($cita))) {
            return;
        }

        Log::warning('CheckCoverageRule: la cita no aparece en el material', [
            'quote_alternative_id' => $alt?->id,
            'aseguradora' => $alt?->aseguradora,
            'plan' => $alt?->titulo,
            'cita' => $cita,
        ]);
    }

    /**
     * Deja solo letras y digitos, en minuscula, para comparar la cita contra el material.
     *
     * Medido con `ai:probe-coverage-qa`: el modelo REFORMATEA las filas de tabla. El material
     * trae `| **Rotura de Cerraduras (3 acontecimientos...**)** | $300.000 |` y el modelo cita
     * `Rotura de Cerraduras (3 acontecimientos...): $300.000` — mismas palabras, mismo numero,
     * sin pipes y con dos puntos agregados. Con comparacion literal eso se rechazaba en 2 de 3
     * corridas: una respuesta CORRECTA tirada a la basura.
     *
     * Sacar la puntuacion no afloja el chequeo: la comparacion sigue siendo por SUBSTRING, o
     * sea que el orden de las palabras tiene que ser exacto. Un texto inventado no aparece
     * igual. Los acentos y los digitos SI se conservan.
     */
    private function normalizar(string $texto): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $texto));
    }

    /** @param  array<string, mixed>  $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Techo de caracteres de documentación que se inyecta entera.
     *
     * Hoy la compañía más grande son 37.040 caracteres sin curar (Sancor) y la estimación
     * curada más alta son ~92.000 (San Cristóbal, sus 3 documentos). 120.000 ≈ 38k tokens,
     * holgado contra los 128k de contexto, y deja margen para que un manual crezca sin que
     * el turno se caiga. Por encima de esto se vuelve al RAG, que es peor pero acotado.
     */
    private const DOC_BUDGET_CHARS = 120_000;

    /**
     * ¿Hay que darle la tool de búsqueda al experto?
     *
     * Sólo cuando la documentación NO entra en contexto. Con documentos que entran, la
     * búsqueda es estrictamente peor: el experto ve el manual completo, así que puede
     * ubicar la columna de SU plan y —lo que el RAG no permite— determinar que un plan
     * NO figura. Con 4 fragmentos nunca ve lo que no recuperó.
     *
     * Y sin documentos tampoco tiene sentido: la búsqueda tampoco tiene dónde buscar.
     *
     * @param  Collection<int, CoverageDocument>  $docs
     */
    private function needsSearchFallback(Collection $docs): bool
    {
        return $docs->isNotEmpty() && $this->totalChars($docs) > self::DOC_BUDGET_CHARS;
    }

    /** @param  Collection<int, CoverageDocument>  $docs */
    private function totalChars(Collection $docs): int
    {
        return (int) $docs->sum(fn (CoverageDocument $d): int => mb_strlen((string) $d->extracted_content));
    }

    /**
     * Documentación de la compañía, entera, con la regla de lectura.
     *
     * Reemplaza a la búsqueda por similitud, que se midió y no sirve acá: sobre 7 consultas
     * cuyo dato SÍ estaba en la documentación, los dos aciertos quedaron MÁS LEJOS (0,3890 y
     * 0,4034 de distancia coseno) que todos los fallos (0,2608 a 0,3766). No existe umbral que
     * los separe — el fallo típico es de especificidad, no de tema: una pregunta de granizo
     * sobre un auto devolvía el cuadro de camiones de Triunfo, que también habla de granizo.
     *
     * @param  Collection<int, CoverageDocument>  $docs
     */
    private function documentationBlock(Collection $docs): string
    {
        $encabezado = "\n\n## DOCUMENTACION DE LA COMPANIA\n\n";

        if ($docs->isEmpty()) {
            return $encabezado
                ."NO HAY DOCUMENTACION CARGADA para esta compania.\n\n"
                .'No tenes con que responder topes, montos, cantidades de eventos, kilometros ni '
                .'condiciones por antiguedad. Decilo: no lo tenes verificado. NO lo deduzcas del '
                .'nombre del plan ni del conocimiento general del mercado.';
        }

        if ($this->needsSearchFallback($docs)) {
            return $encabezado
                .'La documentacion de esta compania no entra completa en contexto, asi que tenes '
                ."la tool `search_company_documentation` para buscar fragmentos.\n"
                .'CUIDADO: la busqueda devuelve lo mas parecido, NO necesariamente lo que responde. '
                .'Verifica que el fragmento hable del plan y del tipo de vehiculo que te preguntan '
                .'antes de usarlo.';
        }

        $cuerpo = $docs->map(fn (CoverageDocument $d): string => sprintf(
            "### %s — %s%s\n\n%s",
            $d->document_type,
            $d->original_filename,
            $d->version !== null && $d->version !== '' ? " (version {$d->version})" : '',
            (string) $d->extracted_content,
        ))->implode("\n\n---\n\n");

        return $encabezado
            ."Esto es TODA la documentacion cargada de la compania. No hay nada mas.\n\n"
            ."COMO LEERLA:\n"
            .'- Ubica la fila/columna del PLAN COTIZADO que figura en la consulta. Los cuadros '
            ."tienen una columna por plan y el nombre puede diferir del de la cotizacion.\n"
            .'- Si el plan cotizado NO figura en el cuadro, decilo: no tenes respaldo para ese '
            ."plan. NO uses la columna de al lado.\n"
            .'- Fijate en el segmento: si el cuadro es de camiones o acoplados y te preguntan por '
            ."un auto, NO aplica.\n"
            .'- Si el dato no esta en este texto, NO esta especificado. Decilo asi. No lo '
            ."completes con lo que sabes del mercado.\n\n"
            .$cuerpo;
    }

    /**
     * Suma asegurada y, si el título la expresa, la franquicia ya resuelta en pesos.
     *
     * La aritmética se hace acá y no la hace el modelo. Antes el bloque no incluía siquiera
     * `sum_asegurada`, así que una pregunta por el monto de la franquicia era literalmente
     * incontestable con lo que el experto tenía: el porcentaje viaja en el título y la base
     * no viajaba.
     */
    private function sumaYFranquicia(QuoteAlternative $alt): string
    {
        $suma = (float) $alt->sum_asegurada;

        if ($suma <= 0.0) {
            return '';
        }

        $linea = 'Suma asegurada: $'.number_format($suma, 0, ',', '.')."\n";
        $franquicia = $alt->franquicia();

        if ($franquicia === null) {
            return $linea
                .'Franquicia: no se puede derivar del titulo de este plan. Si el cliente pregunta '
                ."por el monto, sale de la documentacion; si no esta ahi, no lo tenes.\n";
        }

        return $linea.sprintf(
            "Franquicia: %s%% de la suma asegurada = $%s (segun el titulo del plan: \"%s\")\n",
            rtrim(rtrim(number_format($franquicia['porcentaje'], 1, ',', ''), '0'), ','),
            number_format($franquicia['monto'], 0, ',', '.'),
            $franquicia['origen'],
        );
    }

    /**
     * Bloque `DATOS DEL PRODUCTO` que se le inyecta al experto.
     *
     * La enumeración siempre viene: un plan sin `features_tags` no es ofrecible y no llega hasta
     * acá — sin la lista no se puede explicar contractualmente qué cubre. Ver
     * {@see QuoteAlternative::esOfrecible()}.
     */
    private function productBlock(QuoteAlternative $alt): string
    {
        return "\n\n## DATOS DEL PRODUCTO (fuente primaria)\n\n"
            ."Aseguradora: {$alt->aseguradora}\n"
            ."Plan: {$alt->titulo} — {$alt->descripcion}\n"
            .$this->sumaYFranquicia($alt)
            ."Nivel: {$alt->normalized_grade}\n"
            .'Features incluidas: '.implode(', ', $alt->features_tags ?? [])."\n"
            ."Detalle:\n"
            .collect($alt->full_details ?? [])
                ->map(fn (mixed $v, string $k): string => "- {$k}: {$v}")->implode("\n")
            ."\n\nEsta enumeracion esta COMPLETA: es la lista de riesgos que cubre el plan. "
            .'Una cobertura que no figura aca, no esta incluida.';
    }
}
