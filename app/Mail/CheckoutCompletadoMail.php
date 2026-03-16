<?php

namespace App\Mail;

use App\Models\CheckoutSession;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mail de notificación interna al completarse un checkout.
 * Se envía al productor / equipo de operaciones.
 *
 * Destinatario: config('mail.checkout_notifications_to')
 * Variable .env: CHECKOUT_NOTIFICATIONS_TO=operaciones@tudominio.com
 */
class CheckoutCompletadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Quote          $quote,
        public readonly CheckoutSession $session,
    ) {}

    public function envelope(): Envelope
    {
        $nombre = $this->session->nombre;
        $patente = $this->quote->riskSnapshot->vehicle->patente ?? 'sin patente';

        return new Envelope(
            subject: "✅ Checkout completado — {$nombre} ({$patente})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checkout-completado',
            with: [
                'quote'       => $this->quote,
                'session'     => $this->session,
                'alternative' => $this->quote->alternatives()
                                    ->find($this->quote->checkout_alternative_id),
                'snap'        => $this->quote->riskSnapshot,
            ],
        );
    }
}