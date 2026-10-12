<?php

namespace App\Modules\Integrasi\Jobs;

use App\Core\Queue\JenisQueue;
use App\Modules\Integrasi\Application\PengirimKiriman;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Putaran outbox tiap menit (retry yang jatuh tempo, pemeriksaan ulang kiriman tertahan). Satu berjalan pada satu waktu. */
final class ProsesKotakKeluar implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection(JenisQueue::Integrasi->koneksi())->onQueue(JenisQueue::Integrasi->value);
    }

    public function handle(PengirimKiriman $pengirim): void
    {
        $pengirim->putaran();
    }
}
