<?php

namespace App\Http\Controllers;

use App\Enums\PolicyDocumentKind;
use App\Enums\PolizaEstado;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de mantenimiento de cartera: un **reporte operativo por póliza**. Cada fila
 * es una póliza activa presentada como un checklist accionable que fusiona las dos
 * fuentes de trabajo — la documentación esperada (cada faltante lleva a su carga) y la
 * renovación (derivada de `vigencia`) — priorizadas por urgencia en un solo eje.
 *
 * Todo derivado, sin tabla de tareas: la fila se auto-vacía por hechos de dominio
 * (se carga el doc → se tilda el ítem; existe sucesora → la renovación cae). El sistema
 * no emite ni renueva; "Renovar" es un acto de registro. Una póliza aparece si le falta
 * ≥1 documento **o** tiene una renovación pendiente (dentro de la ventana o vencida).
 */
class MantenimientoCarteraController extends Controller
{
    /**
     * Criticidad del documento faltante en días-equivalentes (baseline cuando la
     * póliza no vence dentro de la ventana). Lógica de priorización local — no del
     * enum. El peor faltante (menor número) define la urgencia de la fila.
     */
    private const DOC_CRITICIDAD = [
        PolicyDocumentKind::Poliza->value => 15,
        PolicyDocumentKind::CirculationCard->value => 30,
    ];

    private const DOC_CRITICIDAD_DEFAULT = 45;

