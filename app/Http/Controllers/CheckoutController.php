<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CheckoutController extends Controller
{
    /**
     * Muestra el formulario de checkout.
     * La URL firmada ya fue validada por el middleware 'signed'.
     */
    public function show(Request $request, Quote $quote): \Inertia\Response
    {
        $alternativeId = $request->query('alternative');

        abort_unless(
            in_array($quote->status, ['checkout_pending', 'checkout_submitted']),
            404,
            'Esta cotización no está disponible para checkout.'
        );

        $alternative = QuoteAlternative::where('id', $alternativeId)
            ->where('quote_id', $quote->id)
            ->firstOrFail();

        $quote->load('riskSnapshot');

        return Inertia::render('Checkout/Show', [
            'quote'       => [
                'id'     => $quote->id,
                'status' => $quote->status,
            ],
            'alternative' => [
                'id'             => $alternative->id,
                'aseguradora'    => $alternative->aseguradora,
                'titulo'         => $alternative->titulo,
                'descripcion'    => $alternative->descripcion,
                'precio'         => $alternative->precio,
                'moneda'         => $alternative->moneda,
                'marketing_title'=> $alternative->marketing_title,
                'features_tags'  => $alternative->features_tags,
                'normalized_grade' => $alternative->normalized_grade,
            ],
            'risk' => [
                'marca'    => $quote->riskSnapshot->marca,
                'modelo'   => $quote->riskSnapshot->modelo,
                'version'  => $quote->riskSnapshot->version,
                'year'     => $quote->riskSnapshot->year,
                'patente'  => $quote->riskSnapshot->patente ?? null,
            ],
            // Pasamos los query params de la firma para incluirlos en el action del form
            'submitUrl' => route('checkout.submit', [
                'quote'       => $quote->id,
                'alternative' => $alternative->id,
                'expires'     => $request->query('expires'),
                'signature'   => $request->query('signature'),
            ]),
        ]);
    }

    /**
     * Procesa el envío del formulario de checkout.
     * La URL firmada ya fue validada por el middleware 'signed'.
     */
    public function submit(Request $request, Quote $quote): \Illuminate\Http\RedirectResponse
    {
        $alternativeId = $request->query('alternative');

        abort_unless(
            in_array($quote->status, ['checkout_pending']),
            409,
            'Esta cotización ya fue enviada o no está disponible.'
        );

        $alternative = QuoteAlternative::where('id', $alternativeId)
            ->where('quote_id', $quote->id)
            ->firstOrFail();

        $validated = $request->validate([
            // Datos personales
            'nombre'           => 'required|string|max:255',
            'dni'              => 'required|string|max:20',
            'domicilio'        => 'required|string|max:500',
            'email'            => 'required|email|max:255',
            'telefono'         => 'required|string|max:50',
            // Tarjeta de crédito
            'cc_brand'         => 'required|string|in:visa,mastercard,amex,naranja,cabal,maestro',
            'cc_pan'           => ['required', 'string', 'regex:/^\d{16}$/'],
            'cc_expiry'        => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
            'cc_holder_name'   => 'required|string|max:255',
            'cc_holder_dni'    => 'required|string|max:20',
            // Fotos
            'photos'           => 'nullable|array|max:10',
            'photos.*'         => 'file|mimes:jpg,jpeg,png,heic,pdf|max:10240',
        ]);

        // Subir fotos a Cloudinary
        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                try {
                    $result = Cloudinary::upload($photo->getRealPath(), [
                        'folder'         => "checkout/{$quote->id}/photos",
                        'resource_type'  => 'auto',
                    ]);
                    $photoPaths[] = $result->getPublicId();
                } catch (\Exception $e) {
                    Log::error('CheckoutController: Error subiendo foto a Cloudinary', [
                        'quote_id' => $quote->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        // Persistir la sesión de checkout con datos cifrados
        CheckoutSession::updateOrCreate(
            ['quote_id' => $quote->id],
            [
                'quote_alternative_id'     => $alternative->id,
                'status'                   => 'submitted',
                'nombre'                   => $validated['nombre'],
                'dni'                      => $validated['dni'],
                'domicilio'                => $validated['domicilio'],
                'email'                    => $validated['email'],
                'telefono'                 => $validated['telefono'],
                'cc_brand'                 => $validated['cc_brand'],
                'cc_pan_encrypted'         => Crypt::encryptString($validated['cc_pan']),
                'cc_expiry_encrypted'      => Crypt::encryptString($validated['cc_expiry']),
                'cc_holder_name_encrypted' => Crypt::encryptString($validated['cc_holder_name']),
                'cc_holder_dni_encrypted'  => Crypt::encryptString($validated['cc_holder_dni']),
                'photo_paths'              => $photoPaths,
                'submitted_at'             => now(),
            ]
        );

        $quote->update(['status' => 'checkout_submitted']);

        return redirect()->route('checkout.success', ['quote' => $quote->id]);
    }

    /**
     * Pantalla de confirmación post-checkout.
     */
    public function success(Quote $quote): \Inertia\Response
    {
        $session = $quote->checkoutSession;

        abort_if($session === null, 404);

        return Inertia::render('Checkout/Success', [
            'email' => $session->email,
        ]);
    }
}
