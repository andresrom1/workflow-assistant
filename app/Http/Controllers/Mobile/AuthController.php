<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\InvalidFirebaseTokenException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Services\Firebase\FirebaseTokenVerifier;
use App\Services\Firebase\VerifiedIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * TTL del Sanctum token (spec v2 §Ciclo de vida del token: ej. 60 días).
     */
    private const TOKEN_TTL_DAYS = 60;

    public function __construct(
        private readonly FirebaseTokenVerifier $verifier,
    ) {}

    /**
     * Intercambia un Firebase ID Token por un Sanctum token.
     *
     * Upsert de MobileAccount: busca por firebase_uid; si no existe, intenta
     * vincular por email (el email viene verificado por OAuth, así que si
     * ya hay una cuenta con ese correo es la misma persona y le pegamos el
     * UID nuevo). Si tampoco matchea, crea una cuenta nueva.
     */
    public function session(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'firebase_token' => ['required', 'string'],
        ]);

        try {
            $identity = $this->verifier->verify($validated['firebase_token']);
        } catch (InvalidFirebaseTokenException) {
            return response()->json([
                'message' => 'No pudimos validar tu sesión. Probá iniciar sesión de nuevo.',
                'code' => 'invalid_firebase_token',
            ], 401);
        }

        // Apple con "Ocultar mi correo": sin email real no podemos identificar
        // al tomador. Se rechaza también del lado del backend (la app ya lo corta).
        if ($identity->isAppleRelayEmail()) {
            return response()->json([
                'message' => 'Necesitamos tu correo real para identificarte. '
                    .'Iniciá sesión con Google, o volvé a entrar con Apple sin ocultar el correo.',
                'code' => 'apple_relay_email',
            ], 422);
        }

        $account = $this->upsertMobileAccount($identity);

        $token = $account->createToken(
            'mobile',
            ['*'],
            now()->addDays(self::TOKEN_TTL_DAYS),
        )->plainTextToken;

        return response()->json([
            'sanctum_token' => $token,
            'user' => $this->accountPayload($account),
            'linked' => $account->isLinked(),
        ]);
    }

    /**
     * Vinculación de identidad: el usuario declara su DNI y matcheamos
     * email (verificado por OAuth) + DNI contra un Customer existente.
     */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dni' => ['required', 'string', 'max:20'],
        ]);

        /** @var MobileAccount $account */
        $account = $request->user();

        if ($account->isLinked()) {
            return response()->json([
                'message' => 'Tu cuenta ya está vinculada.',
                'code' => 'already_linked',
                'linked' => true,
            ]);
        }

        $customer = Customer::query()
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $account->email)])
            ->where('dni', $validated['dni'])
            ->first();

        // Sin match, o el customer ya está reclamado por otra MobileAccount.
        // Misma respuesta neutral en los dos casos para no leakear info.
        $alreadyClaimed = $customer
            && MobileAccount::query()->where('customer_id', $customer->id)->exists();

        if (! $customer || $alreadyClaimed) {
            return response()->json([
                'message' => 'No encontramos pólizas con estos datos.',
                'code' => 'link_failed',
            ], 422);
        }

        DB::transaction(function () use ($account, $customer): void {
            $account->customer_id = $customer->id;
            $account->save();
        });

        return response()->json([
            'message' => 'Listo, tu cuenta quedó vinculada.',
            'linked' => true,
        ]);
    }

    /**
     * Cierra la sesión actual borrando el Sanctum token de este dispositivo.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Upsert por firebase_uid; si no existe, intenta vincular por email
     * (el email viene verificado por el OAuth provider, así que si ya hay
     * un MobileAccount con ese correo es la misma persona y le asociamos
     * el UID). Nunca pisa name/email/avatar con null (Apple solo entrega
     * esos datos en el primer login).
     */
    private function upsertMobileAccount(VerifiedIdentity $identity): MobileAccount
    {
        $account = MobileAccount::query()
            ->where('firebase_uid', $identity->uid)
            ->first();

        if ($account === null && $identity->email !== null) {
            $account = MobileAccount::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($identity->email)])
                ->first();
        }

        $account ??= new MobileAccount;

        $account->firebase_uid = $identity->uid;
        $account->email = $identity->email ?? $account->email;
        $account->name = $identity->name ?? $account->name ?? 'Usuario MANGO';
        $account->avatar_url = $identity->avatarUrl ?? $account->avatar_url;

        if ($identity->emailVerified && $account->email_verified_at === null) {
            $account->email_verified_at = now();
        }

        $account->save();

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(MobileAccount $account): array
    {
        return [
            'name' => $account->name,
            'email' => $account->email,
            'avatar_url' => $account->avatar_url,
        ];
    }
}
