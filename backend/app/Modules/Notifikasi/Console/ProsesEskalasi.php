<?php

namespace App\Modules\Notifikasi\Console;

use App\Modules\Notifikasi\Application\KotakKerja;
use Illuminate\Console\Command;

/** Eskalasi tugas terjadwal (tiket 1.7, FR-53-03): naikkan tingkat tugas yang waktunya tercapai. Aman diulang. */
final class ProsesEskalasi extends Command
{
    protected $signature = 'notifikasi:proses-eskalasi';

    protected $description = 'Mengirim pengingat, teguran, dan eskalasi tugas yang waktunya tercapai';

    public function handle(KotakKerja $kotakKerja): int
    {
        $this->info('Tugas naik tingkat: '.$kotakKerja->prosesEskalasi());

        return self::SUCCESS;
    }
}
