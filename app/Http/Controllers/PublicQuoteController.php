<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Services\Quote\QuoteComparisonService;
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
 */
class PublicQuoteController extends Controller
{
    public function show(string $token, QuoteComparisonService $comparison): Response
    {
        $quote = Quote::where('public_token', $token)->firstOrFail();
        $quote->load(['riskSnapshot', 'alternatives']);

        $vista = $comparison->buildPublicView($quote);

        abort_if($vista['totalOpciones'] === 0, 404, 'Esta cotización no tiene opciones para mostrar.');

        // Sin guard por `status` ni por vigencia a propósito: una cotización vencida tiene que
        // renderizar igual, con el CTA de contratar apagado. Un 404 le daría la espalda al
        // cliente que abre el link al día siguiente, que es justamente el caso a cubrir.
        return inertia('Cotizaciones/Comparador', [
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
