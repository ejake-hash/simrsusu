<?php

namespace App\Modules\MasterData\Console;

use App\Modules\MasterData\Application\MesinUsulan;
use Illuminate\Console\Command;

/** Tugas terjadwal 00.00 zona RS (tiket 1.8c): terapkan usulan disetujui yang tanggal efektifnya tiba. Aman diulang. */
final class TerapkanUsulan extends Command
{
    protected $signature = 'master-data:terapkan-usulan';

    protected $description = 'Menerapkan usulan perubahan data induk yang sudah disetujui dan jatuh tempo';

    public function handle(MesinUsulan $mesin): int
    {
        $this->info('Usulan diproses: '.$mesin->terapkanJatuhTempo());

        return self::SUCCESS;
    }
}
