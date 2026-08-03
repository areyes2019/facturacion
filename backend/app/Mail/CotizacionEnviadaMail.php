<?php

namespace App\Mail;

use App\Models\Cotizacion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CotizacionEnviadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Cotizacion $cotizacion,
        private readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cotización '.$this->cotizacion->folio,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.cotizacion-enviada');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, "cotizacion-{$this->cotizacion->folio}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
