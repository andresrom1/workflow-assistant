<?php

/**
 * Invariantes de la configuración de colas. Son tests de config, no de comportamiento: fallan
 * cuando alguien agrega un job o un worker sin cerrar el círculo, que es exactamente cómo se
 * apilaron las capas que este refactor vino a desarmar.
 *
 * Lo que cuidan:
 *
 *  1. Todo job declara su propia política (`$timeout`, `$tries`, cola explícita). Si la hereda del
 *     `--timeout` del worker, el día que dos colas compartan proceso hereda el valor equivocado.
 *  2. Toda cola nombrada por un job tiene un worker que la lea. Una cola sin lector acumula filas
 *     en silencio, sin error visible en ningún lado (fue el caso de `semantic-analysis`).
 *  3. Para cada worker: el `retry_after` de su conexión supera el `$timeout` más largo que puede
 *     correr. Si se viola, la cola re-reserva un job que sigue corriendo y quedan dos en paralelo.
 */

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Todos los workers del despliegue: los residentes que arranca supervisor y el de
 * `background`, que no es residente y lo levanta el scheduler bajo demanda. Los dos se
 * declaran como `queue:work`, así que los dos tienen que cumplir el mismo invariante.
 *
 * @return list<array{nombre: string, conexion: string, colas: list<string>, timeout: int}>
 */
function workersDeclarados(): array
{
    $patronComando = 'queue:work (?<conexion>\w+) --queue=(?<colas>[\w,-]+)[^\r\n]*?--timeout=(?<timeout>\d+)';

    $fuentes = [
        // Residentes: supervisor los nombra con [program:X].
        ['.docker/start.sh', '/\[program:(?<nombre>[\w-]+)\]\s+command=php artisan '.$patronComando.'/'],
        // Bajo demanda: el scheduler no le pone nombre, se lo damos nosotros.
        ['routes/console.php', "/Schedule::command\('{$patronComando}/"],
    ];

    $workers = [];

    foreach ($fuentes as [$ruta, $patron]) {
        preg_match_all($patron, (string) file_get_contents(base_path($ruta)), $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $workers[] = [
                'nombre' => $m['nombre'] ?? "scheduler:{$m['colas']}",
                'conexion' => $m['conexion'],
                'colas' => explode(',', $m['colas']),
                'timeout' => (int) $m['timeout'],
            ];
        }
    }

    return $workers;
}

/**
 * Los jobs de la app, con la política que declara cada uno.
 *
 * La cola se lee del código fuente y no de una instancia porque instanciar exige los argumentos
 * del constructor de cada job; el `onQueue()` literal es igual de fiable y no obliga a mantener
 * un fixture por job.
 *
 * @return array<class-string, array{cola: ?string, tries: ?int, timeout: ?int}>
 */
function jobsDeclarados(): array
{
    $jobs = [];

    foreach (glob(app_path('Jobs/*.php')) ?: [] as $path) {
        $clase = 'App\\Jobs\\'.basename($path, '.php');

        if (! is_subclass_of($clase, ShouldQueue::class)) {
            continue;
        }

        $defaults = (new ReflectionClass($clase))->getDefaultProperties();
        preg_match("/\\\$this->onQueue\('([\w-]+)'\)/", (string) file_get_contents($path), $m);

        $jobs[$clase] = [
            'cola' => $m[1] ?? null,
            'tries' => isset($defaults['tries']) ? (int) $defaults['tries'] : null,
            'timeout' => isset($defaults['timeout']) ? (int) $defaults['timeout'] : null,
        ];
    }

    return $jobs;
}

