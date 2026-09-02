<?php

namespace App\Traits;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Quote;
use Illuminate\Support\Facades\Bus;

/**
 * Despacha la respuesta del agente al canal de salida, y detrás el link a la vista pública de la
 * cotización cuando el turno lo dejó pendiente.
 *
 * El link va en un mensaje aparte y no pegado al texto del agente: los 3 slots de botones de Meta
 * ya están ocupados (2 opciones + "Tengo una pregunta") y un `cta_url` no se puede mezclar con
 * reply buttons.
 *
 * Van encadenados y no como dos `dispatch()` sueltos: los dos caen en `whatsapp-outbound` y con
 * más de un worker el link puede adelantarse al mensaje que le da sentido.
 *
 * Lo usan los dos puntos que despachan salida del orquestador — la respuesta a un mensaje del
 * cliente (ProcessConversationInbox) y el aviso de cotización lista (NotifyClientQuoteReady).
 */
trait DespachaRespuestaDelAgente
{
    private function despacharRespuesta(
        SendWhatsAppMessage $respuesta,
        ?string $publicLink,
        ?string $phone,
        ?string $bsuid,
        string $phoneNumberId,
        ?int $conversationId,
    ): void {
        if ($publicLink === null) {
            Bus::dispatch($respuesta);
        } else {
            $texto = "Acá podés ver el detalle completo de las opciones, con todo lo que cubre cada una: {$publicLink}";

            Bus::chain([
                $respuesta,
                new SendWhatsAppMessage($phone, $bsuid, $texto, $phoneNumberId, $conversationId, 'public_quote_link'),
            ])->onQueue('whatsapp-outbound')->dispatch();
        }

        $this->sellarPresentacionEntregada($conversationId);
    }

    /**
     * Sella `presented_at` de la cotización que este turno presentó.
     *
     * La marca vive acá y no en `PresentQuoteOptionsTool` porque tiene que significar *el cliente
     * la recibió*, no *la tool corrió*. Entre una cosa y la otra puede morir el proceso —el turno
     * encadenado de CheckoutAgent se pasó del timeout del job y lo mató el alarm a mitad de la
     * generación—, y como el reintento decide si presentar mirando esta marca, sellarla antes de
     * tiempo deja al cliente sin mensaje para siempre. Ver ROADMAP, bitácora 2026-09-02.
     *
     * El límite es el despacho a la cola de salida, no el ACK de Meta: `SendWhatsAppMessage` tiene
     * sus propios reintentos y su propio rastro en `failed_jobs`.
     *
     * Sella solo si la tool llegó a persistir las alternativas presentadas — el turno pudo haber
     * despachado cualquier otro mensaje.
     */
    private function sellarPresentacionEntregada(?int $conversationId): void
    {
        if ($conversationId === null) {
            return;
        }

        Quote::where('conversation_id', $conversationId)
            ->whereNull('presented_at')
            ->whereNotNull('presented_alternative_ids')
            ->update(['presented_at' => now()]);
    }
}
