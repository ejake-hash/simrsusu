<?php

namespace App\Modules\Integrasi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** `php artisan integrasi:hapus-log-lama`: hapus partisi log_api yang seluruhnya lebih tua dari masa simpan (INTEGRASI_LOG_HARI). */
final class HapusLogLama extends Command
{
    protected $signature = 'integrasi:hapus-log-lama';

    protected $description = 'Hapus partisi log integrasi yang melewati masa simpan';

    public function handle(): int
    {
        $hari = max(7, (int) config('integrasi.log_hari'));
        foreach (DB::select('SELECT * FROM integrasi.hapus_log_lama(?::timestamptz)', [now()->subDays($hari)->toIso8601String()]) as $p) {
            $this->line('Dihapus: '.$p->hapus_log_lama);
        }

        return self::SUCCESS;
    }
}
