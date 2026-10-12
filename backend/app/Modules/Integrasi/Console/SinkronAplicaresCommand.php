<?php

namespace App\Modules\Integrasi\Console;

use App\Modules\Integrasi\Application\SinkronAplicares;
use Illuminate\Console\Command;

/** `php artisan integrasi:sinkron-aplicares`: antrekan ketersediaan TT semua bangsal ke Aplicares (harian, atau sesudah diaktifkan). */
final class SinkronAplicaresCommand extends Command
{
    protected $signature = 'integrasi:sinkron-aplicares';

    protected $description = 'Antrekan kiriman ketersediaan tempat tidur semua bangsal ke Aplicares';

    public function handle(SinkronAplicares $sinkron): int
    {
        $jumlah = $sinkron->semua();
        $this->info($jumlah === 0 ? 'Tidak ada yang diantrekan (Aplicares belum aktif atau belum ada TT).' : "$jumlah bangsal diantrekan.");

        return self::SUCCESS;
    }
}
