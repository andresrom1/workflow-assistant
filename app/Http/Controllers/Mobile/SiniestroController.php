<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PolizaEstado;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aviso de siniestro al PAS — spec v2 §4.2.
 *
 * El cliente confirma con un slider en la app; este endpoint:
 *  1) Resuelve qué PAS notificar (asesor dedicado del titular o, en su
 *     defecto, PAS del titular del vehículo compartido de mayor sum_asegurada).
 *  2) Dispatcha WhatsApp al PAS — en mock, no se dispatcha realmente;
 *     se devuelve el PAS resuelto para que la app sepa a quién avisó.
 *  3) Rate-limit liviano en la ruta (el lock real de 48hs vive en el cliente).
 */
class SiniestroController extends Controller
{
    /**
     * POST /siniestro
     */
    public function notify(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();
        $pas = $this->resolvePas($account);

        if (! $pas) {
            throw new ApiException(
                'Todavía no tenés un PAS asignado. Escribinos por WhatsApp.',
                'NO_PAS_ASSIGNED',
                422,
            );
        }

        // TODO: cuando lleguen las APIs reales, dispatchar WhatsApp acá.
        // En la fase mock solo devolvemos el PAS resuelto.

        return response()->json([
            'pas' => [
                'name' => $pas->name,
                'phone' => $pas->pasPhone(),
            ],
            'notified_at' => now()->toIso8601String(),
        ]);
    }

    private function resolvePas(MobileAccount $account): ?User
    {
        $customer = $account->resolveCustomerForMock();
        if ($customer?->pas) {
            return $customer->pas;
        }

        // Fallback: PAS del titular del vehículo compartido de mayor
        // sum_asegurada (criterio determinístico, spec v2 §4.2).
        $email = $account->email;
        $candidate = Poliza::query()
            ->where('estado', PolizaEstado::Vigente)
            ->whereHas('risk.sharedRisks', function (Builder $q) use ($email): void {
                $q->whereNotNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->where('shared_with_email', $email);
            })
            ->orderByDesc('sum_asegurada')
            ->with('risk.customer.pas')
            ->first();

        return $candidate?->risk?->customer?->pas;
    }
}
