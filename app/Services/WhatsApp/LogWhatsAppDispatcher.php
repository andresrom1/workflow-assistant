<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppDispatcher;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * No-op de dispatch: no pega a Meta, solo deja rastro en el log. Default en
 * local/testing mientras no haya credenciales ni templates aprobados. No
 * vuelca el GPS ni el contenido sensible — solo a quién se habría avisado.
 */
class LogWhatsAppDispatcher implements WhatsAppDispatcher
{
    public function emergencyContactNotice(EmergencyContact $contact, string $userName, string $locationUrl, int $estado): void
    {
        Log::info('WhatsApp (log driver): aviso de emergencia NO enviado', [
            'contact_id' => $contact->id,
            'estado' => $estado,
            'template' => $estado === 1 ? 'emergencia_estoy_bien' : 'emergencia_necesito_ayuda',
        ]);
    }

    public function siniestroNoticeToPas(User $pas, string $customerName, string $customerContact): void
    {
        Log::info('WhatsApp (log driver): aviso de siniestro al PAS NO enviado', [
            'pas_id' => $pas->id,
            'template' => 'siniestro_aviso_pas',
        ]);
    }
}
