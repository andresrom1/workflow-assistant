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
 * Mail de notificación interna cuando `EmitirPoliza` agota los reintentos (o falla
 * de una por un 400 determinístico). Se envía al equipo ANTES que el aviso al
 * cliente — casi siempre un humano puede destrabar la emisión.
 *
 * Destinatario: `checkout.notifications_email` (setting) con fallback a
 * `config('mail.checkout_notifications_to')`.
 */
class EmisionFallidaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{message: string, status: int|null, error_code: string|null, field_errors: array<string, list<string>>|null}  $error
     */
    public function __construct(
        public readonly Quote $quote,
        public readonly CheckoutSession $session,
        public readonly array $error,
    ) {}

    public function envelope(): Envelope
    {
        $nombre = $this->session->nombre;
        $patente = $this->quote->riskSnapshot->vehicle->patente ?? 'sin patente';

        return new Envelope(
            subject: "⚠️ Emisión fallida — {$nombre} ({$patente})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.emision-fallida',
            with: [
                'quote' => $this->quote,
                'session' => $this->session,
                'error' => $this->error,
                'snap' => $this->quote->riskSnapshot,
            ],
        );
    }
}
