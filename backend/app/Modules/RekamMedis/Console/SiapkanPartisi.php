<?php

namespace App\Modules\RekamMedis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Partisi bulanan klinis.observasi untuk bulan berjalan + 3 bulan ke depan (tiket 2.13, pola notifikasi 1.7). Aman diulang. */
final class SiapkanPartisi extends Command
{
    protected $signature = 'klinis:siapkan-partisi';

    protected $description = 'Membuat partisi bulanan tabel observasi klinis';

    public function handle(): int
    {
        foreach (DB::select('SELECT * FROM klinis.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]) as $b) {
            $this->line("{$b->partisi}: {$b->status}");
        }

        return self::SUCCESS;
    }
}
