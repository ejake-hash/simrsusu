<?php

namespace App\Modules\Notifikasi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Partisi bulanan notifikasi.notifikasi untuk bulan berjalan + 3 bulan ke depan (tiket 1.7). Aman diulang. */
final class SiapkanPartisi extends Command
{
    protected $signature = 'notifikasi:siapkan-partisi';

    protected $description = 'Membuat partisi bulanan tabel notifikasi';

    public function handle(): int
    {
        foreach (DB::select('SELECT * FROM notifikasi.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]) as $b) {
            $this->line("{$b->partisi}: {$b->status}");
        }

        return self::SUCCESS;
    }
}
