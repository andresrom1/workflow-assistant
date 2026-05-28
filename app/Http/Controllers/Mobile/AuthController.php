<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\InvalidFirebaseTokenException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
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
     * El usuario viene 100% de Firebase; acá solo persistimos firebase_uid + datos.
     */
    public function session(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'firebase_token' => ['required', 'string'],
        ]);

        try {
            $identity = $this->verifier->verify($validated['firebase_token']);
        } catch (InvalidFirebaseTokenException $e) {
            return response()->json([
                'message' => 'No pudimos validar tu sesión. Probá iniciar sesión de nuevo.',
                'code' => 'invalid_firebase_token',
            ], 401);
        }

        // Apple con "Ocultar mi correo": sin email real no podemos identificar al
        // tomador. Se rechaza acá también (defensa server-side; la app ya lo corta).
        if ($identity->isAppleRelayEmail()) {
            return response()->json([
                'message' => 'Necesitamos tu correo real para identificarte. '
                    .'Iniciá sesión con Google, o volvé a entrar con Apple sin ocultar el correo.',
                'code' => 'apple_relay_email',
            ], 422);
        }

        $user = $this->upsertUser($identity);

        $token = $user->createToken(
            'mobile',
            ['*'],
            now()->addDays(self::TOKEN_TTL_DAYS),
        )->plainTextToken;

        return response()->json([
            'sanctum_token' => $token,
            'user' => $this->userPayload($user),
            'linked' => $user->isLinked(),
        ]);
    }

    /**
     * Vinculación de identidad: el usuario declara su DNI y matcheamos
     * email (verificado por OAuth) + DNI contra un Customer (tomador) existente.
     */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dni' => ['required', 'string', 'max:20'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($user->isLinked()) {
            return response()->json([
                'message' => 'Tu cuenta ya está vinculada.',
                'code' => 'already_linked',
                'linked' => true,
            ]);
        }

        $customer = Customer::query()
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->where('dni', $validated['dni'])
            ->first();

        // Sin match, o el customer ya está tomado por otra cuenta → derivar al PAS.
        $alreadyTaken = $customer
            && User::query()->where('customer_id', $customer->id)->exists();

        if (! $customer || $alreadyTaken) {
            return response()->json([
                'message' => 'No pudimos vincular tus datos. Contactá a tu productor para resolverlo.',
                'code' => 'link_failed',
            ], 422);
        }

        DB::transaction(function () use ($user, $customer): void {
            $user->customer_id = $customer->id;
            $user->save();
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
     * firstOrCreate por firebase_uid. Nunca pisa name/email/avatar con null
     * (Apple solo entrega esos datos en el primer login).
     */
    private function upsertUser(VerifiedIdentity $identity): User
    {
        $user = User::firstOrNew(['firebase_uid' => $identity->uid]);

        $user->name = $identity->name ?? $user->name ?? 'Usuario MANGO';
        $user->email = $identity->email ?? $user->email;
        $user->avatar_url = $identity->avatarUrl ?? $user->avatar_url;

        if ($identity->emailVerified && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
        ];
    }
}
