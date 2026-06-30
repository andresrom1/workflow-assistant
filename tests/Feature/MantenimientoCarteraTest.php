<?php

use App\Enums\PolicyDocumentKind;
use App\Enums\PolizaEstado;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('requiere autenticación', function (): void {
    $this->get(route('mantenimiento-cartera'))->assertRedirect('/login');
});

// ─── Modelo: esRenovable() ──────────────────────────────────────────────────

it('esRenovable es true para una vigente limpia y para una vencida sin sucesora', function (): void {
    $vigente = Poliza::factory()->create(['estado' => PolizaEstado::Vigente]);
    $vencida = Poliza::factory()->create(['estado' => PolizaEstado::Vencida]);

    expect($vigente->esRenovable())->toBeTrue()
        ->and($vencida->esRenovable())->toBeTrue();
});

it('esRenovable es false para período corto, descartada o con sucesora', function (): void {
    $periodoCorto = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'periodo_corto' => true]);
    $descartada = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'no_renovar_at' => now()]);

    $conSucesora = Poliza::factory()->create(['estado' => PolizaEstado::Vencida]);
    Poliza::factory()->create([
        'risk_id' => $conSucesora->risk_id,
        'estado' => PolizaEstado::Vigente,
        'contrato_anterior_id' => $conSucesora->id,
    ]);

    expect($periodoCorto->esRenovable())->toBeFalse()
        ->and($descartada->esRenovable())->toBeFalse()
        ->and($conSucesora->fresh()->esRenovable())->toBeFalse();
});

// ─── Modelo: scopeARenovar() ────────────────────────────────────────────────

it('scopeARenovar incluye vigente venciendo y vencida sin sucesora, excluye el resto', function (): void {
    $venciendo = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(10), 'numero' => 'A-VENCIENDO']);
    $vencidaSinSuc = Poliza::factory()->create(['estado' => PolizaEstado::Vencida, 'vigencia' => now()->subDays(3), 'numero' => 'A-VENCIDA']);

    // Excluidas:
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(10), 'periodo_corto' => true, 'numero' => 'X-CORTO']);
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(10), 'no_renovar_at' => now(), 'numero' => 'X-DESCARTADA']);
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(200), 'numero' => 'X-LEJOS']);
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => null, 'numero' => 'X-SINVIG']);

    $conSucesora = Poliza::factory()->create(['estado' => PolizaEstado::Vencida, 'vigencia' => now()->subDays(2), 'numero' => 'X-CONSUC']);
    Poliza::factory()->create(['risk_id' => $conSucesora->risk_id, 'estado' => PolizaEstado::Vigente, 'contrato_anterior_id' => $conSucesora->id]);

    $ids = Poliza::aRenovar()->pluck('id')->all();

    expect($ids)->toContain($venciendo->id)
        ->toContain($vencidaSinSuc->id)
        ->toHaveCount(2);
});

// ─── Reporte por póliza ─────────────────────────────────────────────────────

it('renderiza el reporte ordenado por urgencia: vencida-sin-sucesora arriba', function (): void {
    // Renovación vencida-sin-sucesora → urgencia negativa, queda arriba. Sin checklist
    // de docs (de las vencidas no se chasea documentación).
    Poliza::factory()->create([
        'estado' => PolizaEstado::Vencida,
        'vigencia' => now()->subDays(5),
        'numero' => 'RENOV-VENCIDA',
    ]);

    // Vigente con documentación incompleta y vencimiento lejos → entra por docs.
    Poliza::factory()->create([
        'estado' => PolizaEstado::Vigente,
        'vigencia' => now()->addDays(300),
        'numero' => 'DOC-INCOMPLETA',
    ]);

    $this->actingAs($this->user)
        ->get(route('mantenimiento-cartera'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('MantenimientoCartera/Index')
            ->where('filas.total', 2)
            ->where('filas.data.0.numero', 'RENOV-VENCIDA')
            ->where('filas.data.0.docs', null)
            ->where('filas.data.0.renovacion.nivel', 'vencida')
            ->where('filas.data.0.renovacion.accionable', true)
            ->where('filas.data.1.numero', 'DOC-INCOMPLETA')
            ->where('filas.data.1.docs.completos', 0)
            ->where('filas.data.1.docs.total', 3)
            ->where('filas.data.1.renovacion.nivel', 'al_dia'));
});

it('una sola fila por póliza aunque combine documentación y renovación', function (): void {
    Poliza::factory()->create([
        'estado' => PolizaEstado::Vigente,
        'vigencia' => now()->addDays(10),
        'numero' => 'DUAL',
    ]);

    $this->actingAs($this->user)
        ->get(route('mantenimiento-cartera'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filas.total', 1)
            // "pendientes" cuenta acciones, no pólizas: 3 docs faltantes + 1 renovación.
            ->where('pendientes', 4)
            ->where('filas.data.0.docs.completos', 0)
            ->where('filas.data.0.renovacion.nivel', 'vence_pronto')
            ->where('filas.data.0.renovacion.accionable', true));
});

it('excluye completas, descartadas y período corto al día', function (): void {
    // Vigente completa + vigencia lejos → no entra por ningún eje.
    $completa = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(300)]);
    // Período corto venciendo pero completa → no renueva + docs ok → no entra.
    $corto = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(10), 'periodo_corto' => true]);
    // Descartada venciendo pero completa → fuera de la cola de renovación + docs ok → no entra.
    $descartada = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(10), 'no_renovar_at' => now()]);

    foreach ([$completa, $corto, $descartada] as $poliza) {
        foreach (PolicyDocumentKind::expectedForActivePolicy() as $k) {
            PolicyDocument::factory()->adminUpload()->create(['poliza_id' => $poliza->id, 'kind' => $k]);
        }
    }

    $this->actingAs($this->user)
        ->get(route('mantenimiento-cartera'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filas.total', 0));
});

it('la ventana de renovación es seleccionable (30/60/90)', function (): void {
    // Vigente completa que vence en 75 días: fuera de la ventana default (30), dentro de 90.
    $poliza = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(75)]);
    foreach (PolicyDocumentKind::expectedForActivePolicy() as $k) {
        PolicyDocument::factory()->adminUpload()->create(['poliza_id' => $poliza->id, 'kind' => $k]);
    }

    $this->actingAs($this->user)
        ->get(route('mantenimiento-cartera'))
        ->assertInertia(fn ($page) => $page->where('filas.total', 0));

    $this->actingAs($this->user)
        ->get(route('mantenimiento-cartera', ['dias' => 90]))
        ->assertInertia(fn ($page) => $page
            ->where('filas.total', 1)
            ->where('filas.data.0.renovacion.nivel', 'vence_pronto'));
});
