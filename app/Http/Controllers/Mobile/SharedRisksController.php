<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Cuenta Compartida (`shared_risk`) — spec v2 §4.6.
 *
 * Endpoints sobre el Risk (no sobre la póliza, aunque la spec menciona
 * polizaId — se acepta polizaId y resolvemos al risk subyacente porque
 * la app maneja IDs de pólizas en las cards).
 *
 * Límite: máx 2 conductores activos por Risk.
 */
class SharedRisksController extends Controller
{
    public const MAX_ACTIVE_PER_RISK = 2;

    public function index(Request $request, int $polizaId): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();
        $risk = $this->riskOwnedByUser($account, $polizaId);

        $shared = SharedRisk::where('risk_id', $risk->id)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedRisk $sr) => $this->payload($sr))
            ->all();

        return response()->json(['data' => $shared]);
    }

    public function invite(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $data = $request->validate([
            'poliza_id' => ['required', 'integer'],
            'shared_with_email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $risk = $this->riskOwnedByUser($account, (int) $data['poliza_id']);

        $activeCount = SharedRisk::where('risk_id', $risk->id)
            ->whereNull('revoked_at')
            ->count();

        if ($activeCount >= self::MAX_ACTIVE_PER_RISK) {
            throw new ApiException(
                'Ya tenés el máximo de conductores adicionales para este vehículo ('.self::MAX_ACTIVE_PER_RISK.').',
                'SHARED_RISK_LIMIT_REACHED',
                422,
            );
        }

        // Idempotencia: si ya existe una invitación NO revocada al mismo email
        // (pendiente o aceptada), la devolvemos en lugar de duplicar.
        $existing = SharedRisk::where('risk_id', $risk->id)
            ->where('shared_with_email', $data['shared_with_email'])
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            return response()->json($this->payload($existing));
        }

        $invitation = SharedRisk::create([
            'risk_id' => $risk->id,
            'shared_with_email' => $data['shared_with_email'],
            'invited_by_mobile_account_id' => $account->id,
            'token' => Str::random(48),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json($this->payload($invitation), 201);
    }

    public function revoke(Request $request, int $polizaId, int $conductorId): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();
        $risk = $this->riskOwnedByUser($account, $polizaId);

        $sr = SharedRisk::where('risk_id', $risk->id)
            ->where('id', $conductorId)
            ->whereNull('revoked_at')
            ->first();

        if (! $sr) {
            throw new ApiException(
                'No encontramos esa invitación activa.',
                'SHARED_RISK_NOT_FOUND',
                404,
            );
        }

        $sr->update(['revoked_at' => now()]);

        return response()->json([], 204);
    }

    /**
     * Carga la Poliza por id, valida que su Risk pertenezca al Customer del
     * usuario (titular). Lanza 404 si la póliza no existe; 403 si no es
     * titular.
     */
    private function riskOwnedByUser(MobileAccount $account, int $polizaId): Risk
    {
        $poliza = Poliza::with('risk')->find($polizaId);

        if (! $poliza) {
            throw new ApiException('No encontramos esa póliza.', 'POLIZA_NOT_FOUND', 404);
        }

        $customer = $account->resolveCustomerForMock();
        if (! $customer || $poliza->risk->customer_id !== $customer->id) {
            throw new ApiException(
                'Solo el titular puede gestionar conductores adicionales.',
                'SHARED_RISK_NOT_OWNER',
                403,
            );
        }

        return $poliza->risk;
    }

    /** @return array<string, mixed> */
    private function payload(SharedRisk $sr): array
    {
        return [
            'id' => $sr->id,
            'shared_with_email' => $sr->shared_with_email,
            'invite_url' => rtrim((string) config('app.url'), '/').'/shared-risk/invite/'.$sr->token,
            'expires_at' => $sr->expires_at->toIso8601String(),
            'accepted_at' => $sr->accepted_at?->toIso8601String(),
            'status' => match (true) {
                $sr->isAccepted() => 'aceptado',
                $sr->isPending() => 'pendiente',
                $sr->isExpired() => 'expirado',
                $sr->isRevoked() => 'revocado',
                default => 'desconocido',
            },
        ];
    }
}
