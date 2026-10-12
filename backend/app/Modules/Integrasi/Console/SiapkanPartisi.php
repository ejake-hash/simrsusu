<?php

namespace App\Modules\Integrasi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** `php artisan integrasi:siapkan-partisi`: partisi bulanan kotak_keluar dan log_api untuk 3 bulan ke depan. */
final class SiapkanPartisi extends Command
{
    protected $signature = 'integrasi:siapkan-partisi {--bulan=3}';

    protected $description = 'Siapkan partisi bulanan integrasi.kotak_keluar dan integrasi.log_api';

    public function handle(): int
    {
        foreach (DB::select('SELECT * FROM integrasi.siapkan_partisi(?::date, ?)', [now()->toDateString(), (int) $this->option('bulan')]) as $p) {
            $this->line("{$p->partisi}: {$p->status}");
        }

        return self::SUCCESS;
    }
}
