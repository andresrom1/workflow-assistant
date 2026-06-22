<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\InvalidFirebaseTokenException;
use App\Http\Controllers\Controller;
use App\Models\MobileAccount;
use App\Services\Firebase\FirebaseTokenVerifier;
use App\Services\Firebase\VerifiedIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            // `id` lo usa la app para suscribirse a su topic FCM `account-{id}` (avisos
            // de documentación nueva). El push es data-only (solo dispara un refresco); el
            // contenido sigue detrás de la API autenticada. Ver docs/v3/03.
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'avatar_url' => $account->avatar_url,
        ];
    }
}
