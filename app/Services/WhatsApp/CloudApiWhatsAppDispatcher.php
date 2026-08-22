<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppDispatcher;
use App\Jobs\SendWhatsAppTemplate;
use App\Models\EmergencyContact;
use App\Models\User;

/**
 * Implementación real: arma los parámetros del template y encola un
 * SendWhatsAppTemplate por destinatario. No envía inline — el envío no debe
 * bloquear la respuesta del endpoint (especialmente en una emergencia).
 */
class CloudApiWhatsAppDispatcher implements WhatsAppDispatcher
{
    public function emergencyContactNotice(EmergencyContact $contact, string $userName, string $locationUrl, int $estado): void
    {
        $key = $estado === 1 ? 'emergencia_estoy_bien' : 'emergencia_necesito_ayuda';

        $this->queue($contact->phone, $key, [$userName, $locationUrl]);
    }

    public function siniestroNoticeToPas(User $pas, string $customerName, string $customerContact): void
    {
        $phone = $pas->pasPhone();
        if ($phone === null || $phone === '') {
            return;
        }

        $this->queue($phone, 'siniestro_aviso_pas', [$customerName, $customerContact]);
    }

    /**
     * @param  list<string>  $bodyParams
     */
    private function queue(string $phone, string $templateKey, array $bodyParams): void
    {
        $template = config("whatsapp.templates.{$templateKey}");

        SendWhatsAppTemplate::dispatch(
            $this->normalizePhone($phone),
            $template['name'],
            $template['language'],
            $bodyParams,
            (string) config('services.whatsapp.phone_number_id'),
        );
    }

    /**
     * E.164 → formato que pide la Cloud API: solo dígitos, sin "+".
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }
}
