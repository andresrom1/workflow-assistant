<?php

namespace App\Http\Controllers;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Services\Quote\QuoteComparisonService;
use App\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Vista pública de una cotización: el cliente la abre desde WhatsApp para ver el detalle de las
 * coberturas que le presentó el asistente.
 *
 * El token es la única credencial, así que la página **no expone ningún dato personal**: ni
 * patente, ni DNI, ni nombre, ni teléfono. Los campos se enumeran a mano y nunca se serializa el
 * `RiskSnapshot` entero, que sí tiene `dni` y `codigo_postal`.
 *
 * Contraste con CheckoutController::show(), que sí manda la patente: ese link va a un cliente ya
 * identificado que a continuación carga su DNI; este es de lectura y lo puede abrir cualquiera
 * que tenga la URL.
 *
 * TEMPORAL — `/cotizaciones/{token}/B` sirve la vista anterior, que mostraba un solo grado, para
 * poder mostrar las dos lado a lado. Para sacarla: borrar la ruta `cotizaciones.show.legacy`, el
 * método `showLegacy()`, el parámetro de `render()` y el `$soloGradoRecomendado` de
 * QuoteComparisonService::buildPublicView().
 */
class PublicQuoteController extends Controller
{
    public function show(string $token, QuoteComparisonService $comparison): Response
    {
        return $this->render($token, $comparison, soloGradoRecomendado: false);
    }

    /**
     * La vista anterior: un solo grado, sin comparación cuando el par presentado es cross-grade.
     *
     * En una cotización de mismo grado renderiza igual que la canónica — el único caso donde se
     * diferencian es el cross-grade.
     */
    public function showLegacy(string $token, QuoteComparisonService $comparison): Response
    {
        return $this->render($token, $comparison, soloGradoRecomendado: true);
    }

    private function render(string $token, QuoteComparisonService $comparison, bool $soloGradoRecomendado): Response
    {
        $quote = Quote::where('public_token', $token)->firstOrFail();
        $quote->load(['riskSnapshot', 'alternatives']);

        $vista = $comparison->buildPublicView($quote, $soloGradoRecomendado);

        abort_if($vista['totalOpciones'] === 0, 404, 'Esta cotización no tiene opciones para mostrar.');

        // Sin guard por `status` ni por vigencia a propósito: una cotización vencida tiene que
        // renderizar igual, con el CTA de contratar apagado. Un 404 le daría la espalda al
        // cliente que abre el link al día siguiente, que es justamente el caso a cubrir.
        return inertia('Cotizaciones/Comparador', [
            // El token ya está en la URL que el cliente tiene abierta: mandarlo como prop no lo
            // expone más de lo que ya está, y el CTA lo necesita para postear el checkout.
            'token' => $token,
            'vigente' => $quote->isVigente(),
            'expiresAt' => $quote->expires_at?->toIso8601String(),
            'cotizadoEl' => $quote->created_at
                ?->setTimezone(Quote::TIMEZONE)
                ->locale('es')
                ->isoFormat('D [de] MMMM'),

            'vehiculo' => $this->vehiculo($quote->riskSnapshot),
            'cobertura' => [
                'grade' => $vista['grade'],
                'label' => $vista['gradeLabel'],
            ],

            'totalOpciones' => $vista['totalOpciones'],
            'glosario' => $vista['glosario'],
            'companias' => $vista['companias'],
            'recomendadas' => $vista['recomendadas'],
            'comparacion' => $vista['comparacion'],

            'whatsappNumber' => config('whatsapp.public_number'),
        ]);
    }

