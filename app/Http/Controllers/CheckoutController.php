<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Quote;
// use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
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
            // URLs para el frontend
            'submitUrl' => route('checkout.submit'),
            'uploadPhotoUrl' => route('checkout.upload-photo'),
        ]);
    }

    /**
     * Sube una foto individual a Cloudinary (upload incremental).
     * Se llama desde el frontend cada vez que el usuario captura una foto.
     */
    public function uploadPhoto(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'checkout_token' => 'required|string',
            'photo_key' => 'required|string|max:50',
            'photo' => 'required|file|mimes:jpeg,jpg,png|max:10240',
        ]);

        $quote = Quote::where('checkout_token', $request->input('checkout_token'))->firstOrFail();

        abort_unless(
            in_array($quote->status, ['checkout_pending']),
            409,
            'Esta cotización ya fue enviada o no está disponible.'
        );

        try {
            $photo = $request->file('photo');
            $photoKey = $request->input('photo_key');

            $cloudinary = app(\Cloudinary\Cloudinary::class);

            $result = $cloudinary->uploadApi()->upload($photo->getRealPath(), [
                'folder'        => "checkout/{$quote->id}/photos",
                'public_id'     => "photo_{$photoKey}",
                'resource_type' => 'image',
                'overwrite'     => true,
                'format'        => 'jpg',
            ]);
            return response()->json([
                'success'   => true,
                'public_id' => $result['public_id'],
                'url'       => $result['secure_url'],
            ]);
            
            /*$folder = "checkout/{$quote->id}/photos";

            // Usamos el driver storage nativo provisto por el package
            // En vez de CloudinaryApi directamente
            $path = \Illuminate\Support\Facades\Storage::disk('cloudinary')->putFileAs(
                $folder,
                $photo,
                "photo_{$request->input('photo_key')}.jpg"
            );

            // Obtener la URL publica desde el storage
            $url = \Illuminate\Support\Facades\Storage::disk('cloudinary')->url($path);

            return response()->json([
                'success' => true,
                'public_id' => $path, // Para Cloudinary vía Storage, la ruta suele ser el public_id equivalente
                'url' => $url,
            ]);*/
        } catch (\Exception $e) {
            Log::error('CheckoutController: Error subiendo foto a Cloudinary', [
                'quote_id' => $quote->id,
                'key' => $request->input('photo_key'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error al subir la foto. Intentá de nuevo.',
            ], 500);
        }
    }

    /**
     * Procesa el envío del formulario de checkout.
     * Recibe los datos del formulario + photo_ids (public_ids de Cloudinary ya subidos).
     */
    public function submit(Request $request): \Illuminate\Http\JsonResponse
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
            // Fotos — ahora se reciben como IDs de Cloudinary (ya subidas)
            'photo_ids' => 'required|array',
            'photo_ids.*' => 'required|string|max:255',
        ]);

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
                'photo_paths' => $validated['photo_ids'],
                'submitted_at' => now(),
            ]
        );

        $quote->update(['status' => 'checkout_submitted']);

        return response()->json([
            'success' => true,
            'redirect_url' => route('checkout.success', ['quote' => $quote->id]),
        ]);
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
