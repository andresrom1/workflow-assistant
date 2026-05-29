<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Referidos — STUB.
 *
 * Spec v2 §4.7 marca el feature 4.7 (Gamificación + Referidos) como
 * postergado. Este endpoint existe solo para que la app pueda renderizar
 * la sección de perfil sin 404, y devuelve un link determinístico basado
 * en el id del MobileAccount.
 *
 * Cuando se retome el feature 4.7, este controller se reemplaza por la
 * lógica real (tokens persistentes, tracking de conversiones, etc.).
 */
class ReferidosController extends Controller
{
    public function link(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $code = strtolower(base_convert((string) $account->id, 10, 36)).'-stub';
        $base = rtrim((string) config('app.url'), '/');

        return response()->json([
            'code' => $code,
            'url' => $base.'/r/'.$code,
            'stub' => true,
        ]);
    }
}
