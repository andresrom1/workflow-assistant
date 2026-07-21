<?php

namespace App\Http\Controllers\Mobile;

use App\Contracts\WhatsAppDispatcher;
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
 *  1) Resuelve qué PAS notificar por prelación (spec v2 §4.2, criterio de
 *     Fase 9): tu PAS → PAS del titular del vehículo compartido de mayor
 *     sum_asegurada → PAS por default de MANGO. Mejor un PAS conocido, y si no
 *     hay, cualquier PAS de MANGO antes que dejar a la persona sin a quién llamar.
 *  2) Dispatcha WhatsApp al PAS — en mock, no se dispatcha realmente;
 *     se devuelve el PAS resuelto para que la app sepa a quién avisó.
 *  3) Rate-limit liviano en la ruta (el lock real de 48hs vive en el cliente).
 */
class SiniestroController extends Controller
{
    public function __construct(private readonly WhatsAppDispatcher $dispatcher) {}

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

        // Avisar al PAS que su cliente reportó un siniestro, con el contacto
        // para llamarlo. Sin ubicación (el siniestro no la lleva).
        $customerName = $account->name ?? 'Cliente MANGO';
        $phone = data_get($account->resolveCustomer(), 'phone');
        $customerContact = is_string($phone) ? $phone : $account->email;
        $this->dispatcher->siniestroNoticeToPas($pas, $customerName, $customerContact);

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
        // Tier 1: tu PAS — alguien que ya te conoce.
        $customer = $account->resolveCustomer();
        if ($customer?->pas) {
            return $customer->pas;
        }

        // Tier 2: PAS del titular del vehículo compartido de mayor sum_asegurada
        // (criterio determinístico). No conoce a la persona, pero conoce el auto.
        // Mismo criterio de visibilidad que /polizas: `accessible()` (no revocado
        // ni vencido), SIN exigir accepted_at — el modelo de Fase 9 no acepta.
        $email = $account->email;
        $candidate = Poliza::query()
            ->where('estado', PolizaEstado::Vigente)
            ->whereHas('risk.sharedRisks', function (Builder $q) use ($email): void {
                // Mismas condiciones que SharedRisk::accessible(): no revocado ni
                // vencido, sin exigir accepted_at (modelo sin aceptación, Fase 9).
                $q->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->where('shared_with_email', $email);
            })
            ->orderByDesc('sum_asegurada')
            ->with('risk.customer.pas')
            ->first();

        if ($candidate?->risk?->customer?->pas) {
            return $candidate->risk->customer->pas;
        }

        // Tier 3: PAS por default de MANGO. Mejor un PAS que un 0800 o nada.
        // Fuente única de verdad del default (resuelve por config con fallback canónico).
        return User::defaultPas();
    }
}
