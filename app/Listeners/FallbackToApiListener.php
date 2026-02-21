<?php

namespace App\Listeners;

use App\Events\QuoteOfferedToPas;
use Illuminate\Support\Facades\Log;

class FallbackToApiListener
{
    /**
     * Al detectar que se ofreció a un PAS, registramos el evento.
     * El timer global de 30min iniciado en la creación de la Quote
     * se encargará del fallback si no hay respuesta a tiempo.
     */
    public function handle(QuoteOfferedToPas $event): void
    {
        $quote = $event->quote;
        Log::info("[FallbackToApiListener] Quote #{$quote->id} está ahora en manos de los PAS.");
    }
}
