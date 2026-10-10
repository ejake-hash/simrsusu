<?php

namespace App\Core\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SiapkanPartisi extends Command
{
    protected $signature = 'audit:siapkan-partisi {--bulan= : Jumlah bulan ke depan (bawaan config audit.partisi.bulan_ke_depan)}';

    protected $description = 'Membuat partisi audit.log_aktivitas untuk bulan ini dan beberapa bulan ke depan';

    public function handle(): int
    {
        $bulan = (int) ($this->option('bulan') ?? config('audit.partisi.bulan_ke_depan'));

        // Berjalan sebagai simrs_app; DDL dilakukan fungsi SECURITY DEFINER milik simrs_migrator.
        $hasil = DB::select('SELECT partisi, status FROM audit.siapkan_partisi(?::date, ?)', [
            now()->utc()->toDateString(),
            $bulan,
        ]);

        $this->table(['Partisi', 'Status'], array_map(fn ($b) => [$b->partisi, $b->status], $hasil));

        $bentrok = array_filter($hasil, fn ($b) => $b->status === 'bentrok_default');
        if ($bentrok !== []) {
            $this->error('Ada bulan yang barisnya sudah masuk partisi DEFAULT. Pindahkan oleh DBA (ADR 0003).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
