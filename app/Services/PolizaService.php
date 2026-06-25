<?php

namespace App\Services;

use App\Enums\PolizaEstado;
use App\Enums\RiskType;
use App\Models\Customer;
use App\Models\Poliza;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición manual de pólizas desde el panel.
 *
 * Resuelve el Risk (reusar uno existente del cliente o crear uno nuevo) y aplica
 * el constraint de dominio "una sola póliza `vigente` por Risk" (en código, no en
 * DB — ver PolizaEstado). Agnóstico del canal: recibe/retorna modelos de dominio.
 */
class PolizaService
{
    /**
     * @param  array<string, mixed>|null  $riskData  Datos de un Risk nuevo (vehículo) cuando no se reusa uno existente.
     * @param  array<string, mixed>  $polizaData
     */
    public function createManual(
        Customer $customer,
        ?int $existingRiskId,
        ?array $riskData,
        array $polizaData,
    ): Poliza {
        $risk = $existingRiskId !== null
            ? $this->resolveExistingRisk($customer, $existingRiskId)
            : $this->createRisk($customer, $riskData ?? []);

        $estado = $polizaData['estado'] instanceof PolizaEstado
            ? $polizaData['estado']
            : PolizaEstado::from($polizaData['estado']);

        $this->assertNoOtherVigente($risk->id, $estado, null);

        return $risk->polizas()->create($polizaData);
    }

    /**
     * @param  array<string, mixed>  $polizaData
     */
    public function update(Poliza $poliza, array $polizaData): Poliza
    {
        $estado = $polizaData['estado'] instanceof PolizaEstado
            ? $polizaData['estado']
            : PolizaEstado::from($polizaData['estado']);

        $this->assertNoOtherVigente($poliza->risk_id, $estado, $poliza->id);

        $poliza->update($polizaData);

        return $poliza->refresh();
    }

    /**
     * Renueva una póliza: abre una Poliza NUEVA sobre el MISMO Risk (número nuevo),
     * con `contrato_anterior_id` apuntando a la anterior, y marca la anterior como
     * `vencida` — en una sola transacción. Preserva el constraint "una vigente por
     * Risk" por construcción (la anterior deja de serlo antes de crear la nueva). La
     * anterior queda vencida + con sucesora → excluida de la cola. La documentación de
     * la nueva se carga aparte; el `SharedRisk` cuelga del Risk y sobrevive solo.
     *
     * El guard es `esRenovable()` (estructural): permite renovar una vencida sin
     * sucesora (caso escalado) y bloquea la doble renovación (ya tiene sucesora).
     *
     * @param  array<string, mixed>  $nuevaData  Payload de la póliza nueva (sin `estado`/`contrato_anterior_id`).
     */
    public function renovar(Poliza $anterior, array $nuevaData): Poliza
    {
        if (! $anterior->esRenovable()) {
            throw ValidationException::withMessages([
                'poliza' => 'Esta póliza no se puede renovar (período corto, ya renovada o descartada).',
            ]);
        }

        return DB::transaction(function () use ($anterior, $nuevaData): Poliza {
            $anterior->update(['estado' => PolizaEstado::Vencida]);

            return $anterior->risk->polizas()->create([
                ...$nuevaData,
                'estado' => PolizaEstado::Vigente,
                'contrato_anterior_id' => $anterior->id,
            ]);
        });
    }

    private function resolveExistingRisk(Customer $customer, int $riskId): Risk
    {
        $risk = $customer->risks()->find($riskId);

        if (! $risk instanceof Risk) {
            throw ValidationException::withMessages([
                'risk_id' => 'El vehículo seleccionado no pertenece a este cliente.',
            ]);
        }

        return $risk;
    }

    /**
     * @param  array<string, mixed>  $riskData
     */
    private function createRisk(Customer $customer, array $riskData): Risk
    {
        $marca = trim((string) ($riskData['marca'] ?? ''));
        $modelo = trim((string) ($riskData['modelo'] ?? ''));
        $patente = trim((string) ($riskData['patente'] ?? ''));

        $label = trim("{$marca} {$modelo}").($patente !== '' ? " ({$patente})" : '');

        return $customer->risks()->create([
            'type' => RiskType::Vehicle,
            'label' => $label !== '' ? $label : 'Vehículo',
            'metadata' => array_filter(
                $riskData,
                fn ($value): bool => $value !== null && $value !== '',
            ),
        ]);
    }

    private function assertNoOtherVigente(int $riskId, PolizaEstado $estado, ?int $exceptPolizaId): void
    {
        if ($estado !== PolizaEstado::Vigente) {
            return;
        }

        $exists = Poliza::query()
            ->where('risk_id', $riskId)
            ->where('estado', PolizaEstado::Vigente)
            ->when($exceptPolizaId !== null, fn ($q) => $q->whereKeyNot($exceptPolizaId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'estado' => 'Este vehículo ya tiene una póliza vigente. Cambiá el estado de la póliza vigente actual antes de marcar otra como vigente.',
            ]);
        }
    }
}
