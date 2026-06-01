<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PolizaEstado;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de pólizas para la Home y el detalle de cada card.
 *
 * Identidad = email verificado por OAuth. El tomador propio se resuelve por
 * email (resolveCustomer); los riesgos compartidos también se buscan por email.
 * Una cuenta sin Customer (invitado / sin pólizas) recibe listas vacías, no error.
 */
class PolizasController extends Controller
{
    /**
     * GET /polizas
     *
     * Devuelve el bloque PAS único + pólizas propias + riesgos compartidos.
     * Spec v2 §5.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();
        $customer = $account->resolveCustomer();

        $propias = $this->polizasPropias($customer);
        $compartidos = $this->riesgosCompartidos($account->email);
        $pas = $customer?->pas;

        return response()->json([
            'pas' => $pas ? $this->pasPayload($pas) : null,
            'polizas_propias' => $propias,
            'riesgos_compartidos' => $compartidos,
        ]);
    }

    /**
     * GET /polizas/{id}
     *
     * Detalle de una póliza. Accesible si el risk pertenece al Customer del
     * usuario, o si el risk está compartido con su email.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $poliza = Poliza::with('risk.customer.pas')->find($id);

        if (! $poliza) {
            throw new ApiException('No encontramos esa póliza.', 'POLIZA_NOT_FOUND', 404);
        }

        if (! $this->canAccessRisk($account, $poliza->risk)) {
            throw new ApiException('No tenés acceso a esa póliza.', 'POLIZA_FORBIDDEN', 403);
        }

        return response()->json($this->polizaPayload($poliza));
    }

    /** @return array<int, array<string, mixed>> */
    private function polizasPropias(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        $polizas = Poliza::with('risk')
            ->whereHas('risk', fn ($q) => $q->where('customer_id', $customer->id))
            ->where('estado', PolizaEstado::Vigente)
            ->get()
            ->sortByDesc('sum_asegurada')
            ->values();

        return $polizas->map(fn (Poliza $p) => $this->polizaPayload($p))->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function riesgosCompartidos(string $email): array
    {
        $shared = SharedRisk::accessible()
            ->forEmail($email)
            ->with(['risk.customer.pas', 'risk.polizas' => fn ($q) => $q->where('estado', PolizaEstado::Vigente)])
            ->get();

        return $shared->map(function (SharedRisk $sr): array {
            $risk = $sr->risk;
            $poliza = $risk->polizas->first();
            $titularPas = $risk->customer->pas;

            return [
                'id' => $poliza?->id,
                'risk_id' => $risk->id,
                'label' => $risk->label,
                'patente' => $risk->metadata['patente'] ?? null,
                'titular' => $risk->customer->name,
                'company' => $poliza?->company,
                'coverage' => $poliza?->coverage,
                'sum_asegurada' => $poliza?->sum_asegurada,
                'vigencia' => $poliza?->vigencia?->toDateString(),
                'pas' => $titularPas ? [
                    'name' => $titularPas->name,
                    'phone' => $titularPas->pasPhone(),
                ] : null,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function polizaPayload(Poliza $p): array
    {
        $risk = $p->risk;

        return [
            'id' => $p->id,
            'risk_id' => $risk->id,
            'label' => $risk->label,
            'patente' => $risk->metadata['patente'] ?? null,
            'numero' => $p->numero,
            'company' => $p->company,
            'coverage' => $p->coverage,
            'coverage_detail' => $p->coverage_detail,
            'sum_asegurada' => $p->sum_asegurada,
            'cuota' => $p->cuota,
            'cuota_due' => $p->cuota_due?->toDateString(),
            'vigencia' => $p->vigencia->toDateString(),
            'estado' => $p->estado->value,
            'metadata' => $risk->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function pasPayload(User $pas): array
    {
        return [
            'id' => $pas->id,
            'name' => $pas->name,
            'matricula' => $pas->pasMatricula(),
            'phone' => $pas->pasPhone(),
            'avatar_url' => $pas->pasAvatarUrl(),
        ];
    }

    private function canAccessRisk(MobileAccount $account, Risk $risk): bool
    {
        $customer = $account->resolveCustomer();
        if ($customer && $risk->customer_id === $customer->id) {
            return true;
        }

        return SharedRisk::accessible()
            ->forEmail($account->email)
            ->where('risk_id', $risk->id)
            ->exists();
    }
}