    /**
     * El CTA "La quiero": abre el checkout de la alternativa elegida sin pasar por el chat.
     *
     * Antes el botón era un `wa.me` con texto prearmado y el agente ejecutaba `checkout` al
     * recibirlo. Si el cliente ya eligió, no hay razón para hacerlo volver a la conversación.
     *
     * El controller no decide dominio: la vigencia y la pertenencia de la alternativa las
     * resuelve QuoteService::crearCheckout(), el mismo punto que usan los dos adapters. Acá solo
     * se traduce el `error_code` a algo que le podamos decir a un cliente — los mensajes del
     * service están escritos para el agente.
     */
    public function checkout(Request $request, string $token, QuoteService $quotes): RedirectResponse
    {
        $quote = Quote::where('public_token', $token)->firstOrFail();

        $validated = $request->validate([
            'alternative_id' => 'required|integer',
            // Lo decide el frontend con el user-agent, igual que Checkout/Show.vue: el checkout
            // necesita la cámara para las 7 fotos de inspección y no se puede completar en
            // escritorio. El backend no adivina el dispositivo.
            'movil' => 'required|boolean',
        ]);

        // El destinatario se verifica ANTES de abrir el checkout: si no hay por dónde mandarle el
        // link, crearlo primero dejaría la cotización en `checkout_pending` mientras al cliente le
        // decimos que no se pudo.
        if (! $validated['movil'] && ! $this->tieneDestinatarioDeWhatsApp($quote)) {
            return back()->withErrors([
                'alternative_id' => 'No pudimos mandarte el link por WhatsApp. Escribinos y te lo pasamos.',
            ]);
        }

        $resultado = $quotes->crearCheckout($quote->id, (int) $validated['alternative_id']);

        if (! $resultado['ok']) {
            return back()->withErrors([
                'alternative_id' => match ($resultado['error_code']) {
                    'quote_expired' => 'Los precios de esta cotización valían por el día en que la hicimos. '
                        .'Escribinos y la rehacemos en un minuto.',
                    'alternative_not_found' => 'Esa opción ya no está disponible en esta cotización.',
                    default => 'No pudimos abrir la contratación. Escribinos por WhatsApp y lo resolvemos.',
                },
            ]);
        }

        if ($validated['movil']) {
            return redirect()->route('checkout.show', ['token' => $resultado['token']]);
        }

        return $this->mandarLinkPorWhatsApp($quote, $resultado['url']);
    }

    /**
     * Cliente en escritorio: el checkout no se puede completar acá, así que el link va al teléfono
     * y sigue desde ahí.
     *
     * Si la ventana de 24hs de Meta está cerrada este mensaje lo rechaza la API y el cliente se
     * queda esperando algo que nunca llega. Por eso el modal de la vista ofrece siempre, además,
     * un botón de WhatsApp: que el cliente escriba primero reabre la ventana.
     */
    private function mandarLinkPorWhatsApp(Quote $quote, string $checkoutUrl): RedirectResponse
    {
        $conversation = $quote->conversation;

        if ($conversation === null) {
            return back();
        }

        $texto = 'Para terminar la contratación necesitás el celular: hay que sacarle 7 fotos al auto '
            ."con la cámara. Entrá desde acá y completás en un par de minutos: {$checkoutUrl}";

        SendWhatsAppMessage::dispatch(
            $conversation->recipientPhone(),
            $conversation->ext_user_id,
            $texto,
            (string) config('services.whatsapp.phone_number_id'),
            $conversation->id,
            'checkout_link'
        );

        return back();
    }

    /**
     * ¿Tenemos por dónde mandarle el link? Mismo criterio de destinatario que
     * NotifyClientCheckoutCompleted: se manda por BSUID y el teléfono, si lo tenemos, tiene
     * precedencia.
     */
    private function tieneDestinatarioDeWhatsApp(Quote $quote): bool
    {
        $conversation = $quote->conversation;

        if ($conversation === null || ! config('services.whatsapp.phone_number_id')) {
            return false;
        }

        return $conversation->ext_user_id !== null || $conversation->recipientPhone() !== null;
    }

    /**
     * Identificación del vehículo, sin la patente: alcanza para que el cliente reconozca su auto
     * y no identifica unívocamente al titular.
     *
     * @return array<string, mixed>
     */
    private function vehiculo(?RiskSnapshot $snapshot): array
    {
        $descripcion = trim(implode(' ', array_filter([
            $snapshot?->marca,
            $snapshot?->modelo,
            $snapshot?->version,
        ])));

        return [
            'marca' => $snapshot?->marca,
            'modelo' => $snapshot?->modelo,
            'version' => $snapshot?->version,
            'year' => $snapshot?->year,
            'combustible' => $snapshot?->combustible,
            'descripcion' => $descripcion !== '' ? $descripcion : 'Tu vehículo',
        ];
    }
}
