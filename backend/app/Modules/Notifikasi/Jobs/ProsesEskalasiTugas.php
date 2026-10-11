<?php

namespace App\Modules\Notifikasi\Jobs;

use App\Core\Queue\JenisQueue;
use App\Modules\Notifikasi\Application\KotakKerja;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Eskalasi tugas terjadwal di antrean `notifikasi` (tiket 1.7, FR-53-03). Satu yang berjalan pada satu waktu. */
final class ProsesEskalasiTugas implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection(JenisQueue::Notifikasi->koneksi())->onQueue(JenisQueue::Notifikasi->value);
    }

    public function handle(KotakKerja $kotakKerja): void
    {
        $kotakKerja->prosesEskalasi();
    }
}
