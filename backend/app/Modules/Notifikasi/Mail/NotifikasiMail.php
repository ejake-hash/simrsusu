<?php

namespace App\Modules\Notifikasi\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email notifikasi (K2). Isi tanpa data pasien, sandi, atau IP; tautan membuka layar di SIMRS (perlu masuk). */
final class NotifikasiMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $judul,
        public readonly string $isi,
        public readonly ?string $tautan,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->judul);
    }

    public function content(): Content
    {
        $tautan = $this->tautan === null ? '' : "\n\nBuka: ".rtrim((string) config('app.url'), '/').$this->tautan;

        return new Content(htmlString: nl2br(e($this->isi.$tautan)));
    }
}
