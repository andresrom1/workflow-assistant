<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\InspectionPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutAuditController extends Controller
{
    /**
     * Lista de checkout sessions pendientes de procesar.
     */
    public function index(): Response
    {
        $sessions = CheckoutSession::with(['quote', 'quoteAlternative', 'processedBy'])
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->paginate(20);

        return Inertia::render('Admin/CheckoutSessions/Index', [
            'sessions' => $sessions->through(fn ($s): array => [
                'id' => $s->id,
                'status' => $s->status,
                'nombre' => $s->nombre,
                'email' => $s->email,
                'cc_brand' => $s->cc_brand,
                'cc_cleared' => $s->isCcCleared(),
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'quote_id' => $s->quote_id,
                'aseguradora' => $s->quoteAlternative?->aseguradora,
                'titulo' => $s->quoteAlternative?->titulo,
                'precio' => $s->quoteAlternative?->precio,
            ]),
        ]);
    }

    /**
     * Detalle de una sesión — descifra los datos de tarjeta para el auditor.
     */
    public function show(CheckoutSession $checkoutSession): Response
    {
        $checkoutSession->load(['quote.riskSnapshot', 'quoteAlternative', 'processedBy']);

        return Inertia::render('Admin/CheckoutSessions/Show', [
            'session' => [
                'id' => $checkoutSession->id,
                'status' => $checkoutSession->status,
                // Datos personales
                'nombre' => $checkoutSession->nombre,
                'dni' => $checkoutSession->dni,
                'domicilio' => $checkoutSession->domicilio,
                'email' => $checkoutSession->email,
                'telefono' => $checkoutSession->telefono,
                // Tarjeta descifrada (solo visible en esta pantalla protegida)
                'cc_brand' => $checkoutSession->cc_brand,
                'cc_pan' => $checkoutSession->cc_pan,         // accessor descifra
                'cc_expiry' => $checkoutSession->cc_expiry,
                'cc_holder_name' => $checkoutSession->cc_holder_name,
                'cc_holder_dni' => $checkoutSession->cc_holder_dni,
                'cc_cleared' => $checkoutSession->isCcCleared(),
                'cc_cleared_at' => $checkoutSession->cc_cleared_at?->toIso8601String(),
                'cc_processed_at' => $checkoutSession->cc_processed_at?->toIso8601String(),
                'cc_processed_by' => $checkoutSession->processedBy?->name,
                // Fotos
                // Se resuelven desde las filas de la cotización y no desde `photo_paths`,
                // que en sesiones viejas guarda URLs públicas que ya no sirven. Firmadas al
                // vuelo: el bucket es privado.
                'photo_paths' => InspectionPhoto::where('quote_id', $checkoutSession->quote_id)
                    ->orderBy('id')
                    ->get()
                    ->mapWithKeys(fn (InspectionPhoto $foto): array => [
                        $foto->photo_key => Storage::disk('r2')->temporaryUrl($foto->storage_path, now()->addMinutes(15)),
                    ])
                    ->all(),
                // Quote
                'quote_id' => $checkoutSession->quote_id,
                'alternative' => $checkoutSession->quoteAlternative ? [
                    'aseguradora' => $checkoutSession->quoteAlternative->aseguradora,
                    'titulo' => $checkoutSession->quoteAlternative->titulo,
                    'precio' => $checkoutSession->quoteAlternative->precio,
                    'normalized_grade' => $checkoutSession->quoteAlternative->normalized_grade,
                ] : null,
                'risk' => $checkoutSession->quote->riskSnapshot ? [
                    'marca' => $checkoutSession->quote->riskSnapshot->marca,
                    'modelo' => $checkoutSession->quote->riskSnapshot->modelo,
                    'year' => $checkoutSession->quote->riskSnapshot->year,
                ] : null,
            ],
        ]);
    }

    /**
     * Marca la tarjeta como procesada por el auditor actual.
     */
    public function markProcessed(CheckoutSession $checkoutSession): RedirectResponse
    {
        abort_unless($checkoutSession->status === 'submitted', 409, 'Esta sesión ya fue procesada.');

        $checkoutSession->update([
            'status' => 'processed',
            'cc_processed_at' => now(),
            'cc_processed_by' => Auth::id(),
        ]);

        return redirect()->route('admin.checkout-sessions.show', $checkoutSession)
            ->with('success', 'Sesión marcada como procesada.');
    }

    /**
     * Elimina los datos cifrados de tarjeta una vez procesada la transacción.
     */
    public function clearCardData(CheckoutSession $checkoutSession): RedirectResponse
    {
        abort_unless($checkoutSession->status === 'processed', 409, 'Solo se pueden limpiar sesiones procesadas.');
        abort_unless(! $checkoutSession->isCcCleared(), 409, 'Los datos ya fueron eliminados.');

        $checkoutSession->update([
            'cc_pan_encrypted' => null,
            'cc_expiry_encrypted' => null,
            'cc_holder_name_encrypted' => null,
            'cc_holder_dni_encrypted' => null,
            'cc_cleared_at' => now(),
        ]);

        return redirect()->route('admin.checkout-sessions.show', $checkoutSession)
            ->with('success', 'Datos de tarjeta eliminados correctamente.');
    }
}
