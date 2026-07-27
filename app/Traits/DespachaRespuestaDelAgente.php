<?php

namespace App\Traits;

use App\Jobs\SendWhatsAppMessage;
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
            $respuesta->onQueue('whatsapp-outbound');
            Bus::dispatch($respuesta);

            return;
        }

        $texto = "Acá podés ver el detalle completo de las opciones, con todo lo que cubre cada una: {$publicLink}";

        Bus::chain([
            $respuesta,
            new SendWhatsAppMessage($phone, $bsuid, $texto, $phoneNumberId, $conversationId, 'public_quote_link'),
        ])->onQueue('whatsapp-outbound')->dispatch();
    }
}