    public function __invoke(Request $request): Response
    {
        $perPage = max(1, (int) $request->input('per_page', 25));
        $dias = max(1, min(365, (int) $request->input('dias', 30)));

        $expected = PolicyDocumentKind::expectedForActivePolicy();

        $candidatas = Poliza::query()
            ->with(['risk.customer', 'documents:id,poliza_id,kind'])
            ->withCount('sucesoras')
            ->where(function ($q): void {
                // Activas (vigente/emitida) → candidatas por documentación.
                $q->whereIn('estado', [PolizaEstado::Vigente, PolizaEstado::Emitida])
                    // Vencidas estructuralmente renovables → candidatas por renovación (escalado).
                    ->orWhere(function ($q2): void {
                        $q2->where('estado', PolizaEstado::Vencida)
                            ->where('periodo_corto', false)
                            ->whereNull('no_renovar_at')
                            ->whereDoesntHave('sucesoras');
                    });
            })
            ->get();

        $filas = [];
        $pendientes = 0;

        foreach ($candidatas as $p) {
            $docs = $this->documentos($p, $expected);
            $renovacion = $this->renovacion($p, $dias);

            $docsMissing = $docs !== null && $docs['completos'] < $docs['total'];
            $renovPending = $renovacion !== null && $renovacion['accionable'];

            if (! $docsMissing && ! $renovPending) {
                continue;
            }

            // "Pendientes" = acciones a resolver, no pólizas: cada doc faltante + la
            // renovación accionable suman uno.
            $pendientes += ($docs !== null ? $docs['total'] - $docs['completos'] : 0)
                + ($renovPending ? 1 : 0);

            $urgencia = $this->urgencia($p, $docs, $renovacion, $dias);

            $filas[] = [
                'poliza_id' => $p->id,
                'numero' => $p->numero,
                'company' => $p->company,
                'estado' => $p->estado->value,
                'patente' => $p->risk->metadata['patente'] ?? null,
                'label' => $p->risk->label,
                'cliente' => $p->risk->customer?->name,
                'urgencia' => $urgencia,
                'urgencia_nivel' => $this->nivel($urgencia),
                'docs' => $docs,
                'renovacion' => $renovacion,
            ];
        }

        // Un solo eje: menor `urgencia` = más urgente. `poliza_id` desempata estable.
        $ordenadas = collect($filas)
            ->sortBy([['urgencia', 'asc'], ['poliza_id', 'asc']])
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $ordenadas->forPage($page, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $ordenadas->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('MantenimientoCartera/Index', [
            'filas' => $paginator,
            'pendientes' => $pendientes,
            'filters' => ['per_page' => $perPage, 'dias' => $dias],
        ]);
    }

    /**
     * Checklist de documentos esperados con su estado. Solo aplica a pólizas activas
     * (vigente/emitida); de las vencidas no se chasea documentación → `null`.
     *
     * @param  list<PolicyDocumentKind>  $expected
     * @return array{items: list<array{kind: string, label: string, presente: bool}>, completos: int, total: int}|null
     */
    private function documentos(Poliza $p, array $expected): ?array
    {
        if (! in_array($p->estado, [PolizaEstado::Vigente, PolizaEstado::Emitida], true)) {
            return null;
        }

        $present = $p->documents->map(fn (PolicyDocument $d): PolicyDocumentKind => $d->kind)->unique();

        $items = array_map(
            fn (PolicyDocumentKind $k): array => [
                'kind' => $k->value,
                'label' => $k->label(),
                'presente' => $present->contains($k),
            ],
            $expected,
        );

        $completos = count(array_filter($items, fn (array $i): bool => $i['presente']));

        return ['items' => $items, 'completos' => $completos, 'total' => count($items)];
    }

    /**
     * Estado de renovación derivado de `vigencia`. Solo aplica a vigente/vencida; en
     * emitida es N/A → `null`. `accionable` marca los niveles que abren "Renovar".
     *
     * @return array{nivel: string, dias: int|null, vigencia: string|null, accionable: bool}|null
     */
    private function renovacion(Poliza $p, int $dias): ?array
    {
        if (! in_array($p->estado, [PolizaEstado::Vigente, PolizaEstado::Vencida], true)) {
            return null;
        }

        $diasVenc = $p->vigencia !== null
            ? (int) Carbon::today()->diffInDays($p->vigencia, false)
            : null;

        [$nivel, $accionable] = match (true) {
            $p->periodo_corto => ['no_renueva', false],
            $p->no_renovar_at !== null => ['descartada', false],
            $p->sucesoras_count > 0 => ['renovada', false],
            $diasVenc === null => ['sin_vigencia', false],
            $diasVenc < 0 => ['vencida', true],
            $diasVenc <= $dias => ['vence_pronto', true],
            default => ['al_dia', false],
        };

        return [
            'nivel' => $nivel,
            'dias' => $diasVenc,
            'vigencia' => $p->vigencia?->toDateString(),
            'accionable' => $accionable,
        ];
    }

    /**
     * Urgencia de la fila (un eje, menor = más urgente). Toma la más urgente entre el
     * deadline de renovación (si está pendiente) y la criticidad de la documentación.
     *
     * @param  array{items: list<array{kind: string, label: string, presente: bool}>, completos: int, total: int}|null  $docs
     * @param  array{nivel: string, dias: int|null, vigencia: string|null, accionable: bool}|null  $renovacion
     */
    private function urgencia(Poliza $p, ?array $docs, ?array $renovacion, int $dias): int
    {
        $candidatos = [];

        if ($renovacion !== null && $renovacion['accionable'] && $renovacion['dias'] !== null) {
            $candidatos[] = $renovacion['dias'];
        }

        if ($docs !== null && $docs['completos'] < $docs['total']) {
            $faltantes = array_values(array_filter(
                $docs['items'],
                fn (array $i): bool => ! $i['presente'],
            ));
            $criticidad = $this->criticidad($faltantes);

            $diasVenc = $p->vigencia !== null
                ? (int) Carbon::today()->diffInDays($p->vigencia, false)
                : null;

            // Si vence dentro de la ventana, hereda esa proximidad; si no, baseline.
            $candidatos[] = ($diasVenc !== null && $diasVenc <= $dias) ? $diasVenc : $criticidad;
        }

        return $candidatos === [] ? self::DOC_CRITICIDAD_DEFAULT : min($candidatos);
    }

    /**
     * Criticidad del peor faltante (menor número = más crítico).
     *
     * @param  list<array{kind: string, label: string, presente: bool}>  $faltantes
     */
    private function criticidad(array $faltantes): int
    {
        $valores = array_map(
            fn (array $i): int => self::DOC_CRITICIDAD[$i['kind']] ?? self::DOC_CRITICIDAD_DEFAULT,
            $faltantes,
        );

        return $valores === [] ? self::DOC_CRITICIDAD_DEFAULT : min($valores);
    }

    private function nivel(int $urgencia): string
    {
        return match (true) {
            $urgencia < 0 => 'vencida',
            $urgencia <= 7 => 'critico',
            $urgencia <= 30 => 'pronto',
            default => 'normal',
        };
    }
}
