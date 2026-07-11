<?php

namespace App\Http\Controllers;

use App\Enums\InspectionPhotoStatus;
use App\Jobs\DeleteOrphanPhoto;
use App\Jobs\EmitirPoliza;
use App\Mail\CheckoutCompletadoMail;
use App\Models\CheckoutSession;
use App\Models\InspectionPhoto;
use App\Models\Quote;
use App\Services\CustomerConsolidationService;
use App\Services\CustomerMergeService;
use App\Services\SettingsService;
use App\Services\Visred\VisredCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    /**
     * Muestra el formulario de checkout.
     * El token opaco identifica unívocamente el par (quote + alternative).
     */
    public function show(Request $request, string $token, VisredCatalogService $catalog): Response
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
            // Catálogo de condiciones fiscales para el select del titular (D1).
            'taxConditions' => $catalog->taxConditions(),
            // Token que el frontend incluye como campo oculto en el POST
            'checkoutToken' => $token,
            // URLs para el frontend
            'submitUrl' => route('checkout.submit'),
            'uploadPhotoUrl' => route('checkout.upload-photo'),
            'deletePhotoUrl' => route('checkout.delete-photo'),
        ]);
    }

    /**
     * Sube una foto individual a Cloudinary (upload incremental).
     * Se llama desde el frontend cada vez que el usuario captura una foto.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'checkout_token' => 'required|string',
            'photo_key' => 'required|string|max:50',
            'photo' => 'required|file|mimes:jpeg,jpg,png|max:10240',
        ]);

        $quote = Quote::where('checkout_token', $request->input('checkout_token'))->firstOrFail();

        abort_unless(
            $quote->status == 'checkout_pending',
            409,
            'Esta cotización ya fue enviada o no está disponible.'
        );

        try {
            $photo = $request->file('photo');
            $photoKey = $request->input('photo_key');

            // 1. Guardar referencia de la foto existente (si hay)
            $existing = InspectionPhoto::where('quote_id', $quote->id)
                ->where('photo_key', $photoKey)
                ->first();

            // 2. Subir nueva foto a R2
            $storagePath = "checkout/{$quote->id}/photos/photo_{$photoKey}.jpg";
            Storage::disk('r2')->putFileAs(
                "checkout/{$quote->id}/photos",
                $photo,
                "photo_{$photoKey}.jpg",
                'public'
            );
            $storageUrl = Storage::disk('r2')->url($storagePath);

            // 3. UpdateOrCreate en base de datos (Status Temp)
            InspectionPhoto::updateOrCreate(
                [
                    'quote_id' => $quote->id,
                    'photo_key' => $photoKey,
                ],
                [
                    'storage_path' => $storagePath,
                    'storage_url' => $storageUrl,
                    'status' => InspectionPhotoStatus::Temp,
                    'uploaded_by_ip' => $request->ip(),
                    'file_size' => $photo->getSize(),
                ]
            );

            // 4. Despachar Job para eliminar el asset viejo (Asincrónico)
            if ($existing && $existing->storage_path !== $storagePath) {
                DeleteOrphanPhoto::dispatch($existing->storage_path);
            }

            return response()->json([
                'success' => true,
                'public_id' => $storagePath,
                'url' => $storageUrl,
            ]);

        } catch (\Exception $e) {
            Log::error('CheckoutController: Error subiendo foto a R2', [
                'quote_id' => $quote->id ?? null,
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
    public function submit(Request $request, CustomerConsolidationService $consolidation, CustomerMergeService $merge): JsonResponse
    {
        $token = $request->input('checkout_token');

        abort_if(empty($token), 422, 'Token de checkout requerido.');

        $quote = Quote::where('checkout_token', $token)->firstOrFail();

        abort_unless(
            $quote->status == 'checkout_pending',
            409,
            'Esta cotización ya fue enviada o no está disponible.'
        );

        $alternative = $quote->alternatives()
            ->where('id', $quote->checkout_alternative_id)
            ->firstOrFail();

        $validated = $request->validate([
            // Datos personales del titular (holder). Split de nombre/teléfono que la
            // emisión Visred exige (D1); birthdate/sex_id/tax_condition_id idem.
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'dni' => 'required|string|max:20',
            'birthdate' => 'required|date',
            'sex_id' => 'required|string|max:20',
            'tax_condition_id' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'phone_prefix' => ['required', 'string', 'regex:/^\d{1,3}$/'],
            'phone_number' => ['required', 'string', 'regex:/^\d{1,9}$/'],
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
            'has_gnc' => 'boolean',
            // Tarjeta de crédito
            'cc_brand' => 'required|string|in:visa,mastercard,amex,naranja,cabal,maestro',
            'cc_pan' => ['required', 'string', 'regex:/^\d{16}$/'],
            'cc_expiry' => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
            'cc_holder_name' => 'required|string|max:255',
            'cc_holder_dni' => 'required|string|max:20',
            // Fotos — storage_paths de R2, validamos cantidad vs BD
            'photo_ids' => 'required|array',
            'photo_ids.*' => 'required|string|max:255',
        ]);

        // Validar cantidad de fotos en BD — valor configurable desde /admin/settings
        $requiredPhotoCount = (int) app(SettingsService::class)->get('checkout.required_photos', 7);
        $tempPhotosCount = InspectionPhoto::where('quote_id', $quote->id)
            ->where('status', InspectionPhotoStatus::Temp)
            ->count();

        abort_if($tempPhotosCount < $requiredPhotoCount, 422, 'Faltan fotos de inspección o no fueron procesadas correctamente.');

        // Ejecutar las mutaciones de BD en una transacción atómica.
        // try/catch para loguear el trace completo: sin esto el 500 del submit
        // es invisible (a diferencia de uploadPhoto, que sí captura y loguea).
        try {
            DB::transaction(function () use ($quote, $alternative, $validated, $consolidation, $merge): void {

                // 1. Guardar CheckoutSession
                $session = CheckoutSession::updateOrCreate(
                    ['quote_id' => $quote->id],
                    [
                        'quote_alternative_id' => $alternative->id,
                        'status' => 'submitted',
                        // `nombre`/`telefono` se mantienen poblados (compuestos) para mail/admin.
                        'nombre' => trim($validated['first_name'].' '.$validated['last_name']),
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'birthdate' => $validated['birthdate'],
                        'sex_id' => $validated['sex_id'],
                        'tax_condition_id' => $validated['tax_condition_id'],
                        'dni' => $validated['dni'],
                        'email' => $validated['email'],
                        'telefono' => $validated['phone_prefix'].$validated['phone_number'],
                        'phone_prefix' => $validated['phone_prefix'],
                        'phone_number' => $validated['phone_number'],
                        'domicilio_calle' => $validated['domicilio_calle'],
                        'domicilio_numero' => $validated['domicilio_numero'],
                        'domicilio_cp' => $validated['domicilio_cp'],
                        'domicilio_provincia' => $validated['domicilio_provincia'],
                        'domicilio_localidad' => $validated['domicilio_localidad'],
                        'vehiculo_uso' => $validated['vehiculo_uso'],
                        'vehiculo_nro_chasis' => $validated['vehiculo_nro_chasis'],
                        'vehiculo_nro_motor' => $validated['vehiculo_nro_motor'],
                        'has_gnc' => $validated['has_gnc'] ?? false,
                        'cc_brand' => $validated['cc_brand'],
                        'cc_pan_encrypted' => Crypt::encryptString($validated['cc_pan']),
                        'cc_expiry_encrypted' => Crypt::encryptString($validated['cc_expiry']),
                        'cc_holder_name_encrypted' => Crypt::encryptString($validated['cc_holder_name']),
                        'cc_holder_dni_encrypted' => Crypt::encryptString($validated['cc_holder_dni']),
                        'photo_paths' => collect($validated['photo_ids'])->map(fn ($path) => Storage::disk('r2')->url($path))->toArray(),
                        'submitted_at' => now(),
                    ]
                );

                // 2. Transicionar fotos de temp a confirmed
                InspectionPhoto::where('quote_id', $quote->id)
                    ->where('status', InspectionPhotoStatus::Temp)
                    ->update([
                        'status' => InspectionPhotoStatus::Confirmed,
                        'confirmed_at' => now(),
                    ]);

                // 3. Actualizar status del quote
                $quote->update(['status' => 'checkout_submitted']);

                // 3b. Sync-back al Customer canónico (declaración jurada del checkout). El
                // domicilio del tomador queda en el Customer; la ubicación de guarda del
                // riesgo (que tarifa) se siembra en el vehículo SOLO si está vacía.
                $customer = $quote->conversation?->customer;
                if ($customer !== null) {
                    // Convergencia de identidad: el DNI/email declarados pueden pertenecer a
                    // otra fila creada por otra puerta (WhatsApp por teléfono, app por email).
                    // Se fusionan en el customer de la conversación antes de consolidar. Solo se
                    // reconcilia por claves fuertes (dni/email), nunca por teléfono. Ver docs/v2/12 §5.
                    $customer = $merge->reconcile($customer, [
                        'dni' => $validated['dni'],
                        'email' => $validated['email'],
                    ]);

                    $consolidation->apply($customer, [
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'dni' => $validated['dni'],
                        'birthdate' => $validated['birthdate'],
                        'sex_id' => $validated['sex_id'],
                        'tax_condition_id' => $validated['tax_condition_id'],
                        'email' => $validated['email'],
                        'phone' => '+549'.$validated['phone_prefix'].$validated['phone_number'],
                        'domicilio_calle' => $validated['domicilio_calle'],
                        'domicilio_numero' => $validated['domicilio_numero'],
                        'domicilio_cp' => $validated['domicilio_cp'],
                        'domicilio_provincia' => $validated['domicilio_provincia'],
                        'domicilio_localidad' => $validated['domicilio_localidad'],
                    ], 'checkout');
                }

                $vehicle = $quote->riskSnapshot?->vehicle;
                if ($vehicle !== null && $vehicle->exists && empty($vehicle->codigo_postal)) {
                    $vehicle->update(['codigo_postal' => $validated['domicilio_cp']]);
                }

                // Despachar emisión de póliza (skeleton — cuando API esté lista, solo
                // hay que implementar PolizaEmisionService::emitir())
                EmitirPoliza::dispatch($quote->id, $session->id);

                // Notificación interna por mail
                Mail::to(
                    config('mail.checkout_notifications_to', config('mail.from.address'))
                )->queue(new CheckoutCompletadoMail($quote, $session));
            });
        } catch (\Throwable $e) {
            Log::error('CheckoutController: submit falló dentro de la transacción', [
                'quote_id' => $quote->id,
                'checkout_token' => $token,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'location' => $e->getFile().':'.$e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No pudimos procesar el checkout. Intentá de nuevo en unos minutos.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'redirect_url' => route('checkout.success', ['quote' => $quote->id]),
        ]);
    }

    /**
     * Pantalla de confirmación post-checkout.
     */
    public function success(Quote $quote): Response
    {
        $session = $quote->checkoutSession;

        abort_if($session === null, 404);

        return Inertia::render('Checkout/Success', [
            'email' => $session->email,
        ]);
    }

    /**
     * Elimina explícitamente una foto tomada, borrándola de la base de datos
     * y despachando un Job para eliminarla de Cloudinary.
     */
    public function deletePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'checkout_token' => 'required|string',
            'photo_key' => 'required|string|max:50',
        ]);

        $quote = Quote::where('checkout_token', $request->input('checkout_token'))->firstOrFail();

        $photo = InspectionPhoto::where('quote_id', $quote->id)
            ->where('photo_key', $request->input('photo_key'))
            ->where('status', InspectionPhotoStatus::Temp)
            ->firstOrFail();

        DeleteOrphanPhoto::dispatch($photo->storage_path);
        $photo->delete();

        return response()->json(['success' => true]);
    }
}
