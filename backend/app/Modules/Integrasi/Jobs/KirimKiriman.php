<?php

namespace App\Modules\Integrasi\Jobs;

use App\Core\Queue\JenisQueue;
use App\Modules\Integrasi\Application\PengirimKiriman;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Kirim satu kiriman outbox di queue `integrasi`, sesudah transaksi pembuatnya commit. Aman ganda (sewa per kiriman). */
final class KirimKiriman implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $id)
    {
        $this->onConnection(JenisQueue::Integrasi->koneksi())->onQueue(JenisQueue::Integrasi->value)->afterCommit();
    }

    public function handle(PengirimKiriman $pengirim): void
    {
        $pengirim->proses($this->id);
    }
}