it('mantiene el mapa de jobs por cola', function (): void {
    // El reparto completo, explícito. No es redundante con los invariantes de abajo: éstos
    // dicen que el reparto es CONSISTENTE, y esto dice cuál es. Si alguien mueve un job de
    // cola, acá se entera de que cambió la topología en vez de descubrirlo en producción.
    $esperado = [
        // El turno del LLM. Aislado en su propio worker: 4-95s por turno.
        'whatsapp-ai' => ['NotifyClientCheckoutCompleted', 'NotifyClientQuoteReady', 'ProcessConversationInbox'],

        // Camino caliente, un worker compartido. Todo corto y de cara al cliente.
        'default' => ['AnalyzeConversationHealthJob', 'HandleUserIdUpdate', 'ProcessWhatsAppMessage', 'UpdateMessageStatus'],
        'whatsapp-outbound' => ['NotifyClientEmissionFailed', 'NotifyClientQuoteFailed', 'SendWhatsAppMessage', 'SendWhatsAppTemplate'],
        'media' => ['ProcessMediaAttachment'],

        // Aislada: retiene el proceso 30-360s contra el proveedor.
        'quotes' => ['ResolveQuote'],

        // Sin worker residente: la levanta el scheduler bajo demanda.
        'background' => [
            'AnalyzeConversationSemanticsJob', 'CapturePendingPolicyDocuments', 'CloseInvoiceBatch',
            'DeleteOrphanPhoto', 'EmitInvoice', 'EmitirPoliza', 'ExtractCoverageDocumentText',
            'ExtractIngestedDocument', 'PublishDocumentAvailable', 'SendPolicyDocumentsToClient',
        ],
    ];

    $real = [];
    foreach (jobsDeclarados() as $clase => $p) {
        $real[$p['cola']][] = class_basename($clase);
    }

    foreach ($real as &$jobs) {
        sort($jobs);
    }

    ksort($real);
    ksort($esperado);

    expect($real)->toBe($esperado);
});

it('encuentra los workers y los jobs', function (): void {
    expect(workersDeclarados())->not->toBeEmpty()
        ->and(jobsDeclarados())->not->toBeEmpty();
});

it('cada job declara su cola, sus reintentos y su timeout', function (): void {
    $incompletos = [];

    foreach (jobsDeclarados() as $clase => $p) {
        $falta = array_keys(array_filter([
            'cola' => $p['cola'] === null,
            'tries' => $p['tries'] === null,
            'timeout' => $p['timeout'] === null,
        ]));

        if ($falta !== []) {
            $incompletos[] = class_basename($clase).' (falta: '.implode(', ', $falta).')';
        }
    }

    expect($incompletos)->toBe([], "Estos jobs heredarían la política del worker:\n- ".implode("\n- ", $incompletos));
});

it('toda cola usada por un job tiene un worker que la lee', function (): void {
    $atendidas = array_merge(...array_column(workersDeclarados(), 'colas'));

    $huerfanas = [];
    foreach (jobsDeclarados() as $clase => $p) {
        if ($p['cola'] !== null && ! in_array($p['cola'], $atendidas, true)) {
            $huerfanas[] = "{$p['cola']} (".class_basename($clase).')';
        }
    }

    expect(array_unique($huerfanas))->toBe([], 'Colas sin worker: los jobs se encolan y no los corre nadie.');
});

it('el retry_after de cada worker supera el timeout más largo que puede correr', function (): void {
    $porCola = [];
    foreach (jobsDeclarados() as $p) {
        if ($p['cola'] !== null && $p['timeout'] !== null) {
            $porCola[$p['cola']] = max($porCola[$p['cola']] ?? 0, $p['timeout']);
        }
    }

    foreach (workersDeclarados() as $w) {
        $retryAfter = (int) config("queue.connections.{$w['conexion']}.retry_after");
        $masLargo = max([$w['timeout'], ...array_map(fn (string $c): int => $porCola[$c] ?? 0, $w['colas'])]);

        expect($retryAfter)->toBeGreaterThan(
            $masLargo,
            "El worker `{$w['nombre']}` usa la conexión `{$w['conexion']}` (retry_after {$retryAfter}s) ".
            "pero puede retener un job hasta {$masLargo}s: la cola lo re-reservaría mientras corre.",
        );
    }
});

it('el techo del worker no queda por debajo de un job que atiende', function (): void {
    $porCola = [];
    foreach (jobsDeclarados() as $p) {
        if ($p['cola'] !== null && $p['timeout'] !== null) {
            $porCola[$p['cola']] = max($porCola[$p['cola']] ?? 0, $p['timeout']);
        }
    }

    foreach (workersDeclarados() as $w) {
        foreach ($w['colas'] as $cola) {
            expect($w['timeout'])->toBeGreaterThanOrEqual(
                $porCola[$cola] ?? 0,
                "El worker `{$w['nombre']}` declara --timeout={$w['timeout']} pero la cola `{$cola}` ".
                'tiene un job más largo. El job gana igual, pero el techo declarado miente.',
            );
        }
    }
});
