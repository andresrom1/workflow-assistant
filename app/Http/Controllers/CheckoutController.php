<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Quote;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    /**
     * Muestra el formulario de checkout.
     * El token opaco identifica unívocamente el par (quote + alternative).
     */
    public function show(Request $request, string $token): \Inertia\Response
    {
        $quote = Quote::where('checkout_token', $token)->firstOrFail();

        abort_unless(
            in_array($quote->status, ['checkout_pending', 'checkout_submitted']),
            404,
            'Esta cotización no está disponible para checkout.'
        );

        $alternative = $quote->alternatives()
            ->where('id', $quote->checkout_alternative_id)
            ->firstOrFail();

        $quote->load('riskSnapshot');
        $snap = $quote->riskSnapshot;

        return Inertia::render('Checkout/Show', [
            'quote' => [
                'id' => $quote->id,
                'status' => $quote->status,
            ],
            'alternative' => [
                'id' => $alternative->id,
                'aseguradora' => $alternative->aseguradora,
                'titulo' => $alternative->titulo,
                'descripcion' => $alternative->descripcion,
                'precio' => $alternative->precio,
                'moneda' => $alternative->moneda,
                'marketing_title' => $alternative->marketing_title,
                'features_tags' => $alternative->features_tags,
                'normalized_grade' => $alternative->normalized_grade,
            ],
            // Datos del snapshot — inmutables, solo lectura en el frontend
            'vehicle' => [
                'patente' => $snap->vehicle->patente ?? null,
                'marca' => $snap->marca,
                'modelo' => $snap->modelo,
                'version' => $snap->version,
                'year' => $snap->year,
                'combustible' => $snap->combustible,
            ],
            // Token que el frontend incluye como campo oculto en el POST
            'checkoutToken' => $token,
            // URL fija sin parámetros — el token viaja en el body
            'submitUrl' => route('checkout.submit'),
        ]);
    }

    /**
     * Procesa el envío del formulario de checkout.
     * El checkout_token llega en el body del request (campo oculto).
     */
    public function submit(Request $request): \Illuminate\Http\RedirectResponse
    {
        $token = $request->input('checkout_token');

        abort_if(empty($token), 422, 'Token de checkout requerido.');

        $quote = Quote::where('checkout_token', $token)->firstOrFail();

        abort_unless(
            in_array($quote->status, ['checkout_pending']),
            409,
            'Esta cotización ya fue enviada o no está disponible.'
        );

        $alternative = $quote->alternatives()
            ->where('id', $quote->checkout_alternative_id)
            ->firstOrFail();

        $validated = $request->validate([
            // Datos personales
            'nombre' => 'required|string|max:255',
            'dni' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'telefono' => 'required|string|max:50',
            // Domicilio (5 campos)
            'domicilio_calle' => 'required|string|max:255',
            'domicilio_numero' => 'required|string|max:20',
            'domicilio_cp' => 'required|string|max:10',
            'domicilio_provincia' => 'required|string|max:100',
            'domicilio_localidad' => 'required|string|max:100',
            // Vehículo (confirmación)
            'vehiculo_uso' => 'required|string|in:particular,otro',
            'vehiculo_nro_chasis' => 'required|string|max:50',
            'vehiculo_nro_motor' => 'required|string|max:50',
            // Tarjeta de crédito
            'cc_brand' => 'required|string|in:visa,mastercard,amex,naranja,cabal,maestro',
            'cc_pan' => ['required', 'string', 'regex:/^\d{16}$/'],
            'cc_expiry' => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
            'cc_holder_name' => 'required|string|max:255',
            'cc_holder_dni' => 'required|string|max:20',
            // Fotos de inspección (6 requeridas)
            'photos' => 'required|array|min:6|max:6',
            'photos.*' => 'required|file|mimes:jpg,jpeg,png,heic|max:10240',
        ]);

        // Subir fotos a Cloudinary
        $photoPaths = [];
        foreach ($request->file('photos') as $key => $photo) {
            try {
                $result = Cloudinary::upload($photo->getRealPath(), [
                    'folder' => "checkout/{$quote->id}/photos",
                    'resource_type' => 'image',
                    'public_id' => "photo_{$key}",
                ]);
                $photoPaths[$key] = $result->getPublicId();
            } catch (\Exception $e) {
                Log::error('CheckoutController: Error subiendo foto a Cloudinary', [
                    'quote_id' => $quote->id,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Persistir la sesión de checkout con datos cifrados
        CheckoutSession::updateOrCreate(
            ['quote_id' => $quote->id],
            [
                'quote_alternative_id' => $alternative->id,
                'status' => 'submitted',
                'nombre' => $validated['nombre'],
                'dni' => $validated['dni'],
                'email' => $validated['email'],
                'telefono' => $validated['telefono'],
                // Domicilio estructurado
                'domicilio_calle' => $validated['domicilio_calle'],
                'domicilio_numero' => $validated['domicilio_numero'],
                'domicilio_cp' => $validated['domicilio_cp'],
                'domicilio_provincia' => $validated['domicilio_provincia'],
                'domicilio_localidad' => $validated['domicilio_localidad'],
                // Vehículo
                'vehiculo_uso' => $validated['vehiculo_uso'],
                'vehiculo_nro_chasis' => $validated['vehiculo_nro_chasis'],
                'vehiculo_nro_motor' => $validated['vehiculo_nro_motor'],
                // Tarjeta cifrada
                'cc_brand' => $validated['cc_brand'],
                'cc_pan_encrypted' => Crypt::encryptString($validated['cc_pan']),
                'cc_expiry_encrypted' => Crypt::encryptString($validated['cc_expiry']),
                'cc_holder_name_encrypted' => Crypt::encryptString($validated['cc_holder_name']),
                'cc_holder_dni_encrypted' => Crypt::encryptString($validated['cc_holder_dni']),
                'photo_paths' => $photoPaths,
                'submitted_at' => now(),
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
