<?php

namespace App\Modules\Integrasi\Console;

use App\Modules\Integrasi\Application\SinkronSatusehat;
use Illuminate\Console\Command;

/** `php artisan integrasi:sinkron-satusehat`: antrekan Organization, Location, dan IHS Practitioner yang belum terdaftar atau berubah. */
final class SinkronSatusehatCommand extends Command
{
    protected $signature = 'integrasi:sinkron-satusehat';

    protected $description = 'Antrekan sinkron data induk (unit, lokasi, pegawai nakes) ke SATUSEHAT';

    public function handle(SinkronSatusehat $sinkron): int
    {
        $jumlah = $sinkron->semua();
        $this->info($jumlah === [] ? 'SATUSEHAT belum aktif.' : implode(', ', array_map(fn ($j, $n) => "$j: $n", array_keys($jumlah), $jumlah)));

        return self::SUCCESS;
    }
}
